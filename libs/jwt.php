<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.

/**
 * Cadurix — JWT (JSON Web Token) pur PHP
 * Pas de dépendance externe — compatible PHP 8+
 */

class CadurixJWT {

    private static function base64url_encode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64url_decode(string $data): string {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', 3 - (3 + strlen($data)) % 4));
    }

    /**
     * Générer un token JWT
     */
    public static function encode(array $payload, string $secret, int $expires_in = 3600): string {
        $header = self::base64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));

        $payload['iat'] = time();
        $payload['exp'] = time() + $expires_in;
        $payload['jti'] = bin2hex(random_bytes(16)); // ID unique anti-replay

        $body = self::base64url_encode(json_encode($payload));
        $sig  = self::base64url_encode(hash_hmac('sha256', "$header.$body", $secret, true));

        return "$header.$body.$sig";
    }

    /**
     * Décoder et valider un token JWT
     * @throws RuntimeException si invalide
     */
    public static function decode(string $token, string $secret): array {
        $parts = explode('.', $token);
        if (count($parts) !== 3) throw new RuntimeException('Token malformé', 401);

        [$header, $body, $sig] = $parts;

        // Vérifier la signature
        $expected = self::base64url_encode(hash_hmac('sha256', "$header.$body", $secret, true));
        if (!hash_equals($expected, $sig)) throw new RuntimeException('Signature invalide', 401);

        $payload = json_decode(self::base64url_decode($body), true);
        if (!$payload) throw new RuntimeException('Payload invalide', 401);

        // Vérifier expiration
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            throw new RuntimeException('Token expiré', 401);
        }

        return $payload;
    }

    /**
     * Extraire le token du header Authorization: Bearer <token>
     */
    public static function from_request(): ?string {
        $auth = $_SERVER['HTTP_AUTHORIZATION']
             ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
             ?? apache_request_headers()['Authorization']
             ?? null;
        if ($auth && preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) return $m[1];
        return null;
    }
}

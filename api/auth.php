<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.

/**
 * Cadurix API — Authentification JWT
 * POST /api/auth.php  → obtenir access_token + refresh_token
 * POST /api/auth.php?action=refresh → renouveler l'access_token
 * POST /api/auth.php?action=revoke  → révoquer un token
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../libs/jwt.php';

$JWT_SECRET  = defined('JWT_SECRET') ? JWT_SECRET : hash('sha256', DB_PASS . DB_NAME . 'cadurix_jwt_salt_v1');
$ACCESS_TTL  = 900;    // 15 min
$REFRESH_TTL = 2592000; // 30 jours

function json_error(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['error' => true, 'message' => $msg]);
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? 'login';

// ── LOGIN ────────────────────────────────────────────────────
if ($action === 'login') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error(405, 'Méthode non autorisée');

    $login    = trim($input['login'] ?? '');
    $password = $input['password'] ?? '';
    $api_key  = trim($input['api_key'] ?? ''); // Clé API générée dans l'admin

    if (empty($login) || empty($password) || empty($api_key))
        json_error(400, 'login, password et api_key requis');

    // Vérifier la clé API
    $stmt = $pdo->prepare("
        SELECT t.*, u.mot_de_passe, u.actif AS user_actif, u.super_admin,
               u.nom, u.prenom, u.email
        FROM api_tokens t
        JOIN utilisateurs u ON u.id = t.user_id
        WHERE t.token_hash = ? AND t.actif = 1
          AND (t.expire_le IS NULL OR t.expire_le > NOW())
    ");
    $stmt->execute([hash('sha256', $api_key)]);
    $token_row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$token_row) json_error(401, 'Clé API invalide ou expirée');
    if (!$token_row['user_actif']) json_error(403, 'Compte désactivé');

    // Vérifier le mot de passe
    $dummy = '$2y$12$invalide.hash.pour.timing.egal.AAAAAAAAAAAAAAAAAAA';
    if (!password_verify($password, $token_row['mot_de_passe'] ?: $dummy))
        json_error(401, 'Identifiants incorrects');

    // Vérifier whitelist IP
    if ($token_row['ip_whitelist']) {
        $wl = json_decode($token_row['ip_whitelist'], true) ?? [];
        if (!empty($wl) && !in_array($_SERVER['REMOTE_ADDR'], $wl))
            json_error(403, 'IP non autorisée pour cette clé API');
    }

    $perms = json_decode($token_row['permissions'], true) ?? [];

    // Générer les tokens
    $payload = [
        'sub'      => (int)$token_row['user_id'],
        'site_id'  => $token_row['site_id'] ? (int)$token_row['site_id'] : null,
        'token_id' => (int)$token_row['id'],
        'perms'    => $perms,
        'super'    => (bool)$token_row['super_admin'],
    ];

    $access_token  = CadurixJWT::encode($payload, $JWT_SECRET, $ACCESS_TTL);
    $refresh_token = bin2hex(random_bytes(32));

    // Stocker le refresh token (hash)
    $pdo->prepare("UPDATE api_tokens SET
        token_hash = ?, derniere_utilisation = NOW(), nb_appels = nb_appels + 1
        WHERE id = ?
    ")->execute([hash('sha256', $refresh_token), $token_row['id']]);

    // Log
    $pdo->prepare("INSERT INTO api_logs (token_id,methode,endpoint,ip,statut) VALUES (?,?,?,?,?)")
        ->execute([$token_row['id'], 'POST', '/api/auth', $_SERVER['REMOTE_ADDR'], 200]);

    echo json_encode([
        'access_token'  => $access_token,
        'refresh_token' => $refresh_token,
        'token_type'    => 'Bearer',
        'expires_in'    => $ACCESS_TTL,
        'user' => [
            'id'     => (int)$token_row['user_id'],
            'nom'    => $token_row['nom'],
            'prenom' => $token_row['prenom'],
            'email'  => $token_row['email'],
        ],
        'permissions' => $perms,
    ]);
    exit;
}

// ── REFRESH ──────────────────────────────────────────────────
if ($action === 'refresh') {
    $refresh_token = $input['refresh_token'] ?? '';
    if (empty($refresh_token)) json_error(400, 'refresh_token requis');

    $stmt = $pdo->prepare("
        SELECT t.*, u.actif AS user_actif
        FROM api_tokens t
        JOIN utilisateurs u ON u.id = t.user_id
        WHERE t.token_hash = ? AND t.actif = 1
          AND (t.expire_le IS NULL OR t.expire_le > NOW())
    ");
    $stmt->execute([hash('sha256', $refresh_token)]);
    $token_row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$token_row || !$token_row['user_actif'])
        json_error(401, 'Refresh token invalide ou expiré');

    $perms = json_decode($token_row['permissions'], true) ?? [];
    $payload = [
        'sub'      => (int)$token_row['user_id'],
        'site_id'  => $token_row['site_id'] ? (int)$token_row['site_id'] : null,
        'token_id' => (int)$token_row['id'],
        'perms'    => $perms,
        'super'    => (bool)$token_row['super_admin'] ?? false,
    ];

    $new_access = CadurixJWT::encode($payload, $JWT_SECRET, $ACCESS_TTL);
    $pdo->prepare("UPDATE api_tokens SET derniere_utilisation=NOW(), nb_appels=nb_appels+1 WHERE id=?")
        ->execute([$token_row['id']]);

    echo json_encode([
        'access_token' => $new_access,
        'token_type'   => 'Bearer',
        'expires_in'   => $ACCESS_TTL,
    ]);
    exit;
}

// ── REVOKE ───────────────────────────────────────────────────
if ($action === 'revoke') {
    $token = CadurixJWT::from_request();
    if (!$token) json_error(401, 'Token manquant');
    try {
        $payload = CadurixJWT::decode($token, $JWT_SECRET);
        $pdo->prepare("UPDATE api_tokens SET actif=0 WHERE id=?")
            ->execute([$payload['token_id']]);
        echo json_encode(['message' => 'Token révoqué']);
    } catch (RuntimeException $e) {
        json_error(401, $e->getMessage());
    }
    exit;
}

json_error(400, 'Action inconnue');

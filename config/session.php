<?php
/**
 * Cadurix v5 — Gestion des sessions, CSRF, brute-force
 */

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.use_strict_mode', 1);
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        ini_set('session.cookie_secure', 1);
    }
    session_start();
}

// Régénération périodique de l'ID de session (toutes les 15 min)
function regenerer_session(): void {
    if (!isset($_SESSION['_last_regen'])) {
        $_SESSION['_last_regen'] = time();
    }
    if (time() - $_SESSION['_last_regen'] > 900) {
        session_regenerate_id(true);
        $_SESSION['_last_regen'] = time();
    }
}

// ----------------------------------------------------------------
//  CSRF
// ----------------------------------------------------------------
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}

function csrf_verifier(): bool {
    $token_post    = $_POST['csrf_token']    ?? '';
    $token_header  = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $token_session = $_SESSION['csrf_token'] ?? '';

    if (empty($token_session)) return false;

    $token_recu = !empty($token_post) ? $token_post : $token_header;
    return hash_equals($token_session, $token_recu);
}

function csrf_check(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_verifier()) {
            http_response_code(403);
            die('Token CSRF invalide. <a href="javascript:history.back()">Retour</a>');
        }
    }
}

// ----------------------------------------------------------------
//  Brute-force
// ----------------------------------------------------------------
function brute_force_check(string $login, PDO $pdo): bool {
    $stmt = $pdo->prepare("SELECT tentatives_echec, bloque_jusqu FROM utilisateurs WHERE login = ?");
    $stmt->execute([$login]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) return false;

    if ($user['bloque_jusqu'] && strtotime($user['bloque_jusqu']) > time()) {
        $minutes = ceil((strtotime($user['bloque_jusqu']) - time()) / 60);
        return false; // bloqué
    }
    return true;
}

function brute_force_echec(int $user_id, PDO $pdo): void {
    $pdo->prepare("UPDATE utilisateurs SET tentatives_echec = tentatives_echec + 1,
        bloque_jusqu = IF(tentatives_echec + 1 >= 5, DATE_ADD(NOW(), INTERVAL 15 MINUTE), bloque_jusqu)
        WHERE id = ?")
        ->execute([$user_id]);
}

function brute_force_reset(int $user_id, PDO $pdo): void {
    $pdo->prepare("UPDATE utilisateurs SET tentatives_echec = 0, bloque_jusqu = NULL WHERE id = ?")
        ->execute([$user_id]);
}

function verifier_brute_force_ip(PDO $pdo): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM connexions_historique
            WHERE ip = ? AND succes = 0
              AND cree_le > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        ");
        $stmt->execute([$ip]);
        $nb = (int)$stmt->fetchColumn();
        if ($nb >= 20) {
            http_response_code(429);
            header('Retry-After: 900');
            die('Trop de tentatives depuis votre adresse IP. Réessayez dans 15 minutes.');
        }
    } catch (Exception $e) {}
}

function est_bloque(string $login, PDO $pdo): array {
    $stmt = $pdo->prepare("SELECT tentatives_echec, bloque_jusqu FROM utilisateurs WHERE login = ?");
    $stmt->execute([$login]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) return ['bloque' => false];

    if ($user['bloque_jusqu'] && strtotime($user['bloque_jusqu']) > time()) {
        $minutes = ceil((strtotime($user['bloque_jusqu']) - time()) / 60);
        return ['bloque' => true, 'minutes' => $minutes];
    }
    return ['bloque' => false, 'tentatives' => $user['tentatives_echec']];
}

// ----------------------------------------------------------------
//  Headers de sécurité HTTP
// ----------------------------------------------------------------
function headers_securite(): void {
    header("X-Content-Type-Options: nosniff");
    header("Cache-Control: no-store, no-cache, must-revalidate, private");
    header("Pragma: no-cache");
    header("X-Frame-Options: DENY");
    header("X-XSS-Protection: 1; mode=block");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    header("Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), usb=(), magnetometer=(), gyroscope=(), accelerometer=()");
    header("Cross-Origin-Resource-Policy: same-origin");
    header("Cross-Origin-Opener-Policy: same-origin");
    header("Cross-Origin-Embedder-Policy: require-corp");
    header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; script-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; connect-src 'self'; worker-src 'self' blob:;");
}

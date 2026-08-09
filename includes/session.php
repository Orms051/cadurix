<?php
/**
 * Cadurix v5.2 — Gestion sessions, CSRF, brute-force, sécurité renforcée
 */

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.use_strict_mode', 1);
    ini_set('session.gc_maxlifetime', 7200);
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        ini_set('session.cookie_secure', 1);
    }
    session_start();
}

// ── Timeout session automatique (30 min inactivité) ──────
function check_session_timeout(): void {
    $timeout = 1800;
    if (isset($_SESSION['user_id'])) {
        if (isset($_SESSION['_last_activity']) && (time() - $_SESSION['_last_activity']) > $timeout) {
            session_unset(); session_destroy();
            header('Location: /login.php?timeout=1'); exit;
        }
        $_SESSION['_last_activity'] = time();
    }
}

// ── Régénération ID session (15 min) ─────────────────────
function regenerer_session(): void {
    if (!isset($_SESSION['_last_regen'])) $_SESSION['_last_regen'] = time();
    if (time() - $_SESSION['_last_regen'] > 900) {
        session_regenerate_id(true);
        $_SESSION['_last_regen'] = time();
    }
}

// ── Validation IP session ─────────────────────────────────
function valider_ip_session(): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!isset($_SESSION['_session_ip'])) {
        $_SESSION['_session_ip'] = $ip;
    } elseif ($_SESSION['_session_ip'] !== $ip && isset($_SESSION['user_id'])) {
        session_unset(); session_destroy();
        header('Location: /login.php?security=1'); exit;
    }
}

// ── CSRF ──────────────────────────────────────────────────
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
    $token_post    = $_POST['csrf_token']            ?? '';
    $token_header  = $_SERVER['HTTP_X_CSRF_TOKEN']   ?? '';
    $token_session = $_SESSION['csrf_token']          ?? '';
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

// ── Brute-force ───────────────────────────────────────────
function brute_force_check(string $login, PDO $pdo): bool {
    $stmt = $pdo->prepare("SELECT tentatives_echec, bloque_jusqu FROM utilisateurs WHERE login = ?");
    $stmt->execute([$login]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) return false;
    if ($user['bloque_jusqu'] && strtotime($user['bloque_jusqu']) > time()) return false;
    return true;
}
function brute_force_echec(int $user_id, PDO $pdo): void {
    $pdo->prepare("UPDATE utilisateurs SET tentatives_echec = tentatives_echec + 1,
        bloque_jusqu = IF(tentatives_echec + 1 >= 5, DATE_ADD(NOW(), INTERVAL 15 MINUTE), bloque_jusqu)
        WHERE id = ?")->execute([$user_id]);
}
function brute_force_reset(int $user_id, PDO $pdo): void {
    $pdo->prepare("UPDATE utilisateurs SET tentatives_echec = 0, bloque_jusqu = NULL WHERE id = ?")
        ->execute([$user_id]);
}
function est_bloque(string $login, PDO $pdo): array {
    $stmt = $pdo->prepare("SELECT tentatives_echec, bloque_jusqu FROM utilisateurs WHERE login = ?");
    $stmt->execute([$login]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) return ['bloque' => false];
    if ($user['bloque_jusqu'] && strtotime($user['bloque_jusqu']) > time()) {
        return ['bloque' => true, 'minutes' => ceil((strtotime($user['bloque_jusqu']) - time()) / 60)];
    }
    return ['bloque' => false, 'tentatives' => $user['tentatives_echec']];
}

// ── Rate limiting ─────────────────────────────────────────
function rate_limit(string $key, int $max = 60, int $window = 60): bool {
    $k     = 'rl_' . md5($key);
    $now   = time();
    $count = (int)($_SESSION[$k . '_count'] ?? 0);
    $start = (int)($_SESSION[$k . '_start'] ?? $now);
    if ($now - $start > $window) {
        $_SESSION[$k . '_count'] = 1;
        $_SESSION[$k . '_start'] = $now;
        return true;
    }
    if ($count >= $max) return false;
    $_SESSION[$k . '_count'] = $count + 1;
    return true;
}

// ── Politique mots de passe ───────────────────────────────
function valider_mot_de_passe(string $mdp): array {
    $errors = [];
    if (strlen($mdp) < 8)                    $errors[] = 'Au moins 8 caractères';
    if (!preg_match('/[A-Z]/', $mdp))         $errors[] = 'Au moins une majuscule';
    if (!preg_match('/[a-z]/', $mdp))         $errors[] = 'Au moins une minuscule';
    if (!preg_match('/[0-9]/', $mdp))         $errors[] = 'Au moins un chiffre';
    if (!preg_match('/[^A-Za-z0-9]/', $mdp))  $errors[] = 'Au moins un caractère spécial';
    return $errors;
}
function force_mot_de_passe(string $mdp): string {
    $s = 0;
    if (strlen($mdp) >= 8)  $s++;
    if (strlen($mdp) >= 12) $s++;
    if (preg_match('/[A-Z]/', $mdp))         $s++;
    if (preg_match('/[a-z]/', $mdp))         $s++;
    if (preg_match('/[0-9]/', $mdp))         $s++;
    if (preg_match('/[^A-Za-z0-9]/', $mdp))  $s++;
    return $s <= 2 ? 'faible' : ($s <= 4 ? 'moyen' : 'fort');
}

// ── Sanitisation inputs ───────────────────────────────────
function sanitize_input(string $input, string $type = 'text'): string {
    $input = trim($input);
    return match($type) {
        'email' => filter_var($input, FILTER_SANITIZE_EMAIL),
        'int'   => (string)(int)$input,
        'float' => (string)(float)$input,
        'url'   => filter_var($input, FILTER_SANITIZE_URL),
        'alpha' => preg_replace('/[^a-zA-Z\x{00C0}-\x{024F}\s\-]/u', '', $input),
        default => htmlspecialchars($input, ENT_QUOTES, 'UTF-8'),
    };
}

// ── Validation upload fichiers ────────────────────────────
function valider_upload(array $file, array $types = ['xlsx','csv'], int $max_mb = 10): array {
    $errors = [];
    if ($file['error'] !== UPLOAD_ERR_OK) { $errors[] = 'Erreur upload'; return $errors; }
    if ($file['size'] > $max_mb * 1024 * 1024) $errors[] = "Max {$max_mb}Mo";
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $types)) $errors[] = 'Type non autorisé : ' . implode(', ', $types);
    return $errors;
}

// ── Headers sécurité HTTP ─────────────────────────────────
function headers_securite(): void {
    // Redirection HTTPS en production (pas en local)
    $is_local = str_contains($_SERVER['HTTP_HOST'] ?? '', 'localhost')
             || str_contains($_SERVER['HTTP_HOST'] ?? '', '.test')
             || str_contains($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1');
    if (!$is_local && (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] !== 'on')) {
        header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'], true, 301);
        exit;
    }
    if (!$is_local) header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: SAMEORIGIN");
    header("X-XSS-Protection: 1; mode=block");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    header("Permissions-Policy: geolocation=(), microphone=()");
    header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net; font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; img-src 'self' data: blob:; connect-src 'self'; worker-src 'self';");
}

// ── Log sécurité ──────────────────────────────────────────
function security_log(PDO $pdo, string $event, string $details = '', ?int $user_id = null): void {
    try {
        $pdo->prepare("INSERT INTO audit_log (user_id, action, table_name, details, ip, user_agent)
                       VALUES (?, ?, 'security', ?, ?, ?)")
            ->execute([
                $user_id ?? ($_SESSION['user_id'] ?? null),
                'SECURITY_' . strtoupper($event),
                $details,
                $_SERVER['REMOTE_ADDR'] ?? '',
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
    } catch (Exception $e) {}
}

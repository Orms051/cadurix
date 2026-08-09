<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.

if (session_status() === PHP_SESSION_NONE) session_start();

if (isset($_SESSION['user_id'])) {
    require_once 'config/db.php';
    $uid = $_SESSION['user_id'];
    $ip  = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';

    try {
        $pdo->prepare("INSERT INTO audit_log (user_id, action, ip, user_agent) VALUES(?,?,?,?)")
            ->execute([$uid, 'DECONNEXION', $ip, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)]);
    } catch (Throwable) {}

    try {
        $pdo->prepare("DELETE FROM sessions_actives WHERE user_id=?")->execute([$uid]);
    } catch (Throwable) {}
}

session_unset();
session_destroy();

if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}

setcookie('cadurix_login', '', ['expires' => time() - 3600, 'path' => '/']);

header('Location: /index.php');
exit;

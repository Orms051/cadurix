<?php
// © Cadurix — collecte légère des statistiques d'usage (beacon)
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
}
http_response_code(204); // réponse vide quoi qu'il arrive
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['user_id'])) exit;

$type  = $_POST['type'] ?? '';
$cible = substr(trim($_POST['cible'] ?? ''), 0, 80);
$plat  = (($_POST['plateforme'] ?? 'pc') === 'mobile') ? 'mobile' : 'pc';
if (!in_array($type, ['page', 'tuile'], true) || $cible === '') exit;

require_once __DIR__ . '/config/db.php';
try {
    $uid = (int)$_SESSION['user_id'];
    $sid = $_SESSION['site_actif_id'] ?? null;
    $role = null;
    if ($sid) {
        $st = $pdo->prepare("SELECT role FROM user_site_permissions WHERE user_id=? AND site_id=? LIMIT 1");
        $st->execute([$uid, $sid]);
        $role = $st->fetchColumn() ?: null;
    }
    $pdo->prepare("INSERT INTO activite_stats (user_id, role, type, cible, plateforme, site_id) VALUES (?,?,?,?,?,?)")
        ->execute([$uid, $role, $type, $cible, $plat, $sid]);
} catch (Throwable $e) { /* silencieux : la collecte ne doit jamais gêner */ }
exit;

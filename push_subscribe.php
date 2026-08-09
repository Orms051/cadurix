<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.

/**
 * Cadurix — push_subscribe.php
 * Enregistre / supprime un abonnement push PWA
 */
require_once 'includes/auth.php';
header('Content-Type: application/json');

$user_id = currentUserId();
$site_id = site_actif();

if (!$user_id) { echo json_encode(['ok'=>false,'error'=>'Non authentifié']); exit; }

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? 'subscribe';

if ($action === 'unsubscribe') {
    $endpoint = $input['endpoint'] ?? '';
    $pdo->prepare("UPDATE push_subscriptions SET actif=0 WHERE endpoint=? AND user_id=?")
        ->execute([$endpoint, $user_id]);
    echo json_encode(['ok'=>true]);
    exit;
}

// Subscribe
$endpoint = $input['endpoint'] ?? '';
$p256dh   = $input['keys']['p256dh'] ?? '';
$auth     = $input['keys']['auth'] ?? '';

if (!$endpoint || !$p256dh || !$auth) {
    echo json_encode(['ok'=>false,'error'=>'Données manquantes']);
    exit;
}

try {
    $pdo->prepare("
        INSERT INTO push_subscriptions (user_id, site_id, endpoint, p256dh, auth, user_agent, actif)
        VALUES (?, ?, ?, ?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE
            p256dh    = VALUES(p256dh),
            auth      = VALUES(auth),
            user_agent= VALUES(user_agent),
            actif     = 1,
            last_used = NOW()
    ")->execute([$user_id, $site_id, $endpoint, $p256dh, $auth, $_SERVER['HTTP_USER_AGENT'] ?? null]);
    echo json_encode(['ok'=>true]);
} catch (Exception $e) {
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}

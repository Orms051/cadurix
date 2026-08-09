<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.

/**
 * api/ping.php — Réinitialise le timer d'inactivité
 * Appelé en AJAX par le JS de déconnexion automatique
 */
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'reason' => 'not_logged']);
    exit;
}

// Réinitialiser le timer d'inactivité
$_SESSION['last_activity'] = time();

echo json_encode([
    'ok'         => true,
    'user_id'    => $_SESSION['user_id'],
    'timestamp'  => time(),
]);

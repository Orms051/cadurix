<?php
// © Cadurix v5.4 — Switch site actif
require_once 'includes/auth.php';

$site_id  = (int)($_GET['site_id'] ?? 0);
$redirect = $_GET['redirect'] ?? '/dashboard.php';

// Sécuriser la redirection
$redirect = filter_var($redirect, FILTER_SANITIZE_URL);
if (!str_starts_with($redirect, '/')) $redirect = '/dashboard.php';

if ($site_id && definir_site_actif($site_id, $pdo)) {
    // Invalider le cache permissions pour forcer le rechargement
    unset($_SESSION['permissions_chargees']);
    flashSuccess('Site changé avec succès.');
} else {
    flashError('Site inaccessible ou invalide.');
}

header('Location: ' . $redirect);
exit;

<?php
/**
 * Cadurix v5 — Vérification mode maintenance
 * Inclus automatiquement depuis auth.php
 */
function check_maintenance(): void {
    $config_file = __DIR__ . '/../config/maintenance.json';
    if (!file_exists($config_file)) return;

    $config = json_decode(file_get_contents($config_file), true);
    if (!($config['actif'] ?? false)) return;

    // Super admin bypass toujours
    if (!empty($_SESSION['super_admin'])) return;

    // Bypass par IP
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    $ips_bypass = $config['ips_bypass'] ?? [];
    if (in_array($ip, $ips_bypass)) return;

    // Bypass si on est déjà sur la page maintenance ou admin
    $current = $_SERVER['PHP_SELF'] ?? '';
    if (str_contains($current, 'maintenance.php') || str_contains($current, 'index.php') || str_contains($current, 'logout.php')) return;

    // Stocker le message en session pour la page maintenance
    $_SESSION['maintenance_message'] = $config['message'] ?? '';
    $_SESSION['maintenance_retour']  = $config['retour_estime'] ?? '';
    $_SESSION['maintenance_debut']   = $config['debut'] ?? '';

    header('Location: /maintenance.php');
    exit;
}

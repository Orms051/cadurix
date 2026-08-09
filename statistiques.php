<?php
// Redirigé vers l'onglet du hub admin (le contenu vit dans admin/stats.php)
require_once 'includes/auth.php';
requireAdmin();
header('Location: /admin/?tab=stats');
exit;

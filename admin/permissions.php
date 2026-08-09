<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.

// Redirigé vers users.php?tab=users&onglet=permissions
header('Location: ' . (defined('ADMIN_HUB') ? '?tab=users&onglet=permissions' : '../admin/?tab=users&onglet=permissions'));
exit;

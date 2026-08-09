<?php
// L'affichage PC/Mobile vit désormais dans Utilisateurs → onglet « Affichage »
require_once 'includes/auth.php';
requireAdmin();
header('Location: /admin/users.php?onglet=affichage');
exit;

<?php
// © Cadurix — L'onglet Alertes est fusionné dans Médicaments (products.php).
require_once 'includes/auth.php';
$q = isset($_GET['emplacement_id']) ? '?emplacement=' . (int)$_GET['emplacement_id'] : '';
header('Location: /products.php' . $q);
exit;

<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.

/**
 * Cadurix SSE — Export Excel
 */
require_once '../includes/auth.php';
$site_id = site_actif();
if (!$site_id) { header('Location: /sse.php'); exit; }
// Export direct — pas de header.php (génère du contenu binaire)
// Vérification permission via session
$_role = role_sur_site($site_id);
if (!$_role || !in_array($_role, ['super_admin','administrateur','gestionnaire','sse'])) {
    http_response_code(403); die('Accès refusé');
}

$type = $_GET['type'] ?? 'equipements';

// En-têtes Excel
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="sse_' . $type . '_' . date('Y-m-d') . '.xls"');
header('Cache-Control: max-age=0');
echo "ï»¿"; // BOM UTF-8

if ($type === 'equipements') {
    $stmt = $pdo->prepare("SELECT e.nom,e.numero_serie,e.numero_lot,e.marque,e.modele,e.emplacement,e.statut,e.date_peremption,e.date_prochain_ctrl,c.nom AS categorie FROM sse_equipements e LEFT JOIN sse_categories c ON c.id=e.categorie_id WHERE e.site_id=? AND e.actif=1 ORDER BY e.statut,e.nom");
    $stmt->execute([$site_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Nom	N° Série	N° Lot	Marque	Modèle	Emplacement	Statut	Péremption	Prochain contrôle	Catégorie
";
    foreach ($rows as $r) echo implode("	", array_map(fn($v)=>str_replace(["	","
"],' ',$v??'---'), array_values($r))) . "
";
} else {
    $stmt = $pdo->prepare("SELECT s.nom,s.reference,s.numero_lot,s.quantite,s.unite,s.quantite_min,s.date_peremption,s.emplacement,s.statut,c.nom AS categorie FROM sse_consommables s LEFT JOIN sse_categories c ON c.id=s.categorie_id WHERE s.site_id=? ORDER BY s.date_peremption,s.nom");
    $stmt->execute([$site_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Nom	Référence	N° Lot	Quantité	Unité	Qté min	Péremption	Emplacement	Statut	Catégorie
";
    foreach ($rows as $r) echo implode("	", array_map(fn($v)=>str_replace(["	","
"],' ',$v??'---'), array_values($r))) . "
";
}

<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.

/**
 * Cadurix SSE — Export Excel (équipements ou consommables)
 */
require_once '../includes/auth.php';
$site_id = site_actif();
if (!$site_id) { header('Location: /sse.php'); exit; }
// Export direct — pas de header.php (génère du contenu binaire). Permission via rôle.
$_role = role_sur_site($site_id);
if (!$_role || !in_array($_role, ['super_admin','administrateur','gestionnaire','sse'])) {
    http_response_code(403); die('Accès refusé');
}

$type = (($_GET['type'] ?? 'equipements') === 'consommables') ? 'consommables' : 'equipements';

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="sse_' . $type . '_' . date('Y-m-d') . '.xls"');
header('Cache-Control: max-age=0');
echo "\xEF\xBB\xBF"; // BOM UTF-8

$ligne = function (array $vals) {
    echo implode("\t", array_map(fn($v) => str_replace(["\t", "\r", "\n"], ' ', (string)($v ?? '---')), $vals)) . "\r\n";
};

if ($type === 'equipements') {
    $ligne(['Nom','N° Série','Taille','Localisation','Statut','Péremption','Prochain contrôle','Catégorie']);
    $stmt = $pdo->prepare("SELECT e.nom,e.numero_serie,e.taille,e.localisation,e.statut,e.date_peremption,e.date_prochain_ctrl,c.nom AS categorie FROM sse_equipements e LEFT JOIN sse_categories c ON c.id=e.categorie_id WHERE e.site_id=? AND e.actif=1 ORDER BY e.statut,e.nom");
    $stmt->execute([$site_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $ligne(array_values($r));
} else {
    $ligne(['Nom','Référence','N° Lot','Quantité','Unité','Qté min','Péremption','Localisation','Statut','Catégorie']);
    $stmt = $pdo->prepare("SELECT s.nom,s.reference,s.numero_lot,s.quantite,s.unite,s.quantite_min,s.date_peremption,s.localisation,s.statut,c.nom AS categorie FROM sse_consommables s LEFT JOIN sse_categories c ON c.id=s.categorie_id WHERE s.site_id=? AND s.statut='actif' ORDER BY s.date_peremption,s.nom");
    $stmt->execute([$site_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $ligne(array_values($r));
}

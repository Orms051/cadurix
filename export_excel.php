<?php
/**
 * Cadurix v5.2 — Export Excel (.xlsx) des médicaments
 * Génère un CSV UTF-8 compatible Excel (ou XLSX si PhpSpreadsheet installé)
 */
require_once __DIR__ . '/includes/auth.php';

$site_id = site_actif();
if (!$site_id) { header('Location: dashboard.php'); exit; }

$filtre  = $_GET['filtre'] ?? 'tous';
$fs_val = intval($site_id); // sécurisé par intval
$fs = "e.site_id = $fs_val";
$fe      = sql_filtre_emplacements('e');

$where = match($filtre) {
    'perime'       => "AND p.date_peremption < CURDATE()",
    'critique'     => "AND DATEDIFF(p.date_peremption,CURDATE()) BETWEEN 0 AND 7",
    'avertissement'=> "AND DATEDIFF(p.date_peremption,CURDATE()) BETWEEN 8 AND 30",
    'stock_bas'    => "AND p.quantite<=p.quantite_min AND p.quantite_min>0",
    'froid'        => "AND p.chaine_froid=1",
    default        => ''
};

$stmt = $pdo->prepare("
    SELECT
        p.nom,
        p.reference,
        p.numero_lot,
        e.nom AS emplacement,
        ep.nom AS parent_emplacement,
        p.quantite,
        p.quantite_min,
        p.unite,
        p.date_peremption,
        DATEDIFF(p.date_peremption, CURDATE()) AS jours_restants,
        CASE
            WHEN p.date_peremption < CURDATE() THEN 'Périmé'
            WHEN DATEDIFF(p.date_peremption,CURDATE()) <= 7 THEN 'Critique'
            WHEN DATEDIFF(p.date_peremption,CURDATE()) <= p.alerte_jours THEN 'Avertissement'
            ELSE 'OK'
        END AS statut,
        CASE WHEN p.chaine_froid=1 THEN 'Oui' ELSE 'Non' END AS chaine_froid,
        p.temp_stockage_min,
        p.temp_stockage_max,
        p.notes,
        p.cree_le,
        p.modifie_le
    FROM produits p
    JOIN emplacements e  ON e.id = p.emplacement_id
    LEFT JOIN emplacements ep ON ep.id = e.parent_id
    WHERE p.statut = 'actif' AND $fs AND $fe $where
    ORDER BY p.date_peremption ASC, e.nom, p.nom
");
$stmt->execute();
$produits = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Infos site
$site = $pdo->prepare("SELECT s.nom, o.nom AS org FROM sites s JOIN organisations o ON o.id=s.organisation_id WHERE s.id=?");
$site->execute([$site_id]);
$site_info = $site->fetch(PDO::FETCH_ASSOC);
$site_nom  = ($site_info['org'] ?? '') . ' — ' . ($site_info['nom'] ?? '');

// Nom fichier
$filtres_labels = ['tous'=>'tous','perime'=>'perimes','critique'=>'critiques','avertissement'=>'avertissements','stock_bas'=>'stock-bas','froid'=>'froid'];
$filename = 'Cadurix_' . ($filtres_labels[$filtre] ?? 'export') . '_' . date('Ymd_Hi') . '.csv';

// ── Si PhpSpreadsheet disponible → vrai XLSX ─────────────
if (file_exists(__DIR__ . '/libs/PhpSpreadsheet/autoload.php')) {
    require_once __DIR__ . '/libs/PhpSpreadsheet/autoload.php';
    // XLSX via PhpSpreadsheet (à implémenter si installé)
}

// ── Fallback : CSV UTF-8 BOM (compatible Excel) ───────────
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

$out = fopen('php://output', 'w');

// BOM UTF-8 pour Excel
fwrite($out, "\xEF\xBB\xBF");

// En-tête info
fputcsv($out, ['Cadurix — Export médicaments'], ';');
fputcsv($out, ['Site', $site_nom], ';');
fputcsv($out, ['Date export', date('d/m/Y H:i')], ';');
fputcsv($out, ['Filtre', $filtres_labels[$filtre] ?? 'tous'], ';');
fputcsv($out, ['Total', count($produits) . ' produit(s)'], ';');
fputcsv($out, [], ';'); // ligne vide

// En-têtes colonnes
fputcsv($out, [
    'Nom',
    'Référence',
    'N° Lot',
    'Emplacement parent',
    'Emplacement',
    'Quantité',
    'Quantité min.',
    'Unité',
    'Date péremption',
    'Jours restants',
    'Statut',
    'Chaîne du froid',
    'Temp. min (°C)',
    'Temp. max (°C)',
    'Notes',
    'Créé le',
    'Modifié le',
], ';');

// Données
foreach ($produits as $p) {
    fputcsv($out, [
        $p['nom'],
        $p['reference'] ?? '',
        $p['numero_lot'] ?? '',
        $p['parent_emplacement'] ?? '',
        $p['emplacement'],
        str_replace('.', ',', $p['quantite']),
        $p['quantite_min'] ? str_replace('.', ',', $p['quantite_min']) : '',
        $p['unite'],
        date('d/m/Y', strtotime($p['date_peremption'])),
        $p['jours_restants'],
        $p['statut'],
        $p['chaine_froid'],
        $p['temp_stockage_min'] ?? '',
        $p['temp_stockage_max'] ?? '',
        $p['notes'] ?? '',
        $p['cree_le'] ? date('d/m/Y H:i', strtotime($p['cree_le'])) : '',
        $p['modifie_le'] ? date('d/m/Y H:i', strtotime($p['modifie_le'])) : '',
    ], ';');
}

fclose($out);

// Audit
audit_log($pdo, 'EXPORT_EXCEL', 'produits', 0, null, ['filtre'=>$filtre, 'nb'=>count($produits)]);
exit;

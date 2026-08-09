<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.

require_once __DIR__ . '/../middleware.php';
api_require_perm('read');
$site_id=api_site_id();
$stmt=$pdo->prepare("SELECT p.id,p.nom,p.reference,p.date_peremption,p.quantite,p.quantite_min,p.statut,e.nom AS emplacement,DATEDIFF(p.date_peremption,CURDATE()) AS jours_restants,CASE WHEN p.date_peremption<CURDATE() THEN 'perime' WHEN DATEDIFF(p.date_peremption,CURDATE())<=7 THEN 'critique' WHEN DATEDIFF(p.date_peremption,CURDATE())<=30 THEN 'avertissement' WHEN p.quantite_min>0 AND p.quantite<=p.quantite_min THEN 'stock_bas' END AS niveau_alerte FROM produits p JOIN emplacements e ON e.id=p.emplacement_id WHERE e.site_id=? AND p.statut='actif' AND (p.date_peremption<=DATE_ADD(CURDATE(),INTERVAL 30 DAY) OR (p.quantite_min>0 AND p.quantite<=p.quantite_min)) ORDER BY p.date_peremption ASC");
$stmt->execute([$site_id]);
$data=$stmt->fetchAll(PDO::FETCH_ASSOC);
$stats=['perime'=>0,'critique'=>0,'avertissement'=>0,'stock_bas'=>0];
foreach($data as $r) if($r['niveau_alerte']) $stats[$r['niveau_alerte']]++;
api_success($data,200,['stats'=>$stats]);

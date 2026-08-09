<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.

require_once __DIR__ . '/../middleware.php';
api_require_perm('read');
$site_id=api_site_id();
$produits_total=(int)$pdo->prepare("SELECT COUNT(*) FROM produits p JOIN emplacements e ON e.id=p.emplacement_id WHERE e.site_id=? AND p.statut='actif'")->execute([$site_id])?$pdo->prepare("SELECT COUNT(*) FROM produits p JOIN emplacements e ON e.id=p.emplacement_id WHERE e.site_id=? AND p.statut='actif'")->execute([$site_id]):0;
$s=function($sql,$p) use($pdo){ $st=$pdo->prepare($sql); $st->execute($p); return (int)$st->fetchColumn(); };
api_success([
    'produits_actifs'   =>$s("SELECT COUNT(*) FROM produits p JOIN emplacements e ON e.id=p.emplacement_id WHERE e.site_id=? AND p.statut='actif'",[$site_id]),
    'produits_perimes'  =>$s("SELECT COUNT(*) FROM produits p JOIN emplacements e ON e.id=p.emplacement_id WHERE e.site_id=? AND p.statut='actif' AND p.date_peremption<CURDATE()",[$site_id]),
    'produits_critiques'=>$s("SELECT COUNT(*) FROM produits p JOIN emplacements e ON e.id=p.emplacement_id WHERE e.site_id=? AND p.statut='actif' AND DATEDIFF(p.date_peremption,CURDATE()) BETWEEN 0 AND 7",[$site_id]),
    'produits_stock_bas'=>$s("SELECT COUNT(*) FROM produits p JOIN emplacements e ON e.id=p.emplacement_id WHERE e.site_id=? AND p.statut='actif' AND p.quantite_min>0 AND p.quantite<=p.quantite_min",[$site_id]),
    'emplacements'      =>$s("SELECT COUNT(*) FROM emplacements WHERE site_id=? AND actif=1",[$site_id]),
    'rempotages_mois'   =>$s("SELECT COUNT(*) FROM rempotages WHERE site_id=? AND MONTH(cree_le)=MONTH(NOW())",[$site_id]),
]);

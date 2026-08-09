<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.

/**
 * Cadurix API v1 — Produits
 * GET/POST/PUT/DELETE
 */
require_once __DIR__ . '/../middleware.php';

$site_id = api_site_id();
$method  = $_SERVER['REQUEST_METHOD'];
$id      = isset($_GET['id']) ? (int)$_GET['id'] : null;

if ($method === 'GET' && !$id) {
    api_require_perm('read');
    $pg = api_pagination();
    $where = ["e.site_id = ?"]; $params = [$site_id];
    if (isset($_GET['statut']))  { $where[] = "p.statut=?"; $params[] = $_GET['statut']; }
    if (isset($_GET['perimes']))  { $where[] = "p.date_peremption < CURDATE()"; }
    if (isset($_GET['stock_bas'])){ $where[] = "p.quantite_min>0 AND p.quantite<=p.quantite_min"; }
    $w = implode(' AND ', $where);
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM produits p JOIN emplacements e ON e.id=p.emplacement_id WHERE $w");
    $cnt->execute($params); $total = (int)$cnt->fetchColumn();
    $stmt = $pdo->prepare("SELECT p.*, e.nom AS emplacement_nom, DATEDIFF(p.date_peremption,CURDATE()) AS jours FROM produits p JOIN emplacements e ON e.id=p.emplacement_id WHERE $w ORDER BY p.date_peremption ASC LIMIT {$pg['limit']} OFFSET {$pg['offset']}");
    $stmt->execute($params);
    api_success($stmt->fetchAll(PDO::FETCH_ASSOC), 200, ['total'=>$total,'page'=>$pg['page'],'pages'=>ceil($total/$pg['limit'])]);
}

if ($method === 'GET' && $id) {
    api_require_perm('read');
    $stmt = $pdo->prepare("SELECT p.*, e.nom AS emplacement_nom FROM produits p JOIN emplacements e ON e.id=p.emplacement_id WHERE p.id=? AND e.site_id=?");
    $stmt->execute([$id, $site_id]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$r) api_error(404, 'Produit introuvable');
    api_success($r);
}

if ($method === 'POST') {
    api_require_perm('write');
    $b = api_body();
    foreach (['nom','emplacement_id','date_peremption'] as $r) if (empty($b[$r])) api_error(400, "Champ requis: $r");
    $chk = $pdo->prepare("SELECT 1 FROM emplacements WHERE id=? AND site_id=?");
    $chk->execute([(int)$b['emplacement_id'], $site_id]);
    if (!$chk->fetchColumn()) api_error(403, "Emplacement non autorisé");
    $stmt = $pdo->prepare("INSERT INTO produits (nom,reference,emplacement_id,date_peremption,quantite,quantite_min,statut,cree_le) VALUES (?,?,?,?,?,?,'actif',NOW())");
    $stmt->execute([trim($b['nom']),trim($b['reference']??''),(int)$b['emplacement_id'],$b['date_peremption'],(int)($b['quantite']??1),(int)($b['quantite_min']??0)]);
    api_success(['id'=>(int)$pdo->lastInsertId(),'message'=>'Produit créé'], 201);
}

if ($method === 'PUT' && $id) {
    api_require_perm('write');
    $b = api_body();
    $chk = $pdo->prepare("SELECT 1 FROM produits p JOIN emplacements e ON e.id=p.emplacement_id WHERE p.id=? AND e.site_id=?");
    $chk->execute([$id, $site_id]);
    if (!$chk->fetchColumn()) api_error(404, 'Produit introuvable');
    $fields=[]; $params=[];
    foreach (['nom','reference','date_peremption','quantite','quantite_min','statut','notes'] as $f) {
        if (array_key_exists($f,$b)) { $fields[]="$f=?"; $params[]=$b[$f]; }
    }
    if (!$fields) api_error(400, 'Aucun champ');
    $params[]=$id;
    $pdo->prepare("UPDATE produits SET ".implode(',',$fields).",modifie_le=NOW() WHERE id=?")->execute($params);
    api_success(['message'=>'Produit mis à jour']);
}

if ($method === 'DELETE' && $id) {
    api_require_perm('delete');
    $chk = $pdo->prepare("SELECT 1 FROM produits p JOIN emplacements e ON e.id=p.emplacement_id WHERE p.id=? AND e.site_id=?");
    $chk->execute([$id,$site_id]);
    if (!$chk->fetchColumn()) api_error(404,'Produit introuvable');
    $pdo->prepare("UPDATE produits SET statut='supprime',modifie_le=NOW() WHERE id=?")->execute([$id]);
    api_success(['message'=>'Produit supprimé']);
}
api_error(405,'Méthode non autorisée');

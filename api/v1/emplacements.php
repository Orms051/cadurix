<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.

require_once __DIR__ . '/../middleware.php';
$site_id=$method=$id=null;
$site_id=api_site_id(); $method=$_SERVER['REQUEST_METHOD']; $id=isset($_GET['id'])?(int)$_GET['id']:null;
if ($method==='GET'&&!$id){ api_require_perm('read'); $stmt=$pdo->prepare("SELECT e.*,(SELECT COUNT(*) FROM produits p WHERE p.emplacement_id=e.id AND p.statut='actif') AS nb_produits FROM emplacements e WHERE e.site_id=? AND e.actif=1 ORDER BY e.nom"); $stmt->execute([$site_id]); api_success($stmt->fetchAll(PDO::FETCH_ASSOC)); }
if ($method==='GET'&&$id){ api_require_perm('read'); $stmt=$pdo->prepare("SELECT e.*,(SELECT COUNT(*) FROM produits p WHERE p.emplacement_id=e.id AND p.statut='actif') AS nb_produits FROM emplacements e WHERE e.id=? AND e.site_id=?"); $stmt->execute([$id,$site_id]); $r=$stmt->fetch(PDO::FETCH_ASSOC); if(!$r) api_error(404,'Emplacement introuvable'); api_success($r); }
if ($method==='POST'){ api_require_perm('write'); $b=api_body(); if(empty($b['nom'])) api_error(400,'nom requis'); $pdo->prepare("INSERT INTO emplacements (nom,code,description,site_id,actif,cree_le) VALUES (?,?,?,?,1,NOW())")->execute([trim($b['nom']),trim($b['code']??''),trim($b['description']??''),$site_id]); api_success(['id'=>(int)$pdo->lastInsertId(),'message'=>'Emplacement créé'],201); }
if ($method==='PUT'&&$id){ api_require_perm('write'); $b=api_body(); $chk=$pdo->prepare("SELECT 1 FROM emplacements WHERE id=? AND site_id=?"); $chk->execute([$id,$site_id]); if(!$chk->fetchColumn()) api_error(404,'Emplacement introuvable'); $f=[]; $p=[]; foreach(['nom','code','description'] as $k){ if(array_key_exists($k,$b)){$f[]="$k=?";$p[]=$b[$k];} } if(!$f) api_error(400,'Aucun champ'); $p[]=$id; $pdo->prepare("UPDATE emplacements SET ".implode(',',$f)." WHERE id=?")->execute($p); api_success(['message'=>'Emplacement mis à jour']); }
if ($method==='DELETE'&&$id){ api_require_perm('delete'); $chk=$pdo->prepare("SELECT 1 FROM emplacements WHERE id=? AND site_id=?"); $chk->execute([$id,$site_id]); if(!$chk->fetchColumn()) api_error(404,'Introuvable'); $pdo->prepare("UPDATE emplacements SET actif=0 WHERE id=?")->execute([$id]); api_success(['message'=>'Emplacement désactivé']); }
api_error(405,'Méthode non autorisée');

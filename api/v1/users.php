<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.

require_once __DIR__ . '/../middleware.php';
api_require_perm('read');
$site_id=api_site_id();
if($_SERVER['REQUEST_METHOD']!=='GET') api_error(405,'Lecture seule');
$stmt=$pdo->prepare("SELECT u.id,u.nom,u.prenom,u.login,u.email,usp.role FROM utilisateurs u JOIN user_site_permissions usp ON usp.user_id=u.id WHERE usp.site_id=? AND u.actif=1 ORDER BY u.nom");
$stmt->execute([$site_id]);
api_success($stmt->fetchAll(PDO::FETCH_ASSOC));

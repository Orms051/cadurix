<?php
// © Cadurix v5.4 — Export CSV de la liste des utilisateurs.
require_once '../includes/auth.php';
requireAdmin();

$is_sa = (bool)($_SESSION['super_admin'] ?? false);

$sql = "SELECT u.*,
        (SELECT GROUP_CONCAT(DISTINCT CONCAT(s.nom,' (',usp.role,')') ORDER BY s.nom SEPARATOR ' | ')
           FROM user_site_permissions usp JOIN sites s ON s.id=usp.site_id
          WHERE usp.user_id=u.id) AS acces
        FROM utilisateurs u ";

if ($is_sa) {
    $stmt = $pdo->query($sql . "ORDER BY u.nom, u.prenom");
} else {
    $sid  = site_actif();
    $stmt = $pdo->prepare($sql . "WHERE u.super_admin=1 OR EXISTS(
                SELECT 1 FROM user_site_permissions usp2 WHERE usp2.user_id=u.id AND usp2.site_id=?)
            ORDER BY u.nom, u.prenom");
    $stmt->execute([$sid]);
}
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$fname = 'utilisateurs_cadurix_' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Pragma: no-cache');

$out = fopen('php://output', 'w');
// BOM UTF-8 pour qu'Excel affiche correctement les accents
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, [
    'Nom', 'Prénom', 'Login', 'Email', 'Téléphone', 'Poste', 'Service',
    'Accès (site / rôle)', 'Actif', 'Super admin', 'Dernière connexion', 'Créé le'
], ';');

foreach ($rows as $u) {
    fputcsv($out, [
        $u['nom'] ?? '',
        $u['prenom'] ?? '',
        $u['login'] ?? '',
        $u['email'] ?? '',
        $u['telephone'] ?? '',
        $u['poste'] ?? '',
        $u['service'] ?? '',
        !empty($u['super_admin']) ? 'Tous les sites (super admin)' : ($u['acces'] ?? ''),
        !empty($u['actif']) ? 'Oui' : 'Non',
        !empty($u['super_admin']) ? 'Oui' : 'Non',
        !empty($u['derniere_connexion']) ? date('d/m/Y H:i', strtotime($u['derniere_connexion'])) : 'Jamais',
        !empty($u['cree_le']) ? date('d/m/Y', strtotime($u['cree_le'])) : '',
    ], ';');
}
fclose($out);
exit;

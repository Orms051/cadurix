<?php
/**
 * Cadurix v5.2 — Recherche globale AJAX
 * URL : /includes/search.php?q=...
 */
require_once __DIR__ . '/auth.php';
header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) { echo json_encode([]); exit; }

$sid  = site_actif();
$like = '%' . $q . '%';
$results = [];

// ── Médicaments ───────────────────────────────────────────
if (nav_peut($nav_perms ?? [], 'medicaments')) {
    try {
        $st = $pdo->prepare("
            SELECT p.id, p.nom, p.numero_lot, e.nom AS loc,
                   DATEDIFF(p.date_peremption, CURDATE()) AS days_left
            FROM produits p
            JOIN emplacements e ON e.id = p.emplacement_id
            WHERE e.site_id = ? AND p.statut = 'actif'
              AND (p.nom LIKE ? OR p.numero_lot LIKE ? OR p.reference LIKE ?)
            ORDER BY p.date_peremption ASC
            LIMIT 6
        ");
        $st->execute([$sid, $like, $like, $like]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $days = (int)$r['days_left'];
            $badge = $days < 0 ? 'Périmé' : ($days <= 7 ? 'Critique' : ($days <= 30 ? 'Alerte' : ''));
            $color = $days < 0 ? '#6b7280' : ($days <= 7 ? '#EF4444' : ($days <= 30 ? '#F59E0B' : ''));
            $results[] = [
                'type'        => 'medicament',
                'icon'        => '💊',
                'title'       => $r['nom'],
                'sub'         => $r['loc'] . ($r['numero_lot'] ? ' · Lot ' . $r['numero_lot'] : ''),
                'url'         => '/products.php?edit=' . $r['id'],
                'badge'       => $badge,
                'badge_color' => $color,
            ];
        }
    } catch (Exception $e) {}
}

// ── Emplacements ──────────────────────────────────────────
if (nav_peut($nav_perms ?? [], 'emplacements')) {
    try {
        $st = $pdo->prepare("
            SELECT e.id, e.nom, ep.nom AS parent, e.chaine_froid,
                   COUNT(p.id) AS nb
            FROM emplacements e
            LEFT JOIN emplacements ep ON ep.id = e.parent_id
            LEFT JOIN produits p ON p.emplacement_id = e.id AND p.statut = 'actif'
            WHERE e.site_id = ? AND e.actif = 1 AND e.nom LIKE ?
            GROUP BY e.id
            LIMIT 4
        ");
        $st->execute([$sid, $like]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $results[] = [
                'type'        => 'emplacement',
                'icon'        => $r['chaine_froid'] ? '❄️' : '📍',
                'title'       => $r['nom'],
                'sub'         => ($r['parent'] ? $r['parent'] . ' › ' : '') . $r['nb'] . ' produit(s)',
                'url'         => '/locations.php?id=' . $r['id'],
                'badge'       => '',
                'badge_color' => '',
            ];
        }
    } catch (Exception $e) {}
}

// ── Utilisateurs (admins uniquement) ─────────────────────
if ($_SESSION['super_admin'] ?? false) {
    try {
        $st = $pdo->prepare("
            SELECT id, prenom, nom, login, email, actif
            FROM utilisateurs
            WHERE prenom LIKE ? OR nom LIKE ? OR login LIKE ? OR email LIKE ?
            LIMIT 3
        ");
        $st->execute([$like, $like, $like, $like]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $results[] = [
                'type'        => 'utilisateur',
                'icon'        => '👤',
                'title'       => $r['prenom'] . ' ' . $r['nom'],
                'sub'         => $r['login'] . ' · ' . ($r['email'] ?: 'sans email'),
                'url'         => '/admin/users.php?action=edit&id=' . $r['id'] . '&onglet=utilisateurs',
                'badge'       => $r['actif'] ? '' : 'Inactif',
                'badge_color' => $r['actif'] ? '' : '#6b7280',
            ];
        }
    } catch (Exception $e) {}
}

// ── Alertes ───────────────────────────────────────────────
if (nav_peut($nav_perms ?? [], 'alertes')) {
    try {
        $st = $pdo->prepare("
            SELECT p.id, p.nom, e.nom AS loc,
                   DATEDIFF(p.date_peremption, CURDATE()) AS days_left
            FROM produits p
            JOIN emplacements e ON e.id = p.emplacement_id
            WHERE e.site_id = ? AND p.statut = 'actif'
              AND p.nom LIKE ?
              AND DATEDIFF(p.date_peremption, CURDATE()) <= p.alerte_jours
            LIMIT 3
        ");
        $st->execute([$sid, $like]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $days = (int)$r['days_left'];
            $already = array_column($results, 'url');
            if (in_array('/products.php?edit='.$r['id'], $already)) continue;
            $results[] = [
                'type'        => 'alerte',
                'icon'        => '🔔',
                'title'       => $r['nom'],
                'sub'         => $r['loc'] . ' · ' . ($days < 0 ? 'Périmé depuis '.abs($days).'j' : 'Dans '.$days.'j'),
                'url'         => '/alerts.php',
                'badge'       => $days < 0 ? 'Périmé' : 'Alerte',
                'badge_color' => $days < 0 ? '#6b7280' : '#F59E0B',
            ];
        }
    } catch (Exception $e) {}
}

echo json_encode($results);

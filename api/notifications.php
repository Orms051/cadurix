<?php
/**
 * Cadurix v5 — API Notifications temps réel
 * Appelé toutes les 30s par footer.php
 * Retourne JSON : { count, items }
 */
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store');

// Non connecté = 0
if (empty($_SESSION['user_id'])) {
    echo json_encode(['count' => 0, 'items' => []]);
    exit;
}

require_once __DIR__ . '/../config/db.php';

$uid     = (int)$_SESSION['user_id'];
$site_id = $_SESSION['site_actif_id'] ?? null;
$items   = [];

try {
    // 1. Notifications in-app non lues
    $stmt = $pdo->prepare("
        SELECT id, type, titre, message, lien, cree_le
        FROM notifications
        WHERE (user_id = ? OR user_id IS NULL) AND lu = 0
        ORDER BY cree_le DESC
        LIMIT 10
    ");
    $stmt->execute([$uid]);
    $notifs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($notifs as $n) {
        $items[] = [
            'id'      => $n['id'],
            'type'    => $n['type'],
            'titre'   => $n['titre'],
            'message' => $n['message'],
            'lien'    => $n['lien'] ?? '/alerts.php',
            'temps'   => temps_relatif($n['cree_le']),
            'icone'   => icone_type($n['type']),
        ];
    }

    // 2. Générer des alertes automatiques si site actif
    if ($site_id) {
        // Produits périmés non notifiés
        $stmt_per = $pdo->prepare("
            SELECT COUNT(*) FROM produits p
            JOIN emplacements e ON e.id = p.emplacement_id
            WHERE p.statut = 'actif' AND e.site_id = ?
              AND p.date_peremption < CURDATE()
        ");
        $stmt_per->execute([$site_id]);
        $nb_perime = (int)$stmt_per->fetchColumn();

        if ($nb_perime > 0) {
            $items[] = [
                'id'      => 'auto_perime',
                'type'    => 'peremption',
                'titre'   => "$nb_perime produit(s) périmé(s)",
                'message' => 'Retrait immédiat requis',
                'lien'    => '/alerts.php?tab=expiry',
                'temps'   => 'Maintenant',
                'icone'   => '⚫',
            ];
        }

        // Produits critiques ≤7j
        $stmt_crit = $pdo->prepare("
            SELECT COUNT(*) FROM produits p
            JOIN emplacements e ON e.id = p.emplacement_id
            WHERE p.statut = 'actif' AND e.site_id = ?
              AND p.date_peremption >= CURDATE()
              AND DATEDIFF(p.date_peremption, CURDATE()) <= 7
        ");
        $stmt_crit->execute([$site_id]);
        $nb_critique = (int)$stmt_crit->fetchColumn();

        if ($nb_critique > 0) {
            $items[] = [
                'id'      => 'auto_critique',
                'type'    => 'alerte',
                'titre'   => "$nb_critique alerte(s) critique(s)",
                'message' => 'Péremption dans ≤7 jours',
                'lien'    => '/alerts.php?tab=expiry',
                'temps'   => 'Maintenant',
                'icone'   => '🔴',
            ];
        }

        // Stocks bas
        $stmt_stock = $pdo->prepare("
            SELECT COUNT(*) FROM produits p
            JOIN emplacements e ON e.id = p.emplacement_id
            WHERE p.statut = 'actif' AND e.site_id = ?
              AND p.quantite_min > 0 AND p.quantite <= p.quantite_min
        ");
        $stmt_stock->execute([$site_id]);
        $nb_stock = (int)$stmt_stock->fetchColumn();

        if ($nb_stock > 0) {
            $items[] = [
                'id'      => 'auto_stock',
                'type'    => 'stock',
                'titre'   => "$nb_stock produit(s) en stock bas",
                'message' => 'Réapprovisionnement nécessaire',
                'lien'    => '/alerts.php?tab=lowstock',
                'temps'   => 'Maintenant',
                'icone'   => '📦',
            ];
        }
    }

    // 3. Messages non lus
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM messages m
        JOIN utilisateurs u ON u.id = m.expediteur_id
        WHERE (m.destinataire_id = ? OR m.destinataire_id IS NULL)
          AND m.expediteur_id != ?
          AND m.lu = 0 AND m.parent_id IS NULL
          AND (u.super_admin = 1 OR NOT EXISTS (
              SELECT 1 FROM user_site_permissions usp 
              WHERE usp.user_id = u.id
          ) OR EXISTS (
              SELECT 1 FROM user_site_permissions usp 
              WHERE usp.user_id = u.id AND usp.site_id = ?
          ))
    ");
    $stmt->execute([$uid, $uid, $site_id]);
    $nb_msg = (int)$stmt->fetchColumn();

    if ($nb_msg > 0) {
        $items[] = [
            'id'      => 'auto_msg',
            'type'    => 'message',
            'titre'   => "$nb_msg message(s) non lu(s)",
            'message' => 'Cliquez pour lire',
            'lien'    => '/messaging.php',
            'temps'   => 'Maintenant',
            'icone'   => '💬',
        ];
    }

} catch (Exception $e) {
    echo json_encode(['count' => 0, 'items' => [], 'error' => $e->getMessage()]);
    exit;
}

echo json_encode([
    'count' => count($items),
    'items' => $items,
]);

function temps_relatif(string $date): string {
    $diff = time() - strtotime($date);
    if ($diff < 60)     return 'À l\'instant';
    if ($diff < 3600)   return floor($diff/60).'min';
    if ($diff < 86400)  return floor($diff/3600).'h';
    return date('d/m', strtotime($date));
}

function icone_type(string $type): string {
    return match($type) {
        'peremption' => '⚫',
        'alerte'     => '🔴',
        'stock'      => '📦',
        'message'    => '💬',
        'systeme'    => '⚙️',
        default      => '🔔',
    };
}

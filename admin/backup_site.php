<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.
/**
 * Cadurix — Sauvegarde par site
 * Admin de site : sauvegarde son site uniquement
 * Super admin   : peut sauvegarder n'importe quel site
 */
require_once __DIR__ . '/../includes/auth.php';

if (!isAdmin()) { accessDenied(); exit; }
headers_securite();

$is_super  = (bool)($_SESSION['super_admin'] ?? false);
$my_site   = site_actif();

// Super admin peut choisir le site, admin est limité au sien
if ($is_super && isset($_GET['site_id'])) {
    $target_site = (int)$_GET['site_id'];
} else {
    $target_site = $my_site;
}

if (!$target_site) {
    http_response_code(400);
    die('Site non défini');
}

// Vérifier l'accès au site
if (!$is_super) {
    $chk = $pdo->prepare("SELECT 1 FROM user_site_permissions WHERE user_id=? AND site_id=? AND role IN ('administrateur')");
    $chk->execute([currentUserId(), $target_site]);
    if (!$chk->fetchColumn()) { accessDenied(); exit; }
}

// Récupérer infos du site
$site = $pdo->prepare("SELECT s.*, o.nom AS org_nom FROM sites s JOIN organisations o ON o.id=s.organisation_id WHERE s.id=?");
$site->execute([$target_site]);
$site = $site->fetch(PDO::FETCH_ASSOC);
if (!$site) { http_response_code(404); die('Site introuvable'); }

// Nom du fichier
$filename = sprintf('cadurix_backup_%s_%s_%s.sql',
    preg_replace('/[^a-z0-9]/', '_', strtolower($site['nom'])),
    preg_replace('/[^a-z0-9]/', '_', strtolower($site['org_nom'])),
    date('Y-m-d_His')
);

// Headers téléchargement
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store');
header('Pragma: no-cache');

// ── Génération du SQL ────────────────────────────────────────
$sid = $target_site;

echo "-- ============================================================
";
echo "-- Cadurix v5.4 — Sauvegarde site : {$site['nom']}
";
echo "-- Organisation : {$site['org_nom']}
";
echo "-- Date : " . date('d/m/Y H:i:s') . "
";
echo "-- Généré par : " . h($_SESSION['user_nom'] ?? 'admin') . "
";
echo "-- CONFIDENTIEL — Usage interne uniquement
";
echo "-- ============================================================

";
echo "SET NAMES utf8mb4;
";
echo "SET FOREIGN_KEY_CHECKS = 0;

";

// ── Fonction dump d'une table avec filtre ────────────────────
function dump_table(PDO $pdo, string $table, string $where, array $params, string $comment = ''): void {
    if ($comment) echo "-- $comment
";

    try {
        $stmt = $pdo->prepare("SELECT * FROM `$table` WHERE $where");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        echo "-- Table $table ignorée : " . $e->getMessage() . "

";
        return;
    }

    if (empty($rows)) {
        echo "-- Aucune donnée dans $table pour ce site

";
        return;
    }

    echo "-- Table : $table (" . count($rows) . " ligne(s))
";

    foreach ($rows as $row) {
        $cols = implode(',', array_map(fn($c) => "`$c`", array_keys($row)));
        $vals = implode(',', array_map(function($v) use ($pdo) {
            if ($v === null) return 'NULL';
            return $pdo->quote($v);
        }, array_values($row)));
        echo "INSERT INTO `$table` ($cols) VALUES ($vals);
";
    }
    echo "
";
}

// ── Données du site ──────────────────────────────────────────

// 1. Le site lui-même
dump_table($pdo, 'sites', 'id=?', [$sid], '=== SITE ===');

// 2. Emplacements
dump_table($pdo, 'emplacements', 'site_id=?', [$sid], '=== EMPLACEMENTS ===');

// 3. Produits (via emplacements)
$empl_ids = $pdo->prepare("SELECT id FROM emplacements WHERE site_id=?");
$empl_ids->execute([$sid]);
$empl_list = $empl_ids->fetchAll(PDO::FETCH_COLUMN);

if (!empty($empl_list)) {
    $in = implode(',', array_fill(0, count($empl_list), '?'));
    $stmt = $pdo->prepare("SELECT * FROM produits WHERE emplacement_id IN ($in)");
    $stmt->execute($empl_list);
    $produits = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "-- === PRODUITS (" . count($produits) . ") ===
";
    foreach ($produits as $row) {
        $cols = implode(',', array_map(fn($c) => "`$c`", array_keys($row)));
        $vals = implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote($v), array_values($row)));
        echo "INSERT INTO `produits` ($cols) VALUES ($vals);
";
    }
    echo "
";
}

// 4. Rempotages
dump_table($pdo, 'rempotages', 'site_id=?', [$sid], '=== REMPOTAGES ===');

// 5. Rempotage articles (via rempotages)
$remp_ids = $pdo->prepare("SELECT id FROM rempotages WHERE site_id=?");
$remp_ids->execute([$sid]);
$remp_list = $remp_ids->fetchAll(PDO::FETCH_COLUMN);
if (!empty($remp_list)) {
    $in = implode(',', array_fill(0, count($remp_list), '?'));
    $stmt = $pdo->prepare("SELECT * FROM rempotage_articles WHERE rempotage_id IN ($in)");
    $stmt->execute($remp_list);
    $arts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "-- === REMPOTAGE ARTICLES (" . count($arts) . ") ===
";
    foreach ($arts as $row) {
        $cols = implode(',', array_map(fn($c) => "`$c`", array_keys($row)));
        $vals = implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote($v), array_values($row)));
        echo "INSERT INTO `rempotage_articles` ($cols) VALUES ($vals);
";
    }
    echo "
";
}

// 6. Interventions matériel
dump_table($pdo, 'interventions_materiel', 'site_id=?', [$sid], '=== INTERVENTIONS ===');

// 7. Lignes interventions
$inter_ids = $pdo->prepare("SELECT id FROM interventions_materiel WHERE site_id=?");
$inter_ids->execute([$sid]);
$inter_list = $inter_ids->fetchAll(PDO::FETCH_COLUMN);
if (!empty($inter_list)) {
    $in = implode(',', array_fill(0, count($inter_list), '?'));
    $stmt = $pdo->prepare("SELECT * FROM interventions_lignes WHERE intervention_id IN ($in)");
    $stmt->execute($inter_list);
    $lignes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "-- === LIGNES INTERVENTIONS (" . count($lignes) . ") ===
";
    foreach ($lignes as $row) {
        $cols = implode(',', array_map(fn($c) => "`$c`", array_keys($row)));
        $vals = implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote($v), array_values($row)));
        echo "INSERT INTO `interventions_lignes` ($cols) VALUES ($vals);
";
    }
    echo "
";
}

// 8. Fiches remplies
dump_table($pdo, 'fiches_remplies', 'site_id=?', [$sid], '=== FICHES REMPLIES ===');

// 9. Réponses fiches
$fiche_ids = $pdo->prepare("SELECT id FROM fiches_remplies WHERE site_id=?");
$fiche_ids->execute([$sid]);
$fiche_list = $fiche_ids->fetchAll(PDO::FETCH_COLUMN);
if (!empty($fiche_list)) {
    $in = implode(',', array_fill(0, count($fiche_list), '?'));
    $stmt = $pdo->prepare("SELECT * FROM fiche_reponses WHERE fiche_remplie_id IN ($in)");
    $stmt->execute($fiche_list);
    $reps = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "-- === RÉPONSES FICHES (" . count($reps) . ") ===
";
    foreach ($reps as $row) {
        $cols = implode(',', array_map(fn($c) => "`$c`", array_keys($row)));
        $vals = implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote($v), array_values($row)));
        echo "INSERT INTO `fiche_reponses` ($cols) VALUES ($vals);
";
    }
    echo "
";
}

// 10. Matériels biomédical
dump_table($pdo, 'materiels', 'site_id=?', [$sid], '=== MATÉRIELS ===');

// 11. SSE équipements
try {
    dump_table($pdo, 'sse_equipements', 'site_id=?', [$sid], '=== SSE ÉQUIPEMENTS ===');
    dump_table($pdo, 'sse_consommables', 'site_id=?', [$sid], '=== SSE CONSOMMABLES ===');
    dump_table($pdo, 'sse_categories', 'site_id=?', [$sid], '=== SSE CATÉGORIES ===');

    // SSE contrôles
    $eq_ids = $pdo->prepare("SELECT id FROM sse_equipements WHERE site_id=?");
    $eq_ids->execute([$sid]);
    $eq_list = $eq_ids->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($eq_list)) {
        $in = implode(',', array_fill(0, count($eq_list), '?'));
        $stmt = $pdo->prepare("SELECT * FROM sse_controles WHERE equipement_id IN ($in)");
        $stmt->execute($eq_list);
        $ctrls = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "-- === SSE CONTRÔLES (" . count($ctrls) . ") ===
";
        foreach ($ctrls as $row) {
            $cols = implode(',', array_map(fn($c) => "`$c`", array_keys($row)));
            $vals = implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote($v), array_values($row)));
            echo "INSERT INTO `sse_controles` ($cols) VALUES ($vals);
";
        }
        echo "
";
    }
} catch (Exception $e) {
    echo "-- Module SSE non disponible

";
}

// 12. Dotations réglementaires
try {
    dump_table($pdo, 'dotations_reglementaires', 'site_id=?', [$sid], '=== DOTATIONS ===');
} catch (Exception $e) {}

// 13. Alertes
dump_table($pdo, 'alertes', 'site_id=?', [$sid], '=== ALERTES ===');

// 14. Mouvements stock
try {
    if (!empty($empl_list)) {
        $in = implode(',', array_fill(0, count($empl_list), '?'));
        $stmt = $pdo->prepare("SELECT ms.* FROM mouvements_stock ms JOIN produits p ON p.id=ms.produit_id JOIN emplacements e ON e.id=p.emplacement_id WHERE e.site_id=?");
        $stmt->execute([$sid]);
        $mvts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "-- === MOUVEMENTS STOCK (" . count($mvts) . ") ===
";
        foreach ($mvts as $row) {
            $cols = implode(',', array_map(fn($c) => "`$c`", array_keys($row)));
            $vals = implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote($v), array_values($row)));
            echo "INSERT INTO `mouvements_stock` ($cols) VALUES ($vals);
";
        }
        echo "
";
    }
} catch (Exception $e) {}

// 15. Permissions utilisateurs du site (sans les MDP !)
$stmt = $pdo->prepare("
    SELECT usp.*, u.nom, u.prenom, u.login, u.email
    FROM user_site_permissions usp
    JOIN utilisateurs u ON u.id = usp.user_id
    WHERE usp.site_id = ?
");
$stmt->execute([$sid]);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "-- === UTILISATEURS DU SITE (" . count($users) . ") — sans mots de passe ===
";
foreach ($users as $u) {
    echo "-- " . $u['prenom'] . ' ' . $u['nom'] . ' (' . $u['login'] . ') — rôle: ' . $u['role'] . "
";
}
echo "
";

// ── Fin ──────────────────────────────────────────────────────
echo "SET FOREIGN_KEY_CHECKS = 1;

";
echo "-- ============================================================
";
echo "-- Fin de la sauvegarde — " . date('d/m/Y H:i:s') . "
";
echo "-- Nombre de tables sauvegardées : 15
";
echo "-- ============================================================
";

// Log audit
try {
    $pdo->prepare("INSERT INTO audit_log (user_id,action,table_cible,valeur_apres,ip) VALUES (?,'BACKUP_SITE','sites',?,?)")
        ->execute([currentUserId(), "Sauvegarde site {$site['nom']}", $_SERVER['REMOTE_ADDR'] ?? '']);
} catch(Exception $e) {}

exit;

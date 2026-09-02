<?php
// © Cadurix v5.4 — Traçabilité des lots
require_once 'includes/auth.php';
$pageTitle = 'Traçabilité des lots';
$site_id   = site_actif();
if (!$site_id) { header('Location: dashboard.php'); exit; }

// ── Construit le WHERE de l'historique (partagé endpoints + rendu) ──
function tr_activite_where(PDO $pdo, string $cat, string $search, int $site_id, bool $isSuper): string {
    $wa = [];
    if     ($cat === 'produits')     $wa[] = "a.table_cible='produits'";
    elseif ($cat === 'emplacements') $wa[] = "a.table_cible='emplacements'";
    elseif ($cat === 'materiels')    $wa[] = "a.table_cible='materiels'";
    elseif ($cat === 'admin')        $wa[] = "a.table_cible IN ('role_permissions','site_role_permissions','utilisateurs','sites','organisations','parametres','ai_settings','modules')";
    else                             $wa[] = "a.action NOT IN ('CONNEXION_REUSSIE','CONNEXION_ECHEC','DECONNEXION')";
    if (!$isSuper) $wa[] = "(a.table_cible<>'produits' OR e.site_id=".(int)$site_id." OR pr.id IS NULL)";
    if ($search !== '') { $s = $pdo->quote('%'.$search.'%'); $wa[] = "(pr.nom LIKE $s OR a.action LIKE $s OR a.valeur_avant LIKE $s OR a.valeur_apres LIKE $s)"; }
    return "FROM audit_log a
            LEFT JOIN utilisateurs u ON u.id=a.user_id
            LEFT JOIN produits pr ON (a.table_cible='produits' AND pr.id=a.enregistrement_id)
            LEFT JOIN emplacements e ON e.id=pr.emplacement_id
            ".($wa ? 'WHERE '.implode(' AND ', $wa) : '');
}

// ── Export CSV de l'historique (archive réglementaire) ──
if (($_GET['export'] ?? '') === 'csv') {
    $cat    = in_array($_GET['cat'] ?? '', ['produits','emplacements','materiels','admin','tous'], true) ? $_GET['cat'] : 'produits';
    $search = trim($_GET['q'] ?? '');
    $base   = tr_activite_where($pdo, $cat, $search, (int)$site_id, !empty($_SESSION['super_admin']));
    $rows   = $pdo->query("SELECT a.action, a.cree_le, a.valeur_avant, a.valeur_apres, u.prenom, u.nom AS unom, pr.nom AS prod_nom, a.enregistrement_id, a.table_cible $base ORDER BY a.cree_le DESC LIMIT 5000")->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="tracabilite_'.$cat.'_'.date('Y-m-d').'.csv"');
    echo "\xEF\xBB\xBF"; // BOM UTF-8 (Excel)
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date', 'Utilisateur', 'Action', 'Cible', 'Changements (avant → après)'], ';');
    foreach ($rows as $r) {
        $who   = trim(($r['prenom'] ?? '').' '.($r['unom'] ?? '')) ?: 'Système';
        $cible = $r['prod_nom'] ?: '';
        if (!$cible) { $d = json_decode((string)$r['valeur_apres'], true) ?: json_decode((string)$r['valeur_avant'], true); if (is_array($d) && !empty($d['nom'])) $cible = $d['nom']; }
        if (!$cible && $r['enregistrement_id']) $cible = ($r['table_cible'] ? $r['table_cible'].' #' : '#').$r['enregistrement_id'];
        $a = json_decode((string)$r['valeur_avant'], true); $b = json_decode((string)$r['valeur_apres'], true);
        $ch = [];
        if (is_array($b)) foreach ($b as $k => $nv) {
            if (is_array($nv) || in_array($k, ['masse','copilote','saisie_rapide'], true)) continue;
            $ov = (is_array($a) && array_key_exists($k, $a)) ? $a[$k] : null;
            if (is_array($ov) || (string)$ov === (string)$nv) continue;
            $ch[] = $k.' : '.($ov ?? '∅').' → '.($nv ?? '∅');
        }
        fputcsv($out, [date('d/m/Y H:i', strtotime($r['cree_le'])), $who, $r['action'], $cible, implode(' | ', $ch)], ';');
    }
    fclose($out); exit;
}

// ── Analyse IA de l'activité récente ──
if (($_GET['ajax'] ?? '') === 'ia_activite' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!function_exists('csrf_verifier') || !csrf_verifier()) { echo json_encode(['error' => 'Session expirée, recharge la page.']); exit; }
    $ai_actif = $pdo->query("SELECT valeur FROM ai_settings WHERE cle='actif'")->fetchColumn();
    $api_key  = $pdo->query("SELECT valeur FROM ai_settings WHERE cle='api_key'")->fetchColumn();
    $model    = $pdo->query("SELECT valeur FROM ai_settings WHERE cle='model'")->fetchColumn() ?: 'claude-haiku-4-5-20251001';
    if ($ai_actif !== '1' || !$api_key) { echo json_encode(['error' => 'IA non configurée (Admin → IA).']); exit; }
    $cat    = in_array($_POST['cat'] ?? '', ['produits','emplacements','materiels','admin','tous'], true) ? $_POST['cat'] : 'produits';
    $search = trim($_POST['q'] ?? '');
    $base   = tr_activite_where($pdo, $cat, $search, (int)$site_id, !empty($_SESSION['super_admin']));
    $rows   = $pdo->query("SELECT a.action, a.cree_le, a.valeur_avant, a.valeur_apres, u.prenom, u.nom AS unom, pr.nom AS prod_nom $base ORDER BY a.cree_le DESC LIMIT 60")->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) { echo json_encode(['ok' => true, 'texte' => "Aucune activité à analyser sur ce filtre."]); exit; }
    $ctx = '';
    foreach ($rows as $r) {
        $who = trim(($r['prenom'] ?? '').' '.($r['unom'] ?? '')) ?: 'Systeme';
        $line = '- '.date('d/m H:i', strtotime($r['cree_le'])).' | '.$who.' | '.$r['action'].($r['prod_nom'] ? ' | '.$r['prod_nom'] : '');
        $a = json_decode((string)$r['valeur_avant'], true); $b = json_decode((string)$r['valeur_apres'], true);
        if (is_array($b)) { $ch = []; foreach ($b as $k => $nv) { if (is_array($nv) || in_array($k, ['masse','copilote','saisie_rapide'], true)) continue; $ov = (is_array($a) && array_key_exists($k, $a)) ? $a[$k] : null; if (is_array($ov) || (string)$ov === (string)$nv) continue; $ch[] = "$k: ".($ov ?? 'vide').'->'.($nv ?? 'vide'); } if ($ch) $line .= ' ['.implode(', ', array_slice($ch, 0, 6)).']'; }
        $ctx .= $line."\n";
    }
    $prompt = "Tu es le copilote de Cadurix (gestion de materiel medical SDIS/SSSM). Analyse le JOURNAL D'ACTIVITE ci-dessous (traçabilité). En francais, court et factuel, SANS markdown, SANS rien inventer : 1) une phrase de synthese (periode, volume, qui est actif), 2) 3 a 5 points concrets = changements notables (retraits, renouvellements, corrections de fiche, ajustements) et toute anomalie (retraits repetes, modifs suspectes). Pas de conseil medical.\n\nJOURNAL :\n$ctx";
    $is_prod = str_contains($_SERVER['HTTP_HOST'] ?? '', 'cadurix.fr');
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>json_encode(['model'=>$model,'max_tokens'=>500,'messages'=>[['role'=>'user','content'=>$prompt]]]),
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','x-api-key: '.$api_key,'anthropic-version: 2023-06-01'],
        CURLOPT_TIMEOUT=>40, CURLOPT_SSL_VERIFYPEER=>$is_prod, CURLOPT_SSL_VERIFYHOST=>$is_prod?2:0]);
    $resp = curl_exec($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); $cerr = curl_error($ch); curl_close($ch);
    if ($resp === false || $cerr) { echo json_encode(['error' => "Reseau IA : $cerr"]); exit; }
    $jr = json_decode($resp, true);
    if ($http !== 200 || empty($jr['content'][0]['text'])) { echo json_encode(['error' => ($jr['error']['message'] ?? "Erreur IA HTTP $http")]); exit; }
    echo json_encode(['ok' => true, 'texte' => $jr['content'][0]['text']], JSON_UNESCAPED_UNICODE); exit;
}

$success = $error = '';

// ── POST ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_rappel') {
        $lot    = trim($_POST['numero_lot'] ?? '');
        $table  = in_array($_POST['source_table']??'', ['produits','sse_consommables','materiels']) ? $_POST['source_table'] : 'produits';
        $motif  = trim($_POST['motif'] ?? '');
        $date   = $_POST['date_rappel'] ?? date('Y-m-d');
        if (!$lot) { $error = 'Numéro de lot requis.'; }
        else {
            $pdo->prepare("INSERT INTO lots_rappels (site_id,numero_lot,source_table,motif,date_rappel,actif,cree_par) VALUES (?,?,?,?,?,1,?)")
                ->execute([$site_id, $lot, $table, $motif, $date, currentUserId()]);
            $success = "Alerte rappel créée pour le lot $lot.";
        }
    } elseif ($action === 'close_rappel') {
        $pdo->prepare("UPDATE lots_rappels SET actif=0 WHERE id=? AND site_id=?")
            ->execute([(int)$_POST['id'], $site_id]);
        $success = 'Rappel clôturé.';
    } elseif ($action === 'delete_rappel') {
        $pdo->prepare("DELETE FROM lots_rappels WHERE id=? AND site_id=?")
            ->execute([(int)$_POST['id'], $site_id]);
        $success = 'Rappel supprimé.';
    }
}

// ── Paramètres ─────────────────────────────────────────────
$search   = trim($_GET['q'] ?? '');
$filtre   = $_GET['type'] ?? 'tous';
$page     = max(1, (int)($_GET['p'] ?? 1));
$per_page = 25;
$offset   = ($page - 1) * $per_page;

// ── Vue : historique d'activité (défaut) ou catalogue des lots ──
$vue = in_array($_GET['vue'] ?? '', ['lots','activite'], true) ? $_GET['vue'] : 'activite';
$cat = in_array($_GET['cat'] ?? '', ['produits','emplacements','materiels','admin','tous'], true) ? $_GET['cat'] : 'produits';

// Libellés des champs pour afficher un changement (avant → après)
$TR_FIELD_LBL = ['nom'=>'Nom','dci'=>'DCI','dosage'=>'Dosage','forme'=>'Forme','numero_lot'=>'N° lot','quantite'=>'Quantité','quantite_min'=>'Seuil mini','unite'=>'Unité','date_peremption'=>'Péremption','alerte_jours'=>'Alerte (j)','statut'=>'Statut','chaine_froid'=>'Chaîne du froid','emplacement_id'=>'Emplacement (id)','ean'=>'EAN','gtin'=>'GTIN','reference'=>'Référence','prix'=>'Prix'];
// Métadonnées d'action : [icône, libellé, couleur]
$TR_ACT = [
  'CREATION_PRODUIT'=>['➕','Médicament créé','#10b981'], 'MODIF_PRODUIT'=>['✏️','Médicament modifié','#3b82f6'],
  'MODIF_PEREMPTION'=>['📅','Péremption modifiée','#f59e0b'], 'ARCHIVAGE_PRODUIT'=>['📦','Médicament archivé','#94a3b8'],
  'RETRAIT_PRODUIT'=>['🗑️','Médicament retiré','#ef4444'], 'SUPPRESSION_PRODUIT'=>['❌','Médicament supprimé','#ef4444'],
  'RENOUVELLEMENT_LOT'=>['♻️','Lot renouvelé','#10b981'], 'REMPOTAGE'=>['🔄','Rempotage','#10b981'],
  'REMPOTAGE_VOCAL_IA'=>['🎤','Rempotage (vocal IA)','#8b5cf6'],
  'CREATION_EMPLACEMENT'=>['📍','Emplacement créé','#10b981'], 'MODIF_EMPLACEMENT'=>['📍','Emplacement modifié','#3b82f6'],
  'CREATION_MATERIEL'=>['🩺','Matériel créé','#10b981'], 'MODIF_MATERIEL'=>['🩺','Matériel modifié','#3b82f6'],
  'INTERVENTION_MATERIEL'=>['🔧','Intervention matériel','#f59e0b'], 'FICHE_VALIDEE'=>['📋','Fiche validée','#10b981'],
  'DOTATION_COMPLETION_PUI'=>['🎒','Dotation complétée (PUI)','#10b981'],
  'STUP_CASSE'=>['💔','Stupéfiant cassé / détruit','#ef4444'], 'STUP_ENTREE'=>['🔒','Stupéfiant — entrée','#10b981'], 'STUP_SORTIE'=>['🔒','Stupéfiant — sortie','#f59e0b'],
  'MODIF_ORGANISATION'=>['🏢','Organisation modifiée','#3b82f6'], 'MODIF_SETTINGS'=>['⚙️','Réglages modifiés','#94a3b8'], 'MODIF_PROFIL'=>['👤','Profil modifié','#94a3b8'],
  'MODIF_ROLE_PERMISSIONS'=>['🔐','Permissions de rôle modifiées','#8b5cf6'], 'MODIF_PERMISSIONS'=>['🔐','Permissions modifiées','#8b5cf6'],
  'MODIF_SITE_PERMISSIONS'=>['🔐','Permissions site modifiées','#8b5cf6'], 'MODIF_ACCES'=>['🔐','Accès modifié','#8b5cf6'], 'CREATION_UTILISATEUR'=>['👥','Utilisateur créé','#10b981'],
];
function tr_diff($avant, $apres, array $labels): array {
    $a = is_string($avant) ? json_decode($avant, true) : $avant;
    $b = is_string($apres) ? json_decode($apres, true) : $apres;
    if (!is_array($b)) return [];
    $out = [];
    foreach ($b as $k => $nv) {
        if (in_array($k, ['masse','copilote','saisie_rapide'], true) || is_array($nv)) continue;
        $ov = (is_array($a) && array_key_exists($k, $a)) ? $a[$k] : null;
        if (is_array($ov) || (string)$ov === (string)$nv) continue;
        $out[] = [$labels[$k] ?? $k, $ov, $nv];
    }
    return $out;
}
function tr_json_nom($j): string {
    $d = is_string($j) ? json_decode($j, true) : $j;
    return is_array($d) && !empty($d['nom']) ? (string)$d['nom'] : '';
}

// ── Rappels actifs ─────────────────────────────────────────
$rappels = $pdo->prepare("SELECT * FROM lots_rappels WHERE site_id=? AND actif=1 ORDER BY date_rappel DESC");
$rappels->execute([$site_id]);
$rappels = $rappels->fetchAll(PDO::FETCH_ASSOC);
$lots_rappeles = array_column($rappels, 'numero_lot');

// ── Vue unifiée des lots ───────────────────────────────────
// On construit une UNION des 3 tables
$rows = []; $total_row = 0; $total_pages = 0; $stats = ['expires'=>0,'bientot'=>0];
if ($vue === 'lots') {
$where_prod = "e.site_id = $site_id AND p.numero_lot IS NOT NULL AND p.numero_lot != ''";
$where_sse  = "sc.site_id = $site_id AND sc.numero_lot IS NOT NULL AND sc.numero_lot != ''";
$where_mat  = "m.site_id = $site_id AND m.numero_serie IS NOT NULL AND m.numero_serie != ''";

if ($search) {
    $s = $pdo->quote("%$search%");
    $where_prod .= " AND (p.numero_lot LIKE $s OR p.nom LIKE $s OR p.reference LIKE $s)";
    $where_sse  .= " AND (sc.numero_lot LIKE $s OR sc.nom LIKE $s OR sc.reference LIKE $s)";
    $where_mat  .= " AND (m.numero_serie LIKE $s OR m.nom LIKE $s OR m.reference LIKE $s OR m.marque LIKE $s)";
}

$type_filter_prod = ($filtre === 'tous' || $filtre === 'medicament') ? '' : 'AND 1=0';
$type_filter_sse  = ($filtre === 'tous' || $filtre === 'sse')        ? '' : 'AND 1=0';
$type_filter_mat  = ($filtre === 'tous' || $filtre === 'materiel')   ? '' : 'AND 1=0';

$union_sql = "
    SELECT
        p.id, 'produits' AS source, 'Médicament' AS type_label, '💊' AS icone,
        p.nom, p.numero_lot AS lot, p.reference,
        p.date_peremption AS date_fin,
        p.quantite, p.unite, p.statut,
        e.nom AS emplacement, p.cree_le, p.modifie_le
    FROM produits p
    JOIN emplacements e ON e.id = p.emplacement_id
    WHERE $where_prod $type_filter_prod

    UNION ALL

    SELECT
        sc.id, 'sse_consommables' AS source, 'SSE/NRBC' AS type_label, '🧪' AS icone,
        sc.nom, sc.numero_lot AS lot, sc.reference,
        sc.date_peremption AS date_fin,
        sc.quantite, sc.unite, sc.statut,
        sc.localisation AS emplacement, sc.cree_le, sc.modifie_le
    FROM sse_consommables sc
    WHERE $where_sse $type_filter_sse

    UNION ALL

    SELECT
        m.id, 'materiels' AS source, 'Matériel' AS type_label, '🩺' AS icone,
        m.nom, m.numero_serie AS lot, m.reference,
        m.contrat_fin AS date_fin,
        1 AS quantite, 'unité' AS unite, m.statut,
        COALESCE(em.nom, '') AS emplacement, m.cree_le, m.modifie_le
    FROM materiels m
    LEFT JOIN emplacements em ON em.id = m.emplacement_id
    WHERE $where_mat $type_filter_mat
";

// Compter total
$total_row = $pdo->query("SELECT COUNT(*) FROM ($union_sql) t")->fetchColumn();
$total_pages = ceil($total_row / $per_page);

// Récupérer la page
$rows = $pdo->query("SELECT * FROM ($union_sql) t ORDER BY modifie_le DESC LIMIT $per_page OFFSET $offset")->fetchAll(PDO::FETCH_ASSOC);

// Stats globales
$stats = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN date_fin < CURDATE() THEN 1 ELSE 0 END) AS expires,
        SUM(CASE WHEN date_fin BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS bientot
    FROM ($union_sql) t
")->fetch(PDO::FETCH_ASSOC);
} // ── fin vue 'lots' ──

// ── Historique d'activité (audit_log) ──
$acts = []; $act_total = 0; $act_pages = 0;
if ($vue === 'activite') {
    $wa = [];
    if     ($cat === 'produits')     $wa[] = "a.table_cible='produits'";
    elseif ($cat === 'emplacements') $wa[] = "a.table_cible='emplacements'";
    elseif ($cat === 'materiels')    $wa[] = "a.table_cible='materiels'";
    elseif ($cat === 'admin')        $wa[] = "a.table_cible IN ('role_permissions','site_role_permissions','utilisateurs','sites','organisations','parametres','ai_settings','modules')";
    else /* tous */                  $wa[] = "a.action NOT IN ('CONNEXION_REUSSIE','CONNEXION_ECHEC','DECONNEXION')";
    // Périmètre : non-super-admin ne voit que les produits de son site (les produits disparus restent visibles)
    if (empty($_SESSION['super_admin'])) $wa[] = "(a.table_cible<>'produits' OR e.site_id=".(int)$site_id." OR pr.id IS NULL)";
    if ($search) { $s = $pdo->quote('%'.$search.'%'); $wa[] = "(pr.nom LIKE $s OR a.action LIKE $s OR a.valeur_avant LIKE $s OR a.valeur_apres LIKE $s)"; }
    $wsql = $wa ? ('WHERE '.implode(' AND ', $wa)) : '';
    $base = "FROM audit_log a
             LEFT JOIN utilisateurs u ON u.id=a.user_id
             LEFT JOIN produits pr ON (a.table_cible='produits' AND pr.id=a.enregistrement_id)
             LEFT JOIN emplacements e ON e.id=pr.emplacement_id
             $wsql";
    $act_total = (int)$pdo->query("SELECT COUNT(*) $base")->fetchColumn();
    $act_pages = (int)ceil($act_total / $per_page);
    $acts = $pdo->query("SELECT a.*, u.prenom, u.nom AS unom, pr.nom AS prod_nom, e.nom AS empl_nom $base ORDER BY a.cree_le DESC LIMIT $per_page OFFSET $offset")->fetchAll(PDO::FETCH_ASSOC);
}

require_once 'includes/header.php';
?>
<style>
/* ── Stats ── */
.tr-stats { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:24px; }
.tr-stat  { background:var(--bg2); border:1px solid var(--border); border-radius:12px; padding:16px; text-align:center; }
.tr-stat-n{ font-size:1.8rem; font-weight:800; line-height:1.1; }
.tr-stat-l{ font-size:.72rem; text-transform:uppercase; letter-spacing:.06em; color:var(--text3); margin-top:4px; }

/* ── Rappels ── */
.rappel-banner { background:rgba(239,68,68,.08); border:1px solid rgba(239,68,68,.3); border-radius:12px; padding:14px 18px; margin-bottom:20px; }
.rappel-item   { display:flex; align-items:center; gap:12px; padding:8px 0; border-bottom:1px solid rgba(239,68,68,.15); }
.rappel-item:last-child { border-bottom:none; }

/* ── Filtres ── */
.tr-toolbar { display:flex; gap:10px; align-items:center; margin-bottom:16px; flex-wrap:wrap; }
.tr-search  { flex:1; min-width:200px; display:flex; align-items:center; gap:8px; background:var(--bg2); border:1px solid var(--border); border-radius:9px; padding:7px 12px; }
.tr-search input { background:none; border:none; color:var(--text); flex:1; outline:none; font-size:.88rem; }
.tr-filter  { padding:6px 14px; border-radius:20px; font-size:.78rem; font-weight:600; border:1px solid var(--border); background:var(--glass); color:var(--text2); text-decoration:none; transition:.15s; white-space:nowrap; }
.tr-filter:hover, .tr-filter.on { background:var(--primary); border-color:var(--primary); color:white; }

/* ── Table ── */
.tr-table   { width:100%; border-collapse:collapse; background:var(--bg2); border-radius:12px; overflow:hidden; border:1px solid var(--border); }
.tr-table th{ padding:10px 14px; font-size:.7rem; color:var(--text3); text-transform:uppercase; font-weight:700; letter-spacing:.05em; border-bottom:1px solid var(--border); text-align:left; white-space:nowrap; background:var(--bg3); }
.tr-table td{ padding:10px 14px; border-bottom:1px solid var(--border); vertical-align:middle; font-size:.85rem; }
.tr-table tr:last-child td { border-bottom:none; }
.tr-table tr:hover td { background:var(--glass); }
.tr-table tr.rappel-row td { background:rgba(239,68,68,.04); }

.lot-badge  { font-family:var(--mono,monospace); font-size:.78rem; font-weight:700; padding:3px 9px; border-radius:6px; background:var(--bg3); border:1px solid var(--border); display:inline-block; }
.lot-badge.danger { background:rgba(239,68,68,.12); border-color:rgba(239,68,68,.3); color:#f87171; }

.type-pill  { display:inline-flex; align-items:center; gap:4px; padding:2px 8px; border-radius:20px; font-size:.72rem; font-weight:600; }

/* ── Modal ── */
.tr-modal   { display:none; position:fixed; inset:0; background:rgba(0,0,0,.65); z-index:9999; align-items:center; justify-content:center; }
.tr-modal.on{ display:flex; }
.tr-modal-box{ background:var(--bg2); border:1px solid var(--border2); border-radius:16px; padding:28px; width:90%; max-width:520px; box-shadow:0 24px 60px rgba(0,0,0,.5); }
.tr-label   { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--text3); display:block; margin-bottom:5px; }
.tr-input   { width:100%; background:var(--bg); border:1px solid var(--border); color:var(--text); border-radius:8px; padding:8px 12px; font-family:inherit; font-size:.88rem; box-sizing:border-box; }
.tr-input:focus { outline:none; border-color:var(--primary); }

/* ── Pagination ── */
.tr-pagination { display:flex; gap:6px; justify-content:center; align-items:center; margin-top:16px; flex-wrap:wrap; }
.tr-page { padding:5px 12px; border-radius:7px; border:1px solid var(--border); background:var(--glass); color:var(--text2); font-size:.82rem; text-decoration:none; transition:.15s; }
.tr-page:hover, .tr-page.on { background:var(--primary); border-color:var(--primary); color:white; }

/* ── Historique d'activité ── */
.tr-timeline { display:flex; flex-direction:column; gap:10px; }
.tr-ev { display:flex; gap:13px; background:var(--bg2); border:1px solid var(--border); border-radius:12px; padding:13px 15px; }
.tr-ev-ic { width:38px; height:38px; border-radius:11px; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:1.05rem; }
.tr-ev-body { flex:1; min-width:0; }
.tr-ev-head { display:flex; align-items:baseline; gap:10px; flex-wrap:wrap; }
.tr-ev-act { font-weight:800; font-size:.9rem; }
.tr-ev-cible { font-weight:600; font-size:.85rem; color:var(--text); }
.tr-ev-when { margin-left:auto; font-size:.72rem; color:var(--text3); white-space:nowrap; }
.tr-ev-who { font-size:.75rem; color:var(--text3); margin-top:2px; }
.tr-diffs { margin-top:9px; display:flex; flex-direction:column; gap:5px; }
.tr-diff { display:flex; align-items:center; gap:8px; flex-wrap:wrap; font-size:.8rem; background:var(--bg3); border:1px solid var(--border); border-radius:8px; padding:5px 10px; }
.tr-diff .k { font-weight:700; color:var(--text2); min-width:92px; }
.tr-diff .o { color:var(--text3); text-decoration:line-through; }
.tr-diff .arr { color:var(--text3); }
.tr-diff .n { font-weight:700; color:var(--text); }

/* ── Analyse IA ── */
.tr-ia { border:1px solid var(--border); border-radius:14px; background:linear-gradient(135deg,rgba(59,130,246,.09),rgba(139,92,246,.07)),var(--bg2); padding:16px 18px; margin-bottom:16px; }
.tr-ia-h { display:flex; align-items:center; gap:10px; font-size:.95rem; margin-bottom:8px; }
.tr-ia-orb { width:34px; height:34px; border-radius:10px; display:flex; align-items:center; justify-content:center; background:linear-gradient(135deg,#3b82f6,#8b5cf6); font-size:1rem; }
.tr-ia-b { font-size:.87rem; color:var(--text2); line-height:1.6; white-space:pre-wrap; }
</style>

<!-- Header -->
<div class="page-header" style="margin-bottom:20px">
  <div class="page-title-group">
    <div class="page-title">🔍 Traçabilité</div>
    <div class="page-sub"><?= $vue==='activite' ? "Journal de tout ce qui est fait — qui, quoi, quand" : 'Catalogue des lots — Médicaments · SSE/NRBC · Matériel' ?> — <?= h($site_actif_nom ?? '') ?></div>
  </div>
  <div class="page-actions">
    <?php if ($vue==='lots'): ?><button onclick="document.getElementById('modal-rappel').classList.add('on')" class="btn btn-danger">⚠️ Créer alerte rappel</button><?php endif; ?>
  </div>
</div>

<?php if ($success): ?><div class="flash flash-success" style="margin-bottom:16px">✅ <?= h($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="flash flash-error"   style="margin-bottom:16px">⚠️ <?= h($error) ?></div><?php endif; ?>

<!-- Bascule de vue -->
<div style="display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap">
  <a href="?vue=activite" class="tr-filter <?= $vue==='activite'?'on':'' ?>">📜 Historique d'activité</a>
  <a href="?vue=lots" class="tr-filter <?= $vue==='lots'?'on':'' ?>">📦 Catalogue des lots</a>
</div>

<?php if ($vue === 'lots'): ?>
<!-- Rappels actifs -->
<?php if (!empty($rappels)): ?>
<div class="rappel-banner">
  <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
    <span style="font-size:1.3rem">🚨</span>
    <div>
      <div style="font-weight:700;font-size:.92rem;color:#f87171"><?= count($rappels) ?> rappel<?= count($rappels)>1?'s':'' ?> actif<?= count($rappels)>1?'s':'' ?></div>
      <div style="font-size:.75rem;color:var(--text3)">Les lots concernés sont surlignés dans le tableau</div>
    </div>
  </div>
  <?php foreach ($rappels as $r): ?>
  <div class="rappel-item">
    <span style="font-size:1.1rem">⚠️</span>
    <div style="flex:1;min-width:0">
      <div style="font-weight:700;font-size:.85rem">Lot : <span style="font-family:monospace;color:#f87171"><?= h($r['numero_lot']) ?></span>
        <span style="font-size:.72rem;color:var(--text3);margin-left:8px"><?= match($r['source_table']){ 'produits'=>'💊 Médicament','sse_consommables'=>'🧪 SSE','materiels'=>'🩺 Matériel',default=>'' } ?></span>
      </div>
      <?php if ($r['motif']): ?><div style="font-size:.75rem;color:var(--text3)"><?= h($r['motif']) ?></div><?php endif; ?>
      <div style="font-size:.72rem;color:var(--text3)">Créé le <?= date('d/m/Y', strtotime($r['date_rappel'])) ?></div>
    </div>
    <div style="display:flex;gap:6px">
      <a href="?q=<?= urlencode($r['numero_lot']) ?>" class="btn btn-ghost btn-xs">🔍 Voir</a>
      <form method="post" style="display:contents">
        <?= csrf_field() ?><input type="hidden" name="action" value="close_rappel"><input type="hidden" name="id" value="<?= $r['id'] ?>">
        <button class="btn btn-ghost btn-xs" title="Clôturer">✅</button>
      </form>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Stats -->
<div class="tr-stats">
  <div class="tr-stat">
    <div class="tr-stat-n"><?= $total_row ?></div>
    <div class="tr-stat-l">Entrées tracées</div>
  </div>
  <div class="tr-stat" style="background:rgba(239,68,68,.06);border-color:rgba(239,68,68,.25)">
    <div class="tr-stat-n" style="color:#f87171"><?= $stats['expires'] ?></div>
    <div class="tr-stat-l">⏰ Périmés</div>
  </div>
  <div class="tr-stat" style="background:rgba(245,158,11,.06);border-color:rgba(245,158,11,.25)">
    <div class="tr-stat-n" style="color:#fbbf24"><?= $stats['bientot'] ?></div>
    <div class="tr-stat-l">⚠️ Expirent &lt; 30j</div>
  </div>
  <div class="tr-stat" style="background:rgba(239,68,68,.06);border-color:rgba(239,68,68,.25)">
    <div class="tr-stat-n" style="color:#f87171"><?= count($rappels) ?></div>
    <div class="tr-stat-l">🚨 Rappels actifs</div>
  </div>
</div>

<!-- Toolbar -->
<form method="get" class="tr-toolbar">
  <div class="tr-search">
    <span style="color:var(--text3)">🔍</span>
    <input type="text" name="q" value="<?= h($search) ?>" placeholder="Rechercher lot, nom, référence…" oninput="this.form.submit()">
    <?php if ($search): ?><a href="?" style="color:var(--text3);text-decoration:none;font-size:.85rem">✕</a><?php endif; ?>
  </div>
  <input type="hidden" name="type" value="<?= h($filtre) ?>">
</form>
<div style="display:flex;gap:6px;margin-bottom:16px;flex-wrap:wrap">
  <?php foreach (['tous'=>'🔎 Tous','medicament'=>'💊 Médicaments','sse'=>'🧪 SSE/NRBC','materiel'=>'🩺 Matériel'] as $k=>$v): ?>
  <a href="?<?= $search?"q=".urlencode($search)."&":'' ?>type=<?= $k ?>" class="tr-filter <?= $filtre===$k?'on':'' ?>"><?= $v ?></a>
  <?php endforeach; ?>
  <span style="margin-left:auto;font-size:.78rem;color:var(--text3);align-self:center"><?= $total_row ?> résultat<?= $total_row!=1?'s':'' ?></span>
</div>

<!-- Table -->
<?php if (empty($rows)): ?>
<div style="text-align:center;padding:3rem;background:var(--bg2);border:1px solid var(--border);border-radius:12px;color:var(--text3)">
  <div style="font-size:2.5rem;margin-bottom:12px">🔍</div>
  <div style="font-weight:700;margin-bottom:6px">Aucun résultat</div>
  <div style="font-size:.85rem">Essayez un autre terme de recherche.</div>
</div>
<?php else: ?>
<table class="tr-table">
  <thead>
    <tr>
      <th>Lot / Série</th>
      <th>Désignation</th>
      <th>Type</th>
      <th>Référence</th>
      <th>Qté</th>
      <th>Date fin</th>
      <th>Statut</th>
      <th>Emplacement</th>
      <th style="width:60px"></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($rows as $r):
    $is_rappel = in_array($r['lot'], $lots_rappeles);
    $expire = $r['date_fin'] && strtotime($r['date_fin']) < time();
    $bientot = $r['date_fin'] && !$expire && strtotime($r['date_fin']) < strtotime('+30 days');
    $type_colors = ['Médicament'=>'#ef4444','SSE/NRBC'=>'#8b5cf6','Matériel'=>'#3b82f6'];
    $tc = $type_colors[$r['type_label']] ?? '#64748b';
  ?>
  <tr class="<?= $is_rappel?'rappel-row':'' ?>">
    <td>
      <span class="lot-badge <?= $is_rappel?'danger':'' ?>"><?= h($r['lot']) ?></span>
      <?php if ($is_rappel): ?><span style="font-size:.7rem;color:#f87171;display:block;margin-top:2px">🚨 Rappel actif</span><?php endif; ?>
    </td>
    <td>
      <div style="font-weight:600"><?= h($r['nom']) ?></div>
    </td>
    <td>
      <span class="type-pill" style="background:<?= $tc ?>18;color:<?= $tc ?>;border:1px solid <?= $tc ?>33">
        <?= $r['icone'] ?> <?= $r['type_label'] ?>
      </span>
    </td>
    <td style="font-family:monospace;font-size:.78rem;color:var(--text3)"><?= h($r['reference'] ?: '—') ?></td>
    <td style="font-weight:600"><?= $r['quantite'] ?> <span style="font-size:.72rem;color:var(--text3)"><?= h($r['unite']) ?></span></td>
    <td>
      <?php if ($r['date_fin']): ?>
      <span style="font-size:.82rem;font-weight:600;color:<?= $expire?'#f87171':($bientot?'#fbbf24':'var(--text2)') ?>">
        <?= $expire?'⏰ ':($bientot?'⚠️ ':'')?><?= date('d/m/Y', strtotime($r['date_fin'])) ?>
      </span>
      <?php else: ?>
      <span style="color:var(--text3);font-size:.78rem">—</span>
      <?php endif; ?>
    </td>
    <td>
      <?php
      $statut_colors = ['actif'=>['#34d399','rgba(16,185,129,.12)'],'archive'=>['#94a3b8','var(--glass)'],'retire'=>['#f87171','rgba(239,68,68,.12)'],'en_service'=>['#34d399','rgba(16,185,129,.12)'],'maintenance'=>['#fbbf24','rgba(245,158,11,.12)'],'hors_service'=>['#f87171','rgba(239,68,68,.12)'],'perime'=>['#f87171','rgba(239,68,68,.12)'],'utilise'=>['#94a3b8','var(--glass)']];
      $sc = $statut_colors[$r['statut']] ?? ['#94a3b8','var(--glass)'];
      ?>
      <span style="padding:2px 9px;border-radius:20px;font-size:.72rem;font-weight:600;background:<?= $sc[1] ?>;color:<?= $sc[0] ?>"><?= h($r['statut']) ?></span>
    </td>
    <td style="font-size:.78rem;color:var(--text3);max-width:150px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= h($r['emplacement'] ?: '—') ?></td>
    <td style="white-space:nowrap">
      <a href="?vue=activite&q=<?= urlencode($r['nom']) ?>" class="btn btn-ghost btn-xs" title="Voir l'historique de ce produit">🕓</a>
      <button onclick="openRappel('<?= h($r['lot']) ?>','<?= $r['source'] ?>')" class="btn btn-ghost btn-xs" title="Créer alerte rappel" style="<?= $is_rappel?'color:#f87171':'' ?>">⚠️</button>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<!-- Pagination -->
<?php if ($total_pages > 1): ?>
<div class="tr-pagination">
  <?php if ($page > 1): ?><a href="?p=<?= $page-1 ?><?= $search?"&q=".urlencode($search):'' ?>&type=<?= $filtre ?>" class="tr-page">←</a><?php endif; ?>
  <?php for ($i = max(1,$page-2); $i <= min($total_pages,$page+2); $i++): ?>
  <a href="?p=<?= $i ?><?= $search?"&q=".urlencode($search):'' ?>&type=<?= $filtre ?>" class="tr-page <?= $i===$page?'on':'' ?>"><?= $i ?></a>
  <?php endfor; ?>
  <?php if ($page < $total_pages): ?><a href="?p=<?= $page+1 ?><?= $search?"&q=".urlencode($search):'' ?>&type=<?= $filtre ?>" class="tr-page">→</a><?php endif; ?>
  <span style="font-size:.75rem;color:var(--text3);align-self:center">Page <?= $page ?>/<?= $total_pages ?></span>
</div>
<?php endif; ?>
<?php endif; ?>
<?php endif; /* fin vue 'lots' */ ?>

<?php if ($vue === 'activite'): ?>
<!-- Filtres catégorie + recherche -->
<form method="get" class="tr-toolbar">
  <input type="hidden" name="vue" value="activite">
  <div class="tr-search">
    <span style="color:var(--text3)">🔍</span>
    <input type="text" name="q" value="<?= h($search) ?>" placeholder="Rechercher (médicament, action…)" oninput="if(this.value.length>2||this.value.length===0)this.form.submit()">
    <?php if ($search): ?><a href="?vue=activite&type=<?= h($filtre) ?>&cat=<?= h($cat) ?>" style="color:var(--text3);text-decoration:none;font-size:.85rem">✕</a><?php endif; ?>
  </div>
  <input type="hidden" name="cat" value="<?= h($cat) ?>">
</form>
<div style="display:flex;gap:6px;margin-bottom:16px;flex-wrap:wrap">
  <?php foreach (['produits'=>'💊 Médicaments','emplacements'=>'📍 Emplacements','materiels'=>'🩺 Matériel','admin'=>'🔐 Accès / Réglages','tous'=>'🔎 Tout'] as $k=>$v): ?>
  <a href="?vue=activite&cat=<?= $k ?><?= $search?'&q='.urlencode($search):'' ?>" class="tr-filter <?= $cat===$k?'on':'' ?>"><?= $v ?></a>
  <?php endforeach; ?>
  <span style="margin-left:auto;font-size:.78rem;color:var(--text3);align-self:center"><?= (int)$act_total ?> événement<?= $act_total!=1?'s':'' ?></span>
</div>

<!-- Actions IA + export -->
<div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">
  <button type="button" id="tr-ia-btn" onclick="trIA()" class="btn" style="background:linear-gradient(135deg,#3b82f6,#8b5cf6);color:#fff;border:none">🤖 Analyser l'activité (IA)</button>
  <a href="?export=csv&cat=<?= h($cat) ?><?= $search?'&q='.urlencode($search):'' ?>" class="btn btn-ghost">⬇️ Exporter CSV</a>
</div>
<div id="tr-ia-panel" style="display:none"></div>

<?php if (empty($acts)): ?>
<div style="text-align:center;padding:3rem;background:var(--bg2);border:1px solid var(--border);border-radius:12px;color:var(--text3)">
  <div style="font-size:2.5rem;margin-bottom:12px">📜</div>
  <div style="font-weight:700;margin-bottom:6px">Aucune activité enregistrée</div>
  <div style="font-size:.85rem">Les créations, modifications et retraits apparaîtront ici.</div>
</div>
<?php else: ?>
<div class="tr-timeline">
  <?php foreach ($acts as $a):
    $meta   = $TR_ACT[$a['action']] ?? ['•', ucfirst(strtolower(str_replace('_',' ',$a['action']))), '#64748b'];
    $who    = trim(($a['prenom'] ?? '').' '.($a['unom'] ?? '')) ?: 'Système';
    $cible  = $a['prod_nom'] ?: (tr_json_nom($a['valeur_apres']) ?: tr_json_nom($a['valeur_avant']));
    if (!$cible && $a['enregistrement_id']) $cible = '#'.$a['enregistrement_id'];
    $isCreate = str_starts_with($a['action'], 'CREATION');
    $isDelete = str_starts_with($a['action'], 'SUPPRESSION');
    $diffs = (!$isCreate && !$isDelete) ? tr_diff($a['valeur_avant'], $a['valeur_apres'], $TR_FIELD_LBL) : [];
  ?>
  <div class="tr-ev">
    <span class="tr-ev-ic" style="background:<?= $meta[2] ?>1f;color:<?= $meta[2] ?>"><?= $meta[0] ?></span>
    <div class="tr-ev-body">
      <div class="tr-ev-head">
        <span class="tr-ev-act" style="color:<?= $meta[2] ?>"><?= h($meta[1]) ?></span>
        <?php if ($cible): ?><span class="tr-ev-cible"><?= h($cible) ?></span><?php endif; ?>
        <span class="tr-ev-when"><?= date('d/m/Y à H:i', strtotime($a['cree_le'])) ?></span>
      </div>
      <div class="tr-ev-who">par <b><?= h($who) ?></b><?= $a['empl_nom'] ? ' · '.h($a['empl_nom']) : '' ?></div>
      <?php if ($diffs): ?>
      <div class="tr-diffs">
        <?php foreach ($diffs as $d): ?>
        <div class="tr-diff"><span class="k"><?= h($d[0]) ?></span><span class="o"><?= h(($d[1]!==null && $d[1]!=='') ? $d[1] : '—') ?></span><span class="arr">→</span><span class="n"><?= h(($d[2]!==null && $d[2]!=='') ? $d[2] : '—') ?></span></div>
        <?php endforeach; ?>
      </div>
      <?php elseif ($isCreate): ?>
      <div class="tr-diffs"><div class="tr-diff"><span class="n">✔️ Création enregistrée</span></div></div>
      <?php elseif ($isDelete): ?>
      <div class="tr-diffs"><div class="tr-diff"><span class="o">Supprimé définitivement</span></div></div>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php if ($act_pages > 1): ?>
<div class="tr-pagination">
  <?php $qp = ($search?'&q='.urlencode($search):'').'&cat='.$cat; ?>
  <?php if ($page > 1): ?><a href="?vue=activite&p=<?= $page-1 ?><?= $qp ?>" class="tr-page">←</a><?php endif; ?>
  <?php for ($i = max(1,$page-2); $i <= min($act_pages,$page+2); $i++): ?>
  <a href="?vue=activite&p=<?= $i ?><?= $qp ?>" class="tr-page <?= $i===$page?'on':'' ?>"><?= $i ?></a>
  <?php endfor; ?>
  <?php if ($page < $act_pages): ?><a href="?vue=activite&p=<?= $page+1 ?><?= $qp ?>" class="tr-page">→</a><?php endif; ?>
  <span style="font-size:.75rem;color:var(--text3);align-self:center">Page <?= $page ?>/<?= $act_pages ?></span>
</div>
<?php endif; ?>
<?php endif; ?>
<?php endif; /* fin vue 'activite' */ ?>

<!-- Modal rappel -->
<div class="tr-modal" id="modal-rappel" onclick="if(event.target===this)this.classList.remove('on')">
  <div class="tr-modal-box">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px">
      <div>
        <div style="font-size:1.05rem;font-weight:700">🚨 Créer une alerte rappel</div>
        <div style="font-size:.78rem;color:var(--text3)">Rappel ANSM ou décision interne</div>
      </div>
      <button onclick="document.getElementById('modal-rappel').classList.remove('on')" style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--text2)">✕</button>
    </div>
    <form method="post" style="display:flex;flex-direction:column;gap:14px">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create_rappel">
      <div>
        <label class="tr-label">Numéro de lot *</label>
        <input type="text" name="numero_lot" id="rappel-lot" class="tr-input" required placeholder="Ex: LOT-2024-001">
      </div>
      <div>
        <label class="tr-label">Type de source *</label>
        <select name="source_table" id="rappel-source" class="tr-input">
          <option value="produits">💊 Médicament</option>
          <option value="sse_consommables">🧪 SSE / NRBC</option>
          <option value="materiels">🩺 Matériel</option>
        </select>
      </div>
      <div>
        <label class="tr-label">Date du rappel *</label>
        <input type="date" name="date_rappel" class="tr-input" value="<?= date('Y-m-d') ?>" required>
      </div>
      <div>
        <label class="tr-label">Motif / Description</label>
        <textarea name="motif" class="tr-input" rows="3" placeholder="Ex: Rappel ANSM n°2024-XXX — risque de contamination…"></textarea>
      </div>
      <div style="display:flex;gap:10px;padding-top:8px;border-top:1px solid var(--border)">
        <button type="submit" class="btn btn-danger" style="background:rgba(239,68,68,.15);color:#f87171;border:1px solid rgba(239,68,68,.3)">🚨 Créer l'alerte</button>
        <button type="button" onclick="document.getElementById('modal-rappel').classList.remove('on')" class="btn btn-ghost">Annuler</button>
      </div>
    </form>
  </div>
</div>

<script>
function openRappel(lot, source) {
  document.getElementById('rappel-lot').value    = lot;
  document.getElementById('rappel-source').value = source;
  document.getElementById('modal-rappel').classList.add('on');
}
var TR_CAT  = <?= json_encode($cat) ?>;
var TR_Q    = <?= json_encode($search) ?>;
var TR_CSRF = <?= json_encode(csrf_token()) ?>;
function trIA() {
  var btn = document.getElementById('tr-ia-btn'), panel = document.getElementById('tr-ia-panel');
  if (!panel) return;
  panel.style.display = 'block';
  panel.innerHTML = '<div class="tr-ia"><div class="tr-ia-h"><span class="tr-ia-orb">🤖</span><b>Analyse de l\'activité</b></div><div class="tr-ia-b">⏳ Analyse en cours…</div></div>';
  if (btn) btn.disabled = true;
  var body = 'cat=' + encodeURIComponent(TR_CAT) + '&q=' + encodeURIComponent(TR_Q) + '&csrf_token=' + encodeURIComponent(TR_CSRF);
  fetch('tracabilite.php?ajax=ia_activite', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':TR_CSRF}, body:body })
    .then(function(r){ return r.json(); })
    .then(function(d){ if (btn) btn.disabled = false; var b = panel.querySelector('.tr-ia-b'); if (d.ok) { b.textContent = d.texte; } else { b.innerHTML = '<span style="color:#ef4444">⚠️ ' + (d.error || 'Erreur') + '</span>'; } })
    .catch(function(){ if (btn) btn.disabled = false; var b = panel.querySelector('.tr-ia-b'); if (b) b.innerHTML = '<span style="color:#ef4444">⚠️ Erreur réseau.</span>'; });
}
</script>

<?php require_once 'includes/footer.php'; ?>

<?php
/**
 * Cadurix v5.2 — Historique des rempotages
 */
require_once 'includes/auth.php';
$pageTitle = 'Rempotages';
$site_id   = site_actif();
if (!$site_id) { header('Location: dashboard.php'); exit; }

// ── Suppression d'un rempotage (admin) : ANNULE l'effet sur le stock ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'supprimer' && isAdmin()) {
    csrf_check();
    $rid = (int)($_POST['rempotage_id'] ?? 0);
    $chk = $pdo->prepare("SELECT id FROM rempotages WHERE id=? AND site_id=?");
    $chk->execute([$rid, $site_id]);
    if ($chk->fetch()) {
        try {
            $pdo->beginTransaction();
            $arts = $pdo->prepare("SELECT produit_id, action, quantite FROM rempotage_articles WHERE rempotage_id=?");
            $arts->execute([$rid]);
            foreach ($arts->fetchAll(PDO::FETCH_ASSOC) as $a) {
                $pid = (int)$a['produit_id']; if (!$pid) continue;
                $q = (float)$a['quantite'];
                // On inverse : ce qui était 'retire' revient (+), ce qui était 'cree' repart (-)
                $delta = $a['action'] === 'retire' ? $q : ($a['action'] === 'cree' ? -$q : 0);
                if ($delta == 0) continue;
                $so = $pdo->prepare("SELECT quantite FROM produits WHERE id=?"); $so->execute([$pid]);
                $oq = (float)$so->fetchColumn(); $nq = max(0, $oq + $delta);
                $pdo->prepare("UPDATE produits SET quantite=? WHERE id=?")->execute([$nq, $pid]);
                $pdo->prepare("INSERT INTO mouvements_stock (produit_id,user_id,type,quantite_avant,quantite_delta,quantite_apres,motif)
                               VALUES (?,?,'ajustement',?,?,?,?)")
                    ->execute([$pid, currentUserId(), $oq, $delta, $nq, "Annulation rempotage #$rid"]);
            }
            $pdo->prepare("DELETE FROM rempotage_articles WHERE rempotage_id=?")->execute([$rid]);
            $pdo->prepare("DELETE FROM rempotages WHERE id=? AND site_id=?")->execute([$rid, $site_id]);
            $pdo->commit();
            if (function_exists('audit_log')) audit_log($pdo, 'REMPOTAGE_SUPPR', 'rempotages', $rid, null, null);
            flashSuccess("Rempotage supprimé — stock rétabli.");
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flashError("Erreur : " . $e->getMessage());
        }
    } else {
        flashError("Rempotage introuvable.");
    }
    header('Location: /rempotages.php'); exit;
}

// ── IA active ? (pour le bouton d'analyse) ──
$ia_on = (!function_exists('module_actif') || module_actif('assistant_ia'))
       && ($pdo->query("SELECT valeur FROM ai_settings WHERE cle='actif'")->fetchColumn() === '1');

// ── AJAX : synthèse IA de la période (rempotages + conso) ──
if (($_GET['ajax'] ?? '') === 'ia_periode' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    if (function_exists('module_actif') && !module_actif('assistant_ia')) { echo json_encode(['error'=>"Assistant IA désactivé."]); exit; }
    $ai_actif = $pdo->query("SELECT valeur FROM ai_settings WHERE cle='actif'")->fetchColumn();
    $api_key  = $pdo->query("SELECT valeur FROM ai_settings WHERE cle='api_key'")->fetchColumn();
    $model    = $pdo->query("SELECT valeur FROM ai_settings WHERE cle='model'")->fetchColumn() ?: 'claude-haiku-4-5-20251001';
    if (!$ai_actif || $ai_actif !== '1' || !$api_key) { echo json_encode(['error'=>"IA non configurée."]); exit; }
    $in   = json_decode(file_get_contents('php://input'), true) ?: [];
    $mois = preg_match('/^\d{4}-\d{2}$/', $in['mois'] ?? '') ? $in['mois'] : date('Y-m');
    $d = $mois.'-01'; $f = date('Y-m-t', strtotime($d)).' 23:59:59';
    $w = "r.site_id=".(int)$site_id." AND r.debut_le>='$d' AND r.debut_le<='$f'" . (!isAdmin() ? " AND r.user_id=".(int)currentUserId() : '');
    $nb = (int)$pdo->query("SELECT COUNT(*) FROM rempotages r WHERE $w")->fetchColumn();
    if (!$nb) { echo json_encode(['ok'=>true, 'texte'=>"Aucun rempotage sur $mois — rien à analyser."]); exit; }
    $conso = $pdo->query("SELECT ra.nom, SUM(ra.quantite) q FROM rempotage_articles ra JOIN rempotages r ON r.id=ra.rempotage_id
                          WHERE $w AND ra.action<>'retire' GROUP BY ra.nom ORDER BY q DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);
    $pd = date('Y-m-01', strtotime($d.' -1 month')); $pf = date('Y-m-t', strtotime($pd)).' 23:59:59';
    $nb_prev = (int)$pdo->query("SELECT COUNT(*) FROM rempotages r WHERE r.site_id=".(int)$site_id." AND r.debut_le>='$pd' AND r.debut_le<='$pf'".(!isAdmin()?" AND r.user_id=".(int)currentUserId():''))->fetchColumn();
    $consoTxt = implode(', ', array_map(fn($c)=>$c['nom'].' ('.rtrim(rtrim(number_format($c['q'],1,'.',''),'0'),'.').')', $conso)) ?: 'aucun';
    $prompt = "Tu es l'assistant logistique d'un centre de secours (SDIS/SSSM). Analyse l'activité de rempotage du mois $mois.\n"
            . "- Rempotages ce mois : $nb (mois précédent : $nb_prev).\n"
            . "- Produits les plus remis (= consommés en intervention, du plus au moins) : $consoTxt.\n\n"
            . "Rédige une SYNTHÈSE courte et concrète en français (4 à 6 phrases). Mets en **gras** les points clés. "
            . "Couvre : la tendance d'activité (hausse/baisse vs mois précédent), les produits qui partent le plus, "
            . "et termine par 2-3 RECOMMANDATIONS de réapprovisionnement prioritaire. Ton professionnel et direct. "
            . "N'utilise PAS de titre ni d'en-tête (pas de #) : commence directement par l'analyse.";
    $payload = ['model'=>$model, 'max_tokens'=>600, 'messages'=>[['role'=>'user','content'=>$prompt]]];
    $is_prod = str_contains($_SERVER['HTTP_HOST'] ?? '', 'cadurix.fr');
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>json_encode($payload),
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','x-api-key: '.$api_key,'anthropic-version: 2023-06-01'],
        CURLOPT_TIMEOUT=>40, CURLOPT_SSL_VERIFYPEER=>$is_prod, CURLOPT_SSL_VERIFYHOST=>$is_prod?2:0]);
    $resp = curl_exec($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); $cerr = curl_error($ch); curl_close($ch);
    if ($resp === false || $cerr) { echo json_encode(['error'=>"Réseau IA : $cerr"]); exit; }
    $j = json_decode($resp, true);
    if ($http !== 200 || empty($j['content'][0]['text'])) { echo json_encode(['error'=>($j['error']['message'] ?? "Erreur IA HTTP $http")]); exit; }
    echo json_encode(['ok'=>true, 'texte'=>$j['content'][0]['text']], JSON_UNESCAPED_UNICODE);
    exit;
}

// Filtres
$filtre_empl  = (int)($_GET['emplacement'] ?? 0);
$filtre_user  = (int)($_GET['user'] ?? 0);
$filtre_mois  = preg_match('/^\d{4}-\d{2}$/', $_GET['mois'] ?? '') ? $_GET['mois'] : date('Y-m');

// Détail rempotage
$detail_id = (int)($_GET['id'] ?? 0);
$detail    = null;
$articles  = [];
if ($detail_id) {
    $s = $pdo->prepare("
        SELECT r.*, e.nom AS empl_nom, ep.nom AS empl_parent,
               u.prenom, u.nom AS user_nom
        FROM rempotages r
        LEFT JOIN emplacements e  ON e.id = r.emplacement_id
        LEFT JOIN emplacements ep ON ep.id = e.parent_id
        LEFT JOIN utilisateurs u  ON u.id = r.user_id
        WHERE r.id = ? AND r.site_id = ?
    ");
    $s->execute([$detail_id, $site_id]);
    $detail = $s->fetch(PDO::FETCH_ASSOC);

    if ($detail) {
        // Sécurité : admin ou auteur uniquement
        if (!isAdmin() && $detail['user_id'] !== currentUserId()) {
            $detail = null;
        } else {
            $a = $pdo->prepare("
                SELECT ra.*
                FROM rempotage_articles ra
                WHERE ra.rempotage_id = ?
            ");
            $a->execute([$detail_id]);
            $articles = $a->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}

// KPIs mois en cours
$debut_mois = date('Y-m-01');
$fin_mois   = date('Y-m-t');
$nb_mois    = (int)$pdo->query("SELECT COUNT(*) FROM rempotages WHERE site_id=$site_id AND debut_le >= '$debut_mois' AND debut_le <= '$fin_mois 23:59:59'")->fetchColumn();
$nb_produits_mois = (int)$pdo->query("SELECT COALESCE(SUM(ra.quantite),0) FROM rempotage_articles ra JOIN rempotages r ON r.id=ra.rempotage_id WHERE r.site_id=$site_id AND r.debut_le >= '$debut_mois'")->fetchColumn();
$_hist_where = "site_id=$site_id" . (!isAdmin() ? " AND user_id=".(int)currentUserId() : "");
$nb_total_hist = (int)$pdo->query("SELECT COUNT(*) FROM rempotages WHERE $_hist_where")->fetchColumn();

// Dernier rempotage
$dernier = $pdo->query("
    SELECT r.debut_le, e.nom AS empl_nom, u.prenom, u.nom AS user_nom
    FROM rempotages r
    LEFT JOIN emplacements e ON e.id=r.emplacement_id
    LEFT JOIN utilisateurs u ON u.id=r.user_id
    WHERE r.site_id=$site_id
    ORDER BY r.debut_le DESC LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

// Requête liste
$where  = "WHERE r.site_id = $site_id";
$params = [];
// Non-admin : voir seulement ses propres rempotages
if (!isAdmin()) {
    $where .= " AND r.user_id = " . (int)currentUserId();
}
if ($filtre_empl) { $where .= " AND r.emplacement_id = ?"; $params[] = $filtre_empl; }
if ($filtre_user && isAdmin()) { $where .= " AND r.user_id = ?"; $params[] = $filtre_user; }
if ($filtre_mois) {
    $debut = $filtre_mois . '-01';
    $fin   = date('Y-m-t', strtotime($debut));
    $where .= " AND r.debut_le >= ? AND r.debut_le <= ?";
    $params[] = $debut; $params[] = $fin . ' 23:59:59';
}

$stmt = $pdo->prepare("
    SELECT r.*,
           e.nom AS empl_nom, ep.nom AS empl_parent,
           u.prenom, u.nom AS user_nom,
           COUNT(ra.id) AS nb_articles,
           COALESCE(SUM(ra.quantite),0) AS total_produits
    FROM rempotages r
    LEFT JOIN emplacements e      ON e.id  = r.emplacement_id
    LEFT JOIN emplacements ep     ON ep.id = e.parent_id
    LEFT JOIN utilisateurs u      ON u.id  = r.user_id
    LEFT JOIN rempotage_articles ra ON ra.rempotage_id = r.id
    $where
    GROUP BY r.id
    ORDER BY r.debut_le DESC
    LIMIT 100
");
$stmt->execute($params);
$rempotages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Données filtres
$empls = $pdo->query("SELECT id, nom FROM emplacements WHERE site_id=$site_id AND actif=1 ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
$users = $pdo->query("SELECT DISTINCT u.id, u.prenom, u.nom FROM utilisateurs u JOIN rempotages r ON r.user_id=u.id WHERE r.site_id=$site_id ORDER BY u.nom")->fetchAll(PDO::FETCH_ASSOC);

$statuts = [
    'en_cours' => ['label'=>'En cours',  'color'=>'#f59e0b'],
    'valide'   => ['label'=>'Validé',    'color'=>'#10b981'],
    'annule'   => ['label'=>'Annulé',    'color'=>'#ef4444'],
];

// ── Cockpit conso : agrégats de la période (même filtres que la liste) ──
$c_debut = $filtre_mois . '-01';
$c_fin   = date('Y-m-t', strtotime($c_debut)) . ' 23:59:59';
$c_where = "r.site_id = " . (int)$site_id . " AND r.debut_le >= '$c_debut' AND r.debut_le <= '$c_fin'";
if (!isAdmin())      $c_where .= " AND r.user_id = " . (int)currentUserId();
if ($filtre_empl)    $c_where .= " AND r.emplacement_id = " . (int)$filtre_empl;
if ($filtre_user && isAdmin()) $c_where .= " AND r.user_id = " . (int)$filtre_user;

// Produits les plus REMIS (= consommés en intervention) sur la période
$top_conso = $pdo->query("SELECT ra.nom, SUM(ra.quantite) q
                          FROM rempotage_articles ra JOIN rempotages r ON r.id = ra.rempotage_id
                          WHERE $c_where AND ra.action <> 'retire'
                          GROUP BY ra.nom ORDER BY q DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
// Activité (rempotages par jour)
$trend = $pdo->query("SELECT DATE(r.debut_le) j, COUNT(*) n
                      FROM rempotages r WHERE $c_where GROUP BY DATE(r.debut_le) ORDER BY j")->fetchAll(PDO::FETCH_ASSOC);
$conso_total = 0; foreach ($top_conso as $t) $conso_total += (float)$t['q'];

require_once 'includes/header.php';
?>
<style>
.remp-row{display:flex;align-items:center;gap:.875rem;padding:.75rem 1rem;border-radius:10px;background:var(--glass);border:1px solid var(--border);margin-bottom:.5rem;transition:all .15s;text-decoration:none;color:var(--text)}
.remp-row:hover{border-color:var(--border2);transform:translateX(2px)}
.remp-row.active{border-color:var(--primary);background:rgba(59,130,246,.06)}
.remp-icon{width:42px;height:42px;border-radius:10px;background:rgba(59,130,246,.1);border:1px solid rgba(59,130,246,.2);display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0}
.detail-card{background:var(--glass);border:1px solid var(--border);border-radius:12px;padding:1.25rem;margin-bottom:.75rem}
.art-row{display:flex;align-items:center;gap:.75rem;padding:.6rem .875rem;border-radius:8px;background:rgba(255,255,255,.02);border:1px solid var(--border);margin-bottom:.35rem;font-size:.82rem}
</style>

<div class="page-header">
  <div class="page-title-group">
    <div class="page-title">🔄 Rempotages</div>
    <div class="page-sub">Historique et traçabilité</div>
  </div>
  <a href="/rempotage.php" class="btn btn-primary">➕ Nouveau rempotage</a>
</div>

<!-- KPIs -->
<div class="grid-4" style="margin-bottom:1.25rem">
  <div class="kpi-card" style="border-top:3px solid #3b82f6">
    <div class="kpi-label">Ce mois</div>
    <div class="kpi-value"><?= $nb_mois ?></div>
    <div class="kpi-foot">Rempotages</div>
  </div>
  <div class="kpi-card" style="border-top:3px solid #10b981">
    <div class="kpi-label">Produits rempotés</div>
    <div class="kpi-value"><?= $nb_produits_mois ?></div>
    <div class="kpi-foot">Ce mois</div>
  </div>
  <div class="kpi-card" style="border-top:3px solid #8b5cf6">
    <div class="kpi-label">Dernier rempotage</div>
    <div class="kpi-value" style="font-size:1.1rem"><?= $dernier ? date('d/m/Y', strtotime($dernier['debut_le'])) : '—' ?></div>
    <div class="kpi-foot"><?= $dernier ? h(($dernier['prenom']??'').' '.($dernier['user_nom']??'')) : '—' ?></div>
  </div>
  <div class="kpi-card" style="border-top:3px solid #f59e0b">
    <div class="kpi-label">Total historique</div>
    <div class="kpi-value"><?= $nb_total_hist ?></div>
    <div class="kpi-foot"><?= isAdmin() ? 'Tous les rempotages' : 'Mes rempotages' ?></div>
  </div>
</div>

<!-- Filtres -->
<div class="card" style="margin-bottom:1rem;padding:.875rem 1rem">
  <form method="GET" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end">
    <div class="form-group" style="margin:0;min-width:140px">
      <label class="form-label">Mois</label>
      <input type="month" name="mois" class="form-control" value="<?= h($filtre_mois) ?>">
    </div>
    <div class="form-group" style="margin:0;min-width:160px">
      <label class="form-label">Emplacement</label>
      <select name="emplacement" class="form-control">
        <option value="">Tous</option>
        <?php foreach ($empls as $e): ?>
        <option value="<?= $e['id'] ?>" <?= $filtre_empl===$e['id']?'selected':'' ?>><?= h($e['nom']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if (isAdmin()): ?>
    <div class="form-group" style="margin:0;min-width:160px">
      <label class="form-label">Agent</label>
      <select name="user" class="form-control">
        <option value="">Tous</option>
        <?php foreach ($users as $u): ?>
        <option value="<?= $u['id'] ?>" <?= $filtre_user===$u['id']?'selected':'' ?>><?= h($u['prenom'].' '.$u['nom']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <button type="submit" class="btn btn-primary btn-sm">🔍 Filtrer</button>
    <a href="/rempotages.php" class="btn btn-ghost btn-sm">✕ Réinitialiser</a>
  </form>
</div>

<!-- ── Cockpit conso ── -->
<div class="card" style="margin-bottom:1rem;padding:1rem 1.25rem">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:.85rem">
    <div style="font-weight:800;font-size:.95rem">📊 Consommation de la période <span style="font-weight:400;color:var(--text3);font-size:.82em">(ce que tu as remis = utilisé)</span></div>
    <?php if ($ia_on): ?><button type="button" class="btn btn-primary btn-sm" id="rmpia-btn" onclick="rmpAnalyse()">🤖 Analyser la période</button><?php endif; ?>
  </div>
  <div id="rmpia-out" style="display:none;background:rgba(59,130,246,.06);border:1px solid rgba(59,130,246,.2);border-radius:10px;padding:12px 14px;margin-bottom:14px;font-size:.88rem;line-height:1.55"></div>
  <div class="conso-grid" style="display:grid;grid-template-columns:1fr;gap:18px">
    <div>
      <div style="font-size:.7rem;text-transform:uppercase;letter-spacing:1px;color:var(--text3);font-weight:700;margin-bottom:.6rem">Top produits remis</div>
      <?php if (empty($top_conso)): ?>
      <div style="color:var(--text3);font-size:.85rem;padding:10px 0">Aucune consommation sur cette période.</div>
      <?php else: $maxc = max(array_map(fn($t)=>(float)$t['q'],$top_conso)); foreach ($top_conso as $t): $pct=$maxc>0?round($t['q']/$maxc*100):0; ?>
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:7px">
        <div style="width:180px;font-size:.82rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="<?= h($t['nom']) ?>"><?= h($t['nom']) ?></div>
        <div style="flex:1;height:16px;background:var(--bg3);border-radius:5px;overflow:hidden"><div style="width:<?= max($pct,4) ?>%;height:100%;background:linear-gradient(90deg,var(--primary),#10b981);border-radius:5px"></div></div>
        <div style="width:42px;text-align:right;font-weight:700;font-size:.82rem;font-variant-numeric:tabular-nums"><?= rtrim(rtrim(number_format($t['q'],1,'.',''),'0'),'.') ?></div>
      </div>
      <?php endforeach; endif; ?>
    </div>
    <div>
      <div style="font-size:.7rem;text-transform:uppercase;letter-spacing:1px;color:var(--text3);font-weight:700;margin-bottom:.6rem">Activité (rempotages / jour)</div>
      <?php if (empty($trend)): ?>
      <div style="color:var(--text3);font-size:.85rem;padding:10px 0">Aucune activité.</div>
      <?php else: $maxt=max(array_map(fn($x)=>(int)$x['n'],$trend)); ?>
      <div style="display:flex;align-items:flex-end;gap:3px;height:96px">
        <?php foreach ($trend as $x): $hh=$maxt>0?round($x['n']/$maxt*100):0; ?>
        <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:3px;height:100%" title="<?= date('d/m',strtotime($x['j'])) ?> : <?= (int)$x['n'] ?> rempotage(s)">
          <div style="flex:1;width:100%;display:flex;align-items:flex-end"><div style="width:100%;background:rgba(59,130,246,.65);border-radius:3px 3px 0 0;height:<?= max($hh,5) ?>%"></div></div>
          <div style="font-size:.55rem;color:var(--text3)"><?= date('j',strtotime($x['j'])) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<style>@media(min-width:900px){ .conso-grid{grid-template-columns:1.35fr 1fr !important} }</style>

<div class="grid-2" style="align-items:start">

  <!-- Liste rempotages -->
  <div>
    <?php if (empty($rempotages)): ?>
    <div class="card" style="text-align:center;padding:3rem;color:var(--text3)">
      <div style="font-size:3rem;margin-bottom:1rem">🔄</div>
      <div style="font-weight:700;margin-bottom:.5rem">Aucun rempotage</div>
      <div style="font-size:.85rem">sur cette période</div>
      <a href="/rempotage.php" class="btn btn-primary btn-sm" style="margin-top:1rem">➕ Nouveau rempotage</a>
    </div>
    <?php else: ?>
    <?php foreach ($rempotages as $r):
      $st = $statuts[$r['statut']] ?? ['label'=>$r['statut'],'color'=>'#64748b'];
      $isActive = $detail_id === (int)$r['id'];
    ?>
    <a href="/rempotages.php?id=<?= $r['id'] ?><?= $filtre_mois?"&mois=$filtre_mois":'' ?><?= $filtre_empl?"&emplacement=$filtre_empl":'' ?>" class="remp-row <?= $isActive?'active':'' ?>">
      <div class="remp-icon">🔄</div>
      <div style="flex:1;min-width:0">
        <div style="font-weight:700;font-size:.9rem">
          <?= $r['empl_parent'] ? h($r['empl_parent']).' › ' : '' ?><?= h($r['empl_nom'] ?? 'Emplacement inconnu') ?>
        </div>
        <div style="font-size:.75rem;color:var(--text3);margin-top:.2rem">
          <?= date('d/m/Y à H:i', strtotime($r['debut_le'])) ?>
          · <?= h(($r['prenom']??'').' '.($r['user_nom']??'')) ?>
        </div>
      </div>
      <div style="text-align:right;flex-shrink:0">
        <div style="font-size:.76rem;font-weight:700">
          <?php if ((int)$r['nb_crees']>0): ?><span style="color:#10b981">+<?= (int)$r['nb_crees'] ?> remis</span><?php endif; ?>
          <?php if ((int)$r['nb_non_scannes']>0): ?><span style="color:#ef4444;margin-left:6px">−<?= (int)$r['nb_non_scannes'] ?> retirés</span><?php endif; ?>
          <?php if ((int)$r['nb_crees']<=0 && (int)$r['nb_non_scannes']<=0): ?><span style="color:var(--text3)"><?= (int)$r['nb_articles'] ?> produit(s)</span><?php endif; ?>
        </div>
        <span style="display:inline-block;margin-top:.25rem;font-size:.7rem;padding:.15rem .5rem;border-radius:99px;background:<?= $st['color'] ?>22;color:<?= $st['color'] ?>;border:1px solid <?= $st['color'] ?>44"><?= $st['label'] ?></span>
      </div>
    </a>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Détail -->
  <div>
    <?php if ($detail_id && !$detail): ?>
    <div class="card" style="text-align:center;padding:2rem;color:var(--text3)">
      <div style="font-size:2.5rem;margin-bottom:.75rem">🔒</div>
      <div style="font-weight:700;margin-bottom:.4rem">Accès non autorisé</div>
      <div style="font-size:.85rem">Vous ne pouvez consulter que vos propres rempotages.</div>
    </div>
    <?php elseif ($detail): ?>
    <div class="card" style="margin-bottom:1rem">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:1rem">
        <div>
          <div style="font-weight:800;font-size:1rem">
            🔄 <?= $detail['empl_parent'] ? h($detail['empl_parent']).' › ' : '' ?><?= h($detail['empl_nom'] ?? '—') ?>
          </div>
          <div style="font-size:.78rem;color:var(--text3);margin-top:.3rem">
            <?= date('d/m/Y à H:i', strtotime($detail['debut_le'])) ?>
            · <?= h(($detail['prenom']??'').' '.($detail['user_nom']??'')) ?>
          </div>
        </div>
        <?php $st = $statuts[$detail['statut']] ?? ['label'=>$detail['statut'],'color'=>'#64748b']; ?>
        <span style="padding:.3rem .75rem;border-radius:99px;font-size:.78rem;font-weight:700;background:<?= $st['color'] ?>22;color:<?= $st['color'] ?>;border:1px solid <?= $st['color'] ?>44">
          <?= $st['label'] ?>
        </span>
      </div>

      <!-- Articles -->
      <?php
      $_remis   = count(array_filter($articles, fn($a)=>($a['action']??'cree')!=='retire'));
      $_retires = count($articles) - $_remis;
      ?>
      <div style="font-size:.7rem;text-transform:uppercase;letter-spacing:1.5px;color:var(--text3);font-weight:700;margin-bottom:.5rem">
        <?= count($articles) ?> mouvement(s) ·
        <span style="color:#10b981"><?= $_remis ?> remis</span><?php if ($_retires): ?> · <span style="color:#ef4444"><?= $_retires ?> retirés</span><?php endif; ?>
      </div>

      <?php if (empty($articles)): ?>
      <p style="color:var(--text3);font-size:.85rem">Aucun article enregistré.</p>
      <?php else: ?>
      <?php foreach ($articles as $a): $isCree = ($a['action'] ?? 'cree') !== 'retire'; $col = $isCree ? '#10b981' : '#ef4444'; ?>
      <div class="art-row" style="border-left:3px solid <?= $col ?>">
        <div style="flex:1;min-width:0">
          <div style="font-weight:600"><?= h($a['nom'] ?? 'Produit #'.$a['produit_id']) ?></div>
          <div style="font-size:.72rem;color:var(--text3);margin-top:.15rem">
            <?= $a['numero_lot'] ? 'Lot '.h($a['numero_lot']).' · ' : '' ?>
            <?= $a['date_peremption'] ? 'Exp. '.date('m/Y', strtotime($a['date_peremption'])) : 'Pas de date' ?>
          </div>
        </div>
        <div style="font-weight:700;font-size:.9rem;flex-shrink:0;color:<?= $col ?>">
          <?= $isCree ? '+' : '−' ?><?= number_format($a['quantite'],0) ?> <?= h($a['unite']??'unité') ?>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>

      <?php if ($detail['notes']): ?>
      <div style="margin-top:.875rem;padding:.75rem;background:rgba(255,255,255,.03);border-radius:8px;font-size:.82rem;color:var(--text2)">
        📝 <?= h($detail['notes']) ?>
      </div>
      <?php endif; ?>

      <?php if (isAdmin()): ?>
      <form method="post" onsubmit="return confirm('Supprimer ce rempotage ? Le stock sera remis à l\'état précédent.');" style="margin-top:1rem;text-align:right">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="supprimer">
        <input type="hidden" name="rempotage_id" value="<?= (int)$detail['id'] ?>">
        <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--red);border-color:var(--red)">🗑️ Supprimer (annule le stock)</button>
      </form>
      <?php endif; ?>
    </div>

    <?php else: ?>
    <div class="card" style="text-align:center;padding:3rem;color:var(--text3)">
      <div style="font-size:3rem;margin-bottom:1rem">👈</div>
      <div style="font-size:.875rem">Sélectionnez un rempotage<br>pour voir le détail</div>
    </div>
    <?php endif; ?>
  </div>

</div>

<script>
function rmpAnalyse(){
  var btn=document.getElementById('rmpia-btn'), out=document.getElementById('rmpia-out');
  var mois='<?= h($filtre_mois) ?>', old=btn?btn.textContent:'';
  if(btn){ btn.disabled=true; btn.textContent='🤖 Analyse…'; }
  out.style.display='block'; out.innerHTML='<span style="color:var(--text3)">🤖 Analyse de la période en cours…</span>';
  fetch('/rempotages.php?ajax=ia_periode',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({mois:mois})})
   .then(r=>r.json()).then(function(d){
     if(btn){ btn.disabled=false; btn.textContent=old; }
     if(d.error){ out.innerHTML='<span style="color:#f87171">⚠️ '+d.error+'</span>'; return; }
     var t=(d.texte||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\*\*(.+?)\*\*/g,'<b>$1</b>').replace(/\n/g,'<br>');
     out.innerHTML='<div style="font-weight:800;margin-bottom:6px">🤖 Synthèse de la période</div>'+t;
   }).catch(function(){ if(btn){ btn.disabled=false; btn.textContent=old; } out.innerHTML='<span style="color:#f87171">Erreur réseau IA.</span>'; });
}
</script>
<?php require_once 'includes/footer.php'; ?>

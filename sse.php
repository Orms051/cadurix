<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.

/**
 * Cadurix — Dashboard SSE / NRBC
 */
ob_start();
require_once 'includes/auth.php';
$pageTitle = 'SSE / NRBC';
$site_id   = site_actif();
if (!$site_id) { header('Location: dashboard.php'); exit; }

// ── KPIs ─────────────────────────────────────────────────────
$kpi = [];

$s = function(string $sql, array $p) use ($pdo): int {
    $st = $pdo->prepare($sql); $st->execute($p); return (int)$st->fetchColumn();
};

// Statuts enum sse_equipements : operationnel / hs / revision / reforme
$kpi['eq_total']      = $s("SELECT COUNT(*) FROM sse_equipements WHERE site_id=? AND actif=1", [$site_id]);
$kpi['eq_hs']         = $s("SELECT COUNT(*) FROM sse_equipements WHERE site_id=? AND actif=1 AND statut IN ('hs','revision')", [$site_id]);
$kpi['eq_ctrl_due']   = $s("SELECT COUNT(*) FROM sse_equipements WHERE site_id=? AND actif=1 AND date_prochain_ctrl IS NOT NULL AND date_prochain_ctrl <= CURDATE()", [$site_id]);
$kpi['eq_perime']     = $s("SELECT COUNT(*) FROM sse_equipements WHERE site_id=? AND actif=1 AND date_peremption IS NOT NULL AND date_peremption < CURDATE()", [$site_id]);
// Statuts enum sse_consommables : actif / perime / utilise
$kpi['conso_total']   = $s("SELECT COUNT(*) FROM sse_consommables WHERE site_id=? AND statut='actif'", [$site_id]);
$kpi['conso_perime']  = $s("SELECT COUNT(*) FROM sse_consommables WHERE site_id=? AND statut='actif' AND date_peremption < CURDATE()", [$site_id]);
$kpi['conso_critique']= $s("SELECT COUNT(*) FROM sse_consommables WHERE site_id=? AND statut='actif' AND date_peremption BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)", [$site_id]);
$kpi['stock_bas']     = $s("SELECT COUNT(*) FROM sse_consommables WHERE site_id=? AND statut='actif' AND quantite_min>0 AND quantite<=quantite_min", [$site_id]);

// Équipements critiques
$stmt_crit = $pdo->prepare("
    SELECT e.*, c.nom AS categorie_nom, c.icone
    FROM sse_equipements e
    LEFT JOIN sse_categories c ON c.id = e.categorie_id
    WHERE e.site_id=? AND e.actif=1
      AND (e.statut IN ('hs','revision','reforme')
           OR (e.date_peremption IS NOT NULL AND e.date_peremption <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))
           OR (e.date_prochain_ctrl IS NOT NULL AND e.date_prochain_ctrl <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)))
    ORDER BY e.date_peremption ASC, e.date_prochain_ctrl ASC
    LIMIT 10
");
$stmt_crit->execute([$site_id]);
$equipements_alerte = $stmt_crit->fetchAll(PDO::FETCH_ASSOC);

// Consommables critiques
$stmt_cons = $pdo->prepare("
    SELECT s.*, c.nom AS categorie_nom, c.icone,
           DATEDIFF(s.date_peremption, CURDATE()) AS jours
    FROM sse_consommables s
    LEFT JOIN sse_categories c ON c.id = s.categorie_id
    WHERE s.site_id=? AND s.statut='actif'
      AND (s.date_peremption <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
           OR (s.quantite_min>0 AND s.quantite<=s.quantite_min))
    ORDER BY s.date_peremption ASC
    LIMIT 10
");
$stmt_cons->execute([$site_id]);
$consommables_alerte = $stmt_cons->fetchAll(PDO::FETCH_ASSOC);

// Derniers contrôles
$stmt_ctrl = $pdo->prepare("
    SELECT ct.*, e.nom AS equipement_nom, CONCAT(u.prenom,' ',u.nom) AS agent
    FROM sse_controles ct
    JOIN sse_equipements e ON e.id = ct.equipement_id
    JOIN utilisateurs u ON u.id = ct.operateur_id
    WHERE ct.site_id=?
    ORDER BY ct.date_controle DESC
    LIMIT 5
");
$stmt_ctrl->execute([$site_id]);
$derniers_controles = $stmt_ctrl->fetchAll(PDO::FETCH_ASSOC);

// ── Analyse IA de l'état SSE ──
if (($_GET['ajax'] ?? '') === 'ia') {
    if (function_exists('ob_get_level')) { while (ob_get_level()) ob_end_clean(); }
    header('Content-Type: application/json');
    if (!function_exists('csrf_verifier') || !csrf_verifier()) { echo json_encode(['error'=>'Session expirée, recharge la page.']); exit; }
    $ai_actif = $pdo->query("SELECT valeur FROM ai_settings WHERE cle='actif'")->fetchColumn();
    $api_key  = $pdo->query("SELECT valeur FROM ai_settings WHERE cle='api_key'")->fetchColumn();
    $model    = $pdo->query("SELECT valeur FROM ai_settings WHERE cle='model'")->fetchColumn() ?: 'claude-haiku-4-5-20251001';
    if ($ai_actif !== '1' || !$api_key) { echo json_encode(['error'=>'IA non configurée (Admin → IA).']); exit; }
    $ctx  = "Module SSE / NRBC.\n";
    $ctx .= "Equipements : {$kpi['eq_total']} (HS/revision : {$kpi['eq_hs']}, perimes : {$kpi['eq_perime']}, controles dus : {$kpi['eq_ctrl_due']}).\n";
    $ctx .= "Consommables : {$kpi['conso_total']} (perimes : {$kpi['conso_perime']}, sous 30j/stock bas : {$kpi['conso_critique']}, stock bas : {$kpi['stock_bas']}).\n";
    if ($equipements_alerte) { $ctx .= "Equipements en alerte :\n"; foreach ($equipements_alerte as $e){ $ctx .= "- ".$e['nom']." (statut ".$e['statut'].($e['date_prochain_ctrl']?", ctrl ".$e['date_prochain_ctrl']:'').($e['date_peremption']?", per ".$e['date_peremption']:'').").\n"; } }
    if ($consommables_alerte) { $ctx .= "Consommables en alerte :\n"; foreach ($consommables_alerte as $c){ $low=($c['quantite_min']>0 && $c['quantite']<=$c['quantite_min']); $ctx .= "- ".$c['nom']." (qte ".$c['quantite']."/".$c['quantite_min'].($low?" STOCK BAS":'').($c['date_peremption']?", per ".$c['date_peremption']:'').").\n"; } }
    $prompt = "Tu es le copilote de Cadurix (materiel medical SDIS/SSSM), module SSE/NRBC. A partir des donnees ci-dessous, redige en francais un point clair et priorise : 1 phrase de synthese puis 3 a 5 actions concretes a mener en priorite (controles a faire, equipements HS a traiter, consommables a remplacer ou reapprovisionner). Court, professionnel, sans markdown, sans rien inventer, pas de conseil medical.\n\nDONNEES :\n$ctx";
    $is_prod = str_contains($_SERVER['HTTP_HOST'] ?? '', 'cadurix.fr');
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>json_encode(['model'=>$model,'max_tokens'=>500,'messages'=>[['role'=>'user','content'=>$prompt]]]),
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','x-api-key: '.$api_key,'anthropic-version: 2023-06-01'],
        CURLOPT_TIMEOUT=>40, CURLOPT_SSL_VERIFYPEER=>$is_prod, CURLOPT_SSL_VERIFYHOST=>$is_prod?2:0]);
    $resp=curl_exec($ch); $http=curl_getinfo($ch,CURLINFO_HTTP_CODE); $cerr=curl_error($ch); curl_close($ch);
    if ($resp===false||$cerr){ echo json_encode(['error'=>"Reseau IA : $cerr"]); exit; }
    $jr=json_decode($resp,true);
    if ($http!==200||empty($jr['content'][0]['text'])){ echo json_encode(['error'=>($jr['error']['message']??"Erreur IA HTTP $http")]); exit; }
    echo json_encode(['ok'=>true,'texte'=>$jr['content'][0]['text']], JSON_UNESCAPED_UNICODE); exit;
}

require_once 'includes/header.php';

// Check permission après header.php — $nav_perms et nav_peut() sont maintenant disponibles
if (!nav_peut($nav_perms, 'sse')) { ob_end_clean(); accessDenied(); exit; }
?>
<style>
.sse-kpi { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:12px; margin-bottom:22px; }
.sse-kpi-card { position:relative; display:flex; align-items:center; gap:12px; background:var(--bg2); border:1px solid var(--border); border-radius:13px; padding:12px 15px; overflow:hidden; box-shadow:0 1px 2px rgba(20,30,60,.04); }
.sse-kpi-card::before { content:""; position:absolute; left:0; top:0; bottom:0; width:3px; background:var(--kc,var(--primary)); }
.sse-kpi-card .ic { width:36px; height:36px; border-radius:10px; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:1.05rem; }
.sse-kpi-num  { font-size:1.5rem; font-weight:800; line-height:1; font-variant-numeric:tabular-nums; }
.sse-kpi-label{ font-size:.68rem; color:var(--text3); margin-top:3px; text-transform:uppercase; letter-spacing:.04em; font-weight:700; }
.sse-badge { display:inline-block; padding:2px 10px; border-radius:20px; font-size:.72rem; font-weight:600; }
.sse-badge.danger  { background:rgba(239,68,68,.15); color:#f87171; }
.sse-badge.warning { background:rgba(245,158,11,.15); color:#fbbf24; }
.sse-badge.ok      { background:rgba(16,185,129,.15); color:#34d399; }
.sse-badge.info    { background:rgba(99,102,241,.15);  color:#818cf8; }
.sse-ia{border:1px solid var(--border);border-radius:14px;background:linear-gradient(135deg,rgba(59,130,246,.09),rgba(139,92,246,.07)),var(--bg2);padding:16px 18px}
.sse-ia-h{display:flex;align-items:center;gap:10px;font-size:.95rem;margin-bottom:8px}
.sse-ia-orb{width:34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#3b82f6,#8b5cf6);font-size:1rem}
.sse-ia-b{font-size:.87rem;color:var(--text2);line-height:1.6;white-space:pre-wrap}
</style>

<div class="page-header">
  <div class="page-title-group">
    <div class="page-title">🧪 SSE / NRBC</div>
    <div class="page-sub">Gestion des équipements et consommables de protection</div>
  </div>
  <div class="page-actions">
    <a href="/sse/controles.php" class="btn btn-sm btn-primary">✅ Nouveau contrôle</a>
  </div>
</div>

<!-- Navigation rapide SSE -->
<?php $sse_active = 'dashboard'; include __DIR__.'/sse/_nav.php'; ?>

<!-- KPIs -->
<div class="sse-kpi">
  <?php
  $ssekpis = [
    ['⚙️', $kpi['eq_total'],      'Équipements',   $kpi['eq_hs']>0?'#ef4444':($kpi['eq_ctrl_due']>0?'#f59e0b':'#10b981'), $kpi['eq_hs']>0 ? ['danger', $kpi['eq_hs'].' HS'] : null],
    ['💀', $kpi['eq_perime'],     'Équip. périmés', $kpi['eq_perime']>0?'#ef4444':'#10b981', null],
    ['✅', $kpi['eq_ctrl_due'],   'Contrôles dus', $kpi['eq_ctrl_due']>0?'#f59e0b':'#10b981', null],
    ['📦', $kpi['conso_total'],   'Consommables',  $kpi['conso_perime']>0?'#ef4444':($kpi['conso_critique']>0?'#f59e0b':'#10b981'), $kpi['conso_perime']>0 ? ['danger', $kpi['conso_perime'].' périmés'] : null],
    ['📉', $kpi['stock_bas'],     'Stock bas',     $kpi['stock_bas']>0?'#f59e0b':'#10b981', null],
    ['⚠️', $kpi['conso_critique'],'Expire < 30j',  $kpi['conso_critique']>0?'#f59e0b':'#10b981', null],
  ];
  foreach ($ssekpis as [$ic,$val,$lbl,$c,$badge]): ?>
  <div class="sse-kpi-card" style="--kc:<?= $c ?>">
    <span class="ic" style="background:<?= $c ?>1f;color:<?= $c ?>"><?= $ic ?></span>
    <div style="min-width:0">
      <div class="sse-kpi-num" style="color:<?= $c ?>"><?= $val ?></div>
      <div class="sse-kpi-label"><?= $lbl ?></div>
      <?php if ($badge): ?><div class="sse-badge <?= $badge[0] ?>" style="margin-top:4px"><?= $badge[1] ?></div><?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Analyse IA -->
<div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">
  <button type="button" id="sse-ia-btn" onclick="sseIA()" class="btn" style="background:linear-gradient(135deg,#3b82f6,#8b5cf6);color:#fff;border:none">🤖 Analyser l'état (IA)</button>
</div>
<div id="sse-ia-panel" style="display:none;margin-bottom:16px"></div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">

  <!-- Équipements en alerte -->
  <div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
      <span class="card-title">⚠️ Équipements à traiter</span>
      <a href="/sse/equipements.php" style="font-size:.8rem;color:var(--primary)">Tout voir →</a>
    </div>
    <?php if (empty($equipements_alerte)): ?>
    <div style="text-align:center;padding:2rem;color:var(--text3)">✅ Aucune alerte équipement</div>
    <?php else: ?>
    <?php foreach ($equipements_alerte as $eq): ?>
    <?php
      $alerte_type = 'info';
      $alerte_txt  = '';
      if ($eq['statut'] === 'hs')       { $alerte_type='danger';  $alerte_txt='Hors service'; }
      elseif ($eq['statut'] === 'revision') { $alerte_type='warning'; $alerte_txt='En révision'; }
      elseif ($eq['statut'] === 'reforme')  { $alerte_type='danger';  $alerte_txt='Réformé'; }
      elseif ($eq['date_peremption'] && $eq['date_peremption'] < date('Y-m-d')) { $alerte_type='danger'; $alerte_txt='Périmé'; }
      elseif ($eq['date_peremption'] && $eq['date_peremption'] <= date('Y-m-d', strtotime('+30 days'))) { $alerte_type='warning'; $alerte_txt='Expire bientôt'; }
      elseif ($eq['date_prochain_ctrl'] && $eq['date_prochain_ctrl'] <= date('Y-m-d')) { $alerte_type='warning'; $alerte_txt='Contrôle dû'; }
    ?>
    <div style="display:flex;align-items:center;gap:10px;padding:10px 16px;border-bottom:1px solid var(--border)">
      <span style="font-size:1.4rem"><?= $eq['icone'] ?? '⚙️' ?></span>
      <div style="flex:1;min-width:0">
        <div style="font-weight:600;font-size:.88rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
          <?= htmlspecialchars($eq['nom']) ?>
        </div>
        <div style="font-size:.75rem;color:var(--text3)">
          <?= htmlspecialchars($eq['categorie_nom'] ?? '') ?>
          <?php if ($eq['numero_serie']): ?> · N° <?= htmlspecialchars($eq['numero_serie']) ?><?php endif; ?>
        </div>
      </div>
      <span class="sse-badge <?= $alerte_type ?>"><?= $alerte_txt ?></span>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Consommables en alerte -->
  <div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
      <span class="card-title">📦 Consommables à traiter</span>
      <a href="/sse/consommables.php" style="font-size:.8rem;color:var(--primary)">Tout voir →</a>
    </div>
    <?php if (empty($consommables_alerte)): ?>
    <div style="text-align:center;padding:2rem;color:var(--text3)">✅ Aucune alerte consommable</div>
    <?php else: ?>
    <?php foreach ($consommables_alerte as $cs): ?>
    <?php
      $j = (int)($cs['jours'] ?? 999);
      $type = $cs['date_peremption'] && $cs['date_peremption'] < date('Y-m-d') ? 'danger'
            : ($j <= 7 ? 'danger' : ($j <= 30 ? 'warning' : 'warning'));
      $txt  = $cs['date_peremption'] && $cs['date_peremption'] < date('Y-m-d') ? 'Périmé'
            : ($j <= 0 ? 'Périmé' : "J-$j");
      if ($cs['quantite_min']>0 && $cs['quantite']<=$cs['quantite_min']) { $type='warning'; $txt='Stock bas'; }
    ?>
    <div style="display:flex;align-items:center;gap:10px;padding:10px 16px;border-bottom:1px solid var(--border)">
      <span style="font-size:1.4rem"><?= $cs['icone'] ?? '📦' ?></span>
      <div style="flex:1;min-width:0">
        <div style="font-weight:600;font-size:.88rem"><?= htmlspecialchars($cs['nom']) ?></div>
        <div style="font-size:.75rem;color:var(--text3)">
          Qté: <?= $cs['quantite'] ?> <?= $cs['unite'] ?>
          <?php if ($cs['numero_lot']): ?> · Lot: <?= htmlspecialchars($cs['numero_lot']) ?><?php endif; ?>
        </div>
      </div>
      <span class="sse-badge <?= $type ?>"><?= $txt ?></span>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Derniers contrôles -->
  <div class="card" style="grid-column:1/-1">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
      <span class="card-title">📋 Derniers contrôles</span>
      <a href="/sse/controles.php" style="font-size:.8rem;color:var(--primary)">Historique →</a>
    </div>
    <?php if (empty($derniers_controles)): ?>
    <div style="text-align:center;padding:2rem;color:var(--text3)">Aucun contrôle enregistré</div>
    <?php else: ?>
    <table style="width:100%;border-collapse:collapse">
      <thead>
        <tr style="border-bottom:1px solid var(--border)">
          <th style="padding:10px 16px;text-align:left;font-size:.75rem;color:var(--text3);text-transform:uppercase">Équipement</th>
          <th style="padding:10px 16px;text-align:left;font-size:.75rem;color:var(--text3);text-transform:uppercase">Agent</th>
          <th style="padding:10px 16px;text-align:left;font-size:.75rem;color:var(--text3);text-transform:uppercase">Date</th>
          <th style="padding:10px 16px;text-align:left;font-size:.75rem;color:var(--text3);text-transform:uppercase">Résultat</th>
          <th style="padding:10px 16px;text-align:left;font-size:.75rem;color:var(--text3);text-transform:uppercase">Prochain</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($derniers_controles as $ct): ?>
      <tr style="border-bottom:1px solid var(--border)">
        <td style="padding:10px 16px;font-weight:600;font-size:.88rem"><?= htmlspecialchars($ct['equipement_nom']) ?></td>
        <td style="padding:10px 16px;font-size:.85rem;color:var(--text2)"><?= htmlspecialchars($ct['agent']) ?></td>
        <td style="padding:10px 16px;font-size:.85rem"><?= date('d/m/Y', strtotime($ct['date_controle'])) ?></td>
        <td style="padding:10px 16px">
          <span class="sse-badge <?= $ct['resultat']==='ok'?'ok':($ct['resultat']==='hors_service'?'danger':'warning') ?>">
            <?= $ct['resultat']==='ok'?'✅ OK':($ct['resultat']==='hors_service'?'❌ Hors service':'⚠️ Anomalie') ?>
          </span>
        </td>
        <td style="padding:10px 16px;font-size:.85rem;color:var(--text2)">
          <?= $ct['prochain_controle'] ? date('d/m/Y', strtotime($ct['prochain_controle'])) : '—' ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

</div>

<script>
var SSE_CSRF = <?= json_encode(function_exists('csrf_token') ? csrf_token() : '') ?>;
function sseIA(){
  var b=document.getElementById('sse-ia-btn'), p=document.getElementById('sse-ia-panel');
  if(!p)return;
  p.style.display='block';
  p.innerHTML='<div class="sse-ia"><div class="sse-ia-h"><span class="sse-ia-orb">🤖</span><b>Analyse de l\'état SSE</b></div><div class="sse-ia-b">⏳ Analyse en cours…</div></div>';
  if(b)b.disabled=true;
  fetch('sse.php?ajax=ia',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':SSE_CSRF},body:'csrf_token='+encodeURIComponent(SSE_CSRF)})
    .then(function(r){return r.json();})
    .then(function(d){ if(b)b.disabled=false; var x=p.querySelector('.sse-ia-b'); if(d.ok){x.textContent=d.texte;}else{x.innerHTML='<span style="color:#ef4444">⚠️ '+(d.error||'Erreur')+'</span>';} })
    .catch(function(){ if(b)b.disabled=false; var x=p.querySelector('.sse-ia-b'); if(x)x.innerHTML='<span style="color:#ef4444">⚠️ Erreur réseau.</span>'; });
}
</script>
<?php require_once 'includes/footer.php'; ?>

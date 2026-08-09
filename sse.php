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

require_once 'includes/header.php';

// Check permission après header.php — $nav_perms et nav_peut() sont maintenant disponibles
if (!nav_peut($nav_perms, 'sse')) { ob_end_clean(); accessDenied(); exit; }
?>
<style>
.sse-kpi { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:14px; margin-bottom:24px; }
.sse-kpi-card { background:var(--bg2); border:1px solid var(--border); border-radius:12px; padding:16px; text-align:center; }
.sse-kpi-card.danger  { border-color:rgba(239,68,68,.4); background:rgba(239,68,68,.06); }
.sse-kpi-card.warning { border-color:rgba(245,158,11,.4); background:rgba(245,158,11,.06); }
.sse-kpi-card.ok      { border-color:rgba(16,185,129,.4); background:rgba(16,185,129,.06); }
.sse-kpi-num  { font-size:2rem; font-weight:800; line-height:1; }
.sse-kpi-label{ font-size:.72rem; color:var(--text3); margin-top:4px; text-transform:uppercase; letter-spacing:.05em; }
.sse-badge { display:inline-block; padding:2px 10px; border-radius:20px; font-size:.72rem; font-weight:600; }
.sse-badge.danger  { background:rgba(239,68,68,.15); color:#f87171; }
.sse-badge.warning { background:rgba(245,158,11,.15); color:#fbbf24; }
.sse-badge.ok      { background:rgba(16,185,129,.15); color:#34d399; }
.sse-badge.info    { background:rgba(99,102,241,.15);  color:#818cf8; }
.sse-nav { display:flex; gap:8px; margin-bottom:20px; flex-wrap:wrap; }
.sse-nav a { padding:8px 16px; border-radius:8px; font-size:.88rem; font-weight:600; text-decoration:none; background:var(--glass); border:1px solid var(--border); color:var(--text2); transition:.15s; }
.sse-nav a:hover, .sse-nav a.active { background:var(--primary); border-color:var(--primary); color:white; }
</style>

<div class="page-header">
  <div class="page-title-group">
    <div class="page-title">🧪 SSE / NRBC</div>
    <div class="page-sub">Gestion des équipements et consommables de protection</div>
  </div>
  <div class="page-actions">
    <a href="/sse/equipements.php" class="btn btn-sm btn-ghost">⚙️ Équipements</a>
    <a href="/sse/consommables.php" class="btn btn-sm btn-ghost">📦 Consommables</a>
    <a href="/sse/controles.php" class="btn btn-sm btn-primary">✅ Nouveau contrôle</a>
  </div>
</div>

<!-- Navigation rapide -->
<div class="sse-nav">
  <a href="/sse.php" class="active">📊 Dashboard</a>
  <a href="/sse/equipements.php">⚙️ Équipements (<?= $kpi['eq_total'] ?>)</a>
  <a href="/sse/consommables.php">📦 Consommables (<?= $kpi['conso_total'] ?>)</a>
  <a href="/sse/controles.php">📋 Contrôles</a>
  <a href="/sse/categories.php">🏷️ Catégories</a>
</div>

<!-- KPIs -->
<div class="sse-kpi">
  <div class="sse-kpi-card <?= $kpi['eq_hs']>0?'danger':($kpi['eq_ctrl_due']>0?'warning':'ok') ?>">
    <div class="sse-kpi-num" style="color:<?= $kpi['eq_hs']>0?'#f87171':($kpi['eq_ctrl_due']>0?'#fbbf24':'#34d399') ?>"><?= $kpi['eq_total'] ?></div>
    <div class="sse-kpi-label">Équipements</div>
    <?php if ($kpi['eq_hs']>0): ?><div class="sse-badge danger"><?= $kpi['eq_hs'] ?> HS</div><?php endif; ?>
  </div>
  <div class="sse-kpi-card <?= $kpi['eq_perime']>0?'danger':'ok' ?>">
    <div class="sse-kpi-num" style="color:<?= $kpi['eq_perime']>0?'#f87171':'#34d399' ?>"><?= $kpi['eq_perime'] ?></div>
    <div class="sse-kpi-label">Équip. périmés</div>
  </div>
  <div class="sse-kpi-card <?= $kpi['eq_ctrl_due']>0?'warning':'ok' ?>">
    <div class="sse-kpi-num" style="color:<?= $kpi['eq_ctrl_due']>0?'#fbbf24':'#34d399' ?>"><?= $kpi['eq_ctrl_due'] ?></div>
    <div class="sse-kpi-label">Contrôles dus</div>
  </div>
  <div class="sse-kpi-card <?= $kpi['conso_perime']>0?'danger':($kpi['conso_critique']>0?'warning':'ok') ?>">
    <div class="sse-kpi-num" style="color:<?= $kpi['conso_perime']>0?'#f87171':($kpi['conso_critique']>0?'#fbbf24':'#34d399') ?>"><?= $kpi['conso_total'] ?></div>
    <div class="sse-kpi-label">Consommables</div>
    <?php if ($kpi['conso_perime']>0): ?><div class="sse-badge danger"><?= $kpi['conso_perime'] ?> périmés</div><?php endif; ?>
  </div>
  <div class="sse-kpi-card <?= $kpi['stock_bas']>0?'warning':'ok' ?>">
    <div class="sse-kpi-num" style="color:<?= $kpi['stock_bas']>0?'#fbbf24':'#34d399' ?>"><?= $kpi['stock_bas'] ?></div>
    <div class="sse-kpi-label">Stock bas</div>
  </div>
  <div class="sse-kpi-card <?= $kpi['conso_critique']>0?'warning':'ok' ?>">
    <div class="sse-kpi-num" style="color:<?= $kpi['conso_critique']>0?'#fbbf24':'#34d399' ?>"><?= $kpi['conso_critique'] ?></div>
    <div class="sse-kpi-label">Expire &lt; 30j</div>
  </div>
</div>

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

<?php require_once 'includes/footer.php'; ?>

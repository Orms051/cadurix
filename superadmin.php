<?php
/**
 * Cadurix v5.2 — Dashboard Super Admin global
 * Vue consolidée de tous les sites / organisations
 */
require_once 'includes/auth.php';
requiert_super_admin();

$pageTitle = 'Vue globale — Super Admin';

// ── Statistiques globales ─────────────────────────────────
$stats_globales = $pdo->query("
    SELECT
        COUNT(DISTINCT o.id)  AS nb_orgs,
        COUNT(DISTINCT s.id)  AS nb_sites,
        COUNT(DISTINCT u.id)  AS nb_users,
        COUNT(DISTINCT p.id)  AS nb_produits,
        SUM(CASE WHEN p.date_peremption < CURDATE() AND p.statut='actif' THEN 1 ELSE 0 END) AS nb_perimes,
        SUM(CASE WHEN DATEDIFF(p.date_peremption,CURDATE()) BETWEEN 0 AND 7 AND p.statut='actif' THEN 1 ELSE 0 END) AS nb_critiques,
        SUM(CASE WHEN p.quantite<=p.quantite_min AND p.quantite_min>0 AND p.statut='actif' THEN 1 ELSE 0 END) AS nb_stock_bas
    FROM organisations o
    LEFT JOIN sites s ON s.organisation_id = o.id
    LEFT JOIN emplacements e ON e.site_id = s.id AND e.actif = 1
    LEFT JOIN produits p ON p.emplacement_id = e.id
    LEFT JOIN utilisateurs u ON u.actif = 1
")->fetch(PDO::FETCH_ASSOC);

// ── Stats par site ────────────────────────────────────────
$sites_stats = $pdo->query("
    SELECT
        s.id, s.nom AS site_nom, o.nom AS org_nom,
        COUNT(DISTINCT e.id)  AS nb_empls,
        COUNT(DISTINCT p.id)  AS nb_produits,
        COUNT(DISTINCT usp.user_id) AS nb_users,
        SUM(CASE WHEN p.date_peremption < CURDATE() AND p.statut='actif' THEN 1 ELSE 0 END) AS nb_perimes,
        SUM(CASE WHEN DATEDIFF(p.date_peremption,CURDATE()) BETWEEN 0 AND 7 AND p.statut='actif' THEN 1 ELSE 0 END) AS nb_critiques,
        SUM(CASE WHEN DATEDIFF(p.date_peremption,CURDATE()) BETWEEN 8 AND 30 AND p.statut='actif' THEN 1 ELSE 0 END) AS nb_avert,
        SUM(CASE WHEN p.quantite<=p.quantite_min AND p.quantite_min>0 AND p.statut='actif' THEN 1 ELSE 0 END) AS nb_stock_bas,
        MAX(al.cree_le) AS derniere_activite
    FROM organisations o
    JOIN sites s ON s.organisation_id = o.id
    LEFT JOIN emplacements e ON e.site_id = s.id AND e.actif = 1
    LEFT JOIN produits p ON p.emplacement_id = e.id
    LEFT JOIN user_site_permissions usp ON usp.site_id = s.id
    LEFT JOIN audit_log al ON al.cree_le > DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY s.id, s.nom, o.nom
    ORDER BY (nb_perimes + nb_critiques) DESC, o.nom, s.nom
")->fetchAll(PDO::FETCH_ASSOC);

// ── Activité récente globale ──────────────────────────────
$activite = $pdo->query("
    SELECT al.*, u.prenom, u.nom, u.login
    FROM audit_log al
    LEFT JOIN utilisateurs u ON u.id = al.user_id
    ORDER BY al.cree_le DESC
    LIMIT 15
")->fetchAll(PDO::FETCH_ASSOC);

// ── Produits les plus urgents (tous sites) ────────────────
$urgents = $pdo->query("
    SELECT p.nom, p.date_peremption,
           e.nom AS loc, s.nom AS site_nom, o.nom AS org_nom,
           DATEDIFF(p.date_peremption, CURDATE()) AS days_left
    FROM produits p
    JOIN emplacements e ON e.id = p.emplacement_id
    JOIN sites s ON s.id = e.site_id
    JOIN organisations o ON o.id = s.organisation_id
    WHERE p.statut = 'actif'
      AND p.date_peremption <= DATE_ADD(CURDATE(), INTERVAL 15 DAY)
    ORDER BY p.date_peremption ASC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

require_once 'includes/header.php';
?>

<div class="page-header">
  <div class="page-title-group">
    <div class="page-title">⭐ Vue globale Super Admin</div>
    <div class="page-sub">Tous les sites · <?= date('d/m/Y H:i') ?></div>
  </div>
  <a href="dashboard.php" class="btn btn-ghost">← Mon site</a>
</div>

<!-- KPI globaux -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:14px;margin-bottom:24px">
  <div class="kpi-card" style="border-top:3px solid var(--primary)">
    <div class="kpi-label">🏢 Organisations</div>
    <div class="kpi-value" style="color:var(--primary)"><?= $stats_globales['nb_orgs'] ?></div>
    <div class="kpi-foot"><?= $stats_globales['nb_sites'] ?> sites</div>
  </div>
  <div class="kpi-card" style="border-top:3px solid var(--primary)">
    <div class="kpi-label">💊 Produits</div>
    <div class="kpi-value" style="color:var(--primary)"><?= $stats_globales['nb_produits'] ?></div>
    <div class="kpi-foot">total actifs</div>
  </div>
  <div class="kpi-card" style="border-top:3px solid #6b7280">
    <div class="kpi-label">⛔ Périmés</div>
    <div class="kpi-value" style="color:#9CA3AF"><?= $stats_globales['nb_perimes'] ?></div>
    <div class="kpi-foot">retrait immédiat</div>
  </div>
  <div class="kpi-card" style="border-top:3px solid var(--red)">
    <div class="kpi-label">🔴 Critiques</div>
    <div class="kpi-value" style="color:var(--red)"><?= $stats_globales['nb_critiques'] ?></div>
    <div class="kpi-foot">≤ 7 jours</div>
  </div>
  <div class="kpi-card" style="border-top:3px solid var(--amber)">
    <div class="kpi-label">📦 Stocks bas</div>
    <div class="kpi-value" style="color:var(--amber)"><?= $stats_globales['nb_stock_bas'] ?></div>
    <div class="kpi-foot">à réapprovisionner</div>
  </div>
  <div class="kpi-card" style="border-top:3px solid var(--primary)">
    <div class="kpi-label">👥 Utilisateurs</div>
    <div class="kpi-value" style="color:var(--primary)"><?= $stats_globales['nb_users'] ?></div>
    <div class="kpi-foot">actifs</div>
  </div>
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:18px;margin-bottom:20px">

  <!-- Sites -->
  <div class="card">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
      <div class="card-title" style="margin-bottom:0">🏢 Statut par site</div>
      <span style="font-size:.78rem;color:var(--text2)"><?= count($sites_stats) ?> site(s)</span>
    </div>
    <div style="display:flex;flex-direction:column;gap:8px">
    <?php foreach ($sites_stats as $s):
      $urgence = ($s['nb_perimes'] + $s['nb_critiques']) > 0;
      $bcolor = $s['nb_perimes'] > 0 ? '#6b7280' : ($s['nb_critiques'] > 0 ? '#EF4444' : ($s['nb_avert'] > 0 ? '#F59E0B' : 'var(--primary)'));
    ?>
    <div style="display:flex;align-items:center;gap:12px;padding:10px 14px;background:var(--glass);border:1px solid var(--border);border-radius:10px;border-left:3px solid <?= $bcolor ?>">
      <div style="flex:1;min-width:0">
        <div style="font-size:.85rem;font-weight:600"><?= h($s['site_nom']) ?></div>
        <div style="font-size:.72rem;color:var(--text2)"><?= h($s['org_nom']) ?> · <?= $s['nb_empls'] ?> empl. · <?= $s['nb_users'] ?> users</div>
      </div>
      <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
        <span style="font-size:.72rem;color:var(--text2)"><?= $s['nb_produits'] ?> produits</span>
        <?php if ($s['nb_perimes'] > 0): ?>
        <span class="badge badge-expired" style="font-size:10px">⛔ <?= $s['nb_perimes'] ?></span>
        <?php endif; ?>
        <?php if ($s['nb_critiques'] > 0): ?>
        <span class="badge badge-critical" style="font-size:10px">🔴 <?= $s['nb_critiques'] ?></span>
        <?php endif; ?>
        <?php if ($s['nb_avert'] > 0): ?>
        <span class="badge badge-warning" style="font-size:10px">🟡 <?= $s['nb_avert'] ?></span>
        <?php endif; ?>
        <?php if ($s['nb_stock_bas'] > 0): ?>
        <span class="badge badge-stock" style="font-size:10px">📦 <?= $s['nb_stock_bas'] ?></span>
        <?php endif; ?>
        <?php if (!$s['nb_perimes'] && !$s['nb_critiques'] && !$s['nb_avert']): ?>
        <span class="badge badge-ok" style="font-size:10px">✓ OK</span>
        <?php endif; ?>
      </div>
      <a href="?site_id=<?= $s['id'] ?>" onclick="definirSite(<?= $s['id'] ?>)"
         class="btn btn-ghost btn-xs" title="Accéder à ce site">→</a>
    </div>
    <?php endforeach; ?>
    </div>
  </div>

  <!-- Urgents + Activité -->
  <div style="display:flex;flex-direction:column;gap:18px">

    <!-- Produits urgents tous sites -->
    <?php if (!empty($urgents)): ?>
    <div class="card">
      <div class="card-title" style="margin-bottom:12px">⚠️ Urgents — tous sites</div>
      <div style="display:flex;flex-direction:column;gap:6px">
      <?php foreach ($urgents as $u):
        $days = (int)$u['days_left'];
        $bc   = $days < 0 ? 'badge-expired' : ($days <= 7 ? 'badge-critical' : 'badge-warning');
        $lbl  = $days < 0 ? 'Périmé' : "J-$days";
      ?>
      <div style="display:flex;align-items:center;gap:8px;padding:7px 10px;background:var(--glass);border:1px solid var(--border);border-radius:8px">
        <div style="flex:1;min-width:0">
          <div style="font-size:.8rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= h($u['nom']) ?></div>
          <div style="font-size:.68rem;color:var(--text3)"><?= h($u['site_nom']) ?> · <?= h($u['loc']) ?></div>
        </div>
        <span class="badge <?= $bc ?>" style="font-size:10px;flex-shrink:0"><?= $lbl ?></span>
      </div>
      <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Activité récente -->
    <div class="card">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
        <div class="card-title" style="margin-bottom:0">🕐 Activité récente</div>
        <a href="admin/audit.php" class="btn btn-ghost btn-xs">Tout voir</a>
      </div>
      <div style="display:flex;flex-direction:column;gap:7px">
      <?php foreach ($activite as $a): ?>
      <div style="display:flex;align-items:flex-start;gap:8px">
        <div style="width:24px;height:24px;border-radius:50%;background:var(--primary-bg);display:flex;align-items:center;justify-content:center;font-size:10px;flex-shrink:0;color:var(--primary)">
          <?= strtoupper(substr($a['prenom']??'?',0,1)) ?>
        </div>
        <div style="flex:1;min-width:0">
          <div style="font-size:.75rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= h($a['action']) ?></div>
          <div style="font-size:.68rem;color:var(--text3)"><?= h(($a['prenom']??'').' '.($a['nom']??'')) ?> · <?= date('d/m H:i',strtotime($a['cree_le'])) ?></div>
        </div>
      </div>
      <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<script>
function definirSite(id) {
  // Changer le site actif puis recharger le dashboard
  fetch('/dashboard.php?site_id=' + id).then(() => {
    window.location.href = '/dashboard.php';
  });
  return false;
}
</script>

<?php require_once 'includes/footer.php'; ?>

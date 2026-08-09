<?php
/**
 * Cadurix v5 — Export PDF rapports de péremption
 * Utilise mPDF via une implémentation HTML→PDF sans Composer
 * Solution : génération HTML + envoi avec header PDF (pour impression navigateur)
 * Compatible 1&1 — zéro dépendance externe
 *
 * Pour une vraie génération PDF binaire sans Composer :
 * Télécharger TCPDF.php depuis https://github.com/tecnickcom/tcpdf
 * et le placer dans /libs/tcpdf/ — le code détectera automatiquement sa présence
 */
require_once __DIR__ . '/includes/auth.php';

$site_id  = site_actif();
$filtre   = $_GET['filtre'] ?? 'tous'; // tous | perime | critique | avertissement | stock_bas
$format   = $_GET['format'] ?? 'html'; // html | pdf
$date_gen = date('d/m/Y H:i');

if (!$site_id) {
    die('Aucun site actif sélectionné.');
}

// ----------------------------------------------------------------
//  Récupérer le nom du site
// ----------------------------------------------------------------
$stmt = $pdo->prepare("SELECT s.nom, o.nom AS org_nom FROM sites s JOIN organisations o ON o.id = s.organisation_id WHERE s.id = ?");
$stmt->execute([$site_id]);
$site = $stmt->fetch(PDO::FETCH_ASSOC);

// ----------------------------------------------------------------
//  Construction de la requête avec filtres de permissions
// ----------------------------------------------------------------
$filtre_empl = sql_filtre_emplacements('e');
$filtre_site_val = intval($site_id); // sécurisé par intval
$filtre_site = "e.site_id = $filtre_site_val";

$where_extra = '';
switch ($filtre) {
    case 'perime':       $where_extra = "AND p.date_peremption < CURDATE()"; break;
    case 'critique':     $where_extra = "AND p.date_peremption BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 15 DAY)"; break;
    case 'avertissement':$where_extra = "AND p.date_peremption BETWEEN DATE_ADD(CURDATE(), INTERVAL 15 DAY) AND DATE_ADD(CURDATE(), INTERVAL p.alerte_jours DAY)"; break;
    case 'stock_bas':    $where_extra = "AND p.quantite <= p.quantite_min AND p.quantite_min > 0"; break;
    case 'froid':        $where_extra = "AND p.chaine_froid = 1"; break;
    default:             $where_extra = ''; break;
}

$stmt = $pdo->prepare("
    SELECT p.*,
           e.nom AS emplacement_nom,
           ep.nom AS parent_nom,
           DATEDIFF(p.date_peremption, CURDATE()) AS jours_restants,
           CASE
             WHEN p.date_peremption < CURDATE() THEN 'perime'
             WHEN DATEDIFF(p.date_peremption, CURDATE()) <= 15 THEN 'critique'
             WHEN DATEDIFF(p.date_peremption, CURDATE()) <= p.alerte_jours THEN 'avertissement'
             ELSE 'ok'
           END AS niveau_alerte,
           CASE WHEN p.quantite <= p.quantite_min AND p.quantite_min > 0 THEN 1 ELSE 0 END AS stock_bas
    FROM produits p
    JOIN emplacements e  ON e.id = p.emplacement_id
    LEFT JOIN emplacements ep ON ep.id = e.parent_id
    WHERE p.statut = 'actif'
      AND $filtre_site
      AND $filtre_empl
      $where_extra
    ORDER BY p.date_peremption ASC, e.nom
");
$stmt->execute();
$produits = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistiques
$stats = ['total' => count($produits), 'perime' => 0, 'critique' => 0, 'avertissement' => 0, 'ok' => 0, 'stock_bas' => 0];
foreach ($produits as $p) {
    $stats[$p['niveau_alerte']]++;
    if ($p['stock_bas']) $stats['stock_bas']++;
}

$titres_filtres = ['tous'=>'Tous les produits','perime'=>'Produits périmés','critique'=>'Alertes critiques','avertissement'=>'Avertissements','stock_bas'=>'Stock bas','froid'=>'Chaîne du froid'];
$titre_rapport = $titres_filtres[$filtre] ?? 'Rapport';

// ----------------------------------------------------------------
//  Si format=pdf et TCPDF disponible
// ----------------------------------------------------------------
$tcpdf_disponible = file_exists(__DIR__ . '/libs/tcpdf/tcpdf.php');

if ($format === 'pdf' && $tcpdf_disponible) {
    // Code TCPDF ici si disponible
    require_once __DIR__ . '/libs/tcpdf/tcpdf.php';
    // ... (à compléter avec TCPDF si installé)
}

// ----------------------------------------------------------------
//  Rendu HTML
// ----------------------------------------------------------------
if ($format === 'pdf' && !$tcpdf_disponible) {
    header('Content-Type: text/html; charset=utf-8');
}

if ($format === 'html') {
    $pageTitle = 'Export PDF — ' . ($site['nom'] ?? '');
    require_once __DIR__ . '/includes/header.php';
}
?>
<?php if ($format === 'html'): ?>
<style>
/* ── Export PDF — thème dynamique ── */
.no-print { display:flex; gap:.75rem; margin-bottom:1.5rem; flex-wrap:wrap; align-items:center; }
.rpt-box  { background:var(--bg2); border:1px solid var(--border); border-radius:var(--radius); padding:1.5rem; }
.rpt-header { display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:1.5rem; padding-bottom:1rem; border-bottom:2px solid var(--border); }
.rpt-title  { font-size:1.4rem; font-weight:700; margin-bottom:.2rem; color:var(--text); }
.rpt-sub    { font-size:.85rem; color:var(--text2); }
.rpt-stats  { display:grid; grid-template-columns:repeat(5,1fr); gap:1rem; margin-bottom:1.5rem; }
.rpt-stat   { text-align:center; padding:.75rem; border:1px solid var(--border); border-radius:8px; background:var(--bg3); }
.rpt-stat-n { font-size:1.5rem; font-weight:700; }
.rpt-stat-l { font-size:.7rem; color:var(--text3); margin-top:.2rem; }
.rpt-table  { width:100%; border-collapse:collapse; font-size:.8rem; }
.rpt-table th { padding:.5rem .75rem; text-align:left; font-size:.72rem; text-transform:uppercase; font-weight:600; border-bottom:2px solid var(--border); color:var(--text3); }
.rpt-table td { padding:.5rem .75rem; border-bottom:1px solid var(--border); color:var(--text); }
.rpt-table tr:hover td { background:var(--glass); }
.rpt-footer { margin-top:1.5rem; padding-top:1rem; border-top:1px solid var(--border); font-size:.75rem; color:var(--text3); display:flex; justify-content:space-between; }
.b-btn { display:inline-flex; align-items:center; gap:.4rem; padding:.4rem 1rem; border-radius:8px; font-size:.875rem; font-weight:500; cursor:pointer; border:1px solid var(--border); text-decoration:none; background:var(--glass); color:var(--text2); transition:all .15s; }
.b-btn:hover { border-color:var(--primary); color:var(--primary); }
.b-btn.active { background:var(--primary); color:#fff; border-color:var(--primary); }
.b-btn-print { background:var(--primary); color:#fff; border-color:var(--primary); }
.b-btn-print:hover { opacity:.85; }
.rpt-badge { display:inline-block; padding:.15rem .55rem; border-radius:99px; font-size:.72rem; font-weight:600; }
.b-perime  { background:rgba(239,68,68,.12);  color:#ef4444; border:1px solid rgba(239,68,68,.3); }
.b-critique{ background:rgba(245,158,11,.12); color:#f59e0b; border:1px solid rgba(245,158,11,.3); }
.b-avert   { background:var(--primary-bg);    color:var(--primary); border:1px solid rgba(var(--primary-rgb),.3); }
.b-ok      { background:rgba(16,185,129,.12); color:#10b981; border:1px solid rgba(16,185,129,.3); }
.b-stock   { background:rgba(239,68,68,.12);  color:#ef4444; border:1px solid rgba(239,68,68,.3); }
@media print { .no-print{display:none} }
</style>
<?php else: ?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Rapport péremptions — <?= htmlspecialchars($site['org_nom'] . ' — ' . $site['nom']) ?></title>
<style>
body{font-family:Arial,sans-serif;color:#000;padding:1cm;font-size:10pt}
.no-print{display:none}
.rpt-box{border:1px solid #ccc;border-radius:4px;padding:1cm}
.rpt-table{width:100%;border-collapse:collapse}
.rpt-table th,.rpt-table td{border:1px solid #ddd;padding:4px 8px;font-size:9pt}
.rpt-table th{background:#f0f0f0}
.rpt-stats{display:table;width:100%;margin-bottom:1cm}
.rpt-stat{display:table-cell;text-align:center;border:1px solid #ddd;padding:.5cm}
.rpt-header{margin-bottom:.5cm;padding-bottom:.3cm;border-bottom:2px solid #000}
.rpt-title{font-size:14pt;font-weight:700}.rpt-sub{font-size:9pt;color:#666}
.rpt-stat-n{font-size:14pt;font-weight:700}.rpt-stat-l{font-size:8pt;color:#666}
.rpt-badge{display:inline-block;padding:1px 6px;border-radius:99px;font-size:8pt;font-weight:600;border:1px solid}
.b-perime{color:#ef4444;border-color:#ef4444}.b-critique{color:#f59e0b;border-color:#f59e0b}
.b-avert{color:#3b82f6;border-color:#3b82f6}.b-ok{color:#10b981;border-color:#10b981}
.b-stock{color:#ef4444;border-color:#ef4444}
.rpt-footer{margin-top:.5cm;font-size:8pt;color:#666;display:flex;justify-content:space-between}
@page{size:A4 landscape;margin:1cm}
</style>
</head>
<body>
<?php endif; ?>

<!-- Barre d'outils -->
<div class="no-print">
  <a href="/products.php" class="b-btn">← Médicaments</a>
  <div style="width:1px;background:var(--border);margin:0 4px"></div>
  <a href="?filtre=tous"          class="b-btn <?= $filtre==='tous'?'active':'' ?>">Tous (<?= $stats['total'] ?>)</a>
  <a href="?filtre=perime"        class="b-btn <?= $filtre==='perime'?'active':'' ?>">⛔ Périmés (<?= $stats['perime'] ?>)</a>
  <a href="?filtre=critique"      class="b-btn <?= $filtre==='critique'?'active':'' ?>">🔴 Critiques (<?= $stats['critique'] ?>)</a>
  <a href="?filtre=avertissement" class="b-btn <?= $filtre==='avertissement'?'active':'' ?>">🟡 Avertissements</a>
  <a href="?filtre=stock_bas"     class="b-btn <?= $filtre==='stock_bas'?'active':'' ?>">📦 Stock bas</a>
  <a href="?filtre=froid"         class="b-btn <?= $filtre==='froid'?'active':'' ?>">❄️ Froid</a>
  <div style="flex:1"></div>
  <button class="b-btn b-btn-print" onclick="window.print()">🖨️ Imprimer / PDF</button>
</div>

<div class="rpt-box">
  <!-- En-tête rapport -->
  <div class="rpt-header">
    <div>
      <div class="rpt-title">📋 <?= htmlspecialchars($titre_rapport) ?></div>
      <div class="rpt-sub">
        <?= htmlspecialchars($site['org_nom']) ?> — <?= htmlspecialchars($site['nom']) ?><br>
        Généré le <?= $date_gen ?> par <?= htmlspecialchars($_SESSION['user_nom'] ?? 'Utilisateur') ?>
      </div>
    </div>
    <div style="text-align:right">
      <div style="font-size:2rem">💊</div>
      <div style="font-size:.75rem;color:<?= $format==='html'?'#64748b':'#666' ?>">Cadurix v5</div>
    </div>
  </div>

  <!-- Stats -->
  <div class="rpt-stats">
    <div class="rpt-stat">
      <div class="rpt-stat-n"><?= $stats['total'] ?></div>
      <div class="rpt-stat-l">Total produits</div>
    </div>
    <div class="rpt-stat">
      <div class="rpt-stat-n" style="color:#ef4444"><?= $stats['perime'] ?></div>
      <div class="rpt-stat-l">⛔ Périmés</div>
    </div>
    <div class="rpt-stat">
      <div class="rpt-stat-n" style="color:#f59e0b"><?= $stats['critique'] ?></div>
      <div class="rpt-stat-l">🔴 Critiques</div>
    </div>
    <div class="rpt-stat">
      <div class="rpt-stat-n" style="color:#3b82f6"><?= $stats['avertissement'] ?></div>
      <div class="rpt-stat-l">🟡 Avertissements</div>
    </div>
    <div class="rpt-stat">
      <div class="rpt-stat-n" style="color:#ef4444"><?= $stats['stock_bas'] ?></div>
      <div class="rpt-stat-l">📦 Stock bas</div>
    </div>
  </div>

  <!-- Tableau produits -->
  <?php if (empty($produits)): ?>
  <p style="text-align:center;padding:2rem;color:<?= $format==='html'?'#64748b':'#666' ?>">Aucun produit pour ce filtre.</p>
  <?php else: ?>
  <table class="rpt-table">
    <tr>
      <th>Emplacement</th>
      <th>Produit</th>
      <th>Référence</th>
      <th>N° Lot</th>
      <th>Quantité</th>
      <th>Date péremption</th>
      <th>Statut</th>
      <th>Stock</th>
    </tr>
    <?php foreach ($produits as $p): ?>
    <tr>
      <td>
        <?php if ($p['parent_nom']): ?>
        <span style="color:<?= $format==='html'?'#64748b':'#888' ?>;font-size:.75rem"><?= htmlspecialchars($p['parent_nom']) ?> ›</span><br>
        <?php endif; ?>
        <?= htmlspecialchars($p['emplacement_nom']) ?>
        <?= $p['chaine_froid'] ? ' ❄️' : '' ?>
      </td>
      <td><strong><?= htmlspecialchars($p['nom']) ?></strong></td>
      <td style="font-size:.8rem"><?= htmlspecialchars($p['reference'] ?? '—') ?></td>
      <td style="font-size:.8rem"><?= htmlspecialchars($p['numero_lot'] ?? '—') ?></td>
      <td><?= $p['quantite'] ?> <?= htmlspecialchars($p['unite']) ?></td>
      <td>
        <?= date('d/m/Y', strtotime($p['date_peremption'])) ?>
        <br>
        <span style="font-size:.72rem;color:<?= $format==='html'?'#64748b':'#888' ?>">
          <?php
          $j = $p['jours_restants'];
          if ($j < 0) echo abs($j) . 'j dépassé';
          elseif ($j == 0) echo 'Aujourd\'hui';
          else echo 'Dans ' . $j . 'j';
          ?>
        </span>
      </td>
      <td>
        <?php
        $badges = ['perime'=>'b-perime','critique'=>'b-critique','avertissement'=>'b-avert','ok'=>'b-ok'];
        $labels = ['perime'=>'⛔ Périmé','critique'=>'🔴 Critique','avertissement'=>'🟡 Alerte','ok'=>'✓ OK'];
        $niv = $p['niveau_alerte'];
        ?>
        <span class="rpt-badge <?= $badges[$niv] ?? '' ?>"><?= $labels[$niv] ?? $niv ?></span>
      </td>
      <td>
        <?php if ($p['stock_bas']): ?>
        <span class="rpt-badge b-stock">📦 Bas</span>
        <?php else: ?>
        <span style="color:<?= $format==='html'?'#64748b':'#888' ?>">—</span>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>

  <!-- Pied de rapport -->
  <div class="rpt-footer">
    <span>Cadurix v5 — <?= htmlspecialchars($site['org_nom'] . ' / ' . $site['nom']) ?></span>
    <span>Rapport généré le <?= $date_gen ?> — <?= $stats['total'] ?> produit(s)</span>
    <span>Page 1</span>
  </div>
</div>

<?php if ($format === 'html'): ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
<?php else: ?>
</body>
</html>
<?php endif; ?>

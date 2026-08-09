<?php
/**
 * Cadurix v5 — Fiche de contrôle terrain
 * Imprimable en PDF via Ctrl+P
 * Par emplacement, avec cases à cocher et signature
 */
require_once 'includes/auth.php';

$site_id  = site_actif();
$empl_id  = isset($_GET['emplacement']) ? (int)$_GET['emplacement'] : 0;
$fiche_id = isset($_GET['fiche']) ? (int)$_GET['fiche'] : 0;
$date_gen = date('d/m/Y à H:i');
$date_ctrl= $_GET['date'] ?? date('Y-m-d');

if (!$site_id) { header('Location: dashboard.php'); exit; }

// ── Mode fiche remplie ────────────────────────────────────
$fiche_data    = null;
$fiche_sections = [];
if ($fiche_id) {
    $sf = $pdo->prepare("
        SELECT f.*, m.nom AS modele_nom, u.prenom, u.nom AS user_nom,
               s.nom AS site_nom, o.nom AS org_nom
        FROM fiches_remplies f
        JOIN modeles_fiches m ON m.id = f.modele_id
        JOIN utilisateurs u ON u.id = f.user_id
        JOIN sites s ON s.id = f.site_id
        JOIN organisations o ON o.id = s.organisation_id
        WHERE f.id = ? AND f.site_id = ?
    ");
    $sf->execute([$fiche_id, $site_id]);
    $fiche_data = $sf->fetch(PDO::FETCH_ASSOC);

    // Sécurité : admin ou auteur uniquement
    if ($fiche_data && !isAdmin() && $fiche_data['user_id'] !== currentUserId()) {
        $fiche_data = null;
    }

    if ($fiche_data) {
        $reps = $pdo->prepare("
            SELECT fr.*, mp.libelle, mp.type, mp.unite, mp.bloquant,
                   ms.titre AS section_titre, ms.ordre AS section_ordre, mp.ordre AS point_ordre
            FROM fiche_reponses fr
            JOIN modele_points mp ON mp.id = fr.point_id
            JOIN modele_sections ms ON ms.id = mp.section_id
            WHERE fr.fiche_id = ?
            ORDER BY ms.ordre, mp.ordre
        ");
        $reps->execute([$fiche_id]);
        $nb_ok = 0; $nb_nok = 0;
        foreach ($reps->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $fiche_sections[$r['section_titre']][] = $r;
            $r['conforme'] ? $nb_ok++ : $nb_nok++;
        }
        $nb_total_points = $nb_ok + $nb_nok;
    }
}

// Emplacements disponibles
$fe = sql_filtre_emplacements('e');
$fs = "e.site_id = " . intval($site_id);

if ($empl_id) {
    $stmt = $pdo->prepare("SELECT e.*, s.nom AS site_nom, o.nom AS org_nom FROM emplacements e JOIN sites s ON s.id=e.site_id JOIN organisations o ON o.id=s.organisation_id WHERE e.id=? AND e.site_id=?");
    $stmt->execute([$empl_id, $site_id]);
    $emplacement = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$emplacement) { header('Location: fiche_controle.php'); exit; }

    // Produits de cet emplacement + sous-emplacements
    $ids = [$empl_id];
    $subs = $pdo->prepare("SELECT id FROM emplacements WHERE parent_id=? AND actif=1");
    $subs->execute([$empl_id]);
    foreach ($subs->fetchAll(PDO::FETCH_COLUMN) as $sid) $ids[] = $sid;
    $in = implode(',', $ids);

    $produits = $pdo->query("
        SELECT p.*, e.nom AS empl_nom,
               DATEDIFF(p.date_peremption, CURDATE()) AS days_left,
               CASE
                 WHEN p.date_peremption < CURDATE() THEN 'perime'
                 WHEN DATEDIFF(p.date_peremption, CURDATE()) <= 7 THEN 'critique'
                 WHEN DATEDIFF(p.date_peremption, CURDATE()) <= p.alerte_jours THEN 'avertissement'
                 ELSE 'ok'
               END AS niveau
        FROM produits p
        JOIN emplacements e ON e.id = p.emplacement_id
        WHERE p.emplacement_id IN ($in) AND p.statut = 'actif'
        ORDER BY p.date_peremption ASC, p.nom
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Infos site
    $site_info = $pdo->prepare("SELECT s.nom, o.nom AS org_nom FROM sites s JOIN organisations o ON o.id=s.organisation_id WHERE s.id=?");
    $site_info->execute([$site_id]);
    $site_info = $site_info->fetch(PDO::FETCH_ASSOC);

    // Stats
    $nb_perime   = count(array_filter($produits, fn($p) => $p['niveau']==='perime'));
    $nb_critique = count(array_filter($produits, fn($p) => $p['niveau']==='critique'));
    $nb_stock    = count(array_filter($produits, fn($p) => $p['quantite_min']>0 && $p['quantite']<=$p['quantite_min']));
}

// Liste emplacements pour le sélecteur
$stmt = $pdo->prepare("SELECT e.id, e.nom, ep.nom AS parent_nom FROM emplacements e LEFT JOIN emplacements ep ON e.parent_id=ep.id WHERE e.site_id=? AND e.actif=1 ORDER BY COALESCE(ep.nom, e.nom), e.nom");
$stmt->execute([$site_id]);
$tous_empls = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $fiche_data ? h($fiche_data["modele_nom"]) : "Fiche de contrôle" ?> — Cadurix</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
/* ── Écran ────────────────────────────────────────────── */
:root {
  --bg:#0b0f1a;--surface:#111827;--border:#1f2d45;
  --primary:#3b82f6;--red:#ef4444;--amber:#f59e0b;--green:#10b981;
  --text:#e2e8f0;--muted:#64748b;--radius:.75rem;
}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
.no-print{padding:1.5rem 2rem;background:var(--surface);border-bottom:1px solid var(--border);display:flex;align-items:center;gap:1rem;flex-wrap:wrap}
.no-print h1{font-size:1.1rem;font-weight:700;flex:1}
.btn{display:inline-flex;align-items:center;gap:6px;padding:.5rem 1.1rem;border-radius:.5rem;font-size:.875rem;font-weight:600;cursor:pointer;border:none;text-decoration:none;transition:all .15s}
.btn-primary{background:var(--primary);color:#fff}.btn-primary:hover{background:#2563eb}
.btn-ghost{background:rgba(255,255,255,.06);border:1px solid var(--border);color:var(--text)}.btn-ghost:hover{background:rgba(255,255,255,.1)}
.form-control{padding:.5rem .875rem;background:rgba(255,255,255,.06);border:1px solid var(--border);border-radius:.4rem;color:var(--text);font-family:inherit;font-size:.875rem}
.form-control:focus{outline:none;border-color:var(--primary)}
select.form-control option{background:#111827}
.preview{padding:2rem;max-width:900px;margin:0 auto}

/* ── Impression ───────────────────────────────────────── */
@media print {
  @page { size: A4 portrait; margin: .9cm 1cm; }
  body { background: white !important; color: black !important; font-size: 8pt; }
  .no-print { display: none !important; }
  .preview { padding: 0; max-width: 100%; }
  .print-card { break-inside: avoid; }

  /* Fiche remplie ultra-compacte */
  .fr-section-title {
    font-size: 8pt !important; font-weight: 800 !important;
    background: #f3f4f6 !important; color: #111 !important;
    padding: 3px 8px !important; margin: 6px 0 2px !important;
    border-left: 3px solid #3b82f6 !important;
    break-after: avoid !important;
    print-color-adjust: exact; -webkit-print-color-adjust: exact;
  }
  .fr-row {
    display: flex !important; align-items: baseline !important;
    border-bottom: .5pt solid #e5e7eb !important;
    padding: 1.5px 4px !important; break-inside: avoid !important;
    font-size: 7.5pt !important;
  }
  .fr-row.nok { background: #fff5f5 !important; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
  .fr-libelle { flex: 1; }
  .fr-val { font-weight: 700; min-width: 40px; text-align: right; }
  .fr-val.ok  { color: #16a34a !important; print-color-adjust: exact; }
  .fr-val.nok { color: #dc2626 !important; print-color-adjust: exact; }
  .fr-comment { font-size: 7pt !important; color: #dc2626 !important; font-style: italic; margin-left: 4px; }
}

/* ── Fiche ────────────────────────────────────────────── */
.fiche { background: white; color: #111; border-radius: .75rem; overflow: hidden; box-shadow: 0 4px 24px rgba(0,0,0,.4); }
@media print { .fiche { box-shadow: none; border-radius: 0; } }

.fiche-header {
  background: linear-gradient(135deg, #ef4444, #f97316);
  color: white; padding: 1.25rem 1.5rem;
  display: flex; align-items: center; justify-content: space-between;
}
.fiche-logo { font-size: 1.3rem; font-weight: 800; letter-spacing: -.3px; }
.fiche-logo span { opacity: .7; font-weight: 400; font-size: .9rem; }
.fiche-date { text-align: right; font-size: .8rem; opacity: .85; }

.fiche-info {
  display: grid; grid-template-columns: repeat(4, 1fr);
  border-bottom: 2px solid #f0f0f0; background: #f9fafb;
}
.info-cell { padding: .75rem 1rem; border-right: 1px solid #e5e7eb; }
.info-cell:last-child { border-right: none; }
.info-label { font-size: .65rem; text-transform: uppercase; letter-spacing: 1px; color: #9ca3af; font-weight: 600; margin-bottom: .2rem; }
.info-val { font-size: .9rem; font-weight: 700; color: #111; }
.info-sub { font-size: .75rem; color: #6b7280; margin-top: .1rem; }

.fiche-body { padding: 1.25rem 1.5rem; }

.fiche-table { width: 100%; border-collapse: collapse; font-size: .82rem; }
.fiche-table th {
  padding: .5rem .75rem; text-align: left;
  font-size: .65rem; text-transform: uppercase; letter-spacing: .8px;
  color: #6b7280; font-weight: 600;
  border-bottom: 2px solid #e5e7eb;
  background: #f9fafb;
}
.fiche-table td { padding: .55rem .75rem; border-bottom: 1px solid #f0f0f0; vertical-align: middle; }
.fiche-table tr:last-child td { border-bottom: none; }
.fiche-table tr:hover td { background: #fafafa; }
@media print { .fiche-table tr:hover td { background: transparent; } }

/* Cases à cocher imprimables */
.check-box {
  width: 16px; height: 16px; border: 1.5px solid #d1d5db; border-radius: 3px;
  display: inline-block; flex-shrink: 0;
}
@media print { .check-box { print-color-adjust: exact; -webkit-print-color-adjust: exact; } }

/* Badges statut */
.st { display: inline-block; padding: .15rem .55rem; border-radius: 99px; font-size: .7rem; font-weight: 700; }
.st-perime   { background: #f3f4f6; color: #6b7280; }
.st-critique { background: #fef2f2; color: #ef4444; border: 1px solid #fecaca; }
.st-avert    { background: #fffbeb; color: #f59e0b; border: 1px solid #fde68a; }
.st-ok       { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }
.st-stock    { background: #fffbeb; color: #d97706; border: 1px solid #fde68a; }

/* Section signature */
.signature-section {
  display: grid; grid-template-columns: 1fr 1fr;
  gap: 1.5rem; margin-top: 1.25rem; padding-top: 1.25rem;
  border-top: 2px solid #f0f0f0;
}
.sig-box { border: 1.5px dashed #d1d5db; border-radius: .5rem; padding: .75rem 1rem; min-height: 80px; }
.sig-label { font-size: .72rem; text-transform: uppercase; letter-spacing: .8px; color: #9ca3af; font-weight: 600; margin-bottom: .4rem; }
.sig-line { border-bottom: 1px solid #d1d5db; margin-top: 1.5rem; }

/* Résumé alertes */
.alertes-grid { display: grid; grid-template-columns: repeat(3,1fr); gap: .75rem; margin-bottom: 1.25rem; }
.alerte-box { border-radius: .4rem; padding: .6rem .875rem; text-align: center; }
.alerte-box-val { font-size: 1.6rem; font-weight: 800; }
.alerte-box-lbl { font-size: .7rem; text-transform: uppercase; letter-spacing: .5px; font-weight: 600; margin-top: .1rem; }
.a-perime  { background: #f3f4f6; color: #6b7280; }
.a-critique{ background: #fef2f2; color: #ef4444; }
.a-stock   { background: #fffbeb; color: #d97706; }

.fiche-footer { background: #f9fafb; border-top: 1px solid #e5e7eb; padding: .6rem 1.5rem; display: flex; justify-content: space-between; font-size: .7rem; color: #9ca3af; }
</style>
</head>
<body>

<!-- Barre outils (non imprimée) -->
<div class="no-print">
  <?php if ($fiche_data): ?>
<?php
$all_reps = array_merge(...array_values($fiche_sections));
$nb_ok_f  = count(array_filter($all_reps, fn($r) => $r['conforme']));
$nb_nok_f = count(array_filter($all_reps, fn($r) => !$r['conforme']));
$nb_tot_f = $nb_ok_f + $nb_nok_f;
$pct_ok_f = $nb_tot_f > 0 ? round($nb_ok_f / $nb_tot_f * 100) : 0;
?>
<style>
body { background: white !important; color: #111 !important; }
.no-print { background: #fff !important; border-color: #e5e7eb !important; color: #111 !important; }
.btn-ghost { background: #f3f4f6 !important; border-color: #d1d5db !important; color: #374151 !important; }
.btn-primary { background: #2563eb !important; color: #fff !important; }

.pdf-page { max-width: 210mm; margin: 0 auto; font-family: 'Plus Jakarta Sans', sans-serif; color: #111; }

/* En-tête */
.pdf-hd { background: linear-gradient(135deg,#1e40af,#3b82f6); color:#fff; padding:.6rem 1.1rem; display:flex; align-items:center; justify-content:space-between; border-radius: 8px 8px 0 0; }
.pdf-hd-title { font-size:11pt; font-weight:800; }
.pdf-hd-sub { font-size:7.5pt; opacity:.85; margin-top:2px; }
.pdf-hd-right { text-align:right; font-size:8pt; }

/* Bande méta */
.pdf-meta { display:grid; grid-template-columns:repeat(4,1fr); background:#f9fafb; border:1px solid #e5e7eb; border-top:none; }
.pdf-mc { padding:.4rem .75rem; border-right:1px solid #e5e7eb; }
.pdf-mc:last-child { border-right:none; }
.pdf-mc-lbl { font-size:6.5pt; text-transform:uppercase; letter-spacing:.8px; color:#9ca3af; font-weight:700; margin-bottom:2px; }
.pdf-mc-val { font-size:9pt; font-weight:700; }

/* Stats */
.pdf-stats { display:grid; grid-template-columns:repeat(4,1fr); gap:6px; padding:.5rem .75rem; background:#fff; border:1px solid #e5e7eb; border-top:none; }
.pdf-stat { border-radius:6px; padding:.3rem .5rem; text-align:center; }
.pdf-stat-val { font-size:14pt; font-weight:800; line-height:1; }
.pdf-stat-lbl { font-size:6pt; font-weight:700; text-transform:uppercase; letter-spacing:.5px; margin-top:1px; }
.ps-tot { background:#f1f5f9; color:#475569; }
.ps-ok  { background:#f0fdf4; color:#16a34a; print-color-adjust:exact; -webkit-print-color-adjust:exact; }
.ps-nok { background:#fef2f2; color:#dc2626; print-color-adjust:exact; -webkit-print-color-adjust:exact; }
.ps-pct { background:#eff6ff; color:#2563eb; print-color-adjust:exact; -webkit-print-color-adjust:exact; }

/* Grille 3 colonnes */
.pdf-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:6px; padding:.5rem .75rem; border:1px solid #e5e7eb; border-top:none; background:#fff; }

/* Carte section */
.pdf-sec-card { border:1px solid #e5e7eb; border-radius:6px; overflow:hidden; break-inside:avoid; }
.pdf-sec-card.has-nok { border-color:#fca5a5; }
.pdf-sec-hd { padding:3px 7px; display:flex; align-items:center; gap:4px; background:#eff6ff; border-bottom:1px solid #e5e7eb; }
.pdf-sec-card.has-nok .pdf-sec-hd { background:#fef2f2; border-bottom-color:#fca5a5; }
.pdf-sec-title { font-size:7pt; font-weight:800; color:#1e3a5f; flex:1; line-height:1.3; }
.pdf-sec-card.has-nok .pdf-sec-title { color:#dc2626; }
.pdf-sec-badge { font-size:6pt; font-weight:700; padding:1px 5px; border-radius:10px; white-space:nowrap; background:#dcfce7; color:#16a34a; print-color-adjust:exact; -webkit-print-color-adjust:exact; }
.pdf-sec-card.has-nok .pdf-sec-badge { background:#fee2e2; color:#dc2626; }

/* Lignes points */
.pdf-pt { display:flex; align-items:baseline; gap:3px; padding:1.2px 5px; font-size:6.8pt; }
.pdf-pt.nok { background:#fff5f5; print-color-adjust:exact; -webkit-print-color-adjust:exact; }
.pdf-pt-lib { flex:1; line-height:1.35; color:#111; }
.pdf-pt-val { font-weight:700; white-space:nowrap; min-width:24px; text-align:right; }
.pdf-pt-val.ok  { color:#16a34a; }
.pdf-pt-val.nok { color:#dc2626; }
.pdf-pt-comment { font-size:6pt; color:#dc2626; font-style:italic; padding:0 5px .15rem 10px; display:block; }

/* Signature + Notes */
.pdf-bottom { display:grid; grid-template-columns:1fr 1fr; gap:8px; padding:.5rem .75rem; border:1px solid #e5e7eb; border-top:none; background:#fff; }
.pdf-sig-box { border:1px dashed #d1d5db; border-radius:5px; padding:.4rem .6rem; min-height:50px; }
.pdf-sig-lbl { font-size:6.5pt; text-transform:uppercase; letter-spacing:.8px; color:#9ca3af; font-weight:700; }
.pdf-notes { background:#fffbeb; border:1px solid #fde68a; border-radius:5px; padding:.4rem .6rem; font-size:7.5pt; color:#92400e; }

/* Footer */
.pdf-footer { background:#f9fafb; border:1px solid #e5e7eb; border-top:none; padding:.3rem .75rem; display:flex; justify-content:space-between; font-size:6.5pt; color:#9ca3af; border-radius:0 0 8px 8px; }

@media print {
  @page { size: A4 portrait; margin: .7cm .8cm; }
  .no-print { display: none !important; }
  .preview { padding: 0; max-width: 100%; }
  .pdf-page { max-width: 100% !important; }
  .pdf-hd, .ps-ok, .ps-nok, .ps-pct,
  .pdf-sec-card.has-nok .pdf-sec-hd,
  .pdf-sec-hd, .pdf-pt.nok {
    print-color-adjust: exact !important;
    -webkit-print-color-adjust: exact !important;
  }
}
</style>

<!-- Bouton flottant imprimer -->
<div class="no-print" style="position:fixed;top:12px;right:12px;z-index:999;display:flex;gap:8px">
  <a href="fiches.php?voir=<?= $fiche_id ?>" style="display:inline-flex;align-items:center;gap:6px;padding:.5rem 1rem;background:#f3f4f6;border:1px solid #d1d5db;border-radius:8px;font-size:.875rem;font-weight:600;color:#374151;text-decoration:none;cursor:pointer">
    ← Retour
  </a>
  <button onclick="window.print()" style="display:inline-flex;align-items:center;gap:6px;padding:.5rem 1.1rem;background:#2563eb;border:none;border-radius:8px;font-size:.875rem;font-weight:700;color:#fff;cursor:pointer;box-shadow:0 4px 12px rgba(37,99,235,.4)">
    🖨️ Imprimer / PDF
  </button>
</div>

<div class="pdf-page">

  <!-- En-tête -->
  <div class="pdf-hd">
    <div>
      <div class="pdf-hd-title">💊 Cadurix — Fiche de vérification</div>
      <div class="pdf-hd-sub"><?= h($fiche_data['org_nom']) ?> · <?= h($fiche_data['site_nom']) ?></div>
    </div>
    <div class="pdf-hd-right">
      <div style="font-size:10pt;font-weight:700"><?= date('d/m/Y', strtotime($fiche_data['valide_le'])) ?></div>
      <div><?= date('H:i', strtotime($fiche_data['valide_le'])) ?></div>
      <div><?= h($fiche_data['prenom'].' '.$fiche_data['user_nom']) ?></div>
    </div>
  </div>

  <!-- Méta -->
  <div class="pdf-meta">
    <div class="pdf-mc">
      <div class="pdf-mc-lbl">Fiche</div>
      <div class="pdf-mc-val"><?= h($fiche_data['modele_nom']) ?></div>
    </div>
    <div class="pdf-mc">
      <div class="pdf-mc-lbl">Mode</div>
      <div class="pdf-mc-val"><?= ucfirst($fiche_data['mode'] ?? 'complet') ?></div>
    </div>
    <div class="pdf-mc">
      <div class="pdf-mc-lbl">Points vérifiés</div>
      <div class="pdf-mc-val"><?= $nb_tot_f ?></div>
    </div>
    <div class="pdf-mc">
      <div class="pdf-mc-lbl">Statut</div>
      <div class="pdf-mc-val" style="color:<?= $fiche_data['statut']==='anomalie'?'#dc2626':'#16a34a' ?>">
        <?= $fiche_data['statut']==='anomalie' ? '⚠️ ANOMALIE' : '✅ VALIDÉE' ?>
      </div>
    </div>
  </div>

  <!-- Stats -->
  <div class="pdf-stats">
    <div class="pdf-stat ps-tot"><div class="pdf-stat-val"><?= $nb_tot_f ?></div><div class="pdf-stat-lbl">Total</div></div>
    <div class="pdf-stat ps-ok"><div class="pdf-stat-val"><?= $nb_ok_f ?></div><div class="pdf-stat-lbl">Conformes</div></div>
    <div class="pdf-stat ps-nok"><div class="pdf-stat-val"><?= $nb_nok_f ?></div><div class="pdf-stat-lbl">Anomalies</div></div>
    <div class="pdf-stat ps-pct"><div class="pdf-stat-val"><?= $pct_ok_f ?>%</div><div class="pdf-stat-lbl">Conformité</div></div>
  </div>

  <!-- Grille 3 colonnes -->
  <div class="pdf-grid">
    <?php foreach ($fiche_sections as $sec_titre => $reponses):
      $sec_nok = count(array_filter($reponses, fn($r) => !$r['conforme']));
    ?>
    <div class="pdf-sec-card <?= $sec_nok>0?'has-nok':'' ?>">
      <div class="pdf-sec-hd">
        <span class="pdf-sec-title"><?= h($sec_titre) ?></span>
        <span class="pdf-sec-badge"><?= $sec_nok>0 ? $sec_nok.' ✗' : '✓ OK' ?></span>
      </div>
      <?php foreach ($reponses as $r): ?>
      <div class="pdf-pt <?= $r['conforme']?'':'nok' ?>">
        <span class="pdf-pt-lib">
          <?= $r['bloquant']?'<span style="color:#dc2626;font-size:5.5pt">●</span> ':'' ?><?= h($r['libelle']) ?>
          <?php if($r['unite']): ?><span style="color:#9ca3af"> (<?= h($r['unite']) ?>)</span><?php endif; ?>
        </span>
        <span class="pdf-pt-val <?= $r['conforme']?'ok':'nok' ?>">
          <?php if($r['type']==='oui_non'): ?><?= $r['conforme']?'✓':'✗' ?><?php else: ?><?= h($r['valeur']??'—') ?><?php endif; ?>
        </span>
      </div>
      <?php if(!$r['conforme'] && !empty($r['commentaire'])): ?>
      <span class="pdf-pt-comment">→ <?= h($r['commentaire']) ?></span>
      <?php endif; ?>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Bas de page -->
  <div class="pdf-bottom">
    <div>
      <?php if ($fiche_data['notes']): ?>
      <div class="pdf-notes" style="margin-bottom:6px">📝 <?= h($fiche_data['notes']) ?></div>
      <?php endif; ?>
      <div class="pdf-sig-box">
        <div class="pdf-sig-lbl">Contrôlé par</div>
        <div style="font-size:8.5pt;font-weight:700;margin:3px 0"><?= h($fiche_data['prenom'].' '.$fiche_data['user_nom']) ?></div>
        <?php if ($fiche_data['signature']): ?>
        <img src="<?= h($fiche_data['signature']) ?>" style="max-height:40px;display:block;margin-top:3px">
        <?php else: ?>
        <div style="border-bottom:1px solid #d1d5db;margin-top:18px"></div>
        <?php endif; ?>
      </div>
    </div>
    <div class="pdf-sig-box">
      <div class="pdf-sig-lbl">Validé par</div>
      <div style="border-bottom:1px solid #d1d5db;margin-top:28px"></div>
      <div style="font-size:6.5pt;color:#9ca3af;margin-top:3px">Signature & Cachet</div>
    </div>
  </div>

  <!-- Footer -->
  <div class="pdf-footer">
    <span>Cadurix v5 · <?= h($fiche_data['modele_nom']) ?></span>
    <span><?= h($fiche_data['org_nom']) ?> · <?= h($fiche_data['site_nom']) ?></span>
    <span><?= date('d/m/Y à H:i', strtotime($fiche_data['valide_le'])) ?></span>
  </div>

</div>

<?php elseif (!$empl_id): ?>
<!-- Sélecteur initial -->
<div style="text-align:center;padding:4rem;color:var(--muted)">
  <div style="font-size:3rem;margin-bottom:1rem">📋</div>
  <div style="font-size:1.1rem;font-weight:600;margin-bottom:.5rem;color:var(--text)">Sélectionnez un emplacement</div>
  <div style="font-size:.875rem">Choisissez l'emplacement à contrôler dans le menu ci-dessus</div>
</div>

<?php else: ?>
<!-- ══════════════════════════════════════════════
     FICHE DE CONTRÔLE
══════════════════════════════════════════════ -->
<div class="fiche print-card">

  <!-- En-tête -->
  <div class="fiche-header">
    <div>
      <div class="fiche-logo">💊 Cadurix <span>— Fiche de contrôle</span></div>
      <div style="font-size:.85rem;margin-top:.3rem;opacity:.85">
        <?= h($site_info['org_nom']) ?> · <?= h($site_info['nom']) ?>
      </div>
    </div>
    <div class="fiche-date">
      <div style="font-size:1rem;font-weight:700"><?= date('d/m/Y', strtotime($date_ctrl)) ?></div>
      <div>Générée le <?= $date_gen ?></div>
      <div>Par <?= h(trim($_SESSION['user_nom'] ?? 'Utilisateur')) ?></div>
    </div>
  </div>

  <!-- Infos emplacement -->
  <div class="fiche-info">
    <div class="info-cell">
      <div class="info-label">Emplacement</div>
      <div class="info-val"><?= h($emplacement['nom']) ?></div>
      <?php if ($emplacement['chaine_froid']): ?>
      <div class="info-sub">❄️ Chaîne du froid <?= $emplacement['temp_min'] ? $emplacement['temp_min'].'°C → '.$emplacement['temp_max'].'°C' : '2°C → 8°C' ?></div>
      <?php endif; ?>
    </div>
    <div class="info-cell">
      <div class="info-label">Total produits</div>
      <div class="info-val"><?= count($produits) ?></div>
      <div class="info-sub">Actifs</div>
    </div>
    <div class="info-cell">
      <div class="info-label">Alertes</div>
      <div class="info-val" style="color:<?= ($nb_perime+$nb_critique)>0?'#ef4444':'#16a34a' ?>">
        <?= $nb_perime + $nb_critique ?>
      </div>
      <div class="info-sub"><?= $nb_perime ?> périmé(s) · <?= $nb_critique ?> critique(s)</div>
    </div>
    <div class="info-cell">
      <div class="info-label">Stock bas</div>
      <div class="info-val" style="color:<?= $nb_stock>0?'#d97706':'#16a34a' ?>"><?= $nb_stock ?></div>
      <div class="info-sub">Réapprovisionner</div>
    </div>
  </div>

  <div class="fiche-body">

    <!-- Résumé alertes -->
    <?php if ($nb_perime > 0 || $nb_critique > 0 || $nb_stock > 0): ?>
    <div class="alertes-grid" style="margin-bottom:1rem">
      <div class="alerte-box a-perime">
        <div class="alerte-box-val"><?= $nb_perime ?></div>
        <div class="alerte-box-lbl">⚫ Périmés</div>
      </div>
      <div class="alerte-box a-critique">
        <div class="alerte-box-val"><?= $nb_critique ?></div>
        <div class="alerte-box-lbl">🔴 Critiques ≤7j</div>
      </div>
      <div class="alerte-box a-stock">
        <div class="alerte-box-val"><?= $nb_stock ?></div>
        <div class="alerte-box-lbl">📦 Stock bas</div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Tableau produits -->
    <?php if (empty($produits)): ?>
    <div style="text-align:center;padding:2rem;color:#9ca3af">Aucun produit dans cet emplacement.</div>
    <?php else: ?>
    <table class="fiche-table">
      <thead>
        <tr>
          <th style="width:24px">✓</th>
          <th>Produit</th>
          <th>N° Lot</th>
          <th>Qté</th>
          <th>Date péremption</th>
          <th>Statut</th>
          <th>❄️</th>
          <th>Observation</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($produits as $p):
        $days = (int)$p['days_left'];
        $lbl  = match(true) { $days<0=>'Périmé '.abs($days).'j', $days===0=>"Auj.!", $days<=7=>"J-$days ⚠️", default=>"J-$days" };
        $isLow = $p['quantite_min']>0 && $p['quantite']<=$p['quantite_min'];
      ?>
      <tr>
        <td><span class="check-box"></span></td>
        <td>
          <strong><?= h($p['nom']) ?></strong>
          <?php if ($p['reference']): ?><div style="font-size:.7rem;color:#9ca3af"><?= h($p['reference']) ?></div><?php endif; ?>
          <?php if ($p['empl_nom'] !== $emplacement['nom']): ?><div style="font-size:.7rem;color:#6b7280">📁 <?= h($p['empl_nom']) ?></div><?php endif; ?>
        </td>
        <td style="font-family:monospace;font-size:.78rem"><?= h($p['numero_lot'] ?? '—') ?></td>
        <td>
          <span style="font-weight:700;color:<?= $isLow?'#d97706':'inherit' ?>"><?= $p['quantite'] ?></span>
          <span style="font-size:.75rem;color:#9ca3af"> <?= h($p['unite']) ?></span>
          <?php if ($isLow): ?><div><span class="st st-stock">📦 Bas</span></div><?php endif; ?>
        </td>
        <td style="font-family:monospace;font-weight:600;font-size:.82rem;color:<?= $p['niveau']==='critique'||$p['niveau']==='perime'?'#ef4444':($p['niveau']==='avertissement'?'#d97706':'inherit') ?>">
          <?= date('d/m/Y', strtotime($p['date_peremption'])) ?>
        </td>
        <td>
          <?php $cls = match($p['niveau']){'perime'=>'st-perime','critique'=>'st-critique','avertissement'=>'st-avert',default=>'st-ok'}; ?>
          <span class="st <?= $cls ?>"><?= $lbl ?></span>
        </td>
        <td style="text-align:center"><?= $p['chaine_froid']?'❄️':'' ?></td>
        <td style="min-width:100px">
          <!-- Ligne pour écriture manuscrite -->
          <div style="border-bottom:1px solid #e5e7eb;height:18px;margin-top:4px"></div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>

    <!-- Signature -->
    <div class="signature-section">
      <div class="sig-box">
        <div class="sig-label">Contrôlé par</div>
        <div style="font-size:.8rem;color:#374151;margin-bottom:.5rem"><?= h(trim($_SESSION['user_nom'] ?? '')) ?></div>
        <div class="sig-line"></div>
        <div style="font-size:.7rem;color:#9ca3af;margin-top:.3rem">Signature</div>
      </div>
      <div class="sig-box">
        <div class="sig-label">Validé par</div>
        <div class="sig-line" style="margin-top:1.5rem"></div>
        <div style="font-size:.7rem;color:#9ca3af;margin-top:.3rem">Signature & Cachet</div>
      </div>
    </div>

    <!-- Instructions terrain -->
    <div style="margin-top:1rem;padding:.75rem 1rem;background:#f9fafb;border-radius:.4rem;border-left:3px solid #ef4444;font-size:.78rem;color:#374151">
      <strong>Instructions :</strong> Cochez chaque produit vérifié. Retirez immédiatement tout produit périmé ou endommagé.
      Signalez tout stock bas à votre responsable. Conserver cette fiche dans le registre de l'emplacement.
    </div>
  </div>

  <div class="fiche-footer">
    <span>Cadurix v5 · Fiche de contrôle · <?= h($emplacement['nom']) ?></span>
    <span><?= h($site_info['org_nom']) ?> · <?= h($site_info['nom']) ?></span>
    <span>Contrôle du <?= date('d/m/Y', strtotime($date_ctrl)) ?></span>
  </div>
</div>
<?php endif; ?>
</div>

</body>
</html>

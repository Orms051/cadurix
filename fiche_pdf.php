<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.

/**
 * Cadurix — fiche_pdf.php
 * Page PDF autonome — aucune dépendance au thème
 */
require_once 'includes/auth.php';

$site_id  = site_actif();
$fiche_id = (int)($_GET['fiche'] ?? 0);

if (!$site_id || !$fiche_id) { header('Location: fiches.php'); exit; }

// Charger la fiche
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
$fiche = $sf->fetch(PDO::FETCH_ASSOC);

if (!$fiche) { header('Location: fiches.php'); exit; }
if (!isAdmin() && $fiche['user_id'] !== currentUserId()) { header('Location: fiches.php'); exit; }

// Charger les réponses
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

$sections = [];
$nb_ok = 0; $nb_nok = 0;
foreach ($reps->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $sections[$r['section_titre']][] = $r;
    $r['conforme'] ? $nb_ok++ : $nb_nok++;
}
$nb_tot = $nb_ok + $nb_nok;
$pct    = $nb_tot > 0 ? round($nb_ok / $nb_tot * 100) : 0;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($fiche['modele_nom']) ?> — PDF</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',sans-serif;background:#f1f5f9;color:#111;font-size:10pt}

/* Bouton flottant */
.fab{position:fixed;top:14px;right:14px;display:flex;gap:8px;z-index:999}
.fab-btn{display:inline-flex;align-items:center;gap:6px;padding:.5rem 1.1rem;border-radius:8px;font-size:.875rem;font-weight:700;cursor:pointer;border:none;text-decoration:none;font-family:inherit}
.fab-print{background:#2563eb;color:#fff;box-shadow:0 4px 14px rgba(37,99,235,.4)}
.fab-back{background:#fff;color:#374151;border:1px solid #d1d5db}

/* Page */
.page{background:#fff;max-width:210mm;margin:1rem auto;border-radius:8px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.1)}

/* En-tête */
.hd{background:linear-gradient(135deg,#1e40af,#3b82f6);color:#fff;padding:.7rem 1.1rem;display:flex;align-items:center;justify-content:space-between}
.hd-title{font-size:11pt;font-weight:800}
.hd-sub{font-size:7.5pt;opacity:.85;margin-top:2px}
.hd-right{text-align:right;font-size:8pt;line-height:1.5}

/* Méta */
.meta{display:grid;grid-template-columns:repeat(4,1fr);background:#f9fafb;border-bottom:1px solid #e5e7eb}
.mc{padding:.45rem .875rem;border-right:1px solid #e5e7eb}
.mc:last-child{border-right:none}
.mc-lbl{font-size:6pt;text-transform:uppercase;letter-spacing:.8px;color:#9ca3af;font-weight:700;margin-bottom:2px}
.mc-val{font-size:9pt;font-weight:700}

/* Stats */
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:6px;padding:.5rem .875rem;background:#fff;border-bottom:1px solid #e5e7eb}
.st{border-radius:6px;padding:.35rem .5rem;text-align:center}
.st-val{font-size:15pt;font-weight:800;line-height:1}
.st-lbl{font-size:6pt;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-top:1px}
.st-tot{background:#f1f5f9;color:#475569}
.st-ok {background:#f0fdf4;color:#16a34a}
.st-nok{background:#fef2f2;color:#dc2626}
.st-pct{background:#eff6ff;color:#2563eb}

/* Grille */
.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:7px;padding:.6rem .875rem;background:#fff;border-bottom:1px solid #e5e7eb}

/* Carte section */
.card{border:1px solid #e5e7eb;border-radius:7px;overflow:hidden}
.card.bad{border-color:#fca5a5}
.card-hd{padding:4px 8px;display:flex;align-items:center;gap:4px;background:#eff6ff;border-bottom:1px solid #e0e7ff}
.card.bad .card-hd{background:#fef2f2;border-bottom-color:#fecaca}
.card-title{font-size:7pt;font-weight:800;color:#1e3a5f;flex:1;line-height:1.3}
.card.bad .card-title{color:#dc2626}
.card-badge{font-size:6pt;font-weight:700;padding:1px 6px;border-radius:20px;white-space:nowrap;background:#dcfce7;color:#16a34a}
.card.bad .card-badge{background:#fee2e2;color:#dc2626}
.card-body{padding:.3rem .4rem}

/* Point */
.pt{display:flex;align-items:baseline;gap:3px;padding:1.5px 4px;font-size:6.8pt}
.pt.bad{background:#fff5f5}
.pt-lib{flex:1;line-height:1.35;color:#111}
.pt-blq{color:#dc2626;font-size:6pt;margin-right:2px}
.pt-unit{color:#9ca3af}
.pt-val{font-weight:700;white-space:nowrap;min-width:22px;text-align:right}
.pt-val.ok{color:#16a34a}
.pt-val.nok{color:#dc2626}
.pt-comment{font-size:6pt;color:#dc2626;font-style:italic;padding:0 4px .2rem 10px;display:block}

/* Bas */
.bottom{display:grid;grid-template-columns:1fr 1fr;gap:8px;padding:.6rem .875rem;background:#fff;border-bottom:1px solid #e5e7eb}
.sig-box{border:1px dashed #d1d5db;border-radius:5px;padding:.45rem .65rem;min-height:55px}
.sig-lbl{font-size:6.5pt;text-transform:uppercase;letter-spacing:.8px;color:#9ca3af;font-weight:700}
.sig-name{font-size:8.5pt;font-weight:700;margin:3px 0}
.notes-box{background:#fffbeb;border:1px solid #fde68a;border-radius:5px;padding:.4rem .65rem;font-size:7.5pt;color:#92400e;margin-bottom:6px}

/* Footer */
.footer{background:#f9fafb;padding:.35rem .875rem;display:flex;justify-content:space-between;font-size:6.5pt;color:#9ca3af}

/* ═══ PRINT ═══ */
@media print {
  @page{size:A4 portrait;margin:.7cm .8cm}
  body{background:#fff !important}
  .fab{display:none !important}
  .page{margin:0;box-shadow:none;border-radius:0;max-width:100%}
  .st-ok,.st-nok,.st-pct,.st-tot,
  .card-hd,.card.bad .card-hd,
  .card-badge,.card.bad .card-badge,
  .pt.bad,.notes-box{
    print-color-adjust:exact;
    -webkit-print-color-adjust:exact
  }
}
</style>
</head>
<body>

<!-- Bouton flottant -->
<div class="fab">
  <a href="fiches.php?voir=<?= $fiche_id ?>" class="fab-btn fab-back">← Retour</a>
  <button onclick="window.print()" class="fab-btn fab-print">🖨️ Imprimer / PDF</button>
</div>

<div class="page">

  <!-- En-tête -->
  <div class="hd">
    <div>
      <div class="hd-title">💊 Cadurix — Fiche de vérification</div>
      <div class="hd-sub"><?= htmlspecialchars($fiche['org_nom']) ?> · <?= htmlspecialchars($fiche['site_nom']) ?></div>
    </div>
    <div class="hd-right">
      <strong><?= date('d/m/Y', strtotime($fiche['valide_le'])) ?></strong><br>
      <?= date('H:i', strtotime($fiche['valide_le'])) ?><br>
      <?= htmlspecialchars($fiche['prenom'].' '.$fiche['user_nom']) ?>
    </div>
  </div>

  <!-- Méta -->
  <div class="meta">
    <div class="mc"><div class="mc-lbl">Fiche</div><div class="mc-val"><?= htmlspecialchars($fiche['modele_nom']) ?></div></div>
    <div class="mc"><div class="mc-lbl">Mode</div><div class="mc-val"><?= ucfirst($fiche['mode'] ?? 'complet') ?></div></div>
    <div class="mc"><div class="mc-lbl">Points</div><div class="mc-val"><?= $nb_tot ?> vérifiés</div></div>
    <div class="mc"><div class="mc-lbl">Statut</div>
      <div class="mc-val" style="color:<?= $fiche['statut']==='anomalie'?'#dc2626':'#16a34a' ?>">
        <?= $fiche['statut']==='anomalie' ? '⚠️ ANOMALIE' : '✅ VALIDÉE' ?>
      </div>
    </div>
  </div>

  <!-- Stats -->
  <div class="stats">
    <div class="st st-tot"><div class="st-val"><?= $nb_tot ?></div><div class="st-lbl">Total</div></div>
    <div class="st st-ok"><div class="st-val"><?= $nb_ok ?></div><div class="st-lbl">Conformes</div></div>
    <div class="st st-nok"><div class="st-val"><?= $nb_nok ?></div><div class="st-lbl">Anomalies</div></div>
    <div class="st st-pct"><div class="st-val"><?= $pct ?>%</div><div class="st-lbl">Conformité</div></div>
  </div>

  <!-- Grille 3 colonnes -->
  <div class="grid">
    <?php foreach ($sections as $sec_titre => $reponses):
      $sec_nok = count(array_filter($reponses, fn($r) => !$r['conforme']));
    ?>
    <div class="card <?= $sec_nok>0?'bad':'' ?>">
      <div class="card-hd">
        <span class="card-title"><?= htmlspecialchars($sec_titre) ?></span>
        <span class="card-badge"><?= $sec_nok>0 ? $sec_nok.' ✗' : '✓ OK' ?></span>
      </div>
      <div class="card-body">
        <?php foreach ($reponses as $r): ?>
        <div class="pt <?= $r['conforme']?'':'bad' ?>">
          <span class="pt-lib">
            <?php if($r['bloquant']): ?><span class="pt-blq">●</span><?php endif; ?>
            <?= htmlspecialchars($r['libelle']) ?>
            <?php if($r['unite']): ?><span class="pt-unit"> (<?= htmlspecialchars($r['unite']) ?>)</span><?php endif; ?>
          </span>
          <span class="pt-val <?= $r['conforme']?'ok':'nok' ?>">
            <?php if($r['type']==='oui_non'): ?><?= $r['conforme']?'✓':'✗' ?><?php else: ?><?= htmlspecialchars($r['valeur']??'—') ?><?php endif; ?>
          </span>
        </div>
        <?php if(!$r['conforme'] && !empty($r['commentaire'])): ?>
        <span class="pt-comment">→ <?= htmlspecialchars($r['commentaire']) ?></span>
        <?php endif; ?>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Signature + Notes -->
  <div class="bottom">
    <div>
      <?php if ($fiche['notes']): ?>
      <div class="notes-box">📝 <?= htmlspecialchars($fiche['notes']) ?></div>
      <?php endif; ?>
      <div class="sig-box">
        <div class="sig-lbl">Contrôlé par</div>
        <div class="sig-name"><?= htmlspecialchars($fiche['prenom'].' '.$fiche['user_nom']) ?></div>
        <?php if ($fiche['signature']): ?>
        <img src="<?= htmlspecialchars($fiche['signature']) ?>" style="max-height:40px;display:block;margin-top:3px">
        <?php else: ?>
        <div style="border-bottom:1px solid #d1d5db;margin-top:20px"></div>
        <?php endif; ?>
      </div>
    </div>
    <div class="sig-box">
      <div class="sig-lbl">Validé par</div>
      <div style="border-bottom:1px solid #d1d5db;margin-top:28px"></div>
      <div style="font-size:6.5pt;color:#9ca3af;margin-top:3px">Signature & Cachet</div>
    </div>
  </div>

  <!-- Footer -->
  <div class="footer">
    <span>Cadurix · <?= htmlspecialchars($fiche['modele_nom']) ?></span>
    <span><?= htmlspecialchars($fiche['org_nom']) ?> · <?= htmlspecialchars($fiche['site_nom']) ?></span>
    <span>Vérification du <?= date('d/m/Y à H:i', strtotime($fiche['valide_le'])) ?></span>
  </div>

</div>
</body>
</html>

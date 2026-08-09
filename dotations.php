<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.
require_once 'includes/auth.php';
$pageTitle = 'Dotations réglementaires';
$site_id   = site_actif();
if (!$site_id) { header('Location: dashboard.php'); exit; }
if (!canFullEdit()) { accessDenied(); exit; }

$success = $error = '';

// ── POST ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_dotation') {
        $id = (int)($_POST['id'] ?? 0);
        $d  = [
            'nom'                    => trim(substr($_POST['nom'] ?? '', 0, 150)),
            'nom_produit_reference'  => trim($_POST['nom_produit_reference'] ?? '') ?: null,
            'categorie'              => in_array($_POST['categorie']??'', ['medicament','materiel','consommable','sse','autre']) ? $_POST['categorie'] : 'medicament',
            'quantite_min'           => max(0, (float)($_POST['quantite_min'] ?? 0)),
            'unite'                  => trim($_POST['unite'] ?? 'unité'),
            'reference_reglementaire'=> trim($_POST['reference_reglementaire'] ?? ''),
            'notes'                  => trim($_POST['notes'] ?? ''),
            'actif'                  => 1,
        ];
        if (!$d['nom']) {
            $error = 'Le nom est requis.';
        } else {
            if ($id > 0) {
                $sets = implode(',', array_map(fn($k) => "$k=?", array_keys($d)));
                $pdo->prepare("UPDATE dotations_reglementaires SET $sets WHERE id=? AND site_id=?")
                    ->execute([...array_values($d), $id, $site_id]);
                $success = 'Dotation mise à jour.';
            } else {
                $keys = implode(',', array_keys($d)) . ',site_id';
                $vals = implode(',', array_fill(0, count($d), '?')) . ',?';
                $pdo->prepare("INSERT INTO dotations_reglementaires ($keys) VALUES ($vals)")
                    ->execute([...array_values($d), $site_id]);
                $success = 'Dotation créée.';
            }
        }
    } elseif ($action === 'toggle_actif') {
        $pdo->prepare("UPDATE dotations_reglementaires SET actif = NOT actif WHERE id=? AND site_id=?")
            ->execute([(int)$_POST['id'], $site_id]);
    } elseif ($action === 'delete') {
        $pdo->prepare("DELETE FROM dotations_reglementaires WHERE id=? AND site_id=?")
            ->execute([(int)$_POST['id'], $site_id]);
        $success = 'Dotation supprimée.';
    }
}

// ── Récupérer dotations + stock actuel ─────────────────────
// Stock selon catégorie :
// medicament/consommable → table consommables (sse aussi)
// materiel/sse matériel  → table materiels
$dotations = $pdo->prepare("
    SELECT d.*,
        COALESCE((
            SELECT SUM(sc.quantite)
            FROM sse_consommables sc
            WHERE sc.site_id = :s1
              AND d.categorie IN ('sse','consommable')
              AND (
                sc.nom LIKE CONCAT('%', COALESCE(d.nom_produit_reference, d.nom), '%')
                OR d.nom LIKE CONCAT('%', sc.nom, '%')
              )
        ), 0) +
        COALESCE((
            SELECT SUM(p.quantite)
            FROM produits p
            JOIN emplacements e ON e.id = p.emplacement_id
            WHERE e.site_id = :s2
              AND d.categorie IN ('medicament','consommable')
              AND (
                p.nom LIKE CONCAT('%', COALESCE(d.nom_produit_reference, d.nom), '%')
                OR d.nom LIKE CONCAT('%', p.nom, '%')
              )
        ), 0) +
        COALESCE((
            SELECT COUNT(*)
            FROM materiels m
            WHERE m.site_id = :s3
              AND m.statut NOT IN ('hors_service','reforme')
              AND d.categorie IN ('materiel','autre')
              AND (
                m.nom LIKE CONCAT('%', COALESCE(d.nom_produit_reference, d.nom), '%')
                OR d.nom LIKE CONCAT('%', m.nom, '%')
              )
        ), 0) AS stock_actuel
    FROM dotations_reglementaires d
    WHERE d.site_id = :s4
    ORDER BY d.actif DESC, d.categorie, d.nom
");
$dotations->execute([':s1' => $site_id, ':s2' => $site_id, ':s3' => $site_id, ':s4' => $site_id]);
$dotations = $dotations->fetchAll(PDO::FETCH_ASSOC);

// Stats
$total = count($dotations);
$conformes = 0; $non_conformes = 0; $inactives = 0;
foreach ($dotations as $d) {
    if (!$d['actif']) { $inactives++; continue; }
    if ($d['stock_actuel'] >= $d['quantite_min']) $conformes++;
    else $non_conformes++;
}

// Catégories dispo
$cats = [
    'medicament'  => ['label' => 'Médicament',   'icon' => '💊', 'color' => '#ef4444'],
    'materiel'    => ['label' => 'Matériel',      'icon' => '🩺', 'color' => '#3b82f6'],
    'consommable' => ['label' => 'Consommable',   'icon' => '📦', 'color' => '#f59e0b'],
    'sse'         => ['label' => 'SSE / NRBC',    'icon' => '🧪', 'color' => '#8b5cf6'],
    'autre'       => ['label' => 'Autre',         'icon' => '📋', 'color' => '#64748b'],
];

// Édition
$edit = null;
if (isset($_GET['edit'])) {
    $es = $pdo->prepare("SELECT * FROM dotations_reglementaires WHERE id=? AND site_id=?");
    $es->execute([(int)$_GET['edit'], $site_id]);
    $edit = $es->fetch(PDO::FETCH_ASSOC);
}

// Filtre catégorie
$filtre_cat = $_GET['cat'] ?? '';
$filtre_statut = $_GET['statut'] ?? 'actif';

require_once 'includes/header.php';
?>
<style>
.dot-grid    { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:24px; }
.dot-stat    { background:var(--bg2); border:1px solid var(--border); border-radius:12px; padding:16px; text-align:center; }
.dot-stat-n  { font-size:2rem; font-weight:800; line-height:1.1; }
.dot-stat-l  { font-size:.72rem; text-transform:uppercase; letter-spacing:.06em; color:var(--text3); margin-top:4px; }

.dot-filters { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:16px; }
.dot-filter  { padding:5px 14px; border-radius:20px; font-size:.78rem; font-weight:600; border:1px solid var(--border); background:var(--glass); color:var(--text2); cursor:pointer; text-decoration:none; transition:.15s; }
.dot-filter:hover, .dot-filter.on { background:var(--primary); border-color:var(--primary); color:white; }

.dot-table   { width:100%; border-collapse:collapse; background:var(--bg2); border-radius:12px; overflow:hidden; border:1px solid var(--border); }
.dot-table th { padding:10px 14px; font-size:.7rem; color:var(--text3); text-transform:uppercase; font-weight:700; letter-spacing:.05em; border-bottom:1px solid var(--border); text-align:left; white-space:nowrap; }
.dot-table td { padding:10px 14px; border-bottom:1px solid var(--border); vertical-align:middle; }
.dot-table tr:last-child td { border-bottom:none; }
.dot-table tr:hover td { background:var(--glass); }
.dot-table tr.inactive td { opacity:.5; }

.conf-bar    { height:5px; border-radius:3px; background:var(--border); width:80px; margin-top:4px; overflow:hidden; }
.conf-fill   { height:100%; border-radius:3px; transition:width .3s; }

.cat-pill    { display:inline-flex; align-items:center; gap:4px; padding:2px 9px; border-radius:20px; font-size:.72rem; font-weight:600; }

/* Modal */
.dot-modal   { display:none; position:fixed; inset:0; background:rgba(0,0,0,.65); z-index:9999; align-items:center; justify-content:center; }
.dot-modal.on { display:flex; }
.dot-modal-box { background:var(--bg2); border:1px solid var(--border2); border-radius:16px; padding:28px; width:90%; max-width:580px; max-height:90vh; overflow-y:auto; box-shadow:0 24px 60px rgba(0,0,0,.5); }
.dot-form-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.dot-form-full { grid-column:1/-1; }
.dot-label   { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--text3); display:block; margin-bottom:5px; }
.dot-input   { width:100%; background:var(--bg); border:1px solid var(--border); color:var(--text); border-radius:8px; padding:8px 12px; font-family:inherit; font-size:.88rem; transition:border-color .15s; box-sizing:border-box; }
.dot-input:focus { outline:none; border-color:var(--primary); }
select.dot-input option { background:var(--bg2); }
</style>

<!-- Header -->
<div class="page-header" style="margin-bottom:20px">
  <div class="page-title-group">
    <div class="page-title">📋 Dotations réglementaires</div>
    <div class="page-sub">Seuils minimaux réglementaires — <?= h($site_actif_nom ?? 'Site actif') ?></div>
  </div>
  <div class="page-actions">
    <button onclick="openModal()" class="btn btn-primary">+ Nouvelle dotation</button>
  </div>
</div>

<?php if ($success): ?><div class="flash flash-success" style="margin-bottom:16px">✅ <?= h($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="flash flash-error"   style="margin-bottom:16px">⚠️ <?= h($error) ?></div><?php endif; ?>

<!-- Stats -->
<div class="dot-grid">
  <div class="dot-stat">
    <div class="dot-stat-n"><?= $total ?></div>
    <div class="dot-stat-l">Dotations</div>
  </div>
  <div class="dot-stat" style="background:rgba(16,185,129,.06);border-color:rgba(16,185,129,.25)">
    <div class="dot-stat-n" style="color:#34d399"><?= $conformes ?></div>
    <div class="dot-stat-l">✅ Conformes</div>
  </div>
  <div class="dot-stat" style="background:<?= $non_conformes>0?'rgba(239,68,68,.06)':'var(--bg2)' ?>;border-color:<?= $non_conformes>0?'rgba(239,68,68,.25)':'var(--border)' ?>">
    <div class="dot-stat-n" style="color:<?= $non_conformes>0?'#f87171':'var(--text)' ?>"><?= $non_conformes ?></div>
    <div class="dot-stat-l">❌ Non conformes</div>
  </div>
  <div class="dot-stat">
    <?php
    $taux = ($total - $inactives) > 0 ? round($conformes / ($total - $inactives) * 100) : 100;
    $taux_color = $taux >= 90 ? '#34d399' : ($taux >= 70 ? '#f59e0b' : '#f87171');
    ?>
    <div class="dot-stat-n" style="color:<?= $taux_color ?>"><?= $taux ?>%</div>
    <div class="dot-stat-l">Taux conformité</div>
  </div>
</div>

<!-- Filtres -->
<div class="dot-filters">
  <span style="font-size:.78rem;color:var(--text3);font-weight:600">Catégorie :</span>
  <a href="?" class="dot-filter <?= !$filtre_cat?'on':'' ?>">Toutes</a>
  <?php foreach ($cats as $ck => $cv): ?>
  <a href="?cat=<?= $ck ?>" class="dot-filter <?= $filtre_cat===$ck?'on':'' ?>"><?= $cv['icon'] ?> <?= $cv['label'] ?></a>
  <?php endforeach; ?>
  <span style="margin-left:auto;font-size:.78rem;color:var(--text3)">
    <a href="?statut=actif<?= $filtre_cat?"&cat=$filtre_cat":'' ?>" style="color:<?= $filtre_statut==='actif'?'var(--primary)':'var(--text3)' ?>;text-decoration:none;font-weight:600">Actives</a>
    · <a href="?statut=tous<?= $filtre_cat?"&cat=$filtre_cat":'' ?>" style="color:<?= $filtre_statut==='tous'?'var(--primary)':'var(--text3)' ?>;text-decoration:none;font-weight:600">Toutes</a>
  </span>
</div>

<?php
// Filtrer
$rows = array_filter($dotations, function($d) use ($filtre_cat, $filtre_statut) {
    if ($filtre_cat && $d['categorie'] !== $filtre_cat) return false;
    if ($filtre_statut === 'actif' && !$d['actif']) return false;
    return true;
});
?>

<?php if (empty($rows)): ?>
<div style="text-align:center;padding:3rem;background:var(--bg2);border:1px solid var(--border);border-radius:12px;color:var(--text3)">
  <div style="font-size:2.5rem;margin-bottom:12px">📋</div>
  <div style="font-weight:700;margin-bottom:6px">Aucune dotation</div>
  <div style="font-size:.85rem">Cliquez sur "+ Nouvelle dotation" pour commencer.</div>
</div>
<?php else: ?>
<table class="dot-table">
  <thead>
    <tr>
      <th>Dotation</th>
      <th>Catégorie</th>
      <th>Seuil min.</th>
      <th>Stock actuel</th>
      <th>Conformité</th>
      <th>Référence</th>
      <th style="width:90px"></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($rows as $dot):
    $conforme = $dot['stock_actuel'] >= $dot['quantite_min'];
    $pct = $dot['quantite_min'] > 0 ? min(100, round($dot['stock_actuel'] / $dot['quantite_min'] * 100)) : 100;
    $cat = $cats[$dot['categorie']] ?? ['label'=>$dot['categorie'],'icon'=>'📋','color'=>'#64748b'];
  ?>
  <tr class="<?= !$dot['actif']?'inactive':'' ?>">
    <td>
      <div style="font-weight:600;font-size:.88rem"><?= h($dot['nom']) ?></div>
      <?php if ($dot['nom_produit_reference']): ?>
      <div style="font-size:.7rem;color:var(--text3)">🔗 <?= h($dot['nom_produit_reference']) ?></div>
      <?php endif; ?>
      <?php if ($dot['notes']): ?>
      <div style="font-size:.72rem;color:var(--text3);margin-top:2px"><?= h($dot['notes']) ?></div>
      <?php endif; ?>
    </td>
    <td>
      <span class="cat-pill" style="background:<?= $cat['color'] ?>18;color:<?= $cat['color'] ?>;border:1px solid <?= $cat['color'] ?>33">
        <?= $cat['icon'] ?> <?= $cat['label'] ?>
      </span>
    </td>
    <td style="font-weight:700;font-size:.92rem">
      <?= number_format($dot['quantite_min'], 0) ?>
      <span style="font-size:.72rem;color:var(--text3);font-weight:400"> <?= h($dot['unite']) ?></span>
    </td>
    <td>
      <div style="font-weight:700;font-size:.92rem;color:<?= $conforme?'#34d399':'#f87171' ?>"><?= $dot['stock_actuel'] ?></div>
      <div class="conf-bar"><div class="conf-fill" style="width:<?= $pct ?>%;background:<?= $conforme?'#34d399':'#ef4444' ?>"></div></div>
      <div style="font-size:.68rem;color:var(--text3);margin-top:2px"><?= $pct ?>% du seuil</div>
    </td>
    <td>
      <?php if (!$dot['actif']): ?>
      <span style="padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:600;background:var(--glass);color:var(--text3);border:1px solid var(--border)">⏸ Inactif</span>
      <?php elseif ($conforme): ?>
      <span style="padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:600;background:rgba(16,185,129,.12);color:#34d399;border:1px solid rgba(16,185,129,.25)">✅ Conforme</span>
      <?php else: ?>
      <span style="padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:600;background:rgba(239,68,68,.12);color:#f87171;border:1px solid rgba(239,68,68,.25)">❌ Non conforme</span>
      <?php endif; ?>
    </td>
    <td style="font-size:.75rem;color:var(--text3)"><?= h($dot['reference_reglementaire'] ?: '—') ?></td>
    <td>
      <div style="display:flex;gap:4px;justify-content:flex-end">
        <button onclick="editModal(<?= htmlspecialchars(json_encode($dot), ENT_QUOTES) ?>)" class="btn btn-ghost btn-xs" title="Modifier">✏️</button>
        <form method="post" style="display:contents">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="toggle_actif">
          <input type="hidden" name="id" value="<?= $dot['id'] ?>">
          <button type="submit" class="btn btn-ghost btn-xs" title="<?= $dot['actif']?'Désactiver':'Activer' ?>"><?= $dot['actif']?'⏸':'▶️' ?></button>
        </form>
        <form method="post" style="display:contents" onsubmit="return confirm('Supprimer cette dotation ?')">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= $dot['id'] ?>">
          <button type="submit" class="btn btn-ghost btn-xs" style="color:#f87171" title="Supprimer">🗑️</button>
        </form>
      </div>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<!-- Modal ajout/édition -->
<div class="dot-modal <?= $edit?'on':'' ?>" id="dot-modal" onclick="if(event.target===this)closeModal()">
  <div class="dot-modal-box">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px">
      <div>
        <div style="font-size:1.05rem;font-weight:700" id="modal-title">📋 Nouvelle dotation</div>
        <div style="font-size:.78rem;color:var(--text3)">Définir un seuil minimal réglementaire</div>
      </div>
      <button onclick="closeModal()" style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--text2)">✕</button>
    </div>
    <form method="post" id="dot-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_dotation">
      <input type="hidden" name="id" id="f-id" value="0">
      <div class="dot-form-grid">
        <div class="dot-form-full">
          <label class="dot-label">Nom de la dotation *</label>
          <input type="text" name="nom" id="f-nom" required class="dot-input" placeholder="Ex: Adrénaline 1mg/1ml">
        </div>
        <div>
          <label class="dot-label">Catégorie</label>
          <select name="categorie" id="f-cat" class="dot-input">
            <?php foreach ($cats as $ck => $cv): ?>
            <option value="<?= $ck ?>"><?= $cv['icon'] ?> <?= $cv['label'] ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="dot-label">Référence produit (pour le stock)</label>
          <input type="text" name="nom_produit_reference" id="f-ref" class="dot-input" placeholder="Nom exact dans l'inventaire">
        </div>
        <div>
          <label class="dot-label">Quantité minimale *</label>
          <input type="number" name="quantite_min" id="f-qmin" min="0" step="0.5" value="1" class="dot-input" required>
        </div>
        <div>
          <label class="dot-label">Unité</label>
          <input type="text" name="unite" id="f-unite" value="unité" class="dot-input" placeholder="unité, mg, ml…">
        </div>
        <div class="dot-form-full">
          <label class="dot-label">Référence réglementaire</label>
          <input type="text" name="reference_reglementaire" id="f-refreg" class="dot-input" placeholder="Ex: Arrêté du 25/03/2015, Art. R.1424-42">
        </div>
        <div class="dot-form-full">
          <label class="dot-label">Notes</label>
          <textarea name="notes" id="f-notes" rows="2" class="dot-input" style="resize:vertical"></textarea>
        </div>
      </div>
      <div style="display:flex;gap:10px;margin-top:20px;padding-top:16px;border-top:1px solid var(--border)">
        <button type="submit" class="btn btn-primary">💾 Enregistrer</button>
        <button type="button" onclick="closeModal()" class="btn btn-ghost">Annuler</button>
      </div>
    </form>
  </div>
</div>

<script>
function openModal(data) {
  document.getElementById('modal-title').textContent = '📋 Nouvelle dotation';
  document.getElementById('f-id').value    = '0';
  document.getElementById('f-nom').value   = '';
  document.getElementById('f-cat').value   = 'medicament';
  document.getElementById('f-ref').value   = '';
  document.getElementById('f-qmin').value  = '1';
  document.getElementById('f-unite').value = 'unité';
  document.getElementById('f-refreg').value= '';
  document.getElementById('f-notes').value = '';
  document.getElementById('dot-modal').classList.add('on');
}
function editModal(d) {
  document.getElementById('modal-title').textContent = '✏️ Modifier : ' + d.nom;
  document.getElementById('f-id').value    = d.id;
  document.getElementById('f-nom').value   = d.nom;
  document.getElementById('f-cat').value   = d.categorie;
  document.getElementById('f-ref').value   = d.nom_produit_reference || '';
  document.getElementById('f-qmin').value  = d.quantite_min;
  document.getElementById('f-unite').value = d.unite;
  document.getElementById('f-refreg').value= d.reference_reglementaire || '';
  document.getElementById('f-notes').value = d.notes || '';
  document.getElementById('dot-modal').classList.add('on');
}
function closeModal() {
  document.getElementById('dot-modal').classList.remove('on');
  // Retirer ?edit= de l'URL si présent
  if (window.location.search.includes('edit=')) history.replaceState(null,'', window.location.pathname);
}
<?php if ($edit): ?>
editModal(<?= json_encode($edit) ?>);
<?php endif; ?>
</script>

<?php require_once 'includes/footer.php'; ?>

<?php
/**
 * Cadurix v5.2 — Base de référence médicaments SDIS
 */
if (!defined('ADMIN_HUB')) require_once '../includes/auth.php';
if (!isAdmin()) { accessDenied(); }

$pageTitle = 'Base médicaments';

// ── Actions POST ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id     = (int)($_POST['id'] ?? 0);
        $nom    = trim($_POST['nom'] ?? '');
        $dci    = trim($_POST['dci'] ?? '');
        $forme  = trim($_POST['forme'] ?? '');
        $dosage = trim($_POST['dosage'] ?? '');
        $unite  = trim($_POST['unite'] ?? 'unité');
        $cat    = trim($_POST['categorie'] ?? '');
        $froid  = isset($_POST['froid']) ? 1 : 0;
        $tmin   = $_POST['temp_min'] !== '' ? (float)$_POST['temp_min'] : null;
        $tmax   = $_POST['temp_max'] !== '' ? (float)$_POST['temp_max'] : null;
        $alerte = (int)($_POST['alerte_jours'] ?? 30);

        if (!$nom) { flashError('Le nom est obligatoire.'); header('Location: medicaments_ref.php'); exit; }

        if ($id) {
            $pdo->prepare("UPDATE medicaments_ref SET nom=?,dci=?,forme=?,dosage=?,unite=?,categorie=?,froid=?,temp_min=?,temp_max=?,alerte_jours=? WHERE id=?")
                ->execute([$nom,$dci,$forme,$dosage,$unite,$cat,$froid,$tmin,$tmax,$alerte,$id]);
            flashSuccess("Médicament modifié.");
        } else {
            $pdo->prepare("INSERT INTO medicaments_ref (nom,dci,forme,dosage,unite,categorie,froid,temp_min,temp_max,alerte_jours) VALUES(?,?,?,?,?,?,?,?,?,?)")
                ->execute([$nom,$dci,$forme,$dosage,$unite,$cat,$froid,$tmin,$tmax,$alerte]);
            flashSuccess("Médicament ajouté à la base.");
        }
        header('Location: medicaments_ref.php'); exit;
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE medicaments_ref SET actif = 1 - actif WHERE id=?")->execute([$id]);
        header('Location: medicaments_ref.php'); exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM medicaments_ref WHERE id=?")->execute([$id]);
        flashSuccess("Médicament supprimé.");
        header('Location: medicaments_ref.php'); exit;
    }
}

// ── Chargement ────────────────────────────────────────────
$search  = trim($_GET['q'] ?? '');
$cat_f   = trim($_GET['cat'] ?? '');
$edit_id = (int)($_GET['edit'] ?? 0);
$editing = null;

if ($edit_id) {
    $e = $pdo->prepare("SELECT * FROM medicaments_ref WHERE id=?");
    $e->execute([$edit_id]);
    $editing = $e->fetch(PDO::FETCH_ASSOC);
}

// Catégories disponibles
$cats = $pdo->query("SELECT DISTINCT categorie FROM medicaments_ref WHERE categorie IS NOT NULL ORDER BY categorie")->fetchAll(PDO::FETCH_COLUMN);

// Stats
$stats = $pdo->query("
    SELECT
        COUNT(*) as total,
        SUM(actif) as actifs,
        SUM(froid) as froid,
        COUNT(DISTINCT categorie) as nb_cats
    FROM medicaments_ref
")->fetch(PDO::FETCH_ASSOC);

// Liste
$where = ['1=1'];
$params = [];
if ($search) { $where[] = '(nom LIKE ? OR dci LIKE ? OR dosage LIKE ?)'; $params = array_merge($params, ["%$search%","%$search%","%$search%"]); }
if ($cat_f)  { $where[] = 'categorie = ?'; $params[] = $cat_f; }

$medocs = $pdo->prepare("SELECT * FROM medicaments_ref WHERE " . implode(' AND ', $where) . " ORDER BY categorie, nom");
$medocs->execute($params);
$medocs = $medocs->fetchAll(PDO::FETCH_ASSOC);

// Grouper par catégorie
$grouped = [];
foreach ($medocs as $m) {
    $grouped[$m['categorie'] ?? 'Autre'][] = $m;
}

if (!defined('ADMIN_HUB')) require_once '../includes/header.php';
?>

<style>
.ref-card { background:var(--bg2); border:1px solid var(--border); border-radius:10px; overflow:hidden; margin-bottom:14px; }
.ref-cat-header { display:flex; align-items:center; justify-content:space-between; padding:10px 16px; background:var(--bg3); border-bottom:1px solid var(--border); cursor:pointer; user-select:none; }
.ref-cat-title { font-size:12px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:var(--text2); }
.ref-table { width:100%; border-collapse:collapse; }
.ref-table th { padding:7px 14px; font-size:10px; font-weight:600; letter-spacing:.06em; text-transform:uppercase; color:var(--text3); border-bottom:1px solid var(--border); text-align:left; background:var(--bg3); }
.ref-table td { padding:9px 14px; border-bottom:1px solid var(--border); font-size:13px; vertical-align:middle; }
.ref-table tr:last-child td { border-bottom:none; }
.ref-table tr:hover td { background:var(--glass); }
.ref-table tr.inactive td { opacity:.45; }
.form-panel { background:var(--bg2); border:1px solid var(--border); border-radius:12px; padding:20px; margin-bottom:20px; }
</style>

<div class="page-header">
  <div class="page-title-group">
    <div class="page-title">💊 Base médicaments de référence</div>
    <div class="page-sub"><?= $stats['actifs'] ?> médicaments actifs · <?= $stats['nb_cats'] ?> catégories · <?= $stats['froid'] ?> chaîne du froid</div>
  </div>
  <button class="btn btn-primary" onclick="toggleForm()">
    <i class="ti ti-plus"></i> Ajouter
  </button>
</div>

<!-- KPIs -->
<style>
.akpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px}
@media(max-width:900px){.akpi-grid{grid-template-columns:1fr 1fr}}
.akpi{position:relative;display:flex;align-items:center;gap:12px;background:var(--bg2);border:1px solid var(--border);border-radius:13px;padding:12px 15px;overflow:hidden;box-shadow:0 1px 2px rgba(20,30,60,.04)}
.akpi::before{content:"";position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--kc,var(--primary))}
.akpi .ic{width:36px;height:36px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:1.05rem}
.akpi .n{font-size:1.5rem;font-weight:800;line-height:1;font-variant-numeric:tabular-nums}
.akpi .l{font-size:.68rem;color:var(--text3);text-transform:uppercase;letter-spacing:.04em;font-weight:700;margin-top:3px}
.akpi .f{font-size:.68rem;color:var(--text3);margin-top:1px}
</style>
<div class="akpi-grid">
  <div class="akpi" style="--kc:#3b82f6"><span class="ic" style="background:#3b82f61f;color:#3b82f6">💊</span><div><div class="n" style="color:#3b82f6"><?= $stats['total'] ?></div><div class="l">Total</div><div class="f">médicaments référencés</div></div></div>
  <div class="akpi" style="--kc:#10b981"><span class="ic" style="background:#10b9811f;color:#10b981">✅</span><div><div class="n" style="color:#10b981"><?= $stats['actifs'] ?></div><div class="l">Actifs</div><div class="f">dans l'autocomplétion</div></div></div>
  <div class="akpi" style="--kc:#60a5fa"><span class="ic" style="background:#60a5fa1f;color:#60a5fa">❄️</span><div><div class="n" style="color:#60a5fa"><?= $stats['froid'] ?></div><div class="l">Chaîne du froid</div><div class="f">réfrigération</div></div></div>
  <div class="akpi" style="--kc:#8b5cf6"><span class="ic" style="background:#8b5cf61f;color:#8b5cf6">📂</span><div><div class="n" style="color:#8b5cf6"><?= $stats['nb_cats'] ?></div><div class="l">Catégories</div><div class="f">familles thérapeutiques</div></div></div>
</div>

<!-- Formulaire -->
<div id="form-panel" style="display:<?= $editing ? 'block' : 'none' ?>;margin-bottom:20px">
  <div class="form-panel">
    <div class="card-title" style="margin-bottom:16px"><?= $editing ? '✏️ Modifier' : '➕ Nouveau médicament de référence' ?></div>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <?php if ($editing): ?><input type="hidden" name="id" value="<?= $editing['id'] ?>"><?php endif; ?>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Nom *</label>
          <input type="text" name="nom" class="form-control" required
                 value="<?= h($editing['nom'] ?? '') ?>" placeholder="Adrénaline 1mg/1ml">
        </div>
        <div class="form-group">
          <label class="form-label">DCI</label>
          <input type="text" name="dci" class="form-control"
                 value="<?= h($editing['dci'] ?? '') ?>" placeholder="Adrénaline (Épinéphrine)">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Forme pharmaceutique</label>
          <input type="text" name="forme" class="form-control"
                 value="<?= h($editing['forme'] ?? '') ?>" placeholder="Solution injectable">
        </div>
        <div class="form-group">
          <label class="form-label">Dosage</label>
          <input type="text" name="dosage" class="form-control"
                 value="<?= h($editing['dosage'] ?? '') ?>" placeholder="1mg/1ml">
        </div>
      </div>
      <div class="form-row3">
        <div class="form-group">
          <label class="form-label">Unité</label>
          <select name="unite" class="form-control">
            <?php foreach (['ampoule','flacon','comprimé','gélule','seringue','poche','unité','sachet','tube','kit','auto-injecteur','spray','flacon spray','paire'] as $u): ?>
            <option value="<?= $u ?>" <?= ($editing['unite'] ?? 'ampoule') === $u ? 'selected' : '' ?>><?= $u ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Catégorie</label>
          <input type="text" name="categorie" class="form-control" list="cats-list"
                 value="<?= h($editing['categorie'] ?? '') ?>" placeholder="Urgence cardiaque">
          <datalist id="cats-list">
            <?php foreach ($cats as $c): ?><option value="<?= h($c) ?>"><?php endforeach; ?>
          </datalist>
        </div>
        <div class="form-group">
          <label class="form-label">Alerte péremption (jours)</label>
          <input type="number" name="alerte_jours" class="form-control" min="1" max="365"
                 value="<?= $editing['alerte_jours'] ?? 30 ?>">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-top:24px">
            <input type="checkbox" name="froid" <?= ($editing['froid'] ?? 0) ? 'checked' : '' ?>
                   style="accent-color:var(--primary);width:16px;height:16px">
            <span>❄️ Chaîne du froid requise</span>
          </label>
        </div>
        <div class="form-group">
          <label class="form-label">Température stockage</label>
          <div style="display:flex;gap:8px;align-items:center">
            <input type="number" name="temp_min" class="form-control" placeholder="Min °C" step="0.5"
                   value="<?= $editing['temp_min'] ?? '' ?>" style="width:100px">
            <span style="color:var(--text3)">→</span>
            <input type="number" name="temp_max" class="form-control" placeholder="Max °C" step="0.5"
                   value="<?= $editing['temp_max'] ?? '' ?>" style="width:100px">
          </div>
        </div>
      </div>
      <div style="display:flex;gap:8px;margin-top:8px">
        <button type="submit" class="btn btn-primary">
          <i class="ti ti-device-floppy"></i> <?= $editing ? 'Modifier' : 'Ajouter' ?>
        </button>
        <a href="medicaments_ref.php" class="btn btn-ghost">Annuler</a>
      </div>
    </form>
  </div>
</div>

<!-- Filtres -->
<div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;align-items:center">
  <form method="GET" style="display:flex;gap:8px;flex:1;flex-wrap:wrap">
    <div style="display:flex;align-items:center;gap:6px;background:var(--bg2);border:1px solid var(--border);border-radius:8px;padding:6px 10px;flex:1;min-width:200px">
      <i class="ti ti-search" style="font-size:14px;color:var(--text3)"></i>
      <input type="text" name="q" value="<?= h($search) ?>" placeholder="Rechercher un médicament, DCI…"
             style="background:none;border:none;outline:none;font-size:13px;color:var(--text);width:100%">
    </div>
    <select name="cat" class="form-control" style="width:auto">
      <option value="">Toutes les catégories</option>
      <?php foreach ($cats as $c): ?>
      <option value="<?= h($c) ?>" <?= $cat_f === $c ? 'selected' : '' ?>><?= h($c) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-ghost">Filtrer</button>
    <?php if ($search || $cat_f): ?>
    <a href="medicaments_ref.php" class="btn btn-ghost">✕ Effacer</a>
    <?php endif; ?>
  </form>
  <div style="font-size:.8rem;color:var(--text2)"><?= count($medocs) ?> résultat(s)</div>
</div>

<!-- Liste groupée par catégorie -->
<?php if (empty($grouped)): ?>
<div class="card" style="text-align:center;padding:40px;color:var(--text2)">
  <div style="font-size:36px;margin-bottom:10px">💊</div>
  Aucun médicament trouvé.
</div>
<?php else: ?>
<?php foreach ($grouped as $cat => $items): ?>
<div class="ref-card">
  <div class="ref-cat-header" onclick="toggleCat('cat-<?= md5($cat) ?>')">
    <div style="display:flex;align-items:center;gap:10px">
      <span class="ref-cat-title"><?= h($cat) ?></span>
      <span style="font-size:11px;padding:2px 8px;border-radius:99px;background:var(--primary-bg);color:var(--primary)"><?= count($items) ?></span>
      <?php $nb_froid = count(array_filter($items, fn($i) => $i['froid'])); ?>
      <?php if ($nb_froid): ?><span style="font-size:11px;color:#60a5fa">❄️ <?= $nb_froid ?></span><?php endif; ?>
    </div>
    <i class="ti ti-chevron-down" id="chev-<?= md5($cat) ?>" style="font-size:13px;color:var(--text3);transition:transform .2s"></i>
  </div>
  <div id="cat-<?= md5($cat) ?>">
    <table class="ref-table">
      <thead>
        <tr>
          <th>Nom</th>
          <th>DCI</th>
          <th>Forme / Dosage</th>
          <th>Unité</th>
          <th>Alerte</th>
          <th>Options</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($items as $m): ?>
      <tr class="<?= $m['actif'] ? '' : 'inactive' ?>">
        <td>
          <div style="font-weight:600"><?= h($m['nom']) ?></div>
          <?php if ($m['froid']): ?><span style="font-size:10px;color:#60a5fa">❄️ <?= $m['temp_min'] ?>°→<?= $m['temp_max'] ?>°C</span><?php endif; ?>
        </td>
        <td style="font-size:12px;color:var(--text2)"><?= h($m['dci'] ?? '—') ?></td>
        <td style="font-size:12px;color:var(--text2)"><?= h($m['forme'] ?? '') ?><?= $m['dosage'] ? ' · '.$m['dosage'] : '' ?></td>
        <td><span class="badge badge-blue" style="font-size:10px"><?= h($m['unite']) ?></span></td>
        <td style="font-size:12px;color:var(--text2)"><?= $m['alerte_jours'] ?>j</td>
        <td>
          <?php if (!$m['actif']): ?>
          <span class="badge badge-expired" style="font-size:10px">Inactif</span>
          <?php else: ?>
          <span class="badge badge-ok" style="font-size:10px">Actif</span>
          <?php endif; ?>
        </td>
        <td>
          <div style="display:flex;gap:4px">
            <a href="medicaments_ref.php?edit=<?= $m['id'] ?>" class="btn btn-ghost btn-xs" title="Modifier">
              <i class="ti ti-edit" style="font-size:12px"></i>
            </a>
            <form method="POST" style="display:inline">
              <?= csrf_field() ?><input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= $m['id'] ?>">
              <button class="btn btn-ghost btn-xs" title="<?= $m['actif'] ? 'Désactiver' : 'Activer' ?>">
                <i class="ti <?= $m['actif'] ? 'ti-eye-off' : 'ti-eye' ?>" style="font-size:12px"></i>
              </button>
            </form>
            <form method="POST" style="display:inline" onsubmit="return confirm('Supprimer ce médicament de la base ?')">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= $m['id'] ?>">
              <button class="btn btn-danger btn-xs" title="Supprimer">
                <i class="ti ti-trash" style="font-size:12px"></i>
              </button>
            </form>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<script>
function toggleForm() {
  const p = document.getElementById('form-panel');
  p.style.display = p.style.display === 'none' ? 'block' : 'none';
  if (p.style.display === 'block') p.scrollIntoView({behavior:'smooth'});
}
function toggleCat(id) {
  const el   = document.getElementById(id);
  const chev = document.getElementById('chev-' + id.replace('cat-',''));
  const open = el.style.display !== 'none';
  el.style.display = open ? 'none' : '';
  if (chev) chev.style.transform = open ? 'rotate(-90deg)' : '';
}
</script>

<?php if (!defined('ADMIN_HUB')) require_once '../includes/footer.php'; ?>

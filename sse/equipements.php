<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.
require_once '../includes/auth.php';
$pageTitle = 'SSE — Équipements';
$site_id   = site_actif();
if (!$site_id) { header('Location: /sse.php'); exit; }
ob_start();

$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $d = [
            'nom'                => trim(substr($_POST['nom'] ?? '', 0, 150)),
            'categorie_id'       => (int)($_POST['categorie_id'] ?? 0) ?: null,
            'numero_serie'       => trim($_POST['numero_serie'] ?? '') ?: null,
            'taille'             => trim($_POST['taille'] ?? '') ?: null,
            'localisation'       => trim($_POST['localisation'] ?? '') ?: null,
            'date_fabrication'   => $_POST['date_fabrication'] ?: null,
            'date_mise_service'  => $_POST['date_mise_service'] ?: null,
            'date_peremption'    => $_POST['date_peremption'] ?: null,
            'date_prochain_ctrl' => $_POST['date_prochain_ctrl'] ?: null,
            'statut'             => $_POST['statut'] ?? 'operationnel',
            'notes'              => trim($_POST['notes'] ?? '') ?: null,
        ];
        if (!$d['nom']) { $error = 'Nom requis'; }
        else {
            if ($id > 0) {
                $sets = implode(',', array_map(fn($k) => "$k=?", array_keys($d)));
                $pdo->prepare("UPDATE sse_equipements SET $sets WHERE id=? AND site_id=?")
                    ->execute([...array_values($d), $id, $site_id]);
                $success = 'Équipement mis à jour.';
            } else {
                $keys = implode(',', array_keys($d)) . ',site_id,user_id,cree_le';
                $vals = implode(',', array_fill(0, count($d), '?')) . ',?,?,NOW()';
                $pdo->prepare("INSERT INTO sse_equipements ($keys) VALUES ($vals)")
                    ->execute([...array_values($d), $site_id, currentUserId()]);
                $success = 'Équipement créé.';
                audit_log($pdo,'SSE_EQUIPEMENT_CREATE','sse_equipements',(int)$pdo->lastInsertId());
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE sse_equipements SET actif=0 WHERE id=? AND site_id=?")->execute([$id,$site_id]);
        $success = 'Équipement archivé.';
    } elseif ($action === 'set_statut') {
        $id  = (int)($_POST['id'] ?? 0);
        $new = in_array($_POST['statut'] ?? '', ['operationnel','revision','hs','reforme'], true) ? $_POST['statut'] : 'operationnel';
        $old = $pdo->prepare("SELECT statut FROM sse_equipements WHERE id=? AND site_id=?"); $old->execute([$id,$site_id]); $old = $old->fetchColumn();
        if ($old !== false && $old !== $new) {
            $pdo->prepare("UPDATE sse_equipements SET statut=? WHERE id=? AND site_id=?")->execute([$new,$id,$site_id]);
            audit_log($pdo,'SSE_EQUIPEMENT_STATUT','sse_equipements',$id,['statut'=>$old],['statut'=>$new]);
            $success = 'Statut mis à jour.';
        }
    }
}

// Filtres
$f_statut = $_GET['statut'] ?? '';
$f_cat    = (int)($_GET['categorie'] ?? 0);
$f_alerte = isset($_GET['alerte']);

$where = ['e.site_id=?', 'e.actif=1'];
$params = [$site_id];
if ($f_statut) { $where[] = 'e.statut=?'; $params[] = $f_statut; }
if ($f_cat)    { $where[] = 'e.categorie_id=?'; $params[] = $f_cat; }
if ($f_alerte) { $where[] = "(e.date_peremption<=DATE_ADD(CURDATE(),INTERVAL 30 DAY) OR e.date_prochain_ctrl<=CURDATE() OR e.statut IN ('hs','revision','reforme'))"; }

$w = implode(' AND ', $where);
$equipements = $pdo->prepare("
    SELECT e.*, c.nom AS cat_nom, c.icone
    FROM sse_equipements e
    LEFT JOIN sse_categories c ON c.id=e.categorie_id
    WHERE $w
    ORDER BY e.statut='operationnel' DESC, e.date_peremption ASC, e.nom
");
$equipements->execute($params);
$equipements = $equipements->fetchAll(PDO::FETCH_ASSOC);

$categories = $pdo->prepare("SELECT * FROM sse_categories WHERE site_id=? AND actif=1 ORDER BY nom");
$categories->execute([$site_id]);
$categories = $categories->fetchAll(PDO::FETCH_ASSOC);

$edit = null;
if (isset($_GET['edit'])) {
    $s = $pdo->prepare("SELECT * FROM sse_equipements WHERE id=? AND site_id=?");
    $s->execute([(int)$_GET['edit'], $site_id]);
    $edit = $s->fetch(PDO::FETCH_ASSOC);
}

// Stats
$stats_rows = $pdo->prepare("SELECT statut, COUNT(*) AS nb FROM sse_equipements WHERE site_id=? AND actif=1 GROUP BY statut");
$stats_rows->execute([$site_id]);
$stats = [];
foreach ($stats_rows->fetchAll(PDO::FETCH_ASSOC) as $r) $stats[$r['statut']] = $r['nb'];

require_once '../includes/header.php';
if (!nav_peut($nav_perms, 'sse')) { ob_end_clean(); accessDenied(); exit; }

$sse_statuts = [
    'operationnel' => ['✅','Opérationnel','#10b981'],
    'revision'     => ['🔄','En révision', '#f59e0b'],
    'hs'           => ['❌','Hors service','#ef4444'],
    'reforme'      => ['🗑️','Réformé',     '#6b7280'],
];
?>
<style>
.sse-toolbar { display:flex; gap:10px; margin-bottom:16px; align-items:center; flex-wrap:wrap; }
.sse-search {
  flex:1; min-width:200px; background:var(--bg2); border:1px solid var(--border);
  border-radius:8px; padding:8px 14px; color:var(--text); font-family:inherit;
  font-size:.85rem; outline:none;
}
.sse-search:focus { border-color:var(--primary); }
.sse-filter {
  padding:6px 14px; border-radius:8px; font-size:.78rem; font-weight:600;
  text-decoration:none; border:1px solid var(--border);
  background:var(--glass); color:var(--text2); transition:.15s; white-space:nowrap;
}
.sse-filter:hover { border-color:var(--primary); color:var(--primary); }
.sse-filter.active { background:var(--primary); border-color:var(--primary); color:white; }
.sse-filter.danger.active { background:#ef4444; border-color:#ef4444; }

.sse-table { width:100%; border-collapse:collapse; font-size:.85rem; }
.sse-table thead tr { border-bottom:2px solid var(--border); }
.sse-table th {
  padding:10px 14px; text-align:left; font-size:.72rem; font-weight:700;
  color:var(--text3); text-transform:uppercase; letter-spacing:.5px;
  cursor:pointer; white-space:nowrap; user-select:none;
}
.sse-table th:hover { color:var(--text); }
.sse-table th.sorted { color:var(--primary); }
.sse-table tbody tr { border-bottom:1px solid var(--border); transition:background .1s; }
.sse-table tbody tr:hover { background:var(--glass); }
.sse-table td { padding:10px 14px; vertical-align:middle; }
.sse-badge {
  display:inline-flex; align-items:center; gap:4px;
  padding:3px 10px; border-radius:20px; font-size:.72rem; font-weight:600; white-space:nowrap;
}
.sse-actions { display:flex; gap:4px; }
.sse-statut-sel { padding:4px 9px; border-radius:20px; font-size:.72rem; font-weight:700; font-family:inherit; cursor:pointer; outline:none; -webkit-appearance:none; appearance:none; }
.sse-statut-sel:hover { filter:brightness(1.04); }
</style>

<div class="page-header">
  <div class="page-title-group">
    <div class="page-title">⚙️ Équipements SSE / NRBC</div>
    <div class="page-sub"><?= count($equipements) ?> équipement(s) affiché(s)</div>
  </div>
  <div class="page-actions">
    <a href="/sse.php" class="btn btn-sm btn-ghost">← Dashboard</a>
    <a href="/sse/export.php?type=equipements" class="btn btn-sm btn-ghost">⬇️ Export</a>
    <a href="/qrcodes.php?type=sse" class="btn btn-sm btn-ghost">🏷️ QR Codes</a>
    <button onclick="document.getElementById('modal-add').style.display='flex'" class="btn btn-sm btn-primary">+ Ajouter</button>
  </div>
</div>

<?php $sse_active = 'equipements'; include __DIR__.'/_nav.php'; ?>

<?php if ($success): ?><div class="flash flash-success">✅ <?= h($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="flash flash-error">⚠️ <?= h($error) ?></div><?php endif; ?>

<!-- Barre recherche + filtres -->
<div class="sse-toolbar">
  <input type="text" class="sse-search" id="sse-search" placeholder="🔍 Rechercher nom, N° série, localisation…" oninput="filtrerSSE(this.value)">
  <a href="?" class="sse-filter <?= !$f_statut&&!$f_alerte?'active':'' ?>">Tous (<?= array_sum($stats) ?>)</a>
  <?php foreach ($sse_statuts as $k=>[$ico,$lbl,$col]): if (!($stats[$k]??0)) continue; ?>
  <a href="?statut=<?= $k ?>" class="sse-filter <?= $f_statut===$k?'active':'' ?>"><?= $ico ?> <?= $lbl ?> (<?= $stats[$k] ?>)</a>
  <?php endforeach; ?>
  <a href="?alerte=1" class="sse-filter danger <?= $f_alerte?'active':'' ?>">🚨 Alertes</a>
</div>

<!-- Tableau -->
<?php if (empty($equipements)): ?>
<div style="text-align:center;padding:3rem;color:var(--text3);background:var(--glass);border:1px solid var(--border);border-radius:12px">Aucun équipement trouvé</div>
<?php else: ?>
<div class="card" style="padding:0;overflow:hidden">
  <div style="overflow-x:auto">
  <table class="sse-table" id="sse-table">
    <thead>
      <tr>
        <th onclick="trierSSE(0)">Équipement <span class="sort-icon">↕</span></th>
        <th onclick="trierSSE(1)">Catégorie <span class="sort-icon">↕</span></th>
        <th onclick="trierSSE(2)">N° Série <span class="sort-icon">↕</span></th>
        <th onclick="trierSSE(3)">Statut <span class="sort-icon">↕</span></th>
        <th onclick="trierSSE(4)">Péremption <span class="sort-icon">↕</span></th>
        <th onclick="trierSSE(5)">Prochain contrôle <span class="sort-icon">↕</span></th>
        <th onclick="trierSSE(6)">Localisation <span class="sort-icon">↕</span></th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($equipements as $eq):
      [$ico,$lbl,$col] = $sse_statuts[$eq['statut']] ?? ['❓','Inconnu','#6b7280'];
      $jours_per  = $eq['date_peremption']  ? (int)((strtotime($eq['date_peremption']) -time())/86400) : null;
      $jours_ctrl = $eq['date_prochain_ctrl']? (int)((strtotime($eq['date_prochain_ctrl'])-time())/86400) : null;
    ?>
    <tr class="sse-row" data-search="<?= strtolower(h($eq['nom'].' '.($eq['numero_serie']??'').' '.($eq['localisation']??'').' '.($eq['cat_nom']??''))) ?>">
      <td>
        <div style="font-weight:600"><?= h($eq['icone']??'⚙️') ?> <?= h($eq['nom']) ?></div>
        <?php if ($eq['notes']): ?>
        <div style="font-size:.72rem;color:var(--text3);margin-top:2px"><?= h(mb_substr($eq['notes'],0,60)) ?><?= mb_strlen($eq['notes'])>60?'…':'' ?></div>
        <?php endif; ?>
      </td>
      <td style="font-size:.82rem;color:var(--text2)"><?= h($eq['cat_nom']??'—') ?></td>
      <td><span style="font-family:monospace;font-size:.78rem"><?= $eq['numero_serie'] ? h($eq['numero_serie']) : '—' ?></span></td>
      <td>
        <form method="post" style="margin:0" title="Changer le statut">
          <?= csrf_field() ?><input type="hidden" name="action" value="set_statut"><input type="hidden" name="id" value="<?= $eq['id'] ?>">
          <select name="statut" onchange="this.form.submit()" class="sse-statut-sel" style="background:<?= $col ?>18;color:<?= $col ?>;border:1px solid <?= $col ?>44">
            <?php foreach ($sse_statuts as $sk=>[$si,$sl,$scc]): ?>
            <option value="<?= $sk ?>" <?= $eq['statut']===$sk?'selected':'' ?>><?= $si ?> <?= $sl ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      </td>
      <td>
        <?php if ($jours_per !== null): ?>
        <span style="font-size:.82rem;color:<?= $jours_per<0?'#f87171':($jours_per<30?'#fbbf24':'var(--text2)') ?>">
          <?= date('d/m/Y', strtotime($eq['date_peremption'])) ?>
          <span style="font-size:.72rem">(<?= $jours_per<0?'périmé':'J-'.$jours_per ?>)</span>
        </span>
        <?php else: ?><span style="color:var(--text3)">—</span><?php endif; ?>
      </td>
      <td>
        <?php if ($jours_ctrl !== null): ?>
        <span style="font-size:.82rem;color:<?= $jours_ctrl<=0?'#f87171':($jours_ctrl<=7?'#fbbf24':'var(--text2)') ?>">
          <?= date('d/m/Y', strtotime($eq['date_prochain_ctrl'])) ?>
          <?php if ($jours_ctrl<=0): ?><span style="font-size:.7rem;font-weight:700"> DÛ</span><?php endif; ?>
        </span>
        <?php else: ?><span style="color:var(--text3)">—</span><?php endif; ?>
      </td>
      <td style="font-size:.82rem;color:var(--text2)"><?= h($eq['localisation']??'—') ?></td>
      <td>
        <div class="sse-actions">
          <a href="controles.php?equipement=<?= $eq['id'] ?>" class="btn btn-ghost btn-xs" title="Contrôle">📋</a>
          <a href="?edit=<?= $eq['id'] ?>" class="btn btn-ghost btn-xs" title="Modifier">✏️</a>
          <form method="post" style="display:inline" onsubmit="return confirm('Archiver ?')">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= $eq['id'] ?>"><?= csrf_field() ?>
            <button type="submit" class="btn btn-xs" style="background:rgba(239,68,68,.1);color:#f87171;border:1px solid rgba(239,68,68,.3)">🗑️</button>
          </form>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <div style="padding:10px 16px;font-size:.75rem;color:var(--text3);border-top:1px solid var(--border)" id="sse-count">
    <?= count($equipements) ?> équipement(s) affiché(s)
  </div>
</div>
<?php endif; ?>

<!-- Modal ajout/édition -->
<div id="modal-add" style="display:<?= $edit?'flex':'none' ?>;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;align-items:center;justify-content:center" onclick="if(event.target===this)this.style.display='none'">
  <div style="background:var(--bg2);border:1px solid var(--border);border-radius:16px;padding:28px;width:90%;max-width:640px;max-height:90vh;overflow-y:auto">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">
      <div style="font-size:1.1rem;font-weight:700">⚙️ <?= $edit?'Modifier':'Ajouter' ?> un équipement</div>
      <button onclick="document.getElementById('modal-add').style.display='none'" style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--text2)">✕</button>
    </div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= $edit['id']??0 ?>">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div style="grid-column:1/-1">
          <label class="form-label">Nom *</label>
          <input type="text" name="nom" required value="<?= h($edit['nom']??'') ?>" class="form-control">
        </div>
        <div>
          <label class="form-label">Catégorie</label>
          <select name="categorie_id" class="form-control">
            <option value="">— Choisir —</option>
            <?php foreach ($categories as $cat): ?>
            <option value="<?= $cat['id'] ?>" <?= ($edit['categorie_id']??0)==$cat['id']?'selected':'' ?>><?= h($cat['icone'].' '.$cat['nom']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label">Statut</label>
          <select name="statut" class="form-control">
            <?php foreach ($sse_statuts as $k=>[$i,$l,$c]): ?>
            <option value="<?= $k ?>" <?= ($edit['statut']??'operationnel')===$k?'selected':'' ?>><?= $i.' '.$l ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label">N° Série</label>
          <input type="text" name="numero_serie" value="<?= h($edit['numero_serie']??'') ?>" class="form-control">
        </div>
        <div>
          <label class="form-label">Taille</label>
          <input type="text" name="taille" value="<?= h($edit['taille']??'') ?>" class="form-control" placeholder="S, M, L, XL…">
        </div>
        <div>
          <label class="form-label">Date fabrication</label>
          <input type="date" name="date_fabrication" value="<?= h($edit['date_fabrication']??'') ?>" class="form-control">
        </div>
        <div>
          <label class="form-label">Mise en service</label>
          <input type="date" name="date_mise_service" value="<?= h($edit['date_mise_service']??'') ?>" class="form-control">
        </div>
        <div>
          <label class="form-label">Péremption</label>
          <input type="date" name="date_peremption" value="<?= h($edit['date_peremption']??'') ?>" class="form-control">
        </div>
        <div>
          <label class="form-label">Prochain contrôle</label>
          <input type="date" name="date_prochain_ctrl" value="<?= h($edit['date_prochain_ctrl']??'') ?>" class="form-control">
        </div>
        <div style="grid-column:1/-1">
          <label class="form-label">Localisation</label>
          <input type="text" name="localisation" value="<?= h($edit['localisation']??'') ?>" class="form-control" placeholder="Armoire A1, Local SSE…">
        </div>
        <div style="grid-column:1/-1">
          <label class="form-label">Notes</label>
          <textarea name="notes" rows="2" class="form-control"><?= h($edit['notes']??'') ?></textarea>
        </div>
      </div>
      <div style="display:flex;gap:8px;margin-top:16px">
        <button type="submit" class="btn btn-primary">💾 Enregistrer</button>
        <button type="button" onclick="document.getElementById('modal-add').style.display='none'" class="btn btn-ghost">Annuler</button>
      </div>
    </form>
  </div>
</div>

<script>
function filtrerSSE(q) {
  q = q.toLowerCase().trim();
  const rows = document.querySelectorAll('.sse-row');
  let n = 0;
  rows.forEach(r => {
    const show = !q || r.dataset.search.includes(q);
    r.style.display = show ? '' : 'none';
    if (show) n++;
  });
  document.getElementById('sse-count').textContent = n + ' équipement(s) affiché(s)';
}

let _sc = -1, _sd = 1;
function trierSSE(col) {
  const tbody = document.querySelector('#sse-table tbody');
  const rows = Array.from(tbody.querySelectorAll('tr'));
  if (_sc === col) _sd *= -1; else { _sc = col; _sd = 1; }
  document.querySelectorAll('#sse-table th').forEach((th,i) => {
    th.classList.toggle('sorted', i === col);
    const s = th.querySelector('.sort-icon');
    if (s) s.textContent = i === col ? (_sd===1?'↓':'↑') : '↕';
  });
  rows.sort((a,b) => {
    const ta = a.querySelectorAll('td')[col]?.textContent.trim().toLowerCase()||'';
    const tb = b.querySelectorAll('td')[col]?.textContent.trim().toLowerCase()||'';
    return ta.localeCompare(tb,'fr') * _sd;
  });
  rows.forEach(r => tbody.appendChild(r));
}
</script>

<?php require_once '../includes/footer.php'; ?>

<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.
require_once '../includes/auth.php';
$pageTitle = 'SSE — Consommables';
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
            'nom'             => trim(substr($_POST['nom']??'',0,150)),
            'categorie_id'    => (int)($_POST['categorie_id']??0) ?: null,
            'reference'       => trim($_POST['reference']??'') ?: null,
            'numero_lot'      => trim($_POST['numero_lot']??'') ?: null,
            'quantite'        => (int)($_POST['quantite']??0),
            'quantite_min'    => (int)($_POST['quantite_min']??0),
            'unite'           => trim($_POST['unite']??'unité'),
            'date_peremption' => $_POST['date_peremption'] ?: null,
            'localisation'    => trim($_POST['localisation']??'') ?: null,
            'notes'           => trim($_POST['notes']??'') ?: null,
        ];
        if (!$d['nom']) { $error = 'Nom requis'; }
        else {
            if ($id > 0) {
                $sets = implode(',', array_map(fn($k) => "$k=?", array_keys($d)));
                $pdo->prepare("UPDATE sse_consommables SET $sets WHERE id=? AND site_id=?")->execute([...array_values($d),$id,$site_id]);
                $success = 'Consommable mis à jour.';
            } else {
                $keys = implode(',', array_keys($d)) . ',site_id,cree_le';
                $vals = implode(',', array_fill(0,count($d),'?')) . ',?,NOW()';
                $pdo->prepare("INSERT INTO sse_consommables ($keys) VALUES ($vals)")->execute([...array_values($d),$site_id]);
                $success = 'Consommable créé.';
            }
        }
    } elseif ($action === 'delete') {
        $did = (int)$_POST['id'];
        $dold = $pdo->prepare("SELECT nom,quantite FROM sse_consommables WHERE id=? AND site_id=?"); $dold->execute([$did,$site_id]); $dold=$dold->fetch(PDO::FETCH_ASSOC);
        $pdo->prepare("UPDATE sse_consommables SET statut='retire' WHERE id=? AND site_id=?")->execute([$did,$site_id]);
        if ($dold) audit_log($pdo,'SSE_CONSO_RETRAIT','sse_consommables',$did,$dold,['statut'=>'retire']);
        $success = 'Consommable retiré.';
    } elseif ($action === 'adjust_qty') {
        $id  = (int)($_POST['id']??0);
        $qty = max(0,(int)($_POST['quantite']??0));
        $old = $pdo->prepare("SELECT quantite,nom FROM sse_consommables WHERE id=? AND site_id=?"); $old->execute([$id,$site_id]); $old=$old->fetch(PDO::FETCH_ASSOC);
        if ($old) {
            $pdo->prepare("UPDATE sse_consommables SET quantite=?,modifie_le=NOW() WHERE id=? AND site_id=?")->execute([$qty,$id,$site_id]);
            audit_log($pdo,'SSE_CONSO_QTE','sse_consommables',$id,['quantite'=>$old['quantite'],'nom'=>$old['nom']],['quantite'=>$qty]);
        }
        header('Content-Type: application/json');
        echo json_encode(['ok'=>true,'qty'=>$qty]); exit;
    }
}

$filtre     = $_GET['filtre'] ?? '';
$cat_filtre = (int)($_GET['cat'] ?? 0);

$all_stmt = $pdo->prepare("
    SELECT s.*, c.nom AS cat_nom, c.icone,
           DATEDIFF(s.date_peremption,CURDATE()) AS jours
    FROM sse_consommables s
    LEFT JOIN sse_categories c ON c.id=s.categorie_id
    WHERE s.site_id=? AND s.statut='actif'
    ORDER BY s.date_peremption ASC, s.nom
");
$all_stmt->execute([$site_id]);
$all_items = $all_stmt->fetchAll(PDO::FETCH_ASSOC);

$nb_total   = count($all_items);
$nb_perimes = count(array_filter($all_items, fn($i) => $i['date_peremption'] && $i['jours'] < 0));
$nb_alerte  = count(array_filter($all_items, fn($i) => ($i['date_peremption'] && $i['jours'] >= 0 && $i['jours'] <= 30) || ($i['quantite_min'] > 0 && $i['quantite'] <= $i['quantite_min'])));
$nb_stock   = count(array_filter($all_items, fn($i) => $i['quantite_min'] > 0 && $i['quantite'] <= $i['quantite_min']));

$items = array_values(array_filter($all_items, function($i) use ($filtre, $cat_filtre) {
    if ($cat_filtre && $i['categorie_id'] != $cat_filtre) return false;
    return match($filtre) {
        'perimes' => $i['date_peremption'] && $i['jours'] < 0,
        'alerte'  => ($i['date_peremption'] && $i['jours'] >= 0 && $i['jours'] <= 30) || ($i['quantite_min'] > 0 && $i['quantite'] <= $i['quantite_min']),
        'stock'   => $i['quantite_min'] > 0 && $i['quantite'] <= $i['quantite_min'],
        default   => true,
    };
}));

$cats_stmt = $pdo->prepare("SELECT c.*, COUNT(s.id) AS nb FROM sse_categories c LEFT JOIN sse_consommables s ON s.categorie_id=c.id AND s.site_id=? AND s.statut='actif' WHERE c.site_id=? AND c.actif=1 GROUP BY c.id ORDER BY c.nom");
$cats_stmt->execute([$site_id, $site_id]);
$categories = $cats_stmt->fetchAll(PDO::FETCH_ASSOC);

$edit = null;
if (isset($_GET['edit'])) {
    $s = $pdo->prepare("SELECT * FROM sse_consommables WHERE id=? AND site_id=?");
    $s->execute([(int)$_GET['edit'], $site_id]);
    $edit = $s->fetch(PDO::FETCH_ASSOC);
}

require_once '../includes/header.php';
if (!nav_peut($nav_perms, 'sse')) { ob_end_clean(); accessDenied(); exit; }
?>
<style>
.conso-layout { display:grid; grid-template-columns:200px 1fr; gap:20px; align-items:start; }
@media(max-width:900px){ .conso-layout { grid-template-columns:1fr; } }

.conso-sidebar { background:var(--bg2); border:1px solid var(--border); border-radius:12px; overflow:hidden; position:sticky; top:80px; }
.conso-sidebar-head { padding:12px 16px; border-bottom:1px solid var(--border); font-size:.7rem; font-weight:800; text-transform:uppercase; letter-spacing:1.5px; color:var(--text3); }
.conso-cat-btn { display:flex; align-items:center; gap:9px; padding:9px 16px; cursor:pointer; border-bottom:1px solid var(--border); text-decoration:none; color:var(--text2); transition:.12s; font-size:.82rem; }
.conso-cat-btn:last-child { border-bottom:none; }
.conso-cat-btn:hover { background:var(--glass); color:var(--text); }
.conso-cat-btn.active { background:var(--primary-bg); color:var(--primary); font-weight:600; }
.conso-cat-count { margin-left:auto; font-size:.7rem; font-weight:700; padding:1px 6px; border-radius:10px; background:var(--border); color:var(--text3); }
.conso-cat-btn.active .conso-cat-count { background:var(--primary); color:white; }

/* Tableau */
.conso-table { width:100%; border-collapse:collapse; font-size:.85rem; }
.conso-table thead tr { border-bottom:2px solid var(--border); }
.conso-table th { padding:10px 14px; text-align:left; font-size:.72rem; font-weight:700; color:var(--text3); text-transform:uppercase; letter-spacing:.5px; cursor:pointer; white-space:nowrap; user-select:none; }
.conso-table th:hover { color:var(--text); }
.conso-table th.sorted { color:var(--primary); }
.conso-table tbody tr { border-bottom:1px solid var(--border); transition:background .1s; }
.conso-table tbody tr:hover { background:var(--glass); }
.conso-table td { padding:9px 14px; vertical-align:middle; }
.conso-table tbody tr.row-critique td:first-child { border-left:3px solid #ef4444; }
.conso-table tbody tr.row-alerte   td:first-child { border-left:3px solid #f59e0b; }
.conso-table tbody tr.row-perime   td:first-child { border-left:3px solid #9ca3af; }
.conso-table tbody tr.row-ok       td:first-child { border-left:3px solid #10b981; }

/* Stock inline */
.stock-inline { display:flex; align-items:center; gap:6px; }
.stock-bar-sm { width:50px; height:4px; background:var(--border); border-radius:99px; overflow:hidden; flex-shrink:0; }
.stock-fill-sm { height:100%; border-radius:99px; }

/* Qty adjuster compact */
.qty-adj { display:inline-flex; align-items:center; gap:4px; background:var(--glass); border:1px solid var(--border); border-radius:7px; padding:2px 6px; }
.qty-b { width:20px; height:20px; border-radius:5px; border:none; cursor:pointer; font-size:.85rem; font-weight:700; display:flex; align-items:center; justify-content:center; background:var(--border); color:var(--text); transition:.12s; font-family:inherit; }
.qty-b:hover { background:var(--primary); color:white; }
.qty-v { min-width:28px; text-align:center; font-weight:700; font-size:.85rem; }
.qty-u { font-size:.7rem; color:var(--text3); }

/* Filtres */
.conso-filters { display:flex; gap:8px; margin-bottom:14px; flex-wrap:wrap; }
.cf { padding:5px 12px; border-radius:20px; font-size:.78rem; font-weight:600; text-decoration:none; border:1px solid var(--border); background:var(--glass); color:var(--text2); transition:.15s; }
.cf:hover { border-color:var(--primary); color:var(--primary); }
.cf.active { background:var(--primary); border-color:var(--primary); color:white; }
.cf.a-warning.active { background:#f59e0b; border-color:#f59e0b; color:#1a1a1a; }
.cf.a-danger.active  { background:#ef4444; border-color:#ef4444; }
.cf.a-muted.active   { background:#6b7280; border-color:#6b7280; }

.conso-search { width:100%; background:var(--bg2); border:1px solid var(--border); border-radius:8px; padding:8px 14px; color:var(--text); font-family:inherit; font-size:.85rem; outline:none; margin-bottom:14px; }
.conso-search:focus { border-color:var(--primary); }
</style>

<div class="page-header">
  <div class="page-title-group">
    <div class="page-title">📦 Consommables SSE / NRBC</div>
    <div class="page-sub"><?= $nb_total ?> produit(s) actif(s)</div>
  </div>
  <div class="page-actions">
    <a href="/sse/export.php?type=consommables" class="btn btn-sm btn-ghost">⬇️ Export</a>
    <button onclick="document.getElementById('modal-add').style.display='flex'" class="btn btn-sm btn-primary">+ Ajouter</button>
  </div>
</div>

<?php $sse_active = 'consommables'; include __DIR__.'/_nav.php'; ?>

<?php if ($success): ?><div class="flash flash-success">✅ <?= h($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="flash flash-error">⚠️ <?= h($error) ?></div><?php endif; ?>

<!-- KPIs -->
<div class="sse-ck">
  <div class="sse-ck-card" style="--kc:#10b981"><span class="ic" style="background:#10b9811f;color:#10b981">📦</span><div><div class="n" style="color:#10b981"><?= $nb_total ?></div><div class="l">Total</div><div class="f">Produits actifs</div></div></div>
  <div class="sse-ck-card" style="--kc:<?= $nb_perimes>0?'#ef4444':'#10b981' ?>"><span class="ic" style="background:<?= $nb_perimes>0?'#ef4444':'#10b981' ?>1f;color:<?= $nb_perimes>0?'#ef4444':'#10b981' ?>">💀</span><div><div class="n" style="color:<?= $nb_perimes>0?'#ef4444':'#10b981' ?>"><?= $nb_perimes ?></div><div class="l">Périmés</div><div class="f">À retirer</div></div></div>
  <div class="sse-ck-card" style="--kc:<?= $nb_alerte>0?'#f59e0b':'#10b981' ?>"><span class="ic" style="background:<?= $nb_alerte>0?'#f59e0b':'#10b981' ?>1f;color:<?= $nb_alerte>0?'#f59e0b':'#10b981' ?>">⚠️</span><div><div class="n" style="color:<?= $nb_alerte>0?'#f59e0b':'#10b981' ?>"><?= $nb_alerte ?></div><div class="l">Alertes</div><div class="f">30j ou stock bas</div></div></div>
  <div class="sse-ck-card" style="--kc:<?= $nb_stock>0?'#ef4444':'#10b981' ?>"><span class="ic" style="background:<?= $nb_stock>0?'#ef4444':'#10b981' ?>1f;color:<?= $nb_stock>0?'#ef4444':'#10b981' ?>">📉</span><div><div class="n" style="color:<?= $nb_stock>0?'#ef4444':'#10b981' ?>"><?= $nb_stock ?></div><div class="l">Stock bas</div><div class="f">Sous le minimum</div></div></div>
</div>

<div class="conso-layout">

  <!-- Sidebar catégories -->
  <div class="conso-sidebar">
    <div class="conso-sidebar-head">Catégories</div>
    <a href="?<?= $filtre?"filtre=$filtre":'' ?>" class="conso-cat-btn <?= !$cat_filtre?'active':'' ?>">
      <span>📦</span> Toutes
      <span class="conso-cat-count"><?= $nb_total ?></span>
    </a>
    <?php foreach ($categories as $cat): if (!$cat['nb']) continue; ?>
    <a href="?cat=<?= $cat['id'] ?><?= $filtre?"&filtre=$filtre":'' ?>" class="conso-cat-btn <?= $cat_filtre==$cat['id']?'active':'' ?>">
      <span><?= h($cat['icone']??'📦') ?></span>
      <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($cat['nom']) ?></span>
      <span class="conso-cat-count"><?= $cat['nb'] ?></span>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- Contenu -->
  <div>
    <input type="text" class="conso-search" id="conso-search" placeholder="🔍 Rechercher produit, référence, lot, localisation…" oninput="filtrerConso(this.value)">

    <div class="conso-filters">
      <a href="?<?= $cat_filtre?"cat=$cat_filtre":'' ?>" class="cf <?= !$filtre?'active':'' ?>">Tous (<?= $nb_total ?>)</a>
      <a href="?filtre=alerte<?= $cat_filtre?"&cat=$cat_filtre":'' ?>" class="cf a-warning <?= $filtre==='alerte'?'active':'' ?>">⚠️ Alertes (<?= $nb_alerte ?>)</a>
      <a href="?filtre=stock<?= $cat_filtre?"&cat=$cat_filtre":'' ?>" class="cf a-danger <?= $filtre==='stock'?'active':'' ?>">📉 Stock bas (<?= $nb_stock ?>)</a>
      <a href="?filtre=perimes<?= $cat_filtre?"&cat=$cat_filtre":'' ?>" class="cf a-muted <?= $filtre==='perimes'?'active':'' ?>">💀 Périmés (<?= $nb_perimes ?>)</a>
    </div>

    <?php if (empty($items)): ?>
    <div style="text-align:center;padding:3rem;background:var(--glass);border:1px solid var(--border);border-radius:12px;color:var(--text3)">
      <div style="font-size:2rem;margin-bottom:8px">📦</div>Aucun consommable dans cette sélection
    </div>
    <?php else: ?>
    <div class="card" style="padding:0;overflow:hidden">
      <div style="overflow-x:auto">
      <table class="conso-table" id="conso-table">
        <thead>
          <tr>
            <th onclick="trierConso(0)">Produit <span>↕</span></th>
            <th onclick="trierConso(1)">Catégorie <span>↕</span></th>
            <th onclick="trierConso(2)">Lot <span>↕</span></th>
            <th onclick="trierConso(3)">Stock <span>↕</span></th>
            <th onclick="trierConso(4)">Péremption <span>↕</span></th>
            <th onclick="trierConso(5)">Localisation <span>↕</span></th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $it):
          $j = $it['jours'] ?? 999;
          $is_perime   = $it['date_peremption'] && $j < 0;
          $is_critique = !$is_perime && $it['date_peremption'] && $j <= 7;
          $is_alerte   = !$is_perime && !$is_critique && $it['date_peremption'] && $j <= 30;
          $is_stock    = $it['quantite_min'] > 0 && $it['quantite'] <= $it['quantite_min'];
          $row_cls     = $is_perime?'row-perime':($is_critique?'row-critique':($is_alerte||$is_stock?'row-alerte':'row-ok'));
          $per_col     = $is_perime?'#9ca3af':($is_critique?'#ef4444':($is_alerte?'#f59e0b':'#10b981'));
          $per_lbl     = $is_perime?'💀 Périmé':($is_critique?'⚠️ J-'.$j:($is_alerte?'⏰ J-'.$j:($it['date_peremption']?'✅ J-'.$j:'—')));
          $stock_pct   = $it['quantite_min'] > 0 ? min(100, round($it['quantite'] / max(1, $it['quantite_min'] * 2) * 100)) : 100;
          $stock_col   = $is_stock ? '#ef4444' : ($stock_pct < 75 ? '#f59e0b' : '#10b981');
        ?>
        <tr class="conso-row <?= $row_cls ?>" data-search="<?= strtolower(h($it['nom'].' '.($it['reference']??'').' '.($it['numero_lot']??'').' '.($it['cat_nom']??'').' '.($it['localisation']??''))) ?>">
          <td>
            <div style="font-weight:600"><?= h($it['icone']??'📦') ?> <?= h($it['nom']) ?></div>
            <?php if ($it['reference']): ?><div style="font-size:.72rem;color:var(--text3);font-family:monospace"><?= h($it['reference']) ?></div><?php endif; ?>
          </td>
          <td style="font-size:.82rem;color:var(--text2)"><?= h($it['cat_nom']??'—') ?></td>
          <td><span style="font-family:monospace;font-size:.78rem;color:var(--text2)"><?= $it['numero_lot'] ? h($it['numero_lot']) : '—' ?></span></td>
          <td>
            <div class="stock-inline">
              <div class="qty-adj" id="adj-<?= $it['id'] ?>">
                <button class="qty-b" onclick="ajusterQty(<?= $it['id'] ?>, -1)">−</button>
                <span class="qty-v" id="qty-<?= $it['id'] ?>" style="color:<?= $is_stock?'#f87171':'var(--text)' ?>"><?= $it['quantite'] ?></span>
                <span class="qty-u"><?= h($it['unite']) ?></span>
                <button class="qty-b" onclick="ajusterQty(<?= $it['id'] ?>, 1)">+</button>
              </div>
              <?php if ($it['quantite_min'] > 0): ?>
              <div class="stock-bar-sm" title="Min: <?= $it['quantite_min'] ?> <?= h($it['unite']) ?>">
                <div class="stock-fill-sm" style="width:<?= $stock_pct ?>%;background:<?= $stock_col ?>"></div>
              </div>
              <?php endif; ?>
            </div>
          </td>
          <td>
            <?php if ($it['date_peremption']): ?>
            <div style="font-size:.82rem"><?= date('d/m/Y', strtotime($it['date_peremption'])) ?></div>
            <span style="font-size:.72rem;font-weight:700;padding:1px 7px;border-radius:10px;background:<?= $per_col ?>22;color:<?= $per_col ?>"><?= $per_lbl ?></span>
            <?php else: ?><span style="color:var(--text3)">—</span><?php endif; ?>
          </td>
          <td style="font-size:.82rem;color:var(--text2)"><?= h($it['localisation']??'—') ?></td>
          <td>
            <div style="display:flex;gap:4px">
              <a href="?edit=<?= $it['id'] ?>" class="btn btn-ghost btn-xs" title="Modifier">✏️</a>
              <form method="post" style="display:inline" onsubmit="return confirm('Retirer ?')">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $it['id'] ?>"><?= csrf_field() ?>
                <button type="submit" class="btn btn-xs" style="background:rgba(239,68,68,.1);color:#f87171;border:1px solid rgba(239,68,68,.3)">🗑️</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <div style="padding:10px 16px;font-size:.75rem;color:var(--text3);border-top:1px solid var(--border)" id="conso-count">
        <?= count($items) ?> produit(s) affiché(s)
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Modal -->
<div id="modal-add" style="display:<?= $edit?'flex':'none' ?>;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;align-items:center;justify-content:center" onclick="if(event.target===this)this.style.display='none'">
  <div style="background:var(--bg2);border:1px solid var(--border);border-radius:16px;padding:28px;width:90%;max-width:600px;max-height:90vh;overflow-y:auto">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">
      <b style="font-size:1.05rem">📦 <?= $edit?'Modifier':'Ajouter' ?> un consommable</b>
      <button onclick="document.getElementById('modal-add').style.display='none'" style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--text2)">✕</button>
    </div>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= $edit['id']??0 ?>">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div style="grid-column:1/-1"><label class="form-label">Nom *</label><input type="text" name="nom" required value="<?= h($edit['nom']??'') ?>" class="form-control"></div>
        <div><label class="form-label">Catégorie</label>
          <select name="categorie_id" class="form-control">
            <option value="">—</option>
            <?php foreach ($categories as $cat): ?>
            <option value="<?= $cat['id'] ?>" <?= ($edit['categorie_id']??0)==$cat['id']?'selected':'' ?>><?= h($cat['icone'].' '.$cat['nom']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div><label class="form-label">Référence</label><input type="text" name="reference" value="<?= h($edit['reference']??'') ?>" class="form-control"></div>
        <div><label class="form-label">N° Lot</label><input type="text" name="numero_lot" value="<?= h($edit['numero_lot']??'') ?>" class="form-control"></div>
        <div><label class="form-label">Quantité</label><input type="number" name="quantite" value="<?= h($edit['quantite']??0) ?>" min="0" class="form-control"></div>
        <div><label class="form-label">Quantité min</label><input type="number" name="quantite_min" value="<?= h($edit['quantite_min']??0) ?>" min="0" class="form-control"></div>
        <div><label class="form-label">Unité</label><input type="text" name="unite" value="<?= h($edit['unite']??'unité') ?>" class="form-control"></div>
        <div><label class="form-label">Péremption</label><input type="date" name="date_peremption" value="<?= h($edit['date_peremption']??'') ?>" class="form-control"></div>
        <div style="grid-column:1/-1"><label class="form-label">Localisation</label><input type="text" name="localisation" value="<?= h($edit['localisation']??'') ?>" class="form-control" placeholder="Armoire SSE médicaments…"></div>
        <div style="grid-column:1/-1"><label class="form-label">Notes</label><textarea name="notes" rows="2" class="form-control"><?= h($edit['notes']??'') ?></textarea></div>
      </div>
      <div style="display:flex;gap:8px;margin-top:16px">
        <button type="submit" class="btn btn-primary">💾 Enregistrer</button>
        <button type="button" onclick="document.getElementById('modal-add').style.display='none'" class="btn btn-ghost">Annuler</button>
      </div>
    </form>
  </div>
</div>

<script>
const csrfToken = '<?= csrf_token() ?>';
const qtyState = {};

function ajusterQty(id, delta) {
  const el = document.getElementById('qty-' + id);
  const nv = Math.max(0, (parseInt(el.textContent)||0) + delta);
  el.textContent = nv;
  clearTimeout(qtyState[id]);
  qtyState[id] = setTimeout(() => {
    const fd = new FormData();
    fd.append('action','adjust_qty'); fd.append('id',id);
    fd.append('quantite',nv); fd.append('csrf_token',csrfToken);
    fetch('consommables.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
      if (d.ok) {
        const row = el.closest('tr');
        if (row) { row.style.background='rgba(16,185,129,.08)'; setTimeout(()=>row.style.background='',1000); }
      }
    });
  }, 600);
}

function filtrerConso(q) {
  q = q.toLowerCase().trim();
  const rows = document.querySelectorAll('.conso-row');
  let n = 0;
  rows.forEach(r => {
    const show = !q || r.dataset.search.includes(q);
    r.style.display = show ? '' : 'none';
    if (show) n++;
  });
  const cnt = document.getElementById('conso-count');
  if (cnt) cnt.textContent = n + ' produit(s) affiché(s)';
}

let _sc=-1, _sd=1;
function trierConso(col) {
  const tbody = document.querySelector('#conso-table tbody');
  const rows = Array.from(tbody.querySelectorAll('tr'));
  if (_sc===col) _sd*=-1; else { _sc=col; _sd=1; }
  rows.sort((a,b) => {
    const ta=a.querySelectorAll('td')[col]?.textContent.trim().toLowerCase()||'';
    const tb=b.querySelectorAll('td')[col]?.textContent.trim().toLowerCase()||'';
    return ta.localeCompare(tb,'fr')*_sd;
  });
  rows.forEach(r=>tbody.appendChild(r));
}
</script>

<?php require_once '../includes/footer.php'; ?>

<?php
/**
 * Cadurix v5 — Gestion des organisations et sites
 * Accès : Super Admin uniquement
 */
if (!defined('ADMIN_HUB')) require_once '../includes/auth.php';

if (!defined('ADMIN_HUB')) headers_securite();
if (!isAdmin()) { accessDenied(); }

$message = '';
$erreur  = '';
$action  = $_GET['action'] ?? 'liste';
$id      = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ----------------------------------------------------------------
//  Traitement des formulaires
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $type_form = $_POST['type_form'] ?? '';

    // --- Créer / modifier organisation ---
    if ($type_form === 'organisation') {
        $nom     = trim($_POST['nom'] ?? '');
        $type    = $_POST['type'] ?? 'autre';
        $adresse = trim($_POST['adresse'] ?? '');
        $email   = trim($_POST['email'] ?? '');
        $tel     = trim($_POST['telephone'] ?? '');
        $org_id  = (int)($_POST['org_id'] ?? 0);
        // Logo (data URI base64) : validation format + taille
        $logo = trim($_POST['logo'] ?? '');
        if ($logo !== '' && (!preg_match('#^data:image/(png|jpe?g|gif|webp|svg\+xml);base64,#', $logo) || strlen($logo) > 1400000)) $logo = '';
        $logoRemove = isset($_POST['logo_remove']);

        if (empty($nom)) {
            $erreur = 'Le nom de l\'organisation est obligatoire.';
        } else {
            if ($org_id > 0) {
                if ($logoRemove) { $logoVal = null; }
                elseif ($logo !== '') { $logoVal = $logo; }
                else { $cur = $pdo->prepare("SELECT logo FROM organisations WHERE id=?"); $cur->execute([$org_id]); $logoVal = $cur->fetchColumn() ?: null; }
                $stmt = $pdo->prepare("UPDATE organisations SET nom=?, type=?, adresse=?, email=?, telephone=?, logo=? WHERE id=?");
                $stmt->execute([$nom, $type, $adresse, $email, $tel, $logoVal, $org_id]);
                audit_log($pdo, 'MODIF_ORGANISATION', 'organisations', $org_id, null, compact('nom','type'));
                $message = "Organisation mise à jour.";
            } else {
                $stmt = $pdo->prepare("INSERT INTO organisations (nom, type, adresse, email, telephone, logo) VALUES (?,?,?,?,?,?)");
                $stmt->execute([$nom, $type, $adresse, $email, $tel, $logo !== '' ? $logo : null]);
                $new_id = $pdo->lastInsertId();
                audit_log($pdo, 'CREATION_ORGANISATION', 'organisations', $new_id, null, compact('nom','type'));
                $message = "Organisation créée.";
            }
        }
    }

    // --- Créer / modifier site ---
    if ($type_form === 'site') {
        $nom        = trim($_POST['nom'] ?? '');
        $org_id     = (int)($_POST['organisation_id'] ?? 0);
        $parent_id  = (int)($_POST['site_parent_id'] ?? 0) ?: null;
        $description= trim($_POST['description'] ?? '');
        $adresse    = trim($_POST['adresse'] ?? '');
        $site_id    = (int)($_POST['site_id'] ?? 0);

        if (empty($nom) || !$org_id) {
            $erreur = 'Nom et organisation sont obligatoires.';
        } else {
            if ($site_id > 0) {
                $stmt = $pdo->prepare("UPDATE sites SET nom=?, organisation_id=?, site_parent_id=?, description=?, adresse=? WHERE id=?");
                $stmt->execute([$nom, $org_id, $parent_id, $description, $adresse, $site_id]);
                audit_log($pdo, 'MODIF_SITE', 'sites', $site_id);
                $message = "Site mis à jour.";
            } else {
                $stmt = $pdo->prepare("INSERT INTO sites (nom, organisation_id, site_parent_id, description, adresse) VALUES (?,?,?,?,?)");
                $stmt->execute([$nom, $org_id, $parent_id, $description, $adresse]);
                $new_id = $pdo->lastInsertId();
                audit_log($pdo, 'CREATION_SITE', 'sites', $new_id, null, compact('nom'));
                $message = "Site créé.";
            }
        }
    }

    // --- Supprimer organisation (Super Admin uniquement) ---
    if ($type_form === 'delete_org') {
        $org_id = (int)($_POST['org_id'] ?? 0);
        // Vérifier que l'organisation est vide
        $nb_sites = $pdo->prepare("SELECT COUNT(*) FROM sites WHERE organisation_id=?");
        $nb_sites->execute([$org_id]);
        if ($nb_sites->fetchColumn() > 0) {
            $erreur = "Impossible de supprimer — cette organisation contient des sites. Supprimez d'abord les sites.";
        } else {
            $stmt = $pdo->prepare("SELECT nom FROM organisations WHERE id=?");
            $stmt->execute([$org_id]);
            $org_nom = $stmt->fetchColumn();
            $pdo->prepare("DELETE FROM organisations WHERE id=?")->execute([$org_id]);
            audit_log($pdo, 'SUPPRESSION_ORGANISATION', 'organisations', $org_id, ['nom' => $org_nom]);
            $message = "Organisation « $org_nom » supprimée définitivement.";
        }
    }

    // --- Supprimer site (Super Admin uniquement) ---
    if ($type_form === 'delete_site') {
        $site_id = (int)($_POST['site_id'] ?? 0);
        // Vérifier que le site est vide
        $nb_empl = $pdo->prepare("SELECT COUNT(*) FROM emplacements WHERE site_id=?");
        $nb_empl->execute([$site_id]);
        $nb_users = $pdo->prepare("SELECT COUNT(*) FROM user_site_permissions WHERE site_id=?");
        $nb_users->execute([$site_id]);
        if ($nb_empl->fetchColumn() > 0) {
            $erreur = "Impossible de supprimer — ce site contient des emplacements. Supprimez d'abord les emplacements.";
        } else {
            $stmt = $pdo->prepare("SELECT nom FROM sites WHERE id=?");
            $stmt->execute([$site_id]);
            $site_nom = $stmt->fetchColumn();
            $pdo->prepare("DELETE FROM user_site_permissions WHERE site_id=?")->execute([$site_id]);
            $pdo->prepare("DELETE FROM sites WHERE id=?")->execute([$site_id]);
            audit_log($pdo, 'SUPPRESSION_SITE', 'sites', $site_id, ['nom' => $site_nom]);
            $message = "Site « $site_nom » supprimé définitivement.";
        }
    }

    // --- Toggle actif organisation ---
    if ($type_form === 'toggle_org') {
        $org_id = (int)($_POST['org_id'] ?? 0);
        $pdo->prepare("UPDATE organisations SET actif = 1 - actif WHERE id = ?")->execute([$org_id]);
        audit_log($pdo, 'TOGGLE_ORGANISATION', 'organisations', $org_id);
        $message = "Statut mis à jour.";
    }

    if ($type_form === 'toggle_site') {
        $site_id = (int)($_POST['site_id'] ?? 0);
        $pdo->prepare("UPDATE sites SET actif = 1 - actif WHERE id = ?")->execute([$site_id]);
        audit_log($pdo, 'TOGGLE_SITE', 'sites', $site_id);
        $message = "Statut mis à jour.";
    }
}

// ----------------------------------------------------------------
//  Données
// ----------------------------------------------------------------
$organisations = $pdo->query("SELECT * FROM organisations ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);

$sites = $pdo->query("
    SELECT s.*, o.nom AS org_nom, p.nom AS parent_nom,
           (SELECT COUNT(*) FROM emplacements e WHERE e.site_id = s.id) AS nb_emplacements,
           (SELECT COUNT(*) FROM user_site_permissions usp WHERE usp.site_id = s.id) AS nb_users
    FROM sites s
    JOIN organisations o ON o.id = s.organisation_id
    LEFT JOIN sites p ON p.id = s.site_parent_id
    ORDER BY o.nom, s.nom
")->fetchAll(PDO::FETCH_ASSOC);

$org_edit = null;
$site_edit = null;
if ($action === 'edit_org' && $id) {
    $stmt = $pdo->prepare("SELECT * FROM organisations WHERE id = ?");
    $stmt->execute([$id]);
    $org_edit = $stmt->fetch(PDO::FETCH_ASSOC);
}
if ($action === 'edit_site' && $id) {
    $stmt = $pdo->prepare("SELECT * FROM sites WHERE id = ?");
    $stmt->execute([$id]);
    $site_edit = $stmt->fetch(PDO::FETCH_ASSOC);
}

$types_org = ['hopital' => '🏥 Hôpital', 'sdis' => '🚒 SDIS / Pompiers', 'clinique' => '🏨 Clinique', 'pharmacie' => '💊 Pharmacie', 'autre' => '🏢 Autre'];
?>
<?php if (!defined('ADMIN_HUB')) { require_once __DIR__ . '/../includes/header.php'; } ?>
<style>
/* Sites.php — styles locaux (tabs, badges, tree) */
.page-header { display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem; }
.page-title  { font-size:1.4rem;font-weight:700; }
.page-title span { color:var(--primary); }
.breadcrumb  { font-size:.8rem;color:var(--text3);margin-top:.2rem; }

.tabs { display:flex;gap:.25rem;margin-bottom:1.5rem;border-bottom:2px solid var(--border); }
.tab  { padding:.65rem 1.25rem;cursor:pointer;font-size:.875rem;font-weight:600;
        color:var(--text2);border-bottom:2px solid transparent;margin-bottom:-2px;
        transition:all .15s;border-radius:8px 8px 0 0; }
.tab:hover  { background:var(--glass);color:var(--text); }
.tab.active { color:var(--primary);border-bottom-color:var(--primary); }
.tab-content { display:none; }
.tab-content.active { display:block; }

.grid-2 { display:grid;grid-template-columns:1fr 1fr;gap:1.5rem; }
@media(max-width:900px){ .grid-2{grid-template-columns:1fr;} }

.card-header { display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem; }
.card-title  { font-size:.95rem;font-weight:700; }

.form-group  { margin-bottom:1rem; }
.form-label  { display:block;font-size:.78rem;font-weight:600;color:var(--text3);
               margin-bottom:.35rem;text-transform:uppercase;letter-spacing:.05em; }
.form-control{ width:100%;padding:.6rem .9rem;background:var(--glass);
               border:1px solid var(--border);border-radius:8px;
               color:var(--text);font-family:inherit;font-size:.9rem;transition:border .15s; }
.form-control:focus { outline:none;border-color:var(--primary); }
.form-row { display:grid;grid-template-columns:1fr 1fr;gap:1rem; }

.alert { padding:.875rem 1.25rem;border-radius:9px;margin-bottom:1.25rem;font-size:.875rem; }
.alert-success { background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.3);color:#6ee7b7; }
.alert-danger  { background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.3); color:#fca5a5; }

.badge-active   { background:rgba(16,185,129,.15);color:#6ee7b7;padding:.2rem .65rem;border-radius:99px;font-size:.75rem;font-weight:600; }
.badge-inactive { background:rgba(100,116,139,.15);color:var(--text3);padding:.2rem .65rem;border-radius:99px;font-size:.75rem;font-weight:600; }
.badge-blue     { background:rgba(59,130,246,.15);color:#93c5fd;padding:.2rem .65rem;border-radius:99px;font-size:.75rem;font-weight:600; }

.btn-success { background:#10b981;color:#fff;border:none; }
.btn-danger  { background:#ef4444;color:#fff;border:none; }

table { width:100%;border-collapse:collapse;font-size:.875rem; }
th { padding:.6rem 1rem;text-align:left;color:var(--text3);font-weight:600;
     font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;
     border-bottom:1px solid var(--border); }
td { padding:.75rem 1rem;border-bottom:1px solid var(--border);vertical-align:middle; }
tr:hover td { background:var(--glass); }

.org-tree { padding-left:1.5rem;border-left:2px solid var(--border);margin-left:.5rem; }
.org-item { padding:.5rem 0; }
.org-badge { font-size:.75rem;padding:.15rem .5rem;border-radius:.25rem;
             background:var(--glass);color:var(--text3);margin-left:.5rem; }
</style>

<div class="page-header">
  <div>
    <div class="page-title">🏢 Organisations <span>&amp;</span> Sites</div>
    <div class="breadcrumb">Administration → Gestion multisite</div>
  </div>
</div>

<?php if ($message): ?><div class="alert alert-success">✓ <?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($erreur):  ?><div class="alert alert-danger">✗ <?= htmlspecialchars($erreur)  ?></div><?php endif; ?>

<div class="tabs">
  <div class="tab <?= $action !== 'new_site' && $action !== 'edit_site' ? 'active' : '' ?>" onclick="switchTab('organisations')">🏢 Organisations</div>
  <div class="tab <?= $action === 'new_site' || $action === 'edit_site' ? 'active' : '' ?>" onclick="switchTab('sites')">🗺 Sites</div>
  <div class="tab" onclick="switchTab('arbre')">🌳 Vue arbre</div>
</div>

<!-- TAB ORGANISATIONS -->
<div class="tab-content <?= $action !== 'new_site' && $action !== 'edit_site' ? 'active' : '' ?>" id="tab-organisations">
  <div class="grid-2">
    <!-- Formulaire organisation -->
    <div class="card">
      <div class="card-header">
        <div class="card-title"><?= $org_edit ? '✏️ Modifier' : '➕ Nouvelle' ?> organisation</div>
      </div>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="type_form" value="organisation">
        <?php if ($org_edit): ?>
        <input type="hidden" name="org_id" value="<?= $org_edit['id'] ?>">
        <?php endif; ?>
        <div class="form-group">
          <label class="form-label">Nom *</label>
          <input type="text" name="nom" class="form-control" required value="<?= htmlspecialchars($org_edit['nom'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Type</label>
          <select name="type" class="form-control">
            <?php foreach ($types_org as $k => $v): ?>
            <option value="<?= $k ?>" <?= ($org_edit['type'] ?? 'autre') === $k ? 'selected' : '' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Adresse</label>
          <input type="text" name="adresse" class="form-control" value="<?= htmlspecialchars($org_edit['adresse'] ?? '') ?>">
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($org_edit['email'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Téléphone</label>
            <input type="text" name="telephone" class="form-control" value="<?= htmlspecialchars($org_edit['telephone'] ?? '') ?>">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">🖼️ Logo de l'organisation (impressions : registre stupéfiants, déclarations)</label>
          <?php if (!empty($org_edit['logo'])): ?>
          <div style="margin-bottom:8px"><img src="<?= htmlspecialchars($org_edit['logo']) ?>" alt="logo" style="max-height:70px;border:1px solid var(--border);border-radius:8px;padding:4px;background:#fff"></div>
          <label style="display:inline-flex;align-items:center;gap:6px;font-size:13px;margin-bottom:8px"><input type="checkbox" name="logo_remove" value="1"> Supprimer le logo actuel</label>
          <?php endif; ?>
          <input type="file" accept="image/*" class="form-control" onchange="orgLogoPick(this)">
          <input type="hidden" name="logo" id="org-logo-data">
          <div id="org-logo-preview" style="margin-top:8px"></div>
          <div style="font-size:11.5px;color:var(--text3);margin-top:4px">PNG/JPG, petit fichier (max ~1 Mo). Apparaîtra en tête des documents imprimés.</div>
        </div>
        <script>
        function orgLogoPick(inp){
          var f=inp.files&&inp.files[0]; if(!f) return;
          if(f.size>1000000){ alert('Image trop lourde (max ~1 Mo).'); inp.value=''; return; }
          var rd=new FileReader();
          rd.onload=function(){ document.getElementById('org-logo-data').value=rd.result; document.getElementById('org-logo-preview').innerHTML='<img src="'+rd.result+'" style="max-height:70px;border:1px solid #ccc;border-radius:8px;padding:4px;background:#fff">'; };
          rd.readAsDataURL(f);
        }
        </script>
        <button type="submit" class="btn btn-primary"><?= $org_edit ? '💾 Enregistrer' : '➕ Créer' ?></button>
        <?php if ($org_edit): ?>
        <a href="sites.php" class="btn btn-ghost">Annuler</a>
        <?php endif; ?>
      </form>
    </div>

    <!-- Liste organisations -->
    <div class="card">
      <div class="card-header">
        <div class="card-title">📋 Liste des organisations (<?= count($organisations) ?>)</div>
      </div>
      <table>
        <tr><th>Nom</th><th>Type</th><th>Statut</th><th>Actions</th></tr>
        <?php foreach ($organisations as $org): ?>
        <tr>
          <td><strong><?= htmlspecialchars($org['nom']) ?></strong></td>
          <td><?= $types_org[$org['type']] ?? $org['type'] ?></td>
          <td>
            <span class="badge <?= $org['actif'] ? 'badge-active' : 'badge-inactive' ?>">
              <?= $org['actif'] ? 'Actif' : 'Inactif' ?>
            </span>
          </td>
          <td>
            <a href="?action=edit_org&id=<?= $org['id'] ?>" class="btn btn-ghost btn-sm">✏️</a>
            <form method="POST" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="type_form" value="toggle_org">
              <input type="hidden" name="org_id" value="<?= $org['id'] ?>">
              <button type="submit" class="btn btn-ghost btn-sm"><?= $org['actif'] ? '🔴' : '🟢' ?></button>
            </form>
            <form method="POST" style="display:inline"
                  onsubmit="return confirm('⚠️ Supprimer définitivement « <?= htmlspecialchars($org['nom'], ENT_QUOTES) ?> » ?\n\nCette action est IRRÉVERSIBLE.\nL\'organisation doit être vide (aucun site).')">
              <?= csrf_field() ?>
              <input type="hidden" name="type_form" value="delete_org">
              <input type="hidden" name="org_id" value="<?= $org['id'] ?>">
              <button type="submit" class="btn btn-danger btn-sm" title="Supprimer définitivement">🗑️</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>
  </div>
</div>

<!-- TAB SITES -->
<div class="tab-content <?= $action === 'new_site' || $action === 'edit_site' ? 'active' : '' ?>" id="tab-sites">
  <div class="grid-2">
    <!-- Formulaire site -->
    <div class="card">
      <div class="card-header">
        <div class="card-title"><?= $site_edit ? '✏️ Modifier' : '➕ Nouveau' ?> site</div>
      </div>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="type_form" value="site">
        <?php if ($site_edit): ?>
        <input type="hidden" name="site_id" value="<?= $site_edit['id'] ?>">
        <?php endif; ?>
        <div class="form-group">
          <label class="form-label">Organisation *</label>
          <select name="organisation_id" class="form-control" required>
            <option value="">— Choisir —</option>
            <?php foreach ($organisations as $org): if (!$org['actif']) continue; ?>
            <option value="<?= $org['id'] ?>" <?= ($site_edit['organisation_id'] ?? '') == $org['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($org['nom']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Nom du site *</label>
          <input type="text" name="nom" class="form-control" required value="<?= htmlspecialchars($site_edit['nom'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Site parent (optionnel)</label>
          <select name="site_parent_id" class="form-control">
            <option value="">— Aucun (site principal) —</option>
            <?php foreach ($sites as $s): ?>
            <option value="<?= $s['id'] ?>" <?= ($site_edit['site_parent_id'] ?? '') == $s['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($s['org_nom'] . ' → ' . $s['nom']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Description</label>
          <input type="text" name="description" class="form-control" value="<?= htmlspecialchars($site_edit['description'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Adresse</label>
          <input type="text" name="adresse" class="form-control" value="<?= htmlspecialchars($site_edit['adresse'] ?? '') ?>">
        </div>
        <button type="submit" class="btn btn-primary"><?= $site_edit ? '💾 Enregistrer' : '➕ Créer' ?></button>
        <?php if ($site_edit): ?><a href="sites.php?tab=sites" class="btn btn-ghost">Annuler</a><?php endif; ?>
      </form>
    </div>

    <!-- Liste sites -->
    <div class="card">
      <div class="card-header">
        <div class="card-title">📋 Liste des sites (<?= count($sites) ?>)</div>
      </div>
      <table>
        <tr><th>Site</th><th>Organisation</th><th>👥</th><th>📦</th><th>Actions</th></tr>
        <?php foreach ($sites as $s): ?>
        <tr>
          <td>
            <?= $s['parent_nom'] ? '<span style="color:var(--text3);font-size:.75rem">↳ </span>' : '' ?>
            <strong><?= htmlspecialchars($s['nom']) ?></strong>
            <span class="badge <?= $s['actif'] ? 'badge-active' : 'badge-inactive' ?>"><?= $s['actif'] ? 'actif' : 'inactif' ?></span>
          </td>
          <td style="font-size:.8rem;color:var(--text3)"><?= htmlspecialchars($s['org_nom']) ?></td>
          <td><span class="badge badge-blue"><?= $s['nb_users'] ?></span></td>
          <td><span class="badge badge-blue"><?= $s['nb_emplacements'] ?></span></td>
          <td>
            <a href="?action=edit_site&id=<?= $s['id'] ?>" class="btn btn-ghost btn-sm">✏️</a>
            <form method="POST" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="type_form" value="toggle_site">
              <input type="hidden" name="site_id" value="<?= $s['id'] ?>">
              <button class="btn btn-ghost btn-sm"><?= $s['actif'] ? '🔴' : '🟢' ?></button>
            </form>
            <form method="POST" style="display:inline"
                  onsubmit="return confirm('⚠️ Supprimer définitivement « <?= htmlspecialchars($s['nom'], ENT_QUOTES) ?> » ?\n\nCette action est IRRÉVERSIBLE.\nLe site doit être vide (aucun emplacement).')">
              <?= csrf_field() ?>
              <input type="hidden" name="type_form" value="delete_site">
              <input type="hidden" name="site_id" value="<?= $s['id'] ?>">
              <button type="submit" class="btn btn-danger btn-sm" title="Supprimer définitivement">🗑️</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>
  </div>
</div>

<!-- TAB ARBRE -->
<div class="tab-content" id="tab-arbre">
  <div class="card">
    <div class="card-header">
      <div class="card-title">🌳 Vue arborescente</div>
      <div style="display:flex;gap:8px">
        <button class="btn btn-ghost btn-sm" onclick="toutDeployer()">⊞ Tout déployer</button>
        <button class="btn btn-ghost btn-sm" onclick="toutReplier()">⊟ Tout replier</button>
      </div>
    </div>
    <?php
    // Charger tous les emplacements
    $empls_all = $pdo->query("
        SELECT e.*,
               (SELECT COUNT(*) FROM produits p WHERE p.emplacement_id=e.id AND p.statut='actif') AS nb_produits,
               (SELECT COUNT(*) FROM produits p WHERE p.emplacement_id=e.id AND p.statut='actif' AND p.date_peremption < CURDATE()) AS nb_perime,
               (SELECT COUNT(*) FROM produits p WHERE p.emplacement_id=e.id AND p.statut='actif' AND p.date_peremption >= CURDATE() AND DATEDIFF(p.date_peremption,CURDATE())<=7) AS nb_critique
        FROM emplacements e
        WHERE e.actif=1
        ORDER BY e.site_id, e.parent_id, e.nom
    ")->fetchAll(PDO::FETCH_ASSOC);

    function buildEmplArbre(array $empls, $parentId = null): array {
        $tree = [];
        foreach ($empls as $e) {
            if ($e['parent_id'] == $parentId) {
                $e['children'] = buildEmplArbre($empls, $e['id']);
                $tree[] = $e;
            }
        }
        return $tree;
    }

    $nodeId = 0;
    function renderEmplArbre(array $tree, int $depth = 0): void {
        global $nodeId;
        foreach ($tree as $e) {
            $nodeId++;
            $nid   = 'node-'.$nodeId;
            $icon  = $e['chaine_froid'] ? '❄️' : '📦';
            $hasChildren = !empty($e['children']);
            if ($e['nb_perime'] > 0)        $alert = '<span style="color:#9ca3af;font-size:.72rem">⚫ '.$e['nb_perime'].' périmé(s)</span>';
            elseif ($e['nb_critique'] > 0)  $alert = '<span style="color:#ef4444;font-size:.72rem">🔴 '.$e['nb_critique'].' critique(s)</span>';
            else                            $alert = '<span style="color:#10b981;font-size:.72rem">✅ OK</span>';
            $marginLeft = $depth * 24;
            ?>
            <div class="tree-node">
              <div class="tree-row" onclick="<?= $hasChildren ? "toggleNode('$nid')" : '' ?>"
                   style="cursor:<?= $hasChildren ? 'pointer' : 'default' ?>;padding:5px 8px;margin-left:<?= $marginLeft ?>px;border-left:2px solid rgba(255,255,255,<?= max(0.04, 0.15 - $depth*0.03) ?>);padding-left:10px;margin-bottom:2px;border-radius:0 6px 6px 0">
                <?php if ($hasChildren): ?>
                <span class="tree-arrow" id="arrow-<?= $nid ?>">▶</span>
                <?php else: ?>
                <span style="width:16px;display:inline-block;color:var(--text3);font-size:.6rem">—</span>
                <?php endif; ?>
                <span><?= $icon ?></span>
                <span style="font-size:.85rem;font-weight:<?= $depth===0?'600':'400' ?>;flex:1"><?= htmlspecialchars($e['nom']) ?></span>
                <span style="font-size:.72rem;color:var(--text3)">💊 <?= $e['nb_produits'] ?></span>
                <?= $alert ?>
                <a href="/products.php?emplacement=<?= $e['id'] ?>" style="font-size:.72rem;color:var(--text3);margin-left:6px;text-decoration:none" onclick="event.stopPropagation()" title="Voir les produits">→</a>
              </div>
              <?php if ($hasChildren): ?>
              <div class="tree-children" id="<?= $nid ?>" style="display:none">
                <?php renderEmplArbre($e['children'], $depth + 1); ?>
              </div>
              <?php endif; ?>
            </div>
            <?php
        }
    }

    // Grouper emplacements par site
    $empls_par_site = [];
    foreach ($empls_all as $e) {
        $empls_par_site[$e['site_id']][] = $e;
    }

    $tree_org = [];
    foreach ($organisations as $org) {
        $tree_org[$org['id']] = ['org' => $org, 'sites' => []];
    }
    foreach ($sites as $s) {
        $tree_org[$s['organisation_id']]['sites'][] = $s;
    }

    if (empty($organisations)): ?>
    <div style="text-align:center;padding:3rem;color:var(--text3)">
      Aucune organisation. <a href="?action=new_org" style="color:var(--primary)">Créer →</a>
    </div>
    <?php else:
    foreach ($tree_org as $item):
        $org  = $item['org'];
        $orgId = 'org-'.$org['id'];
    ?>
    <div class="tree-block" style="margin-bottom:10px;border:1px solid var(--border);border-radius:10px;overflow:hidden">

      <!-- Organisation (toujours visible) -->
      <div class="tree-row tree-org" onclick="toggleNode('<?= $orgId ?>')" style="cursor:pointer;padding:12px 16px;background:rgba(59,130,246,.08);border-bottom:1px solid var(--border)">
        <span class="tree-arrow" id="arrow-<?= $orgId ?>">▶</span>
        <span style="font-size:1rem"><?= $types_org[$org['type']] ?? '🏢' ?></span>
        <span style="font-weight:700;font-size:.95rem;flex:1"><?= htmlspecialchars($org['nom']) ?></span>
        <span class="badge <?= $org['actif'] ? 'badge-active' : 'badge-inactive' ?>"><?= $org['actif'] ? 'Actif' : 'Inactif' ?></span>
        <span style="font-size:.75rem;color:var(--text3);margin-left:8px"><?= count($item['sites']) ?> site(s)</span>
        <a href="?action=edit_org&id=<?= $org['id'] ?>" style="font-size:.8rem;color:var(--text3);margin-left:8px" onclick="event.stopPropagation()">✏️</a>
      </div>

      <!-- Sites (repliés par défaut) -->
      <div class="tree-children" id="<?= $orgId ?>" style="display:none;padding:8px 12px">
        <?php if (empty($item['sites'])): ?>
        <div style="padding:8px;color:var(--text3);font-size:.82rem;font-style:italic">
          Aucun site — <a href="#" onclick="switchTab('sites');return false" style="color:var(--primary)">Créer →</a>
        </div>
        <?php else:
        foreach ($item['sites'] as $s):
            $siteId = 'site-'.$s['id'];
            $empls_site  = $empls_par_site[$s['id']] ?? [];
            $arbre_empls = buildEmplArbre($empls_site);
            $hasEmpls    = !empty($arbre_empls);
        ?>
        <div style="margin-bottom:6px;border:1px solid rgba(255,255,255,.06);border-radius:8px;overflow:hidden">

          <!-- Site -->
          <div class="tree-row" onclick="<?= $hasEmpls ? "toggleNode('$siteId')" : '' ?>"
               style="cursor:<?= $hasEmpls ? 'pointer' : 'default' ?>;padding:9px 12px;background:rgba(255,255,255,.03)">
            <?php if ($hasEmpls): ?>
            <span class="tree-arrow" id="arrow-<?= $siteId ?>">▶</span>
            <?php else: ?>
            <span style="width:16px;display:inline-block"></span>
            <?php endif; ?>
            <span>🗺</span>
            <span style="font-weight:600;font-size:.875rem;flex:1"><?= htmlspecialchars($s['nom']) ?></span>
            <span class="badge <?= $s['actif'] ? 'badge-active' : 'badge-inactive' ?>" style="font-size:.7rem"><?= $s['actif'] ? 'Actif' : 'Inactif' ?></span>
            <span style="font-size:.72rem;color:var(--text3);margin-left:6px">👥 <?= $s['nb_users'] ?></span>
            <span style="font-size:.72rem;color:var(--text3);margin-left:6px">📍 <?= $s['nb_emplacements'] ?></span>
            <a href="?action=edit_site&id=<?= $s['id'] ?>" style="font-size:.8rem;color:var(--text3);margin-left:6px" onclick="event.stopPropagation()">✏️</a>
          </div>

          <!-- Emplacements -->
          <?php if ($hasEmpls): ?>
          <div class="tree-children" id="<?= $siteId ?>" style="display:none;padding:6px 8px">
            <?php renderEmplArbre($arbre_empls); ?>
          </div>
          <?php else: ?>
          <div style="padding:6px 12px;color:var(--text3);font-size:.78rem;font-style:italic">Aucun emplacement</div>
          <?php endif; ?>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<style>
.tree-row {
  display: flex;
  align-items: center;
  gap: 8px;
}
.tree-arrow {
  font-size: .65rem;
  color: var(--text3);
  width: 16px;
  flex-shrink: 0;
  transition: transform .2s;
  display: inline-block;
}
.tree-arrow.open {
  transform: rotate(90deg);
}
.tree-node {
  margin-bottom: 3px;
}
.tree-row:hover {
  background: rgba(255,255,255,.03);
  border-radius: 6px;
}
</style>

<script>
function toggleNode(id) {
  const el    = document.getElementById(id);
  const arrow = document.getElementById('arrow-' + id);
  if (!el) return;
  const open = el.style.display !== 'none';
  el.style.display = open ? 'none' : 'block';
  if (arrow) arrow.classList.toggle('open', !open);
}

function toutDeployer() {
  document.querySelectorAll('.tree-children').forEach(el => {
    el.style.display = 'block';
  });
  document.querySelectorAll('.tree-arrow').forEach(el => {
    el.classList.add('open');
  });
}

function toutReplier() {
  document.querySelectorAll('.tree-children').forEach(el => {
    el.style.display = 'none';
  });
  document.querySelectorAll('.tree-arrow').forEach(el => {
    el.classList.remove('open');
  });
}
</script>

</div><!-- .content -->
</div><!-- .layout -->

<script>
function switchTab(name) {
  document.querySelectorAll('.tab').forEach((t,i) => {
    const tabs = ['organisations','sites','arbre'];
    t.classList.toggle('active', tabs[i] === name);
  });
  document.querySelectorAll('.tab-content').forEach((c,i) => {
    const ids = ['tab-organisations','tab-sites','tab-arbre'];
    c.classList.toggle('active', c.id === 'tab-' + name);
  });
}
// Auto-ouvrir l'onglet selon l'action
<?php if ($action === 'new_site' || $action === 'edit_site'): ?>
switchTab('sites');
<?php endif; ?>
</script>
<?php if (!defined('ADMIN_HUB')): ?>
</body>
</html>
<?php endif; ?>
<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.

require_once '../includes/auth.php';
require_once __DIR__ . '/../config/admin_tabs.php';
requireAdmin();
$pageTitle = 'Administration';

$is_super = (bool)($_SESSION['super_admin'] ?? false);
$is_admin = isAdmin();
$is_gest  = isGestionnaire();

// Onglets admin : source unique (config/admin_tabs.php)
$default_access = array_map(fn($t) => $t['defaut'], admin_tabs_definis());

$access_config = $default_access;
try {
  $row = $pdo->query("SELECT valeur FROM parametres WHERE cle='admin_tab_access'")->fetch(PDO::FETCH_ASSOC);
  if ($row) {
    $saved = json_decode($row['valeur'], true) ?: [];
    // Fusion : les nouveaux onglets du default_access s'ajoutent automatiquement
    // même si la BDD a une config plus ancienne
    foreach ($default_access as $t => $roles) {
      if (!isset($saved[$t])) $saved[$t] = $roles;
    }
    $access_config = $saved;
  }
} catch(Exception $e) {}

// Ne garder que les onglets qui existent encore (supprime les orphelins d'anciennes configs, ex. 'logs')
$access_config = array_intersect_key($access_config, $default_access);

if ($_SERVER['REQUEST_METHOD']==="POST" && isset($_POST['save_access']) && $is_super) {
  csrf_check();
  $new = [];
  foreach (array_keys($default_access) as $t) {
    $new[$t] = $_POST['access'][$t] ?? [];
    if (!in_array('super_admin',$new[$t])) $new[$t][] = 'super_admin';
  }
  $val = json_encode($new);
  $pdo->prepare("INSERT INTO parametres (cle,valeur) VALUES ('admin_tab_access',?) ON DUPLICATE KEY UPDATE valeur=?")->execute([$val,$val]);
  $access_config = $new;
  flashSuccess('Accès mis à jour.');
}

$my_role = $is_super ? 'super_admin' : ($is_admin ? 'admin' : ($is_gest ? 'gestionnaire' : 'lecteur'));
$tab_access = [];
foreach ($access_config as $t => $roles) $tab_access[$t] = in_array($my_role, $roles);

$tab = $_GET['tab'] ?? $_COOKIE['admin_last_tab'] ?? 'dashboard';
$tabs_valides = array_keys(array_filter($tab_access));
if (!in_array($tab, $tabs_valides)) $tab = $tabs_valides[0] ?? 'dashboard';

$tab_files = [];
foreach (admin_tabs_definis() as $__k => $__t) $tab_files[$__k] = __DIR__ . '/' . $__t['file'];

$tab_meta = array_map(fn($t) => ['icon'=>$t['icon'], 'label'=>$t['label']], admin_tabs_definis());

// Groupes d'onglets — séparateur avant chaque nouveau groupe
$tab_groups = [];
foreach (admin_tabs_definis() as $__k => $__t) $tab_groups[$__t['group']][] = $__k;

$nb_ban = 0; $lockdown = 0;
try {
  $nb_ban   = (int)$pdo->query("SELECT COUNT(*) FROM security_ips WHERE statut='banni'")->fetchColumn();
  $lockdown = (int)$pdo->query("SELECT COUNT(*) FROM security_lockdown WHERE actif=1")->fetchColumn();
} catch(Exception $e) {}

// Supprime d'éventuels onglets orphelins d'anciennes configs BDD (ex: 'logs')
$access_config = array_intersect_key($access_config, $default_access);

// Toggle scan photo (onglet IA)
$scan_photo_actif = false;
try {
  $r = $pdo->query("SELECT valeur FROM parametres WHERE cle='scan_photo_actif'")->fetch();
  $scan_photo_actif = ($r['valeur'] ?? '0') === '1';
} catch(Exception $e) {}

if ($_SERVER['REQUEST_METHOD']==="POST" && ($_POST['action']??'')=='toggle_scan_photo') {
  csrf_check();
  $val = ($_POST['scan_photo_actif'] ?? '0') === '1' ? '1' : '0';
  $pdo->prepare("INSERT INTO parametres (cle,valeur) VALUES ('scan_photo_actif',?) ON DUPLICATE KEY UPDATE valeur=?")->execute([$val,$val]);
  $scan_photo_actif = $val === '1';
  if ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '' === 'XMLHttpRequest') {
    echo json_encode(['ok'=>true,'actif'=>$scan_photo_actif]); exit;
  }
}

define('ADMIN_HUB', true);
require_once '../includes/header.php';
?>
<style>
/* ── Onglets ─────────────────────────────────────────── */
.admin-tabs-wrap {
  position: sticky; top: 0; z-index: 100;
  background: var(--bg); padding-bottom: 0;
  margin-bottom: 24px;
}
.admin-tabs-bar {
  display: flex; align-items: stretch; gap: 0;
  border-bottom: 2px solid var(--border);
  overflow-x: auto; scrollbar-width: none;
}
.admin-tabs-bar::-webkit-scrollbar { display: none; }
.admin-tab {
  display: flex; align-items: center; gap: 6px;
  padding: 10px 14px; font-size: 12.5px; font-weight: 600;
  color: var(--text2); text-decoration: none; white-space: nowrap;
  border-bottom: 2px solid transparent; margin-bottom: -2px;
  transition: all .18s; position: relative; flex-shrink: 0;
}
.admin-tab:hover { color: var(--text); background: var(--glass); }
.admin-tab.active {
  color: var(--primary);
  border-bottom-color: var(--primary);
  background: var(--primary-bg);
}
.admin-tab .tab-emoji { font-size: 14px; line-height: 1; }
.admin-tab-sep {
  width: 1px; background: var(--border);
  margin: 6px 2px; align-self: stretch; flex-shrink: 0;
}
.admin-tab-badge {
  background: #EF4444; color: #fff;
  font-size: 10px; font-weight: 800;
  padding: 1px 6px; border-radius: 20px; margin-left: 2px;
  animation: pulse-badge 2s infinite;
}
@keyframes pulse-badge { 0%,100%{opacity:1} 50%{opacity:.6} }

/* ── Contenu ─────────────────────────────────────────── */
.admin-content { min-height: 60vh; }

/* ── Modal accès ─────────────────────────────────────── */
.modal-overlay {
  display: none; position: fixed; inset: 0;
  background: rgba(0,0,0,.6); z-index: 9999;
  align-items: center; justify-content: center;
}
.modal-overlay.open { display: flex; }
.modal-box {
  background: var(--bg2); border: 1px solid var(--border);
  border-radius: 16px; padding: 28px; width: 90%; max-width: 680px;
  max-height: 85vh; overflow-y: auto;
  box-shadow: 0 24px 60px rgba(0,0,0,.4);
}
.access-table { width: 100%; border-collapse: collapse; }
.access-table th, .access-table td {
  padding: 10px 14px; text-align: center;
  border-bottom: 1px solid var(--border); font-size: 13px;
}
.access-table th { font-weight: 700; color: var(--text2); font-size: 11px; text-transform: uppercase; letter-spacing: 1px; }
.access-table td:first-child { text-align: left; font-weight: 600; }
.access-table input[type=checkbox] { width: 16px; height: 16px; cursor: pointer; accent-color: var(--primary); }
.access-table tr:last-child td { border-bottom: none; }

/* ── Onglet IA ───────────────────────────────────────── */
.ia-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
@media(max-width:700px){ .ia-grid { grid-template-columns: 1fr; } }

/* ══ Admin : passe mobile ══ */
@media (max-width: 768px) {
  .admin-tabs-wrap { margin-bottom: 16px; }
  .admin-tabs-bar { -webkit-overflow-scrolling: touch; }
  .admin-tab { padding: 9px 12px; font-size: 12px; }
  /* Tableaux admin (non wrappés) : scroll horizontal au lieu de déborder la page */
  .admin-content table { display: block; overflow-x: auto; -webkit-overflow-scrolling: touch; max-width: 100%; }
  /* Modal « accès par onglet » : fenêtre basse pleine largeur + table scrollable */
  #modal-access.modal-overlay { align-items: flex-end; }
  #modal-access .modal-box { width: 100%; max-width: none; padding: 18px; border-radius: 18px 18px 0 0;
    position: fixed; left: 0; right: 0; bottom: 0; top: auto; max-height: 88vh; }
  .access-table { display: block; overflow-x: auto; -webkit-overflow-scrolling: touch; }
}
.toggle-big {
  display: flex; align-items: center; gap: 16px;
  background: var(--glass); border: 1px solid var(--border);
  border-radius: 12px; padding: 16px 20px;
}
.toggle-big-slider {
  width: 48px; height: 26px; border-radius: 13px;
  background: var(--border2); cursor: pointer; position: relative;
  transition: background .2s; flex-shrink: 0;
}
.toggle-big-slider::after {
  content:''; position: absolute; top: 3px; left: 3px;
  width: 20px; height: 20px; border-radius: 50%;
  background: white; transition: transform .2s;
  box-shadow: 0 1px 4px rgba(0,0,0,.3);
}
.toggle-big-slider.on { background: #10B981; }
.toggle-big-slider.on::after { transform: translateX(22px); }
</style>

<div class="page-header" style="margin-bottom:12px">
  <div class="page-title-group">
    <div class="page-title">⚙️ Administration</div>
    <div class="page-sub">Gestion complète du système Cadurix</div>
  </div>
</div>

<!-- BARRE ONGLETS -->
<div class="admin-tabs-wrap">
  <div class="admin-tabs-bar">
    <?php
    // Construire l'ordre des onglets avec séparateurs entre groupes
    $rendered = [];
    $first_group = true;
    foreach ($tab_groups as $group => $group_tabs):
      $visible = array_filter($group_tabs, fn($t) => in_array($t, $tabs_valides));
      if (empty($visible)) continue;
      // Séparateur entre groupes (pas avant le premier)
      if (!$first_group): ?>
        <div class="admin-tab-sep"></div>
      <?php endif;
      $first_group = false;
      foreach ($visible as $t):
        $meta = $tab_meta[$t];
        $rendered[] = $t;
      ?>
      <a href="?tab=<?= $t ?>" class="admin-tab <?= $tab===$t?'active':'' ?>"
         onclick="document.cookie='admin_last_tab=<?= $t ?>;path=/;max-age=86400'">
        <span class="tab-emoji"><?= $meta['icon'] ?></span>
        <?= $meta['label'] ?>
        <?php if ($t==='security' && $lockdown): ?>
          <span class="admin-tab-badge">🔒</span>
        <?php elseif ($t==='security' && $nb_ban>0): ?>
          <span class="admin-tab-badge"><?= $nb_ban ?></span>
        <?php endif; ?>
        <?php if ($t==='maintenance'): ?>
          <?php
          $maint_check = [];
          try { $maint_check = json_decode($pdo->query("SELECT valeur FROM parametres WHERE cle='maintenance'")->fetchColumn()?:'{}', true); } catch(Exception $e){}
          if ($maint_check['actif'] ?? false): ?>
            <span style="width:7px;height:7px;border-radius:50%;background:#ef4444;display:inline-block"></span>
          <?php endif; ?>
        <?php endif; ?>
      </a>
      <?php endforeach;
    endforeach;
    // Onglets non groupés éventuels
    $ungrouped = array_filter($tabs_valides, fn($t) => !in_array($t, $rendered));
    if (!empty($ungrouped)): ?>
      <div class="admin-tab-sep"></div>
      <?php foreach ($ungrouped as $t):
        $meta = $tab_meta[$t] ?? ['icon'=>'⚙️','label'=>$t]; ?>
        <a href="?tab=<?= $t ?>" class="admin-tab <?= $tab===$t?'active':'' ?>"
           onclick="document.cookie='admin_last_tab=<?= $t ?>;path=/;max-age=86400'">
          <span class="tab-emoji"><?= $meta['icon'] ?></span><?= $meta['label'] ?>
        </a>
      <?php endforeach;
    endif; ?>
  </div>
</div>

<!-- CONTENU ONGLET -->
<div class="admin-content">
<?php
  $file = $tab_files[$tab] ?? '';
  // Pour les onglets système, forcer $_GET['tab'] que system.php lit
  $sys_tabs = ['dashboard','maintenance','backup','sessions','nettoyage','settings'];
  if (in_array($tab, $sys_tabs)) {
    $_GET['tab'] = $tab;
  }
  if ($file && file_exists($file)) {
    include $file;
  } else {
    echo '<div class="card" style="text-align:center;padding:3rem;color:var(--text3)">Page introuvable.</div>';
  }
?>

</div><!-- /admin-content -->

<!-- MODAL GESTION DES ACCÈS -->
<?php if ($is_super): ?>
<div id="modal-access" class="modal-overlay" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="modal-box">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px">
      <div style="font-size:1.1rem;font-weight:700">🔒 Gérer les accès par onglet</div>
      <button onclick="document.getElementById('modal-access').classList.remove('open')"
              class="btn btn-ghost btn-sm">✕</button>
    </div>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="save_access" value="1">
      <table class="access-table">
        <thead>
          <tr>
            <th style="text-align:left">Onglet</th>
            <th>👑 Super Admin</th>
            <th>⭐ Admin</th>
            <th>🗂️ Gestionnaire</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($tab_meta as $t => $meta):
          $roles = $access_config[$t] ?? ['super_admin'];
        ?>
        <tr>
          <td><?= $meta['icon'] ?> <?= $meta['label'] ?></td>
          <td>
            <input type="checkbox" name="access[<?= $t ?>][]" value="super_admin"
                   checked disabled title="Super admin a toujours accès">
          </td>
          <td>
            <input type="checkbox" name="access[<?= $t ?>][]" value="admin"
                   <?= in_array('admin',$roles)?'checked':'' ?>>
          </td>
          <td>
            <input type="checkbox" name="access[<?= $t ?>][]" value="gestionnaire"
                   <?= in_array('gestionnaire',$roles)?'checked':'' ?>>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px">
        <button type="button" class="btn btn-ghost"
                onclick="document.getElementById('modal-access').classList.remove('open')">Annuler</button>
        <button type="submit" class="btn btn-primary">💾 Enregistrer</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>

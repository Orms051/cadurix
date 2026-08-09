<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.

/**
 * Matrice des permissions — aperçu « qui voit quoi »
 * Lecture seule. Réservé au super administrateur.
 * Règle appliquée (identique à nav_peut) :
 *   niveau effectif = surcharge site_role_permissions si elle existe, sinon role_permissions (base).
 */

require_once 'includes/auth.php';
$pageTitle = 'Matrice des permissions';

$site_id = site_actif();
if (!$site_id) { header('Location: /dashboard.php'); exit; }

// Réservé au super administrateur
if (empty($_SESSION['super_admin'])) {
    if (function_exists('flashError')) flashError("Accès réservé au super administrateur.");
    header('Location: /dashboard.php'); exit;
}

// Sites disponibles
$sites = $pdo->query("SELECT id, nom FROM sites ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);

// Site sélectionné : 0 = base globale, sinon un site précis (défaut : site actif)
$sel_site = isset($_GET['site']) ? (int)$_GET['site'] : (int)$site_id;
$site_ids = array_map(fn($s) => (int)$s['id'], $sites);
if ($sel_site !== 0 && !in_array($sel_site, $site_ids, true)) $sel_site = (int)$site_id;

// Rôles (ordre métier)
$roles_raw = $pdo->query("SELECT DISTINCT role FROM role_permissions")->fetchAll(PDO::FETCH_COLUMN);
$ordre = ['super_admin','administrateur','gestionnaire','saisie','lecteur','biomedical','sse'];
usort($roles_raw, function($a,$b) use ($ordre) {
    $ia = array_search($a,$ordre); $ib = array_search($b,$ordre);
    $ia = $ia===false ? 99 : $ia; $ib = $ib===false ? 99 : $ib;
    return $ia <=> $ib ?: strcmp($a,$b);
});
$roles = $roles_raw;

// Modules (union des deux tables)
$modules = $pdo->query("
    SELECT module FROM role_permissions
    UNION
    SELECT module FROM site_role_permissions
    ORDER BY module
")->fetchAll(PDO::FETCH_COLUMN);

// Grille de base
$base = [];
foreach ($pdo->query("SELECT role, module, niveau FROM role_permissions") as $r) {
    $base[$r['role']][$r['module']] = (int)$r['niveau'];
}

// Surcharges du site sélectionné (aucune si base globale)
$override = [];
if ($sel_site !== 0) {
    $st = $pdo->prepare("SELECT role, module, niveau FROM site_role_permissions WHERE site_id = ?");
    $st->execute([$sel_site]);
    foreach ($st as $r) { $override[$r['role']][$r['module']] = (int)$r['niveau']; }
}

// niveau effectif + indicateur de surcharge
function eff($role, $module, $base, $override, &$is_ovr) {
    $is_ovr = isset($override[$role][$module]);
    if ($is_ovr) return (int)$override[$role][$module];
    return (int)($base[$role][$module] ?? 0);
}

// Rendu d'une cellule niveau
function cell($n, $is_ovr) {
    $map = [
        0 => ['—',  'var(--text3)', 'transparent',        'Aucun accès'],
        1 => ['👁', 'var(--amber)', 'rgba(245,158,11,.10)','Lecture'],
        2 => ['✏️', 'var(--green, #16a34a)', 'rgba(22,163,74,.12)', 'Écriture / complet'],
    ];
    $m = $map[$n] ?? $map[0];
    $ovr = $is_ovr ? 'box-shadow: inset 0 0 0 2px var(--accent, #6366f1);' : '';
    $dot = $is_ovr ? '<span title="Surcharge de site" style="position:absolute;top:3px;right:4px;font-size:8px;color:var(--accent,#6366f1)">●</span>' : '';
    return '<td style="text-align:center;position:relative;background:'.$m[2].';'.$ovr.'" title="'.$m[3].($is_ovr?' (surcharge site)':'').'">'
         . $dot . '<span style="font-size:15px">'.$m[0].'</span></td>';
}

$role_labels = [
    'super_admin'    => 'Super admin',
    'administrateur' => 'Administrateur',
    'gestionnaire'   => 'Gestionnaire',
    'saisie'         => 'Saisie',
    'lecteur'        => 'Lecteur',
    'biomedical'     => 'Biomédical',
    'sse'            => 'SSE',
];

require_once 'includes/header.php';
?>

<style>
.pm-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.pm-table { border-collapse: collapse; width: 100%; min-width: 640px; }
.pm-table th, .pm-table td { border: 1px solid var(--border); padding: 8px 10px; }
.pm-table thead th { background: var(--bg2); font-size: 12px; text-transform: uppercase; letter-spacing:.04em; color: var(--text2); position: sticky; top: 0; }
.pm-table td.mod { font-weight: 600; font-size: 13px; white-space: nowrap; background: var(--bg2); position: sticky; left: 0; }
.pm-legend { display:flex; gap:16px; flex-wrap:wrap; margin-bottom:16px; font-size:13px; color:var(--text2); }
.pm-legend span { display:inline-flex; align-items:center; gap:6px; }
</style>

<div class="page-header">
  <div class="page-title-group">
    <div class="page-title">🔑 Matrice des permissions</div>
    <div class="page-sub">Aperçu « qui voit quoi » — lecture seule</div>
  </div>
  <form method="get" style="display:flex;align-items:center;gap:8px">
    <label style="font-size:13px;color:var(--text2)">Périmètre :</label>
    <select name="site" onchange="this.form.submit()" class="input" style="min-width:220px">
      <option value="0" <?= $sel_site===0?'selected':'' ?>>Base globale (rôles par défaut)</option>
      <?php foreach ($sites as $s): ?>
        <option value="<?= (int)$s['id'] ?>" <?= $sel_site===(int)$s['id']?'selected':'' ?>>
          Site : <?= h($s['nom']) ?><?= (int)$s['id']===(int)$site_id?' (actif)':'' ?>
        </option>
      <?php endforeach; ?>
    </select>
  </form>
</div>

<div class="pm-legend">
  <span><b>—</b> Aucun</span>
  <span>👁 Lecture</span>
  <span>✏️ Écriture / complet</span>
  <span style="color:var(--accent,#6366f1)">● Surcharge propre à ce site</span>
</div>

<div class="card">
  <div class="pm-wrap">
    <table class="pm-table">
      <thead>
        <tr>
          <th style="text-align:left">Module</th>
          <?php foreach ($roles as $role): ?>
            <th><?= h($role_labels[$role] ?? $role) ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($modules as $mod): ?>
          <tr>
            <td class="mod"><?= h($mod) ?></td>
            <?php foreach ($roles as $role):
              $is_ovr = false;
              // super_admin : accès total quoi qu'il arrive
              if ($role === 'super_admin') { echo cell(2, false); continue; }
              $n = eff($role, $mod, $base, $override, $is_ovr);
              echo cell($n, $is_ovr);
            endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td class="mod" style="font-size:11px;color:var(--text3)">Totaux (✏️ / 👁)</td>
          <?php foreach ($roles as $role):
            $w = 0; $r = 0;
            foreach ($modules as $mod) {
              if ($role === 'super_admin') { $w++; continue; }
              $iv = false; $n = eff($role, $mod, $base, $override, $iv);
              if ($n >= 2) $w++; elseif ($n === 1) $r++;
            }
          ?>
            <td style="text-align:center;font-size:11px;color:var(--text3)"><?= $w ?> / <?= $r ?></td>
          <?php endforeach; ?>
        </tr>
      </tfoot>
    </table>
  </div>
</div>

<div class="flash flash-info" style="margin-top:16px;font-size:13px;">
  ℹ️ <b>À savoir :</b> le <b>super admin</b> a un accès total (contournement des permissions).
  L'<b>activation d'un module par site</b> (<code>module_actif</code>) est un filtre <u>séparé</u> :
  un module désactivé sur un site n'apparaît pour personne, même avec un niveau ≥ 1 ici.
  La règle appliquée : niveau effectif = surcharge du site si présente, sinon niveau de base.
</div>

<?php require_once 'includes/footer.php'; ?>

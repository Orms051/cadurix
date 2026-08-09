<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.

/**
 * Cadurix — Destinataires des alertes mail
 * Matrice « qui reçoit quoi » (rôles) + destinataires nominatifs. Réservé admin.
 */

require_once 'includes/auth.php';
require_once 'includes/alertes_abonnements.php';
$pageTitle = 'Destinataires des alertes';

$site_id = site_actif();
if (!$site_id) { header('Location: /dashboard.php'); exit; }
if (!isAdmin()) { flashError("Accès réservé aux administrateurs."); header('Location: /dashboard.php'); exit; }

// Site ciblé (défaut : site actif)
$sites    = $pdo->query("SELECT id, nom FROM sites ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
$site_ids = array_map(fn($s) => (int)$s['id'], $sites);
$sel      = isset($_GET['site']) ? (int)$_GET['site'] : (int)$site_id;
if (!in_array($sel, $site_ids, true)) $sel = (int)$site_id;

$TYPES = alertes_types();
$ROLES = alertes_roles($pdo);

// ── Traitement POST ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $sel    = (int)($_POST['site'] ?? $sel);
    $action = $_POST['action'] ?? '';

    if ($action === 'save_roles') {
        // On repart de zéro pour les abonnements de rôle de ce site, puis on réinsère les cochés
        $pdo->prepare("DELETE FROM alertes_abonnements WHERE site_id=? AND cible_type='role'")->execute([$sel]);
        $ins = $pdo->prepare("INSERT IGNORE INTO alertes_abonnements (site_id, type_alerte, cible_type, cible_valeur)
                              VALUES (?, ?, 'role', ?)");
        foreach ($TYPES as $t => $_l) {
            foreach ($ROLES as $role => $_rl) {
                if (!empty($_POST['r'][$t][$role])) $ins->execute([$sel, $t, $role]);
            }
        }
        flashSuccess("Abonnements par rôle enregistrés.");
        header("Location: /notifications_alertes.php?site=$sel"); exit;
    }

    if ($action === 'add_user') {
        $t   = $_POST['type'] ?? '';
        $uid = (int)($_POST['user_id'] ?? 0);
        if (isset($TYPES[$t]) && $uid > 0) {
            $pdo->prepare("INSERT IGNORE INTO alertes_abonnements (site_id, type_alerte, cible_type, cible_valeur)
                           VALUES (?, ?, 'user', ?)")->execute([$sel, $t, $uid]);
            flashSuccess("Destinataire ajouté.");
        }
        header("Location: /notifications_alertes.php?site=$sel"); exit;
    }

    if ($action === 'remove_user') {
        $t   = $_POST['type'] ?? '';
        $uid = (int)($_POST['user_id'] ?? 0);
        $pdo->prepare("DELETE FROM alertes_abonnements
                       WHERE site_id=? AND type_alerte=? AND cible_type='user' AND cible_valeur=?")
            ->execute([$sel, $t, (string)$uid]);
        flashSuccess("Destinataire retiré.");
        header("Location: /notifications_alertes.php?site=$sel"); exit;
    }
}

$matrix     = abo_roles_matrix($pdo, $sel);
$site_users = abo_users_du_site($pdo, $sel);

require_once 'includes/header.php';
?>

<style>
.na-table { border-collapse: collapse; width: 100%; }
.na-table th, .na-table td { border: 1px solid var(--border); padding: 10px 12px; text-align: center; }
.na-table th:first-child, .na-table td:first-child { text-align: left; white-space: nowrap; }
.na-table thead th { background: var(--bg2); font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: var(--text2); }
.na-table input[type=checkbox] { width: 18px; height: 18px; cursor: pointer; }
.na-users { display: flex; flex-wrap: wrap; gap: 6px; margin: 6px 0; }
.na-chip { display: inline-flex; align-items: center; gap: 6px; background: var(--bg2); border: 1px solid var(--border); border-radius: 999px; padding: 3px 6px 3px 10px; font-size: 12px; }
.na-chip button { border: none; background: transparent; color: var(--red); cursor: pointer; font-size: 13px; line-height: 1; }
.na-add { display: flex; gap: 6px; align-items: center; margin-top: 4px; }
.na-add select { min-width: 220px; }
</style>

<div class="page-header">
  <div class="page-title-group">
    <div class="page-title">📧 Destinataires des alertes</div>
    <div class="page-sub">Qui reçoit quel type d'alerte mail</div>
  </div>
  <div style="display:flex;gap:8px;align-items:center">
    <form method="get" style="display:flex;gap:8px;align-items:center">
      <label style="font-size:13px;color:var(--text2)">Site :</label>
      <select name="site" onchange="this.form.submit()" class="input" style="min-width:200px">
        <?php foreach ($sites as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= (int)$s['id']===$sel?'selected':'' ?>>
            <?= h($s['nom']) ?><?= (int)$s['id']===(int)$site_id?' (actif)':'' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </form>
    <a href="/alerts.php" class="btn btn-ghost">← Alertes</a>
  </div>
</div>

<!-- Matrice rôles -->
<div class="card" style="margin-bottom:18px;">
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_roles">
    <input type="hidden" name="site" value="<?= $sel ?>">
    <table class="na-table">
      <thead>
        <tr>
          <th>Type d'alerte</th>
          <?php foreach ($ROLES as $role => $label): ?><th><?= h($label) ?></th><?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($TYPES as $t => $tl): ?>
          <tr>
            <td><?= $tl ?></td>
            <?php foreach ($ROLES as $role => $_rl):
              $checked = !empty($matrix[$t][$role]); ?>
              <td><input type="checkbox" name="r[<?= $t ?>][<?= $role ?>]" value="1" <?= $checked?'checked':'' ?>></td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <div style="margin-top:14px;text-align:right;">
      <button type="submit" class="btn btn-primary">💾 Enregistrer les rôles</button>
    </div>
  </form>
</div>

<!-- Destinataires nominatifs par type -->
<div class="card">
  <div style="font-weight:700;margin-bottom:4px;">Destinataires nominatifs</div>
  <div style="color:var(--text2);font-size:13px;margin-bottom:14px;">Ajoute une personne précise à un type d'alerte, en plus des rôles.</div>

  <?php foreach ($TYPES as $t => $tl):
    $users = abo_users($pdo, $sel, $t); ?>
    <div style="padding:12px 0;border-top:1px solid var(--border);">
      <div style="font-weight:600;margin-bottom:6px;"><?= $tl ?></div>

      <div class="na-users">
        <?php if (empty($users)): ?>
          <span style="color:var(--text3);font-size:12px;">Aucune personne nominative.</span>
        <?php else: foreach ($users as $u): ?>
          <span class="na-chip">
            <?= h(trim($u['prenom'].' '.$u['nom'])) ?> <span style="color:var(--text3)"><?= h($u['email']) ?></span>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="remove_user">
              <input type="hidden" name="site" value="<?= $sel ?>">
              <input type="hidden" name="type" value="<?= $t ?>">
              <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
              <button type="submit" title="Retirer">✕</button>
            </form>
          </span>
        <?php endforeach; endif; ?>
      </div>

      <form method="post" class="na-add">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_user">
        <input type="hidden" name="site" value="<?= $sel ?>">
        <input type="hidden" name="type" value="<?= $t ?>">
        <select name="user_id" class="input" required>
          <option value="">+ Ajouter une personne…</option>
          <?php foreach ($site_users as $u): ?>
            <option value="<?= (int)$u['id'] ?>"><?= h(trim($u['prenom'].' '.$u['nom'])) ?> — <?= h($u['email']) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-ghost btn-sm">Ajouter</button>
      </form>
    </div>
  <?php endforeach; ?>
</div>

<div class="flash flash-info" style="margin-top:16px;font-size:13px;">
  ℹ️ Ces réglages définissent qui reçoit chaque type d'alerte. L'envoi (manuel ou automatique) s'appuiera dessus — un destinataire présent en rôle <b>et</b> en nominatif n'est compté qu'une fois.
</div>

<?php require_once 'includes/footer.php'; ?>

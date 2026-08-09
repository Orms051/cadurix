<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.

if (!defined('ADMIN_HUB')) require_once '../includes/auth.php';
if (!defined('ADMIN_HUB')) requireAdmin();
$pageTitle = 'Journal d\'audit';

$filterUser   = (int)($_GET['user']   ?? 0);
$filterAction = $_GET['action']       ?? '';
$filterDate   = $_GET['date']         ?? '';
$page         = max(1, (int)($_GET['p'] ?? 1));
$perPage      = 30;
$filterSecu   = (($_GET['secu'] ?? '') === '1');
$SECU_ACTIONS = ['BLOCAGE_ACTION_PROTEGEE','BLOCAGE_ACTION_SUPER_ADMIN','CONNEXION_ECHEC',
                 'MODIF_PERMISSIONS','SUPPRESSION_PERMISSION','MODIF_UTILISATEUR',
                 'TOGGLE_UTILISATEUR','RESET_MDP','CREATION_UTILISATEUR','SUPPRESSION_UTILISATEUR',
                 'MODIF_SITE','MODIF_ORGANISATION'];

$where  = 'WHERE 1=1';
$params = [];
if ($filterUser)   { $where .= ' AND a.user_id=?';          $params[] = $filterUser; }
if ($filterAction) { $where .= ' AND a.action=?';           $params[] = $filterAction; }
if ($filterDate)   { $where .= ' AND DATE(a.cree_le)=?';    $params[] = $filterDate; }
if ($filterSecu)   { $where .= ' AND a.action IN (' . implode(',', array_fill(0, count($SECU_ACTIONS), '?')) . ')'; foreach ($SECU_ACTIONS as $sa) $params[] = $sa; }

$total = $pdo->prepare("SELECT COUNT(*) FROM audit_log a $where");
$total->execute($params);
$totalRows  = (int)$total->fetchColumn();
$totalPages = max(1, ceil($totalRows / $perPage));
$offset     = ($page - 1) * $perPage;

$stmt = $pdo->prepare("
    SELECT a.*,
           CONCAT(u.prenom,' ',u.nom) AS user_nom_complet,
           IF(u.super_admin=1,'admin',COALESCE(
               (SELECT usp2.role FROM user_site_permissions usp2 WHERE usp2.user_id = a.user_id LIMIT 1),
               'lecteur'
           )) AS user_role_display
    FROM audit_log a
    LEFT JOIN utilisateurs u ON u.id = a.user_id
    $where
    ORDER BY a.cree_le DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Liste des users pour filtre
// Filtre site pour les admins non super admin
$_is_sa = (bool)($_SESSION['super_admin'] ?? false);
$_sa_site = site_actif();
if ($_is_sa) {
    $users = $pdo->query("SELECT id, CONCAT(prenom,' ',nom) AS nom FROM utilisateurs ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt_au = $pdo->prepare("SELECT u.id, CONCAT(u.prenom,' ',u.nom) AS nom FROM utilisateurs u JOIN user_site_permissions usp ON usp.user_id=u.id WHERE usp.site_id=? ORDER BY nom");
    $stmt_au->execute([$_sa_site]);
    $users = $stmt_au->fetchAll(PDO::FETCH_ASSOC);
}

// Actions distinctes
$actions = $pdo->query("SELECT DISTINCT action FROM audit_log ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);

$actionIcons = [
    'CONNEXION_REUSSIE'         => '🔑',
    'CONNEXION_ECHEC'           => '🚫',
    'BLOCAGE_ACTION_PROTEGEE'   => '🛡️',
    'BLOCAGE_ACTION_SUPER_ADMIN'=> '🛡️',
    'SUPPRESSION_UTILISATEUR'   => '🗑️',
    'LOCKDOWN'                  => '🔒',
    'UNLOCK'                    => '🔓',
    'BAN_IP'                    => '⛔',
    'CREATION_PRODUIT'          => '➕',
    'MODIF_PRODUIT'             => '✏️',
    'SUPPRESSION_PRODUIT'       => '🗑️',
    'CREATION_EMPLACEMENT'      => '➕',
    'MODIF_EMPLACEMENT'         => '✏️',
    'SUPPRESSION_EMPLACEMENT'   => '🗑️',
    'CREATION_UTILISATEUR'      => '👤',
    'MODIF_UTILISATEUR'         => '✏️',
    'TOGGLE_UTILISATEUR'        => '🔄',
    'RESET_MDP'                 => '🔑',
    'CREATION_ORGANISATION'     => '🏢',
    'MODIF_ORGANISATION'        => '✏️',
    'CREATION_SITE'             => '🗺',
    'MODIF_SITE'                => '✏️',
    'MODIF_PERMISSIONS'         => '🔐',
    'SUPPRESSION_PERMISSION'    => '🗑️',
    'IMPORT_EXCEL'              => '📥',
    // Compat v4
    'LOGIN'                     => '🔑',
    'CREATE_PRODUCT'            => '➕',
    'UPDATE_PRODUCT'            => '✏️',
    'DELETE_PRODUCT'            => '🗑️',
    'SEND_MESSAGE'              => '✉️',
];

if (!defined('ADMIN_HUB')) require_once '../includes/header.php';
?>

<div class="page-header">
  <div class="page-title-group">
    <div class="page-title">📋 Journal d'audit</div>
    <div class="page-sub">Historique complet · <?= number_format($totalRows) ?> entrée(s)</div>
  </div>
</div>

<!-- Filtres -->
<div class="card" style="margin-bottom:20px;padding:16px 20px;">
  <form method="GET" style="display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;">
    <div class="form-group" style="margin-bottom:0;min-width:180px;">
      <label class="form-label">Utilisateur</label>
      <select name="user" class="form-control" style="font-size:13px;padding:8px 12px;">
        <option value="">Tous</option>
        <?php foreach ($users as $u): ?>
          <option value="<?= $u['id'] ?>" <?= $filterUser==$u['id']?'selected':''?>><?= h($u['nom']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="margin-bottom:0;min-width:220px;">
      <label class="form-label">Action</label>
      <select name="action" class="form-control" style="font-size:13px;padding:8px 12px;">
        <option value="">Toutes</option>
        <?php foreach ($actions as $a): ?>
          <option value="<?= h($a) ?>" <?= $filterAction===$a?'selected':''?>>
            <?= ($actionIcons[$a]??'•').' '.h($a) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="margin-bottom:0;">
      <label class="form-label">Date</label>
      <input type="date" name="date" class="form-control" style="font-size:13px;padding:8px 12px;" value="<?= h($filterDate) ?>">
    </div>
    <button type="submit" class="btn btn-primary btn-sm">🔍 Filtrer</button>
    <a href="audit.php" class="btn btn-ghost btn-sm">Réinitialiser</a>
    <a href="<?= $filterSecu?'audit.php':'?secu=1' ?>" class="btn btn-sm <?= $filterSecu?'btn-primary':'btn-ghost' ?>" style="margin-left:auto">🛡️ Événements sécurité</a>
  </form>
</div>

<!-- Tableau -->
<div class="card">
  <?php if (empty($logs)): ?>
    <div style="text-align:center;padding:50px;color:var(--text2);">
      <div style="font-size:40px;margin-bottom:12px;">📋</div>
      Aucune entrée trouvée.
    </div>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Date / Heure</th>
          <th>Utilisateur</th>
          <th>Action</th>
          <th>Cible</th>
          <th>Détails</th>
          <th>IP</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($logs as $l):
        $icon     = $actionIcons[$l['action']] ?? '•';
        $isDel    = str_contains($l['action'], 'SUPPRESSION') || str_contains($l['action'], 'DELETE');
        $isCreate = str_contains($l['action'], 'CREATION') || str_contains($l['action'], 'CREATE');
        $rowBg    = $isDel ? 'rgba(239,68,68,.04)' : ($isCreate ? 'rgba(16,185,129,.03)' : '');
        $role     = $l['user_role_display'] ?? 'lecteur';
      ?>
        <tr style="background:<?= $rowBg ?>">
          <td style="font-family:var(--mono);font-size:12px;color:var(--text2);white-space:nowrap;">
            <?= date('d/m/Y', strtotime($l['cree_le'])) ?><br>
            <span style="color:var(--text3)"><?= date('H:i:s', strtotime($l['cree_le'])) ?></span>
          </td>
          <td>
            <div style="display:flex;align-items:center;gap:8px;">
              <div style="width:26px;height:26px;border-radius:50%;background:<?= h(roleColor($role)) ?>;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;color:white;flex-shrink:0;">
                <?= strtoupper(substr($l['user_nom_complet'] ?? '?', 0, 1)) ?>
              </div>
              <div>
                <div style="font-size:12.5px;font-weight:600;"><?= h($l['user_nom_complet'] ?? 'Système') ?></div>
                <div style="font-size:10px;color:var(--text3);"><?= roleLabel($role) ?></div>
              </div>
            </div>
          </td>
          <td>
            <span class="badge <?= $isDel?'badge-critical':($isCreate?'badge-ok':'badge-warning') ?>"
                  style="font-family:var(--mono);font-size:11px;">
              <?= $icon ?> <?= h($l['action']) ?>
            </span>
          </td>
          <td style="font-size:13px;">
            <?php if ($l['table_cible']): ?>
              <div style="font-weight:600;"><?= h($l['table_cible']) ?></div>
              <?php if ($l['enregistrement_id']): ?>
                <div style="font-size:11px;color:var(--text3);">#<?= $l['enregistrement_id'] ?></div>
              <?php endif; ?>
            <?php else: ?>
              <span style="color:var(--text3)">—</span>
            <?php endif; ?>
          </td>
          <td style="max-width:220px;">
            <?php if ($l['valeur_avant'] || $l['valeur_apres']): ?>
              <button class="btn btn-ghost btn-xs"
                onclick="this.nextElementSibling.style.display=this.nextElementSibling.style.display==='none'?'block':'none'">
                Détails
              </button>
              <div style="display:none;margin-top:6px;">
                <?php if ($l['valeur_avant']): ?>
                  <div style="font-size:10px;color:var(--text3);margin-bottom:2px;">Avant :</div>
                  <pre style="font-size:10px;color:var(--red);background:rgba(239,68,68,.06);padding:6px;border-radius:6px;overflow:auto;max-width:200px;"><?= h($l['valeur_avant']) ?></pre>
                <?php endif; ?>
                <?php if ($l['valeur_apres']): ?>
                  <div style="font-size:10px;color:var(--text3);margin-top:4px;margin-bottom:2px;">Après :</div>
                  <pre style="font-size:10px;color:#10B981;background:rgba(16,185,129,.06);padding:6px;border-radius:6px;overflow:auto;max-width:200px;"><?= h($l['valeur_apres']) ?></pre>
                <?php endif; ?>
              </div>
            <?php else: ?>
              <span style="color:var(--text3);font-size:12px;">—</span>
            <?php endif; ?>
          </td>
          <td style="font-family:var(--mono);font-size:11.5px;color:var(--text3);"><?= h($l['ip'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <?php if ($totalPages > 1): ?>
  <div style="display:flex;justify-content:center;gap:6px;padding:16px 0 4px;flex-wrap:wrap;">
    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
      <a href="?p=<?= $i ?>&user=<?= $filterUser ?>&action=<?= urlencode($filterAction) ?>&date=<?= urlencode($filterDate) ?>&secu=<?= $filterSecu?'1':'' ?>"
         class="btn btn-sm <?= $i===$page?'btn-primary':'btn-ghost' ?>"
         style="min-width:36px;justify-content:center;"><?= $i ?></a>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<?php if (!defined('ADMIN_HUB')) require_once '../includes/footer.php'; ?>

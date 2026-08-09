<?php
/**
 * Cadurix v5 — Administration Maintenance
 * Intégrée dans l'admin, accessible depuis la sidebar
 */
if (!defined('ADMIN_HUB')) require_once '../../includes/auth.php';
if (!defined('ADMIN_HUB') && !isAdmin()) { accessDenied(); }
$pageTitle = 'Maintenance';

$maint_file = __DIR__ . '/../../config/maintenance.json';

function read_maint(): array {
    global $maint_file;
    if (!file_exists($maint_file)) return ['actif'=>false,'message'=>'Site en maintenance.','retour_estime'=>'','ips_bypass'=>[],'debut'=>null,'historique'=>[]];
    return json_decode(file_get_contents($maint_file), true) ?? [];
}
function save_maint(array $data): void {
    global $maint_file;
    file_put_contents($maint_file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

$maint = read_maint();
$ip_courante = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $actif  = isset($_POST['actif']);
        $avant  = $maint['actif'] ?? false;
        $maint['message']       = trim($_POST['message'] ?? 'Site en maintenance.');
        $maint['retour_estime'] = trim($_POST['retour_estime'] ?? '');
        $maint['ips_bypass']    = array_values(array_filter(array_map('trim', explode("\n", $_POST['ips_bypass'] ?? ''))));

        if ($actif && !$avant) {
            $maint['actif'] = true;
            $maint['debut'] = date('c');
            $maint['historique'][] = ['debut' => date('c'), 'message' => $maint['message']];
            if (count($maint['historique']) > 10) array_shift($maint['historique']);
            audit_log($pdo, 'MAINTENANCE_ON', 'system');
        } elseif (!$actif && $avant) {
            $maint['actif'] = false;
            if (!empty($maint['historique'])) {
                $maint['historique'][count($maint['historique'])-1]['fin'] = date('c');
            }
            $maint['debut'] = null;
            audit_log($pdo, 'MAINTENANCE_OFF', 'system');
        } else {
            $maint['actif'] = $actif;
        }
        save_maint($maint);
        flashSuccess($actif ? "🔧 Mode maintenance activé." : "✅ Site remis en ligne.");
        header('Location: index.php'); exit;
    }

    if ($action === 'add_ip') {
        $ip = trim($_POST['ip'] ?? '');
        if ($ip && !in_array($ip, $maint['ips_bypass'])) {
            $maint['ips_bypass'][] = $ip;
            save_maint($maint);
            flashSuccess("IP $ip ajoutée.");
        }
        header('Location: index.php'); exit;
    }

    if ($action === 'remove_ip') {
        $ip = $_POST['ip'] ?? '';
        $maint['ips_bypass'] = array_values(array_filter($maint['ips_bypass'], fn($i) => $i !== $ip));
        save_maint($maint);
        flashSuccess("IP supprimée.");
        header('Location: index.php'); exit;
    }
}

if (!defined('ADMIN_HUB')) require_once '../../includes/header.php';
?>
<style>
.maint-hero { padding:2rem; text-align:center; border-radius:var(--radius); border:1px solid var(--border); margin-bottom:1.5rem; transition:all .3s; }
.maint-hero.on { background:rgba(239,68,68,.06); border-color:rgba(239,68,68,.25); }
.maint-hero.off { background:rgba(16,185,129,.06); border-color:rgba(16,185,129,.25); }
.status-big { font-size:3rem; margin-bottom:.5rem; }
.status-label { font-size:1.25rem; font-weight:800; margin-bottom:.25rem; }
.toggle-big { display:inline-flex; align-items:center; gap:16px; padding:16px 24px; background:var(--glass); border:1px solid var(--border); border-radius:var(--radius); cursor:pointer; margin-top:1rem; }
.toggle-big-track { width:60px; height:30px; background:var(--border2); border-radius:15px; position:relative; transition:.3s; flex-shrink:0; }
.toggle-big-track.on { background:#10b981; }
.toggle-big-track::after { content:''; position:absolute; width:22px; height:22px; background:white; border-radius:50%; top:4px; left:4px; transition:.3s; box-shadow:0 2px 6px rgba(0,0,0,.3); }
.toggle-big-track.on::after { left:34px; }
.ip-item { display:flex; align-items:center; justify-content:space-between; padding:.6rem .875rem; background:var(--glass); border:1px solid var(--border); border-radius:.5rem; margin-bottom:.4rem; font-size:.85rem; }
.hist-item { padding:.75rem 1rem; background:var(--glass); border:1px solid var(--border); border-radius:.5rem; margin-bottom:.5rem; font-size:.82rem; }
</style>

<div class="page-header">
  <div class="page-title-group">
    <div class="page-title">🔧 Maintenance</div>
    <div class="page-sub">Gérez le mode maintenance du site</div>
  </div>
  <a href="/maintenance.php" target="_blank" class="btn btn-ghost btn-sm">👁 Aperçu page →</a>
</div>

<!-- Statut hero -->
<div class="maint-hero <?= ($maint['actif']??false)?'on':'off' ?>">
  <div class="status-big"><?= ($maint['actif']??false) ? '🔴' : '🟢' ?></div>
  <div class="status-label" style="color:<?= ($maint['actif']??false)?'#f87171':'#34d399' ?>">
    <?= ($maint['actif']??false) ? 'Maintenance EN COURS' : 'Site OPÉRATIONNEL' ?>
  </div>
  <div style="font-size:.875rem;color:var(--text2);margin-bottom:1rem">
    <?php if ($maint['actif']??false): ?>
      Démarré le <?= $maint['debut'] ? date('d/m/Y à H:i', strtotime($maint['debut'])) : '—' ?>
      <?php if ($maint['retour_estime']): ?> · Retour estimé : <strong><?= h($maint['retour_estime']) ?></strong><?php endif; ?>
    <?php else: ?>
      Tous les utilisateurs ont accès normalement
    <?php endif; ?>
  </div>

  <!-- Toggle principal -->
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="message" value="<?= h($maint['message']??'') ?>">
    <input type="hidden" name="retour_estime" value="<?= h($maint['retour_estime']??'') ?>">
    <input type="hidden" name="ips_bypass" value="<?= h(implode("\n", $maint['ips_bypass']??[])) ?>">
    <?php if (!($maint['actif']??false)): ?>
    <input type="hidden" name="actif" value="1">
    <?php endif; ?>
    <button type="submit" class="toggle-big" onclick="return confirm('<?= ($maint['actif']??false) ? 'Remettre le site en ligne ?' : 'Activer le mode maintenance ?' ?>')">
      <div class="toggle-big-track <?= ($maint['actif']??false)?'on':'' ?>"></div>
      <div>
        <div style="font-weight:700;font-size:.95rem"><?= ($maint['actif']??false) ? '→ Remettre en ligne' : '→ Activer la maintenance' ?></div>
        <div style="font-size:.78rem;color:var(--text2)"><?= ($maint['actif']??false) ? 'Le site redeviendra accessible à tous' : 'Les visiteurs verront la page de maintenance' ?></div>
      </div>
    </button>
  </form>
</div>

<div class="grid-2">
  <!-- Configuration -->
  <div class="card">
    <div class="card-title">⚙️ Configuration</div>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <?php if ($maint['actif']??false): ?><input type="hidden" name="actif" value="1"><?php endif; ?>
      <div class="form-group">
        <label class="form-label">Message affiché aux visiteurs</label>
        <textarea name="message" class="form-control" rows="3"><?= h($maint['message']??'') ?></textarea>
      </div>
      <div class="form-group">
        <label class="form-label">Heure de retour estimée</label>
        <input type="text" name="retour_estime" class="form-control" value="<?= h($maint['retour_estime']??'') ?>" placeholder="Ex: Aujourd'hui à 18h00">
      </div>
      <div class="form-group">
        <label class="form-label">IPs en bypass (une par ligne)</label>
        <textarea name="ips_bypass" class="form-control" rows="4" placeholder="192.168.1.1&#10;82.x.x.x"><?= h(implode("\n", $maint['ips_bypass']??[])) ?></textarea>
        <div class="form-hint">Votre IP : <strong style="color:var(--primary)"><?= h($ip_courante) ?></strong></div>
      </div>
      <button type="submit" class="btn btn-primary">💾 Enregistrer la configuration</button>
    </form>
  </div>

  <div style="display:flex;flex-direction:column;gap:1rem">
    <!-- IPs bypass rapide -->
    <div class="card">
      <div class="card-title">🌐 Bypass IP rapide</div>
      <p style="font-size:.82rem;color:var(--text2);margin-bottom:1rem">Ces IPs voient le site normalement même en maintenance.</p>

      <!-- IPs actuelles -->
      <?php if (empty($maint['ips_bypass'])): ?>
      <p style="font-size:.82rem;color:var(--text3)">Aucun bypass configuré — seuls les Super Admins connectés ont accès.</p>
      <?php else: ?>
      <?php foreach ($maint['ips_bypass'] as $ip): ?>
      <div class="ip-item">
        <span style="font-family:var(--mono)"><?= h($ip) ?> <?= $ip===$ip_courante?'<span style="color:#10b981;font-size:.72rem">(votre IP)</span>':'' ?></span>
        <form method="POST" style="display:inline"><?= csrf_field() ?>
          <input type="hidden" name="action" value="remove_ip">
          <input type="hidden" name="ip" value="<?= h($ip) ?>">
          <button class="btn btn-ghost btn-xs" onclick="return confirm('Retirer cette IP ?')">✕</button>
        </form>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>

      <!-- Ajouter IP -->
      <form method="POST" style="display:flex;gap:.5rem;margin-top:.75rem">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_ip">
        <input type="text" name="ip" class="form-control" placeholder="192.168.x.x" style="flex:1;font-family:var(--mono);font-size:.85rem">
        <button class="btn btn-ghost btn-sm">+ Ajouter</button>
      </form>
      <form method="POST" style="margin-top:.5rem">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_ip">
        <input type="hidden" name="ip" value="<?= h($ip_courante) ?>">
        <button class="btn btn-ghost btn-sm">+ Mon IP (<?= h($ip_courante) ?>)</button>
      </form>
    </div>

    <!-- Historique -->
    <div class="card">
      <div class="card-title">📋 Historique des maintenances</div>
      <?php if (empty($maint['historique'])): ?>
      <p style="color:var(--text3);font-size:.82rem">Aucune maintenance enregistrée.</p>
      <?php else: ?>
      <?php foreach (array_reverse($maint['historique']) as $h): ?>
      <div class="hist-item">
        <div style="font-weight:600">🔧 <?= date('d/m/Y à H:i', strtotime($h['debut'])) ?></div>
        <?php if (!empty($h['fin'])): ?>
        <div style="color:var(--text2)">
          ✓ Fin : <?= date('d/m/Y à H:i', strtotime($h['fin'])) ?>
          <span style="color:var(--text3);font-size:.75rem;margin-left:.5rem">
            (<?= round((strtotime($h['fin'])-strtotime($h['debut']))/60) ?> min)
          </span>
        </div>
        <?php else: ?>
        <div style="color:var(--red);font-size:.8rem">⚡ En cours…</div>
        <?php endif; ?>
        <?php if (!empty($h['message'])): ?>
        <div style="color:var(--text3);margin-top:.3rem;font-size:.78rem"><?= h($h['message']) ?></div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if (!defined('ADMIN_HUB')) require_once '../../includes/footer.php'; ?>

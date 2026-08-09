<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.

if (!defined('ADMIN_HUB')) { require_once __DIR__ . '/../../includes/auth.php'; requireAdmin(); }
/**
 * Cadurix — admin/ai/dashboard.php
 * Onglet IA du hub administration
 */

// ── Toggle scan photo AJAX ───────────────────────────────────
if (($_POST['action'] ?? '') === 'toggle_scan_photo') {
    csrf_check();
    $val = ($_POST['scan_photo_actif'] ?? '0') === '1' ? '1' : '0';
    $pdo->prepare("INSERT INTO parametres (cle,valeur) VALUES ('scan_photo_actif',?) ON DUPLICATE KEY UPDATE valeur=?")
        ->execute([$val, $val]);
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) { echo json_encode(['ok'=>true]); exit; }
}

// ── Sauvegarder config IA ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    csrf_check();
    // Champs texte normaux
    foreach (['api_key','model','max_tokens','budget_mensuel_max','rate_limit_heure'] as $f) {
        if (isset($_POST[$f])) {
            $pdo->prepare("UPDATE ai_settings SET valeur=? WHERE cle=?")->execute([trim($_POST[$f]), $f]);
        }
    }
    // Checkbox actif — forcer 0 si non cochée (absente du POST)
    $actif_val = isset($_POST['actif']) ? '1' : '0';
    $pdo->prepare("UPDATE ai_settings SET valeur=? WHERE cle='actif'")->execute([$actif_val]);
    foreach (['lecteur','saisie','biomedical','gestionnaire','administrateur','super_admin'] as $role) {
        $pdo->prepare("UPDATE ai_permissions SET chat=?,rempotage_vocal=?,interventions_vocal=?,rapports=?,admin_knowledge=? WHERE role=?")
            ->execute([
                isset($_POST['perm'][$role]['chat'])?1:0,
                isset($_POST['perm'][$role]['rempotage_vocal'])?1:0,
                isset($_POST['perm'][$role]['interventions_vocal'])?1:0,
                isset($_POST['perm'][$role]['rapports'])?1:0,
                isset($_POST['perm'][$role]['admin_knowledge'])?1:0,
                $role,
            ]);
    }
    flashSuccess("Paramètres IA sauvegardés.");
    echo '<script>window.location.href="?tab=ia";</script>'; exit;
}

// ── Données ─────────────────────────────────────────────────
$settings = $pdo->query("SELECT cle,valeur FROM ai_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$perms    = $pdo->query("SELECT * FROM ai_permissions ORDER BY FIELD(role,'lecteur','saisie','biomedical','gestionnaire','administrateur','super_admin')")->fetchAll(PDO::FETCH_ASSOC);

$row = $pdo->query("
    SELECT COUNT(*) AS nb_conv,
           COALESCE(SUM(tokens),0)   AS nb_tokens,
           COALESCE(SUM(cout_eur),0) AS cout_total,
           COUNT(CASE WHEN action IS NOT NULL THEN 1 END) AS nb_actions
    FROM ai_conversations
")->fetch(PDO::FETCH_ASSOC);

$nb_conv     = (int)$row['nb_conv'];
$nb_tokens   = (int)$row['nb_tokens'];
$nb_actions  = (int)$row['nb_actions'];
$cout_estime = round((float)strval($row['cout_total']), 6);

$conv_recentes = $pdo->query("
    SELECT c.*, u.prenom, u.nom
    FROM ai_conversations c
    LEFT JOIN utilisateurs u ON u.id = c.user_id
    ORDER BY c.cree_le DESC LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

$scan_photo_actif = false;
$nb_scans = 0;
try {
    $scan_photo_actif = ($pdo->query("SELECT valeur FROM parametres WHERE cle='scan_photo_actif'")->fetchColumn() === '1');
    $nb_scans = (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='SCAN_PHOTO' AND DATE(cree_le)=CURDATE()")->fetchColumn();
} catch(Exception $e) {}
?>

<!-- Header -->
<div class="page-header" style="margin-bottom:20px">
  <div class="page-title-group">
    <div class="page-title">🤖 Intelligence Artificielle</div>
    <div class="page-sub">Configuration et supervision du module CadurixAI</div>
  </div>
  <span class="badge" style="background:<?= ($settings['actif']??0)?'rgba(16,185,129,.15)':'rgba(239,68,68,.15)' ?>;color:<?= ($settings['actif']??0)?'#34d399':'#f87171' ?>;border:1px solid <?= ($settings['actif']??0)?'rgba(16,185,129,.3)':'rgba(239,68,68,.3)' ?>;padding:.3rem .9rem;border-radius:99px;font-size:.8rem">
    <?= ($settings['actif']??0) ? '✅ IA Active' : '❌ IA Désactivée' ?>
  </span>
</div>

<!-- KPIs -->
<div class="grid-4" style="margin-bottom:20px">
  <div class="kpi-card" style="border-top:3px solid #3b82f6">
    <div class="kpi-label">💬 Conversations</div>
    <div class="kpi-value"><?= number_format($nb_conv) ?></div>
  </div>
  <div class="kpi-card" style="border-top:3px solid #8b5cf6">
    <div class="kpi-label">⚡ Actions exécutées</div>
    <div class="kpi-value"><?= number_format($nb_actions) ?></div>
  </div>
  <div class="kpi-card" style="border-top:3px solid #f59e0b">
    <div class="kpi-label">🔢 Tokens consommés</div>
    <div class="kpi-value"><?= number_format($nb_tokens) ?></div>
  </div>
  <div class="kpi-card" style="border-top:3px solid #10b981">
    <div class="kpi-label">💰 Coût réel</div>
    <div class="kpi-value" style="font-size:1.3rem"><?= number_format($cout_estime, 4, ',', '') ?> €</div>
    <div style="font-size:.7rem;color:var(--text3);margin-top:3px"><?= $settings['model'] ?? 'haiku-4.5' ?> · Lu depuis la BDD</div>
    <?php
    $budget_max = (float)($settings['budget_mensuel_max'] ?? 0);
    $cout_mois  = (float)$pdo->query("SELECT COALESCE(SUM(cout_eur),0) FROM ai_conversations WHERE MONTH(cree_le)=MONTH(NOW()) AND YEAR(cree_le)=YEAR(NOW())")->fetchColumn();
    if ($budget_max > 0):
        $pct = min(100, round($cout_mois / $budget_max * 100));
        $color = $pct >= 90 ? '#ef4444' : ($pct >= 70 ? '#f59e0b' : '#10b981');
    ?>
    <div style="margin-top:8px">
      <div style="display:flex;justify-content:space-between;font-size:.75rem;color:var(--text2);margin-bottom:3px">
        <span>Budget mois en cours</span>
        <span style="color:<?= $color ?>"><?= number_format($cout_mois,4) ?>€ / <?= $budget_max ?>€</span>
      </div>
      <div style="background:var(--border);border-radius:4px;height:6px">
        <div style="width:<?= $pct ?>%;background:<?= $color ?>;border-radius:4px;height:100%;transition:.3s"></div>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Config + Conversations -->
<div class="grid-2" style="margin-bottom:18px">

  <!-- Paramètres -->
  <div class="card">
    <div class="card-title" style="margin-bottom:16px">⚙️ Configuration API & Modèle</div>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="save_settings" value="1">

      <div class="form-group">
        <label class="form-label">🔑 Clé API Anthropic</label>
        <input type="password" name="api_key" class="form-control"
               value="<?= h($settings['api_key']??'') ?>"
               placeholder="sk-ant-...">
        <div style="font-size:.72rem;color:var(--text3);margin-top:.3rem">
          Obtenir une clé sur <a href="https://console.anthropic.com" target="_blank" style="color:var(--primary)">console.anthropic.com</a>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">🧠 Modèle Claude</label>
        <select name="model" class="form-control">
          <option value="claude-haiku-4-5-20251001" <?= ($settings['model']??'')==='claude-haiku-4-5-20251001'?'selected':'' ?>>Claude Haiku 4.5 — Rapide · Économique (Recommandé)</option>
          <option value="claude-sonnet-4-20250514"  <?= ($settings['model']??'')==='claude-sonnet-4-20250514' ?'selected':'' ?>>Claude Sonnet 4 — Équilibré · Plus puissant</option>
        </select>
        <div style="font-size:.71rem;color:var(--text3);margin-top:4px">💡 Haiku ~0,001€/req · Sonnet ~0,015€</div>
      </div>

      <div class="form-group" style="display:flex;align-items:center;gap:.75rem">
        <label class="form-label" style="margin:0">Module actif</label>
        <input type="checkbox" name="actif" value="1" <?= ($settings['actif']??0)?'checked':'' ?> style="accent-color:var(--primary);width:18px;height:18px">

      </div>

      <!-- Budget mensuel -->
      <div class="form-group">
        <label class="form-label">💰 Budget mensuel maximum (€)</label>
        <input type="number" name="budget_mensuel_max" class="form-control"
               value="<?= h($settings['budget_mensuel_max'] ?? '0') ?>"
               min="0" step="0.50" placeholder="0 = illimité">
        <small style="color:var(--text3);font-size:.75rem">
          0 = pas de limite. Si dépassé, l'IA est bloquée jusqu'au mois suivant.
        </small>
      </div>

      <!-- Rate limit configurable -->
      <div class="form-group">
        <label class="form-label">⏱️ Limite de requêtes par heure (par utilisateur)</label>
        <input type="number" name="rate_limit_heure" class="form-control"
               value="<?= h($settings['rate_limit_heure'] ?? '30') ?>"
               min="1" max="500">
        <small style="color:var(--text3);font-size:.75rem">
          Défaut : 30. Au-delà, l'utilisateur reçoit une erreur 429.
        </small>
      </div>

      <div class="form-group" style="display:flex;align-items:center;gap:12px">
      </div>

      <!-- Permissions par rôle -->
      <div style="margin-top:1rem;border-top:1px solid var(--border);padding-top:1rem">
        <div style="font-size:.78rem;font-weight:700;color:var(--text2);margin-bottom:.75rem">🔐 Permissions par rôle</div>
        <div style="overflow-x:auto">
          <table style="width:100%;font-size:.75rem;border-collapse:collapse">
            <thead>
              <tr>
                <th style="text-align:left;padding:.4rem .5rem;color:var(--text3);border-bottom:1px solid var(--border)">Rôle</th>
                <th style="padding:.4rem .3rem;color:var(--text3);border-bottom:1px solid var(--border)">Chat</th>
                <th style="padding:.4rem .3rem;color:var(--text3);border-bottom:1px solid var(--border)">Vocal</th>
                <th style="padding:.4rem .3rem;color:var(--text3);border-bottom:1px solid var(--border)">Interv.</th>
                <th style="padding:.4rem .3rem;color:var(--text3);border-bottom:1px solid var(--border)">Rapports</th>
                <th style="padding:.4rem .3rem;color:var(--text3);border-bottom:1px solid var(--border)">Admin</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($perms as $p): ?>
            <tr style="border-bottom:1px solid rgba(255,255,255,.04)">
              <td style="padding:.4rem .5rem;font-weight:600"><?= ucfirst($p['role']) ?></td>
              <?php foreach (['chat','rempotage_vocal','interventions_vocal','rapports','admin_knowledge'] as $cap): ?>
              <td style="text-align:center;padding:.4rem .3rem">
                <input type="checkbox" name="perm[<?= $p['role'] ?>][<?= $cap ?>]" value="1"
                       <?= ($p[$cap]??0)?'checked':'' ?>
                       style="accent-color:var(--primary);width:15px;height:15px">
              </td>
              <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <button type="submit" class="btn btn-primary" style="margin-top:1rem;width:100%">💾 Sauvegarder</button>
    </form>
  </div>

  <!-- Conversations récentes -->
  <div class="card" style="display:flex;flex-direction:column">
    <div class="card-title" style="margin-bottom:16px">💬 Conversations récentes</div>
    <div style="flex:1;overflow-y:auto;max-height:460px">
      <?php if (empty($conv_recentes)): ?>
      <div style="text-align:center;padding:40px;color:var(--text3);font-size:.85rem">Aucune conversation.</div>
      <?php else: foreach ($conv_recentes as $c): ?>
      <div style="padding:.6rem 0;border-bottom:1px solid rgba(255,255,255,.04);font-size:.8rem">
        <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.25rem">
          <span style="font-weight:600"><?= h(trim(($c['prenom']??'').' '.($c['nom']??'Inconnu'))) ?></span>
          <span style="color:var(--text3);font-size:.7rem;background:var(--glass);padding:1px 7px;border-radius:20px"><?= h($c['page']??'') ?></span>
          <span style="color:var(--text3);font-size:.7rem;margin-left:auto"><?= date('d/m H:i',strtotime($c['cree_le'])) ?></span>
        </div>
        <div style="color:var(--text2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
          💬 <?= h(mb_substr($c['message']??'',0,70)) ?><?= mb_strlen($c['message']??'')>70?'…':'' ?>
        </div>
        <div style="margin-top:4px;display:flex;gap:6px;align-items:center;flex-wrap:wrap">
          <?php if ($c['action']): ?>
          <span style="background:rgba(16,185,129,.1);color:#34d399;border:1px solid rgba(16,185,129,.2);padding:1px 8px;border-radius:20px;font-size:.68rem">⚡ <?= h($c['action']) ?></span>
          <?php endif; ?>
          <?php if ($c['tokens']): ?>
          <span style="color:var(--text3);font-size:.68rem">🔢 <?= number_format($c['tokens']) ?> tokens</span>
          <?php endif; ?>
          <?php if (!empty($c['cout_eur'])): ?>
          <span style="color:var(--text3);font-size:.68rem">· <?= number_format((float)$c['cout_eur'],6,',','') ?> €</span>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

</div>

<!-- Reconnaissance photo -->
<div class="card">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <div>
      <div class="card-title" style="margin-bottom:4px">📷 Reconnaissance photo médicament</div>
      <div style="font-size:.78rem;color:var(--text2)">
        Photographie une boîte pour remplir automatiquement nom, lot et date. Utilise Claude Haiku Vision (~0,001€/photo).
        <?php if ($nb_scans > 0): ?> · <strong><?= $nb_scans ?> photo(s)</strong> analysée(s) aujourd'hui.<?php endif; ?>
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:10px;cursor:pointer" onclick="toggleScanPhoto()">
      <div class="toggle-big-slider <?= $scan_photo_actif?'on':'' ?>" id="scan-slider" style="pointer-events:none"></div>
      <span id="scan-label" style="font-size:.82rem;font-weight:700;min-width:65px;pointer-events:none;color:<?= $scan_photo_actif?'#10B981':'var(--text3)' ?>">
        <?= $scan_photo_actif ? 'Activé' : 'Désactivé' ?>
      </span>
    </div>
  </div>
</div>

<script>
var scanActif = <?= $scan_photo_actif ? 'true' : 'false' ?>;
function toggleScanPhoto() {
  var slider = document.getElementById('scan-slider');
  var label  = document.getElementById('scan-label');
  scanActif  = !scanActif;
  slider.classList.toggle('on', scanActif);
  label.textContent = scanActif ? 'Activé' : 'Désactivé';
  label.style.color = scanActif ? '#10B981' : 'var(--text3)';
  fetch(window.location.href, {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: 'action=toggle_scan_photo&scan_photo_actif='+(scanActif?'1':'0')+'&csrf_token='+encodeURIComponent('<?= csrf_token() ?>')
  }).catch(function(){ scanActif=!scanActif; slider.classList.toggle('on',scanActif); });
}
</script>

<?php
// © Cadurix v5.4 — Onglet admin "Modules" (+ Profils)
// Inclus depuis admin/index.php (auth + header déjà chargés).
// À placer dans : admin/modules.php

if (!defined('ADMIN_HUB')) { http_response_code(403); exit('Accès direct interdit.'); }

$reg = modules_registry();

// ── Sélecteur de site : 0 = configuration globale ───────────
$sites = [];
try { $sites = sites_accessibles($pdo); } catch (Throwable $e) {}
$site_ids_ok = array_map(fn($s) => (int)$s['id'], $sites);
$is_super = (bool)($_SESSION['super_admin'] ?? false);

$scope_valide = function (int $sid) use ($site_ids_ok, $is_super): int {
    if ($sid === 0) return 0;
    if ($is_super || in_array($sid, $site_ids_ok, true)) return $sid;
    return 0;
};

$sel_site = $scope_valide(isset($_GET['msite']) ? (int)$_GET['msite'] : 0);

$scope_label = function (int $sid) use ($sites): string {
    if ($sid === 0) return 'Configuration globale';
    foreach ($sites as $s) {
        if ((int)$s['id'] === $sid) return $s['org_nom'] . ' — ' . $s['nom'];
    }
    return "Site #$sid";
};

$saved_msg = '';

// ── POST : enregistrement de la config ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_modules') {
    csrf_check();
    $sid = $scope_valide((int)($_POST['site_id'] ?? 0));
    $n = 0;
    foreach ($reg as $cle => $def) {
        if ($sid !== 0 && empty($_POST['override'][$cle])) {
            module_reinitialiser($pdo, $cle, $sid);
            continue;
        }
        $actif  = !empty($_POST['actif'][$cle]);
        $params = [];
        foreach (($def['params'] ?? []) as $pk => $pdef) {
            $raw = $_POST['param'][$cle][$pk] ?? null;
            $val = match ($pdef['type']) {
                'bool'  => !empty($raw),
                'int'   => (int)$raw,
                'float' => (float)str_replace(',', '.', (string)$raw),
                default => trim((string)$raw),
            };
            if (in_array($pdef['type'], ['int', 'float'], true)) {
                if (isset($pdef['min']) && $val < $pdef['min']) $val = $pdef['min'];
                if (isset($pdef['max']) && $val > $pdef['max']) $val = $pdef['max'];
            }
            $params[$pk] = $val;
        }
        $expire = null;
        if (!empty($def['expirable']) && !empty($_POST['expire'][$cle])) {
            $ts = strtotime($_POST['expire'][$cle]);
            if ($ts) $expire = date('Y-m-d H:i:s', $ts);
        }
        if (module_definir($pdo, $cle, $sid, $actif, $params, $expire)) $n++;
    }
    $sel_site  = $sid;
    $saved_msg = $sid === 0
        ? "Configuration globale enregistrée ($n modules)."
        : "Configuration enregistrée pour ce site ($n modules).";
}

// ── POST : créer / capturer un profil ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'profil_creer') {
    csrf_check();
    $nom  = trim($_POST['profil_nom'] ?? '');
    $desc = trim($_POST['profil_desc'] ?? '');
    $sid  = $scope_valide((int)($_POST['site_id'] ?? 0));
    if ($nom === '') {
        $saved_msg = '⚠️ Le nom du profil est obligatoire.';
    } else {
        $config = modules_config_effective($pdo, $sid);
        if (module_profil_sauvegarder($pdo, $nom, $desc, $config, currentUserId())) {
            $saved_msg = "Profil « " . $nom . " » enregistré (" . count($config) . " modules capturés).";
        } else {
            $saved_msg = "⚠️ Impossible d'enregistrer le profil.";
        }
    }
    $sel_site = $sid;
}

// ── POST : appliquer un profil à un périmètre ───────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'profil_appliquer') {
    csrf_check();
    $pid   = (int)($_POST['profil_id'] ?? 0);
    $cible = $scope_valide((int)($_POST['cible_site'] ?? 0));
    $n = module_profil_appliquer($pdo, $pid, $cible);
    $prof = module_profil_charger($pdo, $pid);
    $saved_msg = $n > 0
        ? "Profil « " . ($prof['nom'] ?? '?') . " » appliqué à « " . $scope_label($cible) . " » ($n modules)."
        : "⚠️ Aucun module appliqué (profil introuvable ?).";
    $sel_site = $cible;
}

// ── POST : supprimer un profil ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'profil_supprimer') {
    csrf_check();
    $pid  = (int)($_POST['profil_id'] ?? 0);
    $prof = module_profil_charger($pdo, $pid);
    if ($prof && module_profil_supprimer($pdo, $pid)) {
        $saved_msg = "Profil « " . $prof['nom'] . " » supprimé.";
    }
}

// ── Lecture de l'état courant pour le site sélectionné ──────
$rows = [];
try {
    $st = $pdo->prepare("SELECT cle, site_id, actif, params, expire_le FROM modules WHERE site_id IN (0, ?)");
    $st->execute([$sel_site]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rows[(int)$r['site_id']][$r['cle']] = $r;
    }
} catch (Throwable $e) {}

$glob     = $rows[0] ?? [];
$siterows = $sel_site ? ($rows[$sel_site] ?? []) : [];

$state = [];
foreach ($reg as $cle => $def) {
    $has_site = $sel_site && isset($siterows[$cle]);
    $row      = $has_site ? $siterows[$cle] : ($glob[$cle] ?? null);
    $source   = $has_site ? 'site' : (isset($glob[$cle]) ? 'global' : 'defaut');
    $rowparams = ($row && $row['params']) ? (json_decode($row['params'], true) ?: []) : [];
    $pvals = [];
    foreach (($def['params'] ?? []) as $pk => $pdef) {
        $pvals[$pk] = $rowparams[$pk] ?? $pdef['defaut'];
    }
    $state[$cle] = [
        'actif'    => $row ? (bool)$row['actif'] : (bool)($def['defaut'] ?? false),
        'pvals'    => $pvals,
        'source'   => $source,
        'has_site' => $has_site,
        'expire'   => $row['expire_le'] ?? null,
    ];
}

// ── Groupes dans l'ordre du registre ───────────────────────
$groupes = [];
foreach ($reg as $cle => $def) {
    $groupes[$def['groupe'] ?? 'Autres'][] = $cle;
}

$profils = module_profil_lister($pdo);
?>
<style>
.mod-toolbar{display:flex;flex-wrap:wrap;align-items:center;gap:14px;margin-bottom:18px}
.mod-toolbar select{background:var(--glass);border:1px solid var(--border);color:var(--text);
  border-radius:10px;padding:8px 12px;font-size:13px;font-weight:600;cursor:pointer}
.mod-scope-badge{font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px}
.mod-scope-global{background:var(--primary-bg);color:var(--primary)}
.mod-scope-site{background:rgba(245,158,11,.15);color:#F59E0B}
.prof-card{background:var(--glass);border:1px solid var(--border);border-radius:14px;
  padding:16px 18px;margin-bottom:22px}
.prof-head{display:flex;align-items:center;gap:8px;font-weight:700;font-size:14px;margin-bottom:14px}
.prof-create{display:flex;flex-wrap:wrap;gap:10px;align-items:center;
  padding-bottom:14px;margin-bottom:14px;border-bottom:1px dashed var(--border)}
.prof-create input[type=text]{background:var(--bg2);border:1px solid var(--border);color:var(--text);
  border-radius:8px;padding:8px 12px;font-size:13px}
.prof-create input.nom{width:180px}.prof-create input.desc{flex:1;min-width:160px}
.prof-list{display:flex;flex-direction:column;gap:8px}
.prof-row{display:flex;flex-wrap:wrap;align-items:center;gap:10px;
  background:var(--bg2);border:1px solid var(--border);border-radius:10px;padding:10px 14px}
.prof-row .nm{font-weight:700;font-size:13px}
.prof-row .ds{font-size:12px;color:var(--text3)}
.prof-row form{display:flex;align-items:center;gap:8px;margin:0}
.prof-row select{background:var(--glass);border:1px solid var(--border);color:var(--text);
  border-radius:8px;padding:6px 10px;font-size:12.5px}
.prof-empty{font-size:12.5px;color:var(--text3);padding:6px 2px}
.mod-group-title{font-size:11px;text-transform:uppercase;letter-spacing:1.5px;color:var(--text2);
  font-weight:700;margin:26px 0 12px}
.mod-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:14px}
.mod-card{background:var(--glass);border:1px solid var(--border);border-radius:14px;
  padding:16px 18px;transition:opacity .15s}
.mod-card.off{opacity:.55}
.mod-card-head{display:flex;align-items:center;gap:12px}
.mod-card-icon{font-size:20px;line-height:1}
.mod-card-title{font-weight:700;font-size:14px}
.mod-card-key{font-size:11px;color:var(--text3);font-family:monospace}
.mod-src{font-size:10px;font-weight:700;padding:2px 7px;border-radius:12px;margin-left:auto}
.mod-src-site{background:rgba(245,158,11,.15);color:#F59E0B}
.mod-src-global{background:var(--primary-bg);color:var(--primary)}
.mod-src-defaut{background:var(--border2);color:var(--text2)}
.sw{position:relative;display:inline-block;width:44px;height:24px;flex-shrink:0}
.sw input{opacity:0;width:0;height:0}
.sw-slider{position:absolute;inset:0;background:var(--border2);border-radius:24px;cursor:pointer;transition:.2s}
.sw-slider::before{content:'';position:absolute;height:18px;width:18px;left:3px;top:3px;
  background:#fff;border-radius:50%;transition:.2s;box-shadow:0 1px 3px rgba(0,0,0,.3)}
.sw input:checked + .sw-slider{background:#10B981}
.sw input:checked + .sw-slider::before{transform:translateX(20px)}
.mod-params{margin-top:14px;padding-top:14px;border-top:1px dashed var(--border);
  display:flex;flex-direction:column;gap:10px}
.mod-param-row{display:flex;align-items:center;justify-content:space-between;gap:12px}
.mod-param-row label{font-size:12.5px;color:var(--text2)}
.mod-param-row input[type=number]{width:90px;background:var(--bg2);border:1px solid var(--border);
  color:var(--text);border-radius:8px;padding:6px 10px;font-size:13px;text-align:right}
.mod-param-row input[type=datetime-local]{background:var(--bg2);border:1px solid var(--border);
  color:var(--text);border-radius:8px;padding:6px 10px;font-size:12.5px}
.mod-override{display:flex;align-items:center;gap:8px;font-size:11.5px;color:var(--text2);
  margin-top:12px;padding-top:12px;border-top:1px dashed var(--border)}
.mod-save-bar{position:sticky;bottom:0;background:var(--bg);border-top:2px solid var(--border);
  padding:14px 0;margin-top:24px;display:flex;justify-content:flex-end;gap:12px;z-index:50}
</style>

<?php if ($saved_msg):
  $is_warn = str_starts_with($saved_msg, '⚠️'); ?>
  <div class="card" style="<?= $is_warn
      ? 'background:rgba(245,158,11,.12);border-color:#F59E0B;color:#F59E0B'
      : 'background:rgba(16,185,129,.12);border-color:#10B981;color:#10B981' ?>;
       padding:12px 16px;margin-bottom:18px;font-weight:600">
    <?= $is_warn ? '' : '✅ ' ?><?= h($saved_msg) ?>
  </div>
<?php endif; ?>

<form method="get" class="mod-toolbar">
  <input type="hidden" name="tab" value="modules">
  <label style="font-weight:700;font-size:13px">Configurer :</label>
  <select name="msite" onchange="this.form.submit()">
    <option value="0" <?= $sel_site === 0 ? 'selected' : '' ?>>🌐 Configuration globale (défaut)</option>
    <?php foreach ($sites as $s): ?>
      <option value="<?= (int)$s['id'] ?>" <?= $sel_site === (int)$s['id'] ? 'selected' : '' ?>>
        🏢 <?= h($s['org_nom'] . ' — ' . $s['nom']) ?>
      </option>
    <?php endforeach; ?>
  </select>
  <?php if ($sel_site === 0): ?>
    <span class="mod-scope-badge mod-scope-global">Base appliquée à tous les sites</span>
  <?php else: ?>
    <span class="mod-scope-badge mod-scope-site">Surcharges de ce site uniquement</span>
  <?php endif; ?>
</form>

<div class="prof-card">
  <div class="prof-head">📁 Profils de configuration</div>

  <form method="post" class="prof-create">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="profil_creer">
    <input type="hidden" name="site_id" value="<?= $sel_site ?>">
    <input type="text" class="nom" name="profil_nom" placeholder="Nom du profil" required>
    <input type="text" class="desc" name="profil_desc" placeholder="Description (facultatif)">
    <button type="submit" class="btn btn-primary btn-sm">
      💾 Capturer « <?= h($scope_label($sel_site)) ?> »
    </button>
  </form>

  <?php if (empty($profils)): ?>
    <div class="prof-empty">Aucun profil enregistré. Capture la config actuelle pour en créer un.</div>
  <?php else: ?>
    <div class="prof-list">
      <?php foreach ($profils as $p): ?>
        <div class="prof-row">
          <div>
            <div class="nm">📋 <?= h($p['nom']) ?></div>
            <?php if (!empty($p['description'])): ?>
              <div class="ds"><?= h($p['description']) ?></div>
            <?php endif; ?>
          </div>
          <form method="post" style="margin-left:auto">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="profil_appliquer">
            <input type="hidden" name="profil_id" value="<?= (int)$p['id'] ?>">
            <span style="font-size:12px;color:var(--text2)">Appliquer à</span>
            <select name="cible_site">
              <option value="0">🌐 Global</option>
              <?php foreach ($sites as $s): ?>
                <option value="<?= (int)$s['id'] ?>">🏢 <?= h($s['nom']) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-ghost btn-sm"
                    onclick="return confirm('Appliquer ce profil au périmètre choisi ?')">✅ Appliquer</button>
          </form>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="profil_supprimer">
            <input type="hidden" name="profil_id" value="<?= (int)$p['id'] ?>">
            <button type="submit" class="btn btn-ghost btn-sm" style="color:#EF4444"
                    onclick="return confirm('Supprimer ce profil ?')">🗑️</button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_modules">
  <input type="hidden" name="site_id" value="<?= $sel_site ?>">

  <?php foreach ($groupes as $groupe => $cles): ?>
    <div class="mod-group-title"><?= h($groupe) ?></div>
    <div class="mod-grid">
      <?php foreach ($cles as $cle):
        $def = $reg[$cle]; $s = $state[$cle];
        $has_params = !empty($def['params']); ?>
      <div class="mod-card <?= $s['actif'] ? '' : 'off' ?>" data-cle="<?= h($cle) ?>">
        <div class="mod-card-head">
          <span class="mod-card-icon"><?= $def['icone'] ?? '🧩' ?></span>
          <div>
            <div class="mod-card-title"><?= h($def['nom']) ?></div>
            <div class="mod-card-key"><?= h($cle) ?></div>
          </div>
          <span class="mod-src mod-src-<?= $s['source'] ?>">
            <?= $s['source'] === 'site' ? 'site' : ($s['source'] === 'global' ? 'global' : 'défaut') ?>
          </span>
          <label class="sw" style="margin-left:10px">
            <input type="checkbox" name="actif[<?= h($cle) ?>]" value="1"
                   <?= $s['actif'] ? 'checked' : '' ?>
                   onchange="this.closest('.mod-card').classList.toggle('off', !this.checked)">
            <span class="sw-slider"></span>
          </label>
        </div>

        <?php if ($has_params): ?>
          <div class="mod-params">
            <?php foreach ($def['params'] as $pk => $pdef):
              $val = $s['pvals'][$pk]; ?>
              <div class="mod-param-row">
                <label><?= h($pdef['label']) ?></label>
                <?php if ($pdef['type'] === 'bool'): ?>
                  <label class="sw">
                    <input type="checkbox" name="param[<?= h($cle) ?>][<?= h($pk) ?>]" value="1"
                           <?= !empty($val) ? 'checked' : '' ?>>
                    <span class="sw-slider"></span>
                  </label>
                <?php else: ?>
                  <input type="number" name="param[<?= h($cle) ?>][<?= h($pk) ?>]"
                         value="<?= h((string)$val) ?>"
                         <?= isset($pdef['min']) ? 'min="' . (float)$pdef['min'] . '"' : '' ?>
                         <?= isset($pdef['max']) ? 'max="' . (float)$pdef['max'] . '"' : '' ?>
                         <?= $pdef['type'] === 'float' ? 'step="0.1"' : 'step="1"' ?>>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php if (!empty($def['expirable'])): ?>
          <div class="mod-param-row" style="margin-top:12px">
            <label>⏱️ Expire le (vide = jamais)</label>
            <input type="datetime-local" name="expire[<?= h($cle) ?>]"
                   value="<?= $s['expire'] ? h(date('Y-m-d\TH:i', strtotime($s['expire']))) : '' ?>">
          </div>
        <?php endif; ?>

        <?php if ($sel_site !== 0): ?>
          <label class="mod-override">
            <input type="checkbox" name="override[<?= h($cle) ?>]" value="1"
                   <?= $s['has_site'] ? 'checked' : '' ?>>
            Personnaliser pour ce site (sinon hérite du global)
          </label>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>

  <div class="mod-save-bar">
    <button type="submit" class="btn btn-primary">💾 Enregistrer la configuration</button>
  </div>
</form>

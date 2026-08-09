<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.
if (!defined('ADMIN_HUB')) die('Accès direct interdit.');

require_once __DIR__ . '/../config/themes.php';
$THEMES = themes_predefinis();

$msg = ''; $msg_type = 'success';
$hx = fn($v,$d) => preg_match('/^#[0-9A-Fa-f]{6}$/', $v ?? '') ? $v : $d;
$sv = fn($v,$a,$d) => in_array($v ?? '', $a) ? $v : $d;

// ── Helpers paramètres ─────────────────────────────────────
function ap_gp(PDO $p, string $k, string $d): string {
    $s = $p->prepare("SELECT valeur FROM parametres WHERE cle=?");
    $s->execute([$k]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    return $r ? $r['valeur'] : $d;
}
function ap_sp(PDO $p, string $k, string $v): void {
    $p->prepare("INSERT INTO parametres (cle,valeur) VALUES (?,?) ON DUPLICATE KEY UPDATE valeur=VALUES(valeur)")
      ->execute([$k, $v]);
}

// ── Traitement POST ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'set_default') {
        $key = $_POST['theme_key'] ?? '';
        if (isset($THEMES[$key])) {
            ap_sp($pdo, 'theme_defaut', $key);
            $msg = 'Thème par défaut : ' . $THEMES[$key]['name'] . '.';
        } else {
            $msg = 'Thème inconnu.'; $msg_type = 'error';
        }

    } elseif ($action === 'set_lock') {
        // case "Autoriser" cochée = personnalisation autorisée = PAS de verrou
        $lock = isset($_POST['autoriser']) ? '0' : '1';
        ap_sp($pdo, 'theme_lock', $lock);
        $msg = $lock === '1'
            ? 'Personnalisation verrouillée : tout le monde garde le thème par défaut.'
            : 'Personnalisation autorisée : chacun choisit dans son profil.';

    } elseif ($action === 'save_logo') {
        $fields = [
            'logo_variante' => $sv($_POST['logo_variante_global'] ?? '', ['A','B','C'], 'A'),
            'logo_texte'    => isset($_POST['logo_texte']) ? '1' : '0',
        ];
        foreach ($fields as $k => $v) ap_sp($pdo, $k, $v);
        $msg = 'Logo mis à jour.';
    }
}

// ── État courant ───────────────────────────────────────────
$theme_defaut = ap_gp($pdo, 'theme_defaut', THEME_FALLBACK);
if (!isset($THEMES[$theme_defaut])) $theme_defaut = THEME_FALLBACK;
$theme_lock   = ap_gp($pdo, 'theme_lock', '0') === '1';

// Logo
$lv  = ap_gp($pdo, 'logo_variante', 'A');
$ltx = ap_gp($pdo, 'logo_texte',    '1');
$has_logo = file_exists(__DIR__ . '/../logo.php');
?>
<style>
/* ── Layout ── */
.ap-wrap   { display:grid; grid-template-columns:300px 1fr; gap:24px; align-items:start; }
.ap-panel  { background:var(--bg2); border:1px solid var(--border); border-radius:14px; overflow:hidden; }
.ap-panel-head { padding:14px 18px; border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; }
.ap-panel-head h3 { font-size:.92rem; font-weight:700; margin:0; }
.ap-panel-body { padding:16px; }

/* ── Onglets ── */
.ap-tabs { display:flex; gap:2px; border-bottom:1px solid var(--border); margin-bottom:24px; }
.ap-tab  { padding:9px 20px; font-size:.85rem; font-weight:600; color:var(--text2); background:none; border:none; border-bottom:2px solid transparent; margin-bottom:-1px; cursor:pointer; border-radius:8px 8px 0 0; transition:.15s; }
.ap-tab:hover { color:var(--text); background:var(--glass); }
.ap-tab.on    { color:var(--primary); border-bottom-color:var(--primary); }

/* ── Éditeur logo ── */
.ed-section { margin-bottom:20px; }
.ed-label   { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--text3); display:block; margin-bottom:8px; }
.ed-grid2   { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.color-row  { display:flex; align-items:center; gap:8px; }
.color-row input[type=color] { width:38px; height:38px; border-radius:8px; border:1px solid var(--border); cursor:pointer; padding:2px; flex-shrink:0; }
.color-row input[type=text]  { flex:1; background:var(--bg); border:1px solid var(--border); border-radius:8px; padding:7px 10px; color:var(--text); font-family:var(--mono,monospace); font-size:.82rem; }
.color-row input[type=text]:focus { outline:none; border-color:var(--primary); }
.logo-variants { display:flex; gap:10px; }
.logo-var { flex:1; border:2px solid var(--border); border-radius:10px; padding:12px 8px; cursor:pointer; text-align:center; transition:.15s; }
.logo-var:has(input:checked) { border-color:var(--primary); background:var(--primary-bg); }
.logo-var:hover { border-color:var(--primary-light, #93c5fd); }
.logo-var .lv-icon { font-size:24px; margin-bottom:4px; }
.logo-var span  { font-size:.75rem; color:var(--text2); display:block; }

/* ── Verrou de personnalisation ── */
.lockbar { display:flex; align-items:center; gap:13px; background:var(--bg2); border:1px solid var(--border); border-radius:13px; padding:14px 18px; margin-bottom:22px; }
.lockbar .ic { width:36px; height:36px; border-radius:10px; background:var(--primary-bg); color:var(--primary); display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0; }
.lockbar .tx { flex:1; }
.lockbar .tt { font-size:.9rem; font-weight:700; }
.lockbar .ts { font-size:.75rem; color:var(--text3); margin-top:1px; }
.tgl { position:relative; display:inline-block; width:46px; height:26px; flex-shrink:0; }
.tgl input { opacity:0; width:0; height:0; }
.tgl .sl { position:absolute; inset:0; background:var(--border2); border-radius:20px; cursor:pointer; transition:.2s; }
.tgl .sl::before { content:""; position:absolute; height:20px; width:20px; left:3px; top:3px; background:#fff; border-radius:50%; transition:.2s; }
.tgl input:checked + .sl { background:var(--primary); }
.tgl input:checked + .sl::before { transform:translateX(20px); }

/* ── Grille de choix du thème par défaut ── */
.thp-glabel { font-size:11px; font-weight:800; letter-spacing:.08em; text-transform:uppercase; color:var(--text3); margin:4px 2px 10px; }
.thp-grid   { display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:12px; margin-bottom:22px; }
.thp-card   { border:2px solid var(--border); border-radius:13px; padding:8px; background:var(--bg2); cursor:pointer; transition:.15s; text-align:left; width:100%; font:inherit; color:inherit; }
.thp-card:hover { transform:translateY(-2px); box-shadow:0 8px 22px rgba(0,0,0,.12); }
.thp-card.def   { border-color:var(--primary); box-shadow:0 0 0 3px var(--primary-bg); }
.thp-prev { display:flex; height:58px; border-radius:8px; overflow:hidden; border:1px solid rgba(128,128,128,.15); }
.thp-sb   { width:34%; padding:6px 5px; }
.thp-dot  { height:5px; border-radius:3px; margin-bottom:4px; opacity:.5; }
.thp-dot.on { opacity:1; }
.thp-bd   { flex:1; padding:8px 7px; }
.thp-btn  { height:12px; width:44px; border-radius:4px; margin-bottom:7px; }
.thp-ln   { height:5px; border-radius:3px; margin-bottom:5px; }
.thp-meta { display:flex; align-items:center; justify-content:space-between; margin-top:8px; }
.thp-name { font-size:.82rem; font-weight:700; }
.thp-badge{ font-size:9px; font-weight:800; color:#fff; background:var(--primary); padding:2px 7px; border-radius:6px; }

@media(max-width:900px){ .ap-wrap { grid-template-columns:1fr; } }
</style>

<!-- Header -->
<div class="page-header" style="margin-bottom:20px">
  <div class="page-title-group">
    <div class="page-title">🎨 Apparence</div>
    <div class="page-sub">Thème par défaut du site &amp; logo</div>
  </div>
</div>

<?php if ($msg): ?>
<div class="flash flash-<?= $msg_type==='error'?'error':'success' ?>" style="margin-bottom:16px"><?= $msg_type==='error'?'⚠️':'✅' ?> <?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<?php if (!$has_logo): ?>
<div class="flash flash-warning" style="margin-bottom:16px">⚠️ <strong>logo.php</strong> manquant à la racine — l'aperçu logo ne sera pas disponible.</div>
<?php endif; ?>

<!-- Onglets -->
<div class="ap-tabs">
  <button class="ap-tab on" onclick="apTab('themes',this)">🎨 Thèmes</button>
  <button class="ap-tab"    onclick="apTab('logo',this)">🖼️ Logo global</button>
</div>

<!-- ════════ THÈMES ════════ -->
<div id="ap-themes">

  <!-- Verrou de personnalisation -->
  <form method="post" class="lockbar">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="set_lock">
    <span class="ic"><?= $theme_lock ? '🔒' : '🔓' ?></span>
    <div class="tx">
      <div class="tt">Autoriser les utilisateurs à personnaliser leur thème</div>
      <div class="ts">Activé : chacun choisit dans son profil. Désactivé : tout le monde garde le thème par défaut.</div>
    </div>
    <label class="tgl">
      <input type="checkbox" name="autoriser" <?= $theme_lock ? '' : 'checked' ?> onchange="this.form.submit()">
      <span class="sl"></span>
    </label>
  </form>

  <?php
  // rendu d'une grille de cartes-thème
  $render_grid = function(array $list) use ($theme_defaut) {
      foreach ($list as $key => $t):
          $isdef = ($key === $theme_defaut);
  ?>
    <form method="post" style="display:contents">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="set_default">
      <input type="hidden" name="theme_key" value="<?= htmlspecialchars($key) ?>">
      <button type="submit" class="thp-card <?= $isdef ? 'def' : '' ?>" title="Définir comme thème par défaut">
        <div class="thp-prev" style="background:<?= $t['bg'] ?>">
          <div class="thp-sb" style="background:<?= $t['sidebar'] ?>">
            <div class="thp-dot" style="background:<?= $t['text'] ?>"></div>
            <div class="thp-dot on" style="background:<?= $t['accent'] ?>"></div>
            <div class="thp-dot" style="background:<?= $t['text'] ?>"></div>
          </div>
          <div class="thp-bd">
            <div class="thp-btn" style="background:<?= $t['accent'] ?>"></div>
            <div class="thp-ln" style="background:<?= $t['border'] ?>;width:90%"></div>
            <div class="thp-ln" style="background:<?= $t['border'] ?>;width:60%"></div>
          </div>
        </div>
        <div class="thp-meta">
          <span class="thp-name"><?= ($t['emoji'] ?? '') ?> <?= htmlspecialchars($t['name']) ?></span>
          <?php if ($isdef): ?><span class="thp-badge">✓ Défaut</span><?php endif ?>
        </div>
      </button>
    </form>
  <?php
      endforeach;
  };
  $clairs  = array_filter($THEMES, fn($t) => ($t['mode'] ?? 'light') === 'light');
  $sombres = array_filter($THEMES, fn($t) => ($t['mode'] ?? 'dark')  === 'dark');
  ?>

  <div class="thp-glabel">☀️ Clairs</div>
  <div class="thp-grid"><?php $render_grid($clairs); ?></div>

  <div class="thp-glabel">🌙 Sombres</div>
  <div class="thp-grid"><?php $render_grid($sombres); ?></div>

  <p style="font-size:.72rem;color:var(--text3);margin-top:2px">Cliquez sur un thème pour le définir comme défaut du site. Les utilisateurs qui n'ont pas personnalisé leur thème verront celui-ci.</p>
</div>

<!-- ════════ LOGO ════════ -->
<div id="ap-logo" style="display:none">
<div class="ap-wrap">

  <!-- Formulaire -->
  <div class="ap-panel" style="grid-column:1">
    <div class="ap-panel-head"><h3>🖼️ Configuration du logo</h3></div>
    <div class="ap-panel-body">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_logo">

        <div class="ed-section">
          <label class="ed-label">Variante</label>
          <div class="logo-variants">
            <?php foreach (['A'=>['Croix médicale','➕'],'B'=>['Caducée','🐍'],'C'=>['Urgence','🚨']] as $k=>[$v,$emoji]): ?>
            <label class="logo-var">
              <input type="radio" name="logo_variante_global" value="<?= $k ?>" <?= $lv===$k?'checked':'' ?> hidden onchange="rLP()">
              <?php if($has_logo): ?>
              <img src="/logo.php?size=48&icon&variante=<?= $k ?>" id="lg<?= $k ?>" style="width:44px;height:44px;margin:0 auto;display:block" alt="<?= $k ?>">
              <?php else: ?>
              <div class="lv-icon"><?= $emoji ?></div>
              <?php endif ?>
              <span><?= $v ?></span>
            </label>
            <?php endforeach ?>
          </div>
        </div>

        <div class="ed-section">
          <div style="display:flex;align-items:flex-start;gap:9px;background:var(--primary-bg);border:1px solid var(--border);border-radius:10px;padding:11px 13px">
            <span style="font-size:15px">🎨</span>
            <div style="font-size:.78rem;color:var(--text2);line-height:1.4">Les couleurs du logo suivent le <strong>thème par défaut</strong> du site. Pour les modifier, passe par l'onglet <strong>Thèmes</strong>.</div>
          </div>
        </div>

        <div class="ed-section">
          <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:.88rem">
            <input type="checkbox" name="logo_texte" <?= $ltx==='1'?'checked':'' ?> style="accent-color:var(--primary);width:16px;height:16px">
            Afficher "CADURIX" sous le logo
          </label>
        </div>

        <button type="submit" class="btn btn-primary">💾 Enregistrer</button>
      </form>
    </div>
  </div>

  <!-- Aperçu logo -->
  <div class="ap-panel">
    <div class="ap-panel-head"><h3>👁️ Aperçu</h3></div>
    <div class="ap-panel-body">
      <?php if($has_logo): ?>
      <div style="margin-bottom:16px">
        <div style="font-size:.75rem;color:var(--text3);margin-bottom:8px;font-weight:600">FOND CLAIR</div>
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:24px;text-align:center">
          <img id="lpL" src="/logo.php?size=100" alt="logo" style="max-height:80px">
        </div>
      </div>
      <div>
        <div style="font-size:.75rem;color:var(--text3);margin-bottom:8px;font-weight:600">FOND SOMBRE</div>
        <div style="background:#0f172a;border:1px solid #1e293b;border-radius:10px;padding:24px;text-align:center">
          <img id="lpD" src="/logo.php?size=100&dark" alt="logo" style="max-height:80px">
        </div>
      </div>
      <?php else: ?>
      <div style="background:var(--bg3);border:2px dashed var(--border2);border-radius:10px;padding:48px 24px;text-align:center;color:var(--text3)">
        <div style="font-size:2rem;margin-bottom:10px">🖼️</div>
        <div style="font-size:.88rem;font-weight:600">logo.php manquant</div>
        <div style="font-size:.78rem;margin-top:4px">Déposez <code>logo.php</code> à la racine du projet</div>
      </div>
      <?php endif ?>
    </div>
  </div>

</div>
</div>

<script>
// ── Onglets ───────────────────────────────────────────────
function apTab(n, el) {
  ['themes','logo'].forEach(t => document.getElementById('ap-'+t).style.display = 'none');
  document.querySelectorAll('.ap-tab').forEach(t => t.classList.remove('on'));
  document.getElementById('ap-'+n).style.display = 'block';
  el.classList.add('on');
}

// ── Logo : sync couleurs + aperçu live ────────────────────
function rLP() {
  const lL = document.getElementById('lpL');
  if (!lL) return;
  const lD  = document.getElementById('lpD');
  const sel = document.querySelector('[name=logo_variante_global]:checked');
  const v   = sel ? sel.value : 'A';
  const ts  = Date.now();
  lL.src = `/logo.php?size=100&variante=${v}&_=${ts}`;
  if (lD) lD.src = `/logo.php?size=100&dark&variante=${v}&_=${ts}`;
}
</script>

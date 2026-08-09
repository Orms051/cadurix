<?php
// © Cadurix v5.4 — Tous droits réservés.
/**
 * admin/site_permissions.php — Matrice « Modules par site »
 * Grille modules (lignes) × rôles (colonnes), 3 états par case.
 * Périmètre : 0 = défauts globaux (role_permissions), >0 = surcharges du site (site_role_permissions).
 * Inclus par le hub admin (onglet « Permissions par site » / onglet=roles).
 */
if (!defined('ADMIN_HUB')) { require_once '../includes/auth.php'; requireAdmin(); }
require_once __DIR__ . '/../config/perms_modules.php';
require_once __DIR__ . '/../config/roles.php';

$is_super = $_SESSION['super_admin'] ?? false;
$success = $error = '';

// ── Sites accessibles ───────────────────────────────────────
if ($is_super) {
    $sites = $pdo->query("SELECT s.id,s.nom,o.nom AS org_nom FROM sites s JOIN organisations o ON o.id=s.organisation_id WHERE s.actif=1 ORDER BY o.nom,s.nom")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt_s = $pdo->prepare("SELECT s.id,s.nom,o.nom AS org_nom FROM sites s JOIN organisations o ON o.id=s.organisation_id JOIN user_site_permissions usp ON usp.site_id=s.id WHERE usp.user_id=? AND usp.role='administrateur' AND s.actif=1 ORDER BY s.nom");
    $stmt_s->execute([currentUserId()]);
    $sites = $stmt_s->fetchAll(PDO::FETCH_ASSOC);
}

// ── Vocabulaire (sources uniques) ───────────────────────────
$modules_config  = modules_permissions();
$roles_editables = array_keys(roles_editables());
$roles_meta      = roles_editables();
$sections        = modules_sections();

// Vérifie qu'un périmètre est autorisé (0 = global → super admin seulement)
function perim_autorise(int $sid, bool $is_super, array $sites): bool {
    if ($sid === 0) return $is_super;
    foreach ($sites as $s) if ((int)$s['id'] === $sid) return true;
    return false;
}

// ── POST (enregistrer / réinitialiser) ──────────────────────
// NB : en mode hub, users.php a DÉJÀ fait csrf_check() → ne pas le refaire (double check = échec).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!defined('ADMIN_HUB')) csrf_check();

    $sid    = (int)($_POST['site_id'] ?? 0);
    $pact   = $_POST['perm_action'] ?? 'save';
    $matrix = $_POST['perms'] ?? [];

    if (!perim_autorise($sid, $is_super, $sites)) {
        $error = 'Accès refusé à ce périmètre.';
    } elseif ($pact === 'reset') {
        if ($sid > 0) {
            $pdo->prepare("DELETE FROM site_role_permissions WHERE site_id=?")->execute([$sid]);
            audit_log($pdo,'RESET_SITE_PERMISSIONS','site_role_permissions',$sid);
        }
        $success = 'Site réinitialisé aux défauts globaux.';
    } else {
        if ($sid === 0) {
            // Défauts globaux → role_permissions
            // UPSERT ciblé (ne touche QUE les modules visibles) — préserve action_X + modules coupés
            $ins = $pdo->prepare("INSERT INTO role_permissions (role,module,niveau) VALUES (?,?,?) ON DUPLICATE KEY UPDATE niveau=VALUES(niveau)");
            foreach ($roles_editables as $role) {
                foreach (array_keys($modules_config) as $mk) {
                    if (!module_perm_visible($mk)) continue;
                    $niv = (int)($matrix[$role][$mk] ?? 0);
                    if ($niv < 0 || $niv > 2) $niv = 0;
                    $ins->execute([$role, $mk, $niv]);
                }
            }
            audit_log($pdo,'MODIF_ROLE_PERMISSIONS','role_permissions',0);
        } else {
            // Surcharges du site → site_role_permissions (uniquement les écarts vs global)
            $gstmt = $pdo->prepare("SELECT module,niveau FROM role_permissions WHERE role=?");
            $del   = $pdo->prepare("DELETE FROM site_role_permissions WHERE site_id=? AND role=?");
            $ins   = $pdo->prepare("INSERT INTO site_role_permissions (site_id,role,module,niveau) VALUES (?,?,?,?)");
            foreach ($roles_editables as $role) {
                $gstmt->execute([$role]);
                $global = [];
                foreach ($gstmt->fetchAll(PDO::FETCH_ASSOC) as $g) $global[$g['module']] = (int)$g['niveau'];
                $del->execute([$sid, $role]);
                foreach (array_keys($modules_config) as $mk) {
                    if (!module_perm_visible($mk)) continue;
                    $niv = (int)($matrix[$role][$mk] ?? 0);
                    if ($niv < 0 || $niv > 2) $niv = 0;
                    if ($niv !== ($global[$mk] ?? 0)) $ins->execute([$sid, $role, $mk, $niv]); // écart = surcharge
                }
            }
            audit_log($pdo,'MODIF_SITE_PERMISSIONS','site_role_permissions',$sid);
        }
        $success = 'Permissions enregistrées.';
    }
    // Pas de redirection ici : en mode hub les en-têtes sont déjà envoyés.
    // L'enregistrement est idempotent (DELETE+INSERT), on ré-affiche la grille à jour.
}

// ── Périmètre sélectionné ───────────────────────────────────
$selected_site = isset($_GET['site_id']) ? (int)$_GET['site_id'] : (int)($_POST['site_id'] ?? ($sites[0]['id'] ?? 0));
if (!perim_autorise($selected_site, $is_super, $sites)) $selected_site = (int)($sites[0]['id'] ?? 0);
$copy_from = isset($_GET['copy_from']) ? (int)$_GET['copy_from'] : null;

// ── Chargement de la matrice (tous rôles × tous modules) ────
function load_matrix(PDO $pdo, int $site_id, array $roles, array $modkeys): array {
    $global = [];
    foreach ($pdo->query("SELECT role,module,niveau FROM role_permissions")->fetchAll(PDO::FETCH_ASSOC) as $r)
        $global[$r['role']][$r['module']] = (int)$r['niveau'];
    $site = [];
    if ($site_id > 0) {
        $st = $pdo->prepare("SELECT role,module,niveau FROM site_role_permissions WHERE site_id=?");
        $st->execute([$site_id]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $site[$r['role']][$r['module']] = (int)$r['niveau'];
    }
    $out = [];
    foreach ($roles as $role) foreach ($modkeys as $mk) {
        $g       = $global[$role][$mk] ?? 0;
        $hasOver = isset($site[$role][$mk]);
        $out[$role][$mk] = [
            'global' => $g,
            'eff'    => $hasOver ? $site[$role][$mk] : $g,
            'over'   => ($site_id > 0 && $hasOver),
        ];
    }
    return $out;
}

$modkeys = array_keys($modules_config);
$matrix  = $selected_site!==null ? load_matrix($pdo, $selected_site, $roles_editables, $modkeys) : [];

// Copier depuis un autre périmètre (pré-remplit sans enregistrer)
$copied_label = '';
if ($copy_from !== null && perim_autorise($copy_from, $is_super, $sites)) {
    $src = load_matrix($pdo, $copy_from, $roles_editables, $modkeys);
    foreach ($matrix as $role => $mods) foreach ($mods as $mk => $cell) {
        $matrix[$role][$mk]['eff']  = $src[$role][$mk]['eff'];
        $matrix[$role][$mk]['over'] = ($selected_site > 0 && $src[$role][$mk]['eff'] !== $cell['global']);
    }
    $copied_label = ($copy_from === 0) ? 'Défauts globaux'
        : (function($sites,$id){ foreach($sites as $s) if((int)$s['id']===$id) return $s['org_nom'].' — '.$s['nom']; return 'site'; })($sites,$copy_from);
}

$icons = ['✕','👁','✓'];
function perim_label(int $id, array $sites): string {
    if ($id === 0) return '🌐 Défauts globaux (tous les sites)';
    foreach ($sites as $s) if ((int)$s['id'] === $id) return '🏥 '.$s['org_nom'].' — '.$s['nom'];
    return 'Site';
}
?>

<style>
/* ════════ Matrice Modules par site — v5.4 ════════ */
.sp-bar{display:flex;align-items:center;gap:12px;margin-bottom:14px;flex-wrap:wrap}
.sp-bar .sp-spacer{flex:1}
.sp-copy{display:inline-flex;align-items:center;gap:7px;font-family:inherit;font-size:13px;font-weight:700;color:var(--text2);background:var(--bg);border:1.5px solid var(--border);border-radius:11px;padding:9px 14px;cursor:pointer}
.sp-copy:hover{border-color:var(--border2);color:var(--text)}
.sp-pick{display:flex;align-items:center;gap:8px}
.sp-pick label{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--text3)}
.sp-pick select{font-family:inherit;font-size:13.5px;font-weight:600;color:var(--text);background:var(--bg);border:1.5px solid var(--border);border-radius:11px;padding:9px 14px;cursor:pointer;min-width:240px}
.sp-pick select:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(var(--primary-rgb),.12)}
.sp-hint{display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--text2);background:rgba(var(--primary-rgb),.08);border:1px solid rgba(var(--primary-rgb),.18);padding:9px 14px;border-radius:11px;margin-bottom:16px}
.sp-card{background:var(--bg);border-radius:18px;box-shadow:0 12px 30px rgba(30,40,80,.10);overflow:visible}
.sp-matrix{width:100%;border-collapse:collapse;table-layout:fixed}
.sp-matrix thead th{background:var(--bg2);padding:10px 8px;font-size:11px;font-weight:800;color:var(--text2);border-bottom:1px solid var(--border)}
.sp-matrix thead th:first-child{text-align:left;padding-left:20px;width:32%;border-top-left-radius:18px}
.sp-matrix thead th:last-child{border-top-right-radius:18px}
.sp-rh{display:flex;flex-direction:column;align-items:center;gap:3px;cursor:pointer;padding:4px 2px;border-radius:9px;transition:background .12s}
.sp-rh:hover{background:var(--bg3)}
.sp-rh .ri{width:28px;height:28px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:15px}
.sp-rh .rl{font-size:10.5px;font-weight:800;display:flex;align-items:center;gap:3px}
.sp-rh .rl .ti{font-size:11px;color:var(--text3)}
.sp-sec td{background:linear-gradient(180deg,var(--bg2),var(--bg));padding:7px 20px;font-size:9.5px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:var(--text3);border-bottom:1px solid var(--border)}
.sp-mod{border-bottom:1px solid var(--border)}
.sp-mod:hover{background:var(--bg2)}
.sp-mod td{padding:9px 8px;text-align:center}
.sp-mname{text-align:left!important;padding-left:20px!important;font-weight:600;font-size:13.5px;display:flex;align-items:center;gap:9px;cursor:pointer}
.sp-mname:hover{color:var(--primary)}
.sp-mname .mi{font-size:15px;width:20px;text-align:center}
.sp-mname .gear{margin-left:auto;margin-right:8px;font-size:13px;color:var(--text3);opacity:0;transition:opacity .12s}
.sp-mod:hover .sp-mname .gear{opacity:1}
.sp-cell{display:inline-flex;align-items:center;justify-content:center;width:58px;padding:6px 0;border-radius:20px;font-size:12px;font-weight:800;cursor:pointer;border:1.5px solid transparent;transition:all .12s;user-select:none;position:relative}
.sp-cell .ic{pointer-events:none}
.sp-cell.s0{background:var(--bg3);color:var(--text3)}
.sp-cell.s1{background:rgba(59,130,246,.12);color:#3b82f6;border-color:rgba(59,130,246,.25)}
.sp-cell.s2{background:rgba(22,163,74,.12);color:#16a34a;border-color:rgba(22,163,74,.28)}
.sp-cell:hover{transform:scale(1.06)}
.sp-cell .ov{position:absolute;top:-3px;right:-3px;width:9px;height:9px;border-radius:50%;background:#f59e0b;border:2px solid var(--bg);display:none}
.sp-cell.over .ov{display:block}
.sp-save{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 20px;border-top:1px solid var(--border);background:var(--bg2);border-bottom-left-radius:18px;border-bottom-right-radius:18px}
.sp-legend{display:flex;gap:14px;font-size:11.5px;color:var(--text2);flex-wrap:wrap;align-items:center}
.sp-legend span{display:inline-flex;align-items:center;gap:5px}
.sp-lg{width:12px;height:12px;border-radius:4px}
#sp-dirty{display:none;align-items:center;gap:5px;font-size:12px;font-weight:800;color:#d97706;background:rgba(245,158,11,.12);padding:4px 11px;border-radius:20px}
#sp-dirty.show{display:inline-flex}
.sp-btn{border:none;cursor:pointer;font-family:inherit;font-weight:700;border-radius:11px;font-size:13.5px;display:inline-flex;align-items:center;gap:7px}
.sp-btn-primary{background:var(--primary);color:#fff;padding:10px 18px;box-shadow:0 6px 16px rgba(var(--primary-rgb),.30);transition:box-shadow .2s}
.sp-btn-primary.pulse{animation:sp-pulse 1.4s infinite}
@keyframes sp-pulse{0%,100%{box-shadow:0 6px 16px rgba(var(--primary-rgb),.30)}50%{box-shadow:0 6px 22px rgba(var(--primary-rgb),.6)}}
.sp-btn-ghost{background:var(--bg);color:var(--text2);border:1.5px solid var(--border);padding:10px 16px}
.sp-flash{padding:10px 16px;border-radius:11px;margin-bottom:14px;font-size:13.5px;font-weight:600}
.sp-flash.ok{background:rgba(22,163,74,.1);color:#16a34a;border:1px solid rgba(22,163,74,.25)}
.sp-flash.err{background:rgba(239,68,68,.1);color:#dc2626;border:1px solid rgba(239,68,68,.25)}
#sp-pop{position:absolute;z-index:50;background:var(--bg);border:1px solid var(--border);border-radius:13px;box-shadow:0 16px 40px rgba(30,40,80,.22);padding:6px;display:none;min-width:180px}
#sp-pop.show{display:block}
#sp-pop .pt{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:var(--text3);padding:6px 10px 4px}
#sp-pop button{display:flex;align-items:center;gap:9px;width:100%;border:none;background:none;font-family:inherit;font-size:13px;font-weight:600;color:var(--text);padding:8px 10px;border-radius:9px;cursor:pointer;text-align:left}
#sp-pop button:hover{background:var(--bg2)}
#sp-pop .ico{width:20px;text-align:center}
.sp-empty{text-align:center;padding:3rem;color:var(--text3)}
@media(max-width:900px){.sp-card{overflow-x:auto}.sp-matrix{min-width:740px}}
</style>

<?php if (empty($sites) && !$is_super): ?>
<div class="sp-empty">Aucun site administrable.</div>
<?php else: ?>

<?php if ($success): ?><div class="sp-flash ok">✅ <?= h($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="sp-flash err">⚠️ <?= h($error) ?></div><?php endif; ?>

<div class="sp-bar">
  <button class="sp-copy" onclick="spCopyMenu(this)"><i class="ti ti-copy"></i> Copier depuis…</button>
  <div class="sp-spacer"></div>
  <div class="sp-pick">
    <label>Périmètre</label>
    <select id="sp-perim" onchange="spSwitch(this.value)">
      <?php if ($is_super): ?><option value="0" <?= $selected_site===0?'selected':'' ?>>🌐 Défauts globaux (tous les sites)</option><?php endif; ?>
      <?php foreach ($sites as $s): ?>
      <option value="<?= $s['id'] ?>" <?= (int)$s['id']===$selected_site?'selected':'' ?>>🏥 <?= h($s['org_nom'].' — '.$s['nom']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
</div>

<div class="sp-hint">
  <i class="ti ti-info-circle"></i>
  <span>
    <?php if ($copied_label): ?>📋 Configuration copiée depuis <strong><?= h($copied_label) ?></strong> — pensez à enregistrer.
    <?php elseif ($selected_site===0): ?>Vous éditez les <strong>défauts globaux</strong> (base de tous les sites). Clic case : ✕→👁→✓.
    <?php else: ?>Vous éditez <strong><?= h(perim_label($selected_site,$sites)) ?></strong>. Point <span style="color:#f59e0b;font-weight:800">●</span> = surcharge du défaut global. Clic case : ✕→👁→✓.
    <?php endif; ?>
  </span>
</div>

<form method="post" id="sp-form" action="?tab=users&onglet=roles&site_id=<?= $selected_site ?>" onsubmit="spDirty=false">
  <?= csrf_field() ?>
  <input type="hidden" name="site_id" value="<?= $selected_site ?>">
  <input type="hidden" name="perm_action" id="sp-action" value="save">

  <div class="sp-card">
    <table class="sp-matrix">
      <thead>
        <tr>
          <th>Module</th>
          <?php foreach ($roles_editables as $role): $m = $roles_meta[$role]; ?>
          <th><div class="sp-rh" onclick="spCol(event,'<?= $role ?>')">
            <span class="ri" style="background:<?= $m['color'] ?>22"><?= $m['icon'] ?></span>
            <span class="rl" style="color:<?= $m['color'] ?>"><?= h(preg_replace('/ \/ .*/','',$m['label'])) ?> <i class="ti ti-chevron-down"></i></span>
          </div></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($sections as $section): ?>
        <tr class="sp-sec"><td colspan="<?= count($roles_editables)+1 ?>"><?= h($section) ?></td></tr>
        <?php foreach ($modules_config as $mk => $mc): if ($mc['section'] !== $section) continue; if (!module_perm_visible($mk)) continue; ?>
        <tr class="sp-mod">
          <td class="sp-mname" onclick="spRow(event,this)"><span class="mi"><?= $mc['icon'] ?? '•' ?></span><?= h($mc['label']) ?><i class="ti ti-adjustments gear"></i></td>
          <?php foreach ($roles_editables as $role): $c = $matrix[$role][$mk] ?? ['eff'=>0,'global'=>0,'over'=>false]; ?>
          <td>
            <span class="sp-cell s<?= $c['eff'] ?> <?= $c['over']?'over':'' ?>" data-g="<?= $selected_site===0?$c['eff']:$c['global'] ?>" onclick="spCyc(this)">
              <span class="ic"><?= $icons[$c['eff']] ?></span><span class="ov"></span>
              <input type="hidden" name="perms[<?= $role ?>][<?= $mk ?>]" value="<?= $c['eff'] ?>">
            </span>
          </td>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="sp-save">
      <div class="sp-legend">
        <span><span class="sp-lg" style="background:var(--bg3)"></span> ✕ Aucun</span>
        <span><span class="sp-lg" style="background:rgba(59,130,246,.4)"></span> 👁 Lecture</span>
        <span><span class="sp-lg" style="background:rgba(22,163,74,.4)"></span> ✓ Complet</span>
        <?php if ($selected_site>0): ?><span><span style="color:#f59e0b;font-weight:800">●</span> surcharge site</span><?php endif; ?>
        <span id="sp-dirty"><i class="ti ti-point-filled"></i> Non enregistré</span>
      </div>
      <div style="display:flex;gap:8px">
        <?php if ($selected_site>0): ?>
        <button type="button" class="sp-btn sp-btn-ghost" onclick="spReset()"><i class="ti ti-rotate"></i> Réinitialiser au global</button>
        <?php endif; ?>
        <button type="submit" class="sp-btn sp-btn-primary" id="sp-savebtn"><i class="ti ti-device-floppy"></i> Enregistrer</button>
      </div>
    </div>
  </div>
</form>
<div id="sp-pop"></div>

<script>
(function(){
const ICON=['✕','👁','✓'], LBL=['Aucun','Lecture','Complet'];
const pop=document.getElementById('sp-pop');
window.spDirty=false;
const COPY_BANNER=<?= $copied_label ? 'true' : 'false' ?>;
const PERIM=<?= (int)$selected_site ?>;

function markDirty(){spDirty=true;document.getElementById('sp-dirty').classList.add('show');document.getElementById('sp-savebtn').classList.add('pulse');}
window.spCyc=function(el){spSetCell(el,(+el.querySelector('input').value+1)%3);};
function spSetCell(el,s){
  el.classList.remove('s0','s1','s2','over');el.classList.add('s'+s);
  if(PERIM>0 && s!=(+el.dataset.g)) el.classList.add('over');
  el.querySelector('.ic').textContent=ICON[s];
  el.querySelector('input').value=s;
  markDirty();
}
function showPop(x,y,title,onPick){
  pop.innerHTML='<div class="pt">'+title+'</div>'+[2,1,0].map(s=>'<button data-s="'+s+'"><span class="ico">'+ICON[s]+'</span> Tout '+LBL[s]+'</button>').join('');
  pop.querySelectorAll('button').forEach(b=>b.onclick=()=>{onPick(+b.dataset.s);pop.classList.remove('show');});
  pop.style.left=x+'px';pop.style.top=y+'px';pop.classList.add('show');
}
window.spCol=function(e,role){e.stopPropagation();const r=e.currentTarget.getBoundingClientRect();
  showPop(r.left+scrollX,r.bottom+scrollY+6,'Toute la colonne',s=>{
    document.querySelectorAll('input[name^="perms['+role+']"]').forEach(inp=>spSetCell(inp.closest('.sp-cell'),s));});};
window.spRow=function(e,td){e.stopPropagation();const tr=td.parentElement;const r=td.getBoundingClientRect();
  showPop(r.left+scrollX+40,r.bottom+scrollY+4,'Toute la ligne',s=>{
    tr.querySelectorAll('.sp-cell').forEach(c=>spSetCell(c,s));});};
window.spCopyMenu=function(btn){const r=btn.getBoundingClientRect();
  const opts=[<?php
    $o=[]; if($is_super) $o[]="{id:0,label:'🌐 Défauts globaux'}";
    foreach($sites as $s){ if((int)$s['id']!==$selected_site) $o[]="{id:".(int)$s['id'].",label:'".h(addslashes('🏥 '.$s['org_nom'].' — '.$s['nom']))."'}"; }
    echo implode(',',$o);
  ?>].filter(o=>o.id!==PERIM);
  if(!opts.length){return;}
  pop.innerHTML='<div class="pt">Copier la config depuis</div>'+opts.map(o=>'<button data-i="'+o.id+'"><span class="ico">📋</span> '+o.label+'</button>').join('');
  pop.querySelectorAll('button').forEach(b=>b.onclick=()=>{
    if(spDirty && !confirm("Modifications non enregistrées.\nCopier écrasera la grille affichée. Continuer ?")){pop.classList.remove('show');return;}
    location.href='?tab=users&onglet=roles&site_id='+PERIM+'&copy_from='+b.dataset.i;});
  pop.style.left=(r.right+scrollX-190)+'px';pop.style.top=(r.bottom+scrollY+6)+'px';pop.classList.add('show');};
window.spSwitch=function(v){
  if(spDirty && !confirm("Modifications non enregistrées sur ce périmètre.\nChanger sans enregistrer ?")){document.getElementById('sp-perim').value=PERIM;return;}
  location.href='?tab=users&onglet=roles&site_id='+v;};
window.spReset=function(){
  if(confirm("Réinitialiser ce site aux défauts globaux ? Les surcharges seront supprimées.")){
    document.getElementById('sp-action').value='reset';spDirty=false;document.getElementById('sp-form').submit();}};
document.addEventListener('click',e=>{if(!pop.contains(e.target)&&!e.target.closest('.sp-rh')&&!e.target.closest('.sp-mname')&&!e.target.closest('.sp-copy'))pop.classList.remove('show');});
window.addEventListener('beforeunload',e=>{if(spDirty){e.preventDefault();e.returnValue='';}});
if(COPY_BANNER){markDirty();}
})();
</script>

<?php endif; ?>

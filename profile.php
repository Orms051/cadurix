<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.

require_once 'includes/auth.php';

headers_securite();
requiert_connexion();
charger_permissions($pdo, $_SESSION['user_id']);

$pageTitle = 'Mon profil';
// Message si changement de MDP forcé
$force_pwd = isset($_GET['force_pwd']) || ($_SESSION['force_pwd_change'] ?? false);
$uid = currentUserId();

// ================================================================
//  THÈMES PRÉDÉFINIS
// ================================================================
require_once __DIR__ . '/config/themes.php';
$themes = themes_predefinis();
$theme_lock = false;
try {
    $theme_lock = ($pdo->query("SELECT valeur FROM parametres WHERE cle='theme_lock'")->fetchColumn() === '1');
} catch (Throwable $e) {}

// ================================================================
//  TRAITEMENT POST
// ================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $type = $_POST['type_form'] ?? '';

    // ── Thème complet ──────────────────────────────────────────
if ($type === 'save_theme') {
        if ($theme_lock) { flashError("Personnalisation désactivée par l'administration."); header('Location: profile.php?t=infos'); exit; }
        $tj = $_POST['theme_json'] ?? '';
        $tc = json_decode($tj, true);
        if (is_array($tc)) {
            $valid = true;
            foreach ($tc as $k => $v) {
                if (is_string($v) && str_starts_with($v,'#') && !preg_match('/^#[0-9A-Fa-f]{6}$/', $v)) { $valid=false; break; }
            }
            if ($valid) {
                try { $pdo->prepare("UPDATE utilisateurs SET theme_config=? WHERE id=?")->execute([json_encode($tc),$uid]); }
                catch (Exception $e) {}
                flashSuccess("Thème appliqué !");
            } else { flashError("Couleurs invalides."); }
        }
        header('Location: profile.php?t=apparence'); exit;
    }

    // ── Infos perso ────────────────────────────────────────────
    if ($type === 'save_profil') {
        $prenom = trim($_POST['prenom'] ?? '');
        $nom    = trim($_POST['nom']    ?? '');
        $tel    = trim($_POST['telephone'] ?? '') ?: null;
        if (!$prenom || !$nom) { flashError('Prénom et nom obligatoires.'); }
        else {
            $pdo->prepare("UPDATE utilisateurs SET prenom=?,nom=?,telephone=? WHERE id=?")->execute([$prenom,$nom,$tel,$uid]);
            $_SESSION['user_nom'] = $nom; $_SESSION['user_prenom'] = $prenom;
            audit_log($pdo,'MODIF_PROFIL','utilisateurs',$uid);
            flashSuccess("Profil mis à jour.");
        }
        header('Location: profile.php'); exit;
    }

    // ── Mot de passe ───────────────────────────────────────────
    if ($type === 'change_mdp') {
        $old = $_POST['mdp_actuel'] ?? ''; $new = $_POST['mdp_nouveau'] ?? ''; $cfm = $_POST['mdp_confirm'] ?? '';
        $s = $pdo->prepare("SELECT mot_de_passe FROM utilisateurs WHERE id=?"); $s->execute([$uid]); $hash = $s->fetchColumn();
        if (!password_verify($old,$hash))                                                    { flashError("Mot de passe actuel incorrect."); }
        elseif (strlen($new)<8||!preg_match('/[A-Z]/',$new)||!preg_match('/[0-9]/',$new))   { flashError("8 min, 1 maj, 1 chiffre."); }
        elseif ($new!==$cfm)                                                                 { flashError("Les mots de passe ne correspondent pas."); }
        elseif (password_verify($new,$hash))                                                 { flashError("Le nouveau mot de passe doit être différent de l'ancien."); }
        elseif (function_exists('cdx_password_pwned') && cdx_password_pwned($new))           { flashError("Ce mot de passe figure dans des fuites connues. Choisissez-en un autre."); }
        else {
            $newHash = function_exists('cdx_password_hash') ? cdx_password_hash($new) : password_hash($new,PASSWORD_BCRYPT,['cost'=>12]);
            $pdo->prepare("UPDATE utilisateurs SET mot_de_passe=?,tentatives_echec=0,mot_de_passe_le=NOW() WHERE id=?")->execute([$newHash,$uid]);
unset($_SESSION['force_pwd_change']);
            audit_log($pdo,'CHANGEMENT_MDP','utilisateurs',$uid);
            flashSuccess("Mot de passe modifié.");
        }
        header('Location: profile.php?t=securite'); exit;
    }

    // ── Déconnecter les autres sessions ────────────────────────
    if ($type === 'logout_autres') {
        try { $pdo->prepare("DELETE FROM sessions_actives WHERE user_id=? AND session_id<>?")->execute([$uid, session_id()]); } catch(Exception $e){}
        audit_log($pdo,'DECONNEXION_AUTRES_SESSIONS','utilisateurs',$uid);
        flashSuccess("Vos autres sessions ont été déconnectées.");
        header('Location: profile.php?t=securite'); exit;
    }

    // ── Photo ──────────────────────────────────────────────────
    if ($type === 'upload_photo') {
        if (isset($_FILES['photo']) && $_FILES['photo']['error']===UPLOAD_ERR_OK) {
            $ext=strtolower(pathinfo($_FILES['photo']['name'],PATHINFO_EXTENSION));
            $mime=mime_content_type($_FILES['photo']['tmp_name']);
            if (!in_array($ext,['jpg','jpeg','png','webp'])||!in_array($mime,['image/jpeg','image/png','image/webp'])) { flashError('Format non autorisé.'); }
            elseif ($_FILES['photo']['size']>2*1024*1024) { flashError('Max 2 Mo.'); }
            else {
                $dir=__DIR__.'/uploads/avatars/'; if(!is_dir($dir))mkdir($dir,0755,true);
                $fn='avatar_'.$uid.'_'.time().'.'.$ext;
                move_uploaded_file($_FILES['photo']['tmp_name'],$dir.$fn);
                $pdo->prepare("UPDATE utilisateurs SET photo=? WHERE id=?")->execute(['uploads/avatars/'.$fn,$uid]);
                flashSuccess("Photo mise à jour.");
            }
        }
        header('Location: profile.php'); exit;
    }
}

// ================================================================
//  DONNÉES
// ================================================================
$s = $pdo->prepare("SELECT * FROM utilisateurs WHERE id=?"); $s->execute([$uid]); $user=$s->fetch(PDO::FETCH_ASSOC);
$s = $pdo->prepare("SELECT usp.role,s.nom AS site_nom,o.nom AS org_nom FROM user_site_permissions usp JOIN sites s ON s.id=usp.site_id JOIN organisations o ON o.id=s.organisation_id WHERE usp.user_id=? ORDER BY o.nom,s.nom"); $s->execute([$uid]); $mes_acces=$s->fetchAll(PDO::FETCH_ASSOC);
$hist=[];
try { $s=$pdo->prepare("SELECT * FROM connexions_historique WHERE user_id=? ORDER BY cree_le DESC LIMIT 8"); $s->execute([$uid]); $hist=$s->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e){}
$mes_sessions=[]; $session_courante=session_id();
try { $s=$pdo->prepare("SELECT * FROM sessions_actives WHERE user_id=? ORDER BY derniere_activite DESC"); $s->execute([$uid]); $mes_sessions=$s->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e){}

$roles_config=['super_admin'=>['label'=>'Super Admin','color'=>'#EF4444','icon'=>'⭐'],'administrateur'=>['label'=>'Administrateur','color'=>'#F59E0B','icon'=>'👑'],'gestionnaire'=>['label'=>'Gestionnaire','color'=>'#3B82F6','icon'=>'🗂️'],'saisie'=>['label'=>'Saisie','color'=>'#10B981','icon'=>'✏️'],'lecteur'=>['label'=>'Lecteur','color'=>'#64748B','icon'=>'👁️'],'biomedical'=>['label'=>'Biomédical','color'=>'#8B5CF6','icon'=>'🔬']];
$role_principal = $user['super_admin'] ? 'super_admin' : ($mes_acces[0]['role'] ?? null);
$rc = $roles_config[$role_principal] ?? ['label'=>'Utilisateur','color'=>'#64748b','icon'=>'👤'];
$initials = strtoupper(substr($user['prenom'],0,1).substr($user['nom'],0,1));

// Thème actuel
// base = thème par défaut du site (admin), sinon filet de sécurité
$defaut_key = isset($themes[THEME_FALLBACK]) ? THEME_FALLBACK : array_key_first($themes);
try {
    $v = $pdo->query("SELECT valeur FROM parametres WHERE cle='theme_defaut'")->fetchColumn();
    if ($v && isset($themes[$v])) $defaut_key = $v;
} catch (Throwable $e) {}
$theme_actuel     = $themes[$defaut_key];
$theme_actuel_key = $defaut_key;
try {
    $tc_raw = $user['theme_config'] ?? null;
    if ($tc_raw) {
        $tc = json_decode($tc_raw, true);
        if (is_array($tc)) {
            $theme_actuel = array_merge($theme_actuel, $tc);
            foreach ($themes as $k => $t) {
                if (($tc['accent']??'') === $t['accent'] && ($tc['bg']??'') === $t['bg']) { $theme_actuel_key=$k; break; }
            }
        }
    }
} catch (Exception $e) {}

$onglet_actif = $_GET['t'] ?? 'infos';
if ($theme_lock && $onglet_actif === 'apparence') $onglet_actif = 'infos';

function parse_ua_p(string $ua):string{$d=(str_contains($ua,'iPhone')||str_contains($ua,'Android'))?(str_contains($ua,'iPhone')?'📱 iPhone':'📱 Android'):'🖥️ Desktop';if(str_contains($ua,'Chrome'))$b='Chrome';elseif(str_contains($ua,'Firefox'))$b='Firefox';elseif(str_contains($ua,'Safari'))$b='Safari';elseif(str_contains($ua,'Edge'))$b='Edge';else $b='?';return $d.' · '.$b;}

require_once 'includes/header.php';
?>

<style>
.profile-layout{display:grid;grid-template-columns:290px 1fr;gap:24px;align-items:start}
@media(max-width:900px){.profile-layout{grid-template-columns:1fr}}
.id-card{background:var(--bg3);border:1px solid var(--border);border-radius:var(--radius);padding:28px 24px;text-align:center;position:sticky;top:20px}
.id-avatar{width:96px;height:96px;border-radius:50%;margin:0 auto 16px;position:relative;cursor:pointer}
.id-avatar img,.id-avatar-init{width:96px;height:96px;border-radius:50%;object-fit:cover;border:3px solid var(--border2)}
.id-avatar-init{display:flex;align-items:center;justify-content:center;font-size:2.2rem;font-weight:800;color:white}
.id-ov{position:absolute;inset:0;border-radius:50%;background:rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity .15s;font-size:1.4rem}
.id-avatar:hover .id-ov{opacity:1}
.id-name{font-size:1.2rem;font-weight:800;margin-bottom:4px}
.id-poste{font-size:.82rem;color:var(--text2);margin-bottom:12px}
.id-role{display:inline-flex;align-items:center;gap:6px;padding:4px 14px;border-radius:20px;font-size:.8rem;font-weight:600;margin-bottom:18px}
.id-meta{display:flex;flex-direction:column;gap:7px;text-align:left}
.id-mi{display:flex;align-items:center;gap:8px;font-size:.82rem;color:var(--text2);padding:6px 10px;background:var(--glass);border-radius:8px}
.id-mi i{font-size:14px;flex-shrink:0;color:var(--text3)}
.ptabs{display:flex;gap:4px;border-bottom:1px solid var(--border);margin-bottom:20px;flex-wrap:wrap}
.ptab{padding:8px 14px;font-size:13px;font-weight:600;color:var(--text2);background:none;border:none;border-bottom:2px solid transparent;margin-bottom:-1px;cursor:pointer;transition:all .15s;display:flex;align-items:center;gap:6px}
.ptab.active{color:var(--primary);border-bottom-color:var(--primary)}
.ptab:hover{color:var(--text)}
.acces-item{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:var(--glass);border:1px solid var(--border);border-radius:9px;margin-bottom:6px}
.rpill{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:600}
.hist-row{display:flex;align-items:center;gap:10px;padding:.55rem .75rem;border-radius:.5rem;font-size:.8rem;margin-bottom:.3rem;background:var(--glass)}
.hist-row.fail{background:rgba(239,68,68,.06)}
.hdot{width:8px;height:8px;border-radius:50%;flex-shrink:0}
.dok{background:#10b981}.derr{background:#ef4444}
.iptag{display:inline-block;font-family:var(--mono);font-size:.75rem;padding:.15rem .5rem;background:var(--glass2);border-radius:.3rem;border:1px solid var(--border)}
.pwd-bar{height:4px;border-radius:2px;transition:all .3s;margin-top:6px}

/* ── Thème ── */
.theme-preset{border:2px solid var(--border);border-radius:12px;padding:10px;cursor:pointer;transition:border-color .15s,transform .15s}
.theme-preset:hover{transform:translateY(-2px)}
.theme-preset.active{box-shadow:0 0 0 3px rgba(255,255,255,.15)}
.theme-preview{display:flex;height:70px;border-radius:7px;overflow:hidden}
.tp-sidebar{width:32px;padding:5px 4px;display:flex;flex-direction:column;gap:3px}
.tp-logo{height:10px;border-radius:3px;margin-bottom:3px}
.tp-nav{height:6px;border-radius:2px;background:rgba(255,255,255,.2)}
.tp-main{flex:1;padding:5px}
.tp-card{height:100%;border-radius:4px;border:1px solid;padding:4px;display:flex;flex-direction:column;gap:3px}
.tp-btn{height:8px;width:30px;border-radius:3px}
.tp-txt{height:4px;border-radius:2px;background:rgba(255,255,255,.4)}
.color-zone{padding:10px;background:var(--glass);border:1px solid var(--border);border-radius:10px}
</style>

<?php if (!empty($_SESSION['force_pwd_change'])): ?>
<!-- Changement de mot de passe forcé (1re connexion / expiration) -->
<div class="fpwd-overlay">
  <div class="fpwd-box">
    <div class="fpwd-ic">🔒</div>
    <div class="fpwd-t">Bienvenue <?= h($user['prenom'] ?? '') ?> 👋</div>
    <div class="fpwd-s">Pour votre sécurité, vous devez définir un nouveau mot de passe avant de continuer.</div>
    <form method="POST" action="/profile.php" style="text-align:left;margin-top:18px">
      <?= csrf_field() ?>
      <input type="hidden" name="type_form" value="change_mdp">
      <div class="form-group"><label class="form-label">Mot de passe actuel *</label>
        <input type="password" name="mdp_actuel" class="form-control" required autocomplete="current-password"></div>
      <div class="form-group"><label class="form-label">Nouveau mot de passe *</label>
        <div style="display:flex;gap:6px;align-items:center">
          <input type="password" name="mdp_nouveau" class="form-control" required minlength="8" autocomplete="new-password" id="fpwd-new" style="flex:1">
          <button type="button" class="btn btn-ghost btn-sm" onclick="fpwdGen()" title="Générer un mot de passe fort">🎲</button>
        </div>
        <div id="fpwd-bar" style="height:5px;width:0;border-radius:3px;background:#334155;margin-top:6px;transition:.2s"></div>
        <div style="font-size:.75rem;margin-top:4px;color:var(--text3)">8 caractères min, 1 majuscule, 1 chiffre</div></div>
      <div class="form-group"><label class="form-label">Confirmer *</label>
        <input type="password" name="mdp_confirm" class="form-control" required autocomplete="new-password" id="fpwd-cfm">
        <div id="fpwd-match" style="font-size:.75rem;margin-top:4px"></div></div>
      <button type="submit" class="btn btn-primary" style="width:100%">🔒 Valider et continuer</button>
    </form>
    <a href="/logout.php" class="fpwd-out">Se déconnecter</a>
  </div>
</div>
<style>
.fpwd-overlay{position:fixed;inset:0;background:rgba(8,12,24,.62);backdrop-filter:blur(7px);-webkit-backdrop-filter:blur(7px);display:flex;align-items:center;justify-content:center;z-index:99999;padding:16px}
.fpwd-box{background:var(--bg2,#111827);border:1px solid var(--border);border-radius:18px;padding:30px 26px;max-width:440px;width:100%;text-align:center;box-shadow:0 30px 70px rgba(0,0,0,.55)}
.fpwd-ic{font-size:2.6rem;margin-bottom:8px}
.fpwd-t{font-size:1.2rem;font-weight:800;color:var(--text);margin-bottom:6px}
.fpwd-s{font-size:.9rem;color:var(--text2);line-height:1.4}
.fpwd-out{display:inline-block;margin-top:14px;font-size:.8rem;color:var(--text3);text-decoration:none}
.fpwd-out:hover{color:var(--text2);text-decoration:underline}
</style>
<script>
(function(){
  var n=document.getElementById('fpwd-new'),c=document.getElementById('fpwd-cfm'),m=document.getElementById('fpwd-match'),bar=document.getElementById('fpwd-bar');
  function strength(v){ var s=0; if(v.length>=8)s++; if(v.length>=12)s++; if(/[A-Z]/.test(v))s++; if(/[0-9]/.test(v))s++; if(/[^A-Za-z0-9]/.test(v))s++; return s; }
  function paint(){ if(!bar)return; var i=strength(n.value); bar.style.width=[0,20,40,60,80,100][i]+'%'; bar.style.background=['#334155','#dc2626','#f59e0b','#f59e0b','#16a34a','#16a34a'][i]; }
  function chk(){ if(!c||!c.value){if(m)m.textContent='';return;} var ok=n.value===c.value; m.textContent=ok?'✓ Identiques':'✗ Différents'; m.style.color=ok?'#16a34a':'#dc2626'; }
  window.fpwdGen=function(){ var cs='ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$%'; var p=''; for(var i=0;i<14;i++)p+=cs[Math.floor(Math.random()*cs.length)]; n.type='text'; n.value=p; if(c)c.value=p; paint(); chk(); };
  if(n){ n.addEventListener('input',function(){paint();chk();}); }
  if(c){ c.addEventListener('input',chk); }
})();
</script>
<?php endif; ?>

<div class="page-header">
  <div class="page-title-group">
    <div class="page-title">👤 Mon profil</div>
    <div class="page-sub">Informations personnelles et préférences</div>
  </div>
</div>

<div class="profile-layout">

  <!-- ═══ CARTE IDENTITÉ ═══ -->
  <aside>
    <div class="id-card">
      <form method="POST" enctype="multipart/form-data" id="pf">
        <?= csrf_field() ?><input type="hidden" name="type_form" value="upload_photo">
        <input type="file" name="photo" id="photo-input" accept="image/*" style="display:none" onchange="document.getElementById('pf').submit()">
      </form>
      <div class="id-avatar" onclick="document.getElementById('photo-input').click()" title="Changer la photo">
        <?php if ($user['photo']&&file_exists(__DIR__.'/'.$user['photo'])): ?>
        <img src="/<?= h($user['photo']) ?>" alt="Photo">
        <?php else: ?>
        <div class="id-avatar-init" style="background:<?= h($rc['color']) ?>"><?= h($initials) ?></div>
        <?php endif; ?>
        <div class="id-ov">📷</div>
      </div>
      <div class="id-name"><?= h($user['prenom'].' '.$user['nom']) ?></div>
      <div class="id-poste"><?= h($user['poste']??($user['service']??'')) ?></div>
      <div class="id-role" style="background:<?= $rc['color'] ?>22;color:<?= $rc['color'] ?>;border:1px solid <?= $rc['color'] ?>44"><?= $rc['icon'] ?> <?= $rc['label'] ?></div>
      <div class="id-meta">
        <div class="id-mi"><i class="ti ti-mail"></i><span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($user['email']) ?></span></div>
        <div class="id-mi"><i class="ti ti-user"></i><span style="font-family:var(--mono)"><?= h($user['login']) ?></span></div>
        <?php if($user['telephone']): ?><div class="id-mi"><i class="ti ti-phone"></i><?= h($user['telephone']) ?></div><?php endif; ?>
        <div class="id-mi"><i class="ti ti-calendar"></i>Membre depuis <?= date('M Y',strtotime($user['cree_le'])) ?></div>
        <div class="id-mi"><i class="ti ti-clock"></i><?= $user['derniere_connexion']?'Co. le '.date('d/m/Y',strtotime($user['derniere_connexion'])):'Jamais connecté' ?></div>
        <?php if(count($mes_acces)): ?><div class="id-mi"><i class="ti ti-building"></i><?= count($mes_acces) ?> site<?= count($mes_acces)>1?'s':'' ?></div><?php endif; ?>
      </div>
      <!-- Barre couleur accent -->
      <div style="margin-top:14px;height:3px;border-radius:2px;background:var(--primary)"></div>
    </div>
  </aside>

  <!-- ═══ PANNEAU PRINCIPAL ═══ -->
  <div>
    <div class="ptabs">
      <button class="ptab <?= $onglet_actif==='infos'?'active':'' ?>" onclick="sw('infos',this)"><i class="ti ti-edit"></i> Mes infos</button>
      <?php if (!$theme_lock): ?>
      <button class="ptab <?= $onglet_actif==='apparence'?'active':'' ?>" onclick="sw('apparence',this)"><i class="ti ti-palette"></i> Apparence</button>
      <?php endif; ?>
      <button class="ptab <?= $onglet_actif==='securite'?'active':'' ?>" onclick="sw('securite',this)"><i class="ti ti-lock"></i> Sécurité</button>
      <button class="ptab <?= $onglet_actif==='acces'?'active':'' ?>" onclick="sw('acces',this)"><i class="ti ti-shield"></i> Mes accès<?php if(count($mes_acces)):?> <span style="font-size:10px;background:var(--glass2);padding:1px 6px;border-radius:10px"><?= count($mes_acces) ?></span><?php endif;?></button>
      <button class="ptab <?= $onglet_actif==='historique'?'active':'' ?>" onclick="sw('historique',this)"><i class="ti ti-history"></i> Historique</button>
    </div>

    <!-- ══ INFOS ══ -->
    <div id="tab-infos" class="tab-pane" style="display:<?= $onglet_actif==='infos'?'block':'none' ?>">
      <div class="card">
        <div class="card-title" style="margin-bottom:1rem">✏️ Informations personnelles</div>
        <form method="POST">
          <?= csrf_field() ?><input type="hidden" name="type_form" value="save_profil">
          <div class="form-row">
            <div class="form-group"><label class="form-label">Prénom *</label><input type="text" name="prenom" class="form-control" required value="<?= h($user['prenom']) ?>"></div>
            <div class="form-group"><label class="form-label">Nom *</label><input type="text" name="nom" class="form-control" required value="<?= h($user['nom']) ?>"></div>
          </div>
          <div class="form-group"><label class="form-label">Téléphone</label><input type="text" name="telephone" class="form-control" value="<?= h($user['telephone']??'') ?>" placeholder="06 XX XX XX XX"></div>
          <div style="margin-top:1rem;padding:12px 16px;background:var(--glass);border:1px solid var(--border);border-radius:10px">
            <div style="font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--text3);margin-bottom:10px">Géré par l'administrateur</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:.82rem">
              <div><span style="color:var(--text3)">Email</span><div style="color:var(--text2);margin-top:2px"><?= h($user['email']) ?></div></div>
              <div><span style="color:var(--text3)">Login</span><div style="font-family:var(--mono);color:var(--text2);margin-top:2px"><?= h($user['login']) ?></div></div>
              <div><span style="color:var(--text3)">Poste</span><div style="color:var(--text2);margin-top:2px"><?= h($user['poste']??'—') ?></div></div>
              <div><span style="color:var(--text3)">Service</span><div style="color:var(--text2);margin-top:2px"><?= h($user['service']??'—') ?></div></div>
            </div>
          </div>
          <button type="submit" class="btn btn-primary" style="margin-top:1rem">💾 Enregistrer</button>
        </form>
      </div>
    </div>

    <!-- ══ APPARENCE ══ -->
    <div id="tab-apparence" class="tab-pane" style="display:<?= $onglet_actif==='apparence'?'block':'none' ?>">
    <style>
    .themes-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(95px,1fr));gap:10px}
    .theme-preset{border:2px solid var(--border);border-radius:12px;padding:9px 7px;cursor:pointer;transition:border-color .15s,transform .15s,box-shadow .15s;background:var(--bg2)}
    .theme-preset:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(0,0,0,.15)}
    .theme-preset.active{box-shadow:0 0 0 3px rgba(var(--primary-rgb),.3)}
    .th-preview{display:flex;border-radius:7px;overflow:hidden;height:52px;margin-bottom:7px;border:1px solid rgba(0,0,0,.08)}
    .tp-sb{width:30%;display:flex;flex-direction:column;gap:3px;padding:5px 4px}
    .tp-logo{height:7px;border-radius:2px;margin-bottom:2px}
    .tp-nav{height:4px;border-radius:2px;background:rgba(255,255,255,.18)}
    .tp-body{flex:1;padding:5px}
    .tp-card-inner{border-radius:4px;padding:5px;border:1px solid;height:100%;display:flex;flex-direction:column;gap:3px}
    .tp-btn{height:6px;width:50%;border-radius:2px}
    .tp-txt{height:3px;border-radius:2px;width:90%}
    .th-name{font-size:.72rem;font-weight:700;text-align:center;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .th-emoji{text-align:center;font-size:.85rem;margin-bottom:2px}

    .ap-cols{display:grid;grid-template-columns:1fr 300px;gap:18px;align-items:start}
    @media(max-width:860px){.ap-cols{grid-template-columns:1fr}}

    .zgroup{margin-bottom:16px}
    .zgroup-title{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:var(--text3);margin-bottom:8px;display:flex;align-items:center;gap:6px}
    .zgroup-title::after{content:'';flex:1;height:1px;background:var(--border)}
    .zones-2col{display:grid;grid-template-columns:1fr 1fr;gap:8px}
    .cz{display:flex;align-items:center;gap:9px;padding:8px 11px;background:var(--bg3);border:1px solid var(--border);border-radius:9px;transition:border-color .15s}
    .cz:hover{border-color:var(--border2)}
    .cz input[type=color]{width:30px;height:30px;border-radius:6px;border:1px solid var(--border);cursor:pointer;padding:2px;background:none;flex-shrink:0}
    .cz-info{flex:1;min-width:0}
    .cz-label{font-size:.72rem;font-weight:600;color:var(--text2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:2px}
    .cz input[type=text]{width:100%;font-family:var(--mono);font-size:.72rem;background:transparent;border:none;color:var(--text3);outline:none;padding:0}
    .cz input[type=text]:focus{color:var(--text)}

    .pv-box{border-radius:10px;overflow:hidden;border:1px solid var(--border);display:flex;height:180px}
    .pv-sb2{width:80px;flex-shrink:0;padding:9px 7px;display:flex;flex-direction:column;gap:3px}
    .pv-body{flex:1;padding:10px;display:flex;flex-direction:column;gap:7px}
    </style>

      <form method="POST" id="form-theme">
        <?= csrf_field() ?>
        <input type="hidden" name="type_form" value="save_theme">
        <input type="hidden" name="theme_json" id="theme-json-input">

        <!-- Thèmes prédéfinis -->
        <div class="card" style="margin-bottom:18px">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
            <div>
              <div class="card-title" style="margin-bottom:2px">🎨 Thèmes prédéfinis</div>
              <p style="font-size:.75rem;color:var(--text3);margin:0">Cliquez pour appliquer instantanément</p>
            </div>
            <span style="font-size:.72rem;color:var(--text3);background:var(--bg3);padding:3px 9px;border-radius:20px;border:1px solid var(--border)"><?= count($themes) ?> thèmes</span>
          </div>
          <div class="themes-grid">
            <?php foreach ($themes as $key => $t): $active = $theme_actuel_key===$key; ?>
            <div class="theme-preset <?= $active?'active':'' ?>" style="<?= $active?'border-color:'.$t['accent'].';':'' ?>"
                 onclick="applyPreset(<?= htmlspecialchars(json_encode($t),ENT_QUOTES) ?>,this,'<?= $t['accent'] ?>')">
              <div class="th-preview">
                <div class="tp-sb" style="background:<?= $t['sidebar'] ?>">
                  <div class="tp-logo" style="background:linear-gradient(135deg,<?= $t['logo1'] ?>,<?= $t['logo2'] ?>)"></div>
                  <div class="tp-nav" style="background:<?= $t['accent'] ?>44;border-left:2px solid <?= $t['accent'] ?>"></div>
                  <div class="tp-nav"></div>
                  <div class="tp-nav" style="opacity:.5"></div>
                </div>
                <div class="tp-body" style="background:<?= $t['bg'] ?>">
                  <div class="tp-card-inner" style="background:<?= $t['bg2'] ?>;border-color:<?= $t['border'] ?>">
                    <div class="tp-btn" style="background:<?= $t['accent'] ?>"></div>
                    <div class="tp-txt" style="background:<?= $t['text'] ?>88"></div>
                    <div class="tp-txt" style="background:<?= $t['text2'] ?>66;width:70%"></div>
                  </div>
                </div>
              </div>
              <div class="th-emoji"><?= $t['emoji'] ?></div>
              <div class="th-name"><?= $t['name'] ?></div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- 2 colonnes : personnalisation | aperçu + actions -->
        <div class="ap-cols">

          <!-- Personnalisation -->
          <div class="card">
            <div class="card-title" style="margin-bottom:4px">🎛️ Personnalisation avancée</div>
            <p style="font-size:.75rem;color:var(--text3);margin-bottom:16px">Ajustez chaque couleur indépendamment du thème</p>
            <?php
            $zgroups = [
              'Fonds' => [
                ['bg','Fond principal'],['bg2','Fond secondaire'],
                ['bg3','Fond cartes'],['sidebar','Sidebar'],
              ],
              'Couleurs' => [
                ['accent','Accent / boutons'],
                ['logo1','Logo couleur 1'],['logo2','Logo couleur 2'],
              ],
              'Textes' => [
                ['text','Principal'],['text2','Secondaire'],['text3','Discret'],
              ],
              'Bordures' => [
                ['border','Légères'],['border2','Fortes'],
              ],
            ];
            foreach ($zgroups as $gname => $gzones): ?>
            <div class="zgroup">
              <div class="zgroup-title"><?= $gname ?></div>
              <div class="zones-2col">
                <?php foreach ($gzones as [$zk,$zl]): ?>
                <div class="cz">
                  <input type="color" id="zone-<?= $zk ?>" value="<?= h($theme_actuel[$zk]) ?>" oninput="updateZone('<?= $zk ?>',this.value)">
                  <div class="cz-info">
                    <div class="cz-label"><?= $zl ?></div>
                    <input type="text" id="hex-<?= $zk ?>" value="<?= h($theme_actuel[$zk]) ?>" maxlength="7" oninput="updateZoneHex('<?= $zk ?>',this.value)" placeholder="#000000">
                  </div>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
            <?php endforeach; ?>
          </div>

          <!-- Droite : aperçu + actions -->
          <div style="display:flex;flex-direction:column;gap:14px;position:sticky;top:80px">

            <!-- Aperçu -->
            <div class="card">
              <div class="card-title" style="margin-bottom:12px">👁️ Aperçu</div>
              <div class="pv-box">
                <div id="pv-sidebar" class="pv-sb2" style="background:<?= $theme_actuel['sidebar'] ?>">
                  <div id="pv-logo" style="height:14px;border-radius:4px;margin-bottom:6px;background:linear-gradient(135deg,<?= $theme_actuel['logo1'] ?>,<?= $theme_actuel['logo2'] ?>)"></div>
                  <div id="pv-active" style="height:9px;border-radius:3px;border-left:2px solid <?= $theme_actuel['accent'] ?>;background:<?= $theme_actuel['accent'] ?>22"></div>
                  <div style="height:7px;border-radius:3px;background:rgba(128,128,128,.15);margin-top:3px"></div>
                  <div style="height:7px;border-radius:3px;background:rgba(128,128,128,.08);margin-top:3px"></div>
                  <div style="height:7px;border-radius:3px;background:rgba(128,128,128,.05);margin-top:3px"></div>
                </div>
                <div id="pv-main" class="pv-body" style="background:<?= $theme_actuel['bg'] ?>">
                  <div id="pv-card" style="border-radius:8px;padding:10px;border:1px solid;background:<?= $theme_actuel['bg2'] ?>;border-color:<?= $theme_actuel['border'] ?>">
                    <div style="display:flex;gap:5px;margin-bottom:7px">
                      <div id="pv-btn" style="height:18px;width:60px;border-radius:4px;background:<?= $theme_actuel['accent'] ?>"></div>
                      <div id="pv-badge" style="height:18px;width:40px;border-radius:9px;background:<?= $theme_actuel['accent'] ?>22;border:1px solid <?= $theme_actuel['accent'] ?>44"></div>
                    </div>
                    <div id="pv-t1" style="height:6px;border-radius:3px;margin-bottom:4px;background:<?= $theme_actuel['text'] ?>99;width:85%"></div>
                    <div id="pv-t2" style="height:5px;border-radius:3px;margin-bottom:4px;background:<?= $theme_actuel['text2'] ?>77;width:65%"></div>
                    <div id="pv-t3" style="height:4px;border-radius:3px;background:<?= $theme_actuel['text3'] ?>55;width:45%"></div>
                  </div>
                  <div id="pv-card2" style="border-radius:7px;padding:8px;border:1px solid;border-color:<?= $theme_actuel['border2'] ?>;background:<?= $theme_actuel['bg3'] ?>">
                    <div style="height:5px;border-radius:3px;background:<?= $theme_actuel['border2'] ?>66;width:80%"></div>
                    <div style="height:4px;border-radius:3px;background:<?= $theme_actuel['border2'] ?>44;width:55%;margin-top:4px"></div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Actions -->
            <div class="card">
              <div style="display:flex;flex-direction:column;gap:8px">
                <button type="submit" class="btn btn-primary" onclick="prepareSubmit()" style="width:100%;justify-content:center">
                  <i class="ti ti-device-floppy"></i> Appliquer le thème
                </button>
                <button type="button" class="btn btn-ghost" onclick="resetTheme()" style="width:100%;justify-content:center">
                  <i class="ti ti-refresh"></i> Réinitialiser
                </button>
                <p style="font-size:.72rem;color:var(--text3);text-align:center;margin:0">S'applique uniquement à votre compte</p>
              </div>
            </div>
          </div>

        </div>
      </form>
    </div>

    <!-- ══ SÉCURITÉ ══ -->
    <div id="tab-securite" class="tab-pane" style="display:<?= $onglet_actif==='securite'?'block':'none' ?>">
      <div class="card">
        <div class="card-title" style="margin-bottom:1rem">🔑 Changer mon mot de passe</div>
        <form method="POST" style="max-width:480px">
          <?= csrf_field() ?><input type="hidden" name="type_form" value="change_mdp">
          <div class="form-group"><label class="form-label">Mot de passe actuel *</label><input type="password" name="mdp_actuel" class="form-control" required autocomplete="current-password"></div>
          <div class="form-group">
            <label class="form-label">Nouveau mot de passe *</label>
            <div style="display:flex;gap:8px;align-items:center">
              <input type="password" name="mdp_nouveau" class="form-control" required minlength="8"
                     autocomplete="new-password" id="pwd-nouveau"
                     oninput="checkPwd(this.value)" style="flex:1">
              <button type="button" class="btn btn-ghost btn-sm" onclick="togglePwdVisProfile('pwd-nouveau','eye-p')" title="Voir">
                <span id="eye-p">👁️</span>
              </button>
              <button type="button" class="btn btn-ghost btn-sm" onclick="genPwdProfile()" title="Générer">🎲</button>
              <button type="button" class="btn btn-ghost btn-sm" onclick="copyPwdProfile()" title="Copier">📋</button>
            </div>
            <div class="pwd-bar" id="pwd-bar" style="background:var(--border2)"></div>
            <div id="pwd-hint" style="font-size:.75rem;margin-top:4px;color:var(--text3)">8 caractères min, 1 majuscule, 1 chiffre</div>
          </div>
          <div class="form-group"><label class="form-label">Confirmer *</label>
            <input type="password" name="mdp_confirm" class="form-control" required autocomplete="new-password" id="pwd-confirm" oninput="checkConfirm()">
            <div id="confirm-hint" style="font-size:.75rem;margin-top:4px"></div>
          </div>
          <button type="submit" class="btn btn-primary">🔒 Changer</button>
        </form>
        <div style="margin-top:2rem;padding-top:1.5rem;border-top:1px solid var(--border)">
          <div class="card-title" style="margin-bottom:.75rem">📊 Sécurité du compte</div>
          <?php foreach ([
            [$user['derniere_connexion']&&strtotime($user['derniere_connexion'])>strtotime('-30 days'),'Connexion récente'],
            [!$user['bloque_jusqu']||strtotime($user['bloque_jusqu'])<time(),'Compte non bloqué'],
            [$user['actif'],'Compte actif'],
            [($user['tentatives_echec']??0)==0,'Aucun échec récent'],
          ] as [$ok,$label]): ?>
          <div style="display:flex;align-items:center;gap:10px;font-size:.85rem;margin-bottom:6px">
            <span><?= $ok?'✅':'⚠️' ?></span><span style="color:<?= $ok?'var(--text)':'#fcd34d' ?>"><?= h($label) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="card" style="margin-top:1.25rem">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:1rem">
          <div class="card-title" style="margin-bottom:0">💻 Sessions actives</div>
          <?php if (count($mes_sessions) > 1): ?>
          <form method="POST" onsubmit="return confirm('Déconnecter toutes vos autres sessions ?')" style="margin:0">
            <?= csrf_field() ?><input type="hidden" name="type_form" value="logout_autres">
            <button class="btn btn-ghost btn-sm" style="color:#dc2626">🚪 Déconnecter les autres</button>
          </form>
          <?php endif; ?>
        </div>
        <?php if (empty($mes_sessions)): ?>
        <div style="color:var(--text3);font-size:.85rem">Aucune session enregistrée.</div>
        <?php else: foreach ($mes_sessions as $ses):
          $courante = ($ses['session_id'] === $session_courante);
          $ua = $ses['user_agent'] ?? '';
          $nav = preg_match('/Edg/i',$ua)?'Edge':(preg_match('/Chrome/i',$ua)?'Chrome':(preg_match('/Firefox/i',$ua)?'Firefox':(preg_match('/Safari/i',$ua)?'Safari':'Navigateur')));
          $os  = preg_match('/Android/i',$ua)?'Android':(preg_match('/iPhone|iPad/i',$ua)?'iOS':(preg_match('/Windows/i',$ua)?'Windows':(preg_match('/Mac/i',$ua)?'macOS':(preg_match('/Linux/i',$ua)?'Linux':''))));
        ?>
        <div style="display:flex;align-items:center;gap:12px;padding:11px 13px;background:var(--bg2);border:1px solid <?= $courante?'rgba(16,185,129,.4)':'var(--border)' ?>;border-radius:11px;margin-bottom:7px">
          <span style="font-size:1.3rem"><?= preg_match('/Android|iPhone|iPad|Mobile/i',$ua)?'📱':'💻' ?></span>
          <div style="flex:1;min-width:0">
            <div style="font-size:.85rem;font-weight:600"><?= h(trim($nav.' · '.$os,' ·')) ?><?php if($courante):?> <span style="font-size:.7rem;color:#10b981;font-weight:700">● session actuelle</span><?php endif;?></div>
            <div style="font-size:.72rem;color:var(--text3);font-family:var(--mono)"><?= h($ses['ip'] ?? '—') ?> · <?= $ses['derniere_activite']?date('d/m H:i',strtotime($ses['derniere_activite'])):'—' ?></div>
          </div>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <!-- ══ ACCÈS ══ -->
    <div id="tab-acces" class="tab-pane" style="display:<?= $onglet_actif==='acces'?'block':'none' ?>">
      <div class="card">
        <div class="card-title" style="margin-bottom:1rem">🛡️ Mes accès</div>
        <?php if ($user['super_admin']): ?>
        <div style="padding:14px 18px;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);border-radius:10px;display:flex;align-items:center;gap:10px">
          <span style="font-size:1.4rem">⭐</span>
          <div><div style="font-weight:700;color:#fca5a5">Super Administrateur</div><div style="font-size:.8rem;color:var(--text2)">Accès complet à tous les sites.</div></div>
        </div>
        <?php elseif (empty($mes_acces)): ?>
        <div style="text-align:center;padding:2rem;color:var(--text3)"><div style="font-size:2rem;margin-bottom:.5rem">🔒</div>Aucun accès configuré.</div>
        <?php else: ?>
        <?php foreach ($mes_acces as $a): $rc2=$roles_config[$a['role']]??['label'=>$a['role'],'color'=>'#64748b','icon'=>'?']; ?>
        <div class="acces-item">
          <div><div style="font-size:.875rem;font-weight:600"><i class="ti ti-building" style="font-size:13px;margin-right:5px"></i><?= h($a['site_nom']) ?></div><div style="font-size:.75rem;color:var(--text3)"><?= h($a['org_nom']) ?></div></div>
          <span class="rpill" style="background:<?= $rc2['color'] ?>22;color:<?= $rc2['color'] ?>;border:1px solid <?= $rc2['color'] ?>44"><?= $rc2['icon'] ?> <?= $rc2['label'] ?></span>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- ══ HISTORIQUE ══ -->
    <div id="tab-historique" class="tab-pane" style="display:<?= $onglet_actif==='historique'?'block':'none' ?>">
      <div class="card">
        <div class="card-title" style="margin-bottom:1rem">🌐 Historique de connexions</div>
        <?php if (empty($hist)): ?><p style="color:var(--text3);text-align:center;padding:2rem">Aucun historique.</p>
        <?php else: foreach($hist as $hh): ?>
        <div class="hist-row <?= $hh['succes']?'':'fail' ?>">
          <div class="hdot <?= $hh['succes']?'dok':'derr' ?>"></div>
          <div style="flex:1"><div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap"><span class="iptag"><?= h($hh['ip']??'—') ?></span><span style="color:var(--text2);font-size:.75rem"><?= h(parse_ua_p($hh['user_agent']??'')) ?></span></div></div>
          <span style="color:var(--text3);font-size:.75rem;white-space:nowrap"><?= date('d/m/Y H:i',strtotime($hh['cree_le'])) ?></span>
          <span class="badge <?= $hh['succes']?'badge-ok':'badge-critical' ?>" style="font-size:.7rem"><?= $hh['succes']?'✓':'✗' ?></span>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

  </div>
</div>

<script>
// ── Onglets ───────────────────────────────────────────────
function sw(name, el) {
  document.querySelectorAll('.tab-pane').forEach(t => t.style.display='none');
  document.querySelectorAll('.ptab').forEach(t => t.classList.remove('active'));
  document.getElementById('tab-'+name).style.display = 'block';
  el.classList.add('active');
}

// ── Thème ─────────────────────────────────────────────────
let currentTheme = <?= json_encode($theme_actuel) ?>;
const defaultTheme = <?= json_encode($themes['minuit_violet']) ?>;

function applyPreset(preset, el, accentColor) {
  currentTheme = { ...currentTheme, ...preset };
  updateAllPickers();
  updatePreview();
  updateLiveCSS();
  // Forcer la sidebar directement en plus du CSS injecté
  const sb = document.querySelector('aside.sidebar');
  const sf = document.querySelector('.sidebar-footer');
  if (sb) sb.style.cssText += ';background:' + preset.sidebar + '!important';
  if (sf) sf.style.cssText += ';background:' + preset.sidebar + '!important';
  document.querySelectorAll('.theme-preset').forEach(p => {
    p.classList.remove('active');
    p.style.borderColor = '';
  });
  el.classList.add('active');
  el.style.borderColor = accentColor;
}

function updateZone(key, value) {
  if (!value.match(/^#[0-9A-Fa-f]{6}$/)) return;
  currentTheme[key] = value;
  document.getElementById('hex-'+key).value = value;
  updatePreview();
  updateLiveCSS();
  document.querySelectorAll('.theme-preset').forEach(p => { p.classList.remove('active'); p.style.borderColor=''; });
}

function updateZoneHex(key, value) {
  if (value.match(/^#[0-9A-Fa-f]{6}$/)) {
    currentTheme[key] = value;
    document.getElementById('zone-'+key).value = value;
    updatePreview();
    updateLiveCSS();
  }
}

function updateAllPickers() {
  ['bg','bg2','bg3','sidebar','accent','logo1','logo2','text','text2','text3','border','border2'].forEach(k => {
    if (currentTheme[k]) {
      const p = document.getElementById('zone-'+k);
      const h = document.getElementById('hex-'+k);
      if (p) p.value = currentTheme[k];
      if (h) h.value = currentTheme[k];
    }
  });
  updatePreview();
  updateLiveCSS();
}

function updatePreview() {
  const t = currentTheme;
  const $ = id => document.getElementById(id);
  if ($('pv-sidebar'))  $('pv-sidebar').style.background = t.sidebar;
  if ($('pv-logo'))     $('pv-logo').style.background = `linear-gradient(135deg,${t.logo1},${t.logo2})`;
  if ($('pv-active'))   { $('pv-active').style.borderLeftColor=t.accent; $('pv-active').style.background=t.accent+'22'; }
  if ($('pv-main'))     $('pv-main').style.background = t.bg;
  if ($('pv-card'))     { $('pv-card').style.background=t.bg2; $('pv-card').style.borderColor=t.border; }
  if ($('pv-card2'))    { $('pv-card2').style.background=t.bg3; $('pv-card2').style.borderColor=t.border2; }
  if ($('pv-btn'))      $('pv-btn').style.background = t.accent;
  if ($('pv-badge'))    { $('pv-badge').style.background=t.accent+'22'; $('pv-badge').style.borderColor=t.accent+'44'; }
  if ($('pv-t1'))       $('pv-t1').style.background = t.text+'99';
  if ($('pv-t2'))       $('pv-t2').style.background = t.text2+'77';
  if ($('pv-t3'))       $('pv-t3').style.background = t.text3+'55';
}

function hexRgb(hex) {
  return [parseInt(hex.slice(1,3),16),parseInt(hex.slice(3,5),16),parseInt(hex.slice(5,7),16)].join(',');
}

function updateLiveCSS() {
  const t = currentTheme;
  const rgb = hexRgb(t.accent);
  let s = document.getElementById('theme-live');
  if (!s) { s=document.createElement('style'); s.id='theme-live'; document.head.appendChild(s); }
  s.textContent = `:root {
    --bg:${t.bg};--bg2:${t.bg2};--bg3:${t.bg3};
    --border:${t.border};--border2:${t.border2};
    --text:${t.text};--text2:${t.text2};--text3:${t.text3};
    --primary:${t.accent};--primary-rgb:${rgb};
    --primary-glow:rgba(${rgb},.35);--primary-soft:rgba(${rgb},.12);--primary-bg:rgba(${rgb},.06);
  }
  --sidebar:${t.sidebar};
  aside.sidebar,aside.sidebar.sidebar{background:${t.sidebar}!important;}
  .sidebar-footer,.sidebar-footer.sidebar-footer{background:${t.sidebar}!important;}
  .topbar{background:${t.sidebar}!important;}
  .sidebar-logo{background:${t.sidebar}!important;}
  .sidebar *{transition:none!important;}
  .logo-mark{background:linear-gradient(135deg,${t.logo1},${t.logo2})!important}
  body,body.body{background:${t.bg}!important;}`;
}

function prepareSubmit() {
  document.getElementById('theme-json-input').value = JSON.stringify(currentTheme);
}

function resetTheme() {
  if (!confirm('Réinitialiser au thème Midnight ?')) return;
  currentTheme = { ...defaultTheme };
  updateAllPickers();
  document.querySelectorAll('.theme-preset').forEach((p,i) => {
    p.classList.remove('active'); p.style.borderColor='';
    if (i===0) { p.classList.add('active'); p.style.borderColor=defaultTheme.accent; }
  });
}

// ── Mot de passe ──────────────────────────────────────────
function checkPwd(v) {
  let s=0;
  if(v.length>=8)s++;if(/[A-Z]/.test(v))s++;if(/[0-9]/.test(v))s++;if(/[^A-Za-z0-9]/.test(v))s++;
  const c=['#ef4444','#ef4444','#f59e0b','#10b981','#10b981'];
  const l=['','Trop court','Moyen','Fort','Très fort'];
  document.getElementById('pwd-bar').style.cssText=`background:${c[s]};width:${s*25}%`;
  document.getElementById('pwd-hint').innerHTML=v?`<span style="color:${c[s]}">${l[s]}</span>`:'8 caractères min, 1 majuscule, 1 chiffre';
  checkConfirm();
}
function checkConfirm() {
  const v1=document.getElementById('pwd-nouveau')?.value;
  const v2=document.getElementById('pwd-confirm')?.value;
  const el=document.getElementById('confirm-hint');
  if(!el||!v2){if(el)el.innerHTML='';return;}
  el.innerHTML=v1===v2?'<span style="color:#10b981">✓ Correspondent</span>':'<span style="color:#ef4444">✗ Ne correspondent pas</span>';
}

// ── Générateur MDP (profil) ────────────────────────────────
function genPwdProfile() {
  const charset = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%&*';
  const specials = '!@#$%&*', upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
  const digits = '23456789', lower = 'abcdefghijkmnopqrstuvwxyz';
  let pwd = upper[Math.floor(Math.random()*upper.length)]
          + digits[Math.floor(Math.random()*digits.length)]
          + specials[Math.floor(Math.random()*specials.length)];
  for (let i=3; i<14; i++) pwd += charset[Math.floor(Math.random()*charset.length)];
  pwd = pwd.split('').sort(()=>Math.random()-.5).join('');
  const el = document.getElementById('pwd-nouveau');
  const cf = document.getElementById('pwd-confirm');
  if (el) { el.value = pwd; el.type = 'text'; checkPwd(pwd); }
  if (cf) { cf.value = pwd; checkConfirm(); }
  navigator.clipboard?.writeText(pwd).then(()=>{
    showToast?.('🎲 Mot de passe généré et copié !','success');
  });
}
function togglePwdVisProfile(id, eyeId) {
  const el = document.getElementById(id);
  const eye = document.getElementById(eyeId);
  if (!el) return;
  el.type = el.type === 'password' ? 'text' : 'password';
  if (eye) eye.textContent = el.type === 'password' ? '👁️' : '🙈';
}
function copyPwdProfile() {
  const el = document.getElementById('pwd-nouveau');
  if (!el || !el.value) return;
  navigator.clipboard?.writeText(el.value).then(()=>showToast?.('📋 Copié !','success'));
}
</script>

<?php require_once 'includes/footer.php'; ?>

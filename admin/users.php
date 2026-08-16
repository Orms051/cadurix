<?php
/**
 * Cadurix v5.2 — Gestion unifiée Utilisateurs / Permissions / Rôles
 */
if (!defined('ADMIN_HUB')) require_once '../includes/auth.php';

if (!defined('ADMIN_HUB')) headers_securite();
if (!isAdmin()) { accessDenied(); }
require_once __DIR__ . '/../config/roles.php';
require_once __DIR__ . '/../config/perms_modules.php';
require_once __DIR__ . '/../config/admin_tabs.php';

$pageTitle = 'Utilisateurs & Accès';
$action    = $_GET['action'] ?? 'liste';
$id        = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$onglet    = $_GET['onglet'] ?? 'utilisateurs'; // utilisateurs | acces | affichage | modules
if (in_array($onglet, ['roles','site_permissions','admin_access','permissions'], true)) $onglet = 'acces'; // ancien onglet fusionné dans Accès

// Rôles : source unique (config/roles.php)
$roles_config = roles_definis();
$roles_valides = array_keys(roles_editables());

// ================================================================
//  TRAITEMENT POST
// ================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $type = $_POST['type_form'] ?? '';

    // ── 🛡️ Garde-fou : protection des comptes Super Admin ─────
    // Un non-super_admin ne peut JAMAIS agir sur un compte super_admin
    // (désactiver, supprimer, reset MDP, changer rôle/permissions, 2FA, kick…)
    $_actions_protegees = ['save_user','delete_user','toggle_user','debloquer',
                           'kick_user','toggle_2fa','upload_photo',
                           'save_permissions','supprimer_permission','resend_credentials'];
    if (in_array($type, $_actions_protegees, true) && !($_SESSION['super_admin'] ?? false)) {
        $_cible = (int)($_POST['user_id'] ?? 0);
        if ($_cible) {
            $_q = $pdo->prepare("SELECT super_admin FROM utilisateurs WHERE id=?");
            $_q->execute([$_cible]);
            if ($_q->fetchColumn()) {
                audit_log($pdo,'BLOCAGE_ACTION_PROTEGEE','utilisateurs',$_cible);
                // 📧 Alerte email (best-effort) : prévenir le titulaire du compte visé + l'email d'alerte sécurité
                try {
                    require_once __DIR__.'/../includes/mailer.php';
                    $_inf=$pdo->prepare("SELECT email,prenom,nom FROM utilisateurs WHERE id=?"); $_inf->execute([$_cible]); $_tgt=$_inf->fetch(PDO::FETCH_ASSOC);
                    $_acteur = function_exists('currentUserName') ? currentUserName() : ('#'.($_SESSION['user_id']??0));
                    $_dest = [];
                    if (!empty($_tgt['email'])) $_dest[$_tgt['email']] = trim(($_tgt['prenom']??'').' '.($_tgt['nom']??''));
                    $_alert_to = cdx_get_param($pdo,'sec_alert_email'); if ($_alert_to) $_dest[$_alert_to] = 'Sécurité Cadurix';
                    if ($_dest) {
                        $_html = '<div style="font-family:system-ui,sans-serif;color:#1f2937">'
                               . '<h2 style="color:#dc2626;margin:0 0 10px">⚠️ Tentative bloquée sur un compte protégé</h2>'
                               . '<p>Une action <b>'.h($type).'</b> visant le compte <b>'.h(trim(($_tgt['prenom']??'').' '.($_tgt['nom']??''))).'</b> (Super Admin) a été tentée par <b>'.h($_acteur).'</b>.</p>'
                               . '<p>Elle a été <b>refusée automatiquement</b>.<br>IP : '.h($_SERVER['REMOTE_ADDR']??'?').' &middot; '.date('d/m/Y H:i:s').'</p>'
                               . '<p style="font-size:12px;color:#6b7280">Cadurix — Sécurité</p></div>';
                        foreach ($_dest as $_em=>$_nm) { cadurix_send_mail($pdo,$_em,$_nm?:'Administrateur','Cadurix — tentative bloquée sur votre compte',$_html); }
                    }
                } catch (Throwable $e) {}
                flashError("⛔ Action interdite : ce compte est protégé (Super Admin).");
                echo '<script>window.location.href=(window.location.pathname.includes("/admin/")?"?tab=users&onglet=utilisateurs":"users.php?onglet=utilisateurs");</script>'; exit;
            }
        }
    }

    // ── Créer / modifier utilisateur ──────────────────────────
    // 🛡️ Empêche de retirer le dernier Super Admin actif (anti-verrouillage)
    if (!function_exists('_dernier_super_actif')) {
        function _dernier_super_actif(PDO $pdo, int $uid): bool {
            $s=$pdo->prepare("SELECT super_admin,actif FROM utilisateurs WHERE id=?"); $s->execute([$uid]); $u=$s->fetch(PDO::FETCH_ASSOC);
            if(!$u||!$u['super_admin']||!$u['actif']) return false;
            return (int)$pdo->query("SELECT COUNT(*) FROM utilisateurs WHERE super_admin=1 AND actif=1")->fetchColumn() <= 1;
        }
    }

    if ($type === 'save_user') {
        $uid     = (int)($_POST['user_id'] ?? 0);
        $nom     = trim($_POST['nom']          ?? '');
        $prenom  = trim($_POST['prenom']       ?? '');
        $email   = trim($_POST['email']        ?? '');
        $login   = trim($_POST['login']        ?? '');
        $mdp     = $_POST['mot_de_passe']      ?? '';
        $tel     = trim($_POST['telephone']    ?? '') ?: null;
        $poste   = trim($_POST['poste']        ?? '') ?: null;
        $service = trim($_POST['service']      ?? '') ?: null;
        $note    = trim($_POST['note_admin']   ?? '') ?: null;
        $expire  = $_POST['expire_le']         ?? '' ?: null;
        $actif   = isset($_POST['actif'])      ? 1 : 0;
        $site_id   = (int)($_POST['premier_site'] ?? 0);
        $role_init = $_POST['premier_role'] ?? '';
        $force_mdp = isset($_POST['force_mdp']);
        $send_mail = isset($_POST['send_welcome']);

        if (!$nom || !$prenom || !$email || !$login) {
            flashError('Tous les champs obligatoires doivent être remplis.');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flashError('Email invalide.');
        } else {
            $ok = true;
            if (!empty($mdp) && (strlen($mdp)<8 || !preg_match('/[A-Z]/',$mdp) || !preg_match('/[0-9]/',$mdp))) {
                flashError('MDP : 8 caractères min, 1 majuscule, 1 chiffre.'); $ok = false;
            }
            if ($ok) {
                if ($uid > 0) {
                    if ($actif==0 && _dernier_super_actif($pdo,$uid)) {
                        flashError("⛔ Impossible de désactiver le dernier Super Admin actif.");
                        echo '<script>window.location.href=(window.location.pathname.includes("/admin/")?"?tab=users&":"users.php?")+"action=edit&id='.$uid.'&onglet=utilisateurs";</script>'; exit;
                    }
                    if (!empty($mdp)) {
                        $hash = cdx_password_hash($mdp);
                        $pdo->prepare("UPDATE utilisateurs SET mot_de_passe=?,tentatives_echec=0,bloque_jusqu=NULL,mot_de_passe_le=NOW() WHERE id=?")->execute([$hash,$uid]);
                        try { $pdo->prepare("DELETE FROM sessions_actives WHERE user_id=?")->execute([$uid]); } catch(Exception $e){}
                    }
                    $pdo->prepare("UPDATE utilisateurs SET nom=?,prenom=?,email=?,login=?,telephone=?,poste=?,service=?,note_admin=?,expire_le=?,actif=? WHERE id=?")
                        ->execute([$nom,$prenom,$email,$login,$tel,$poste,$service,$note,$expire,$actif,$uid]);
                    audit_log($pdo,'MODIF_UTILISATEUR','utilisateurs',$uid);
                    flashSuccess("Utilisateur mis à jour.");
                } else {
                    if (empty($mdp)) { flashError('Mot de passe requis.'); }
                    else {
                        $chk = $pdo->prepare("SELECT id FROM utilisateurs WHERE login=? OR email=?");
                        $chk->execute([$login,$email]);
                        if ($chk->fetch()) { flashError('Login ou email déjà utilisé.'); }
                        else {
                            $hash = cdx_password_hash($mdp);
                            $mdp_le = $force_mdp ? '2000-01-01 00:00:00' : date('Y-m-d H:i:s');
                            $pdo->prepare("INSERT INTO utilisateurs (nom,prenom,email,login,mot_de_passe,telephone,poste,service,note_admin,expire_le,actif,mot_de_passe_le) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)")
                                ->execute([$nom,$prenom,$email,$login,$hash,$tel,$poste,$service,$note,$expire,$actif,$mdp_le]);
                            $new_id = (int)$pdo->lastInsertId();
                            audit_log($pdo,'CREATION_UTILISATEUR','utilisateurs',$new_id);
                            // Premier accès (site + rôle) — optionnel
                            if ($site_id && in_array($role_init, $roles_valides, true)) {
                                try {
                                    $pdo->prepare("INSERT INTO user_site_permissions (user_id,site_id,role,locations_ids) VALUES(?,?,?,NULL) ON DUPLICATE KEY UPDATE role=VALUES(role)")->execute([$new_id,$site_id,$role_init]);
                                    $poste_auto = $roles_config[$role_init]['poste'] ?? null;
                                    if ($poste_auto && !$poste) $pdo->prepare("UPDATE utilisateurs SET poste=? WHERE id=? AND (poste IS NULL OR poste='')")->execute([$poste_auto,$new_id]);
                                    audit_log($pdo,'MODIF_PERMISSIONS','user_site_permissions',$new_id,null,['sid'=>$site_id,'role'=>$role_init]); if (function_exists('perms_bump')) perms_bump($pdo);
                                } catch (Throwable $e) {}
                            }
                            // Email de bienvenue — optionnel
                            if ($send_mail) {
                                require_once __DIR__.'/../includes/mailer.php';
                                $_wurl = isset($_SERVER['HTTP_HOST']) ? 'https://'.$_SERVER['HTTP_HOST'].'/' : '/';
                                $_whtml = '<div style="font-family:system-ui,sans-serif;color:#1f2937;max-width:460px">'
                                    .'<h2 style="color:#7c3aed;margin:0 0 10px">Bienvenue sur Cadurix</h2>'
                                    .'<p>Bonjour '.h($prenom).',</p>'
                                    .'<p>Un compte vient de vous être créé. Voici vos identifiants :</p>'
                                    .'<table style="border-collapse:collapse;margin:14px 0;background:#f8fafc;border-radius:8px">'
                                    .'<tr><td style="padding:8px 14px;color:#64748b">Identifiant</td><td style="padding:8px 14px"><b>'.h($login).'</b></td></tr>'
                                    .'<tr><td style="padding:8px 14px;color:#64748b">Mot de passe</td><td style="padding:8px 14px;font-family:monospace"><b>'.h($mdp).'</b></td></tr></table>'
                                    .'<p><a href="'.$_wurl.'" style="display:inline-block;background:#7c3aed;color:#fff;padding:11px 20px;border-radius:8px;text-decoration:none;font-weight:600">Se connecter</a></p>'
                                    .($force_mdp ? '<p style="font-size:13px;color:#64748b">🔒 Vous devrez choisir un nouveau mot de passe à la première connexion.</p>' : '')
                                    .'<hr style="border:none;border-top:1px solid #e2e8f0;margin:18px 0"><p style="font-size:11px;color:#94a3b8">Cadurix — Gestion des péremptions</p></div>';
                                try { cadurix_send_mail($pdo, $email, trim($prenom.' '.$nom), 'Cadurix — votre compte a été créé', $_whtml); } catch (Throwable $e) {}
                            }
                            flashSuccess("Utilisateur créé.".($site_id && in_array($role_init,$roles_valides,true) ? ' Accès attribué.' : '').($send_mail ? ' Email envoyé.' : ''));
                            echo '<script>window.location.href=(window.location.pathname.includes("/admin/")?"?tab=users&onglet=utilisateurs":"users.php?onglet=utilisateurs");</script>'; exit;
                        }
                    }
                }
            }
        }
        echo '<script>window.location.href=(window.location.pathname.includes("/admin/")?"?tab=users&":"users.php?")+"action=edit&id='.($uid?:$id).'&onglet=utilisateurs";</script>'; exit;
    }

    // ── Supprimer utilisateur ─────────────────────────────────
    if ($type === 'save_users_bulk') {
        $site_id   = (int)($_POST['bulk_site'] ?? 0);
        $role      = $_POST['bulk_role'] ?? '';
        $force_mdp = isset($_POST['bulk_force_mdp']);
        $send_mail = isset($_POST['bulk_send_mail']);
        $raw       = trim($_POST['bulk_list'] ?? '');
        $site_ok = false;
        if ($site_id) { $qs=$pdo->prepare("SELECT 1 FROM sites WHERE id=? LIMIT 1"); $qs->execute([$site_id]); $site_ok=(bool)$qs->fetchColumn(); }
        if (!$site_ok || !in_array($role, $roles_valides, true) || $raw === '') {
            flashError("Site, rôle et liste sont requis.");
        } else {
            require_once __DIR__.'/../includes/mailer.php';
            $slug = function($s){
                $s = strtolower(trim($s));
                $s = strtr($s, ['à'=>'a','â'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','î'=>'i','ï'=>'i','ô'=>'o','ö'=>'o','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n']);
                return preg_replace('/[^a-z0-9]/','',$s);
            };
            $gen_pwd = function(){
                $U='ABCDEFGHJKLMNPQRSTUVWXYZ'; $L='abcdefghijkmnpqrstuvwxyz'; $D='23456789';
                $s=''; for($i=0;$i<2;$i++)$s.=$U[random_int(0,strlen($U)-1)];
                for($i=0;$i<5;$i++)$s.=$L[random_int(0,strlen($L)-1)];
                for($i=0;$i<3;$i++)$s.=$D[random_int(0,strlen($D)-1)];
                return str_shuffle($s);
            };
            $login_exists = function($lg) use ($pdo){ $q=$pdo->prepare("SELECT 1 FROM utilisateurs WHERE login=? LIMIT 1"); $q->execute([$lg]); return (bool)$q->fetchColumn(); };
            $email_exists = function($em) use ($pdo){ $q=$pdo->prepare("SELECT 1 FROM utilisateurs WHERE email=? LIMIT 1"); $q->execute([$em]); return (bool)$q->fetchColumn(); };
            $poste_auto = $roles_config[$role]['poste'] ?? null;
            $lignes = preg_split('/\r\n|\r|\n/', $raw);
            $batch=[]; $created=0; $skipped=[];
            foreach ($lignes as $ln) {
                $ln = trim($ln); if ($ln==='') continue;
                $pp = preg_split('/[;,\t]+/', $ln);
                $prenom=trim($pp[0]??''); $nom=trim($pp[1]??''); $email=trim($pp[2]??'');
                if ($prenom===''||$nom===''||$email==='') { $skipped[]="$ln (incomplet)"; continue; }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $skipped[]="$email (email invalide)"; continue; }
                if ($email_exists($email)) { $skipped[]="$email (déjà existant)"; continue; }
                $base = substr($slug($prenom),0,1).$slug($nom); if ($base==='') $base='user';
                $login=$base; $i=2; while ($login_exists($login) || in_array($login,$batch,true)) { $login=$base.$i; $i++; }
                $batch[]=$login;
                $pwd=$gen_pwd(); $hash=cdx_password_hash($pwd);
                $mdp_le = $force_mdp ? '2000-01-01 00:00:00' : date('Y-m-d H:i:s');
                try {
                    $pdo->prepare("INSERT INTO utilisateurs (nom,prenom,email,login,mot_de_passe,telephone,poste,service,note_admin,expire_le,actif,mot_de_passe_le) VALUES(?,?,?,?,?,NULL,?,NULL,NULL,NULL,1,?)")
                        ->execute([$nom,$prenom,$email,$login,$hash,$poste_auto,$mdp_le]);
                    $nid=(int)$pdo->lastInsertId();
                    $pdo->prepare("INSERT INTO user_site_permissions (user_id,site_id,role,locations_ids) VALUES(?,?,?,NULL) ON DUPLICATE KEY UPDATE role=VALUES(role)")->execute([$nid,$site_id,$role]);
                    audit_log($pdo,'CREATION_UTILISATEUR','utilisateurs',$nid);
                    if ($send_mail) {
                        $wurl = isset($_SERVER['HTTP_HOST']) ? 'https://'.$_SERVER['HTTP_HOST'].'/' : '/';
                        $html = '<div style="font-family:system-ui,sans-serif;color:#1f2937;max-width:460px">'
                            .'<h2 style="color:#7c3aed;margin:0 0 10px">Bienvenue sur Cadurix</h2>'
                            .'<p>Bonjour '.h($prenom).',</p><p>Un compte vient de vous être créé. Vos identifiants :</p>'
                            .'<table style="border-collapse:collapse;margin:14px 0;background:#f8fafc;border-radius:8px">'
                            .'<tr><td style="padding:8px 14px;color:#64748b">Identifiant</td><td style="padding:8px 14px"><b>'.h($login).'</b></td></tr>'
                            .'<tr><td style="padding:8px 14px;color:#64748b">Mot de passe</td><td style="padding:8px 14px;font-family:monospace"><b>'.h($pwd).'</b></td></tr></table>'
                            .'<p><a href="'.$wurl.'" style="display:inline-block;background:#7c3aed;color:#fff;padding:11px 20px;border-radius:8px;text-decoration:none;font-weight:600">Se connecter</a></p>'
                            .($force_mdp?'<p style="font-size:13px;color:#64748b">🔒 Vous devrez choisir un nouveau mot de passe à la première connexion.</p>':'')
                            .'<p style="font-size:11px;color:#94a3b8">Cadurix</p></div>';
                        try { cadurix_send_mail($pdo,$email,trim($prenom.' '.$nom),'Cadurix — votre compte a été créé',$html); } catch (Throwable $e) {}
                    }
                    $created++;
                } catch (Throwable $e) { $skipped[]="$email (erreur)"; }
            }
            $msg = "$created compte(s) créé(s).";
            if ($skipped) $msg .= ' Ignorés ('.count($skipped).') : '.implode(' ; ', array_slice($skipped,0,6)).(count($skipped)>6?'…':'');
            if ($created>0) flashSuccess($msg); else flashError($msg ?: 'Aucun compte créé.');
        }
        echo '<script>window.location.href=(window.location.pathname.includes("/admin/")?"?tab=users&onglet=utilisateurs":"users.php?onglet=utilisateurs");</script>'; exit;
    }
    if ($type === 'resend_credentials') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $info = $pdo->prepare("SELECT prenom,nom,email,login FROM utilisateurs WHERE id=?"); $info->execute([$uid]); $tu=$info->fetch(PDO::FETCH_ASSOC);
        if ($tu && !empty($tu['email'])) {
            $UU='ABCDEFGHJKLMNPQRSTUVWXYZ';$LL='abcdefghijkmnpqrstuvwxyz';$DD='23456789';
            $pwd=''; for($i=0;$i<2;$i++)$pwd.=$UU[random_int(0,strlen($UU)-1)]; for($i=0;$i<5;$i++)$pwd.=$LL[random_int(0,strlen($LL)-1)]; for($i=0;$i<3;$i++)$pwd.=$DD[random_int(0,strlen($DD)-1)]; $pwd=str_shuffle($pwd);
            $hash=cdx_password_hash($pwd);
            $pdo->prepare("UPDATE utilisateurs SET mot_de_passe=?,tentatives_echec=0,bloque_jusqu=NULL,mot_de_passe_le='2000-01-01 00:00:00' WHERE id=?")->execute([$hash,$uid]);
            audit_log($pdo,'RESET_MDP','utilisateurs',$uid);
            require_once __DIR__.'/../includes/mailer.php';
            $wurl = isset($_SERVER['HTTP_HOST']) ? 'https://'.$_SERVER['HTTP_HOST'].'/' : '/';
            $html = '<div style="font-family:system-ui,sans-serif;color:#1f2937;max-width:460px">'
                .'<h2 style="color:#7c3aed;margin:0 0 10px">Vos nouveaux identifiants</h2>'
                .'<p>Bonjour '.h($tu['prenom']).',</p><p>Votre mot de passe a ete reinitialise. Voici vos identifiants :</p>'
                .'<table style="border-collapse:collapse;margin:14px 0;background:#f8fafc;border-radius:8px">'
                .'<tr><td style="padding:8px 14px;color:#64748b">Identifiant</td><td style="padding:8px 14px"><b>'.h($tu['login']).'</b></td></tr>'
                .'<tr><td style="padding:8px 14px;color:#64748b">Mot de passe</td><td style="padding:8px 14px;font-family:monospace"><b>'.h($pwd).'</b></td></tr></table>'
                .'<p><a href="'.$wurl.'" style="display:inline-block;background:#7c3aed;color:#fff;padding:11px 20px;border-radius:8px;text-decoration:none;font-weight:600">Se connecter</a></p>'
                .'<p style="font-size:13px;color:#64748b">Vous devrez choisir un nouveau mot de passe a la connexion.</p>'
                .'<p style="font-size:11px;color:#94a3b8">Cadurix</p></div>';
            $sent=false; try { $sent=cadurix_send_mail($pdo,$tu['email'],trim($tu['prenom'].' '.$tu['nom']),'Cadurix - vos nouveaux identifiants',$html); } catch(Throwable $e){}
            flashSuccess('Identifiants regeneres'.($sent?' et envoyes par email.':" - mais l'email n'a pas pu etre envoye (verifier le SMTP)."));
        } else {
            flashError("Cet utilisateur n'a pas d'email : impossible d'envoyer les identifiants.");
        }
        echo '<script>window.location.href=(window.location.pathname.includes("/admin/")?"?tab=users&onglet=utilisateurs":"users.php?onglet=utilisateurs");</script>'; exit;
    }
    if ($type === 'delete_user') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid && $uid !== ($_SESSION['user_id']??0)) {
            // Vérifier que c'est pas un super admin
            $is_sa = $pdo->prepare("SELECT super_admin FROM utilisateurs WHERE id=?");
            $is_sa->execute([$uid]);
            $u_sa = $is_sa->fetchColumn();
            if ($u_sa && !($_SESSION['super_admin']??false)) {
                flashError("Impossible de supprimer un Super Admin.");
            } else {
                if (_dernier_super_actif($pdo,$uid)) { flashError("⛔ Impossible de supprimer le dernier Super Admin actif."); echo '<script>window.location.href=(window.location.pathname.includes("/admin/")?"?tab=users&onglet=utilisateurs":"users.php?onglet=utilisateurs");</script>'; exit; }
                $pdo->prepare("DELETE FROM user_site_permissions WHERE user_id=?")->execute([$uid]);
                try { $pdo->prepare("DELETE FROM sessions_actives WHERE user_id=?")->execute([$uid]); } catch(Exception $e){}
                $pdo->prepare("DELETE FROM utilisateurs WHERE id=?")->execute([$uid]);
                audit_log($pdo,'SUPPRESSION_UTILISATEUR','utilisateurs',$uid);
                if (function_exists('perms_bump')) perms_bump($pdo); flashSuccess("Utilisateur supprimé.");
            }
        }
        echo '<script>window.location.href=(window.location.pathname.includes("/admin/")?"?tab=users&onglet=utilisateurs":"users.php?onglet=utilisateurs");</script>'; exit;
    }

    // ── Toggle actif ──────────────────────────────────────────
    if ($type === 'toggle_user') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid !== ($_SESSION['user_id']??0)) {
            if (_dernier_super_actif($pdo,$uid)) { flashError("⛔ Impossible de désactiver le dernier Super Admin actif."); echo '<script>window.location.href=(window.location.pathname.includes("/admin/")?"?tab=users&onglet=utilisateurs":"users.php?onglet=utilisateurs");</script>'; exit; }
            $pdo->prepare("UPDATE utilisateurs SET actif=1-actif WHERE id=?")->execute([$uid]);
            audit_log($pdo,'TOGGLE_UTILISATEUR','utilisateurs',$uid);
            flashSuccess("Statut mis à jour.");
        }
        echo '<script>window.location.href=(window.location.pathname.includes("/admin/")?"?tab=users&onglet=utilisateurs":"users.php?onglet=utilisateurs");</script>'; exit;
    }

    // ── Débloquer ─────────────────────────────────────────────
    if ($type === 'debloquer') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $pdo->prepare("UPDATE utilisateurs SET tentatives_echec=0,bloque_jusqu=NULL WHERE id=?")->execute([$uid]);
        audit_log($pdo,'DEBLOCAGE_UTILISATEUR','utilisateurs',$uid);
        flashSuccess("Compte débloqué.");
        echo '<script>window.location.href=(window.location.pathname.includes("/admin/")?"?tab=users&":"users.php?")+"action=edit&id='.$uid.'&onglet=utilisateurs";</script>'; exit;
    }

    // ── Kick session ──────────────────────────────────────────
    if ($type === 'kick_user') {
        $uid = (int)($_POST['user_id'] ?? 0);
        try { $pdo->prepare("DELETE FROM sessions_actives WHERE user_id=?")->execute([$uid]); } catch(Exception $e){}
        audit_log($pdo,'KICK_SESSION','utilisateurs',$uid);
        flashSuccess("Utilisateur déconnecté.");
        echo '<script>window.location.href=(window.location.pathname.includes("/admin/")?"?tab=users&":"users.php?")+"action=edit&id='.$uid.'&onglet=utilisateurs";</script>'; exit;
    }

    // ── Sauvegarder matrice permissions rôles ────────────────
    // ── Toggle 2FA ───────────────────────────────────────────
    if ($type === 'toggle_2fa') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $val = isset($_POST['twofa_enabled']) ? 1 : 0;
        $pdo->prepare("UPDATE utilisateurs SET twofa_enabled=? WHERE id=?")->execute([$val, $uid]);
        // Si désactivé, supprimer les OTP en attente
        if (!$val) {
            try { $pdo->prepare("DELETE FROM otp_codes WHERE user_id=?")->execute([$uid]); } catch(Exception $e){}
        }
        audit_log($pdo, $val ? 'ACTIVATE_2FA' : 'DEACTIVATE_2FA', 'utilisateurs', $uid);
        flashSuccess($val ? '🔐 Double authentification activée.' : '🔓 Double authentification désactivée.');
        echo '<script>window.location.href=(window.location.pathname.includes("/admin/")?"?tab=users&":"users.php?")+"action=edit&id='.$uid.'&onglet=utilisateurs";</script>'; exit;
    }

    if ($type === 'save_role_permissions') {
        if (!($_SESSION['super_admin'] ?? false)) { flashError("Réservé au Super Admin."); echo '<script>window.location.href=(window.location.pathname.includes("/admin/")?"?tab=users&onglet=roles":"users.php?onglet=roles");</script>'; exit; }
        $modules = ['dashboard','medicaments','emplacements','alertes',
                    'rapports','import_export',
                    'scanner','qrcodes','fiches','rempotages',
                    'equipements','biomed','biomed_admin',
                    'ia',
                    'messagerie',
                    'admin','dotations','tracabilite'];
        $roles_editables = array_keys(roles_editables()); // inclut 'sse' — source unique config/roles.php
        foreach ($roles_editables as $role) {
            foreach ($modules as $module) {
                if (function_exists('module_perm_visible') && !module_perm_visible($module)) continue; // module coupé : on ne touche pas
                $niveau = (int)($_POST["perm_{$role}_{$module}"] ?? 0);
                if ($niveau < 0 || $niveau > 2) $niveau = 0;
                $pdo->prepare("INSERT INTO role_permissions (role,module,niveau) VALUES(?,?,?) ON DUPLICATE KEY UPDATE niveau=?")
                    ->execute([$role, $module, $niveau, $niveau]);
            }
        }
        audit_log($pdo,'MODIF_ROLE_PERMISSIONS','role_permissions',0);
        flashSuccess("Matrice des permissions sauvegardée.");
        echo '<script>window.location.href=(window.location.pathname.includes("/admin/")?"?tab=users&onglet=roles":"users.php?onglet=roles");</script>'; exit;
    }

    if ($type === 'save_button_perms') {
        if (!($_SESSION['super_admin'] ?? false) && !isAdmin()) { flashError("Réservé aux administrateurs."); echo '<script>window.location.href=(window.location.pathname.includes("/admin/")?"?tab=users&onglet=roles":"users.php?onglet=roles");</script>'; exit; }
        foreach (array_keys(roles_editables()) as $rk) {
            foreach (['creer','modifier','ajuster','renouveler','dupliquer','archiver','supprimer','historique','dotation'] as $act) {
                $on = isset($_POST['btnperm'][$rk][$act]) ? 1 : 0;
                $pdo->prepare("INSERT INTO role_permissions (role,module,niveau) VALUES(?,?,?) ON DUPLICATE KEY UPDATE niveau=?")
                    ->execute([$rk, 'action_'.$act, $on, $on]);
            }
        }
        audit_log($pdo,'MODIF_DROITS_BOUTONS','role_permissions',0);
        flashSuccess("Droits des boutons enregistrés.");
        echo '<script>window.location.href=(window.location.pathname.includes("/admin/")?"?tab=users&onglet=roles":"users.php?onglet=roles");</script>'; exit;
    }

    if ($type === 'save_acces') {
        if (!($_SESSION['super_admin'] ?? false) && !isAdmin()) { flashError("Réservé aux administrateurs."); echo '<script>window.location.href=(window.location.pathname.includes("/admin/")?"?tab=users&onglet=acces":"users.php?onglet=acces");</script>'; exit; }
        $rolesE = array_keys(roles_editables());
        $cur = [];
        try { foreach ($pdo->query("SELECT role,module,niveau FROM role_permissions") as $r) $cur[$r['role']][$r['module']] = (int)$r['niveau']; } catch (Throwable $e) {}
        $upd = $pdo->prepare("INSERT INTO role_permissions (role,module,niveau) VALUES(?,?,?) ON DUPLICATE KEY UPDATE niveau=VALUES(niveau)");
        // Étage 1 : accès modules — scope Global (role_permissions) ou surcharges de site (site_role_permissions)
        $acc_site_save = (int)($_POST['acc_site'] ?? 0);
        if ($acc_site_save > 0 && !($_SESSION['super_admin'] ?? false)) $acc_site_save = 0;
        if (function_exists('modules_permissions')) {
            if ($acc_site_save > 0) {
                $delS = $pdo->prepare("DELETE FROM site_role_permissions WHERE site_id=? AND role=?");
                $insS = $pdo->prepare("INSERT INTO site_role_permissions (site_id,role,module,niveau) VALUES (?,?,?,?)");
                foreach ($rolesE as $rk) {
                    $delS->execute([$acc_site_save, $rk]);
                    foreach (array_keys(modules_permissions()) as $mk) {
                        if (function_exists('module_perm_visible') && !module_perm_visible($mk)) continue;
                        if (isset($_POST['modacc'][$rk][$mk])) { $ex = $cur[$rk][$mk] ?? 0; $niv = $ex >= 1 ? $ex : 2; }
                        else { $niv = 0; }
                        if ($niv !== ($cur[$rk][$mk] ?? 0)) $insS->execute([$acc_site_save, $rk, $mk, $niv]);
                    }
                }
            } else {
                foreach ($rolesE as $rk) {
                    foreach (array_keys(modules_permissions()) as $mk) {
                        if (function_exists('module_perm_visible') && !module_perm_visible($mk)) continue;
                        if (isset($_POST['modacc'][$rk][$mk])) { $ex = $cur[$rk][$mk] ?? 0; $niv = $ex >= 1 ? $ex : 2; }
                        else { $niv = 0; }
                        $upd->execute([$rk, $mk, $niv]);
                    }
                }
            }
        }
        // Étage 2 : actions détaillées
        foreach ($rolesE as $rk) {
            foreach (['creer','modifier','ajuster','renouveler','dupliquer','archiver','supprimer','historique','dotation'] as $act) {
                $on = isset($_POST['actperm'][$rk][$act]) ? 1 : 0;
                $upd->execute([$rk, 'action_'.$act, $on]);
            }
        }
        if (($_SESSION['super_admin'] ?? false) && function_exists('admin_tabs_definis')) {
            $adm_new = [];
            foreach (array_keys(admin_tabs_definis()) as $__t) {
                $adm_new[$__t] = $_POST['admintab'][$__t] ?? [];
                if (!in_array('super_admin', $adm_new[$__t], true)) $adm_new[$__t][] = 'super_admin';
            }
            $__v = json_encode($adm_new);
            $pdo->prepare("INSERT INTO parametres (cle,valeur) VALUES('admin_tab_access',?) ON DUPLICATE KEY UPDATE valeur=?")->execute([$__v,$__v]);
        }
        audit_log($pdo,'MODIF_ACCES','role_permissions',0);
        if (function_exists('perms_bump')) perms_bump($pdo);
        flashSuccess("Accès enregistrés.");
        $__q = $acc_site_save > 0 ? "&acc_site=$acc_site_save" : "";
        echo '<script>window.location.href=(window.location.pathname.includes("/admin/")?"?tab=users&onglet=acces'.$__q.'":"users.php?onglet=acces'.$__q.'");</script>'; exit;
    }

    if ($type === 'save_affichage') {
        if (!isAdmin()) { flashError("Réservé aux administrateurs."); echo '<script>window.location.href=(window.location.pathname.includes("/admin/")?"?tab=users&onglet=affichage":"users.php?onglet=affichage");</script>'; exit; }
        $role = $_POST['role'] ?? '';
        if (in_array($role, array_keys(roles_editables()), true)) {
            foreach (array_keys(modules_permissions()) as $mk) {
                $pc  = isset($_POST['pc'][$mk])     ? 1 : 0;
                $mob = isset($_POST['mobile'][$mk]) ? 1 : 0;
                $pdo->prepare("INSERT INTO affichage_modules (role,module,visible_pc,visible_mobile) VALUES(?,?,?,?)
                               ON DUPLICATE KEY UPDATE visible_pc=?, visible_mobile=?")
                    ->execute([$role, $mk, $pc, $mob, $pc, $mob]);
            }
            audit_log($pdo,'MODIF_AFFICHAGE','affichage_modules',0);
            flashSuccess("Affichage du rôle « ".roleLabel($role)." » enregistré.");
        }
        echo '<script>window.location.href=(window.location.pathname.includes("/admin/")?"?tab=users&onglet=affichage":"users.php?onglet=affichage");</script>'; exit;
    }

    // ── Upload photo ──────────────────────────────────────────
    if ($type === 'upload_photo') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if (isset($_FILES['photo']) && $_FILES['photo']['error']===UPLOAD_ERR_OK) {
            $ext  = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            $mime = mime_content_type($_FILES['photo']['tmp_name']);
            if (!in_array($ext,['jpg','jpeg','png','webp']) || !in_array($mime,['image/jpeg','image/png','image/webp'])) {
                flashError('Format non autorisé.');
            } elseif ($_FILES['photo']['size']>2*1024*1024) {
                flashError('Image trop lourde (max 2 Mo).');
            } else {
                $dir = __DIR__.'/../../uploads/avatars/';
                if (!is_dir($dir)) mkdir($dir,0755,true);
                $fn = 'avatar_'.$uid.'_'.time().'.'.$ext;
                move_uploaded_file($_FILES['photo']['tmp_name'],$dir.$fn);
                $pdo->prepare("UPDATE utilisateurs SET photo=? WHERE id=?")->execute(['uploads/avatars/'.$fn,$uid]);
                flashSuccess("Photo mise à jour.");
            }
        }
        echo '<script>window.location.href=(window.location.pathname.includes("/admin/")?"?tab=users&":"users.php?")+"action=edit&id='.$uid.'&onglet=utilisateurs";</script>'; exit;
    }

    // ── Permissions : assigner ────────────────────────────────
    if ($type === 'save_permissions') {
        $uid  = (int)($_POST['user_id'] ?? 0);
        $sid  = (int)($_POST['site_id'] ?? 0);
        $role = $_POST['role'] ?? '';
        if (!$uid || !$sid || !in_array($role,$roles_valides)) {
            flashError('Remplissez tous les champs.');
        } else {
            $pdo->prepare("INSERT INTO user_site_permissions (user_id,site_id,role,locations_ids) VALUES(?,?,?,NULL) ON DUPLICATE KEY UPDATE role=VALUES(role),locations_ids=NULL")
                ->execute([$uid,$sid,$role]);
            // Met à jour le poste automatiquement si vide
            $poste_auto = $roles_config[$role]['poste'] ?? null;
            if ($poste_auto) {
                $pdo->prepare("UPDATE utilisateurs SET poste=? WHERE id=? AND (poste IS NULL OR poste='')")
                    ->execute([$poste_auto,$uid]);
            }
            audit_log($pdo,'MODIF_PERMISSIONS','user_site_permissions',$uid,null,compact('sid','role')); if (function_exists('perms_bump')) perms_bump($pdo);
            flashSuccess("Permissions enregistrées.");
        }
        echo '<script>window.location.href=(window.location.pathname.includes("/admin/")?"?tab=users&onglet=permissions":"users.php?onglet=permissions");</script>'; exit;
    }

    // ── Permissions : supprimer ───────────────────────────────
    if ($type === 'supprimer_permission') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $sid = (int)($_POST['site_id'] ?? 0);
        $pdo->prepare("DELETE FROM user_site_permissions WHERE user_id=? AND site_id=?")->execute([$uid,$sid]);
        audit_log($pdo,'SUPPRESSION_PERMISSION','user_site_permissions',$uid);
        flashSuccess("Accès supprimé.");
        echo '<script>window.location.href=(window.location.pathname.includes("/admin/")?"?tab=users&onglet=permissions":"users.php?onglet=permissions");</script>'; exit;
    }
}

// ================================================================
//  DONNÉES
// ================================================================
// Isolation sites : admin voit seulement les users de son site, super admin voit tout
$_is_super_admin = (bool)($_SESSION['super_admin'] ?? false);
$is_super = $_is_super_admin;
$_my_site_id = site_actif();

if ($_is_super_admin) {
    $users = $pdo->query("
        SELECT u.*,
            (SELECT COUNT(*) FROM user_site_permissions usp WHERE usp.user_id=u.id) AS nb_sites,
            (SELECT COUNT(*) FROM connexions_historique ch WHERE ch.user_id=u.id AND ch.succes=0 AND ch.cree_le>DATE_SUB(NOW(),INTERVAL 24 HOUR)) AS echecs_24h
        FROM utilisateurs u
        ORDER BY u.super_admin DESC, u.actif DESC, u.nom, u.prenom
    ")->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Admin de site : seulement les users de son site + les super admins
    $stmt_u = $pdo->prepare("
        SELECT u.*,
            (SELECT COUNT(*) FROM user_site_permissions usp2 WHERE usp2.user_id=u.id) AS nb_sites,
            (SELECT COUNT(*) FROM connexions_historique ch WHERE ch.user_id=u.id AND ch.succes=0 AND ch.cree_le>DATE_SUB(NOW(),INTERVAL 24 HOUR)) AS echecs_24h
        FROM utilisateurs u
        WHERE u.super_admin=1
           OR EXISTS (SELECT 1 FROM user_site_permissions usp WHERE usp.user_id=u.id AND usp.site_id=?)
        ORDER BY u.super_admin DESC, u.actif DESC, u.nom, u.prenom
    ");
    $stmt_u->execute([$_my_site_id]);
    $users = $stmt_u->fetchAll(PDO::FETCH_ASSOC);
}

$sites = $pdo->query("
    SELECT s.id, s.nom, o.nom AS org_nom
    FROM sites s JOIN organisations o ON o.id=s.organisation_id
    WHERE s.actif=1 AND o.actif=1 ORDER BY o.nom,s.nom
")->fetchAll(PDO::FETCH_ASSOC);

if ($_is_super_admin) {
    $perms_existantes = $pdo->query("
        SELECT usp.*, u.nom, u.prenom, u.login, s.nom AS site_nom, o.nom AS org_nom
        FROM user_site_permissions usp
        JOIN utilisateurs u  ON u.id=usp.user_id
        JOIN sites s         ON s.id=usp.site_id
        JOIN organisations o ON o.id=s.organisation_id
        ORDER BY u.nom,s.nom
    ")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt_pe = $pdo->prepare("
        SELECT usp.*, u.nom, u.prenom, u.login, s.nom AS site_nom, o.nom AS org_nom
        FROM user_site_permissions usp
        JOIN utilisateurs u  ON u.id=usp.user_id
        JOIN sites s         ON s.id=usp.site_id
        JOIN organisations o ON o.id=s.organisation_id
        WHERE usp.site_id = ?
        ORDER BY u.nom,s.nom
    ");
    $stmt_pe->execute([$_my_site_id]);
    $perms_existantes = $stmt_pe->fetchAll(PDO::FETCH_ASSOC);
}

// Grouper permissions par user
$par_user = [];
foreach ($perms_existantes as $p) {
    $key = $p['user_id'];
    if (!isset($par_user[$key])) $par_user[$key] = ['info'=>$p,'perms'=>[]];
    $par_user[$key]['perms'][] = $p;
}

// Données fiche utilisateur
$user_edit = $user_perms = $user_hist = $user_sessions = [];
if (in_array($action,['edit','new']) && $id) {
    $s = $pdo->prepare("SELECT * FROM utilisateurs WHERE id=?");
    $s->execute([$id]); $user_edit = $s->fetch(PDO::FETCH_ASSOC);
    // 🛡️ Un non-super_admin ne peut pas ouvrir la fiche d'un super_admin
    if ($action==='edit' && $user_edit && ($user_edit['super_admin']??0) && !($_SESSION['super_admin']??false)) {
        flashError("⛔ Action interdite : ce compte est protégé (Super Admin).");
        echo '<script>window.location.href=(window.location.pathname.includes("/admin/")?"?tab=users&onglet=utilisateurs":"users.php?onglet=utilisateurs");</script>'; exit;
    }
    if ($user_edit) {
        $s = $pdo->prepare("SELECT usp.*,s.nom AS site_nom,o.nom AS org_nom FROM user_site_permissions usp JOIN sites s ON s.id=usp.site_id JOIN organisations o ON o.id=s.organisation_id WHERE usp.user_id=? ORDER BY o.nom,s.nom");
        $s->execute([$id]); $user_perms = $s->fetchAll(PDO::FETCH_ASSOC);
        try { $s=$pdo->prepare("SELECT * FROM connexions_historique WHERE user_id=? ORDER BY cree_le DESC LIMIT 10"); $s->execute([$id]); $user_hist=$s->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e){}
        try { $s=$pdo->prepare("SELECT sa.*,s2.nom AS site_nom FROM sessions_actives sa LEFT JOIN sites s2 ON s2.id=sa.site_actif_id WHERE sa.user_id=? ORDER BY sa.derniere_activite DESC"); $s->execute([$id]); $user_sessions=$s->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e){}
    }
}

function score_securite(array $u, array $hist): array {
    $score=0; $details=[];
    if ($u['derniere_connexion']&&strtotime($u['derniere_connexion'])>strtotime('-30 days')){$score+=20;$details[]=['ok','Connexion récente'];}else $details[]=['warn','Pas de connexion depuis 30j'];
    if (!$u['bloque_jusqu']||strtotime($u['bloque_jusqu'])<time()){$score+=20;$details[]=['ok','Compte non bloqué'];}else $details[]=['err','Compte bloqué'];
    if ($u['actif']){$score+=20;$details[]=['ok','Compte actif'];}else $details[]=['err','Compte désactivé'];
    $echecs=count(array_filter($hist,fn($h)=>!$h['succes']&&strtotime($h['cree_le'])>strtotime('-7 days')));
    if ($echecs===0){$score+=20;$details[]=['ok','Aucun échec récent'];}else $details[]=['warn',$echecs.' échec(s) ces 7 jours'];
    if (!$u['expire_le']||strtotime($u['expire_le'])>strtotime('+30 days')){$score+=20;$details[]=['ok','Compte valide'];}else $details[]=['warn','Expire le '.date('d/m/Y',strtotime($u['expire_le']))];
    return ['score'=>$score,'color'=>$score>=80?'#10b981':($score>=60?'#f59e0b':'#ef4444'),'details'=>$details];
}

function parse_ua(string $ua): string {
    $d=(str_contains($ua,'iPhone')||str_contains($ua,'Android'))?(str_contains($ua,'iPhone')?'📱 iPhone':'📱 Android'):'🖥️ Desktop';
    if(str_contains($ua,'Chrome'))$b='Chrome';elseif(str_contains($ua,'Firefox'))$b='Firefox';elseif(str_contains($ua,'Safari'))$b='Safari';elseif(str_contains($ua,'Edge'))$b='Edge';else $b='Inconnu';
    return $d.' · '.$b;
}

if (!defined('ADMIN_HUB')) require_once '../includes/header.php';
?>

<style>
/* ── Onglets principaux ── */
.main-tabs { display:flex; gap:4px; margin-bottom:24px; border-bottom:1px solid var(--border); }
.main-tab {
  padding:9px 18px; font-size:13px; font-weight:600;
  color:var(--text2); cursor:pointer; border:none; background:none;
  border-bottom:2px solid transparent; margin-bottom:-1px;
  transition:all .15s; display:flex; align-items:center; gap:7px;
  text-decoration:none;
}
.main-tab:hover { color:var(--text); }
.main-tab.active { color:#3B82F6; border-bottom-color:#3B82F6; }
.main-tab .tab-count { font-size:10px; background:var(--glass2); padding:1px 6px; border-radius:10px; }

/* ── Table utilisateurs ── */
.users-table { width:100%; border-collapse:collapse; }
.users-table th { font-size:11px; font-weight:600; letter-spacing:.05em; text-transform:uppercase; color:var(--text3); padding:8px 12px; border-bottom:1px solid var(--border); text-align:left; }
.users-table td { padding:10px 12px; border-bottom:1px solid rgba(255,255,255,.04); vertical-align:middle; }
.users-table tr:hover td { background:var(--glass); }
.users-table tr:last-child td { border-bottom:none; }

/* ── Role badge ── */
.role-pill { display:inline-flex; align-items:center; gap:4px; padding:2px 9px; border-radius:20px; font-size:11px; font-weight:600; }

/* ── Fiche user ── */
.user-grid { display:grid; grid-template-columns:1fr 1fr; gap:1.5rem; }
@media(max-width:900px){.user-grid{grid-template-columns:1fr}}
.tabs-user { display:flex; gap:4px; margin-bottom:1.25rem; border-bottom:1px solid var(--border); flex-wrap:wrap; }
.tab-user { padding:.5rem 1rem; font-size:.8rem; font-weight:600; color:var(--text2); cursor:pointer; border-bottom:2px solid transparent; margin-bottom:-1px; transition:all .15s; text-decoration:none; display:inline-block; border:none; background:none; }
.tab-user.active { color:var(--primary); border-bottom-color:var(--primary); }
.score-ring { position:relative; display:inline-flex; align-items:center; justify-content:center; }
.score-ring svg { position:absolute; top:0; left:0; transform:rotate(-90deg); }
.hist-row { display:flex; align-items:center; gap:10px; padding:.6rem .75rem; border-radius:.5rem; font-size:.8rem; margin-bottom:.3rem; background:var(--glass); }
.hist-row.fail { background:rgba(239,68,68,.06); }
.hist-dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; }
.dot-ok { background:#10b981; } .dot-err { background:#ef4444; }
.ip-tag { display:inline-block; font-family:var(--mono); font-size:.75rem; padding:.2rem .6rem; background:var(--glass2); border-radius:.3rem; border:1px solid var(--border); }
.ip-tag.suspicious { border-color:rgba(245,158,11,.4); color:#fcd34d; background:rgba(245,158,11,.08); }
.perm-line { display:flex; align-items:center; justify-content:space-between; padding:.7rem 1rem; background:var(--glass); border:1px solid var(--border); border-radius:.5rem; margin-bottom:.5rem; }
.avatar-wrap { position:relative; width:80px; height:80px; flex-shrink:0; }
.avatar-big { width:80px; height:80px; border-radius:50%; object-fit:cover; border:3px solid var(--border); }
.avatar-initials { width:80px; height:80px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:1.75rem; font-weight:800; color:white; border:3px solid var(--border); }
.avatar-edit-btn { position:absolute; bottom:0; right:0; width:26px; height:26px; border-radius:50%; background:var(--primary); display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:.7rem; border:2px solid var(--bg); }
.stat-mini { text-align:center; padding:.75rem; background:var(--glass); border:1px solid var(--border); border-radius:.5rem; }
.stat-mini-val { font-size:1.4rem; font-weight:800; }
.stat-mini-lbl { font-size:.7rem; color:var(--text3); text-transform:uppercase; letter-spacing:.5px; margin-top:2px; }
.score-detail { display:flex; align-items:center; gap:6px; font-size:.8rem; padding:.3rem 0; }
.toggle-sw { display:flex; align-items:center; gap:8px; cursor:pointer; font-size:.875rem; color:var(--text); }
.toggle-sw input { display:none; }
.sw-track { width:40px; height:22px; background:var(--border2); border-radius:11px; position:relative; transition:.2s; flex-shrink:0; }
.toggle-sw input:checked + .sw-track { background:#10b981; }
.sw-thumb { position:absolute; width:16px; height:16px; background:white; border-radius:50%; top:3px; left:3px; transition:.2s; pointer-events:none; }
.toggle-sw input:checked ~ .sw-thumb { left:21px; }

/* ── Permissions ── */
.perm-form-grid { display:grid; grid-template-columns:1fr 1fr 1fr auto; gap:1rem; align-items:end; }
@media(max-width:900px){.perm-form-grid{grid-template-columns:1fr 1fr;}}
@media(max-width:600px){.perm-form-grid{grid-template-columns:1fr;}}
.perm-user-block { margin-bottom:1.25rem; padding-bottom:1.25rem; border-bottom:1px solid var(--border); }
.perm-user-block:last-child { border-bottom:none; margin-bottom:0; padding-bottom:0; }

/* ── Rôles ── */
.roles-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:14px; }
.role-card { background:var(--glass); border:1px solid var(--border); border-radius:12px; padding:16px 18px; position:relative; overflow:hidden; }
.role-card::before { content:''; position:absolute; left:0; top:0; bottom:0; width:3px; border-radius:0; }
.role-card-header { display:flex; align-items:center; gap:10px; margin-bottom:10px; }
.role-card-icon { font-size:22px; }
.role-card-title { font-size:14px; font-weight:700; }
.role-card-desc { font-size:12px; color:var(--text2); margin-bottom:12px; }
.role-access-list { display:flex; flex-direction:column; gap:4px; }
.role-access-item { display:flex; align-items:center; gap:8px; font-size:12px; color:var(--text2); }
.role-access-item .dot-ok { width:6px; height:6px; flex-shrink:0; }
.role-access-item .dot-err { width:6px; height:6px; flex-shrink:0; }
</style>

<div class="page-header">
  <div class="page-title-group">
    <div class="page-title">👥 Utilisateurs & Accès</div>
    <div class="page-sub">Gestion des comptes, permissions et rôles</div>
  </div>
  <?php if ($onglet==='utilisateurs' && $action==='liste'): ?>
  <a href="?action=new&onglet=utilisateurs" class="btn btn-primary">➕ Nouvel utilisateur</a>
  <a href="?action=bulk&onglet=utilisateurs" class="btn btn-ghost">👥 Ajouter plusieurs</a>
  <a href="export_users.php" class="btn btn-ghost">⬇️ Exporter CSV</a>
  <?php elseif ($action!=='liste'): ?>
  <a href="users.php?onglet=<?= h($onglet) ?>" class="btn btn-ghost">← Retour</a>
  <?php endif; ?>
</div>

<!-- ═══ ONGLETS PRINCIPAUX ═══ -->
<div class="main-tabs">
  <a href="?onglet=utilisateurs" class="main-tab <?= $onglet==='utilisateurs'?'active':'' ?>">
    👥 Utilisateurs <span class="tab-count"><?= count($users) ?></span>
  </a>
  <a href="?onglet=affichage" class="main-tab <?= $onglet==='affichage'?'active':'' ?>">
    📱 Affichage
  </a>
  <a href="?onglet=modules" class="main-tab <?= $onglet==='modules'?'active':'' ?>">
    🧩 Modules
  </a>
  <a href="?onglet=acces" class="main-tab <?= $onglet==='acces'?'active':'' ?>">
    🔓 Accès
  </a>
</div>

<?php if ($onglet === 'utilisateurs'): ?>
<?php
// Aperçu des droits effectifs : pré-charge global + surcharges (2 requêtes)
$MODS_PERM = function_exists('modules_permissions') ? modules_permissions() : [];
$global_perms = [];
foreach ($pdo->query("SELECT role,module,niveau FROM role_permissions")->fetchAll(PDO::FETCH_ASSOC) as $g) $global_perms[$g['role']][$g['module']] = (int)$g['niveau'];
$site_perms = [];
foreach ($pdo->query("SELECT site_id,role,module,niveau FROM site_role_permissions")->fetchAll(PDO::FETCH_ASSOC) as $sp) $site_perms[$sp['site_id']][$sp['role']][$sp['module']] = (int)$sp['niveau'];
?>
<!-- ════════════════════════════════════════════════════════
     ONGLET UTILISATEURS
═════════════════════════════════════════════════════════ -->

<style>
.ua-modal{position:fixed;inset:0;background:rgba(8,12,24,.6);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);display:none;align-items:flex-start;justify-content:center;z-index:9999;padding:40px 16px;overflow-y:auto}
.ua-modal.open{display:flex}
.ua-modal-box{position:relative;width:100%;max-width:900px;margin:auto}
.ua-modal-box .card{max-width:100%!important;margin:0}
.ua-modal-x{position:absolute;top:-32px;right:0;color:#fff;font-size:24px;text-decoration:none;line-height:1;opacity:.85}
.ua-modal-x:hover{opacity:1}
</style>
<?php if (in_array($action, ['liste','new','bulk'], true)): ?>
<?php
// Grouper par rôle
$users_by_role = ['super_admin' => [], 'administrateur' => [], 'gestionnaire' => [], 'saisie' => [], 'lecteur' => [], 'biomedical' => [], 'sse' => [], 'aucun' => []];
foreach ($users as $u) {
    $role = null;
    if ($u['super_admin']) { $role = 'super_admin'; }
    else { foreach ($perms_existantes as $pp) { if ($pp['user_id']==$u['id']) { $role=$pp['role']; break; } } }
    $key = isset($users_by_role[$role]) ? $role : 'aucun';
    $users_by_role[$key][] = $u;
}
$users_by_role = array_filter($users_by_role, fn($g) => count($g) > 0);
?>

<style>
/* ════════ Onglet Utilisateurs — refonte v5.4 ════════ */
.ua-stats{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:16px}
.ua-kpi{background:var(--bg);border:1px solid var(--border);border-radius:14px;padding:14px 16px;display:flex;align-items:center;gap:12px;box-shadow:0 6px 16px rgba(30,40,80,.05)}
.ua-kpi .k-ic{width:38px;height:38px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
.ua-kpi .k-n{font-size:22px;font-weight:800;line-height:1}
.ua-kpi .k-l{font-size:11.5px;color:var(--text2);font-weight:600;margin-top:2px}
#uaList.never-only .ua-row[data-never="0"]{display:none}
#uaList.never-only .ua-sep{display:none}
.ua-kpi.kpi-on{outline:2px solid #d97706;outline-offset:-1px}
@media(max-width:1100px){.ua-stats{grid-template-columns:repeat(3,1fr)}}
@media(max-width:720px){.ua-stats{grid-template-columns:repeat(2,1fr)}}
.ua-toolbar{display:flex;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap}
.ua-search{flex:1;min-width:220px;display:flex;align-items:center;gap:9px;background:var(--bg);border:1.5px solid var(--border);border-radius:12px;padding:10px 14px;transition:all .15s}
.ua-search:focus-within{border-color:var(--primary);box-shadow:0 0 0 3px rgba(var(--primary-rgb),.12)}
.ua-search i{color:var(--text3);font-size:17px}
.ua-search input{border:none;outline:none;background:none;font-family:inherit;font-size:14px;width:100%;color:var(--text)}
.ua-card{background:var(--bg);border-radius:18px;box-shadow:0 12px 30px rgba(30,40,80,.10);overflow:hidden}
.ua-sep{display:flex;align-items:center;gap:10px;padding:13px 20px 11px;background:var(--bg2);border-bottom:1px solid var(--border);cursor:pointer;user-select:none}
.ua-sep:hover{background:var(--bg3)}
.ua-rt{width:26px;height:26px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0}
.ua-rn{font-weight:800;font-size:13px}
.ua-rd{font-size:11.5px;color:var(--text3);font-weight:500}
.ua-rc{margin-left:auto;font-size:11px;font-weight:800;padding:2px 10px;border-radius:11px}
.ua-chev{font-size:14px;color:var(--text3);transition:transform .2s;margin-left:8px}
.ua-row{display:flex;align-items:center;gap:14px;padding:12px 20px;border-bottom:1px solid var(--border);transition:background .12s;flex-wrap:nowrap}
.ua-row:hover{background:var(--bg2)}
.ua-avatar{width:42px;height:42px;border-radius:12px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:15px;color:#fff;object-fit:cover}
.ua-id{min-width:0;flex:1.6}
.ua-name{font-weight:700;font-size:14.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ua-meta{font-size:12px;color:var(--text2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:1px}
.ua-login{font-family:var(--mono,monospace);font-size:11px;color:var(--text3)}
.ua-sites{flex:2;display:flex;flex-wrap:wrap;gap:5px;align-items:center;min-width:0}
.ua-spill{font-size:11px;font-weight:700;padding:3px 9px;border-radius:8px;display:inline-flex;align-items:center;gap:4px;white-space:nowrap}
.ua-spill.ua-none{background:var(--bg3);color:var(--text3);border:1px dashed var(--border)}
.ua-spill.ua-all{background:rgba(239,68,68,.12);color:#dc2626}
.ua-mini{font-size:10px;font-weight:700;padding:1px 7px;border-radius:7px}
.ua-mini.ua-lock{background:rgba(239,68,68,.12);color:#dc2626}
.ua-mini.ua-warn{background:rgba(245,158,11,.14);color:#d97706}
.ua-status{flex:1.2;display:flex;flex-direction:row;flex-wrap:wrap;gap:5px;align-items:center;min-width:0}
.ua-dot{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600}
.ua-dot::before{content:'';width:7px;height:7px;border-radius:50%}
.ua-dot.ok{color:#16a34a}.ua-dot.ok::before{background:#16a34a}
.ua-dot.off{color:var(--text3)}.ua-dot.off::before{background:#cbd5e1}
.ua-last{flex:1;font-size:11.5px;color:var(--text2);line-height:1.4;min-width:0}
.ua-ip{font-family:var(--mono,monospace);font-size:10px;color:var(--text3)}
.ua-acts{display:flex;gap:4px;align-items:center;width:184px;flex-shrink:0;justify-content:flex-end}
.ua-acts form{display:inline;margin:0}
.ua-ib{width:30px;height:30px;border-radius:8px;border:1px solid var(--border);background:var(--bg);color:var(--text2);display:inline-flex;align-items:center;justify-content:center;cursor:pointer;font-size:15px;transition:all .12s;padding:0;text-decoration:none}
.ua-ib:hover{background:rgba(var(--primary-rgb),.10);color:var(--primary);border-color:rgba(var(--primary-rgb),.3)}
.ua-ib.ua-danger:hover{background:rgba(239,68,68,.1);color:#dc2626;border-color:rgba(239,68,68,.3)}
.ua-perm{background:var(--bg2);border-bottom:1px solid var(--border);padding:14px 20px 16px}
.ua-perm-title{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--text3);margin-bottom:10px}
.ua-eff-wrap{margin:2px 0 8px}
.ua-eff-toggle{display:inline-flex;align-items:center;gap:6px;background:none;border:none;font-family:inherit;font-size:11.5px;font-weight:700;color:var(--primary);cursor:pointer;padding:2px 0}
.ua-eff{display:none;flex-wrap:wrap;gap:5px;margin-top:6px}
.ua-eff-chip{font-size:10.5px;font-weight:700;padding:2px 8px;border-radius:7px}
.ua-eff-chip.read{background:rgba(59,130,246,.12);color:#3b82f6}
.ua-eff-chip.full{background:rgba(22,163,74,.12);color:#16a34a}
.ua-eff-none{font-size:11.5px;color:var(--text3)}
.ua-perm-item{display:flex;align-items:center;justify-content:space-between;padding:8px 12px;background:var(--bg);border:1px solid var(--border);border-radius:10px;margin-bottom:6px}
.ua-perm-site{font-size:.85rem;font-weight:600}
.ua-perm-org{font-size:.72rem;color:var(--text3);margin-left:6px}
.ua-perm-add{display:flex;gap:8px;align-items:center;margin-top:10px;flex-wrap:wrap}
.ua-perm-add select{font-family:inherit;font-size:13px;color:var(--text);background:var(--bg);border:1.5px solid var(--border);border-radius:10px;padding:8px 12px;flex:1;min-width:140px}
.ua-btn-add{border:none;background:var(--primary);color:#fff;font-family:inherit;font-weight:700;font-size:13px;border-radius:10px;padding:9px 16px;cursor:pointer;display:inline-flex;align-items:center;gap:6px}
.ua-empty{padding:40px;text-align:center;color:var(--text3);font-size:13px}
@media(max-width:820px){.ua-last,.ua-status{display:none}}
</style>

<?php
$st_total=count($users); $st_actifs=0; $st_bloques=0; $st_sans=0; $st_never=0;
foreach ($users as $uu) {
  if (!empty($uu['actif'])) $st_actifs++;
  if (empty($uu['derniere_connexion'])) $st_never++;
  if (!empty($uu['bloque_jusqu']) && strtotime($uu['bloque_jusqu'])>time()) $st_bloques++;
  if (empty($uu['super_admin'])) { $hh=false; foreach ($perms_existantes as $pp) if ($pp['user_id']==$uu['id']){ $hh=true; break; } if (!$hh) $st_sans++; }
}
?>
<div class="ua-stats">
  <div class="ua-kpi" data-f="all" onclick="uaSetCat('all')" style="cursor:pointer"><span class="k-ic" style="background:rgba(var(--primary-rgb),.12);color:var(--primary)">👥</span><div><div class="k-n"><?= $st_total ?></div><div class="k-l">Utilisateurs</div></div></div>
  <div class="ua-kpi" data-f="actif" onclick="uaSetCat('actif')" style="cursor:pointer"><span class="k-ic" style="background:rgba(22,163,74,.12);color:#16a34a">✅</span><div><div class="k-n"><?= $st_actifs ?></div><div class="k-l">Actifs</div></div></div>
  <div class="ua-kpi" data-f="bloque" onclick="uaSetCat('bloque')" style="cursor:pointer"><span class="k-ic" style="background:rgba(239,68,68,.12);color:#dc2626">🔒</span><div><div class="k-n"><?= $st_bloques ?></div><div class="k-l">Bloqués</div></div></div>
  <div class="ua-kpi" data-f="sansite" onclick="uaSetCat('sansite')" style="cursor:pointer"><span class="k-ic" style="background:rgba(245,158,11,.14);color:#d97706">📍</span><div><div class="k-n"><?= $st_sans ?></div><div class="k-l">Sans site</div></div></div>
  <div class="ua-kpi" id="kpiNever" data-f="never" onclick="uaSetCat('never')" style="cursor:pointer" title="Cliquer pour filtrer"><span class="k-ic" style="background:rgba(100,116,139,.14);color:#64748b">📭</span><div><div class="k-n"><?= $st_never ?></div><div class="k-l">Jamais connectés</div></div></div>
</div>
<script>
var UA_CAT='all';
function uaSetCat(cat){
  UA_CAT = (UA_CAT===cat) ? 'all' : cat;
  document.querySelectorAll('.ua-kpi[data-f]').forEach(function(k){ k.classList.toggle('kpi-on', k.getAttribute('data-f')===UA_CAT && UA_CAT!=='all'); });
  uaApply();
}
function uaApply(){
  var s=document.getElementById('uaSearch'); var q=((s&&s.value)||'').toLowerCase();
  document.querySelectorAll('#uaList .ua-row').forEach(function(r){
    var okS=(r.dataset.search||'').includes(q), okC=true;
    if(UA_CAT==='actif')        okC=r.dataset.actif==='1';
    else if(UA_CAT==='bloque')  okC=r.dataset.bloque==='1';
    else if(UA_CAT==='sansite')  okC=r.dataset.sansite==='1';
    else if(UA_CAT==='never')   okC=r.dataset.never==='1';
    r.style.display=(okS&&okC)?'':'none';
  });
  document.querySelectorAll('#uaList .ua-sep').forEach(function(sep){
    var g=sep.getAttribute('data-sepgrp'), vis=0;
    document.querySelectorAll('#uaList .ua-row[data-grp="'+g+'"]').forEach(function(r){ if(r.style.display!=='none') vis++; });
    sep.style.display=vis?'':'none';
  });
}
</script>
<div class="ua-toolbar">
  <div class="ua-search"><i class="ti ti-search"></i><input id="uaSearch" onkeyup="uaFilter()" placeholder="Rechercher un utilisateur, un email, un site…"></div>
</div>

<div class="ua-card" id="uaList">
<?php foreach ($users_by_role as $role_key => $group_users):
  $rc_g = $roles_config[$role_key] ?? ['label'=>'Sans site attribué','color'=>'#64748b','icon'=>'👤','desc'=>''];
?>
  <div class="ua-sep" data-sepgrp="grp-<?= $role_key ?>" onclick="toggleGroup('grp-<?= $role_key ?>')">
    <span class="ua-rt" style="background:<?= $rc_g['color'] ?>22"><?= $rc_g['icon'] ?></span>
    <span class="ua-rn" style="color:<?= $rc_g['color'] ?>"><?= $rc_g['label'] ?></span>
    <?php if (!empty($rc_g['desc'])): ?><span class="ua-rd"><?= $rc_g['desc'] ?></span><?php endif; ?>
    <span class="ua-rc" style="background:<?= $rc_g['color'] ?>18;color:<?= $rc_g['color'] ?>"><?= count($group_users) ?></span>
    <i class="ti ti-chevron-down ua-chev" id="chev-<?= $role_key ?>"></i>
  </div>
  <?php foreach ($group_users as $u):
    $bloque   = $u['bloque_jusqu'] && strtotime($u['bloque_jusqu']) > time();
    $expire   = $u['expire_le'] && strtotime($u['expire_le']) < time();
    $initials = strtoupper(substr($u['prenom'],0,1).substr($u['nom'],0,1));
    $search   = strtolower($u['prenom'].' '.$u['nom'].' '.$u['email'].' '.$u['login']);
    $sansite  = !$u['super_admin'] && count(array_filter($perms_existantes, fn($p) => $p['user_id'] == $u['id'])) === 0;
  ?>
  <div class="ua-row" data-grp="grp-<?= $role_key ?>" data-never="<?= empty($u['derniere_connexion'])?'1':'0' ?>" data-actif="<?= $u['actif']?'1':'0' ?>" data-bloque="<?= $bloque?'1':'0' ?>" data-sansite="<?= $sansite?'1':'0' ?>" data-search="<?= h($search) ?>">
    <?php if ($u['photo']): ?>
      <img src="/<?= h($u['photo']) ?>" class="ua-avatar" alt="">
    <?php else: ?>
      <div class="ua-avatar" style="background:<?= $rc_g['color'] ?>"><?= h($initials) ?></div>
    <?php endif; ?>
    <div class="ua-id">
      <div class="ua-name"><?= h($u['prenom'].' '.$u['nom']) ?></div>
      <div class="ua-meta"><?= h($u['email']) ?> · <span class="ua-login"><?= h($u['login']) ?></span></div>
    </div>
    <div class="ua-sites">
      <?php if ($u['super_admin']): ?>
        <span class="ua-spill ua-all">⭐ Tous les sites</span>
      <?php else:
        $u_perms = array_filter($perms_existantes, fn($p) => $p['user_id'] == $u['id']);
        if (empty($u_perms)): ?>
        <span class="ua-spill ua-none">Aucun site</span>
        <?php else: foreach ($u_perms as $up): $rc_up = $roles_config[$up['role']] ?? ['color'=>'#64748b','icon'=>'?']; ?>
        <span class="ua-spill" style="background:<?= $rc_up['color'] ?>22;color:<?= $rc_up['color'] ?>"><?= $rc_up['icon'] ?> <?= h($up['site_nom']) ?></span>
        <?php endforeach; endif;
      endif; ?>
      <?php if (($u['echecs_24h'] ?? 0) >= 3): ?><span class="ua-mini ua-warn">⚠️ <?= (int)$u['echecs_24h'] ?> échecs</span><?php endif; ?>
    </div>
    <div class="ua-status">
      <span class="ua-dot <?= $u['actif']?'ok':'off' ?>"><?= $u['actif']?'Actif':'Inactif' ?></span>
      <?php if ($bloque): ?><span class="ua-mini ua-lock">🔒 Bloqué</span><?php endif; ?>
      <?php if ($expire): ?><span class="ua-mini ua-lock">⏰ Expiré</span><?php endif; ?>
      <?php if (empty($u['derniere_connexion'])): ?><span class="ua-mini ua-warn">📭 Jamais connecté</span><?php endif; ?>
    </div>
    <div class="ua-last">
      <?= $u['derniere_connexion'] ? date('d/m/Y H:i',strtotime($u['derniere_connexion'])) : 'Jamais' ?>
      <?php if ($u['derniere_ip']): ?><br><span class="ua-ip"><?= h($u['derniere_ip']) ?></span><?php endif; ?>
    </div>
    <div class="ua-acts">
      <?php $_peut_gerer = (!$u['super_admin'] || ($_SESSION['super_admin'] ?? false)); ?>
      <?php if ($_peut_gerer): ?>
      <a href="?action=edit&id=<?= $u['id'] ?>&onglet=utilisateurs" class="ua-ib" title="Modifier"><i class="ti ti-edit"></i></a>
      <form method="POST" onsubmit="return confirm('Régénérer le mot de passe et envoyer par email ?')" style="margin:0"><?= csrf_field() ?><input type="hidden" name="type_form" value="resend_credentials"><input type="hidden" name="user_id" value="<?= $u['id'] ?>"><button class="ua-ib" title="Renvoyer les identifiants par email"><i class="ti ti-mail"></i></button></form>
      <?php endif; ?>
      <?php if (!$u['super_admin']): ?>
      <button type="button" class="ua-ib" title="Gérer les accès aux sites" onclick="togglePermRow(<?= $u['id'] ?>)"><i class="ti ti-key"></i></button>
      <?php endif; ?>
      <?php if ($_peut_gerer && $u['id'] !== ($_SESSION['user_id'] ?? 0)): ?>
      <form method="POST"><?= csrf_field() ?>
        <input type="hidden" name="type_form" value="toggle_user">
        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
        <button class="ua-ib" title="<?= $u['actif']?'Désactiver':'Activer' ?>"><i class="ti <?= $u['actif']?'ti-player-pause':'ti-player-play' ?>"></i></button>
      </form>
      <form method="POST" onsubmit="return confirm('Supprimer définitivement ?')"><?= csrf_field() ?>
        <input type="hidden" name="type_form" value="delete_user">
        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
        <button class="ua-ib ua-danger" title="Supprimer"><i class="ti ti-trash"></i></button>
      </form>
      <?php endif; ?>
      <?php if (!$_peut_gerer): ?><span class="ua-ib" style="opacity:.45;cursor:default" title="Compte protégé"><i class="ti ti-lock"></i></span><?php endif; ?>
    </div>
  </div>
  <?php if (!$u['super_admin']): ?>
  <div class="ua-perm" id="perm-row-<?= $u['id'] ?>" data-grp="grp-<?= $role_key ?>" style="display:none">
    <div class="ua-perm-title">🔐 Accès de <?= h($u['prenom']) ?> aux sites</div>
    <?php
    $u_perms2 = array_filter($perms_existantes, fn($p) => $p['user_id'] == $u['id']);
    foreach ($u_perms2 as $up2): $rc_up2 = $roles_config[$up2['role']] ?? ['label'=>$up2['role'],'color'=>'#64748b','icon'=>'?']; ?>
    <div class="ua-perm-item">
      <div><span class="ua-perm-site">🗺 <?= h($up2['site_nom']) ?></span><span class="ua-perm-org"><?= h($up2['org_nom']) ?></span></div>
      <div style="display:flex;align-items:center;gap:8px">
        <span class="ua-spill" style="background:<?= $rc_up2['color'] ?>22;color:<?= $rc_up2['color'] ?>"><?= $rc_up2['icon'] ?> <?= $rc_up2['label'] ?></span>
        <form method="POST"><?= csrf_field() ?>
          <input type="hidden" name="type_form" value="supprimer_permission">
          <input type="hidden" name="user_id" value="<?= $up2['user_id'] ?>">
          <input type="hidden" name="site_id" value="<?= $up2['site_id'] ?>">
          <button class="ua-ib ua-danger" onclick="return confirm('Retirer cet accès ?')" title="Retirer"><i class="ti ti-x"></i></button>
        </form>
      </div>
    </div>
    <?php
    $eff = [];
    foreach ($MODS_PERM as $mk => $mc) {
      $niv = $site_perms[$up2['site_id']][$up2['role']][$mk] ?? $global_perms[$up2['role']][$mk] ?? 0;
      if ($niv > 0) $eff[] = ['niv'=>$niv,'label'=>$mc['label']];
    }
    ?>
    <div class="ua-eff-wrap">
      <button type="button" class="ua-eff-toggle" onclick="var b=this.nextElementSibling;b.style.display=b.style.display==='flex'?'none':'flex'"><i class="ti ti-eye"></i> Droits effectifs (<?= count($eff) ?>)</button>
      <div class="ua-eff">
        <?php if (empty($eff)): ?><span class="ua-eff-none">Aucun accès actif</span>
        <?php else: foreach ($eff as $e): ?><span class="ua-eff-chip <?= $e['niv']==2?'full':'read' ?>"><?= $e['niv']==2?'✓':'👁' ?> <?= h($e['label']) ?></span>
        <?php endforeach; endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <form method="POST" class="ua-perm-add"><?= csrf_field() ?>
      <input type="hidden" name="type_form" value="save_permissions">
      <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
      <select name="site_id" required><option value="">— Site —</option><?php foreach ($sites as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['nom']) ?></option><?php endforeach; ?></select>
      <select name="role" required><option value="">— Rôle —</option><?php foreach ($roles_valides as $r): $rc2 = $roles_config[$r]; ?><option value="<?= $r ?>"><?= $rc2['icon'] ?> <?= $rc2['label'] ?></option><?php endforeach; ?></select>
      <button type="submit" class="ua-btn-add"><i class="ti ti-plus"></i> Assigner</button>
    </form>
  </div>
  <?php endif; ?>
  <?php endforeach; ?>
<?php endforeach; ?>
</div>

<script>
function togglePermRow(uid){
  const r=document.getElementById('perm-row-'+uid);
  if(r) r.style.display=(r.style.display==='none'||!r.style.display)?'block':'none';
}
function toggleGroup(grpKey){
  const chev=document.getElementById('chev-'+grpKey);
  const rows=document.querySelectorAll('[data-grp="'+grpKey+'"]');
  let isOpen=false;
  rows.forEach(r=>{ if(!(r.id&&r.id.indexOf('perm-row-')===0) && r.style.display!=='none') isOpen=true; });
  rows.forEach(r=>{
    if(r.id&&r.id.indexOf('perm-row-')===0){ r.style.display='none'; }
    else { r.style.display=isOpen?'none':''; }
  });
  if(chev) chev.style.transform=isOpen?'rotate(-90deg)':'';
}
function uaFilter(){ uaApply(); }
</script>

<?php endif; /* fin liste */ ?>
<?php if ($action === 'new'): ?>
<div class="ua-modal open" onclick="if(event.target===this)location.href='?action=liste&onglet=utilisateurs'">
  <div class="ua-modal-box">
    <a href="?action=liste&onglet=utilisateurs" class="ua-modal-x" title="Fermer">✕</a>
<div class="card" style="max-width:760px">
  <div class="card-title">➕ Nouvel utilisateur</div>
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="type_form" value="save_user">

    <div class="ua-sec">👤 Identité</div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Prénom *</label><input type="text" name="prenom" class="form-control" required></div>
      <div class="form-group"><label class="form-label">Nom *</label><input type="text" name="nom" class="form-control" required></div>
    </div>
    <div class="form-group"><label class="form-label">Téléphone</label><input type="text" name="telephone" class="form-control"></div>

    <div class="ua-sec">🔑 Connexion</div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Email *</label><input type="email" name="email" class="form-control" required></div>
      <div class="form-group"><label class="form-label">Login *</label><input type="text" name="login" class="form-control" required autocomplete="username"></div>
    </div>
    <div class="form-group">
      <label class="form-label">Mot de passe * (8 min, 1 majuscule, 1 chiffre)</label>
      <div style="display:flex;gap:8px;align-items:center">
        <input type="password" name="mot_de_passe" class="form-control" required minlength="8" autocomplete="new-password" id="new-pwd" style="flex:1">
        <button type="button" class="btn btn-ghost btn-sm" onclick="togglePwdVis('new-pwd','eye-create')" title="Voir"><span id="eye-create">👁️</span></button>
        <button type="button" class="btn btn-ghost btn-sm" onclick="genPwd('new-pwd','pwd-strength')" title="Générer">🎲</button>
        <button type="button" class="btn btn-ghost btn-sm" onclick="copyPwd('new-pwd')" title="Copier">📋</button>
      </div>
      <div id="pwd-strength" style="margin-top:.4rem;font-size:.75rem"></div>
    </div>
    <label class="ua-check"><input type="checkbox" name="force_mdp" checked> 🔒 Obliger à changer le mot de passe à la première connexion</label>

    <div class="ua-sec">🛡️ Premier accès <span style="font-weight:400;color:var(--text3);font-size:11px">(optionnel — sinon à définir plus tard)</span></div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Site</label>
        <select name="premier_site" class="form-control"><option value="">— Aucun pour l'instant —</option><?php foreach ($sites as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['org_nom']) ?> — <?= h($s['nom']) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label class="form-label">Rôle</label>
        <select name="premier_role" class="form-control" onchange="autoPoste(this.value)"><option value="">— Rôle —</option><?php foreach ($roles_valides as $r): $rc2 = $roles_config[$r]; ?><option value="<?= $r ?>"><?= $rc2['icon'] ?> <?= h($rc2['label']) ?></option><?php endforeach; ?></select></div>
    </div>

    <div class="ua-sec">⚙️ Détails & options</div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Poste <span style="color:var(--text3);font-size:.75rem">(auto selon rôle)</span></label>
        <input type="text" name="poste" class="form-control" id="field-poste" placeholder="Rempli selon le rôle"></div>
      <div class="form-group"><label class="form-label">Service</label><input type="text" name="service" class="form-control"></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Expiration du compte</label><input type="date" name="expire_le" class="form-control"></div>
      <div class="form-group" style="display:flex;align-items:flex-end"><label class="toggle-sw" style="margin-bottom:9px"><input type="checkbox" name="actif" checked><div class="sw-track"></div><div class="sw-thumb"></div> Compte actif</label></div>
    </div>
    <div class="form-group"><label class="form-label">Note interne</label><textarea name="note_admin" class="form-control" rows="2"></textarea></div>
    <label class="ua-check"><input type="checkbox" name="send_welcome"> 📧 Envoyer les identifiants par email au nouvel utilisateur</label>

    <div style="display:flex;gap:.75rem;margin-top:18px">
      <button type="submit" class="btn btn-primary">💾 Créer l'utilisateur</button>
      <a href="users.php?onglet=utilisateurs" class="btn btn-ghost">Annuler</a>
    </div>
  </form>
</div>
<style>
.ua-sec{font-weight:700;font-size:13px;color:var(--text2);margin:20px 0 10px;padding-bottom:6px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:7px}
.ua-check{display:flex;align-items:center;gap:9px;font-size:13px;font-weight:500;color:var(--text);background:var(--bg2);border:1px solid var(--border);border-radius:10px;padding:11px 13px;margin-top:10px;cursor:pointer}
.ua-check input{width:16px;height:16px;flex-shrink:0}
</style>

  </div>
</div>
<?php endif; /* fin new */ ?>
<?php if ($action === 'bulk'): ?>
<div class="ua-modal open" onclick="if(event.target===this)location.href='?action=liste&onglet=utilisateurs'">
  <div class="ua-modal-box">
    <a href="?action=liste&onglet=utilisateurs" class="ua-modal-x" title="Fermer">✕</a>
<div class="card" style="max-width:880px">
  <div class="card-title">👥 Ajouter plusieurs utilisateurs</div>
  <p style="color:var(--text2);font-size:13px;margin-bottom:16px">Crée plusieurs comptes d'un coup (un service entier). Tous reçoivent le <b>même site</b> et le <b>même rôle</b>. Le login est généré automatiquement, un mot de passe est créé et envoyé par email.</p>
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="type_form" value="save_users_bulk">
    <div class="form-row">
      <div class="form-group"><label class="form-label">Site commun *</label>
        <select name="bulk_site" class="form-control" required><option value="">— Choisir —</option><?php foreach ($sites as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['org_nom']) ?> — <?= h($s['nom']) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label class="form-label">Rôle commun *</label>
        <select name="bulk_role" class="form-control" required><option value="">— Choisir —</option><?php foreach ($roles_valides as $r): $rc2=$roles_config[$r]; ?><option value="<?= $r ?>"><?= $rc2['icon'] ?> <?= h($rc2['label']) ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="form-group">
      <label class="form-label">Liste — une personne par ligne, format <code>Prénom;Nom;Email</code></label>
      <textarea name="bulk_list" id="bulk-list" class="form-control" rows="8" oninput="bulkPreview()" style="font-family:var(--mono,monospace);font-size:13px" placeholder="Jean;Dupont;jean.dupont@sdis51.fr&#10;Marie;Martin;marie.martin@sdis51.fr"></textarea>
    </div>
    <div id="bulk-preview"></div>
    <label class="ua-check"><input type="checkbox" name="bulk_force_mdp" checked> 🔒 Forcer le changement de mot de passe à la première connexion</label>
    <label class="ua-check"><input type="checkbox" name="bulk_send_mail" checked> 📧 Envoyer les identifiants par email à chaque personne</label>
    <div style="display:flex;gap:.75rem;margin-top:18px">
      <button type="submit" class="btn btn-primary">💾 Créer les comptes</button>
      <a href="users.php?onglet=utilisateurs" class="btn btn-ghost">Annuler</a>
    </div>
  </form>
</div>
<style>
.bulk-tbl{width:100%;border-collapse:collapse;margin-top:8px;font-size:12.5px}
.bulk-tbl th{text-align:left;color:var(--text3);font-weight:700;padding:6px 10px;border-bottom:1px solid var(--border)}
.bulk-tbl td{padding:6px 10px;border-bottom:1px solid var(--border)}
.bulk-ok{color:#10b981;font-weight:600}.bulk-err{color:#dc2626;font-weight:600}
.bulk-login{font-family:var(--mono,monospace);font-weight:700;color:var(--primary)}
.ua-check{display:flex;align-items:center;gap:9px;font-size:13px;font-weight:500;color:var(--text);background:var(--bg2);border:1px solid var(--border);border-radius:10px;padding:11px 13px;margin-top:10px;cursor:pointer}
.ua-check input{width:16px;height:16px;flex-shrink:0}
</style>
<script>
function bulkSlug(s){s=(s||'').toLowerCase().trim();s=s.normalize('NFD').replace(/[\u0300-\u036f]/g,'');return s.replace(/[^a-z0-9]/g,'');}
function bulkPreview(){
  const raw=document.getElementById('bulk-list').value;
  const lines=raw.split(/\r\n|\r|\n/).map(l=>l.trim()).filter(l=>l!=='');
  const box=document.getElementById('bulk-preview');
  if(!lines.length){box.innerHTML='';return;}
  const used={}; let rows=''; let ok=0;
  lines.forEach(ln=>{
    const p=ln.split(/[;,\t]+/);
    const prenom=(p[0]||'').trim(),nom=(p[1]||'').trim(),email=(p[2]||'').trim();
    let login='',statut='',cls='bulk-ok';
    if(!prenom||!nom||!email){statut='⚠️ incomplet';cls='bulk-err';}
    else if(!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)){statut='⚠️ email invalide';cls='bulk-err';}
    else{let base=(bulkSlug(prenom).charAt(0)||'')+bulkSlug(nom);if(!base)base='user';login=base;let i=2;while(used[login]){login=base+i;i++;}used[login]=1;statut='✓ prêt';ok++;}
    rows+='<tr><td>'+(prenom||'—')+'</td><td>'+(nom||'—')+'</td><td>'+(email||'—')+'</td><td class="bulk-login">'+(login||'—')+'</td><td class="'+cls+'">'+statut+'</td></tr>';
  });
  box.innerHTML='<div style="font-size:12px;color:var(--text2);margin-top:6px">'+ok+' / '+lines.length+' prêt(s)</div><table class="bulk-tbl"><thead><tr><th>Prénom</th><th>Nom</th><th>Email</th><th>Login généré</th><th>Statut</th></tr></thead><tbody>'+rows+'</tbody></table>';
}
</script>

  </div>
</div>
<?php endif; /* fin bulk */ ?>
<?php if ($action==='edit' && $user_edit): ?>
<?php
$sec        = score_securite($user_edit, $user_hist);
$bloque     = $user_edit['bloque_jusqu'] && strtotime($user_edit['bloque_jusqu'])>time();
$nb_sessions= count($user_sessions);
$ips        = array_unique(array_column($user_hist,'ip'));
$ip_suspect = count($ips)>=3;
$rc_edit    = $user_edit['super_admin'] ? $roles_config['super_admin'] : null;
// Cherche rôle principal
foreach ($user_perms as $pp) { if (!$rc_edit) $rc_edit = $roles_config[$pp['role']] ?? null; break; }
?>

<div class="card" style="margin-bottom:1.5rem">
  <div style="display:flex;align-items:center;gap:1.5rem;flex-wrap:wrap">
    <div class="avatar-wrap">
      <?php if ($user_edit['photo']): ?><img src="/<?= h($user_edit['photo']) ?>" class="avatar-big">
      <?php else: ?><div class="avatar-initials" style="background:<?= h($rc_edit?$rc_edit['color']:'#64748b') ?>"><?= strtoupper(substr($user_edit['prenom'],0,1).substr($user_edit['nom'],0,1)) ?></div><?php endif; ?>
      <form method="POST" enctype="multipart/form-data" id="photo-form">
        <?= csrf_field() ?><input type="hidden" name="type_form" value="upload_photo"><input type="hidden" name="user_id" value="<?= $user_edit['id'] ?>">
        <input type="file" name="photo" id="photo-input" accept="image/*" style="display:none" onchange="document.getElementById('photo-form').submit()">
      </form>
      <div class="avatar-edit-btn" onclick="document.getElementById('photo-input').click()">📷</div>
    </div>
    <div style="flex:1;min-width:0">
      <div style="font-size:1.4rem;font-weight:800;display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
        <?= h($user_edit['prenom'].' '.$user_edit['nom']) ?>
        <?php if ($rc_edit): ?><span class="role-pill" style="background:<?= $rc_edit['color'] ?>22;color:<?= $rc_edit['color'] ?>;border:1px solid <?= $rc_edit['color'] ?>44;font-size:.72rem"><?= $rc_edit['icon'] ?> <?= $rc_edit['label'] ?></span><?php endif; ?>
        <?php if (!$user_edit['actif']): ?><span class="badge badge-expired" style="font-size:.72rem">Inactif</span><?php endif; ?>
        <?php if ($bloque): ?><span class="badge badge-critical" style="font-size:.72rem">🔒 Bloqué</span><?php endif; ?>
      </div>
      <div style="color:var(--text2);font-size:.85rem;margin-top:.2rem"><?= h($user_edit['poste']??'') ?><?php if($user_edit['service']): ?> · <?= h($user_edit['service']) ?><?php endif; ?></div>
      <div style="display:flex;gap:1rem;margin-top:.5rem;flex-wrap:wrap;font-size:.78rem;color:var(--text3)">
        <span>📧 <?= h($user_edit['email']) ?></span>
        <span style="font-family:var(--mono)">👤 <?= h($user_edit['login']) ?></span>
        <?php if($user_edit['telephone']): ?><span>📞 <?= h($user_edit['telephone']) ?></span><?php endif; ?>
      </div>
    </div>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:.6rem;flex-shrink:0">
      <div class="stat-mini"><div class="stat-mini-val"><?= $user_edit['nb_connexions']??0 ?></div><div class="stat-mini-lbl">Connexions</div></div>
      <div class="stat-mini"><div class="stat-mini-val" style="color:<?= $nb_sessions>0?'#10b981':'var(--text3)' ?>"><?= $nb_sessions ?></div><div class="stat-mini-lbl">Sessions</div></div>
      <div class="stat-mini"><div class="stat-mini-val" style="color:<?= $ip_suspect?'#f59e0b':'var(--text)' ?>"><?= count($ips) ?></div><div class="stat-mini-lbl">IPs</div></div>
    </div>
    <div style="text-align:center;flex-shrink:0">
      <div class="score-ring" style="width:80px;height:80px">
        <svg width="80" height="80" viewBox="0 0 80 80"><circle cx="40" cy="40" r="34" fill="none" stroke="var(--border2)" stroke-width="6"/><circle cx="40" cy="40" r="34" fill="none" stroke="<?= $sec['color'] ?>" stroke-width="6" stroke-dasharray="<?= round(2*M_PI*34*$sec['score']/100,1) ?> <?= round(2*M_PI*34,1) ?>" stroke-linecap="round"/></svg>
        <div style="font-size:1.2rem;font-weight:800;color:<?= $sec['color'] ?>;position:relative;z-index:1"><?= $sec['score'] ?></div>
      </div>
      <div style="font-size:.7rem;color:var(--text3);margin-top:.2rem">Sécurité</div>
    </div>
  </div>
</div>

<!-- Actions rapides -->
<div style="display:flex;gap:.6rem;margin-bottom:1.25rem;flex-wrap:wrap">
  <a href="/messaging.php?compose=1" class="btn btn-ghost btn-sm">💬 Message</a>
  <a href="/admin/audit.php?user=<?= $user_edit['id'] ?>" class="btn btn-ghost btn-sm">📋 Audit</a>
  <?php if ($bloque): ?>
  <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="type_form" value="debloquer"><input type="hidden" name="user_id" value="<?= $user_edit['id'] ?>"><button class="btn btn-ghost btn-sm">🔓 Débloquer</button></form>
  <?php endif; ?>
  <?php if ($nb_sessions>0): ?>
  <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="type_form" value="kick_user"><input type="hidden" name="user_id" value="<?= $user_edit['id'] ?>"><button class="btn btn-danger btn-sm" onclick="return confirm('Forcer la déconnexion ?')">⏻ Déconnecter</button></form>
  <?php endif; ?>
  <?php if ($user_edit['id']!==($_SESSION['user_id']??0)): ?>
  <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="type_form" value="toggle_user"><input type="hidden" name="user_id" value="<?= $user_edit['id'] ?>"><button class="btn btn-ghost btn-sm"><?= $user_edit['actif']?'🔴 Désactiver':'🟢 Activer' ?></button></form>
  <?php if (!$user_edit['super_admin'] || ($_SESSION['super_admin']??false)): ?>
  <form method="POST" style="display:inline" onsubmit="return confirm('Supprimer définitivement cet utilisateur ?')"><?= csrf_field() ?><input type="hidden" name="type_form" value="delete_user"><input type="hidden" name="user_id" value="<?= $user_edit['id'] ?>"><button class="btn btn-danger btn-sm">🗑️ Supprimer</button></form>
  <?php endif; ?>
  <?php endif; ?>
</div>

<!-- Onglets fiche -->
<div class="tabs-user">
  <button class="tab-user active" onclick="switchTab('infos',this)">✏️ Infos</button>
  <button class="tab-user" onclick="switchTab('permissions',this)">🔐 Permissions (<?= count($user_perms) ?>)</button>
  <button class="tab-user" onclick="switchTab('securite',this)">🔒 Sécurité</button>
  <button class="tab-user" onclick="switchTab('connexions',this)">🌐 Connexions<?php if($ip_suspect): ?> ⚠️<?php endif; ?></button>
</div>

<!-- TAB INFOS -->
<div id="tab-infos" class="tab-pane">
<div class="user-grid">
  <div class="card">
    <div class="card-title">✏️ Modifier le profil</div>
    <form method="POST">
      <?= csrf_field() ?><input type="hidden" name="type_form" value="save_user"><input type="hidden" name="user_id" value="<?= $user_edit['id'] ?>">
      <div class="form-row">
        <div class="form-group"><label class="form-label">Prénom *</label><input type="text" name="prenom" class="form-control" required value="<?= h($user_edit['prenom']) ?>"></div>
        <div class="form-group"><label class="form-label">Nom *</label><input type="text" name="nom" class="form-control" required value="<?= h($user_edit['nom']) ?>"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Email *</label><input type="email" name="email" class="form-control" required value="<?= h($user_edit['email']) ?>"></div>
        <div class="form-group"><label class="form-label">Login *</label><input type="text" name="login" class="form-control" required value="<?= h($user_edit['login']) ?>" autocomplete="username"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Téléphone</label><input type="text" name="telephone" class="form-control" value="<?= h($user_edit['telephone']??'') ?>"></div>
        <div class="form-group"><label class="form-label">Poste</label><input type="text" name="poste" class="form-control" value="<?= h($user_edit['poste']??'') ?>"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Service</label><input type="text" name="service" class="form-control" value="<?= h($user_edit['service']??'') ?>"></div>
        <div class="form-group"><label class="form-label">Expiration</label><input type="date" name="expire_le" class="form-control" value="<?= $user_edit['expire_le']??'' ?>"></div>
      </div>
      <div class="form-group"><label class="form-label">Note interne</label><textarea name="note_admin" class="form-control" rows="2"><?= h($user_edit['note_admin']??'') ?></textarea></div>
      <div class="form-group"><label class="toggle-sw"><input type="checkbox" name="actif" <?= $user_edit['actif']?'checked':'' ?>><div class="sw-track"></div><div class="sw-thumb"></div> Compte actif</label></div>
      <button type="submit" class="btn btn-primary">💾 Enregistrer</button>
    </form>
  </div>
  <div class="card">
    <div class="card-title">🔐 Double authentification (2FA)</div>
    <p style="font-size:.82rem;color:var(--text2);margin-bottom:1rem">
      Quand activée, l'utilisateur doit saisir un code reçu par email à chaque connexion.
    </p>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="type_form" value="toggle_2fa">
      <input type="hidden" name="user_id" value="<?= $user_edit['id'] ?>">
      <div style="display:flex;align-items:center;gap:1rem;padding:.75rem;background:var(--glass);border:1px solid var(--border);border-radius:10px">
        <label class="toggle-sw" style="margin:0">
          <input type="checkbox" name="twofa_enabled" <?= ($user_edit['twofa_enabled']??0)?'checked':'' ?>
                 onchange="this.form.submit()">
          <div class="sw-track"></div><div class="sw-thumb"></div>
        </label>
        <div>
          <div style="font-weight:600;font-size:.88rem">
            <?= ($user_edit['twofa_enabled']??0) ? '🔐 2FA activée' : '🔓 2FA désactivée' ?>
          </div>
          <div style="font-size:.75rem;color:var(--text3)">
            <?= ($user_edit['twofa_enabled']??0) ? 'Code OTP envoyé par email à chaque connexion' : 'Connexion directe sans code' ?>
          </div>
        </div>
      </div>
    </form>
  </div>
  <div class="card">
    <div class="card-title">🔑 Nouveau mot de passe</div>
    <form method="POST">
      <?= csrf_field() ?><input type="hidden" name="type_form" value="save_user"><input type="hidden" name="user_id" value="<?= $user_edit['id'] ?>">
      <div class="form-group">
        <label class="form-label">Mot de passe (8 min, 1 maj, 1 chiffre)</label>
        <div style="display:flex;gap:8px;align-items:center">
          <input type="password" name="mot_de_passe" class="form-control" minlength="8"
                 autocomplete="new-password" placeholder="Laisser vide = inchangé"
                 id="new-pwd" style="flex:1">
          <button type="button" class="btn btn-ghost btn-sm" onclick="togglePwdVis('new-pwd','eye-edit')" title="Voir">
            <span id="eye-edit">👁️</span>
          </button>
          <button type="button" class="btn btn-ghost btn-sm" onclick="genPwd('new-pwd','pwd-strength')" title="Générer">
            🎲
          </button>
          <button type="button" class="btn btn-ghost btn-sm" onclick="copyPwd('new-pwd')" title="Copier">
            📋
          </button>
        </div>
        <div id="pwd-strength" style="margin-top:.4rem;font-size:.75rem"></div>
      </div>
      <button type="submit" class="btn btn-ghost">💾 Changer</button>
    </form>
    <div style="margin-top:1.25rem;padding-top:1.25rem;border-top:1px solid var(--border)">
      <div class="card-title">📊 Infos connexion</div>
      <?php foreach ([
        ['Dernière connexion',$user_edit['derniere_connexion']?date('d/m/Y H:i',strtotime($user_edit['derniere_connexion'])):'Jamais'],
        ['Dernière IP',$user_edit['derniere_ip']??'—'],
        ['Tentatives échec',$user_edit['tentatives_echec']??0],
      ] as [$lbl,$val]): ?>
      <div style="display:flex;justify-content:space-between;padding:.4rem 0;border-bottom:1px solid rgba(255,255,255,.04);font-size:.82rem">
        <span style="color:var(--text2)"><?= $lbl ?></span>
        <span style="font-family:<?= $lbl==='Dernière IP'?'var(--mono)':'inherit' ?>"><?= h((string)$val) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
</div>

<!-- TAB PERMISSIONS dans fiche -->
<div id="tab-permissions" class="tab-pane" style="display:none">
<div class="card">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem">
    <div class="card-title" style="margin-bottom:0">🔐 Permissions par site</div>
  </div>
  <!-- Formulaire ajout rapide -->
  <form method="POST" style="margin-bottom:1.25rem;padding-bottom:1.25rem;border-bottom:1px solid var(--border)">
    <?= csrf_field() ?><input type="hidden" name="type_form" value="save_permissions"><input type="hidden" name="user_id" value="<?= $user_edit['id'] ?>">
    <div style="display:grid;grid-template-columns:1fr 1fr auto;gap:10px;align-items:end">
      <div class="form-group" style="margin:0"><label class="form-label">Site</label>
        <select name="site_id" class="form-control" required><option value="">— Choisir —</option>
          <?php foreach ($sites as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['org_nom'].' → '.$s['nom']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="margin:0"><label class="form-label">Rôle</label>
        <select name="role" class="form-control" required id="role-select-fiche" onchange="autoPoste(this.value)"><option value="">— Choisir —</option>
          <?php foreach ($roles_valides as $r): $rc2=$roles_config[$r]; ?>
          <option value="<?= $r ?>"><?= $rc2['icon'] ?> <?= $rc2['label'] ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-primary">➕ Assigner</button>
    </div>
  </form>
  <?php if (empty($user_perms)): ?>
  <p style="color:var(--text3);text-align:center;padding:2rem">Aucune permission configurée.</p>
  <?php else: ?>
  <?php foreach ($user_perms as $p): $rc3=$roles_config[$p['role']]??['label'=>$p['role'],'color'=>'#64748b','icon'=>'?']; ?>
  <div class="perm-line">
    <div>
      <div style="font-weight:600;font-size:.875rem">🗺 <?= h($p['site_nom']) ?></div>
      <div style="font-size:.75rem;color:var(--text3)"><?= h($p['org_nom']) ?></div>
    </div>
    <div style="display:flex;gap:.5rem;align-items:center">
      <span class="role-pill" style="background:<?= $rc3['color'] ?>22;color:<?= $rc3['color'] ?>;border:1px solid <?= $rc3['color'] ?>44"><?= $rc3['icon'] ?> <?= $rc3['label'] ?></span>
      <form method="POST"><?= csrf_field() ?><input type="hidden" name="type_form" value="supprimer_permission"><input type="hidden" name="user_id" value="<?= $p['user_id'] ?>"><input type="hidden" name="site_id" value="<?= $p['site_id'] ?>"><button class="btn btn-ghost btn-xs" onclick="return confirm('Supprimer cet accès ?')">🗑️</button></form>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
</div>
</div>

<!-- TAB SECURITE -->
<div id="tab-securite" class="tab-pane" style="display:none">
<div class="user-grid">
  <div class="card">
    <div class="card-title">🔒 Score de sécurité</div>
    <div style="display:flex;align-items:center;gap:1.5rem;margin-bottom:1rem">
      <div class="score-ring" style="width:100px;height:100px">
        <svg width="100" height="100" viewBox="0 0 100 100"><circle cx="50" cy="50" r="42" fill="none" stroke="var(--border2)" stroke-width="8"/><circle cx="50" cy="50" r="42" fill="none" stroke="<?= $sec['color'] ?>" stroke-width="8" stroke-dasharray="<?= round(2*M_PI*42*$sec['score']/100,1) ?> <?= round(2*M_PI*42,1) ?>" stroke-linecap="round"/></svg>
        <div style="font-size:1.6rem;font-weight:800;color:<?= $sec['color'] ?>;position:relative;z-index:1"><?= $sec['score'] ?>/100</div>
      </div>
      <div><?php foreach ($sec['details'] as $d): ?><div class="score-detail"><span><?= $d[0]==='ok'?'✅':($d[0]==='warn'?'⚠️':'❌') ?></span><span style="color:<?= $d[0]==='ok'?'#6ee7b7':($d[0]==='warn'?'#fcd34d':'#fca5a5') ?>;font-size:.82rem"><?= h($d[1]) ?></span></div><?php endforeach; ?></div>
    </div>
    <?php if ($bloque): ?><div class="flash flash-error" style="margin-bottom:1rem">🔒 Bloqué jusqu'au <?= date('d/m/Y H:i',strtotime($user_edit['bloque_jusqu'])) ?></div>
    <form method="POST"><?= csrf_field() ?><input type="hidden" name="type_form" value="debloquer"><input type="hidden" name="user_id" value="<?= $user_edit['id'] ?>"><button class="btn btn-primary btn-sm">🔓 Débloquer</button></form><?php endif; ?>
  </div>
  <div class="card">
    <div class="card-title">👥 Sessions actives (<?= $nb_sessions ?>)</div>
    <?php if (empty($user_sessions)): ?><p style="color:var(--text3);font-size:.875rem">Aucune session active.</p>
    <?php else: ?>
    <?php foreach ($user_sessions as $s): ?>
    <div style="padding:.75rem;background:var(--glass);border:1px solid var(--border);border-radius:.5rem;margin-bottom:.5rem;font-size:.8rem">
      <div style="display:flex;justify-content:space-between"><span class="ip-tag"><?= h($s['ip']??'—') ?></span><span style="color:var(--text3)"><?= date('d/m H:i',strtotime($s['derniere_activite'])) ?></span></div>
      <?php if ($s['site_nom']): ?><div style="color:var(--text2);margin-top:.3rem">🏥 <?= h($s['site_nom']) ?></div><?php endif; ?>
    </div>
    <?php endforeach; ?>
    <form method="POST" style="margin-top:.75rem"><?= csrf_field() ?><input type="hidden" name="type_form" value="kick_user"><input type="hidden" name="user_id" value="<?= $user_edit['id'] ?>"><button class="btn btn-danger btn-sm" onclick="return confirm('Forcer la déconnexion ?')">⏻ Déconnecter</button></form>
    <?php endif; ?>
  </div>
</div>
</div>

<!-- TAB CONNEXIONS -->
<div id="tab-connexions" class="tab-pane" style="display:none">
<div class="card">
  <div class="card-title">🌐 Historique (10 dernières)<?php if ($ip_suspect): ?> <span class="badge badge-warning" style="margin-left:.5rem">⚠️ IPs multiples</span><?php endif; ?></div>
  <?php if (empty($user_hist)): ?><p style="color:var(--text3);text-align:center;padding:2rem;font-size:.875rem">Aucun historique disponible.</p>
  <?php else: ?>
  <?php foreach ($user_hist as $hh): ?>
  <div class="hist-row <?= $hh['succes']?'':'fail' ?>">
    <div class="hist-dot <?= $hh['succes']?'dot-ok':'dot-err' ?>"></div>
    <div style="flex:1"><div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap"><span class="ip-tag <?= !$hh['succes']?'suspicious':'' ?>"><?= h($hh['ip']??'—') ?></span><span style="color:var(--text2);font-size:.75rem"><?= h(parse_ua($hh['user_agent']??'')) ?></span></div></div>
    <span style="color:var(--text3);font-size:.75rem;white-space:nowrap"><?= date('d/m/Y H:i',strtotime($hh['cree_le'])) ?></span>
    <span class="badge <?= $hh['succes']?'badge-ok':'badge-critical' ?>" style="font-size:.7rem"><?= $hh['succes']?'✓ OK':'✗ Échec' ?></span>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
</div>
</div>

<?php endif; // end action edit ?>
<?php endif; // end onglet utilisateurs ?>

<?php if ($onglet === 'permissions'):
// Redirection vers utilisateurs pour compatibilité
echo '<script>window.location.href=(window.location.pathname.includes("/admin/")?"?tab=users&onglet=utilisateurs":"users.php?onglet=utilisateurs");</script>'; exit;
?>
<!-- ════════════════════════════════════════════════════════
     ONGLET PERMISSIONS (redirigé)
═════════════════════════════════════════════════════════ -->
<div class="card" style="margin-bottom:1.5rem">
  <div class="card-title" style="margin-bottom:1rem">➕ Assigner ou modifier un accès</div>
  <form method="POST">
    <?= csrf_field() ?><input type="hidden" name="type_form" value="save_permissions">
    <div class="perm-form-grid">
      <div class="form-group" style="margin:0"><label class="form-label">Utilisateur</label>
        <select name="user_id" class="form-control" required><option value="">— Choisir —</option>
          <?php foreach ($users as $u): if ($u['super_admin']) continue; ?>
          <option value="<?= $u['id'] ?>"><?= h($u['prenom'].' '.$u['nom']) ?> (<?= h($u['login']) ?>)<?= !$u['actif']?' [inactif]':'' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="margin:0"><label class="form-label">Site</label>
        <select name="site_id" class="form-control" required><option value="">— Choisir —</option>
          <?php foreach ($sites as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['org_nom'].' → '.$s['nom']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="margin:0"><label class="form-label">Rôle</label>
        <select name="role" class="form-control" required><option value="">— Choisir —</option>
          <?php foreach ($roles_valides as $r): $rc2=$roles_config[$r]; ?>
          <option value="<?= $r ?>"><?= $rc2['icon'] ?> <?= $rc2['label'] ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-primary" style="white-space:nowrap">💾 Attribuer</button>
    </div>
  </form>
</div>

<div class="card">
  <div class="card-title" style="margin-bottom:1rem">📋 Permissions en place (<?= count($perms_existantes) ?>)</div>
  <?php if (empty($par_user)): ?><p style="color:var(--text3);text-align:center;padding:2rem">Aucune permission configurée.</p>
  <?php else: ?>
  <?php foreach ($par_user as $uid => $data): ?>
  <div class="perm-user-block">
    <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.6rem;font-weight:600">
      👤 <?= h($data['info']['prenom'].' '.$data['info']['nom']) ?>
      <span style="color:var(--text3);font-weight:400;font-size:.8rem">(<?= h($data['info']['login']) ?>)</span>
      <a href="?action=edit&id=<?= $uid ?>&onglet=utilisateurs" class="btn btn-ghost btn-xs" style="margin-left:auto">✏️ Fiche</a>
    </div>
    <?php foreach ($data['perms'] as $p): $rc3=$roles_config[$p['role']]??['label'=>$p['role'],'color'=>'#64748b','icon'=>'?']; ?>
    <div style="display:flex;align-items:center;justify-content:space-between;padding:.65rem 1rem;background:var(--glass);border:1px solid var(--border);border-radius:8px;margin-bottom:.4rem">
      <div>
        <div style="font-size:.875rem;font-weight:600"><?= h($p['site_nom']) ?> <span style="color:var(--text3);font-weight:400;font-size:.78rem">— <?= h($p['org_nom']) ?></span></div>
        <span class="role-pill" style="background:<?= $rc3['color'] ?>22;color:<?= $rc3['color'] ?>;border:1px solid <?= $rc3['color'] ?>44;margin-top:4px"><?= $rc3['icon'] ?> <?= $rc3['label'] ?></span>
      </div>
      <form method="POST"><?= csrf_field() ?><input type="hidden" name="type_form" value="supprimer_permission"><input type="hidden" name="user_id" value="<?= $p['user_id'] ?>"><input type="hidden" name="site_id" value="<?= $p['site_id'] ?>"><button class="btn btn-ghost btn-xs" onclick="return confirm('Supprimer cet accès ?')">🗑️</button></form>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php endif; // end onglet permissions ?>

<!-- ════════════════════════════════════════════════════════
     ACCÈS ONGLETS ADMINISTRATION
═════════════════════════════════════════════════════════ -->
<?php
// ── Onglets hub admin — SOURCE UNIQUE (config/admin_tabs.php) ──
$__at = admin_tabs_definis();
$__gl = admin_tabs_groupes();
$admin_tabs_meta = [];
foreach ($__at as $__k => $__t) $admin_tabs_meta[$__k] = ['icon'=>$__t['icon'],'label'=>$__t['label'],'section'=>($__gl[$__t['group']] ?? $__t['group'])];
$default_tab_access = array_map(fn($t) => $t['defaut'], $__at);
$tab_access_config = $default_tab_access;
try {
  $row = $pdo->query("SELECT valeur FROM parametres WHERE cle='admin_tab_access'")->fetch();
  if ($row) $tab_access_config = json_decode($row['valeur'], true) ?: $default_tab_access;
} catch(Exception $e) {}

// Sauvegarder
if (($_POST['action'] ?? '') === 'save_tab_access' && ($is_super ?? false)) {
  $new = [];
  foreach (array_keys($default_tab_access) as $t) {
    $new[$t] = $_POST['tab_access'][$t] ?? [];
    if (!in_array('super_admin', $new[$t])) $new[$t][] = 'super_admin';
  }
  $val = json_encode($new);
  $pdo->prepare("INSERT INTO parametres (cle,valeur) VALUES ('admin_tab_access',?) ON DUPLICATE KEY UPDATE valeur=?")
      ->execute([$val,$val]);
  $tab_access_config = $new;
  flashSuccess('Accès onglets mis à jour.');
}
?>

<?php if (in_array($onglet, ['roles','site_permissions','admin_access'])):
  $vue = ($onglet === 'admin_access') ? 'admin' : ($_GET['vue'] ?? 'modules');
  if (!$is_super && $vue === 'admin') $vue = 'modules';
?>
<style>
.ra-seg{display:inline-flex;background:var(--bg3);border-radius:11px;padding:4px;margin-bottom:18px}
.ra-seg a{display:flex;align-items:center;gap:7px;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:700;color:var(--text2);text-decoration:none}
.ra-seg a.on{background:var(--bg);color:var(--text);box-shadow:0 2px 6px rgba(0,0,0,.06)}
</style>
<div class="ra-seg">
  <a href="?onglet=roles&vue=modules" class="<?= $vue!=='admin'?'on':'' ?>">📦 Modules par site</a>
  <?php if ($is_super): ?><a href="?onglet=roles&vue=admin" class="<?= $vue==='admin'?'on':'' ?>">⚙️ Onglets admin</a><?php endif; ?>
</div>

<?php if ($vue === 'admin' && $is_super): ?>
<div class="sp-hint" style="display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--text2);background:rgba(var(--primary-rgb),.08);border:1px solid rgba(var(--primary-rgb),.18);padding:9px 14px;border-radius:11px;margin-bottom:16px">
  <i class="ti ti-shield-lock"></i> <span><strong>Super Admin</strong> a toujours accès à tout. Définissez ce que voient Administrateur et Gestionnaire — clic sur l'en-tête d'un rôle pour tout (dé)cocher.</span>
</div>
<style>
.at-card{background:var(--bg);border-radius:18px;box-shadow:0 12px 30px rgba(30,40,80,.10);overflow:hidden}
.at-matrix{width:100%;border-collapse:collapse;table-layout:fixed}
.at-matrix thead th{background:var(--bg2);padding:10px 8px;font-size:11px;font-weight:800;color:var(--text2);border-bottom:1px solid var(--border)}
.at-matrix thead th:first-child{text-align:left;padding-left:20px;width:46%;border-top-left-radius:18px}
.at-matrix thead th:last-child{border-top-right-radius:18px}
.at-rh{display:flex;flex-direction:column;align-items:center;gap:3px;cursor:pointer;padding:4px 2px;border-radius:9px;transition:background .12s}
.at-rh.lock{cursor:default}
.at-rh:not(.lock):hover{background:var(--bg3)}
.at-rh .ri{width:28px;height:28px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:15px}
.at-rh .rl{font-size:10.5px;font-weight:800;display:flex;align-items:center;gap:3px}
.at-rh .rl .ti{font-size:11px;color:var(--text3)}
.at-sec td{background:linear-gradient(180deg,var(--bg2),var(--bg));padding:7px 20px;font-size:9.5px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:var(--text3);border-bottom:1px solid var(--border)}
.at-mod{border-bottom:1px solid var(--border)}
.at-mod:hover{background:var(--bg2)}
.at-mod td{padding:9px 8px;text-align:center}
.at-mname{text-align:left!important;padding-left:20px!important;font-weight:600;font-size:13.5px;display:flex;align-items:center;gap:9px}
.at-mname .mi{font-size:15px;width:20px;text-align:center}
.at-pill{display:inline-flex;align-items:center;justify-content:center;width:88px;padding:6px 0;border-radius:20px;font-size:11px;font-weight:800;cursor:pointer;border:1.5px solid transparent;transition:all .12s;user-select:none}
.at-pill.on{background:rgba(22,163,74,.12);color:#16a34a;border-color:rgba(22,163,74,.28)}
.at-pill.off{background:rgba(239,68,68,.08);color:#ef4444;border-color:rgba(239,68,68,.2)}
.at-pill.lock{background:rgba(245,158,11,.12);color:#d97706;border-color:rgba(245,158,11,.3);cursor:default}
.at-pill:not(.lock):hover{transform:scale(1.05)}
.at-pill input{display:none}
.at-save{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 20px;border-top:1px solid var(--border);background:var(--bg2);border-bottom-left-radius:18px;border-bottom-right-radius:18px}
.at-legend{font-size:11.5px;color:var(--text2)}
</style>

<form method="POST" id="form-tab-access" action="?tab=users&onglet=roles&vue=admin">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_tab_access">
  <div class="at-card">
    <table class="at-matrix">
      <thead><tr>
        <th>Onglet du hub</th>
        <th><div class="at-rh lock"><span class="ri" style="background:#EF444422">⭐</span><span class="rl" style="color:#EF4444">Super Admin</span></div></th>
        <th><div class="at-rh" onclick="atCol('admin')"><span class="ri" style="background:#F59E0B22">👑</span><span class="rl" style="color:#F59E0B">Admin <i class="ti ti-chevron-down"></i></span></div></th>
        <th><div class="at-rh" onclick="atCol('gestionnaire')"><span class="ri" style="background:#3B82F622">🗂️</span><span class="rl" style="color:#3B82F6">Gestionnaire <i class="ti ti-chevron-down"></i></span></div></th>
      </tr></thead>
      <tbody>
      <?php
      $prev_section = null;
      foreach ($admin_tabs_meta as $t => $meta):
        $roles   = $tab_access_config[$t] ?? ['super_admin'];
        $section = $meta['section'] ?? '';
        if ($section !== $prev_section): $prev_section = $section; ?>
        <tr class="at-sec"><td colspan="4"><?= h($section) ?></td></tr>
      <?php endif; ?>
        <tr class="at-mod">
          <td class="at-mname"><span class="mi"><?= $meta['icon'] ?></span><?= h($meta['label']) ?></td>
          <td><span class="at-pill lock"><input type="checkbox" name="tab_access[<?= $t ?>][]" value="super_admin" checked>✓ Toujours</span></td>
          <?php foreach (['admin','gestionnaire'] as $rk): $on = in_array($rk,$roles); ?>
          <td><span class="at-pill <?= $on?'on':'off' ?>" data-role="<?= $rk ?>" onclick="atTog(this)"><input type="checkbox" name="tab_access[<?= $t ?>][]" value="<?= $rk ?>" <?= $on?'checked':'' ?>><span class="lbl"><?= $on?'✓ Accès':'✕ Non' ?></span></span></td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="at-save">
      <div class="at-legend">Super Admin verrouillé sur « Toujours ». Un onglet retiré disparaît du menu pour ce rôle.</div>
      <button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy"></i> Enregistrer les accès</button>
    </div>
  </div>
</form>

<script>
function atSet(p,on){p.classList.toggle('on',on);p.classList.toggle('off',!on);p.querySelector('input').checked=on;p.querySelector('.lbl').textContent=on?'✓ Accès':'✕ Non';}
function atTog(p){atSet(p,!p.querySelector('input').checked);}
function atCol(role){var ps=document.querySelectorAll('.at-pill[data-role="'+role+'"]');var anyOff=false;ps.forEach(function(p){if(!p.querySelector('input').checked)anyOff=true;});ps.forEach(function(p){atSet(p,anyOff);});}
</script>
<?php else: ?>
<?php if (!defined('ADMIN_HUB')) define('ADMIN_HUB', true); include __DIR__ . '/site_permissions.php'; ?>
<?php endif; ?>
<?php endif; // end onglet Rôles & accès ?>

<?php if ($onglet === 'affichage'):
  $roles_ed_aff = roles_editables();
  $MODS_AFF = modules_permissions();
  $acc_aff = [];
  foreach ($pdo->query("SELECT role,module,niveau FROM role_permissions")->fetchAll(PDO::FETCH_ASSOC) as $r)
      $acc_aff[$r['role']][$r['module']] = (int)$r['niveau'];
  $aff_aff = [];
  try {
      foreach ($pdo->query("SELECT role,module,visible_pc,visible_mobile FROM affichage_modules")->fetchAll(PDO::FETCH_ASSOC) as $r)
          $aff_aff[$r['role']][$r['module']] = ['pc'=>(int)$r['visible_pc'],'mobile'=>(int)$r['visible_mobile']];
  } catch (Throwable $e) {}
?>
<style>
.rc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px}
.rc-card{display:flex;align-items:center;gap:14px;background:var(--bg);border:1px solid var(--border);border-radius:16px;padding:16px 18px;cursor:pointer;transition:.15s}
.rc-card:hover{transform:translateY(-2px);border-color:var(--primary)}
.rc-ic{width:46px;height:46px;border-radius:13px;display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0}
.rc-name{font-weight:800;font-size:1.02rem}.rc-sub{font-size:.78rem;color:var(--text3);margin-top:2px}
.rc-modal{position:fixed;inset:0;background:rgba(8,12,24,.6);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);display:none;align-items:center;justify-content:center;z-index:9999;padding:16px}
.rc-modal.open{display:flex}
.rc-box{background:var(--bg2,#111827);border:1px solid var(--border);border-radius:18px;width:100%;max-width:560px;max-height:86vh;display:flex;flex-direction:column}
.rc-head{display:flex;align-items:center;gap:10px;font-size:1.1rem;font-weight:800;padding:18px 20px;border-bottom:1px solid var(--border)}
.rc-head button{margin-left:auto;background:none;border:none;color:var(--text2);font-size:1.2rem;cursor:pointer}
.rc-tblwrap{overflow-y:auto;padding:6px 8px}.rc-tbl{width:100%;border-collapse:collapse}
.rc-tbl th{font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text3);padding:8px 10px;text-align:center}
.rc-tbl th:first-child{text-align:left}
.rc-tbl td{padding:9px 10px;border-top:1px solid var(--border);font-size:.9rem}
.rc-tbl td:first-child{font-weight:600}
.rc-tbl input[type=checkbox]{width:20px;height:20px;cursor:pointer;accent-color:var(--primary)}
.rc-noacc{opacity:.42}
.rc-foot{display:flex;align-items:center;gap:12px;padding:16px 20px;border-top:1px solid var(--border)}
.rc-legend{font-size:.76rem;color:var(--text3);flex:1}
</style>
<div class="card" style="margin-bottom:18px;font-size:.9rem;color:var(--text2);line-height:1.5">
  Cliquez un rôle pour régler <b>où ses modules s'affichent</b> : 💻 sur ordinateur, 📱 sur mobile, ou les deux.
  L'<b>accès</b> se règle dans « Rôles &amp; accès » ; ici on gère seulement l'<b>affichage</b>. Un module sans accès est grisé.
</div>
<div class="rc-grid">
  <?php foreach ($roles_ed_aff as $rk => $rc): ?>
  <div class="rc-card" onclick="rcOpen('<?= $rk ?>')">
    <div class="rc-ic" style="background:<?= $rc['color'] ?>22;color:<?= $rc['color'] ?>"><?= $rc['icon'] ?></div>
    <div><div class="rc-name"><?= h($rc['label']) ?></div><div class="rc-sub">Régler l'affichage →</div></div>
  </div>
  <?php endforeach; ?>
</div>
<?php foreach ($roles_ed_aff as $rk => $rc): ?>
<div class="rc-modal" id="rcm-<?= $rk ?>">
  <div class="rc-box">
    <div class="rc-head"><span><?= $rc['icon'] ?></span> <?= h($rc['label']) ?>
      <button type="button" onclick="rcClose('<?= $rk ?>')">✕</button></div>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="type_form" value="save_affichage">
      <input type="hidden" name="role" value="<?= $rk ?>">
      <div class="rc-tblwrap">
        <table class="rc-tbl">
          <thead><tr><th>Module</th><th>Accès</th><th>💻 PC</th><th>📱 Mobile</th></tr></thead>
          <tbody>
          <?php foreach ($MODS_AFF as $mk => $mc):
            $hasAcc = ($acc_aff[$rk][$mk] ?? 0) >= 1;
            $pcv  = $aff_aff[$rk][$mk]['pc']     ?? 1;
            $mobv = $aff_aff[$rk][$mk]['mobile'] ?? 1; ?>
            <tr class="<?= $hasAcc ? '' : 'rc-noacc' ?>">
              <td><?= $mc['icon'] ?> <?= h($mc['label']) ?></td>
              <td style="text-align:center"><?= $hasAcc ? '<span style="color:#16a34a">✓</span>' : '<span style="color:#94a3b8">✕</span>' ?></td>
              <td style="text-align:center"><input type="checkbox" name="pc[<?= $mk ?>]"     <?= ($pcv  && $hasAcc) ? 'checked' : '' ?> <?= $hasAcc ? '' : 'disabled' ?>></td>
              <td style="text-align:center"><input type="checkbox" name="mobile[<?= $mk ?>]" <?= ($mobv && $hasAcc) ? 'checked' : '' ?> <?= $hasAcc ? '' : 'disabled' ?>></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="rc-foot">
        <span class="rc-legend">L'accès se règle dans « Rôles &amp; accès ». Ici : où le module apparaît.</span>
        <button type="button" class="btn btn-ghost" onclick="rcClose('<?= $rk ?>')">Annuler</button>
        <button type="submit" class="btn btn-primary">💾 Enregistrer</button>
      </div>
    </form>
  </div>
</div>
<?php endforeach; ?>
<script>
function rcOpen(r){var m=document.getElementById('rcm-'+r);if(m)m.classList.add('open');}
function rcClose(r){var m=document.getElementById('rcm-'+r);if(m)m.classList.remove('open');}
document.querySelectorAll('.rc-modal').forEach(function(m){m.addEventListener('click',function(e){if(e.target===m)m.classList.remove('open');});});
</script>
<?php endif; // end onglet Affichage ?>

<?php if ($onglet === 'modules'):
  $__mod = __DIR__ . '/modules.php';
  if (is_file($__mod)) { include $__mod; }
  else { echo '<div class="card" style="padding:2rem;text-align:center;color:var(--text3)">Module introuvable.</div>'; }
endif; // end onglet Modules ?>
<?php if ($onglet === 'acces'):
  $acc_roles = roles_editables();
  $acc_mods  = function_exists('modules_permissions') ? modules_permissions() : [];
  // Sélecteur de site (super_admin : tous ; sinon Global uniquement)
  $acc_sites = [];
  if ($_SESSION['super_admin'] ?? false) {
    try { $acc_sites = $pdo->query("SELECT s.id,s.nom,o.nom AS org_nom FROM sites s JOIN organisations o ON o.id=s.organisation_id WHERE s.actif=1 ORDER BY o.nom,s.nom")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
  }
  $acc_site = isset($_GET['acc_site']) ? (int)$_GET['acc_site'] : 0;
  if ($acc_site > 0) { $__ok=false; foreach ($acc_sites as $__s) if ((int)$__s['id']===$acc_site) $__ok=true; if (!$__ok) $acc_site=0; }
  // Perms Étage 1 : global, puis surcharges du site sélectionné
  $acc_perms = [];
  try { foreach ($pdo->query("SELECT role,module,niveau FROM role_permissions") as $r) $acc_perms[$r['role']][$r['module']] = (int)$r['niveau']; } catch (Throwable $e) {}
  if ($acc_site > 0) {
    try { $__st=$pdo->prepare("SELECT role,module,niveau FROM site_role_permissions WHERE site_id=?"); $__st->execute([$acc_site]);
          foreach ($__st as $r) $acc_perms[$r['role']][$r['module']] = (int)$r['niveau']; } catch (Throwable $e) {}
  }
  $acc_by_sec = [];
  foreach ($acc_mods as $mk => $mc) {
    if (function_exists('module_perm_visible') && !module_perm_visible($mk)) continue;
    $acc_by_sec[$mc['section'] ?? 'Modules'][$mk] = $mc;
  }
  $acc_actions = ['creer'=>'➕ Créer', 'modifier'=>'✏️ Modifier', 'ajuster'=>'± Ajuster qté', 'renouveler'=>'♻️ Renouveler', 'dupliquer'=>'📋 Dupliquer', 'archiver'=>'📦 Archiver', 'supprimer'=>'🗑️ Supprimer', 'historique'=>'🕓 Historique', 'dotation'=>'🎯 Dotation'];
?>
<style>
.acc-hint{display:flex;align-items:center;gap:8px;background:rgba(45,212,191,.08);border:1px solid rgba(45,212,191,.25);border-radius:10px;padding:10px 14px;font-size:12.5px;color:var(--text2);margin:18px 0}
.acc-stage{display:flex;align-items:center;gap:10px;margin:26px 0 14px;font-size:15px}
.acc-num{width:24px;height:24px;border-radius:7px;background:var(--primary,#2dd4bf);color:#04231f;font-weight:800;display:flex;align-items:center;justify-content:center;flex:none}
.acc-grp{background:var(--card,#fff);border:1px solid var(--border);border-radius:12px;margin-bottom:14px;overflow:hidden}
.acc-grp-h{padding:11px 16px;font-weight:700;font-size:13.5px;border-bottom:1px solid var(--border);background:rgba(0,0,0,.02)}
.acc-scroll{overflow-x:auto}
.acc-tbl{width:100%;border-collapse:collapse;min-width:680px;table-layout:fixed}
.acc-tbl th{font-size:10.5px;color:var(--text3);padding:10px 5px;text-align:center;text-transform:uppercase;letter-spacing:.3px;border-bottom:1px solid var(--border)}
.acc-tbl th:first-child{text-align:left;padding-left:16px;width:230px}
.acc-tbl td{padding:9px 5px;text-align:center;border-bottom:1px solid var(--border);font-size:13px}
.acc-tbl tr:last-child td{border-bottom:none}
.acc-tbl td:first-child{text-align:left;padding-left:16px;font-weight:600;width:230px}
.acc-ic{display:block;font-size:15px;margin-bottom:1px}
.acc-tbl input[type=checkbox]{width:19px;height:19px;cursor:pointer;accent-color:var(--primary,#2dd4bf)}
</style>
<div class="acc-hint">🛡️ Le Super Admin a toujours accès à tout. Coche qui peut voir/faire quoi. L'étage 2 se grise si le module est décoché à l'étage 1.</div>
<form method="POST" action="<?= defined('ADMIN_HUB') ? '?tab=users&onglet=acces' : 'users.php?onglet=acces' ?>">
  <?= csrfField() ?>
  <input type="hidden" name="type_form" value="save_acces">
  <input type="hidden" name="acc_site" value="<?= $acc_site ?>">
  <?php if (!empty($acc_sites)): ?>
  <div style="display:flex;align-items:center;gap:10px;margin:16px 0;flex-wrap:wrap">
    <span style="font-weight:700;font-size:13px">🌍 Périmètre (Étage 1) :</span>
    <select onchange="location.href='<?= defined('ADMIN_HUB')?'?tab=users&onglet=acces':'users.php?onglet=acces' ?>&acc_site='+this.value" style="padding:8px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg3,#fff);color:var(--text);font-size:13px">
      <option value="0" <?= $acc_site===0?'selected':'' ?>>Global (tous les sites)</option>
      <?php foreach ($acc_sites as $__s): ?>
        <option value="<?= (int)$__s['id'] ?>" <?= $acc_site===(int)$__s['id']?'selected':'' ?>><?= h($__s['org_nom'].' — '.$__s['nom']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php if ($acc_site>0): ?><span style="font-size:12px;color:#d97706">⚠️ Surcharges de site — l'Étage 1 s'applique à ce site (Étages 2 & 3 restent globaux)</span><?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="acc-stage"><span class="acc-num">1</span> <b>Accès aux modules</b> <span style="color:var(--text3);font-size:12px">— ce rôle voit-il le module ?</span></div>
  <?php foreach ($acc_by_sec as $sec => $mods): ?>
  <div class="acc-grp">
    <div class="acc-grp-h"><?= h($sec) ?></div>
    <div class="acc-scroll"><table class="acc-tbl">
      <thead><tr><th>Module</th><?php foreach ($acc_roles as $rk=>$rc): ?><th><span class="acc-ic"><?= $rc['icon'] ?></span><?= h($rc['label']) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php foreach ($mods as $mk=>$mc): ?>
        <tr><td><?= $mc['icon'] ?? '' ?> <?= h($mc['label'] ?? $mk) ?></td>
        <?php foreach ($acc_roles as $rk=>$rc): $on=($acc_perms[$rk][$mk]??0)>=1; ?>
          <td><input type="checkbox" name="modacc[<?= $rk ?>][<?= $mk ?>]" <?= $on?'checked':'' ?> onchange="accGrey('<?= $rk ?>','<?= $mk ?>')"></td>
        <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <?php endforeach; ?>

  <div class="acc-stage"><span class="acc-num">2</span> <b>Droits détaillés</b> <span style="color:var(--text3);font-size:12px">— actions fines (grisées si le module est décoché)</span></div>
  <div class="acc-grp">
    <div class="acc-grp-h">💊 Médicaments</div>
    <div class="acc-scroll"><table class="acc-tbl">
      <thead><tr><th>Action</th><?php foreach ($acc_roles as $rk=>$rc): ?><th><span class="acc-ic"><?= $rc['icon'] ?></span><?= h($rc['label']) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php foreach ($acc_actions as $act=>$lbl): ?>
        <tr><td><?= $lbl ?></td>
        <?php foreach ($acc_roles as $rk=>$rc): $on=($acc_perms[$rk]['action_'.$act]??0)>=1; ?>
          <td data-cell="<?= $rk ?>-medicaments"><input type="checkbox" name="actperm[<?= $rk ?>][<?= $act ?>]" <?= $on?'checked':'' ?>></td>
        <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>

  <?php if ($_SESSION['super_admin'] ?? false):
    $adm_tabs = function_exists('admin_tabs_definis') ? admin_tabs_definis() : [];
    $adm_acc = [];
    try { $r = $pdo->query("SELECT valeur FROM parametres WHERE cle='admin_tab_access'")->fetch(PDO::FETCH_ASSOC); if ($r) $adm_acc = json_decode($r['valeur'], true) ?: []; } catch (Throwable $e) {}
    foreach ($adm_tabs as $__k=>$__t) if (!isset($adm_acc[$__k])) $adm_acc[$__k] = $__t['defaut'];
  ?>
  <div class="acc-stage"><span class="acc-num">3</span> <b>Administration</b> <span style="color:var(--text3);font-size:12px">— accès aux onglets admin (toi seul peux régler ça)</span></div>
  <div class="acc-grp">
    <div class="acc-grp-h">🛠️ Onglets d'administration</div>
    <div class="acc-scroll"><table class="acc-tbl">
      <thead><tr><th>Onglet</th><th><span class="acc-ic">👑</span>Super Admin</th><th><span class="acc-ic">🛡️</span>Admin</th><th><span class="acc-ic">⚙️</span>Gestion.</th></tr></thead>
      <tbody>
      <?php foreach ($adm_tabs as $__t=>$__m): $__r = $adm_acc[$__t] ?? ['super_admin']; ?>
        <tr><td><?= $__m['icon'] ?> <?= h($__m['label']) ?></td>
          <td><input type="checkbox" checked disabled title="Super admin toujours"></td>
          <td><input type="checkbox" name="admintab[<?= $__t ?>][]" value="admin" <?= in_array('admin',$__r)?'checked':'' ?>></td>
          <td><input type="checkbox" name="admintab[<?= $__t ?>][]" value="gestionnaire" <?= in_array('gestionnaire',$__r)?'checked':'' ?>></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <?php endif; ?>
  <button type="submit" class="btn btn-primary" style="margin-top:16px">💾 Enregistrer les accès</button>
</form>
<script>
function accGrey(role, mod){
  if (mod !== 'medicaments') return;
  var cb = document.querySelector('input[name="modacc['+role+'][medicaments]"]');
  var on = cb ? cb.checked : true;
  document.querySelectorAll('td[data-cell="'+role+'-medicaments"]').forEach(function(td){
    td.style.opacity = on ? '' : '.25';
    var i = td.querySelector('input'); if (i) i.disabled = !on;
  });
}
document.addEventListener('DOMContentLoaded', function(){
  <?php foreach ($acc_roles as $rk=>$rc): ?>accGrey('<?= $rk ?>','medicaments');<?php endforeach; ?>
});
</script>
<?php endif; // end onglet Accès ?>


<script>
// Onglets fiche utilisateur
function switchTab(name, el) {
  document.querySelectorAll('.tab-pane').forEach(t => t.style.display='none');
  document.querySelectorAll('.tab-user').forEach(t => t.classList.remove('active'));
  document.getElementById('tab-'+name).style.display = 'block';
  el.classList.add('active');
}

// Poste auto selon rôle
const postesParRole = <?= json_encode(array_map(fn($r)=>$r['poste'], $roles_config)) ?>;
function autoPoste(role) {
  const posteField = document.getElementById('field-poste');
  if (posteField && postesParRole[role]) {
    posteField.value = postesParRole[role];
    posteField.style.borderColor = '#10b981';
    setTimeout(()=>posteField.style.borderColor='',2000);
  }
}

// Force du mot de passe
const pwdInput = document.getElementById('new-pwd');
if (pwdInput) {
  pwdInput.addEventListener('input', function() {
    const v=this.value; let s=0;
    if(v.length>=8)s++;if(/[A-Z]/.test(v))s++;if(/[0-9]/.test(v))s++;if(/[^A-Za-z0-9]/.test(v))s++;
    const lb=['','⚠️ Faible','🟡 Moyen','🟢 Fort','✅ Très fort'];
    const cl=['','#ef4444','#f59e0b','#10b981','#10b981'];
    document.getElementById('pwd-strength').innerHTML = v?`<span style="color:${cl[s]}">${lb[s]}</span>`:'';
  });
}

// ── Générateur de mot de passe ─────────────────────────────
function genPwd(inputId, strengthId) {
  const charset = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%&*';
  const specials = '!@#$%&*';
  const upper    = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
  const digits   = '23456789';
  const lower    = 'abcdefghijkmnopqrstuvwxyz';
  let pwd = '';
  // Garantir au moins 1 de chaque catégorie requise
  pwd += upper[Math.floor(Math.random()*upper.length)];
  pwd += digits[Math.floor(Math.random()*digits.length)];
  pwd += specials[Math.floor(Math.random()*specials.length)];
  // Compléter jusqu'à 14 caractères
  for (let i=3; i<14; i++) pwd += charset[Math.floor(Math.random()*charset.length)];
  // Mélanger
  pwd = pwd.split('').sort(()=>Math.random()-.5).join('');
  const el = document.getElementById(inputId);
  if (el) {
    el.value = pwd;
    el.type  = 'text'; // Afficher pour que l'admin voie le mdp généré
    evalPwdStrength(pwd, strengthId);
    // Copier dans le presse-papier
    navigator.clipboard?.writeText(pwd).then(()=>{
      showToast?.('🎲 Mot de passe généré et copié !', 'success');
    }).catch(()=>{});
  }
}

function togglePwdVis(inputId, eyeId) {
  const el  = document.getElementById(inputId);
  const eye = document.getElementById(eyeId);
  if (!el) return;
  el.type = el.type === 'password' ? 'text' : 'password';
  if (eye) eye.textContent = el.type === 'password' ? '👁️' : '🙈';
}

function copyPwd(inputId) {
  const el = document.getElementById(inputId);
  if (!el || !el.value) return;
  navigator.clipboard?.writeText(el.value).then(()=>{
    showToast?.('📋 Mot de passe copié !', 'success');
  }).catch(()=>{
    // Fallback
    el.select(); document.execCommand('copy');
  });
}

function evalPwdStrength(pwd, strengthId) {
  const el = document.getElementById(strengthId);
  if (!el) return;
  let score = 0;
  if (pwd.length >= 8)  score++;
  if (pwd.length >= 12) score++;
  if (/[A-Z]/.test(pwd)) score++;
  if (/[0-9]/.test(pwd)) score++;
  if (/[^A-Za-z0-9]/.test(pwd)) score++;
  const levels = [
    {label:'Très faible', color:'#ef4444'},
    {label:'Faible',      color:'#f97316'},
    {label:'Moyen',       color:'#eab308'},
    {label:'Bon',         color:'#22c55e'},
    {label:'Fort',        color:'#10b981'},
    {label:'Excellent',   color:'#6366f1'},
  ];
  const lv = levels[Math.min(score, 5)];
  el.innerHTML = `<span style="color:${lv.color};font-weight:600">${lv.label}</span>
    <span style="display:inline-block;margin-left:8px;width:${score*20}%;max-width:100%;height:3px;background:${lv.color};border-radius:2px;vertical-align:middle"></span>`;
}

// Évaluation temps réel sur les champs mdp existants
document.querySelectorAll('#new-pwd').forEach(el => {
  el.addEventListener('input', () => evalPwdStrength(el.value, 'pwd-strength'));
});
</script>



<?php if (!defined('ADMIN_HUB')) require_once '../includes/footer.php'; ?>

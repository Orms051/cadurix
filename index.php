<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.

// ============================================================
//  Cadurix — index.php  |  Landing page + Login
// ============================================================
session_start();
if (isset($_SESSION['user_id'])) {
    header('Location: https://' . $_SERVER['HTTP_HOST'] . '/dashboard.php');
    exit;
}

// ============================================================
//  Fonctions anti brute-force (auth.php non inclus ici — page publique)
// ============================================================
// Journalise CHAQUE tentative (succès/échec) — indispensable pour que l'anti-brute-force fonctionne
function journaliser_tentative(PDO $pdo, ?int $user_id, bool $succes): void {
    try {
        $pdo->prepare("INSERT INTO connexions_historique (user_id, ip, user_agent, succes)
                       VALUES (?, ?, ?, ?)")
            ->execute([
                $user_id,
                $_SERVER['REMOTE_ADDR'] ?? '',
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                $succes ? 1 : 0,
            ]);
    } catch (Throwable $e) {}
}

$error = '';
// Message déconnexion automatique
if (isset($_GET['timeout']) && $_GET['timeout'] == '1') {
    $error = "⏱️ Vous avez été déconnecté automatiquement après une période d'inactivité.";
}
if (isset($_GET['twofa']) && $_GET['twofa'] === 'locked') {
    $error = "Trop de tentatives de code. Reconnectez-vous pour recevoir un nouveau code.";
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once 'config/db.php';
    require_once __DIR__ . '/includes/mailer.php';
    require_once __DIR__ . '/config/connexion.php';
    require_once __DIR__ . '/includes/security.php';
    // Anti-bot honeypot : le champ website doit être vide
    if (!empty($_POST['website'])) {
        sleep(2); // Ralentir les bots
        $error = 'Identifiants incorrects.';
        goto end_login;
    }
    security_middleware($pdo); // Sous-système sécurité : IP bannie + lockdown + anti-injection SQL
    $login    = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';
    // Adapte selon ta logique d'auth existante
    $stmt = $pdo->prepare("SELECT * FROM utilisateurs WHERE (login = ? OR email = ?) AND actif = 1 LIMIT 1");
    $stmt->execute([$login, $login]);
    $user = $stmt->fetch();
    // Anti timing-attack : toujours appeler password_verify même si user inexistant
    $_dummy_hash = '$2y$12$invalide.hash.pour.timing.egal.AAAAAAAAAAAAAAAAAAAAAA';
    $_hash_check = $user ? $user['mot_de_passe'] : $_dummy_hash;
    $_pwd_ok     = password_verify($password, $_hash_check);
    if ($user && $_pwd_ok) {
        // Régénérer l'ID de session pour éviter la fixation de session
        session_regenerate_id(true);
        // Vérifier si connexion depuis un nouvel appareil
        try {
            $ua_actuel = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $ip_actuel = $_SERVER['REMOTE_ADDR'] ?? '';
            $stmt_hist = $pdo->prepare("
                SELECT COUNT(*) FROM connexions_historique 
                WHERE user_id=? AND user_agent=? AND succes=1
                LIMIT 1
            ");
            $stmt_hist->execute([$user['id'], $ua_actuel]);
            $is_new_device = (int)$stmt_hist->fetchColumn() === 0;

            if ($is_new_device && $user['email']) {
                // Envoyer email d'alerte (objet en UTF-8, pas d'entités HTML)
                $subject = "🔒 Nouvelle connexion à votre compte Cadurix";
                $E = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
                $ua_court = mb_substr((string)$ua_actuel, 0, 90);
                $rowTd = 'style="padding:10px 12px;border-bottom:1px solid #eef2f7;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#1e293b"';
                $rowLbl = 'style="padding:10px 12px;border-bottom:1px solid #eef2f7;font-family:Arial,Helvetica,sans-serif;font-size:12.5px;color:#64748b;width:120px"';
                $html = '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><title>Nouvelle connexion</title></head>
<body style="margin:0;padding:0;background:#eef2f9;-webkit-text-size-adjust:100%">
<div style="display:none;max-height:0;overflow:hidden;opacity:0">Nouvelle connexion détectée sur votre compte Cadurix — ' . $E(date('d/m/Y à H:i')) . '</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef2f9"><tr><td align="center" style="padding:26px 12px">
  <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="width:560px;max-width:560px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e6ecf5">
    <tr><td style="background:#4f46e5;background:linear-gradient(135deg,#3b82f6,#7c4dff);padding:24px 26px;font-family:Arial,Helvetica,sans-serif" bgcolor="#4f46e5">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
        <td width="46" valign="middle"><div style="width:44px;height:44px;background:rgba(255,255,255,.18);border-radius:12px;text-align:center;line-height:44px;font-size:22px">🔒</div></td>
        <td valign="middle" style="padding-left:12px"><div style="color:#fff;font-size:18px;font-weight:800;line-height:1.15">Nouvelle connexion</div><div style="color:#e6ecff;font-size:12.5px;margin-top:2px">Sécurité de votre compte Cadurix</div></td>
      </tr></table>
    </td></tr>
    <tr><td style="padding:22px 26px 6px 26px;font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#1e293b;line-height:1.6">
      Bonjour <strong>' . $E($user['prenom']) . '</strong>,<br>
      Une connexion à votre compte a été détectée depuis un <strong>nouvel appareil</strong>.
    </td></tr>
    <tr><td style="padding:8px 26px 4px 26px">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #eef2f7;border-radius:10px;border-collapse:separate;overflow:hidden">
        <tr><td ' . $rowLbl . '>📅 Date</td><td ' . $rowTd . '><strong>' . $E(date('d/m/Y H:i')) . '</strong></td></tr>
        <tr><td ' . $rowLbl . '>🌐 Adresse IP</td><td ' . $rowTd . '><strong>' . $E($ip_actuel) . '</strong></td></tr>
        <tr><td style="padding:10px 12px;font-family:Arial,Helvetica,sans-serif;font-size:12.5px;color:#64748b;width:120px">💻 Appareil</td><td style="padding:10px 12px;font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#475569">' . $E($ua_court) . '</td></tr>
      </table>
    </td></tr>
    <tr><td style="padding:14px 26px 4px 26px">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td style="background:#fef2f2;border-left:3px solid #ef4444;border-radius:0 8px 8px 0;padding:11px 14px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#991b1b">
        <strong>Ce n\'est pas vous ?</strong> Changez votre mot de passe immédiatement et prévenez l\'administrateur.
      </td></tr></table>
    </td></tr>
    <tr><td align="center" style="padding:18px 26px 24px 26px">
      <table role="presentation" cellpadding="0" cellspacing="0"><tr><td align="center" bgcolor="#3b82f6" style="border-radius:10px">
        <a href="https://cadurix.fr/" style="display:inline-block;padding:12px 26px;font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:10px;background:#3b82f6">Vérifier mon compte →</a>
      </td></tr></table>
    </td></tr>
    <tr><td style="padding:16px 26px;background:#f8fafc;border-top:1px solid #eef2f7;text-align:center;font-family:Arial,Helvetica,sans-serif;font-size:11px;color:#94a3b8">
      Message de sécurité automatique · Cadurix<br>Vous recevez cet email car une connexion depuis un nouvel appareil a été détectée.
    </td></tr>
  </table>
</td></tr></table>
</body></html>';
                cadurix_send_mail($pdo, $user['email'], $user['prenom'] ?? '', $subject, $html);
            }
        } catch(Exception $e) {} // Fail silently


        // Vérifier si 2FA activée (+ forçage super_admin si sec_force_2fa)
        $force2fa = false;
        try { $force2fa = ($pdo->query("SELECT valeur FROM parametres WHERE cle='sec_force_2fa'")->fetchColumn() === '1'); } catch (Throwable $e) {}
        if ($user['twofa_enabled'] || ($force2fa && $user['super_admin'])) {
            // Stocker l'user en session temporaire pour verify_2fa.php
            $_SESSION['2fa_pending']  = true;
            $_SESSION['2fa_user_id']  = $user['id'];
            session_write_close();
            header('Location: https://' . $_SERVER['HTTP_HOST'] . '/verify_2fa.php');
        } else {
            // Connexion directe sans 2FA
            $_SESSION['user_id']     = $user['id'];
            $_SESSION['user_nom']    = $user['nom'];
            $_SESSION['user_prenom'] = $user['prenom'] ?? '';
            $_SESSION['login']       = $user['login'];
            $_SESSION['super_admin'] = (bool)$user['super_admin'];
            journaliser_tentative($pdo, (int)$user['id'], true);
            enregistrer_connexion($pdo, (int)$user['id']); // dernière connexion + IP + compteur
            session_write_close();
            header('Location: https://' . $_SERVER['HTTP_HOST'] . '/dashboard.php');
        }
        exit;
    } else {
        // Échec → sous-système sécurité : score IP, ban auto au seuil, événement, lockdown auto
        init_security($pdo)->recordLoginFail($login);
        sleep(1); // léger ralentissement
        $error = 'Identifiants incorrects.';
    }
    end_login:
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Cadurix — Gestion des p&eacute;remptions m&eacute;dicales</title>
<link rel="icon" href="assets/img/cadurix-icon.svg" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;1,9..40,300&display=swap" rel="stylesheet">
<style>
/* ── Reset & base ───────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --c1: #0a0f1e;       /* fond principal nuit */
  --c2: #0d1526;       /* fond carte */
  --c3: #111d35;       /* fond hover */
  --accent: #00d4aa;   /* vert-cyan signature */
  --accent2: #0099ff;  /* bleu électrique */
  --accent3: #7c3aed;  /* violet profond */
  --text: #e8edf5;
  --text-muted: #6b7fa3;
  --text-soft: #9baac4;
  --border: rgba(255,255,255,0.07);
  --border-accent: rgba(0,212,170,0.3);
  --radius: 16px;
  --radius-sm: 10px;
}

html { scroll-behavior: smooth; }

body {
  font-family: 'DM Sans', sans-serif;
  background: var(--c1);
  color: var(--text);
  min-height: 100vh;
  overflow-x: hidden;
}

/* ── Fond animé ─────────────────────────────────────────── */
.bg-grid {
  position: fixed; inset: 0; z-index: 0;
  background-image:
    linear-gradient(rgba(0,212,170,0.03) 1px, transparent 1px),
    linear-gradient(90deg, rgba(0,212,170,0.03) 1px, transparent 1px);
  background-size: 60px 60px;
  pointer-events: none;
}

.orb {
  position: fixed; border-radius: 50%;
  filter: blur(80px); pointer-events: none; z-index: 0;
  animation: drift 12s ease-in-out infinite alternate;
}
.orb-1 { width: 600px; height: 600px; top: -200px; left: -200px; background: radial-gradient(circle, rgba(0,153,255,0.12) 0%, transparent 70%); }
.orb-2 { width: 500px; height: 500px; bottom: -150px; right: -100px; background: radial-gradient(circle, rgba(0,212,170,0.1) 0%, transparent 70%); animation-delay: -6s; }
.orb-3 { width: 300px; height: 300px; top: 40%; left: 50%; background: radial-gradient(circle, rgba(124,58,237,0.08) 0%, transparent 70%); animation-delay: -3s; }

@keyframes drift {
  from { transform: translate(0,0) scale(1); }
  to   { transform: translate(30px, 20px) scale(1.05); }
}

/* ── Layout principal ───────────────────────────────────── */
.page {
  position: relative; z-index: 1;
  min-height: 100vh;
  display: grid;
  grid-template-columns: 1fr 480px;
}

/* ── CÔTÉ GAUCHE — Hero ─────────────────────────────────── */
.hero {
  display: flex; flex-direction: column;
  justify-content: center; padding: 60px 80px;
  border-right: 1px solid var(--border);
}

.nav {
  position: absolute; top: 0; left: 0; right: 0;
  display: flex; align-items: center; justify-content: space-between;
  padding: 28px 60px;
}

.logo {
  display: flex; align-items: center; gap: 12px;
  font-family: 'Syne', sans-serif;
  font-size: 22px; font-weight: 800;
  letter-spacing: -0.5px;
  color: var(--text);
  text-decoration: none;
}

.logo-icon {
  width: 36px; height: 36px;
  background: linear-gradient(135deg, var(--accent) 0%, var(--accent2) 100%);
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 18px;
  box-shadow: 0 0 20px rgba(0,212,170,0.3);
}

.badge-version {
  font-size: 12px; font-weight: 500;
  background: rgba(0,212,170,0.1);
  border: 1px solid rgba(0,212,170,0.2);
  color: var(--accent);
  padding: 4px 12px; border-radius: 20px;
  letter-spacing: 0.5px;
}

/* Hero content */
.hero-eyebrow {
  display: flex; align-items: center; gap: 10px;
  margin-bottom: 28px;
}
.hero-eyebrow-dot {
  width: 8px; height: 8px; border-radius: 50%;
  background: var(--accent);
  box-shadow: 0 0 12px var(--accent);
  animation: pulse 2s ease-in-out infinite;
}
@keyframes pulse {
  0%, 100% { opacity: 1; transform: scale(1); }
  50% { opacity: 0.5; transform: scale(0.8); }
}
.hero-eyebrow-text {
  font-size: 13px; font-weight: 500;
  color: var(--accent); letter-spacing: 1.5px;
  text-transform: uppercase;
}

.hero-title {
  font-family: 'Syne', sans-serif;
  font-size: clamp(40px, 4vw, 62px);
  font-weight: 800;
  line-height: 1.05;
  letter-spacing: -2px;
  margin-bottom: 24px;
}
.hero-title span {
  background: linear-gradient(135deg, var(--accent) 0%, var(--accent2) 60%, var(--accent3) 100%);
  -webkit-background-clip: text; -webkit-text-fill-color: transparent;
  background-clip: text;
}

.hero-sub {
  font-size: 17px; font-weight: 300;
  color: var(--text-soft); line-height: 1.7;
  max-width: 500px; margin-bottom: 48px;
}

/* Features grid */
.features {
  display: grid; grid-template-columns: 1fr 1fr;
  gap: 12px; max-width: 540px;
}
.feature-card {
  background: var(--c2);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  padding: 18px 20px;
  transition: border-color 0.3s, transform 0.3s;
  cursor: default;
}
.feature-card:hover {
  border-color: var(--border-accent);
  transform: translateY(-2px);
}
.feature-icon {
  font-size: 22px; margin-bottom: 10px;
  display: block;
}
.feature-title {
  font-family: 'Syne', sans-serif;
  font-size: 14px; font-weight: 600;
  color: var(--text); margin-bottom: 4px;
}
.feature-desc {
  font-size: 12px; color: var(--text-muted); line-height: 1.5;
}

/* Stats */
.stats {
  display: flex; gap: 40px; margin-top: 48px;
  padding-top: 40px;
  border-top: 1px solid var(--border);
}
.stat-value {
  font-family: 'Syne', sans-serif;
  font-size: 28px; font-weight: 800;
  color: var(--text);
}
.stat-label {
  font-size: 12px; color: var(--text-muted);
  margin-top: 2px; letter-spacing: 0.5px;
}

/* ── CÔTÉ DROIT — Login ──────────────────────────────────── */
.login-panel {
  display: flex; flex-direction: column;
  justify-content: center; padding: 60px 48px;
  background: var(--c2);
}

.login-header { margin-bottom: 40px; }
.login-title {
  font-family: 'Syne', sans-serif;
  font-size: 26px; font-weight: 700;
  color: var(--text); margin-bottom: 8px;
}
.login-sub { font-size: 14px; color: var(--text-muted); }

/* Form */
.form-group { margin-bottom: 20px; }
.form-label {
  display: block; font-size: 12px; font-weight: 500;
  color: var(--text-soft); letter-spacing: 0.8px;
  text-transform: uppercase; margin-bottom: 8px;
}
.form-input {
  width: 100%;
  background: var(--c1);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  padding: 14px 18px;
  font-family: 'DM Sans', sans-serif;
  font-size: 15px; color: var(--text);
  outline: none;
  transition: border-color 0.2s, box-shadow 0.2s;
}
.form-input::placeholder { color: var(--text-muted); }
.form-input:focus {
  border-color: var(--accent);
  box-shadow: 0 0 0 3px rgba(0,212,170,0.1);
}

.form-input-icon {
  position: relative;
}
.form-input-icon .form-input { padding-left: 46px; }
.form-input-icon .icon {
  position: absolute; left: 16px; top: 50%;
  transform: translateY(-50%);
  color: var(--text-muted); font-size: 17px;
  pointer-events: none;
}

/* Error */
.error-msg {
  background: rgba(220, 53, 69, 0.1);
  border: 1px solid rgba(220, 53, 69, 0.3);
  color: #ff6b7a;
  border-radius: var(--radius-sm);
  padding: 12px 16px;
  font-size: 13px; margin-bottom: 20px;
  display: flex; align-items: center; gap: 8px;
}

/* Submit button */
.btn-login {
  width: 100%;
  background: linear-gradient(135deg, var(--accent) 0%, var(--accent2) 100%);
  border: none; border-radius: var(--radius-sm);
  padding: 16px;
  font-family: 'Syne', sans-serif;
  font-size: 15px; font-weight: 700;
  color: #0a0f1e;
  cursor: pointer; letter-spacing: 0.3px;
  transition: opacity 0.2s, transform 0.2s;
  box-shadow: 0 8px 30px rgba(0,212,170,0.25);
  margin-top: 8px;
}
.btn-login:hover { opacity: 0.9; transform: translateY(-1px); }
.btn-login:active { transform: translateY(0); }

/* Divider */
.login-divider {
  display: flex; align-items: center; gap: 16px;
  margin: 28px 0;
}
.login-divider::before, .login-divider::after {
  content: ''; flex: 1; height: 1px;
  background: var(--border);
}
.login-divider span { font-size: 12px; color: var(--text-muted); white-space: nowrap; }

/* Info cards */
.info-cards {
  display: grid; grid-template-columns: 1fr 1fr; gap: 10px;
}
.info-card {
  background: var(--c3);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  padding: 14px;
  text-align: center;
}
.info-card-icon { font-size: 20px; margin-bottom: 6px; }
.info-card-text { font-size: 11px; color: var(--text-muted); line-height: 1.4; }

/* Footer */
.login-footer {
  margin-top: 36px; padding-top: 24px;
  border-top: 1px solid var(--border);
  text-align: center;
  font-size: 11px; color: var(--text-muted);
}

/* ── Responsive ─────────────────────────────────────────── */
@media (max-width: 960px) {
  .page { grid-template-columns: 1fr; }
  .hero {
    padding: 100px 32px 48px;
    border-right: none;
    border-bottom: 1px solid var(--border);
  }
  .features { grid-template-columns: 1fr 1fr; }
  .login-panel { padding: 48px 32px; }
  .nav { padding: 24px 32px; }
}

@media (max-width: 480px) {
  .hero { padding: 90px 24px 40px; }
  .hero-title { font-size: 36px; letter-spacing: -1.5px; }
  .features { grid-template-columns: 1fr; }
  .stats { flex-wrap: wrap; gap: 24px; }
  .login-panel { padding: 40px 24px; }
  .nav { padding: 20px 24px; }
}

/* ── Animations d'entrée ────────────────────────────────── */
.fade-up {
  opacity: 0; transform: translateY(20px);
  animation: fadeUp 0.7s ease forwards;
}
@keyframes fadeUp {
  to { opacity: 1; transform: translateY(0); pointer-events: auto; }
}
/* Le formulaire doit toujours être interactif même pendant l'animation */
form.fade-up, form.fade-up * {
  pointer-events: auto !important;
}
.delay-1 { animation-delay: 0.1s; }
.delay-2 { animation-delay: 0.2s; }
.delay-3 { animation-delay: 0.35s; }
.delay-4 { animation-delay: 0.5s; }
.delay-5 { animation-delay: 0.65s; }

/* ── Mobile — connexion directe ─────────────────────────── */
@media (max-width: 768px) {
  /* Masquer icônes dans les champs */
  .form-input-icon .icon {
    display: none !important;
  }
  .form-input-icon .form-input {
    padding-left: 14px !important;
  }

  /* Masquer toute la landing marketing */
  .hero-section,
  .landing-section,
  .features-section,
  .stats-bar,
  .hero-features,
  .hero-text,
  .feature-cards,
  section:not(.login-section),
  .landing-content {
    display: none !important;
  }

  /* Page centrée sur le formulaire uniquement */
  body {
    align-items: center;
    justify-content: center;
    min-height: 100vh;
  }

  /* Formulaire de connexion plein écran */
  .login-section,
  .login-box,
  .auth-card {
    display: flex !important;
    flex-direction: column;
    width: 100% !important;
    max-width: 100% !important;
    min-height: 100vh !important;
    padding: 40px 24px 32px !important;
    margin: 0 !important;
    border-radius: 0 !important;
    justify-content: center;
  }

  /* Cacher les stats/chiffres en haut de la landing si présents */
  .landing-stats,
  .hero-stats,
  [class*="stats-"] {
    display: none !important;
  }

  /* Inputs plus grands */
  input[type="text"],
  input[type="email"],
  input[type="password"] {
    font-size: 16px !important;
    min-height: 52px !important;
    padding: 14px 16px !important;
  }

  /* Bouton connexion pleine largeur */
  .login-btn,
  button[type="submit"],
  .btn-login {
    width: 100% !important;
    min-height: 52px !important;
    font-size: 16px !important;
  }
}
</style>
</head>
<body>

<div class="bg-grid"></div>
<div class="orb orb-1"></div>
<div class="orb orb-2"></div>
<div class="orb orb-3"></div>

<!-- NAV -->
<nav class="nav">
  <a href="#" class="logo">
    <div class="logo-icon">&#9877;</div>
    Cadurix
  </a>
  <span class="badge-version">v5.4</span>
</nav>

<div class="page">

  <!-- ── HERO ── -->
  <section class="hero">

    <div class="hero-eyebrow fade-up delay-1">
      <span class="hero-eyebrow-dot"></span>
      <span class="hero-eyebrow-text">Plateforme m&eacute;dicale op&eacute;rationnelle</span>
    </div>

    <h1 class="hero-title fade-up delay-2">
      Z&eacute;ro produit<br><span>p&eacute;rim&eacute;.</span><br>Z&eacute;ro risque.
    </h1>

    <p class="hero-sub fade-up delay-3">
      Toutes vos dotations m&eacute;dicales centralis&eacute;es et surveill&eacute;es 24h/24 &mdash; pour que votre &eacute;quipe intervienne toujours avec le bon mat&eacute;riel, &agrave; chaque fois.
    </p>

    <div class="features fade-up delay-4">
      <div class="feature-card">
        <span class="feature-icon">&#128230;</span>
        <div class="feature-title">Gestion compl&egrave;te</div>
        <div class="feature-desc">Vos dotations organis&eacute;es par v&eacute;hicule, sacoche et structure</div>
      </div>
      <div class="feature-card">
        <span class="feature-icon">&#9200;</span>
        <div class="feature-title">Alertes p&eacute;remption</div>
        <div class="feature-desc">Notification automatique avant chaque expiration critique</div>
      </div>
      <div class="feature-card">
        <span class="feature-icon">&#9989;</span>
        <div class="feature-title">Checklists guid&eacute;es</div>
        <div class="feature-desc">V&eacute;rification quotidienne sign&eacute;e et trac&eacute;e, vacation par vacation</div>
      </div>
      <div class="feature-card">
        <span class="feature-icon">&#129302;</span>
        <div class="feature-title">IA int&eacute;gr&eacute;e</div>
        <div class="feature-desc">Saisie vocale, reconnaissance photo, suggestions intelligentes</div>
      </div>
    </div>

    <div class="stats fade-up delay-5">
      <div>
        <div class="stat-value">43+</div>
        <div class="stat-label">Tables de donn&eacute;es</div>
      </div>
      <div>
        <div class="stat-value">280</div>
        <div class="stat-label">M&eacute;dicaments SDIS</div>
      </div>
      <div>
        <div class="stat-value">100%</div>
        <div class="stat-label">Tra&ccedil;abilit&eacute;</div>
      </div>
    </div>

  </section>

  <!-- ── LOGIN ── -->
  <aside class="login-panel">

    <div class="login-header fade-up delay-1">
      <h2 class="login-title">Connexion</h2>
      <p class="login-sub">Acc&eacute;dez &agrave; votre espace Cadurix</p>
    </div>

    <?php if ($error): ?>
    <div class="error-msg">
      <span>&#9888;&#65039;</span> <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="" class="fade-up delay-2" id="login-form">

      <!-- Honeypot anti-bot (champ caché, doit rester vide) -->
      <input type="text" name="website" style="display:none" tabindex="-1" autocomplete="off">

      <div class="form-group">
        <label class="form-label" for="login">Identifiant</label>
        <div class="form-input-icon">
          <span class="icon">&#128100;</span>
          <input
            class="form-input"
            type="text"
            id="login"
            name="login"
            placeholder="Login ou adresse e-mail"
            required
            autocomplete="username"
            value="<?= htmlspecialchars($_POST['login'] ?? '') ?>"
          >
        </div>
      </div>

      <div class="form-group">
        <label class="form-label" for="password">Mot de passe</label>
        <div class="form-input-icon">
          <span class="icon">&#128274;</span>
          <input
            class="form-input"
            type="password"
            id="password"
            name="password"
            placeholder="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;"
            required
            autocomplete="current-password"
          >
        </div>
      </div>

      <button type="submit" class="btn-login">
        Acc&eacute;der &agrave; Cadurix &rarr;
      </button>

    </form>

    <div class="login-divider fade-up delay-3">
      <span>Acc&egrave;s s&eacute;curis&eacute;</span>
    </div>

    <div class="info-cards fade-up delay-4">
      <div class="info-card">
        <div class="info-card-icon">&#128272;</div>
        <div class="info-card-text">Connexion chiffr&eacute;e HTTPS</div>
      </div>
      <div class="info-card">
        <div class="info-card-icon">&#128737;</div>
        <div class="info-card-text">Acc&egrave;s par r&ocirc;le et site</div>
      </div>
      <div class="info-card">
        <div class="info-card-icon">&#128203;</div>
        <div class="info-card-text">Audit de toutes les actions</div>
      </div>
      <div class="info-card">
        <div class="info-card-icon">&#128190;</div>
        <div class="info-card-text">Sauvegarde automatique</div>
      </div>
    </div>

    <footer class="login-footer">
      Cadurix v5.4 &nbsp;&middot;&nbsp; Tous droits r&eacute;serv&eacute;s &nbsp;&middot;&nbsp;
      <a href="mailto:support@cadurix.fr" style="color: var(--accent); text-decoration: none;">Support</a>
    </footer>

  </aside>

</div>

<script>
// Forcer le submit sur Enter même pendant l'animation
document.addEventListener('keydown', function(e) {
  if (e.key === 'Enter') {
    const form = document.getElementById('login-form');
    if (form) { e.preventDefault(); form.submit(); }
  }
});
</script>
</body>
</html>

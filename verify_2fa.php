<?php
/**
 * Cadurix v5.2 — Double authentification (2FA) par email OTP
 * Appelé après connexion si 2FA activé
 */
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/config/connexion.php';
require_once __DIR__ . '/includes/security.php';
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/includes/mailer.php';
security_middleware($pdo); // IP bannie + lockdown

// Vérifier qu'une session 2FA est en attente
if (!isset($_SESSION['2fa_pending']) || !$_SESSION['2fa_pending']) {
    header('Location: /login.php'); exit;
}

$user_id  = $_SESSION['2fa_user_id'] ?? 0;
$error    = '';
$success  = '';

// ── Générer et envoyer le code ────────────────────────────
function sendOTP(PDO $pdo, int $user_id, array $smtp): bool {
    $code    = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    $expires = date('Y-m-d H:i:s', time() + 600); // 10 min

    // Sauvegarder en BDD
    $pdo->prepare("
        INSERT INTO otp_codes (user_id, code, expires_at, used)
        VALUES (?, ?, ?, 0)
        ON DUPLICATE KEY UPDATE code=?, expires_at=?, used=0
    ")->execute([$user_id, password_hash($code, PASSWORD_BCRYPT), $expires, password_hash($code, PASSWORD_BCRYPT), $expires]);

    // Récupérer email user
    $u = $pdo->prepare("SELECT email, prenom FROM utilisateurs WHERE id=?");
    $u->execute([$user_id]);
    $user = $u->fetch(PDO::FETCH_ASSOC);
    if (!$user || !$user['email']) return false;

    // Envoyer email
    $subject = "🔐 Code de connexion Cadurix : $code";
    $E = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $content = 'Bonjour <strong>'.$E($user['prenom']).'</strong>,<br>Voici votre code de connexion à usage unique :'
      .'<div style="text-align:center;margin:18px 0"><div style="display:inline-block;background:#f1f5f9;border:1px solid #e6ecf5;border-radius:12px;padding:16px 24px;font-family:\'Courier New\',monospace;font-size:34px;font-weight:800;letter-spacing:8px;color:#4f46e5">'.$E($code).'</div><div style="font-size:12px;color:#94a3b8;margin-top:8px">Valide 10 minutes</div></div>'
      .cdx_email_note("Si vous n'avez pas demandé ce code, ignorez cet email — votre compte reste protégé.", 'info');
    $html = cdx_email_layout("Votre code de connexion Cadurix : $code", "Vérification en deux étapes", $content);
    return cadurix_send_mail($pdo, $user['email'], $user['prenom'] ?? '', $subject, $html);
}

// Charger config SMTP
function get_smtp(PDO $pdo): array {
    $keys = ['smtp_host','smtp_port','smtp_from','smtp_from_name','smtp_secure'];
    $cfg  = [];
    foreach ($keys as $k) {
        try { $cfg[$k] = $pdo->query("SELECT valeur FROM parametres WHERE cle='$k'")->fetchColumn() ?: ''; }
        catch (Exception $e) { $cfg[$k] = ''; }
    }
    return $cfg;
}

// ── Actions ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if ($_POST['action'] === 'resend') {
        $smtp = get_smtp($pdo);
        sendOTP($pdo, $user_id, $smtp);
        $_SESSION['2fa_attempts'] = 0;
        $success = 'Nouveau code envoyé.';
    }

    if ($_POST['action'] === 'verify') {
        $input_code = trim($_POST['code'] ?? '');
        $input_code = preg_replace('/\s+/', '', $input_code);

        // Récupérer le code en BDD
        $row = $pdo->prepare("SELECT code, expires_at, used FROM otp_codes WHERE user_id=? ORDER BY id DESC LIMIT 1");
        $row->execute([$user_id]);
        $otp = $row->fetch(PDO::FETCH_ASSOC);

        if (!$otp || $otp['used']) {
            $_SESSION['2fa_attempts'] = ($_SESSION['2fa_attempts'] ?? 0) + 1;
            $error = 'Code invalide ou déjà utilisé.';
        } elseif (strtotime($otp['expires_at']) < time()) {
            $error = 'Code expiré. Demandez-en un nouveau.';
        } elseif (!password_verify($input_code, $otp['code'])) {
            $_SESSION['2fa_attempts'] = ($_SESSION['2fa_attempts'] ?? 0) + 1;
            init_security($pdo)->recordLoginFail('2FA user #' . $user_id);
            $error = 'Code incorrect.';
        } else {
            // Code OK — marquer utilisé
            $pdo->prepare("UPDATE otp_codes SET used=1 WHERE user_id=?")->execute([$user_id]);
            unset($_SESSION['2fa_attempts']);
            // Régénérer l'ID de session (anti-fixation) au moment de l'authentification complète
            session_regenerate_id(true);

            // Finaliser la session
            $u = $pdo->prepare("SELECT * FROM utilisateurs WHERE id=?");
            $u->execute([$user_id]);
            $user = $u->fetch(PDO::FETCH_ASSOC);

            $_SESSION['user_id']     = $user['id'];
            $_SESSION['user_nom']    = $user['nom'];
            $_SESSION['user_prenom'] = $user['prenom'];
            $_SESSION['login']       = $user['login'];
            $_SESSION['super_admin'] = (bool)$user['super_admin'];
            $_SESSION['2fa_pending'] = false;
            unset($_SESSION['2fa_user_id']);

            // Log connexion
            try {
                $pdo->prepare("INSERT INTO connexions_historique (user_id,ip,user_agent,succes) VALUES(?,?,?,1)")
                    ->execute([$user['id'], $_SERVER['REMOTE_ADDR']??'', $_SERVER['HTTP_USER_AGENT']??'']);
            } catch(Exception $e) {}

            enregistrer_connexion($pdo, (int)$user['id']); // dernière connexion + IP + compteur

            header('Location: /dashboard.php'); exit;
        }

        // Trop d'essais de code OTP → invalider le code et renvoyer au login
        if (($_SESSION['2fa_attempts'] ?? 0) >= 5) {
            try { $pdo->prepare("UPDATE otp_codes SET used=1 WHERE user_id=?")->execute([$user_id]); } catch (Throwable $e) {}
            $_SESSION['2fa_pending'] = false;
            unset($_SESSION['2fa_user_id'], $_SESSION['2fa_attempts']);
            header('Location: /index.php?twofa=locked'); exit;
        }
    }
} else {
    // Premier chargement — envoyer le code
    $smtp = get_smtp($pdo);
    sendOTP($pdo, $user_id, $smtp);
}

// Email masqué
$email_masque = '';
try {
    $em = $pdo->prepare("SELECT email FROM utilisateurs WHERE id=?");
    $em->execute([$user_id]);
    $email_full = $em->fetchColumn() ?: '';
    if ($email_full) {
        $parts = explode('@', $email_full);
        $email_masque = substr($parts[0], 0, 2) . str_repeat('*', max(2, strlen($parts[0])-2)) . '@' . $parts[1];
    }
} catch(Exception $e) {}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title>Vérification — Cadurix</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Plus Jakarta Sans',sans-serif;background:#080E1A;color:#E8EEFF;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.card{background:#0D1525;border:1px solid #1A2240;border-radius:16px;padding:36px 32px;width:100%;max-width:380px;text-align:center}
.icon{width:64px;height:64px;background:linear-gradient(135deg,#7C3AED,#5B21B6);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:28px;margin:0 auto 20px}
h1{font-size:1.3rem;font-weight:700;margin-bottom:8px}
.sub{font-size:.85rem;color:#8892AA;margin-bottom:24px;line-height:1.5}
.code-inputs{display:flex;gap:8px;justify-content:center;margin-bottom:20px}
.code-input{width:48px;height:56px;background:#111D30;border:2px solid #1A2240;border-radius:10px;font-size:1.4rem;font-weight:700;text-align:center;color:#E8EEFF;font-family:'Plus Jakarta Sans',sans-serif;outline:none;transition:border-color .15s}
.code-input:focus{border-color:#7C3AED}
.btn{width:100%;padding:13px;background:#7C3AED;color:#fff;border:none;border-radius:10px;font-size:.95rem;font-weight:700;cursor:pointer;font-family:inherit;transition:opacity .15s;margin-bottom:10px}
.btn:hover{opacity:.85}
.btn-ghost{background:transparent;border:1px solid #1A2240;color:#8892AA;margin-bottom:0}
.btn-ghost:hover{border-color:#7C3AED;color:#E8EEFF;opacity:1}
.error{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#f87171;padding:10px 14px;border-radius:8px;font-size:.83rem;margin-bottom:16px}
.success{background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.3);color:#34d399;padding:10px 14px;border-radius:8px;font-size:.83rem;margin-bottom:16px}
.timer{font-size:.78rem;color:#8892AA;margin-top:8px}
</style>
</head>
<body>
<div class="card">
  <div class="icon">🔐</div>
  <h1>Vérification en 2 étapes</h1>
  <p class="sub">
    Un code à 6 chiffres a été envoyé à<br>
    <strong style="color:#E8EEFF"><?= h($email_masque) ?></strong>
  </p>

  <?php if ($error): ?>
  <div class="error">⚠️ <?= h($error) ?></div>
  <?php endif; ?>
  <?php if ($success): ?>
  <div class="success">✅ <?= h($success) ?></div>
  <?php endif; ?>

  <form method="POST" id="form-2fa">
  <?= csrf_field() ?>
    <input type="hidden" name="action" value="verify">
    <input type="hidden" name="code" id="code-hidden">
    <div class="code-inputs">
      <?php for ($i=1; $i<=6; $i++): ?>
      <input type="text" class="code-input" maxlength="1" inputmode="numeric"
             id="ci<?= $i ?>" pattern="[0-9]" autocomplete="<?= $i===1?'one-time-code':'off' ?>">
      <?php endfor; ?>
    </div>
    <button type="submit" class="btn">Vérifier le code</button>
  </form>

  <form method="POST">
  <?= csrf_field() ?>
    <input type="hidden" name="action" value="resend">
    <button type="submit" class="btn btn-ghost">📧 Renvoyer le code</button>
  </form>

  <div class="timer" id="timer">Code valide pendant <span id="countdown">10:00</span></div>
  <div style="margin-top:20px;padding-top:16px;border-top:1px solid #1A2240">
    <a href="/logout.php" style="font-size:.8rem;color:#8892AA;text-decoration:none">← Déconnexion</a>
  </div>
</div>

<script>
// Navigation entre inputs
const inputs = document.querySelectorAll('.code-input');
inputs.forEach((inp, i) => {
  inp.addEventListener('input', e => {
    const val = e.target.value.replace(/\D/g,'');
    e.target.value = val;
    if (val && i < 5) inputs[i+1].focus();
    updateHidden();
  });
  inp.addEventListener('keydown', e => {
    if (e.key === 'Backspace' && !e.target.value && i > 0) inputs[i-1].focus();
  });
  inp.addEventListener('paste', e => {
    e.preventDefault();
    const paste = (e.clipboardData||window.clipboardData).getData('text').replace(/\D/g,'');
    paste.split('').slice(0,6).forEach((c,j) => {
      if (inputs[j]) inputs[j].value = c;
    });
    updateHidden();
    if (paste.length >= 6) document.getElementById('form-2fa').submit();
  });
});

function updateHidden() {
  const code = Array.from(inputs).map(i => i.value).join('');
  document.getElementById('code-hidden').value = code;
}

// Auto-submit quand 6 chiffres
inputs[5].addEventListener('input', () => {
  const code = Array.from(inputs).map(i=>i.value).join('');
  if (code.length === 6) setTimeout(() => document.getElementById('form-2fa').submit(), 200);
});

// Countdown 10 min
let secs = 600;
const cd = document.getElementById('countdown');
const interval = setInterval(() => {
  secs--;
  if (secs <= 0) { clearInterval(interval); cd.textContent = 'expiré'; return; }
  cd.textContent = Math.floor(secs/60) + ':' + String(secs%60).padStart(2,'0');
}, 1000);

// Focus premier champ
inputs[0].focus();
</script>
</body>
</html>

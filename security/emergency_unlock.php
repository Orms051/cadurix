<?php
/**
 * Cadurix v5.2 — Déblocage d'urgence par token
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/security.php';

$token = trim($_GET['token'] ?? '');

if (!$token || strlen($token) < 32) {
    http_response_code(403);
    $error = 'Token invalide ou manquant.';
} else {
    try {
        $row = $pdo->prepare("SELECT id FROM security_lockdown WHERE token_urgence = ? AND actif = 1");
        $row->execute([$token]);
        $lockdown = $row->fetch(PDO::FETCH_ASSOC);

        if (!$lockdown) {
            http_response_code(403);
            $error = 'Token invalide ou lockdown déjà levé.';
        } else {
            $pdo->prepare("UPDATE security_lockdown SET actif=0, debloque_le=NOW(), debloque_par=NULL WHERE id=?")
                ->execute([$lockdown['id']]);
            $pdo->prepare("INSERT INTO security_events (type, ip, details) VALUES ('unlock', ?, 'Déblocage urgence via token')")
                ->execute([$_SERVER['REMOTE_ADDR'] ?? '']);
            $success = true;
        }
    } catch (Exception $e) {
        $error = 'Erreur serveur.';
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Cadurix — Déblocage d'urgence</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=DM+Sans:wght@400;500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg: #030508;
  --success: #10b981;
  --error: #ef4444;
  --text: #f0fff8;
  --muted: rgba(240,255,248,.45);
}
body{
  font-family:'DM Sans',sans-serif;
  background:var(--bg);
  color:var(--text);
  min-height:100vh;
  display:flex;align-items:center;justify-content:center;
  overflow:hidden;
}

/* Fond */
.bg{position:fixed;inset:0;z-index:0;pointer-events:none}
<?php if (!empty($success)): ?>
.bg{background:radial-gradient(ellipse 600px 500px at 50% 60%, rgba(16,185,129,.08), transparent 70%)}
<?php else: ?>
.bg{background:radial-gradient(ellipse 600px 500px at 50% 60%, rgba(239,68,68,.06), transparent 70%)}
<?php endif; ?>

/* Lignes scan */
.scanlines{
  position:fixed;inset:0;z-index:0;pointer-events:none;
  background:repeating-linear-gradient(
    0deg,
    transparent,transparent 2px,
    rgba(255,255,255,.008) 2px,rgba(255,255,255,.008) 4px
  );
}

.wrap{
  position:relative;z-index:1;
  width:100%;max-width:420px;
  padding:2rem;
  text-align:center;
  animation:appear .6s ease both;
}
@keyframes appear{from{opacity:0;transform:scale(.95)}to{opacity:1;transform:scale(1)}}

/* Logo */
.logo{
  display:flex;align-items:center;justify-content:center;gap:10px;
  margin-bottom:2.5rem;
  opacity:.6;
}
.logo-mark{
  width:36px;height:36px;
  background:linear-gradient(135deg,#6366f1,#818cf8);
  border-radius:10px;
  display:flex;align-items:center;justify-content:center;
  font-size:16px;
}
.logo-name{font-family:'Syne',sans-serif;font-size:1.1rem;font-weight:700;letter-spacing:-.3px}

/* Icône centrale */
.icon-wrap{
  position:relative;
  width:100px;height:100px;
  margin:0 auto 1.75rem;
}
.icon-ring{
  position:absolute;inset:0;border-radius:50%;
  animation:ring-pulse 2.5s ease-in-out infinite;
}
<?php if (!empty($success)): ?>
.icon-ring{border:2px solid rgba(16,185,129,.3);}
@keyframes ring-pulse{0%,100%{transform:scale(1);opacity:1}50%{transform:scale(1.15);opacity:.4}}
.icon-core{
  width:100px;height:100px;border-radius:50%;
  background:radial-gradient(circle,rgba(16,185,129,.2),rgba(16,185,129,.05));
  border:2px solid rgba(16,185,129,.5);
  display:flex;align-items:center;justify-content:center;
  font-size:2.8rem;
  box-shadow:0 0 40px rgba(16,185,129,.3), 0 0 80px rgba(16,185,129,.1);
}
<?php else: ?>
.icon-ring{border:2px solid rgba(239,68,68,.3);}
@keyframes ring-pulse{0%,100%{transform:scale(1);opacity:1}50%{transform:scale(1.12);opacity:.4}}
.icon-core{
  width:100px;height:100px;border-radius:50%;
  background:radial-gradient(circle,rgba(239,68,68,.15),rgba(239,68,68,.03));
  border:2px solid rgba(239,68,68,.4);
  display:flex;align-items:center;justify-content:center;
  font-size:2.8rem;
  box-shadow:0 0 40px rgba(239,68,68,.25), 0 0 80px rgba(239,68,68,.08);
}
<?php endif; ?>

.status-line{
  font-family:'Syne',sans-serif;
  font-size:1.5rem;font-weight:800;
  letter-spacing:-.3px;
  margin-bottom:.6rem;
  <?= !empty($success) ? 'color:#34d399' : 'color:#f87171' ?>;
}

.status-msg{
  color:var(--muted);
  font-size:.9rem;line-height:1.6;
  margin-bottom:2rem;
}

.card{
  background:rgba(255,255,255,.03);
  border:1px solid <?= !empty($success) ? 'rgba(16,185,129,.2)' : 'rgba(239,68,68,.2)' ?>;
  border-radius:18px;
  padding:1.5rem 2rem;
  margin-bottom:1.75rem;
  backdrop-filter:blur(20px);
}

.meta-row{
  display:flex;justify-content:space-between;align-items:center;
  font-size:.8rem;padding:.5rem 0;
  border-bottom:1px solid rgba(255,255,255,.05);
}
.meta-row:last-child{border:none}
.meta-lbl{color:var(--muted)}
.meta-val{font-weight:600;font-family:monospace;font-size:.78rem}

.btn{
  display:inline-flex;align-items:center;gap:8px;
  padding:.8rem 1.75rem;
  border-radius:12px;border:none;cursor:pointer;
  font-family:'Syne',sans-serif;font-size:.9rem;font-weight:700;
  text-decoration:none;
  <?= !empty($success) ? 'background:#10b981;color:#fff;box-shadow:0 4px 20px rgba(16,185,129,.4)' : 'background:rgba(239,68,68,.15);color:#f87171;border:1px solid rgba(239,68,68,.3)' ?>;
  transition:all .2s;
}
.btn:hover{transform:translateY(-1px);filter:brightness(1.1)}

.footer{
  margin-top:1.5rem;
  font-size:.7rem;color:rgba(255,255,255,.15);
  letter-spacing:1.5px;text-transform:uppercase;
}
</style>
</head>
<body>
<div class="bg"></div>
<div class="scanlines"></div>

<div class="wrap">

  <div class="logo">
    <div class="logo-mark">💊</div>
    <div class="logo-name">Cadurix</div>
  </div>

  <div class="icon-wrap">
    <div class="icon-ring"></div>
    <div class="icon-core"><?= !empty($success) ? '✓' : '✕' ?></div>
  </div>

  <?php if (!empty($success)): ?>
  <div class="status-line">Site déverrouillé</div>
  <div class="status-msg">Le mode lockdown a été levé avec succès.<br>Le site est de nouveau accessible à tous les utilisateurs.</div>
  <div class="card">
    <div class="meta-row"><span class="meta-lbl">Statut</span><span class="meta-val" style="color:#34d399">✓ Lockdown levé</span></div>
    <div class="meta-row"><span class="meta-lbl">Heure</span><span class="meta-val"><?= date('d/m/Y à H:i:s') ?></span></div>
    <div class="meta-row"><span class="meta-lbl">IP</span><span class="meta-val"><?= htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? '—') ?></span></div>
  </div>
  <a href="/login.php" class="btn">→ Se connecter</a>

  <?php else: ?>
  <div class="status-line">Accès refusé</div>
  <div class="status-msg"><?= htmlspecialchars($error ?? 'Token invalide.') ?></div>
  <div class="card">
    <div class="meta-row"><span class="meta-lbl">Statut</span><span class="meta-val" style="color:#f87171">✕ Échec</span></div>
    <div class="meta-row"><span class="meta-lbl">Heure</span><span class="meta-val"><?= date('d/m/Y à H:i:s') ?></span></div>
    <div class="meta-row"><span class="meta-lbl">IP</span><span class="meta-val"><?= htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? '—') ?></span></div>
  </div>
  <a href="/login.php" class="btn">Retour à la connexion</a>
  <?php endif; ?>

  <div class="footer">Cadurix · Sécurité · <?= date('Y') ?></div>
</div>
</body>
</html>

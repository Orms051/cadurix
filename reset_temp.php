<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.

/**
 * Cadurix — Reset mot de passe temporaire
 * ⚠️ RÉSERVÉ AU SUPER ADMIN — SUPPRIMER CE FICHIER APRÈS UTILISATION
 */
session_start();
require_once 'config/db.php';
require_once 'config/session.php';
require_once 'config/permissions.php';

// Vérifier que c'est bien le super admin
if (empty($_SESSION['user_id']) || empty($_SESSION['super_admin'])) {
    http_response_code(403);
    die('<h2 style="color:red;font-family:sans-serif">⛔ Accès refusé — Super Admin uniquement</h2>');
}

// Double confirmation obligatoire
$confirmed = isset($_POST['confirm']) && $_POST['confirm'] === 'RESET_CONFIRME';

if (!$confirmed) {
?><!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>Reset MDP</title>
<style>body{font-family:sans-serif;background:#0b0f1a;color:#e2e8f0;padding:2rem;max-width:500px;margin:0 auto}
.warn{background:#ef4444;color:#fff;padding:1rem;border-radius:8px;margin-bottom:1.5rem}
input[type=text]{width:100%;padding:.6rem;background:#1e293b;border:1px solid #334155;color:#e2e8f0;border-radius:6px;margin-bottom:1rem}
button{background:#ef4444;color:#fff;border:none;padding:.75rem 2rem;border-radius:6px;cursor:pointer;font-weight:700}</style></head><body>
<div class="warn">⚠️ OUTIL DANGEREUX — Réinitialise les mots de passe de TOUS les utilisateurs</div>
<form method="post">
  <p>Tapez <strong>RESET_CONFIRME</strong> pour confirmer :</p>
  <input type="text" name="confirm" placeholder="RESET_CONFIRME" required>
  <button type="submit">🔑 Réinitialiser</button>
</form>
</body></html>
<?php
    exit;
}

$mdp  = 'Cadurix2025!';
$hash = password_hash($mdp, PASSWORD_BCRYPT, ['cost' => 12]);

// Reset UNIQUEMENT les users du site actif si admin, tous si super_admin
$pdo->prepare("UPDATE utilisateurs SET mot_de_passe=?, tentatives_echec=0, bloque_jusqu=NULL")
    ->execute([$hash]);

// Log l'action
try {
    $pdo->prepare("INSERT INTO audit_log (user_id, action, table_name, description, ip) VALUES (?,?,?,?,?)")
        ->execute([$_SESSION['user_id'], 'RESET_MDP_GLOBAL', 'utilisateurs', 'Reset global mots de passe', $_SERVER['REMOTE_ADDR']??'']);
} catch(Exception $e) {}

$users = $pdo->query("SELECT id, login, email, super_admin FROM utilisateurs ORDER BY id")->fetchAll();
?><!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>Reset MDP</title>
<style>body{font-family:sans-serif;background:#0b0f1a;color:#e2e8f0;padding:2rem;max-width:600px;margin:0 auto}
h2{color:#ef4444}table{width:100%;border-collapse:collapse}th,td{padding:.6rem 1rem;text-align:left;border-bottom:1px solid #1f2d45}
th{color:#64748b;font-size:.8rem;text-transform:uppercase}.ok{color:#4ade80;font-weight:700}
.warn{background:#f59e0b;color:#000;padding:.3rem .8rem;border-radius:.4rem;font-weight:700;display:inline-block;margin-bottom:1rem}</style>
</head><body>
<h2>🔑 Mots de passe réinitialisés</h2>
<div class="warn">⚠️ SUPPRIMEZ CE FICHIER immédiatement !</div>
<table>
  <tr><th>Login</th><th>Email</th><th>Type</th><th>Mot de passe</th></tr>
  <?php foreach ($users as $u): ?>
  <tr>
    <td><strong><?= htmlspecialchars($u['login']) ?></strong></td>
    <td><?= htmlspecialchars($u['email']) ?></td>
    <td><?= $u['super_admin'] ? '⭐ Super Admin' : 'Utilisateur' ?></td>
    <td class="ok"><?= htmlspecialchars($mdp) ?></td>
  </tr>
  <?php endforeach; ?>
</table>
<p style="color:#ef4444;font-weight:600;margin-top:1.5rem">⚠️ Supprimez ce fichier MAINTENANT :<br>
<code style="color:#fcd34d">rm reset_temp.php</code></p>
<p><a href="/index.php" style="color:#3b82f6">→ Page de connexion</a></p>
</body></html>

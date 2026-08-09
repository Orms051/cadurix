<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.

/**
 * admin/api_tokens.php — Gestion des clés API
 */
if (!defined('ADMIN_HUB')) die('Accès direct interdit.');

$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_token') {
        $nom   = trim($_POST['nom'] ?? '');
        $perms = $_POST['perms'] ?? ['read'];
        $site  = (int)($_POST['site_id'] ?? 0) ?: null;
        $expire = !empty($_POST['expire_le']) ? $_POST['expire_le'] : null;
        $ips   = trim($_POST['ip_whitelist'] ?? '');

        if (!$nom) { $error = 'Nom requis'; }
        else {
            // Générer une clé API lisible
            $api_key = 'cad_' . bin2hex(random_bytes(20));
            $ip_list = $ips ? json_encode(array_filter(array_map('trim', explode(',', $ips)))) : null;

            $pdo->prepare("INSERT INTO api_tokens (user_id,site_id,nom,token_hash,permissions,expire_le,ip_whitelist,actif)
                VALUES (?,?,?,?,?,?,?,1)")->execute([
                currentUserId(), $site, $nom,
                hash('sha256', $api_key),
                json_encode($perms),
                $expire, $ip_list,
            ]);
            $success = "Clé API créée : <code style='background:var(--bg3);padding:4px 10px;border-radius:6px;font-size:.9rem'>$api_key</code><br>⚠️ Copiez-la maintenant, elle ne sera plus affichée.";
        }

    } elseif ($action === 'toggle_token') {
        $id  = (int)($_POST['token_id'] ?? 0);
        $val = (int)$_POST['actif'];
        $pdo->prepare("UPDATE api_tokens SET actif=? WHERE id=?")->execute([$val, $id]);
        $success = $val ? 'Clé activée.' : 'Clé désactivée.';

    } elseif ($action === 'delete_token') {
        $id = (int)($_POST['token_id'] ?? 0);
        $pdo->prepare("DELETE FROM api_tokens WHERE id=?")->execute([$id]);
        $success = 'Clé supprimée.';
    }
}

$tokens = $pdo->query("
    SELECT t.*, u.nom AS user_nom, u.prenom AS user_prenom,
           s.nom AS site_nom,
           (SELECT COUNT(*) FROM api_logs l WHERE l.token_id=t.id AND l.cree_le > DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS appels_24h
    FROM api_tokens t
    JOIN utilisateurs u ON u.id = t.user_id
    LEFT JOIN sites s ON s.id = t.site_id
    ORDER BY t.cree_le DESC
")->fetchAll(PDO::FETCH_ASSOC);

$sites = $pdo->query("SELECT id, nom FROM sites WHERE actif=1 ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="page-header" style="margin-bottom:16px">
  <div class="page-title-group">
    <div class="page-title">🔑 Clés API</div>
    <div class="page-sub">Gestion des tokens d'accès pour l'API REST Cadurix</div>
  </div>
</div>

<?php if ($success): ?>
<div style="background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.3);color:#10b981;border-radius:10px;padding:14px 18px;margin-bottom:16px">
  ✅ <?= $success ?>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div style="background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);color:#ef4444;border-radius:10px;padding:14px 18px;margin-bottom:16px">
  ⚠️ <?= h($error) ?>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1.4fr;gap:20px">

  <!-- Créer une clé -->
  <div class="card" style="padding:20px">
    <div style="font-weight:700;font-size:.95rem;margin-bottom:16px">➕ Nouvelle clé API</div>
    <form method="post">
      <input type="hidden" name="action" value="create_token">
      <?= csrf_field() ?>

      <div style="margin-bottom:12px">
        <label style="font-size:11px;font-weight:600;color:var(--text2);text-transform:uppercase;display:block;margin-bottom:4px">Nom descriptif *</label>
        <input type="text" name="nom" class="form-control" placeholder="Ex: App Mobile SDIS51" required
               style="background:var(--glass);border:1px solid var(--border);color:var(--text);border-radius:8px;padding:8px 12px;width:100%">
      </div>

      <div style="margin-bottom:12px">
        <label style="font-size:11px;font-weight:600;color:var(--text2);text-transform:uppercase;display:block;margin-bottom:4px">Site (optionnel)</label>
        <select name="site_id" style="background:var(--glass);border:1px solid var(--border);color:var(--text);border-radius:8px;padding:8px 12px;width:100%">
          <option value="">Tous les sites autorisés</option>
          <?php foreach ($sites as $s): ?>
          <option value="<?= $s['id'] ?>"><?= h($s['nom']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div style="margin-bottom:12px">
        <label style="font-size:11px;font-weight:600;color:var(--text2);text-transform:uppercase;display:block;margin-bottom:6px">Permissions</label>
        <div style="display:flex;gap:12px;flex-wrap:wrap">
          <?php foreach (['read'=>'👁️ Lecture','write'=>'✏️ Écriture','delete'=>'🗑️ Suppression'] as $p=>$l): ?>
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:.88rem">
            <input type="checkbox" name="perms[]" value="<?= $p ?>" <?= $p==='read'?'checked':'' ?> style="accent-color:var(--primary)">
            <?= $l ?>
          </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div style="margin-bottom:12px">
        <label style="font-size:11px;font-weight:600;color:var(--text2);text-transform:uppercase;display:block;margin-bottom:4px">Expiration (optionnel)</label>
        <input type="date" name="expire_le" style="background:var(--glass);border:1px solid var(--border);color:var(--text);border-radius:8px;padding:8px 12px;width:100%">
      </div>

      <div style="margin-bottom:16px">
        <label style="font-size:11px;font-weight:600;color:var(--text2);text-transform:uppercase;display:block;margin-bottom:4px">Whitelist IPs (optionnel)</label>
        <input type="text" name="ip_whitelist" placeholder="192.168.1.1, 10.0.0.1"
               style="background:var(--glass);border:1px solid var(--border);color:var(--text);border-radius:8px;padding:8px 12px;width:100%">
        <div style="font-size:11px;color:var(--text3);margin-top:3px">Séparer par des virgules. Vide = toutes les IPs</div>
      </div>

      <button type="submit" class="btn btn-primary">🔑 Générer la clé</button>
    </form>
  </div>

  <!-- Liste des clés -->
  <div>
    <div style="font-weight:700;font-size:.95rem;margin-bottom:12px">Clés existantes (<?= count($tokens) ?>)</div>
    <?php if (empty($tokens)): ?>
    <div style="text-align:center;padding:2rem;color:var(--text3);background:var(--glass);border-radius:10px">
      Aucune clé API créée
    </div>
    <?php endif; ?>
    <?php foreach ($tokens as $t): ?>
    <div style="border:1px solid var(--border);border-radius:10px;padding:14px;margin-bottom:8px;opacity:<?= $t['actif']?1:.6 ?>">
      <div style="display:flex;align-items:start;justify-content:space-between;gap:10px">
        <div style="flex:1;min-width:0">
          <div style="font-weight:600;font-size:.9rem"><?= h($t['nom']) ?></div>
          <div style="font-size:.75rem;color:var(--text3);margin-top:2px">
            👤 <?= h($t['user_prenom'].' '.$t['user_nom']) ?>
            <?php if ($t['site_nom']): ?> · 🏢 <?= h($t['site_nom']) ?><?php endif; ?>
          </div>
          <div style="display:flex;gap:6px;margin-top:6px;flex-wrap:wrap">
            <?php foreach (json_decode($t['permissions'],true)??[] as $p): ?>
            <span style="background:var(--primary-bg);color:var(--primary);padding:1px 8px;border-radius:20px;font-size:10px;font-weight:600"><?= h($p) ?></span>
            <?php endforeach; ?>
            <span style="background:var(--glass);color:var(--text3);padding:1px 8px;border-radius:20px;font-size:10px">
              <?= $t['appels_24h'] ?> appels/24h
            </span>
            <?php if ($t['expire_le']): ?>
            <span style="background:rgba(245,158,11,.1);color:#f59e0b;padding:1px 8px;border-radius:20px;font-size:10px">
              Expire <?= date('d/m/Y', strtotime($t['expire_le'])) ?>
            </span>
            <?php endif; ?>
          </div>
        </div>
        <div style="display:flex;gap:4px;flex-shrink:0">
          <form method="post" style="display:inline">
            <input type="hidden" name="action" value="toggle_token">
            <input type="hidden" name="token_id" value="<?= $t['id'] ?>">
            <input type="hidden" name="actif" value="<?= $t['actif']?0:1 ?>">
            <?= csrf_field() ?>
            <button type="submit" style="padding:4px 10px;border-radius:6px;border:1px solid var(--border);background:var(--glass);color:var(--text);cursor:pointer;font-size:12px">
              <?= $t['actif']?'⏸️':'▶️' ?>
            </button>
          </form>
          <form method="post" style="display:inline" onsubmit="return confirm('Supprimer cette clé ?')">
            <input type="hidden" name="action" value="delete_token">
            <input type="hidden" name="token_id" value="<?= $t['id'] ?>">
            <?= csrf_field() ?>
            <button type="submit" style="padding:4px 10px;border-radius:6px;border:1px solid rgba(239,68,68,.3);background:rgba(239,68,68,.1);color:#ef4444;cursor:pointer;font-size:12px">🗑️</button>
          </form>
        </div>
      </div>
    </div>
    <?php endforeach; ?>

    <!-- Documentation rapide -->
    <div style="margin-top:16px;background:var(--bg3);border:1px solid var(--border);border-radius:10px;padding:14px">
      <div style="font-weight:600;font-size:.85rem;margin-bottom:8px">📖 Utilisation rapide</div>
      <pre style="font-size:11px;color:var(--text2);overflow-x:auto;margin:0">POST /api/auth.php
{
  "login": "yoann",
  "password": "****",
  "api_key": "cad_xxxxxxxxxxxx"
}

→ access_token (15min) + refresh_token (30j)

Authorization: Bearer {access_token}
GET /api/v1/produits.php?site_id=1</pre>
    </div>
  </div>

</div>

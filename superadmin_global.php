<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.

/**
 * Cadurix — Dashboard Super Admin Global
 * Vue d'ensemble de tous les sites/organisations
 */
require_once 'includes/auth.php';
$pageTitle = 'Vue globale';

if (!($_SESSION['super_admin'] ?? false)) {
    header('Location: dashboard.php'); exit;
}
headers_securite();

// Stats globales toutes organisations
$total_sites  = (int)$pdo->query("SELECT COUNT(*) FROM sites WHERE actif=1")->fetchColumn();
$total_users  = (int)$pdo->query("SELECT COUNT(*) FROM utilisateurs WHERE actif=1")->fetchColumn();
$total_prod   = (int)$pdo->query("SELECT COUNT(*) FROM produits WHERE statut='actif'")->fetchColumn();
$total_perime = (int)$pdo->query("SELECT COUNT(*) FROM produits WHERE statut='actif' AND date_peremption < CURDATE()")->fetchColumn();
$total_crit   = (int)$pdo->query("SELECT COUNT(*) FROM produits WHERE statut='actif' AND DATEDIFF(date_peremption,CURDATE()) BETWEEN 0 AND 7")->fetchColumn();
$total_remp   = (int)$pdo->query("SELECT COUNT(*) FROM rempotages WHERE MONTH(debut_le)=MONTH(NOW()) AND YEAR(debut_le)=YEAR(NOW())")->fetchColumn();

// Coût IA du mois
$cout_ia = (float)$pdo->query("SELECT COALESCE(SUM(cout_eur),0) FROM ai_conversations WHERE MONTH(cree_le)=MONTH(NOW()) AND YEAR(cree_le)=YEAR(NOW())")->fetchColumn();

// Par organisation
$orgs = $pdo->query("
    SELECT o.id, o.nom, o.type,
           COUNT(DISTINCT s.id) AS nb_sites,
           COUNT(DISTINCT usp.user_id) AS nb_users,
           (SELECT COUNT(*) FROM produits p JOIN emplacements e ON e.id=p.emplacement_id 
            JOIN sites s2 ON s2.id=e.site_id WHERE s2.organisation_id=o.id AND p.statut='actif') AS nb_prod,
           (SELECT COUNT(*) FROM produits p JOIN emplacements e ON e.id=p.emplacement_id 
            JOIN sites s2 ON s2.id=e.site_id WHERE s2.organisation_id=o.id AND p.statut='actif' AND p.date_peremption<CURDATE()) AS nb_perime,
           (SELECT COUNT(*) FROM produits p JOIN emplacements e ON e.id=p.emplacement_id 
            JOIN sites s2 ON s2.id=e.site_id WHERE s2.organisation_id=o.id AND p.statut='actif' AND DATEDIFF(p.date_peremption,CURDATE()) BETWEEN 0 AND 7) AS nb_crit
    FROM organisations o
    LEFT JOIN sites s ON s.organisation_id=o.id AND s.actif=1
    LEFT JOIN user_site_permissions usp ON usp.site_id=s.id
    GROUP BY o.id
    ORDER BY nb_perime DESC, o.nom
")->fetchAll(PDO::FETCH_ASSOC);

// Sites les plus critiques
$sites_crit = $pdo->query("
    SELECT s.id, s.nom, o.nom AS org_nom,
           (SELECT COUNT(*) FROM produits p JOIN emplacements e ON e.id=p.emplacement_id WHERE e.site_id=s.id AND p.statut='actif' AND p.date_peremption<CURDATE()) AS perime,
           (SELECT COUNT(*) FROM produits p JOIN emplacements e ON e.id=p.emplacement_id WHERE e.site_id=s.id AND p.statut='actif' AND DATEDIFF(p.date_peremption,CURDATE()) BETWEEN 0 AND 7) AS critique,
           (SELECT COUNT(*) FROM utilisateurs u JOIN user_site_permissions usp ON usp.user_id=u.id WHERE usp.site_id=s.id AND u.derniere_connexion < DATE_SUB(NOW(), INTERVAL 30 DAY)) AS inactifs
    FROM sites s JOIN organisations o ON o.id=s.organisation_id
    WHERE s.actif=1
    HAVING (perime+critique) > 0
    ORDER BY perime DESC, critique DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// Activité récente inter-sites
$activite = $pdo->query("
    SELECT al.action, al.table_cible, al.cree_le,
           CONCAT(u.prenom,' ',u.nom) AS user_nom,
           (SELECT s.nom FROM user_site_permissions usp2
            JOIN sites s ON s.id=usp2.site_id
            WHERE usp2.user_id=al.user_id LIMIT 1) AS site_nom
    FROM audit_log al
    LEFT JOIN utilisateurs u ON u.id = al.user_id
    ORDER BY al.cree_le DESC LIMIT 15
")->fetchAll(PDO::FETCH_ASSOC);

require_once 'includes/header.php';
?>

<div class="page-header">
  <div class="page-title-group">
    <div class="page-title">🌐 Vue globale — Super Admin</div>
    <div class="page-sub">Supervision de tous les sites et organisations</div>
  </div>
  <div class="page-actions">
    <a href="/dashboard.php" class="btn btn-sm btn-ghost">← Mon site</a>
    <button onclick="envoyerRapport()" class="btn btn-sm btn-primary">📄 Rapport global</button>
  </div>
</div>

<!-- Modale rapport -->
<div id="modal-rapport" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.6);backdrop-filter:blur(4px);align-items:center;justify-content:center">
  <div style="background:var(--bg2);border:1px solid var(--border);border-radius:16px;padding:32px;max-width:480px;width:90%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.4)">
    <div id="modal-rapport-content">
      <div style="font-size:2.5rem;margin-bottom:16px">⏳</div>
      <div style="font-size:1.1rem;font-weight:700;color:var(--text);margin-bottom:8px">Génération en cours…</div>
      <div style="font-size:.88rem;color:var(--text3)">Envoi des rapports mensuels à tous les admins</div>
    </div>
  </div>
</div>

<script>
async function envoyerRapport() {
  const modal = document.getElementById('modal-rapport');
  const content = document.getElementById('modal-rapport-content');
  modal.style.display = 'flex';

  try {
    const token = <?= json_encode($pdo->query("SELECT valeur FROM parametres WHERE cle='cron_token'")->fetchColumn() ?: '') ?>;
    const res = await fetch(`/rapport_mensuel.php?token=${token}`);
    const data = await res.json();

    if (data.ok) {
      const lignes = data.results.map(r =>
        `<div style="display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid var(--border);font-size:.85rem">
          <span>${r.ok ? '✅' : '❌'}</span>
          <span style="flex:1;text-align:left">${r.email}</span>
          <span style="color:var(--text3)">${r.site}</span>
        </div>`
      ).join('');

      content.innerHTML = `
        <div style="font-size:2.5rem;margin-bottom:16px">✅</div>
        <div style="font-size:1.1rem;font-weight:700;color:var(--text);margin-bottom:4px">Rapports envoyés</div>
        <div style="font-size:.85rem;color:var(--text3);margin-bottom:20px">${data.mois}</div>
        <div style="background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:8px 16px;margin-bottom:20px;max-height:240px;overflow-y:auto">
          ${lignes || '<div style="color:var(--text3);padding:12px">Aucun email configuré</div>'}
        </div>
        <button onclick="document.getElementById('modal-rapport').style.display='none'"
          style="background:var(--primary);color:white;border:none;border-radius:8px;padding:10px 28px;font-weight:700;cursor:pointer;font-size:.9rem">
          Fermer
        </button>`;
    } else {
      throw new Error('Réponse invalide');
    }
  } catch(e) {
    content.innerHTML = `
      <div style="font-size:2.5rem;margin-bottom:16px">❌</div>
      <div style="font-size:1rem;font-weight:700;color:#f87171;margin-bottom:16px">Erreur lors de l'envoi</div>
      <button onclick="document.getElementById('modal-rapport').style.display='none'"
        style="background:var(--bg);color:var(--text);border:1px solid var(--border);border-radius:8px;padding:10px 28px;font-weight:700;cursor:pointer">
        Fermer
      </button>`;
  }
}

// Fermer en cliquant dehors
document.getElementById('modal-rapport').addEventListener('click', function(e) {
  if (e.target === this) this.style.display = 'none';
});
</script>

<!-- KPIs globaux -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;margin-bottom:24px">
  <?php foreach ([
    ['🏢', $total_sites,  'Sites actifs',    'ok'],
    ['👥', $total_users,  'Utilisateurs',    'ok'],
    ['💊', $total_prod,   'Produits',        'ok'],
    ['💀', $total_perime, 'Périmés',         $total_perime>0?'danger':'ok'],
    ['⚠️', $total_crit,   'Critiques (7j)',  $total_crit>0?'warning':'ok'],
    ['🔄', $total_remp,   'Rempotages/mois', 'ok'],
  ] as [$ico,$val,$lbl,$type]): ?>
  <div style="background:var(--bg2);border:1px solid <?= $type==='danger'?'rgba(239,68,68,.4)':($type==='warning'?'rgba(245,158,11,.4)':'var(--border)') ?>;border-radius:12px;padding:16px;text-align:center">
    <div style="font-size:1.5rem;margin-bottom:4px"><?= $ico ?></div>
    <div style="font-size:1.8rem;font-weight:800;color:<?= $type==='danger'?'#f87171':($type==='warning'?'#fbbf24':'var(--text)') ?>"><?= $val ?></div>
    <div style="font-size:.72rem;color:var(--text3);text-transform:uppercase;letter-spacing:.05em"><?= $lbl ?></div>
  </div>
  <?php endforeach; ?>
  <div style="background:var(--bg2);border:1px solid var(--border);border-radius:12px;padding:16px;text-align:center">
    <div style="font-size:1.5rem;margin-bottom:4px">🤖</div>
    <div style="font-size:1.8rem;font-weight:800"><?= number_format($cout_ia,2) ?>€</div>
    <div style="font-size:.72rem;color:var(--text3);text-transform:uppercase;letter-spacing:.05em">Coût IA/mois</div>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px">

  <!-- Sites critiques -->
  <div class="card">
    <div class="card-header"><span class="card-title">🚨 Sites les plus critiques</span></div>
    <?php if (empty($sites_crit)): ?>
    <div style="text-align:center;padding:2rem;color:var(--text3)">✅ Aucun site critique</div>
    <?php else: ?>
    <?php foreach ($sites_crit as $s): ?>
    <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 16px;border-bottom:1px solid var(--border)">
      <div>
        <div style="font-weight:600;font-size:.88rem"><?= htmlspecialchars($s['nom']) ?></div>
        <div style="font-size:.75rem;color:var(--text3)"><?= htmlspecialchars($s['org_nom']) ?></div>
      </div>
      <div style="display:flex;gap:6px">
        <?php if ($s['perime']>0): ?>
        <span style="background:rgba(239,68,68,.15);color:#f87171;padding:2px 8px;border-radius:20px;font-size:.75rem;font-weight:600"><?= $s['perime'] ?> périmés</span>
        <?php endif; ?>
        <?php if ($s['critique']>0): ?>
        <span style="background:rgba(245,158,11,.15);color:#fbbf24;padding:2px 8px;border-radius:20px;font-size:.75rem;font-weight:600"><?= $s['critique'] ?> critiques</span>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Par organisation -->
  <div class="card">
    <div class="card-header"><span class="card-title">🏢 Par organisation</span></div>
    <table style="width:100%;border-collapse:collapse">
      <thead><tr style="border-bottom:1px solid var(--border)">
        <th style="padding:8px 14px;text-align:left;font-size:.72rem;color:var(--text3);text-transform:uppercase">Organisation</th>
        <th style="padding:8px 14px;text-align:center;font-size:.72rem;color:var(--text3);text-transform:uppercase">Sites</th>
        <th style="padding:8px 14px;text-align:center;font-size:.72rem;color:var(--text3);text-transform:uppercase">Prod.</th>
        <th style="padding:8px 14px;text-align:center;font-size:.72rem;color:var(--text3);text-transform:uppercase">Périmés</th>
      </tr></thead>
      <tbody>
      <?php foreach ($orgs as $org): ?>
      <tr style="border-bottom:1px solid var(--border)">
        <td style="padding:8px 14px;font-weight:600;font-size:.88rem"><?= htmlspecialchars($org['nom']) ?></td>
        <td style="padding:8px 14px;text-align:center;font-size:.85rem"><?= $org['nb_sites'] ?></td>
        <td style="padding:8px 14px;text-align:center;font-size:.85rem"><?= $org['nb_prod'] ?></td>
        <td style="padding:8px 14px;text-align:center">
          <?php if ($org['nb_perime']>0): ?>
          <span style="background:rgba(239,68,68,.15);color:#f87171;padding:2px 8px;border-radius:20px;font-size:.75rem;font-weight:600"><?= $org['nb_perime'] ?></span>
          <?php else: ?>
          <span style="color:#34d399;font-size:.85rem">✅</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Activité récente globale -->
<div class="card">
  <div class="card-header"><span class="card-title">📋 Activité récente — tous sites</span></div>
  <table style="width:100%;border-collapse:collapse">
    <thead><tr style="border-bottom:1px solid var(--border)">
      <th style="padding:8px 14px;text-align:left;font-size:.72rem;color:var(--text3);text-transform:uppercase">Action</th>
      <th style="padding:8px 14px;text-align:left;font-size:.72rem;color:var(--text3);text-transform:uppercase">Agent</th>
      <th style="padding:8px 14px;text-align:left;font-size:.72rem;color:var(--text3);text-transform:uppercase">Site</th>
      <th style="padding:8px 14px;text-align:left;font-size:.72rem;color:var(--text3);text-transform:uppercase">Date</th>
    </tr></thead>
    <tbody>
    <?php foreach ($activite as $a): ?>
    <tr style="border-bottom:1px solid var(--border)">
      <td style="padding:8px 14px;font-size:.85rem;font-weight:600"><?= htmlspecialchars($a['action']) ?><?php if ($a['table_cible']): ?> <span style="font-size:.75rem;color:var(--text3);font-weight:400">(<?= htmlspecialchars($a['table_cible']) ?>)</span><?php endif; ?></td>
      <td style="padding:8px 14px;font-size:.82rem;color:var(--text2)"><?= htmlspecialchars($a['user_nom'] ?? '—') ?></td>
      <td style="padding:8px 14px;font-size:.82rem;color:var(--text2)"><?= htmlspecialchars($a['site_nom'] ?? 'Global') ?></td>
      <td style="padding:8px 14px;font-size:.8rem;color:var(--text3)"><?= date('d/m H:i', strtotime($a['cree_le'])) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once 'includes/footer.php'; ?>

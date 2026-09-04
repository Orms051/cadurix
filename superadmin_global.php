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
// (enveloppé dans une sous-requête : filtre en WHERE sur les alias → 100% portable MySQL/MariaDB)
$sites_crit = $pdo->query("
    SELECT * FROM (
        SELECT s.id, s.nom, o.nom AS org_nom,
               (SELECT COUNT(*) FROM produits p JOIN emplacements e ON e.id=p.emplacement_id WHERE e.site_id=s.id AND p.statut='actif' AND p.date_peremption<CURDATE()) AS perime,
               (SELECT COUNT(*) FROM produits p JOIN emplacements e ON e.id=p.emplacement_id WHERE e.site_id=s.id AND p.statut='actif' AND DATEDIFF(p.date_peremption,CURDATE()) BETWEEN 0 AND 7) AS critique,
               (SELECT COUNT(*) FROM utilisateurs u JOIN user_site_permissions usp ON usp.user_id=u.id WHERE usp.site_id=s.id AND u.derniere_connexion < DATE_SUB(NOW(), INTERVAL 30 DAY)) AS inactifs
        FROM sites s JOIN organisations o ON o.id=s.organisation_id
        WHERE s.actif=1
    ) t
    WHERE (perime + critique) > 0
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

$SAG_ACT = [
  'CREATION_PRODUIT'=>['➕','#10b981'],'MODIF_PRODUIT'=>['✏️','#3b82f6'],'MODIF_PEREMPTION'=>['📅','#f59e0b'],
  'RETRAIT_PRODUIT'=>['🗑️','#ef4444'],'SUPPRESSION_PRODUIT'=>['❌','#ef4444'],'ARCHIVAGE_PRODUIT'=>['📦','#94a3b8'],
  'RENOUVELLEMENT_LOT'=>['♻️','#10b981'],'REMPOTAGE'=>['🔄','#10b981'],
  'CREATION_EMPLACEMENT'=>['📍','#10b981'],'MODIF_EMPLACEMENT'=>['📍','#3b82f6'],
  'CREATION_MATERIEL'=>['🩺','#10b981'],'MODIF_MATERIEL'=>['🩺','#3b82f6'],'INTERVENTION_MATERIEL'=>['🔧','#f59e0b'],
  'CONNEXION_REUSSIE'=>['🔑','#94a3b8'],'DECONNEXION'=>['🚪','#94a3b8'],'FICHE_VALIDEE'=>['📋','#10b981'],
];
require_once 'includes/header.php';
?>
<style>
.sag-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:22px}
.sag-kpi{position:relative;display:flex;align-items:center;gap:12px;background:var(--bg2);border:1px solid var(--border);border-radius:13px;padding:12px 15px;overflow:hidden;box-shadow:0 1px 2px rgba(20,30,60,.04)}
.sag-kpi::before{content:"";position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--kc,var(--primary))}
.sag-kpi .ic{width:36px;height:36px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:1.05rem}
.sag-kpi .n{font-size:1.5rem;font-weight:800;line-height:1;font-variant-numeric:tabular-nums}
.sag-kpi .l{font-size:.68rem;color:var(--text3);font-weight:700;text-transform:uppercase;letter-spacing:.04em;margin-top:3px}
.sag-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px}
.sag-card{background:var(--bg2);border:1px solid var(--border);border-radius:16px;overflow:hidden;box-shadow:0 1px 2px rgba(20,30,60,.04)}
.sag-card-h{display:flex;align-items:center;gap:9px;padding:13px 16px;border-bottom:1px solid var(--border);font-weight:800;font-size:.9rem}
.sag-row{display:flex;align-items:center;gap:12px;padding:10px 16px;border-top:1px solid var(--border)}
.sag-row:first-of-type{border-top:none}
.sag-row .nm{font-weight:700;font-size:.86rem}
.sag-row .sub{font-size:.74rem;color:var(--text3);margin-top:1px}
.sag-badges{margin-left:auto;display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end}
.sag-b{padding:2px 9px;border-radius:20px;font-size:.72rem;font-weight:700}
.sag-b.red{background:rgba(239,68,68,.14);color:#ef4444}.sag-b.amb{background:rgba(245,158,11,.16);color:#b4700a}
.sag-tl{display:flex;flex-direction:column}
.sag-tl .ev{display:flex;align-items:center;gap:11px;padding:9px 16px;border-top:1px solid var(--border)}
.sag-tl .ev:first-child{border-top:none}
.sag-tl .evic{width:30px;height:30px;border-radius:9px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:.9rem}
.sag-tl .tt{flex:1;min-width:0;font-size:.83rem;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sag-tl .mt{font-size:.72rem;color:var(--text3);white-space:nowrap;flex-shrink:0}
.sag-tbl{width:100%;border-collapse:collapse}
.sag-tbl th{padding:8px 14px;text-align:left;font-size:.66rem;color:var(--text3);text-transform:uppercase;letter-spacing:.05em;font-weight:800;background:var(--bg3);border-bottom:1px solid var(--border)}
.sag-tbl td{padding:9px 14px;border-bottom:1px solid var(--border);font-size:.85rem}
.sag-tbl tr:last-child td{border-bottom:none}
@media(max-width:800px){.sag-grid{grid-template-columns:1fr}.sag-tl .mt{display:none}}
</style>
<div class="sag">
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
<div class="sag-kpis">
  <?php foreach ([
    ['🏢', $total_sites,  'Sites actifs',    '#3b82f6'],
    ['👥', $total_users,  'Utilisateurs',    '#8b5cf6'],
    ['💊', $total_prod,   'Produits',        '#3b82f6'],
    ['💀', $total_perime, 'Périmés',         $total_perime>0?'#ef4444':'#10b981'],
    ['⚠️', $total_crit,   'Critiques (7j)',  $total_crit>0?'#f59e0b':'#10b981'],
    ['🔄', $total_remp,   'Rempotages/mois', '#10b981'],
    ['🤖', number_format($cout_ia,2).'€', 'Coût IA/mois', '#8b5cf6'],
  ] as [$ico,$val,$lbl,$c]): ?>
  <div class="sag-kpi" style="--kc:<?= $c ?>">
    <span class="ic" style="background:<?= $c ?>1f;color:<?= $c ?>"><?= $ico ?></span>
    <div><div class="n" style="color:<?= $c ?>"><?= $val ?></div><div class="l"><?= $lbl ?></div></div>
  </div>
  <?php endforeach; ?>
</div>

<div class="sag-grid">

  <!-- Sites critiques -->
  <div class="sag-card">
    <div class="sag-card-h">🚨 Sites les plus critiques</div>
    <?php if (empty($sites_crit)): ?>
    <div style="text-align:center;padding:2rem;color:var(--text3)">✅ Aucun site critique</div>
    <?php else: foreach ($sites_crit as $s): ?>
    <div class="sag-row">
      <div>
        <div class="nm"><?= htmlspecialchars($s['nom']) ?></div>
        <div class="sub"><?= htmlspecialchars($s['org_nom']) ?><?= $s['inactifs']>0 ? ' · '.$s['inactifs'].' inactif'.($s['inactifs']>1?'s':'').' 30j' : '' ?></div>
      </div>
      <div class="sag-badges">
        <?php if ($s['perime']>0): ?><span class="sag-b red"><?= $s['perime'] ?> périmés</span><?php endif; ?>
        <?php if ($s['critique']>0): ?><span class="sag-b amb"><?= $s['critique'] ?> &lt; 7j</span><?php endif; ?>
      </div>
    </div>
    <?php endforeach; endif; ?>
  </div>

  <!-- Par organisation -->
  <div class="sag-card">
    <div class="sag-card-h">🏢 Par organisation</div>
    <table class="sag-tbl">
      <thead><tr><th>Organisation</th><th style="text-align:center">Sites</th><th style="text-align:center">Prod.</th><th style="text-align:center">Périmés</th></tr></thead>
      <tbody>
      <?php foreach ($orgs as $org): ?>
      <tr>
        <td style="font-weight:700"><?= htmlspecialchars($org['nom']) ?></td>
        <td style="text-align:center"><?= $org['nb_sites'] ?></td>
        <td style="text-align:center"><?= $org['nb_prod'] ?></td>
        <td style="text-align:center"><?php if ($org['nb_perime']>0): ?><span class="sag-b red"><?= $org['nb_perime'] ?></span><?php else: ?><span style="color:#10b981">✅</span><?php endif; ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Activité récente globale -->
<div class="sag-card">
  <div class="sag-card-h">📋 Activité récente — tous sites</div>
  <div class="sag-tl">
    <?php if (empty($activite)): ?>
    <div style="text-align:center;padding:2rem;color:var(--text3)">Aucune activité récente.</div>
    <?php else: foreach ($activite as $a): $m = $SAG_ACT[$a['action']] ?? ['•','#64748b']; $lbl = ucfirst(strtolower(str_replace('_',' ',$a['action']))); ?>
    <div class="ev">
      <span class="evic" style="background:<?= $m[1] ?>1f;color:<?= $m[1] ?>"><?= $m[0] ?></span>
      <span class="tt"><?= htmlspecialchars($lbl) ?><?php if ($a['table_cible']): ?> <span style="color:var(--text3);font-weight:500;font-size:.74rem">(<?= htmlspecialchars($a['table_cible']) ?>)</span><?php endif; ?></span>
      <span class="mt"><?= htmlspecialchars($a['user_nom'] ?? '—') ?> · <?= htmlspecialchars($a['site_nom'] ?? 'Global') ?> · <?= date('d/m H:i', strtotime($a['cree_le'])) ?></span>
    </div>
    <?php endforeach; endif; ?>
  </div>
</div>

</div><!-- /.sag -->

<?php require_once 'includes/footer.php'; ?>

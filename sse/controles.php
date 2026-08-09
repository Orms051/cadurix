<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.

require_once '../includes/auth.php';
$pageTitle = 'SSE — Contrôles';
$site_id   = site_actif();
if (!$site_id) { header('Location: /sse.php'); exit; }
ob_start();

$success = '';
$pre_eq = isset($_GET['equipement']) ? (int)$_GET['equipement'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $eq_id    = (int)($_POST['equipement_id']??0);
    $resultat = $_POST['resultat'] ?? 'ok';
    $obs      = trim($_POST['observations']??'')?:null;
    $actions  = trim($_POST['actions_menees']??'')?:null;
    $prochain = $_POST['prochain_ctrl']?:null;

    // Vérifier que l'équipement appartient au site
    $chk = $pdo->prepare("SELECT id,frequence_ctrl_jours FROM sse_equipements WHERE id=? AND site_id=? AND actif=1");
    $chk->execute([$eq_id,$site_id]);
    $eq_row = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$eq_row) { header('Location: /sse/controles.php'); exit; }

    $pdo->prepare("INSERT INTO sse_controles (equipement_id,site_id,operateur_id,resultat,notes,type_controle,prochain_controle) VALUES (?,?,?,?,?,?,?)")
        ->execute([$eq_id,$site_id,currentUserId(),$resultat,$obs,$actions,$prochain]);

    // Mettre à jour le prochain contrôle sur l'équipement
    if ($prochain) {
        $pdo->prepare("UPDATE sse_equipements SET date_prochain_ctrl=?,statut=? WHERE id=? AND site_id=?")
            ->execute([$prochain,$resultat==='hors_service'?'hs':'operationnel',$eq_id,$site_id]);
    } elseif ($eq_row['frequence_ctrl_jours']) {
        $next = date('Y-m-d', strtotime('+'.$eq_row['frequence_ctrl_jours'].'days'));
        $pdo->prepare("UPDATE sse_equipements SET date_prochain_ctrl=? WHERE id=? AND site_id=?")->execute([$next,$eq_id,$site_id]);
    }

    audit_log($pdo,'SSE_CONTROLE','sse_controles',(int)$pdo->lastInsertId());
    $success = 'Contrôle enregistré.';
}

$equipements = $pdo->prepare("SELECT e.id,e.nom,c.icone,e.statut FROM sse_equipements e LEFT JOIN sse_categories c ON c.id=e.categorie_id WHERE e.site_id=? AND e.actif=1 ORDER BY e.nom");
$equipements->execute([$site_id]);
$equipements = $equipements->fetchAll(PDO::FETCH_ASSOC);

$historique = $pdo->prepare("SELECT ct.*,e.nom AS eq_nom,CONCAT(u.prenom,' ',u.nom) AS agent FROM sse_controles ct JOIN sse_equipements e ON e.id=ct.equipement_id JOIN utilisateurs u ON u.id=ct.operateur_id WHERE ct.site_id=? ORDER BY ct.date_controle DESC LIMIT 50");
$historique->execute([$site_id]);
$historique = $historique->fetchAll(PDO::FETCH_ASSOC);

require_once '../includes/header.php';
if (!nav_peut($nav_perms, 'sse')) { ob_end_clean(); accessDenied(); exit; }
?>
<div class="page-header">
  <div class="page-title-group"><div class="page-title">📋 Contrôles SSE / NRBC</div></div>
  <div class="page-actions"><a href="/sse.php" class="btn btn-sm btn-ghost">← Dashboard</a></div>
</div>
<?php if ($success): ?><div class="flash flash-success">✅ <?= h($success) ?></div><?php endif; ?>

<div style="display:grid;grid-template-columns:400px 1fr;gap:20px">

  <!-- Formulaire contrôle -->
  <div class="card" style="padding:20px">
    <div style="font-weight:700;font-size:.95rem;margin-bottom:16px">✅ Nouveau contrôle</div>
    <form method="post">
      <?= csrf_field() ?>
      <div style="margin-bottom:12px">
        <label style="font-size:11px;font-weight:600;color:var(--text2);text-transform:uppercase;display:block;margin-bottom:4px">Équipement *</label>
        <select name="equipement_id" required style="width:100%;background:var(--glass);border:1px solid var(--border);color:var(--text);border-radius:8px;padding:8px 12px">
          <option value="">— Choisir un équipement —</option>
          <?php foreach ($equipements as $eq): ?>
          <option value="<?= $eq['id'] ?>" <?= $eq['id']===$pre_eq?'selected':'' ?>><?= h($eq['icone']??'⚙️'.' '.$eq['nom']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="margin-bottom:12px">
        <label style="font-size:11px;font-weight:600;color:var(--text2);text-transform:uppercase;display:block;margin-bottom:4px">Résultat *</label>
        <div style="display:flex;gap:8px">
          <?php foreach (['ok'=>['✅','OK','#34d399'],'anomalie'=>['⚠️','Anomalie','#fbbf24'],'hors_service'=>['❌','Hors service','#f87171']] as $k=>[$i,$l,$c]): ?>
          <label style="flex:1;text-align:center;cursor:pointer">
            <input type="radio" name="resultat" value="<?= $k ?>" <?= $k==='ok'?'checked':'' ?> hidden>
            <div style="padding:8px;border:2px solid var(--border);border-radius:8px;font-size:.8rem;font-weight:600;transition:.15s" onclick="this.parentElement.querySelector('input').checked=true;document.querySelectorAll('[name=resultat]').forEach(r=>r.parentElement.querySelector('div').style.borderColor='var(--border)');this.style.borderColor='<?= $c ?>'">
              <?= $i ?><br><?= $l ?>
            </div>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
      <div style="margin-bottom:12px">
        <label style="font-size:11px;font-weight:600;color:var(--text2);text-transform:uppercase;display:block;margin-bottom:4px">Observations</label>
        <textarea name="observations" rows="3" style="width:100%;background:var(--glass);border:1px solid var(--border);color:var(--text);border-radius:8px;padding:8px 12px;resize:vertical" placeholder="État général, anomalies constatées..."></textarea>
      </div>
      <div style="margin-bottom:12px">
        <label style="font-size:11px;font-weight:600;color:var(--text2);text-transform:uppercase;display:block;margin-bottom:4px">Actions menées</label>
        <textarea name="actions_menees" rows="2" style="width:100%;background:var(--glass);border:1px solid var(--border);color:var(--text);border-radius:8px;padding:8px 12px;resize:vertical" placeholder="Remplacement, réparation, nettoyage..."></textarea>
      </div>
      <div style="margin-bottom:16px">
        <label style="font-size:11px;font-weight:600;color:var(--text2);text-transform:uppercase;display:block;margin-bottom:4px">Prochain contrôle</label>
        <input type="date" name="prochain_ctrl" style="width:100%;background:var(--glass);border:1px solid var(--border);color:var(--text);border-radius:8px;padding:8px 12px">
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%">💾 Enregistrer le contrôle</button>
    </form>
  </div>

  <!-- Historique -->
  <div class="card">
    <div class="card-header"><span class="card-title">📜 Historique</span></div>
    <?php if (empty($historique)): ?>
    <div style="text-align:center;padding:2rem;color:var(--text3)">Aucun contrôle enregistré</div>
    <?php else: ?>
    <table style="width:100%;border-collapse:collapse">
      <thead><tr style="border-bottom:1px solid var(--border)">
        <th style="padding:10px 14px;text-align:left;font-size:.75rem;color:var(--text3);text-transform:uppercase">Équipement</th>
        <th style="padding:10px 14px;text-align:left;font-size:.75rem;color:var(--text3);text-transform:uppercase">Date</th>
        <th style="padding:10px 14px;text-align:left;font-size:.75rem;color:var(--text3);text-transform:uppercase">Agent</th>
        <th style="padding:10px 14px;text-align:left;font-size:.75rem;color:var(--text3);text-transform:uppercase">Résultat</th>
      </tr></thead>
      <tbody>
      <?php foreach ($historique as $ct): ?>
      <tr style="border-bottom:1px solid var(--border)">
        <td style="padding:10px 14px;font-weight:600;font-size:.88rem"><?= h($ct['eq_nom']) ?></td>
        <td style="padding:10px 14px;font-size:.85rem"><?= date('d/m/Y H:i',strtotime($ct['date_controle'])) ?></td>
        <td style="padding:10px 14px;font-size:.85rem;color:var(--text2)"><?= h($ct['agent']) ?></td>
        <td style="padding:10px 14px">
          <span style="padding:2px 10px;border-radius:20px;font-size:.72rem;font-weight:600;background:<?= $ct['resultat']=='ok'?'rgba(16,185,129,.15)':($ct['resultat']=='hors_service'?'rgba(239,68,68,.15)':'rgba(245,158,11,.15)') ?>;color:<?= $ct['resultat']=='ok'?'#34d399':($ct['resultat']=='hors_service'?'#f87171':'#fbbf24') ?>">
            <?= $ct['resultat']=='ok'?'✅ OK':($ct['resultat']=='hors_service'?'❌ Hors service':'⚠️ Anomalie') ?>
          </span>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>
<?php require_once '../includes/footer.php'; ?>

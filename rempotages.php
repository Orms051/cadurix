<?php
/**
 * Cadurix v5.2 — Historique des rempotages
 */
require_once 'includes/auth.php';
$pageTitle = 'Rempotages';
$site_id   = site_actif();
if (!$site_id) { header('Location: dashboard.php'); exit; }

// ── Suppression d'un rempotage (admin) : ANNULE l'effet sur le stock ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'supprimer' && isAdmin()) {
    csrf_check();
    $rid = (int)($_POST['rempotage_id'] ?? 0);
    $chk = $pdo->prepare("SELECT id FROM rempotages WHERE id=? AND site_id=?");
    $chk->execute([$rid, $site_id]);
    if ($chk->fetch()) {
        try {
            $pdo->beginTransaction();
            $arts = $pdo->prepare("SELECT produit_id, action, quantite FROM rempotage_articles WHERE rempotage_id=?");
            $arts->execute([$rid]);
            foreach ($arts->fetchAll(PDO::FETCH_ASSOC) as $a) {
                $pid = (int)$a['produit_id']; if (!$pid) continue;
                $q = (float)$a['quantite'];
                // On inverse : ce qui était 'retire' revient (+), ce qui était 'cree' repart (-)
                $delta = $a['action'] === 'retire' ? $q : ($a['action'] === 'cree' ? -$q : 0);
                if ($delta == 0) continue;
                $so = $pdo->prepare("SELECT quantite FROM produits WHERE id=?"); $so->execute([$pid]);
                $oq = (float)$so->fetchColumn(); $nq = max(0, $oq + $delta);
                $pdo->prepare("UPDATE produits SET quantite=? WHERE id=?")->execute([$nq, $pid]);
                $pdo->prepare("INSERT INTO mouvements_stock (produit_id,user_id,type,quantite_avant,quantite_delta,quantite_apres,motif)
                               VALUES (?,?,'ajustement',?,?,?,?)")
                    ->execute([$pid, currentUserId(), $oq, $delta, $nq, "Annulation rempotage #$rid"]);
            }
            $pdo->prepare("DELETE FROM rempotage_articles WHERE rempotage_id=?")->execute([$rid]);
            $pdo->prepare("DELETE FROM rempotages WHERE id=? AND site_id=?")->execute([$rid, $site_id]);
            $pdo->commit();
            if (function_exists('audit_log')) audit_log($pdo, 'REMPOTAGE_SUPPR', 'rempotages', $rid, null, null);
            flashSuccess("Rempotage supprimé — stock rétabli.");
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flashError("Erreur : " . $e->getMessage());
        }
    } else {
        flashError("Rempotage introuvable.");
    }
    header('Location: /rempotages.php'); exit;
}

// Filtres
$filtre_empl  = (int)($_GET['emplacement'] ?? 0);
$filtre_user  = (int)($_GET['user'] ?? 0);
$filtre_mois  = $_GET['mois'] ?? date('Y-m');

// Détail rempotage
$detail_id = (int)($_GET['id'] ?? 0);
$detail    = null;
$articles  = [];
if ($detail_id) {
    $s = $pdo->prepare("
        SELECT r.*, e.nom AS empl_nom, ep.nom AS empl_parent,
               u.prenom, u.nom AS user_nom
        FROM rempotages r
        LEFT JOIN emplacements e  ON e.id = r.emplacement_id
        LEFT JOIN emplacements ep ON ep.id = e.parent_id
        LEFT JOIN utilisateurs u  ON u.id = r.user_id
        WHERE r.id = ? AND r.site_id = ?
    ");
    $s->execute([$detail_id, $site_id]);
    $detail = $s->fetch(PDO::FETCH_ASSOC);

    if ($detail) {
        // Sécurité : admin ou auteur uniquement
        if (!isAdmin() && $detail['user_id'] !== currentUserId()) {
            $detail = null;
        } else {
            $a = $pdo->prepare("
                SELECT ra.*
                FROM rempotage_articles ra
                WHERE ra.rempotage_id = ?
            ");
            $a->execute([$detail_id]);
            $articles = $a->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}

// KPIs mois en cours
$debut_mois = date('Y-m-01');
$fin_mois   = date('Y-m-t');
$nb_mois    = (int)$pdo->query("SELECT COUNT(*) FROM rempotages WHERE site_id=$site_id AND debut_le >= '$debut_mois' AND debut_le <= '$fin_mois 23:59:59'")->fetchColumn();
$nb_produits_mois = (int)$pdo->query("SELECT COALESCE(SUM(ra.quantite),0) FROM rempotage_articles ra JOIN rempotages r ON r.id=ra.rempotage_id WHERE r.site_id=$site_id AND r.debut_le >= '$debut_mois'")->fetchColumn();

// Dernier rempotage
$dernier = $pdo->query("
    SELECT r.debut_le, e.nom AS empl_nom, u.prenom, u.nom AS user_nom
    FROM rempotages r
    LEFT JOIN emplacements e ON e.id=r.emplacement_id
    LEFT JOIN utilisateurs u ON u.id=r.user_id
    WHERE r.site_id=$site_id
    ORDER BY r.debut_le DESC LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

// Requête liste
$where  = "WHERE r.site_id = $site_id";
$params = [];
// Non-admin : voir seulement ses propres rempotages
if (!isAdmin()) {
    $where .= " AND r.user_id = " . (int)currentUserId();
}
if ($filtre_empl) { $where .= " AND r.emplacement_id = ?"; $params[] = $filtre_empl; }
if ($filtre_user && isAdmin()) { $where .= " AND r.user_id = ?"; $params[] = $filtre_user; }
if ($filtre_mois) {
    $debut = $filtre_mois . '-01';
    $fin   = date('Y-m-t', strtotime($debut));
    $where .= " AND r.debut_le >= ? AND r.debut_le <= ?";
    $params[] = $debut; $params[] = $fin . ' 23:59:59';
}

$stmt = $pdo->prepare("
    SELECT r.*,
           e.nom AS empl_nom, ep.nom AS empl_parent,
           u.prenom, u.nom AS user_nom,
           COUNT(ra.id) AS nb_articles,
           COALESCE(SUM(ra.quantite),0) AS total_produits
    FROM rempotages r
    LEFT JOIN emplacements e      ON e.id  = r.emplacement_id
    LEFT JOIN emplacements ep     ON ep.id = e.parent_id
    LEFT JOIN utilisateurs u      ON u.id  = r.user_id
    LEFT JOIN rempotage_articles ra ON ra.rempotage_id = r.id
    $where
    GROUP BY r.id
    ORDER BY r.debut_le DESC
    LIMIT 100
");
$stmt->execute($params);
$rempotages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Données filtres
$empls = $pdo->query("SELECT id, nom FROM emplacements WHERE site_id=$site_id AND actif=1 ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
$users = $pdo->query("SELECT DISTINCT u.id, u.prenom, u.nom FROM utilisateurs u JOIN rempotages r ON r.user_id=u.id WHERE r.site_id=$site_id ORDER BY u.nom")->fetchAll(PDO::FETCH_ASSOC);

$statuts = [
    'en_cours' => ['label'=>'En cours',  'color'=>'#f59e0b'],
    'valide'   => ['label'=>'Validé',    'color'=>'#10b981'],
    'annule'   => ['label'=>'Annulé',    'color'=>'#ef4444'],
];

require_once 'includes/header.php';
?>
<style>
.remp-row{display:flex;align-items:center;gap:.875rem;padding:.75rem 1rem;border-radius:10px;background:var(--glass);border:1px solid var(--border);margin-bottom:.5rem;transition:all .15s;text-decoration:none;color:var(--text)}
.remp-row:hover{border-color:var(--border2);transform:translateX(2px)}
.remp-row.active{border-color:var(--primary);background:rgba(59,130,246,.06)}
.remp-icon{width:42px;height:42px;border-radius:10px;background:rgba(59,130,246,.1);border:1px solid rgba(59,130,246,.2);display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0}
.detail-card{background:var(--glass);border:1px solid var(--border);border-radius:12px;padding:1.25rem;margin-bottom:.75rem}
.art-row{display:flex;align-items:center;gap:.75rem;padding:.6rem .875rem;border-radius:8px;background:rgba(255,255,255,.02);border:1px solid var(--border);margin-bottom:.35rem;font-size:.82rem}
</style>

<div class="page-header">
  <div class="page-title-group">
    <div class="page-title">🔄 Rempotages</div>
    <div class="page-sub">Historique et traçabilité</div>
  </div>
  <a href="/rempotage.php" class="btn btn-primary">➕ Nouveau rempotage</a>
</div>

<!-- KPIs -->
<div class="grid-4" style="margin-bottom:1.25rem">
  <div class="kpi-card" style="border-top:3px solid #3b82f6">
    <div class="kpi-label">Ce mois</div>
    <div class="kpi-value"><?= $nb_mois ?></div>
    <div class="kpi-foot">Rempotages</div>
  </div>
  <div class="kpi-card" style="border-top:3px solid #10b981">
    <div class="kpi-label">Produits rempotés</div>
    <div class="kpi-value"><?= $nb_produits_mois ?></div>
    <div class="kpi-foot">Ce mois</div>
  </div>
  <div class="kpi-card" style="border-top:3px solid #8b5cf6">
    <div class="kpi-label">Dernier rempotage</div>
    <div class="kpi-value" style="font-size:1.1rem"><?= $dernier ? date('d/m/Y', strtotime($dernier['debut_le'])) : '—' ?></div>
    <div class="kpi-foot"><?= $dernier ? h(($dernier['prenom']??'').' '.($dernier['user_nom']??'')) : '—' ?></div>
  </div>
  <div class="kpi-card" style="border-top:3px solid #f59e0b">
    <div class="kpi-label">Total historique</div>
    <div class="kpi-value"><?= count($rempotages) ?></div>
    <div class="kpi-foot">Rempotages</div>
  </div>
</div>

<!-- Filtres -->
<div class="card" style="margin-bottom:1rem;padding:.875rem 1rem">
  <form method="GET" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end">
    <div class="form-group" style="margin:0;min-width:140px">
      <label class="form-label">Mois</label>
      <input type="month" name="mois" class="form-control" value="<?= h($filtre_mois) ?>">
    </div>
    <div class="form-group" style="margin:0;min-width:160px">
      <label class="form-label">Emplacement</label>
      <select name="emplacement" class="form-control">
        <option value="">Tous</option>
        <?php foreach ($empls as $e): ?>
        <option value="<?= $e['id'] ?>" <?= $filtre_empl===$e['id']?'selected':'' ?>><?= h($e['nom']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if (isAdmin()): ?>
    <div class="form-group" style="margin:0;min-width:160px">
      <label class="form-label">Agent</label>
      <select name="user" class="form-control">
        <option value="">Tous</option>
        <?php foreach ($users as $u): ?>
        <option value="<?= $u['id'] ?>" <?= $filtre_user===$u['id']?'selected':'' ?>><?= h($u['prenom'].' '.$u['nom']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <button type="submit" class="btn btn-primary btn-sm">🔍 Filtrer</button>
    <a href="/rempotages.php" class="btn btn-ghost btn-sm">✕ Réinitialiser</a>
  </form>
</div>

<div class="grid-2" style="align-items:start">

  <!-- Liste rempotages -->
  <div>
    <?php if (empty($rempotages)): ?>
    <div class="card" style="text-align:center;padding:3rem;color:var(--text3)">
      <div style="font-size:3rem;margin-bottom:1rem">🔄</div>
      <div style="font-weight:700;margin-bottom:.5rem">Aucun rempotage</div>
      <div style="font-size:.85rem">sur cette période</div>
      <a href="/rempotage.php" class="btn btn-primary btn-sm" style="margin-top:1rem">➕ Nouveau rempotage</a>
    </div>
    <?php else: ?>
    <?php foreach ($rempotages as $r):
      $st = $statuts[$r['statut']] ?? ['label'=>$r['statut'],'color'=>'#64748b'];
      $isActive = $detail_id === (int)$r['id'];
    ?>
    <a href="/rempotages.php?id=<?= $r['id'] ?><?= $filtre_mois?"&mois=$filtre_mois":'' ?><?= $filtre_empl?"&emplacement=$filtre_empl":'' ?>" class="remp-row <?= $isActive?'active':'' ?>">
      <div class="remp-icon">🔄</div>
      <div style="flex:1;min-width:0">
        <div style="font-weight:700;font-size:.9rem">
          <?= $r['empl_parent'] ? h($r['empl_parent']).' › ' : '' ?><?= h($r['empl_nom'] ?? 'Emplacement inconnu') ?>
        </div>
        <div style="font-size:.75rem;color:var(--text3);margin-top:.2rem">
          <?= date('d/m/Y à H:i', strtotime($r['debut_le'])) ?>
          · <?= h(($r['prenom']??'').' '.($r['user_nom']??'')) ?>
        </div>
      </div>
      <div style="text-align:right;flex-shrink:0">
        <div style="font-size:.8rem;font-weight:700"><?= $r['nb_articles'] ?> produit(s)</div>
        <span style="font-size:.7rem;padding:.15rem .5rem;border-radius:99px;background:<?= $st['color'] ?>22;color:<?= $st['color'] ?>;border:1px solid <?= $st['color'] ?>44"><?= $st['label'] ?></span>
      </div>
    </a>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Détail -->
  <div>
    <?php if ($detail_id && !$detail): ?>
    <div class="card" style="text-align:center;padding:2rem;color:var(--text3)">
      <div style="font-size:2.5rem;margin-bottom:.75rem">🔒</div>
      <div style="font-weight:700;margin-bottom:.4rem">Accès non autorisé</div>
      <div style="font-size:.85rem">Vous ne pouvez consulter que vos propres rempotages.</div>
    </div>
    <?php elseif ($detail): ?>
    <div class="card" style="margin-bottom:1rem">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:1rem">
        <div>
          <div style="font-weight:800;font-size:1rem">
            🔄 <?= $detail['empl_parent'] ? h($detail['empl_parent']).' › ' : '' ?><?= h($detail['empl_nom'] ?? '—') ?>
          </div>
          <div style="font-size:.78rem;color:var(--text3);margin-top:.3rem">
            <?= date('d/m/Y à H:i', strtotime($detail['debut_le'])) ?>
            · <?= h(($detail['prenom']??'').' '.($detail['user_nom']??'')) ?>
          </div>
        </div>
        <?php $st = $statuts[$detail['statut']] ?? ['label'=>$detail['statut'],'color'=>'#64748b']; ?>
        <span style="padding:.3rem .75rem;border-radius:99px;font-size:.78rem;font-weight:700;background:<?= $st['color'] ?>22;color:<?= $st['color'] ?>;border:1px solid <?= $st['color'] ?>44">
          <?= $st['label'] ?>
        </span>
      </div>

      <!-- Articles -->
      <div style="font-size:.7rem;text-transform:uppercase;letter-spacing:1.5px;color:var(--text3);font-weight:700;margin-bottom:.5rem">
        <?= count($articles) ?> produit(s) rempoté(s)
      </div>

      <?php if (empty($articles)): ?>
      <p style="color:var(--text3);font-size:.85rem">Aucun article enregistré.</p>
      <?php else: ?>
      <?php foreach ($articles as $a): ?>
      <div class="art-row">
        <div style="flex:1;min-width:0">
          <div style="font-weight:600"><?= h($a['nom'] ?? 'Produit #'.$a['produit_id']) ?></div>
          <div style="font-size:.72rem;color:var(--text3);margin-top:.15rem">
            <?= $a['numero_lot'] ? 'Lot '.h($a['numero_lot']).' · ' : '' ?>
            <?= $a['date_peremption'] ? 'Exp. '.date('m/Y', strtotime($a['date_peremption'])) : 'Pas de date' ?>
          </div>
        </div>
        <div style="font-weight:700;font-size:.9rem;flex-shrink:0">
          +<?= number_format($a['quantite'],0) ?> <?= h($a['unite']??'unité') ?>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>

      <?php if ($detail['notes']): ?>
      <div style="margin-top:.875rem;padding:.75rem;background:rgba(255,255,255,.03);border-radius:8px;font-size:.82rem;color:var(--text2)">
        📝 <?= h($detail['notes']) ?>
      </div>
      <?php endif; ?>

      <?php if (isAdmin()): ?>
      <form method="post" onsubmit="return confirm('Supprimer ce rempotage ? Le stock sera remis à l\'état précédent.');" style="margin-top:1rem;text-align:right">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="supprimer">
        <input type="hidden" name="rempotage_id" value="<?= (int)$detail['id'] ?>">
        <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--red);border-color:var(--red)">🗑️ Supprimer (annule le stock)</button>
      </form>
      <?php endif; ?>
    </div>

    <?php else: ?>
    <div class="card" style="text-align:center;padding:3rem;color:var(--text3)">
      <div style="font-size:3rem;margin-bottom:1rem">👈</div>
      <div style="font-size:.875rem">Sélectionnez un rempotage<br>pour voir le détail</div>
    </div>
    <?php endif; ?>
  </div>

</div>

<?php require_once 'includes/footer.php'; ?>

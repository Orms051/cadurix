<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.

require_once 'includes/auth.php';

// ── AJAX handlers — avant tout output ────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'valider_rempotage') {
    header('Content-Type: application/json');
    try {
        $inter_id    = (int)($_POST['inter_id'] ?? 0);
        $site_id_req = site_actif();
        if ($inter_id && $site_id_req) {
            $stmt = $pdo->prepare("UPDATE interventions_materiel SET statut='termine', modifie_le=NOW() WHERE id=? AND site_id=?");
            $stmt->execute([$inter_id, $site_id_req]);
            echo json_encode(['ok' => true, 'rows' => $stmt->rowCount()]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'ID ou site manquant']);
        }
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

requireCanEdit();
$pageTitle = 'Matériel utilisé';
$site_id   = site_actif();

// ── Autocomplétion médicaments (AJAX) ────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search_med') {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) { echo '[]'; exit; }
    $stmt = $pdo->prepare("
        SELECT id, nom, dosage, unite
        FROM medicaments_ref
        WHERE nom LIKE ? OR dosage LIKE ?
        ORDER BY nom LIMIT 12
    ");
    $stmt->execute(["%$q%", "%$q%"]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ── Autocomplétion produits stock (AJAX) ─────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search_prod') {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) { echo '[]'; exit; }
    $stmt = $pdo->prepare("
        SELECT p.id, p.nom, p.lot, e.nom AS emplacement
        FROM produits p
        LEFT JOIN emplacements e ON e.id = p.emplacement_id
        WHERE p.site_id = ? AND p.nom LIKE ?
        ORDER BY p.nom LIMIT 10
    ");
    $stmt->execute([$site_id, "%$q%"]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ── Sauvegarder intervention (AJAX POST) ─────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_intervention') {
    csrf_check();
    $lignes = json_decode($_POST['lignes'] ?? '[]', true);
    $ref    = trim($_POST['reference'] ?? '');
    $notes  = trim($_POST['notes'] ?? '');

    if (empty($lignes)) {
        echo json_encode(['ok' => false, 'error' => 'Aucune ligne saisie.']); exit;
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("
            INSERT INTO interventions_materiel (site_id, user_id, reference, notes, statut)
            VALUES (?, ?, ?, ?, 'en_cours')
        ")->execute([$site_id, currentUserId(), $ref ?: null, $notes ?: null]);

        $inter_id = $pdo->lastInsertId();

        foreach ($lignes as $l) {
            $nom = trim($l['nom'] ?? '');
            $qty = max(0.1, (float)($l['quantite'] ?? 1));
            $unite = trim($l['unite'] ?? 'unité');
            $prod_id = (int)($l['produit_id'] ?? 0) ?: null;
            $empl_id = (int)($l['emplacement_id'] ?? 0) ?: null;
            if (!$nom) continue;

            $pdo->prepare("
                INSERT INTO interventions_lignes
                  (intervention_id, produit_id, nom, quantite, unite, emplacement_id)
                VALUES (?, ?, ?, ?, ?, ?)
            ")->execute([$inter_id, $prod_id, $nom, $qty, $unite, $empl_id]);
        }

        // ── Créer rempotages automatiques par emplacement ────
        // Grouper les lignes par emplacement
        $lignes_par_empl = [];
        foreach ($lignes as $l) {
            $nom     = trim($l['nom'] ?? '');
            $qty     = max(0.1, (float)($l['quantite'] ?? 1));
            $unite   = trim($l['unite'] ?? 'unité');
            $empl_id = (int)($l['emplacement_id'] ?? 0) ?: null;
            $prod_id = (int)($l['produit_id'] ?? 0) ?: null;
            if (!$nom) continue;
            $key = $empl_id ?? 0;
            $lignes_par_empl[$key][] = [
                'nom'     => $nom,
                'qty'     => $qty,
                'unite'   => $unite,
                'prod_id' => $prod_id,
                'empl_id' => $empl_id,
            ];
        }

        $remp_ids = [];
        foreach ($lignes_par_empl as $empl_id => $articles) {
            // Créer un rempotage par emplacement
            $pdo->prepare("
                INSERT INTO rempotages (emplacement_id, user_id, site_id, statut, notes)
                VALUES (?, ?, ?, 'en_cours', ?)
            ")->execute([
                $empl_id ?: null,
                currentUserId(),
                $site_id,
                $ref ? 'Intervention '.$ref : null
            ]);
            $remp_id = (int)$pdo->lastInsertId();
            $remp_ids[] = $remp_id;

            foreach ($articles as $a) {
                // Chercher le produit existant dans l'emplacement
                $prod_id = $a['prod_id'];
                if (!$prod_id && $empl_id) {
                    $ep = $pdo->prepare("SELECT id FROM produits WHERE emplacement_id=? AND LOWER(nom) LIKE ? AND statut='actif' LIMIT 1");
                    $ep->execute([$empl_id, '%'.strtolower($a['nom']).'%']);
                    $prod_id = $ep->fetchColumn() ?: null;
                }

                $pdo->prepare("
                    INSERT INTO rempotage_articles
                      (rempotage_id, produit_id, action, nom, quantite, unite)
                    VALUES (?, ?, 'retire', ?, ?, ?)
                ")->execute([$remp_id, $prod_id, $a['nom'], $a['qty'], $a['unite']]);

                // Décrémenter le stock si produit trouvé
                if ($prod_id) {
                    $ep2 = $pdo->prepare("SELECT quantite FROM produits WHERE id=?");
                    $ep2->execute([$prod_id]);
                    $old_qty = (float)$ep2->fetchColumn();
                    $new_qty = max(0, $old_qty - $a['qty']);
                    $pdo->prepare("UPDATE produits SET quantite=? WHERE id=?")->execute([$new_qty, $prod_id]);
                    $pdo->prepare("INSERT INTO mouvements_stock (produit_id,user_id,type,quantite_avant,quantite_delta,quantite_apres,motif) VALUES(?,?,'sortie',?,?,?,'Matériel utilisé intervention')")
                        ->execute([$prod_id, currentUserId(), $old_qty, -$a['qty'], $new_qty]);
                }
            }
        }

        $pdo->commit();
        audit_log($pdo, 'INTERVENTION_MATERIEL', 'interventions_materiel', $inter_id);
        // Retourner l'ID du premier rempotage créé pour la redirection
        echo json_encode(['ok' => true, 'id' => $inter_id, 'remp_id' => $remp_ids[0] ?? null]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── Générer rempotage depuis intervention ────────────────
if (isset($_GET['rempotage']) && is_numeric($_GET['rempotage'])) {
    $inter_id = (int)$_GET['rempotage'];
    $lignes = $pdo->prepare("
        SELECT il.*, e.nom AS empl_nom
        FROM interventions_lignes il
        LEFT JOIN emplacements e ON e.id = il.emplacement_id
        WHERE il.intervention_id = ?
        ORDER BY il.nom
    ");
    $lignes->execute([$inter_id]);
    $lignes_rempotage = $lignes->fetchAll(PDO::FETCH_ASSOC);

    $inter = $pdo->prepare("SELECT * FROM interventions_materiel WHERE id=? AND site_id=?");
    $inter->execute([$inter_id, $site_id]);
    $inter = $inter->fetch(PDO::FETCH_ASSOC);
}

// ── Historique ───────────────────────────────────────────
$historique = $pdo->prepare("
    SELECT im.*,
           CONCAT(u.prenom,' ',u.nom) AS user_nom,
           COUNT(il.id) AS nb_lignes
    FROM interventions_materiel im
    JOIN utilisateurs u ON u.id = im.user_id
    LEFT JOIN interventions_lignes il ON il.intervention_id = im.id
    WHERE im.site_id = ?
    GROUP BY im.id
    ORDER BY im.cree_le DESC
    LIMIT 20
");
$historique->execute([$site_id]);
$historique = $historique->fetchAll(PDO::FETCH_ASSOC);

// Emplacements pour saisie
$empls_raw = $pdo->prepare("
    SELECT e.id, e.nom, e.parent_id, p.nom AS parent_nom
    FROM emplacements e
    LEFT JOIN emplacements p ON p.id = e.parent_id
    WHERE e.site_id = ? AND e.actif = 1
    ORDER BY COALESCE(p.nom, e.nom), e.parent_id IS NULL, e.nom
");
$empls_raw->execute([$site_id]);
$empls_raw = $empls_raw->fetchAll(PDO::FETCH_ASSOC);
// Grouper par parent, garder uniquement les sous-emplacements (qui ont un parent)
$empls_grouped = [];
$empls = []; // flat list pour la compatibilité JS
foreach ($empls_raw as $e) {
    if ($e['parent_id']) {
        $parent = $e['parent_nom'] ?? 'Autres';
        $empls_grouped[$parent][] = $e;
        $empls[] = ['id' => $e['id'], 'nom' => $e['nom'], 'parent' => $parent];
    }
}
// Si aucun sous-emplacement, afficher tous
if (empty($empls)) {
    foreach ($empls_raw as $e) {
        $empls_grouped['Tous'][] = $e;
        $empls[] = ['id' => $e['id'], 'nom' => $e['nom'], 'parent' => ''];
    }
}

require_once 'includes/header.php';
?>

<style>
/* ── Saisie vocale ── */
@keyframes pulse-rec { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.5;transform:scale(1.3)} }
@keyframes wave {
  0%,100% { transform: scaleY(1); }
  50%      { transform: scaleY(2.5); }
}

.voice-panel {
  display: none;
  position: fixed;
  bottom: 24px; left: 50%; transform: translateX(-50%);
  z-index: 500;
  background: var(--bg2);
  border: 1px solid var(--primary);
  border-radius: 20px;
  padding: 20px 28px;
  box-shadow: 0 12px 40px rgba(0,0,0,.4), 0 0 0 1px var(--primary);
  width: 90%; max-width: 480px;
  text-align: center;
}
.voice-panel.active { display: block; animation: slideUp .2s ease; }
@keyframes slideUp { from{opacity:0;transform:translateX(-50%) translateY(20px)} to{opacity:1;transform:translateX(-50%) translateY(0)} }

.voice-waves {
  display: flex; align-items: center; justify-content: center;
  gap: 4px; height: 40px; margin: 12px 0;
}
.voice-wave {
  width: 4px; border-radius: 2px;
  background: var(--primary);
  animation: wave .8s ease-in-out infinite;
  min-height: 6px;
}
.voice-wave:nth-child(1){ animation-delay:0s;    height:20px; }
.voice-wave:nth-child(2){ animation-delay:.1s;   height:30px; }
.voice-wave:nth-child(3){ animation-delay:.2s;   height:40px; }
.voice-wave:nth-child(4){ animation-delay:.3s;   height:30px; }
.voice-wave:nth-child(5){ animation-delay:.4s;   height:20px; }
.voice-wave:nth-child(6){ animation-delay:.15s;  height:35px; }
.voice-wave:nth-child(7){ animation-delay:.25s;  height:25px; }

.voice-transcript {
  font-size: .85rem; color: var(--text2); min-height: 24px;
  padding: 8px 12px; background: var(--glass);
  border-radius: 8px; margin: 8px 0;
  font-style: italic;
}
.voice-status {
  font-size: .75rem; color: var(--text3); margin-bottom: 12px;
}

/* ── Saisie rapide ── */
.inter-wrap { display: grid; grid-template-columns: 1fr 380px; gap: 20px; align-items: flex-start; }
@media(max-width:900px){ .inter-wrap { grid-template-columns: 1fr; } }

.saisie-table { width: 100%; border-collapse: collapse; }
.saisie-table th {
  padding: 10px 12px; text-align: left; font-size: 11px; font-weight: 700;
  text-transform: uppercase; letter-spacing: 1.5px; color: var(--text3);
  border-bottom: 1px solid var(--border);
}
.saisie-table td { padding: 6px 6px; vertical-align: middle; }
.saisie-table tr:not(:last-child) td { border-bottom: 1px solid var(--border); }

/* Input dans tableau */
.td-input {
  width: 100%; background: var(--glass); border: 1px solid var(--border);
  border-radius: 7px; padding: 8px 10px; color: var(--text); font-size: 13px;
  transition: border-color .15s;
}
.td-input:focus { outline: none; border-color: var(--primary); background: var(--bg2); }

/* Autocomplete dropdown */
.ac-dropdown {
  position: absolute; top: calc(100% + 4px); left: 0; right: 0;
  background: var(--bg2); border: 1px solid var(--border); border-radius: 10px;
  box-shadow: 0 8px 24px rgba(0,0,0,.25); z-index: 200;
  max-height: 220px; overflow-y: auto; display: none;
}
.ac-item {
  padding: 9px 13px; cursor: pointer; font-size: 13px;
  border-bottom: 1px solid var(--border); transition: background .1s;
}
.ac-item:last-child { border-bottom: none; }
.ac-item:hover { background: var(--primary-soft); color: var(--primary); }
.ac-item strong { color: var(--text); }
.ac-item small { color: var(--text3); font-size: 11px; margin-left: 6px; }

/* Qty input */
.qty-input {
  width: 70px; text-align: center; background: var(--glass);
  border: 1px solid var(--border); border-radius: 7px; padding: 8px 6px;
  color: var(--text); font-size: 13px; font-weight: 600;
}
.qty-input:focus { outline: none; border-color: var(--primary); }

/* Bouton suppr ligne */
.btn-del-row {
  width: 28px; height: 28px; border-radius: 7px; border: 1px solid var(--border);
  background: transparent; color: var(--text3); cursor: pointer; font-size: 14px;
  display: flex; align-items: center; justify-content: center; transition: all .15s;
}
.btn-del-row:hover { background: rgba(239,68,68,.1); border-color: #ef4444; color: #ef4444; }

/* Panel rempotage */
.rempotage-panel {
  background: var(--glass); border: 1px solid var(--primary);
  border-radius: 14px; padding: 20px;
}
.rempotage-item {
  display: flex; align-items: center; gap: 12px;
  padding: 12px 14px; border-radius: 10px;
  background: var(--bg2); border: 1px solid var(--border);
  margin-bottom: 8px; transition: border-color .15s;
}
.rempotage-item:hover { border-color: var(--primary); }
.rempotage-check { width: 20px; height: 20px; cursor: pointer; accent-color: var(--primary); flex-shrink:0; }
.rempotage-item.done { opacity: .5; }
.rempotage-item.done .rempotage-nom { text-decoration: line-through; }

/* Historique cards */
.hist-card {
  display: flex; align-items: center; gap: 14px;
  padding: 12px 16px; border-radius: 10px;
  background: var(--glass); border: 1px solid var(--border);
  margin-bottom: 8px; cursor: pointer; transition: all .15s;
  text-decoration: none; color: var(--text);
}
.hist-card:hover { border-color: var(--primary); transform: translateX(2px); }
.hist-dot {
  width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0;
}

/* Mobile optimisé — bouton micro toujours visible */
@media(max-width:600px){
  #btn-micro span:not(#micro-dot) { display:none; }
  #btn-micro::before { content: '🎤'; }
}

/* Mobile optimisé */
@media(max-width:600px){
  .saisie-table thead { display: none; }
  .saisie-table tr { display: flex; flex-wrap: wrap; gap: 6px; padding: 10px 0; border-bottom: 1px solid var(--border); }
  .saisie-table td { display: block; padding: 2px; }
  .td-nom-cell { flex: 1; min-width: 200px; }
  .td-qty-cell { width: 80px; }
  .qty-input { width: 100%; }
}
</style>

<div class="page-header">
  <div class="page-title-group">
    <div class="page-title">🚑 Matériel utilisé</div>
    <div class="page-sub">Saisie rapide après intervention — génération rempotage automatique</div>
  </div>
  <div style="display:flex;gap:8px;align-items:center">
    <button class="btn btn-ghost" onclick="toggleHistorique()">📋 Historique</button>
    <button class="btn btn-ghost" id="btn-micro" onclick="toggleVoice()"
            title="Dicter plusieurs articles d'un coup"
            style="display:flex;align-items:center;gap:7px;position:relative">
      🎤 Saisie vocale
      <span id="micro-dot" style="width:8px;height:8px;border-radius:50%;background:#ef4444;display:none;animation:pulse-rec 1s infinite"></span>
    </button>
    <button class="btn btn-primary" onclick="soumettre()" id="btn-soumettre">
      ✅ Enregistrer & Rempotage
    </button>
  </div>
</div>

<?php $flash = getFlash(); if ($flash['success']): ?>
<div class="alert alert-success"><?= h($flash['success']) ?></div>
<?php endif; ?>

<!-- PANNEAU SAISIE VOCALE -->
<div id="voice-panel" class="voice-panel">
  <div style="font-weight:700;font-size:.95rem;margin-bottom:4px">🎤 Parlez maintenant</div>
  <div class="voice-status" id="voice-status">Dites les médicaments et quantités utilisés...</div>
  <div class="voice-waves" id="voice-waves">
    <div class="voice-wave"></div>
    <div class="voice-wave"></div>
    <div class="voice-wave"></div>
    <div class="voice-wave"></div>
    <div class="voice-wave"></div>
    <div class="voice-wave"></div>
    <div class="voice-wave"></div>
  </div>
  <div class="voice-transcript" id="voice-transcript">En attente...</div>
  <div style="display:flex;gap:8px;justify-content:center;margin-top:12px">
    <button class="btn btn-ghost btn-sm" onclick="stopVoice()">✕ Annuler</button>
    <button class="btn btn-primary btn-sm" id="btn-voice-confirm"
            onclick="confirmerVoice()" style="display:none">
      ✅ Ajouter ces lignes
    </button>
  </div>
</div>

<!-- Mode rempotage si on vient d'un historique -->
<?php if (isset($lignes_rempotage) && $inter): ?>
<div class="rempotage-panel" style="margin-bottom:24px">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
    <div>
      <div style="font-weight:700;font-size:1rem">📦 Fiche de rempotage</div>
      <div style="font-size:.78rem;color:var(--text2)">
        <?= $inter['reference'] ? 'Intervention '.$inter['reference'].' — ' : '' ?>
        <?= date('d/m/Y H:i', strtotime($inter['cree_le'])) ?>
      </div>
    </div>
    <a href="interventions.php" class="btn btn-ghost btn-sm">✕ Fermer</a>
  </div>
  <div id="rempotage-list">
    <?php foreach ($lignes_rempotage as $i => $l): ?>
    <div class="rempotage-item" id="ri-<?= $i ?>">
      <input type="checkbox" class="rempotage-check"
             onchange="marquerRempoté(<?= $i ?>, this.checked)">
      <div style="flex:1;min-width:0">
        <div class="rempotage-nom" style="font-weight:600;font-size:.9rem">
          <?= h($l['nom']) ?>
        </div>
        <div style="font-size:.75rem;color:var(--text2)">
          Qté : <strong><?= $l['quantite'] ?> <?= h($l['unite']) ?></strong>
          <?php if ($l['empl_nom']): ?>
          · 📍 <?= h($l['empl_nom']) ?>
          <?php else: ?>
          · <span style="color:var(--text3)">Emplacement non défini</span>
          <?php endif; ?>
        </div>
      </div>
      <span style="font-size:11px;padding:3px 9px;border-radius:20px;background:var(--glass);color:var(--text3)"
            id="ri-status-<?= $i ?>">À rempotter</span>
    </div>
    <?php endforeach; ?>
  </div>
  <div style="margin-top:16px;display:flex;gap:10px;align-items:center">
    <div id="rempotage-progress" style="flex:1;height:6px;background:var(--border);border-radius:3px;overflow:hidden">
      <div id="rempotage-bar" style="height:100%;width:0%;background:var(--primary);transition:width .4s;border-radius:3px"></div>
    </div>
    <span id="rempotage-count" style="font-size:.8rem;color:var(--text2);white-space:nowrap">0 / <?= count($lignes_rempotage) ?></span>
  </div>
  <div style="margin-top:14px;display:flex;gap:8px;justify-content:flex-end">
    <a href="interventions.php" class="btn btn-ghost btn-sm">✕ Fermer</a>
    <button class="btn btn-primary btn-sm" onclick="validerRempotage(<?= $inter['id'] ?>)" id="btn-valider-remp">
      ✅ Valider le rempotage
    </button>
  </div>
</div>
<?php endif; ?>

<div class="inter-wrap">

  <!-- SAISIE RAPIDE -->
  <div>
    <div class="card" style="padding:0;overflow:visible">
      <!-- En-tête optionnel -->
      <div style="padding:16px 20px;border-bottom:1px solid var(--border);display:flex;gap:12px;align-items:center;flex-wrap:wrap">
        <div style="flex:1;min-width:160px">
          <label class="form-label" style="margin-bottom:4px;font-size:11px">N° intervention <span style="color:var(--text3)">(optionnel)</span></label>
          <input type="text" id="ref-input" class="form-control" placeholder="Ex: 2024-0142"
                 style="font-size:13px;padding:7px 11px">
        </div>
        <div style="flex:2;min-width:200px">
          <label class="form-label" style="margin-bottom:4px;font-size:11px">Notes <span style="color:var(--text3)">(optionnel)</span></label>
          <input type="text" id="notes-input" class="form-control" placeholder="Ex: AVP route nationale"
                 style="font-size:13px;padding:7px 11px">
        </div>
      </div>

      <!-- Tableau saisie multiple -->
      <div style="padding:16px 20px">
        <table class="saisie-table" id="saisie-table">
          <thead>
            <tr>
              <th style="width:45%">Médicament / Matériel</th>
              <th style="width:15%">Quantité</th>
              <th style="width:20%">Unité</th>
              <th style="width:18%">Emplacement</th>
              <th style="width:2%"></th>
            </tr>
          </thead>
          <tbody id="saisie-tbody">
            <!-- Lignes générées par JS -->
          </tbody>
        </table>
        <button type="button" class="btn btn-ghost btn-sm" onclick="addRow()"
                style="margin-top:12px;width:100%;border-style:dashed">
          + Ajouter une ligne
        </button>
      </div>

      <div style="padding:12px 20px;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
        <span id="nb-lignes-label" style="font-size:.8rem;color:var(--text3)">0 ligne(s)</span>
        <div style="display:flex;gap:8px">
          <button class="btn btn-ghost btn-sm" onclick="clearAll()">🗑️ Tout effacer</button>
          <button class="btn btn-primary" onclick="soumettre()" id="btn-soumettre2">
            ✅ Enregistrer
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- HISTORIQUE LATÉRAL -->
  <div id="historique-panel">
    <div class="card">
      <div class="card-title" style="margin-bottom:14px">📋 Historique récent</div>
      <?php if (empty($historique)): ?>
      <div style="text-align:center;padding:24px;color:var(--text3);font-size:.82rem">
        Aucune intervention enregistrée
      </div>
      <?php else: ?>
      <?php foreach ($historique as $h): ?>
      <a href="?rempotage=<?= $h['id'] ?>" class="hist-card">
        <div class="hist-dot" style="background:<?= $h['statut']==='termine'?'#10B981':'var(--primary)' ?>"></div>
        <div style="flex:1;min-width:0">
          <div style="font-weight:600;font-size:.88rem">
            <?= $h['reference'] ? h($h['reference']) : 'Sans référence' ?>
          </div>
          <div style="font-size:.75rem;color:var(--text2)">
            <?= $h['nb_lignes'] ?> article(s) · <?= date('d/m/Y H:i', strtotime($h['cree_le'])) ?>
          </div>
          <div style="font-size:.72rem;color:var(--text3)">par <?= h($h['user_nom']) ?></div>
        </div>
        <span style="font-size:10px;padding:2px 8px;border-radius:20px;
          background:<?= $h['statut']==='termine'?'rgba(16,185,129,.12)':'rgba(245,158,11,.12)' ?>;
          color:<?= $h['statut']==='termine'?'#10B981':'#F59E0B' ?>">
          <?= $h['statut'] === 'termine' ? '✅ Rempoté' : '⏳ À rempotter' ?>
        </span>
      </a>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

</div>

<script>
var rowCount  = 0;
var empls     = <?= json_encode($empls) ?>;
var csrfToken = '<?= csrf_token() ?>';


// ══════════════════════════════════════════════════════════
// SAISIE VOCALE — Web Speech API + Claude Haiku
// ══════════════════════════════════════════════════════════
var recognition   = null;
var voiceActive   = false;
var voiceText     = '';
var voiceParsed   = [];

function toggleVoice() {
  if (voiceActive) { stopVoice(); return; }
  startVoice();
}

function startVoice() {
  // Vérifier support
  var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
  if (!SpeechRecognition) {
    alert('La reconnaissance vocale n\'est pas supportée sur ce navigateur.\nUtilisez Chrome ou Safari.');
    return;
  }

  voiceActive = true;
  voiceText   = '';
  voiceParsed = [];

  // UI
  document.getElementById('voice-panel').classList.add('active');
  document.getElementById('micro-dot').style.display = 'inline-block';
  document.getElementById('btn-micro').style.borderColor = '#ef4444';
  document.getElementById('btn-micro').style.color = '#ef4444';
  document.getElementById('voice-transcript').textContent = 'En attente de votre voix...';
  document.getElementById('voice-status').textContent = 'Dites les médicaments et quantités utilisés...';
  document.getElementById('btn-voice-confirm').style.display = 'none';

  recognition = new SpeechRecognition();
  recognition.lang = 'fr-FR';
  recognition.continuous = true;
  recognition.interimResults = true;

  recognition.onresult = function(event) {
    var interim = '';
    var final   = '';
    for (var i = event.resultIndex; i < event.results.length; i++) {
      if (event.results[i].isFinal) {
        final += event.results[i][0].transcript + ' ';
      } else {
        interim += event.results[i][0].transcript;
      }
    }
    if (final) voiceText += final;
    document.getElementById('voice-transcript').textContent =
      (voiceText + interim) || 'En écoute...';
  };

  recognition.onerror = function(e) {
    document.getElementById('voice-status').textContent = 'Erreur : ' + e.error;
    stopVoice();
  };

  recognition.onend = function() {
    if (voiceActive && voiceText.trim()) {
      // Arrêt naturel — interpréter avec Claude
      voiceActive = false;
      document.getElementById('micro-dot').style.display = 'none';
      interpreterAvecClaude(voiceText.trim());
    }
  };

  recognition.start();

  // Arrêt auto après 30 secondes
  setTimeout(function() {
    if (voiceActive) {
      recognition.stop();
    }
  }, 30000);
}

function stopVoice() {
  voiceActive = false;
  if (recognition) { try { recognition.stop(); } catch(e){} }
  document.getElementById('voice-panel').classList.remove('active');
  document.getElementById('micro-dot').style.display = 'none';
  document.getElementById('btn-micro').style.borderColor = '';
  document.getElementById('btn-micro').style.color = '';
}

function interpreterAvecClaude(texte) {
  document.getElementById('voice-status').textContent = '⏳ Analyse en cours...';
  document.getElementById('voice-waves').style.opacity = '.4';

  var prompt = "Tu es un assistant médical pour les secouristes SDIS. " +
    "Extrait de ce texte dicté la liste des médicaments/matériels utilisés avec leurs quantités et unités. " +
    "Réponds UNIQUEMENT en JSON valide, tableau d'objets avec les clés: nom, quantite (nombre), unite (string: unité/mg/ml/g/cp/amp/L). " +
    "Texte dicté: \"" + texte + "\"\n\n" +
    "Exemples de mapping:\n" +
    "- \"paracétamol un gramme deux comprimés\" → {nom:\"Paracétamol 1g\",quantite:2,unite:\"cp\"}\n" +
    "- \"sérum phy cinq cents ml\" → {nom:\"Sérum physiologique\",quantite:500,unite:\"ml\"}\n" +
    "- \"deux ampoules de morphine\" → {nom:\"Morphine\",quantite:2,unite:\"amp\"}\n" +
    "Réponds uniquement avec le JSON, sans texte autour, sans backticks.";

  fetch('https://api.anthropic.com/v1/messages', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      model: 'claude-haiku-4-5-20251001',
      max_tokens: 500,
      messages: [{ role: 'user', content: prompt }]
    })
  })
  .then(function(r) { return r.json(); })
  .then(function(data) {
    var txt = (data.content && data.content[0] && data.content[0].text) || '[]';
    try {
      // Nettoyer si Claude a mis des backticks malgré tout
      txt = txt.replace(/```json|```/g, '').trim();
      voiceParsed = JSON.parse(txt);
      if (!Array.isArray(voiceParsed)) voiceParsed = [voiceParsed];
      afficherPreviewVoice();
    } catch(e) {
      document.getElementById('voice-status').textContent = '❌ Impossible d\'interpréter. Réessayez.';
      document.getElementById('btn-voice-confirm').style.display = 'none';
    }
  })
  .catch(function() {
    // Fallback si pas d'API key : parsing basique local
    voiceParsed = parseVoiceLocal(texte);
    afficherPreviewVoice();
  });
}

// Fallback parsing local sans API (regex simple)
function parseVoiceLocal(texte) {
  var results = [];
  // Pattern : "X [quantité] [unité]" ou "[quantité] [unité] de/d' X"
  var unites = { 'mg':['mg','milligramme','milligrammes'],
                 'ml':['ml','millilitre','millilitres'],
                 'g':['gramme','grammes'],
                 'cp':['comprimé','comprimés','cp','cachet','cachets'],
                 'amp':['ampoule','ampoules','amp'],
                 'L':['litre','litres','l'],
                 'unité':['unité','unités','flacon','flacons','sachet','sachets','tube','tubes'] };

  // Découper par virgule, "et", "plus"
  var parties = texte.split(/,|\.|et|plus/i);
  parties.forEach(function(p) {
    p = p.trim();
    if (!p) return;
    var qty = 1;
    var unite = 'unité';
    var nom = p;

    // Chercher nombre
    var numMatch = p.match(/(\d+(?:[,.]\d+)?)/);
    if (numMatch) {
      qty = parseFloat(numMatch[1].replace(',','.'));
      nom = nom.replace(numMatch[0], '').trim();
    }

    // Chercher unité
    for (var u in unites) {
      unites[u].forEach(function(syn) {
        var re = new RegExp('\\b' + syn + '\\b', 'i');
        if (re.test(nom)) {
          unite = u;
          nom = nom.replace(re, '').trim();
        }
      });
    }

    // Nettoyer "de", "d'", articles
    nom = nom.replace(/^(de|d\'|du|des|un|une|le|la|les)\s+/i, '').trim();
    nom = nom.replace(/\s+(de|d\')\s*$/i, '').trim();

    if (nom.length > 2) {
      // Capitaliser
      nom = nom.charAt(0).toUpperCase() + nom.slice(1).toLowerCase();
      results.push({ nom: nom, quantite: qty, unite: unite });
    }
  });
  return results.length ? results : [{ nom: texte, quantite: 1, unite: 'unité' }];
}

function afficherPreviewVoice() {
  if (!voiceParsed.length) {
    document.getElementById('voice-status').textContent = '❌ Rien détecté. Réessayez.';
    return;
  }

  var preview = voiceParsed.map(function(l) {
    return '• ' + l.nom + ' — ' + l.quantite + ' ' + l.unite;
  }).join('\n');

  document.getElementById('voice-transcript').textContent = preview;
  document.getElementById('voice-status').textContent =
    '✅ ' + voiceParsed.length + ' article(s) détecté(s) — vérifiez et confirmez';
  document.getElementById('btn-voice-confirm').style.display = 'inline-flex';
  document.getElementById('voice-waves').style.opacity = '1';
}

function confirmerVoice() {
  voiceParsed.forEach(function(l) {
    addRow(l.nom, l.quantite, l.unite, null);
  });
  stopVoice();
}
// ══════════════════════════════════════════════════════════

// Emplacements options HTML avec groupes
var emplOptsHtml = '<option value="">— Emplacement —</option>';
var currentGroup = '';
empls.forEach(function(e) {
  if (e.parent && e.parent !== currentGroup) {
    if (currentGroup) emplOptsHtml += '</optgroup>';
    emplOptsHtml += '<optgroup label="' + escHtml(e.parent) + '">';
    currentGroup = e.parent;
  }
  emplOptsHtml += '<option value="' + e.id + '">' + escHtml(e.nom) + '</option>';
});
if (currentGroup) emplOptsHtml += '</optgroup>';

function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function addRow(nom, qty, unite, emplId) {
  var i = rowCount++;
  var tr = document.createElement('tr');
  tr.id = 'row-' + i;
  tr.innerHTML =
    '<td class="td-nom-cell" style="position:relative">' +
      '<input type="text" class="td-input" id="nom-' + i + '" placeholder="Nom médicament / matériel..."' +
             ' oninput="acSearch(' + i + ', this.value)" autocomplete="off"' +
             ' value="' + escHtml(nom || '') + '">' +
      '<div class="ac-dropdown" id="ac-' + i + '"></div>' +
    '</td>' +
    '<td class="td-qty-cell">' +
      '<input type="number" class="qty-input" id="qty-' + i + '" value="' + (qty || 1) + '" min="0.1" step="0.5">' +
    '</td>' +
    '<td>' +
      '<select class="td-input" id="unite-' + i + '" style="padding:8px 6px">' +
        '<option value="unité"' + ((!unite||unite==="unité")?'" selected':'') + '>unité</option>' +
        '<option value="mg"'   + (unite==="mg"   ?' selected':'') + '>mg</option>' +
        '<option value="ml"'   + (unite==="ml"   ?' selected':'') + '>ml</option>' +
        '<option value="g"'    + (unite==="g"    ?' selected':'') + '>g</option>' +
        '<option value="cp"'   + (unite==="cp"   ?' selected':'') + '>cp</option>' +
        '<option value="amp"'  + (unite==="amp"  ?' selected':'') + '>amp</option>' +
        '<option value="L"'    + (unite==="L"    ?' selected':'') + '>L</option>' +
      '</select>' +
    '</td>' +
    '<td>' +
      '<select class="td-input" id="empl-' + i + '" style="padding:8px 6px">' +
        emplOptsHtml +
      '</select>' +
    '</td>' +
    '<td>' +
      '<button type="button" class="btn-del-row" onclick="delRow(' + i + ')" title="Supprimer">✕</button>' +
    '</td>';

  document.getElementById('saisie-tbody').appendChild(tr);

  // Sélectionner l'emplacement si fourni
  if (emplId) {
    var sel = document.getElementById('empl-' + i);
    if (sel) sel.value = emplId;
  }

  updateCount();
  document.getElementById('nom-' + i).focus();
}

function delRow(i) {
  var tr = document.getElementById('row-' + i);
  if (tr) tr.remove();
  updateCount();
}

function clearAll() {
  if (!confirm('Effacer toutes les lignes ?')) return;
  document.getElementById('saisie-tbody').innerHTML = '';
  rowCount = 0;
  updateCount();
}

function updateCount() {
  var n = document.getElementById('saisie-tbody').children.length;
  document.getElementById('nb-lignes-label').textContent = n + ' ligne(s)';
}

// Autocomplétion
var acTimers = {};
function acSearch(i, q) {
  clearTimeout(acTimers[i]);
  var list = document.getElementById('ac-' + i);
  if (q.length < 2) { list.style.display = 'none'; return; }
  acTimers[i] = setTimeout(function() {
    fetch('interventions.php?ajax=search_med&q=' + encodeURIComponent(q))
      .then(function(r){ return r.json(); })
      .then(function(data) {
        if (!data.length) { list.style.display = 'none'; return; }
        list.innerHTML = data.map(function(d) {
          var dosage = d.dosage ? '<small>' + escHtml(d.dosage) + '</small>' : '';
          return '<div class="ac-item" data-nom="' + escHtml(d.nom) + '" data-unite="' + escHtml(d.unite||'unité') + '">'
               + '<strong>' + escHtml(d.nom) + '</strong>' + dosage + '</div>';
        }).join('');
        Array.from(list.children).forEach(function(el) {
          el.addEventListener('mousedown', function() {
            document.getElementById('nom-' + i).value = el.dataset.nom;
            var uniteEl = document.getElementById('unite-' + i);
            if (uniteEl && el.dataset.unite) uniteEl.value = el.dataset.unite;
            list.style.display = 'none';
            var qtyEl = document.getElementById('qty-' + i);
            if (qtyEl) qtyEl.focus();
          });
        });
        list.style.display = 'block';
      });
  }, 220);
}

// Fermer dropdown au clic extérieur
document.addEventListener('click', function(e) {
  document.querySelectorAll('.ac-dropdown').forEach(function(d) {
    if (!d.contains(e.target)) d.style.display = 'none';
  });
});

// Soumettre
function soumettre() {
  var rows  = document.getElementById('saisie-tbody').children;
  var lignes = [];
  for (var r = 0; r < rows.length; r++) {
    var i   = rows[r].id.replace('row-','');
    var nom = (document.getElementById('nom-' + i)?.value || '').trim();
    var qty = parseFloat(document.getElementById('qty-' + i)?.value || 1);
    var unite  = document.getElementById('unite-' + i)?.value || 'unité';
    var emplId = document.getElementById('empl-' + i)?.value || '';
    if (!nom) continue;
    lignes.push({ nom: nom, quantite: qty, unite: unite, emplacement_id: emplId });
  }
  if (!lignes.length) { alert('Ajoutez au moins une ligne.'); return; }

  var btn = document.getElementById('btn-soumettre');
  var btn2 = document.getElementById('btn-soumettre2');
  if (btn)  { btn.disabled = true;  btn.textContent  = '⏳ Enregistrement...'; }
  if (btn2) { btn2.disabled = true; btn2.textContent = '⏳ Enregistrement...'; }

  fetch('interventions.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'action=save_intervention'
        + '&lignes=' + encodeURIComponent(JSON.stringify(lignes))
        + '&reference=' + encodeURIComponent(document.getElementById('ref-input')?.value || '')
        + '&notes='     + encodeURIComponent(document.getElementById('notes-input')?.value || '')
        + '&csrf_token=' + encodeURIComponent(csrfToken)
  })
  .then(function(r){ return r.json(); })
  .then(function(data) {
    if (data.ok) {
      // Rediriger vers le rempotage créé ou la fiche intervention
      if (data.remp_id) {
        window.location.href = 'rempotages.php?id=' + data.remp_id;
      } else {
        window.location.href = 'interventions.php?rempotage=' + data.id;
      }
    } else {
      alert('Erreur : ' + (data.error || 'Inconnue'));
      if (btn)  { btn.disabled = false;  btn.textContent  = '✅ Enregistrer & Rempotage'; }
      if (btn2) { btn2.disabled = false; btn2.textContent = '✅ Enregistrer'; }
    }
  });
}

// Rempotage interactif
var totalItems = <?= isset($lignes_rempotage) ? count($lignes_rempotage) : 0 ?>;
var doneItems  = 0;
function marquerRempoté(i, checked) {
  var item   = document.getElementById('ri-' + i);
  var status = document.getElementById('ri-status-' + i);
  if (checked) {
    item.classList.add('done');
    status.textContent = '✅ Rempoté';
    status.style.color = '#10B981';
    doneItems++;
  } else {
    item.classList.remove('done');
    status.textContent = 'À rempotter';
    status.style.color = 'var(--text3)';
    doneItems--;
  }
  var pct = totalItems ? Math.round(doneItems/totalItems*100) : 0;
  document.getElementById('rempotage-bar').style.width = pct + '%';
  document.getElementById('rempotage-count').textContent = doneItems + ' / ' + totalItems;
}

// Valider le rempotage
function validerRempotage(interId) {
  var btn = document.getElementById('btn-valider-remp');
  if (btn) { btn.disabled = true; btn.textContent = '⏳ Validation...'; }
  fetch('interventions.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'action=valider_rempotage&inter_id=' + interId + '&csrf_token=' + encodeURIComponent(csrfToken)
  })
  .then(function(r){ return r.json(); })
  .then(function(data) {
    if (data.ok) {
      window.location.href = 'interventions.php';
    } else {
      alert('Erreur : ' + (data.error || 'Inconnue'));
      if (btn) { btn.disabled = false; btn.textContent = '✅ Valider le rempotage'; }
    }
  });
}

// Toggle historique sur mobile
function toggleHistorique() {
  var p = document.getElementById('historique-panel');
  p.style.display = p.style.display === 'none' ? '' : 'none';
}

// Ajouter 3 lignes vides au démarrage
addRow(); addRow(); addRow();
</script>

<?php require_once 'includes/footer.php'; ?>

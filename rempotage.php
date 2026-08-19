<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.

/**
 * Cadurix — Rempotage (module unifié) — ÉTAPE 1 : socle
 * Mobile-first ET responsive ordinateur.
 * On déclare ce qu'on REMET (produit + nouveau lot + péremption + quantité).
 * Enregistrement FEFO : le neuf entre, une quantité équivalente sort du lot le plus
 * proche de la péremption → quantité constante, péremptions à jour.
 * (L'écran « lots retirés Oui/Non » viendra en Étape 2, par-dessus ce défaut FEFO.)
 * terrain.php n'est PAS touché ; scanner rebranché plus tard comme composant.
 */

require_once 'includes/auth.php';
$pageTitle = 'Rempotage';
$site_id = site_actif();
if (!$site_id) { header('Location: /dashboard.php'); exit; }

/* ── AJAX : produits d'un sac (et de ses sous-emplacements) ── */
if (($_GET['ajax'] ?? '') === 'sac_produits') {
    header('Content-Type: application/json');
    $empl = (int)($_GET['emplacement'] ?? 0);
    if (!$empl) { echo json_encode([]); exit; }
    $ids = [$empl]; $frontier = [$empl]; $guard = 0;
    while ($frontier && $guard++ < 12) {
        $in = implode(',', array_map('intval', $frontier));
        $q = $pdo->prepare("SELECT id FROM emplacements WHERE site_id=? AND parent_id IN ($in)");
        $q->execute([$site_id]);
        $children = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
        $frontier = array_values(array_diff($children, $ids));
        $ids = array_merge($ids, $frontier);
    }
    $inIds = implode(',', array_map('intval', $ids));
    $st = $pdo->prepare("SELECT p.id, p.nom, p.quantite, p.unite, p.numero_lot, p.date_peremption,
                                p.emplacement_id, e.nom AS emplacement
                         FROM produits p JOIN emplacements e ON e.id=p.emplacement_id
                         WHERE p.emplacement_id IN ($inIds) AND p.statut='actif'
                         ORDER BY p.nom, p.date_peremption");
    $st->execute();
    echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

/* ── AJAX : stock de la PUI du site (LECTURE SEULE — étape 2a) ──
   Renvoie, par nom de produit, le stock total disponible dans la PUI du site
   (emplacement type='pui' + ses sous-emplacements). Aucune écriture. */
if (($_GET['ajax'] ?? '') === 'pui_stock') {
    header('Content-Type: application/json');
    // PUI(s) du site
    $puis = $pdo->prepare("SELECT id FROM emplacements WHERE site_id=? AND type='pui' AND actif=1");
    $puis->execute([$site_id]);
    $ids = array_map('intval', $puis->fetchAll(PDO::FETCH_COLUMN));
    if (!$ids) { echo json_encode(['pui'=>false, 'stock'=>[]]); exit; }

    // Descendants (mêmes bornes que sac_produits)
    $frontier = $ids; $guard = 0;
    while ($frontier && $guard++ < 12) {
        $in = implode(',', array_map('intval', $frontier));
        $q = $pdo->prepare("SELECT id FROM emplacements WHERE site_id=? AND parent_id IN ($in)");
        $q->execute([$site_id]);
        $children = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
        $frontier = array_values(array_diff($children, $ids));
        $ids = array_merge($ids, $frontier);
    }
    $inIds = implode(',', array_map('intval', $ids));
    $st = $pdo->query("SELECT nom,
                              SUM(quantite) AS stock,
                              COUNT(*) AS lots,
                              MIN(unite) AS unite
                       FROM produits
                       WHERE emplacement_id IN ($inIds) AND statut='actif' AND quantite > 0
                       GROUP BY nom");
    $map = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $map[$r['nom']] = [
            'stock' => (float)$r['stock'],
            'lots'  => (int)$r['lots'],
            'unite' => $r['unite'],
            'fefo'  => null,
        ];
    }
    // Lot FEFO (le plus proche de la péremption) par produit → pré-remplissage lot/date
    $fe = $pdo->query("SELECT nom, numero_lot, date_peremption FROM produits
                       WHERE emplacement_id IN ($inIds) AND statut='actif' AND quantite > 0
                       ORDER BY (date_peremption IS NULL), date_peremption ASC, id ASC");
    foreach ($fe->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (isset($map[$r['nom']]) && $map[$r['nom']]['fefo'] === null) {
            $map[$r['nom']]['fefo'] = ['lot' => $r['numero_lot'], 'exp' => $r['date_peremption']];
        }
    }
    echo json_encode(['pui'=>true, 'stock'=>$map]);
    exit;
}

/* ── AJAX : lookup code-barres (composant scanner partagé, jamais modifié) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'lookup_barcode') {
    header('Content-Type: application/json');
    require_once __DIR__ . '/scanner/barcode_lookup.php';
    $code = trim($_POST['code'] ?? '');
    if ($code === '') { echo json_encode(['found'=>false]); exit; }
    $res = barcode_lookup($pdo, $code, (int)$site_id);
    if (($res['source'] ?? '') === 'ref') {
        echo json_encode(['found'=>true,'nom'=>$res['nom'],'lot'=>$res['lot'],'date_exp'=>$res['date_exp']]); exit;
    }
    if (($res['source'] ?? '') === 'stock' && !empty($res['produit'])) {
        $prod = $res['produit']; unset($prod['numero_lot']);
        echo json_encode(array_merge(['found'=>true], $prod, ['lot'=>$res['lot'],'lot_scan'=>$res['lot'],'date_exp'=>$res['date_exp']])); exit;
    }
    echo json_encode(['found'=>false,'code'=>$code,'lot'=>$res['lot'] ?? '','date_exp'=>$res['date_exp'] ?? '','ean'=>$res['ean'] ?? '']); exit;
}

/* ── Enregistrement (FEFO) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'enregistrer') {
    csrf_check();
    $empl_id  = (int)($_POST['emplacement'] ?? 0);
    $ref      = trim($_POST['reference'] ?? '');
    $notes    = trim($_POST['notes'] ?? '');
    $articles = json_decode($_POST['articles'] ?? '[]', true);
    if (!is_array($articles)) $articles = [];
    $articles = array_values(array_filter($articles, fn($a) => trim($a['nom'] ?? '') !== '' && (float)($a['quantite'] ?? 0) > 0));

    if (!$empl_id)        { flashError("Choisissez un sac / emplacement."); header('Location: /rempotage.php'); exit; }
    if (empty($articles)) { flashError("Ajoutez au moins un produit remis."); header('Location: /rempotage.php'); exit; }

    try {
        $pdo->beginTransaction();
        $note_final = trim(($ref ? "Intervention $ref" : '') . ($notes ? ($ref ? ' — ' : '') . $notes : ''));
        $pdo->prepare("INSERT INTO rempotages (emplacement_id, user_id, site_id, statut, notes, valide_le)
                       VALUES (?, ?, ?, 'valide', ?, NOW())")
            ->execute([$empl_id, currentUserId(), $site_id, $note_final ?: null]);
        $remp_id = (int)$pdo->lastInsertId();

        $nb_crees = 0; $nb_retires = 0;

        // ── PUI du site (étape 2b) : le rempotage débite la PUI du même site ──
        //    Débit UNIQUEMENT si une PUI existe. Si le rempotage vise la PUI
        //    elle-même, c'est une réappro (simple ajout) → aucun débit.
        $pui_ids        = [];
        $pui_shortfalls = [];   // règle « autoriser + signaler » : manques non bloquants
        $pui_debits     = 0;
        $puis = $pdo->prepare("SELECT id FROM emplacements WHERE site_id=? AND type='pui' AND actif=1");
        $puis->execute([$site_id]);
        $pui_ids = array_map('intval', $puis->fetchAll(PDO::FETCH_COLUMN));
        if ($pui_ids) {
            $frontier = $pui_ids; $guard = 0;
            while ($frontier && $guard++ < 12) {
                $in = implode(',', array_map('intval', $frontier));
                $q = $pdo->prepare("SELECT id FROM emplacements WHERE site_id=? AND parent_id IN ($in)");
                $q->execute([$site_id]);
                $children = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
                $frontier = array_values(array_diff($children, $pui_ids));
                $pui_ids  = array_merge($pui_ids, $frontier);
            }
        }
        $do_pui_debit = $pui_ids && !in_array($empl_id, $pui_ids, true);

        foreach ($articles as $a) {
            $nom  = trim($a['nom']);
            $unit = $a['unite'] ?? 'unité';
            $qty  = max(0.01, (float)$a['quantite']);
            $lot  = trim($a['numero_lot'] ?? '') ?: null;
            $exp  = trim($a['date_peremption'] ?? '');
            $exp  = $exp ? (strlen($exp) === 7 ? $exp . '-01' : $exp) : '2099-12-31'; // YYYY-MM → YYYY-MM-01
            $empl_line = (int)($a['emplacement_id'] ?? 0) ?: $empl_id;

            // 1) FEFO : lots existants de ce produit dans cet emplacement, du plus urgent au plus lointain
            $fefo = $pdo->prepare("SELECT id, quantite, numero_lot, date_peremption
                                   FROM produits
                                   WHERE nom=? AND emplacement_id=? AND statut='actif' AND quantite > 0
                                   ORDER BY date_peremption ASC, id ASC");
            $fefo->execute([$nom, $empl_line]);
            $anciens = $fefo->fetchAll(PDO::FETCH_ASSOC);

            // 2) Entrée du neuf : fusion si même lot, sinon nouvelle ligne
            $cible = null;
            if ($lot !== null) {
                $f = $pdo->prepare("SELECT id, quantite FROM produits WHERE nom=? AND emplacement_id=? AND numero_lot=? AND statut='actif' LIMIT 1");
                $f->execute([$nom, $empl_line, $lot]);
                $cible = $f->fetch(PDO::FETCH_ASSOC);
            }
            if ($cible) {
                $oq = (float)$cible['quantite']; $nq = $oq + $qty;
                $pdo->prepare("UPDATE produits SET quantite=?, date_peremption=? WHERE id=?")->execute([$nq, $exp, $cible['id']]);
                $new_pid = (int)$cible['id'];
            } else {
                $pdo->prepare("INSERT INTO produits (nom, quantite, unite, numero_lot, date_peremption, emplacement_id, statut, cree_par, cree_le)
                               VALUES (?, ?, ?, ?, ?, ?, 'actif', ?, NOW())")
                    ->execute([$nom, $qty, $unit, $lot, $exp, $empl_line, currentUserId()]);
                $new_pid = (int)$pdo->lastInsertId();
                $oq = 0; $nq = $qty;
            }
            $pdo->prepare("INSERT INTO rempotage_articles (rempotage_id, produit_id, action, nom, quantite, unite, numero_lot, date_peremption)
                           VALUES (?, ?, 'cree', ?, ?, ?, ?, ?)")
                ->execute([$remp_id, $new_pid, $nom, $qty, $unit, $lot, $exp]);
            $pdo->prepare("INSERT INTO mouvements_stock (produit_id, user_id, type, quantite_avant, quantite_delta, quantite_apres, motif)
                           VALUES (?, ?, 'entree', ?, ?, ?, 'Rempotage — remis')")
                ->execute([$new_pid, currentUserId(), $oq, $qty, $nq]);
            $nb_crees++;

            // 2bis) Débit de la PUI (transfert) — le neuf remis provient de la PUI du site.
            //       Règle « autoriser + signaler » : on débite ce qui est dispo (jamais
            //       de négatif), on ne bloque jamais ; le manque éventuel est noté.
            if ($do_pui_debit) {
                $inPui = implode(',', array_map('intval', $pui_ids));
                $pl = $pdo->prepare("SELECT id, quantite, numero_lot, date_peremption
                                     FROM produits
                                     WHERE nom=? AND emplacement_id IN ($inPui) AND statut='actif' AND quantite > 0
                                     ORDER BY date_peremption ASC, id ASC");
                $pl->execute([$nom]);
                $reste_pui = $qty;
                foreach ($pl->fetchAll(PDO::FETCH_ASSOC) as $plot) {
                    if ($reste_pui <= 0) break;
                    $dispo_pui = (float)$plot['quantite'];
                    $deb = min($dispo_pui, $reste_pui);
                    if ($deb <= 0) continue;
                    $nqp = $dispo_pui - $deb;
                    $pdo->prepare("UPDATE produits SET quantite=? WHERE id=?")->execute([$nqp, $plot['id']]);
                    $pdo->prepare("INSERT INTO mouvements_stock (produit_id, user_id, type, quantite_avant, quantite_delta, quantite_apres, motif)
                                   VALUES (?, ?, 'sortie', ?, ?, ?, 'Rempotage — débit PUI')")
                        ->execute([(int)$plot['id'], currentUserId(), $dispo_pui, -$deb, $nqp]);
                    $reste_pui -= $deb;
                    $pui_debits++;
                }
                if ($reste_pui > 0.0001) {
                    $mq = rtrim(rtrim(number_format($reste_pui, 2, '.', ''), '0'), '.');
                    $pui_shortfalls[] = "$nom (manque $mq $unit)";
                }
            }

            // 3) Sortie d'une quantité équivalente : lot indiqué d'abord (si choisi), sinon FEFO
            $chosen_id = (int)($a['lot_retire_id'] ?? 0);
            if ($chosen_id) {
                usort($anciens, function ($x, $y) use ($chosen_id) {
                    if ((int)$x['id'] === $chosen_id) return -1;
                    if ((int)$y['id'] === $chosen_id) return 1;
                    return strcmp((string)$x['date_peremption'], (string)$y['date_peremption']);
                });
            }
            $reste = $qty;
            foreach ($anciens as $anc) {
                if ($reste <= 0) break;
                if ($lot !== null && $anc['numero_lot'] === $lot) continue; // ne pas sortir le lot qu'on vient de remettre
                $dispo = (float)$anc['quantite'];
                $sortie = min($dispo, $reste);
                if ($sortie <= 0) continue;
                $oq2 = $dispo; $nq2 = $dispo - $sortie;
                $motif = ($chosen_id && (int)$anc['id'] === $chosen_id) ? 'Rempotage — sortie (lot confirmé)' : 'Rempotage — sortie (FEFO)';
                $pdo->prepare("UPDATE produits SET quantite=? WHERE id=?")->execute([$nq2, $anc['id']]);
                $pdo->prepare("INSERT INTO rempotage_articles (rempotage_id, produit_id, action, nom, quantite, unite, numero_lot, date_peremption)
                               VALUES (?, ?, 'retire', ?, ?, ?, ?, ?)")
                    ->execute([$remp_id, (int)$anc['id'], $nom, $sortie, $unit, $anc['numero_lot'], $anc['date_peremption']]);
                $pdo->prepare("INSERT INTO mouvements_stock (produit_id, user_id, type, quantite_avant, quantite_delta, quantite_apres, motif)
                               VALUES (?, ?, 'sortie', ?, ?, ?, ?)")
                    ->execute([(int)$anc['id'], currentUserId(), $oq2, -$sortie, $nq2, $motif]);
                $reste -= $sortie;
                $nb_retires++;
            }
            // Si $reste > 0 : aucun ancien lot (produit neuf) → la quantité augmente légitimement.
        }

        // Bilan PUI : « signaler » les manques en les inscrivant sur le rempotage
        if ($pui_shortfalls) {
            $manque_txt = 'PUI insuffisante : ' . implode(', ', $pui_shortfalls);
            $note2 = trim(($note_final ? $note_final . ' — ' : '') . $manque_txt);
            $pdo->prepare("UPDATE rempotages SET notes=? WHERE id=?")->execute([$note2, $remp_id]);
        }

        $pdo->prepare("UPDATE rempotages SET nb_crees=?, nb_non_scannes=? WHERE id=?")->execute([$nb_crees, $nb_retires, $remp_id]);
        $pdo->commit();
        if (function_exists('audit_log')) audit_log($pdo, 'REMPOTAGE', 'rempotages', $remp_id, null, ['remis'=>$nb_crees, 'sortis'=>$nb_retires, 'pui_debits'=>$pui_debits, 'pui_manques'=>count($pui_shortfalls)]);
        $msg = "✅ Rempotage enregistré : $nb_crees produit(s) remis.";
        if ($do_pui_debit) $msg .= " Débité de la PUI : $pui_debits lot(s).";
        flashSuccess($msg);
        if ($pui_shortfalls) {
            flashError("⚠️ Stock PUI insuffisant pour : " . implode(', ', $pui_shortfalls) . ". Rempotage enregistré, écart à régulariser.");
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flashError("Erreur : " . $e->getMessage());
    }
    header('Location: /rempotage.php'); exit;
}

// Sacs / emplacements de premier niveau
$sacs = $pdo->prepare("SELECT id, nom FROM emplacements WHERE site_id=? AND parent_id IS NULL AND actif=1 ORDER BY nom");
$sacs->execute([$site_id]);
$sacs = $sacs->fetchAll(PDO::FETCH_ASSOC);
$ia_photo_on = function_exists('module_actif') && module_actif('ia_photo');

require_once 'includes/header.php';
?>
<style>
.rmp-wrap{margin:0}
.rmp-card{background:var(--glass);border:1px solid var(--border);border-radius:14px;padding:16px;margin-bottom:14px}
.rmp-grid{display:grid;grid-template-columns:1fr;gap:14px}
.rmp-search{width:100%;padding:12px;font-size:1rem;border:1px solid var(--border);border-radius:10px;margin-bottom:10px}
.rmp-cat{max-height:360px;overflow:auto}
.rmp-prod{display:flex;align-items:center;gap:10px;padding:11px;border:1px solid var(--border);border-radius:10px;margin-bottom:8px;cursor:pointer;transition:.12s}
.rmp-prod:hover{border-color:var(--primary);background:rgba(59,130,246,.06)}
.rmp-prod .nom{font-weight:600;font-size:.9rem}
.rmp-prod .meta{font-size:.72rem;color:var(--text3);margin-top:2px}
.rmp-line{border:1px solid var(--border);border-radius:12px;padding:12px;margin-bottom:10px;background:var(--bg2)}
.rmp-line .top{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}
.rmp-line .nom{font-weight:700}
.rmp-fields{display:grid;grid-template-columns:1fr 1fr 128px;gap:8px}
.rmp-fields label{font-size:.68rem;text-transform:uppercase;letter-spacing:.04em;color:var(--text3)}
.rmp-fields input{width:100%;padding:9px;border:1px solid var(--border);border-radius:8px;font-size:.9rem}
.rmp-qty{display:flex;align-items:center;gap:4px}
.rmp-step{width:32px;flex-shrink:0;border:1px solid var(--border);background:var(--glass);color:var(--text);border-radius:8px;font-size:1.15rem;font-weight:700;cursor:pointer;padding:7px 0;line-height:1}
.rmp-step:hover{border-color:var(--primary);color:var(--primary)}
.rmp-del{border:none;background:transparent;color:var(--red);font-size:1.15rem;cursor:pointer}
.rmp-empty{text-align:center;color:var(--text3);padding:26px 10px;font-size:.9rem}
@media(min-width:900px){
  .rmp-grid{grid-template-columns:290px 400px minmax(0,1fr);align-items:start;gap:18px}
  .rmp-card{padding:18px}
  .rmp-cat{max-height:calc(100vh - 300px)}
  #rmp-lines{max-height:calc(100vh - 250px);overflow:auto;padding-right:4px}
  .rmp-ctx-row{flex-direction:column}
  .rmp-ctx-row>div{min-width:0 !important}
  #rmp-save{max-width:520px;margin:0 auto;display:block}
}
@media(min-width:1300px){ .rmp-grid{grid-template-columns:300px 440px minmax(0,1fr)} }
.rmp-confirm{position:fixed;inset:0;background:rgba(0,0,0,.55);display:none;align-items:center;justify-content:center;z-index:1100;padding:16px}
.rmp-confirm.open{display:flex}
.rmp-confirm-box{background:var(--bg2,#111827);border:1px solid var(--border);border-radius:14px;padding:16px;max-width:520px;width:100%;max-height:85vh;overflow:auto}
.rmp-scan{position:fixed;inset:0;background:rgba(0,0,0,.85);display:none;align-items:center;justify-content:center;z-index:1200;padding:16px}
.rmp-scan.open{display:flex}
.rmp-scan-inner{width:100%;max-width:520px}
#scan-video,#cam-video{width:100%;border-radius:12px;background:#000;max-height:70vh}
.rmp-lotopt{display:flex;align-items:center;gap:12px;padding:14px;border:2px solid var(--border);border-radius:10px;margin-bottom:8px;cursor:pointer;transition:.12s;-webkit-tap-highlight-color:transparent}
.rmp-lotopt.sel{border-color:var(--primary);background:rgba(59,130,246,.14);box-shadow:0 0 0 1px var(--primary) inset}
.rmp-lotopt input{width:22px;height:22px;flex-shrink:0;accent-color:var(--primary)}
.pui-badge{display:inline-block;font-size:.68rem;font-weight:700;padding:2px 8px;border-radius:999px;vertical-align:middle;margin-left:6px;white-space:nowrap}
.pui-b-ok{background:rgba(139,92,246,.14);color:#8B5CF6}
.pui-b-low{background:rgba(239,159,39,.16);color:#EF9F27}
.pui-b-out{background:rgba(226,75,74,.14);color:#E24B4A}
</style>

<div class="rmp-wrap">
  <div class="page-header">
    <div class="page-title-group">
      <div class="page-title">🔄 Rempotage</div>
      <div class="page-sub">Refaire le plein d'un sac — mobile & bureau</div>
    </div>
    <a href="/rempotages.php" class="btn btn-ghost">📋 Historique</a>
  </div>

  <div class="rmp-grid">
    <!-- Sac + contexte -->
    <div class="rmp-card rmp-ctx">
      <label class="form-label">Sac / véhicule</label>
      <select id="rmp-empl" class="form-control" style="font-size:1rem;padding:11px">
        <option value="">— Choisir —</option>
        <?php foreach ($sacs as $s): ?><option value="<?= (int)$s['id'] ?>"><?= h($s['nom']) ?></option><?php endforeach; ?>
      </select>
      <div class="rmp-ctx-row" style="display:flex;gap:10px;margin-top:12px;flex-wrap:wrap">
        <div style="flex:1;min-width:140px"><label class="form-label">N° intervention (optionnel)</label>
          <input type="text" id="rmp-ref" class="form-control" placeholder="Ex : 2026-0142"></div>
        <div style="flex:2;min-width:180px"><label class="form-label">Notes (optionnel)</label>
          <input type="text" id="rmp-notes" class="form-control" placeholder="Précisions…"></div>
      </div>
    </div>

    <!-- Catalogue du sac -->
    <div class="rmp-card">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;gap:6px;flex-wrap:wrap">
        <div class="card-title" style="margin:0">Produits du sac</div>
        <div style="display:flex;gap:6px">
          <button type="button" class="btn btn-ghost btn-sm" onclick="RMP.openScan()">📷 Scanner</button>
          <?php if ($ia_photo_on): ?><button type="button" class="btn btn-ghost btn-sm" onclick="RMP.openPhoto()">🤖 Photo IA</button><?php endif; ?>
        </div>
      </div>
      <?php if ($ia_photo_on): ?><input type="file" id="rmp-photo-input" accept="image/*" capture="environment" style="display:none" onchange="RMP.onPhoto(this)"><?php endif; ?>
      <input type="text" id="rmp-search" class="rmp-search" placeholder="🔎 Rechercher (premières lettres)…" disabled>
      <div class="rmp-cat" id="rmp-cat"><div class="rmp-empty">Choisis d'abord un sac.</div></div>
    </div>

    <!-- Lignes à remettre -->
    <div class="rmp-card">
      <div class="card-title" style="margin-bottom:10px">À remettre (le neuf)</div>
      <div id="rmp-lines"><div class="rmp-empty">Ajoute des produits depuis la liste de gauche.</div></div>
    </div>
  </div>

  <form method="post" id="rmp-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="enregistrer">
    <input type="hidden" name="emplacement" id="rmp-empl-hidden">
    <input type="hidden" name="reference" id="rmp-ref-hidden">
    <input type="hidden" name="notes" id="rmp-notes-hidden">
    <input type="hidden" name="articles" id="rmp-articles">
    <button type="button" class="btn btn-primary" style="width:100%;padding:14px;font-size:1rem" id="rmp-save" disabled onclick="RMP.askRetires()">
      ✅ Enregistrer le rempotage
    </button>
  </form>

  <!-- Confirmation : indiquer les lots retirés ? -->
  <div class="rmp-confirm" id="rmp-confirm">
    <div class="rmp-confirm-box">
      <div style="display:flex;justify-content:flex-end"><button type="button" class="rmp-del" onclick="RMP.closeConfirm()">✕</button></div>
      <div id="rmp-confirm-body"></div>
    </div>
  </div>

  <!-- Scanner (moteur partagé /scanner/scanner-zxing.js) -->
  <div class="rmp-scan" id="rmp-scan">
    <div class="rmp-scan-inner">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
        <div style="font-weight:700;color:#fff">📷 Scanner un produit</div>
        <button type="button" class="rmp-del" style="color:#fff" onclick="RMP.closeScan()">✕</button>
      </div>
      <div style="position:relative"><video id="scan-video" playsinline></video><div id="scan-overlay"></div></div>
      <div style="text-align:center;margin-top:10px"><button type="button" class="btn btn-ghost btn-sm" onclick="RMP.switchCam()">🔄 Changer de caméra</button></div>
      <div style="color:#9ca3af;font-size:.78rem;text-align:center;margin-top:6px">DataMatrix · EAN13 · Code-barres</div>
    </div>
  </div>

  <div class="rmp-scan" id="rmp-cam">
    <div class="rmp-scan-inner">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
        <div style="font-weight:700;color:#fff">🤖 Photo IA</div>
        <button type="button" class="rmp-del" style="color:#fff" onclick="RMP.closeCam()">✕</button>
      </div>
      <video id="cam-video" playsinline muted></video>
      <div style="display:flex;justify-content:center;gap:16px;margin-top:12px;align-items:center">
        <button type="button" class="btn btn-ghost btn-sm" onclick="RMP.switchCamPhoto()">🔄</button>
        <button type="button" class="btn btn-primary" style="border-radius:999px;padding:14px 24px;font-size:1.05rem" onclick="RMP.shoot()">📸 Capturer</button>
        <span style="width:40px"></span>
      </div>
      <div style="color:#9ca3af;font-size:.78rem;text-align:center;margin-top:6px">Cadre le produit puis capture</div>
    </div>
  </div>

  <div class="rmp-scan" id="rmp-ia-loading"><div style="color:#fff;text-align:center;font-weight:600"><div style="font-size:2.4rem;margin-bottom:8px">🤖</div>Analyse IA en cours…</div></div>
</div>

<script src="/scanner/zxing-0.21.3.min.js"></script>
<script src="/scanner/scanner-zxing.js"></script>
<script>
const RMP_CSRF = <?= json_encode(csrf_token()) ?>;
const RMP = {
  produits: [], lignes: [],
  puiStock: {}, puiExists: false,   // étape 2a : stock PUI en lecture seule
  init(){
    document.getElementById('rmp-empl').addEventListener('change', e => this.loadSac(e.target.value));
    document.getElementById('rmp-search').addEventListener('input', e => this.renderCat(e.target.value));
    document.getElementById('rmp-form').addEventListener('submit', e => {
      e.preventDefault();
      if (this.lignes.length) this.askRetires();   // → ouvre l'écran « Indiquer les lots retirés ? »
    });
    this.loadPui();
  },

  async loadPui(){
    try {
      const r = await fetch('/rempotage.php?ajax=pui_stock');
      const d = await r.json();
      this.puiExists = !!(d && d.pui);
      this.puiStock  = (d && d.stock) || {};
    } catch(e){ this.puiExists = false; this.puiStock = {}; }
    if (this.lignes.length) this.renderLines();
  },

  // Badge « dispo PUI » pour une ligne (lecture seule, ne bloque rien)
  puiBadge(nom, qty){
    if (!this.puiExists) return '';
    const info = this.puiStock[nom];
    const stock = info ? info.stock : 0;
    const need  = parseFloat(qty) || 0;
    const rnd = n => Math.round(n * 100) / 100;
    let cls, txt;
    if (stock <= 0)        { cls = 'pui-b-out';  txt = '🏢 PUI : rupture'; }
    else if (stock < need) { cls = 'pui-b-low';  txt = '🏢 PUI ' + rnd(stock) + ' → manque ' + rnd(need - stock); }
    else                   { cls = 'pui-b-ok';   txt = '🏢 PUI ' + rnd(stock) + ' → reste ' + rnd(stock - need); }
    return '<span class="pui-badge '+cls+'">'+txt+'</span>';
  },
  esc(s){ return String(s??'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); },
  fmtExp(d){ if(!d) return 'pas de date'; const p=String(d).split('-'); return 'exp. '+(p[1]||'?')+'/'+(p[0]||'?'); },

  async loadSac(id){
    this.produits=[]; const search=document.getElementById('rmp-search');
    if(!id){ search.disabled=true; search.value=''; this.renderCat(''); return; }
    try{ const r=await fetch('/rempotage.php?ajax=sac_produits&emplacement='+encodeURIComponent(id)); this.produits=await r.json(); }
    catch(e){ this.produits=[]; }
    search.disabled=false; this.renderCat('');
  },

  renderCat(q){
    q=(q||'').toLowerCase();
    const seen={}, list=[];
    this.produits.forEach(p=>{ if(q && !(p.nom||'').toLowerCase().includes(q)) return;
      if(!seen[p.nom]){ seen[p.nom]={nom:p.nom,unite:p.unite,emplacement_id:p.emplacement_id,emplacement:p.emplacement,lots:0,stock:0}; list.push(seen[p.nom]); }
      seen[p.nom].lots++; seen[p.nom].stock+=parseFloat(p.quantite)||0;
    });
    const el=document.getElementById('rmp-cat');
    if(!list.length){ el.innerHTML='<div class="rmp-empty">'+(this.produits.length?'Aucun résultat.':'Aucun produit dans ce sac.')+'</div>'; return; }
    el.innerHTML=list.map(p=>'<div class="rmp-prod" onclick=\'RMP.add('+JSON.stringify(p)+')\'>'+
      '<div style="flex:1;min-width:0"><div class="nom">'+this.esc(p.nom)+'</div>'+
      '<div class="meta">'+p.lots+' lot(s) · stock '+p.stock+' · '+this.esc(p.emplacement)+'</div></div>'+
      '<div style="color:var(--primary);font-weight:700">+</div></div>').join('');
  },

  add(p){
    // Pré-remplir le nouveau lot + la date depuis le lot PUI le plus proche (FEFO) :
    // le neuf remis provient de la PUI, donc c'est ce lot-là qui partira.
    var lot='', exp='', fromPui=false;
    var info = this.puiStock[p.nom];
    if (info && info.fefo){ lot = info.fefo.lot || ''; exp = (info.fefo.exp||'').substring(0,7); fromPui = !!(lot || exp); }
    this.lignes.push({nom:p.nom,unite:p.unite||'unité',emplacement_id:p.emplacement_id,numero_lot:lot,date_peremption:exp,quantite:1,fromPui:fromPui});
    this.renderLines();
  },
  bumpQty(i,d){ var l=this.lignes[i]; if(!l) return; l.quantite=Math.max(0.01, Math.round(((parseFloat(l.quantite)||1)+d)*100)/100); this.renderLines(); },
  setField(i,f,v){
    this.lignes[i][f] = (f==='quantite') ? Math.max(0.01,parseFloat(v)||1) : v;
    if (f==='quantite') { const b=document.getElementById('pui-badge-'+i); if(b) b.innerHTML=this.puiBadge(this.lignes[i].nom,this.lignes[i].quantite); }
  },
  remove(i){ this.lignes.splice(i,1); this.renderLines(); },

  askRetires(){
    if(!this.lignes.length) return;
    document.getElementById('rmp-confirm-body').innerHTML =
      '<div style="text-align:center;padding:8px 0">'+
      '<div style="font-size:1.05rem;font-weight:700;margin-bottom:6px">Indiquer les lots retirés ?</div>'+
      '<div style="font-size:.85rem;color:var(--text3);margin-bottom:16px">Sinon, on retire le lot le plus proche de la péremption (FEFO).</div>'+
      '<button type="button" class="btn btn-ghost" style="margin:4px" onclick="RMP.doSubmit(false)">Non, FEFO auto</button>'+
      '<button type="button" class="btn btn-primary" style="margin:4px" onclick="RMP.showLotPick()">Oui, choisir</button></div>';
    document.getElementById('rmp-confirm').classList.add('open');
  },
  lotsDe(l){
    return this.produits.filter(p=>p.nom===l.nom && p.emplacement_id==l.emplacement_id && (parseFloat(p.quantite)||0)>0)
      .sort((a,b)=>String(a.date_peremption).localeCompare(String(b.date_peremption)));
  },
  showLotPick(){
    const html=this.lignes.map((l,i)=>{
      const lots=this.lotsDe(l);
      const opts = !lots.length
        ? '<div style="font-size:.8rem;color:var(--text3)">Nouveau produit — rien à retirer.</div>'
        : lots.map((p,j)=>'<label class="rmp-lotopt'+(j===0?' sel':'')+'">'+
            '<input type="radio" name="ret_'+i+'" value="'+p.id+'" '+(j===0?'checked':'')+' onchange="RMP.selLot(this)">'+
            '<span>Lot '+this.esc(p.numero_lot||'—')+' · '+this.fmtExp(p.date_peremption)+' · stock '+p.quantite+'</span></label>').join('');
      return '<div style="margin-bottom:14px"><div style="font-weight:700;margin-bottom:6px">'+this.esc(l.nom)+' <span style="font-weight:400;color:var(--text3)">(×'+l.quantite+')</span></div>'+opts+'</div>';
    }).join('');
    document.getElementById('rmp-confirm-body').innerHTML =
      '<div style="font-weight:700;margin-bottom:4px">Quel lot est parti ?</div>'+
      '<div style="font-size:.8rem;color:var(--text3);margin-bottom:12px">Regarde le sac et coche le lot qui a quitté la sacoche. Le plus urgent est proposé par défaut.</div>'+
      html+'<button type="button" class="btn btn-primary" style="width:100%;margin-top:4px" onclick="RMP.doSubmit(true)">Valider le rempotage</button>';
  },
  doSubmit(withPick){
    if(withPick){ this.lignes.forEach((l,i)=>{ const r=document.querySelector('input[name="ret_'+i+'"]:checked'); l.lot_retire_id=r?parseInt(r.value):null; }); }
    else { this.lignes.forEach(l=>l.lot_retire_id=null); }
    document.getElementById('rmp-empl-hidden').value  = document.getElementById('rmp-empl').value;
    document.getElementById('rmp-ref-hidden').value   = document.getElementById('rmp-ref').value;
    document.getElementById('rmp-notes-hidden').value = document.getElementById('rmp-notes').value;
    document.getElementById('rmp-articles').value     = JSON.stringify(this.lignes);
    document.getElementById('rmp-form').submit();
  },
  closeConfirm(){ document.getElementById('rmp-confirm').classList.remove('open'); },
  selLot(radio){ document.querySelectorAll('input[name="'+radio.name+'"]').forEach(r=>{ const lab=r.closest('label'); if(lab) lab.classList.toggle('sel', r.checked); }); },

  openScan(){
    if(!document.getElementById('rmp-empl').value){ alert('Choisis d\'abord un sac.'); return; }
    document.getElementById('rmp-scan').classList.add('open');
    try{ CadurixScanner.start('scan-video', c=>this.onScan(c), { onError:e=>console.error('[scan]',e), canvasContainer:'#scan-overlay' }); }
    catch(e){ console.error(e); alert('Scanner indisponible.'); this.closeScan(); }
  },
  closeScan(){ try{ CadurixScanner.stop(); }catch(e){} document.getElementById('rmp-scan').classList.remove('open'); },
  switchCam(){ try{ CadurixScanner.switchCam(); }catch(e){} },

  openPhoto(){
    if(!document.getElementById('rmp-empl').value){ alert('Choisis d\'abord un sac.'); return; }
    this.startCam();
  },
  async startCam(facing){
    this._facing = facing || this._facing || 'environment';
    document.getElementById('rmp-cam').classList.add('open');
    try{
      this._stream = await navigator.mediaDevices.getUserMedia({ video:{ facingMode:this._facing }, audio:false });
      const v=document.getElementById('cam-video'); v.srcObject=this._stream; await v.play();
    }catch(e){
      this.closeCam();
      const inp=document.getElementById('rmp-photo-input'); if(inp) inp.click(); // repli sélecteur fichier
    }
  },
  switchCamPhoto(){ this._facing=(this._facing==='environment')?'user':'environment'; this.stopCam(); this.startCam(this._facing); },
  stopCam(){ if(this._stream){ this._stream.getTracks().forEach(t=>t.stop()); this._stream=null; } },
  closeCam(){ this.stopCam(); document.getElementById('rmp-cam').classList.remove('open'); },
  shoot(){
    const v=document.getElementById('cam-video'); if(!v||!v.videoWidth) return;
    const cv=document.createElement('canvas'); cv.width=v.videoWidth; cv.height=v.videoHeight;
    cv.getContext('2d').drawImage(v,0,0);
    const b64=(cv.toDataURL('image/jpeg',0.85).split(',')[1])||'';
    this.closeCam(); this.analyzePhoto(b64,'image/jpeg');
  },
  onPhoto(input){
    const file=input.files && input.files[0]; input.value='';
    if(!file) return;
    const reader=new FileReader();
    reader.onload=()=>{ const b64=(String(reader.result).split(',')[1])||'';
      let media=file.type; if(['image/jpeg','image/png','image/webp'].indexOf(media)<0) media='image/jpeg';
      this.analyzePhoto(b64, media); };
    reader.readAsDataURL(file);
  },
  analyzePhoto(b64, media){
    document.getElementById('rmp-ia-loading').classList.add('open');
    const fd=new FormData(); fd.append('action','analyze'); fd.append('image',b64); fd.append('media_type',media); fd.append('csrf_token',RMP_CSRF);
    fetch('/ai/identifier.php',{method:'POST',body:fd})
      .then(r=>r.json())
      .then(d=>{
        document.getElementById('rmp-ia-loading').classList.remove('open');
        if(!d || !d.ok){ alert('IA photo : '+((d&&d.error)||'échec de l\'analyse')); return; }
        const f=d.fields||{};
        let exp=''; if(f.date_peremption){ const p=String(f.date_peremption).split('-'); if(p.length>=2) exp=p[0]+'-'+p[1]; }
        this.lignes.push({ nom:f.nom||'Produit (IA)', unite:'unité',
          emplacement_id:parseInt(document.getElementById('rmp-empl').value)||0,
          numero_lot:f.numero_lot||'', date_peremption:exp, quantite:1 });
        this.renderLines();
        if(f.confiance!=null && f.confiance<70){ alert('⚠️ Confiance IA faible ('+f.confiance+'%). Vérifie nom, lot et péremption avant d\'enregistrer.'); }
      })
      .catch(()=>{ document.getElementById('rmp-ia-loading').classList.remove('open'); alert('IA photo indisponible (module actif ? droits gestionnaire ? budget ?).'); });
  },
  onScan(code){
    const fd=new FormData(); fd.append('action','lookup_barcode'); fd.append('code',code);
    fetch('/rempotage.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
      let exp=''; if(d && d.date_exp){ const q=String(d.date_exp).split('-'); if(q.length>=2) exp=q[0]+'-'+q[1]; }
      const nom=(d && d.nom) ? d.nom : ('Code '+code);
      this.lignes.push({ nom:nom, unite:(d&&d.unite)||'unité',
        emplacement_id:(d&&d.emplacement_id)||parseInt(document.getElementById('rmp-empl').value)||0,
        numero_lot:(d&&(d.lot||d.lot_scan))||'', date_peremption:exp, quantite:1 });
      this.renderLines(); this.closeScan();
    }).catch(()=>{ this.closeScan(); });
  },

  renderLines(){
    const box=document.getElementById('rmp-lines'), save=document.getElementById('rmp-save');
    if(!this.lignes.length){ box.innerHTML='<div class="rmp-empty">Ajoute des produits depuis la liste de gauche.</div>'; save.disabled=true; return; }
    box.innerHTML=this.lignes.map((l,i)=>'<div class="rmp-line">'+
      '<div class="top"><div class="nom">'+this.esc(l.nom)+' <span id="pui-badge-'+i+'">'+this.puiBadge(l.nom,l.quantite)+'</span></div><button type="button" class="rmp-del" onclick="RMP.remove('+i+')">🗑️</button></div>'+
      '<div class="rmp-fields">'+
        '<div><label>Nouveau lot</label><input type="text" value="'+this.esc(l.numero_lot)+'" oninput="RMP.setField('+i+',\'numero_lot\',this.value)" placeholder="Lot"></div>'+
        '<div><label>Péremption</label><input type="month" value="'+this.esc(l.date_peremption)+'" oninput="RMP.setField('+i+',\'date_peremption\',this.value)"></div>'+
        '<div><label>Qté</label><div class="rmp-qty"><button type="button" class="rmp-step" onclick="RMP.bumpQty('+i+',-1)">−</button>'+
          '<input type="number" min="0.01" step="0.01" value="'+l.quantite+'" oninput="RMP.setField('+i+',\'quantite\',this.value)" style="text-align:center">'+
          '<button type="button" class="rmp-step" onclick="RMP.bumpQty('+i+',1)">+</button></div></div>'+
      '</div>'+
      (l.fromPui ? '<div style="font-size:.7rem;color:#8B5CF6;margin-top:7px">🏢 Lot &amp; date pris dans la PUI (FEFO) — modifie si besoin</div>' : '')+
      '</div>').join('');
    save.disabled=false;
  },
};
document.addEventListener('DOMContentLoaded',()=>RMP.init());
</script>

<?php require_once 'includes/footer.php'; ?>

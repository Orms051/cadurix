<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.

require_once 'includes/auth.php';

headers_securite();
requiert_connexion();
charger_permissions($pdo, $_SESSION['user_id']);
requiert_role(ROLE_LECTEUR);

$pageTitle = 'Emplacements';
$site_id   = site_actif();

if (!$site_id) {
    header('Location: dashboard.php');
    exit;
}

$csrf = $_SESSION['csrf_token'] ?? '';

// Palette d'icônes proposées pour un emplacement (source unique : validation
// serveur + sélecteur côté formulaire). Toute valeur hors liste est ignorée.
$ICON_CHOICES = [
  // Médicaments & injections
  '💊','💉','🧪','🩸','💧','🧴','🧫',
  // Soins & matériel
  '🩹','🩺','🌡️','🧤','🥽','✂️','🩼','🫀','🫁','🦴','🦷',
  // Respiratoire / oxygène
  '💨','🫧',
  // Sacs / sacoches / mallettes (différents)
  '🎒','👝','👜','🧳','💼','🛍️','🧰','📦',
  // Véhicules
  '🚑','🚐','🚒','🚗','🚁',
  // Froid / énergie
  '❄️','🧊','🔋','🔦',
  // Lieux / repères
  '🏥','🏢','🚩','📍',
  // Sécurité / NRBC
  '🧯','⚠️','🚨','☢️','☣️','🆘',
  // Pastilles de couleur
  '🔴','🟠','🟡','🟢','🔵','🟣','⭐',
];

// ─── Stats emplacement + enfants ───────────────────────────────────────────
function getEmplStats(PDO $pdo, int $id): array {
    $ids = [$id];
    $ch = $pdo->prepare("SELECT id FROM emplacements WHERE parent_id=? AND actif=1");
    $ch->execute([$id]);
    foreach ($ch->fetchAll() as $c) $ids[] = $c['id'];
    $in = implode(',', $ids);
    return $pdo->query("
        SELECT COUNT(*) AS total,
               SUM(date_peremption < CURDATE()) AS exp,
               SUM(date_peremption >= CURDATE() AND DATEDIFF(date_peremption,CURDATE())<=7) AS crit,
               SUM(quantite_min>0 AND quantite<=quantite_min) AS low
        FROM produits WHERE emplacement_id IN ($in) AND statut='actif'
    ")->fetch(PDO::FETCH_ASSOC);
}

// ═══════════════════════════════════════════════════════════════════════════
// AJAX
// ═══════════════════════════════════════════════════════════════════════════
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    // ── Arbre complet ──────────────────────────────────────────────────────
    if ($_GET['ajax'] === 'tree') {
        function buildTree(PDO $pdo, $parent_id, int $site_id): array {
            $stmt = $pdo->prepare("
                SELECT e.id, e.nom, e.parent_id, e.chaine_froid, e.type, e.controle_le, e.icone, e.pui_id,
                    COUNT(DISTINCT p.id) AS nb_produits,
                    SUM(CASE WHEN p.date_peremption < CURDATE() THEN 1 ELSE 0 END) AS nb_perimes,
                    SUM(CASE WHEN p.date_peremption >= CURDATE() AND DATEDIFF(p.date_peremption,CURDATE())<=7 THEN 1 ELSE 0 END) AS nb_crit
                FROM emplacements e
                LEFT JOIN produits p ON p.emplacement_id = e.id AND p.statut='actif'
                WHERE e.parent_id " . ($parent_id === null ? "IS NULL" : "= ?") . "
                  AND e.site_id = ? AND e.actif = 1
                GROUP BY e.id ORDER BY e.nom
            ");
            $parent_id === null ? $stmt->execute([$site_id]) : $stmt->execute([$parent_id, $site_id]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$row) {
                $row['nb_produits'] = (int)$row['nb_produits'];
                $row['nb_perimes']  = (int)$row['nb_perimes'];
                $row['nb_crit']     = (int)$row['nb_crit'];

                $row['children'] = buildTree($pdo, $row['id'], $site_id);

                // remontée : le parent agrège les totaux de ses sous-emplacements
                foreach ($row['children'] as $ch) {
                    $row['nb_produits'] += $ch['nb_produits'];
                    $row['nb_perimes']  += $ch['nb_perimes'];
                    $row['nb_crit']     += $ch['nb_crit'];
                }
            }
            unset($row);
            return $rows;
        }
        echo json_encode(buildTree($pdo, null, $site_id));
        exit;
    }

    // ── Détail d'un nœud ───────────────────────────────────────────────────
    if ($_GET['ajax'] === 'node' && isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        $stmt = $pdo->prepare("SELECT * FROM emplacements WHERE id=? AND site_id=? AND actif=1");
        $stmt->execute([$id, $site_id]);
        $node = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$node) { echo json_encode(['error' => 'not found']); exit; }
        $node['controle_par_nom'] = null;
        if (!empty($node['controle_par'] ?? null)) {
            try {
                $sc = $pdo->prepare("SELECT * FROM utilisateurs WHERE id=? LIMIT 1");
                $sc->execute([$node['controle_par']]);
                if ($u = $sc->fetch(PDO::FETCH_ASSOC)) {
                    $node['controle_par_nom'] = trim(($u['prenom'] ?? '').' '.($u['nom'] ?? '')) ?: ($u['login'] ?? '');
                }
            } catch (Throwable $e) {}
        }

        // Breadcrumb
        $breadcrumb = [];
        $cur = $node;
        while ($cur) {
            array_unshift($breadcrumb, ['id' => $cur['id'], 'nom' => $cur['nom']]);
            if ($cur['parent_id']) {
                $s = $pdo->prepare("SELECT * FROM emplacements WHERE id=?");
                $s->execute([$cur['parent_id']]);
                $cur = $s->fetch(PDO::FETCH_ASSOC);
            } else { $cur = null; }
        }

        // Enfants directs avec stats
        $stmt = $pdo->prepare("
            SELECT e.*,
                COUNT(DISTINCT p.id) AS nb_produits,
                SUM(CASE WHEN p.date_peremption < CURDATE() THEN 1 ELSE 0 END) AS nb_perimes,
                SUM(CASE WHEN p.date_peremption >= CURDATE() AND DATEDIFF(p.date_peremption,CURDATE())<=7 THEN 1 ELSE 0 END) AS nb_crit,
                SUM(CASE WHEN p.quantite_min>0 AND p.quantite<=p.quantite_min THEN 1 ELSE 0 END) AS nb_low,
                (SELECT COUNT(*) FROM emplacements e2 WHERE e2.parent_id=e.id AND e2.actif=1) AS nb_enfants
            FROM emplacements e
            LEFT JOIN produits p ON p.emplacement_id=e.id AND p.statut='actif'
            WHERE e.parent_id=? AND e.site_id=? AND e.actif=1
            GROUP BY e.id ORDER BY e.nom
        ");
        $stmt->execute([$id, $site_id]);
        $enfants = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Stats globales
        $stats = getEmplStats($pdo, $id);

        // Dernier rempotage
        $stmt_r = $pdo->prepare("
            SELECT valide_le FROM rempotages
            WHERE emplacement_id=? AND site_id=? AND statut='valide'
            ORDER BY valide_le DESC LIMIT 1
        ");
        $stmt_r->execute([$id, $site_id]);
        $last_remp = $stmt_r->fetchColumn();

        echo json_encode([
            'node'       => $node,
            'breadcrumb' => $breadcrumb,
            'enfants'    => $enfants,
            'stats'      => $stats,
            'last_remp'  => $last_remp,
        ]);
        exit;
    }


    // u2500u2500 Produits d'un emplacement u2500u2500u2500u2500u2500u2500u2500u2500u2500u2500u2500u2500u2500u2500u2500u2500u2500u2500u2500u2500u2500u2500u2500u2500u2500u2500u2500u2500u2500u2500u2500u2500u2500u2500u2500u2500u2500u2500u2500u2500u2500u2500u2500u2500u2500u2500
    if ($_GET['ajax'] === 'products' && isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        $stmt = $pdo->prepare("SELECT id, nom, dci, dosage, forme, numero_lot, quantite, quantite_min, unite, date_peremption, notes, DATEDIFF(date_peremption, CURDATE()) AS jours_restants FROM produits WHERE emplacement_id = ? AND statut = 'actif' ORDER BY nom, date_peremption ASC");
        $stmt->execute([$id]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    // ── Sauvegarde AJAX ────────────────────────────────────────────────────
    if ($_GET['ajax'] === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_verifier()) { echo json_encode(['error' => 'Jeton CSRF invalide. Rechargez la page.']); exit; }
        if (!peut_faire(ROLE_GESTIONNAIRE)) { echo json_encode(['error' => 'Permission refusée']); exit; }

        $data        = json_decode(file_get_contents('php://input'), true);
        $nom         = trim($data['nom']         ?? '');
        $type        = in_array($data['type'] ?? 'standard', ['standard','pui','sac','vehicule'], true)
                       ? $data['type'] : 'standard';
        $icone_val   = $data['icone'] ?? '';
        $icone       = (in_array($icone_val, $ICON_CHOICES, true)
                        || preg_match('/^(sac|dos|perf)-(red|org|amb|grn|teal|blue|vio|pink|grey|navy)$/', $icone_val))
                       ? $icone_val : null;
        $parent_id   = !empty($data['parent_id']) ? (int)$data['parent_id'] : null;
        // PUI de rattachement : doit être une PUI du site ; une PUI n'est pas rattachée.
        $pui_id      = !empty($data['pui_id']) ? (int)$data['pui_id'] : null;
        if ($type === 'pui') {
            $pui_id = null;
        } elseif ($pui_id) {
            $chk = $pdo->prepare("SELECT 1 FROM emplacements WHERE id=? AND site_id=? AND type='pui'");
            $chk->execute([$pui_id, $site_id]);
            if (!$chk->fetchColumn()) $pui_id = null;
        }
        $desc        = trim($data['description']  ?? '');
        $chaine_froid= !empty($data['chaine_froid']) ? 1 : 0;
        $temp_min    = isset($data['temp_min']) && $data['temp_min'] !== '' ? (float)$data['temp_min'] : null;
        $temp_max    = isset($data['temp_max']) && $data['temp_max'] !== '' ? (float)$data['temp_max'] : null;
        $id          = !empty($data['id']) ? (int)$data['id'] : null;

        if (!$nom) { echo json_encode(['error' => 'Nom obligatoire']); exit; }

        if ($id) {
            $pdo->prepare("UPDATE emplacements SET nom=?,type=?,icone=?,pui_id=?,description=?,chaine_froid=?,temp_min=?,temp_max=?,parent_id=? WHERE id=? AND site_id=?")
                ->execute([$nom,$type,$icone,$pui_id,$desc,$chaine_froid,$temp_min,$temp_max,$parent_id,$id,$site_id]);
            audit_log($pdo,'MODIF_EMPLACEMENT','emplacements',$id);
        } else {
            $pdo->prepare("INSERT INTO emplacements (site_id,parent_id,nom,type,icone,pui_id,description,chaine_froid,temp_min,temp_max,actif) VALUES(?,?,?,?,?,?,?,?,?,?,1)")
                ->execute([$site_id,$parent_id,$nom,$type,$icone,$pui_id,$desc,$chaine_froid,$temp_min,$temp_max]);
            $id = (int)$pdo->lastInsertId();
            audit_log($pdo,'CREATION_EMPLACEMENT','emplacements',$id,null,['nom'=>$nom,'type'=>$type,'site_id'=>$site_id]);
        }
        echo json_encode(['success' => true, 'id' => $id]);
        exit;
    }

    // ── Suppression AJAX ───────────────────────────────────────────────────
    if ($_GET['ajax'] === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_verifier()) { echo json_encode(['error' => 'Jeton CSRF invalide. Rechargez la page.']); exit; }
        if (!peut_faire(ROLE_GESTIONNAIRE)) { echo json_encode(['error' => 'Permission refusée']); exit; }

        $data = json_decode(file_get_contents('php://input'), true);
        $id   = (int)($data['id'] ?? 0);

        $nb_prod = $pdo->prepare("SELECT COUNT(*) FROM produits WHERE emplacement_id=? AND statut='actif'");
        $nb_prod->execute([$id]);
        if ($nb_prod->fetchColumn() > 0) { echo json_encode(['error' => 'Impossible : cet emplacement contient des produits actifs.']); exit; }

        $nb_sub = $pdo->prepare("SELECT COUNT(*) FROM emplacements WHERE parent_id=? AND actif=1");
        $nb_sub->execute([$id]);
        if ($nb_sub->fetchColumn() > 0) { echo json_encode(['error' => 'Impossible : supprimez d\'abord les sous-emplacements.']); exit; }

        $stmt = $pdo->prepare("SELECT nom FROM emplacements WHERE id=?");
        $stmt->execute([$id]);
        $nom_del = $stmt->fetchColumn();
        $pdo->prepare("DELETE FROM emplacements WHERE id=? AND site_id=?")->execute([$id, $site_id]);
        audit_log($pdo,'SUPPRESSION_EMPLACEMENT','emplacements',$id);
        echo json_encode(['success' => true, 'nom' => $nom_del]);
        exit;
    }

    if ($_GET['ajax'] === 'controle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_verifier()) { echo json_encode(['error' => 'Jeton CSRF invalide. Rechargez la page.']); exit; }
        if (!peut_faire(ROLE_GESTIONNAIRE)) { echo json_encode(['error' => 'Permission refusée']); exit; }
        $eid    = (int)($_POST['emplacement_id'] ?? 0);
        $scelle = trim($_POST['scelle_numero'] ?? '') ?: null;
        $conf   = !empty($_POST['conforme']) ? 1 : 0;
        $notes  = trim($_POST['notes'] ?? '') ?: null;
        $uid    = function_exists('currentUserId') ? currentUserId() : ($_SESSION['user_id'] ?? null);
        $chk = $pdo->prepare("SELECT id FROM emplacements WHERE id=? AND site_id=?");
        $chk->execute([$eid, $site_id]);
        if (!$chk->fetch()) { echo json_encode(['error' => 'Emplacement introuvable']); exit; }
        try {
            $pdo->prepare("INSERT INTO controles_sacs (emplacement_id, scelle_numero, conforme, notes, controle_par) VALUES (?,?,?,?,?)")
                ->execute([$eid, $scelle, $conf, $notes, $uid]);
            $pdo->prepare("UPDATE emplacements SET scelle_numero=?, controle_le=NOW(), controle_par=? WHERE id=?")
                ->execute([$scelle, $uid, $eid]);
            if (function_exists('audit_log')) audit_log($pdo, 'CONTROLE_SAC', 'emplacements', $eid, null, ['scelle'=>$scelle, 'conforme'=>$conf]);
            echo json_encode(['success' => true]);
        } catch (Throwable $e) {
            echo json_encode(['error' => 'Erreur : ' . $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['error' => 'unknown']);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
// PAGE HTML
// ═══════════════════════════════════════════════════════════════════════════
require_once 'includes/header.php';
?>

<div class="loc-page">

  <!-- ═══ SIDEBAR ARBRE ═══ -->
  <aside class="loc-sidebar">
    <div class="sidebar-head">
      <span class="sb-tile">📍</span>
      <div class="sb-titles">
        <div class="sb-title">Emplacements</div>
        <div class="sb-sub" id="treeSummary">…</div>
      </div>
      <div class="sb-actions">
        <a href="/qrcodes.php" class="btn-icon sb-soft" title="Générer les QR Codes" style="text-decoration:none">
          <i class="ti ti-qrcode" aria-hidden="true"></i>
        </a>
        <?php if (peut_faire(ROLE_GESTIONNAIRE)): ?>
        <button class="btn-icon sb-prim" title="Nouvel emplacement (N)" onclick="openModal()">
          <i class="ti ti-plus" aria-hidden="true"></i>
        </button>
        <?php endif; ?>
      </div>
    </div>
    <div class="sidebar-search">
      <span aria-hidden="true" style="font-size:15px">🔎</span>
      <input type="text" id="searchTree" placeholder="Rechercher un emplacement…" autocomplete="off" aria-label="Rechercher un emplacement">
    </div>
    <div class="tree-container" id="treeContainer">
      <div class="tree-loading"><i class="ti ti-loader-2 spin" aria-hidden="true"></i> Chargement…</div>
    </div>
  </aside>

  <!-- ═══ PANNEAU DÉTAIL ═══ -->
  <main class="loc-main">
    <div class="main-empty" id="mainEmpty">
      <i class="ti ti-map-2" aria-hidden="true"></i>
      <p>Sélectionnez un emplacement dans l'arbre</p>
    </div>
    <div id="mainContent" style="display:none"></div>
  </main>

</div>

<!-- ═══ MODALE AJOUT / ÉDITION ═══ -->
<div class="modal-overlay" id="ctrlOverlay" role="dialog" aria-modal="true">
  <div class="modal-box">
    <div class="modal-header">
      <span>🔒 Contrôle de scellé</span>
      <button class="btn-icon" onclick="closeControle()" aria-label="Fermer"><i class="ti ti-x" aria-hidden="true"></i></button>
    </div>
    <div class="modal-body">
      <p style="margin:0 0 14px;color:var(--text2)" id="ctrlNom"></p>
      <label style="display:flex;align-items:center;gap:8px;font-size:14px;margin-bottom:14px;cursor:pointer">
        <input type="checkbox" id="ctrlConforme" checked> Tout est conforme (contenu vérifié)
      </label>
      <label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px">Nouveau N° de scellé</label>
      <input type="text" id="ctrlScelle" placeholder="Ex : 0012345" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:8px;background:var(--bg2);color:var(--text);margin-bottom:14px">
      <label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px">Notes (optionnel)</label>
      <textarea id="ctrlNotes" rows="2" placeholder="Remarques éventuelles…" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:8px;background:var(--bg2);color:var(--text);resize:vertical"></textarea>
      <div class="modal-error" id="ctrlError" role="alert"></div>
    </div>
    <div class="modal-footer">
      <button class="btn-ghost" onclick="closeControle()">Annuler</button>
      <button class="btn-primary" onclick="saveControle()"><i class="ti ti-check" aria-hidden="true"></i> Valider le contrôle</button>
    </div>
  </div>
</div>
<div class="modal-overlay" id="delOverlay" role="dialog" aria-modal="true">
  <div class="modal-box">
    <div class="modal-header">
      <span>🗑️ Supprimer le produit</span>
      <button class="btn-icon" onclick="closeDelete()" aria-label="Fermer"><i class="ti ti-x" aria-hidden="true"></i></button>
    </div>
    <div class="modal-body">
      <p style="margin:0;line-height:1.5;color:var(--text2)">Voulez-vous vraiment supprimer <b id="delNom" style="color:var(--text)"></b> ?<br>Cette action est <b>définitive</b>.</p>
    </div>
    <div class="modal-footer">
      <button class="btn-ghost" onclick="closeDelete()">Annuler</button>
      <a id="delConfirm" href="#" class="btn-primary" style="background:#dc2626;border-color:#dc2626"><i class="ti ti-trash" aria-hidden="true"></i> Supprimer définitivement</a>
    </div>
  </div>
</div>
<div class="modal-overlay" id="modalOverlay" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
  <div class="modal-box">
    <div class="modal-header">
      <span id="modalTitle">Nouvel emplacement</span>
      <button class="btn-icon" onclick="closeModal()" aria-label="Fermer"><i class="ti ti-x" aria-hidden="true"></i></button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="editId">
      <div class="form-group">
        <label for="editNom">Nom <span class="req" aria-hidden="true">*</span></label>
        <input type="text" id="editNom" placeholder="Ex: SAC ISP 1, Ambulance A1…" autocomplete="off">
      </div>
      <div class="form-group">
        <label for="editType">Type</label>
        <select id="editType">
          <option value="standard">Emplacement standard (sac, sacoche, véhicule…)</option>
          <option value="pui">PUI — Pharmacie à usage intérieur (stock central du site)</option>
        </select>
      </div>
      <div class="form-group">
        <label>Icône</label>
        <div id="editIconePicker" class="icone-picker"></div>
        <input type="hidden" id="editIcone" value="">
      </div>
      <div class="form-group">
        <label for="editParent">Emplacement parent</label>
        <select id="editParent">
          <option value="">— Aucun (racine) —</option>
        </select>
      </div>
      <div class="form-group" id="puiRow">
        <label for="editPui">PUI de rattachement</label>
        <select id="editPui">
          <option value="">— Aucune —</option>
        </select>
      </div>
      <div class="form-group">
        <label for="editDesc">Description</label>
        <input type="text" id="editDesc" placeholder="Optionnel">
      </div>
      <div class="form-group form-group-inline">
        <label class="toggle-label" for="editChaineFroid">
          <input type="checkbox" id="editChaineFroid">
          <i class="ti ti-snowflake" aria-hidden="true"></i> Chaîne du froid requise
        </label>
      </div>
      <div class="form-row-temp" id="tempRow" style="display:none">
        <div class="form-group">
          <label for="editTempMin">Temp. min (°C)</label>
          <input type="number" id="editTempMin" step="0.1" placeholder="Ex: 2">
        </div>
        <div class="form-group">
          <label for="editTempMax">Temp. max (°C)</label>
          <input type="number" id="editTempMax" step="0.1" placeholder="Ex: 8">
        </div>
      </div>
      <div class="modal-error" id="modalError" role="alert"></div>
    </div>
    <div class="modal-footer">
      <button class="btn-ghost" onclick="closeModal()">Annuler</button>
      <button class="btn-primary" onclick="saveEmplacement()">
        <i class="ti ti-check" aria-hidden="true"></i> Enregistrer
      </button>
    </div>
  </div>
</div>

<style>
.loc-page {
  display: grid;
  grid-template-columns: 290px 1fr;
  gap: 16px;
  height: calc(100vh - 60px);
  padding: 16px;
  overflow: hidden;
}

/* ── Sidebar ── */
.loc-sidebar {
  background: var(--bg2);
  border: none;
  border-radius: 20px;
  box-shadow: 0 12px 30px rgba(30, 40, 80, .15);
  display: flex;
  flex-direction: column;
  overflow: hidden;
}
.sidebar-head {
  display: flex;
  align-items: center;
  gap: 11px;
  padding: 14px 14px 12px;
  flex-shrink: 0;
}
.sb-tile {
  width: 38px; height: 38px; flex-shrink: 0;
  border-radius: 11px;
  display: flex; align-items: center; justify-content: center;
  background: var(--primary-bg); color: var(--primary);
  font-size: 19px;
}
.sb-titles { flex: 1; min-width: 0; }
.sb-title { font-size: 15px; font-weight: 700; color: var(--text); line-height: 1.1; }
.sb-sub {
  font-size: 11.5px; color: var(--text3); font-weight: 500; margin-top: 3px;
  line-height: 1.35;
}
.sb-actions { display: flex; gap: 6px; align-items: center; }

/* boutons d'action */
.sidebar-head .btn-icon {
  width: 30px; height: 30px; border-radius: 9px;
  display: flex; align-items: center; justify-content: center;
}
.sidebar-head .btn-icon.sb-soft {
  background: var(--bg);
  border: 1px solid var(--border);
  color: var(--text2);
  transition: color .15s, border-color .15s;
}
.sidebar-head .btn-icon.sb-soft:hover { color: var(--primary); border-color: var(--primary); }
.sidebar-head .btn-icon.sb-prim {
  background: var(--primary);
  border: 1px solid var(--primary);
  color: #fff;
  box-shadow: 0 3px 9px rgba(var(--primary-rgb), .35);
  transition: filter .15s;
}
.sidebar-head .btn-icon.sb-prim:hover { filter: brightness(1.07); }
.sidebar-search {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 4px 12px 10px;
  padding: 9px 11px;
  background: rgba(var(--primary-rgb), .05);
  border: 1px solid var(--border);
  border-radius: 10px;
  flex-shrink: 0;
  transition: border-color .15s, background .15s;
}
.sidebar-search:focus-within {
  border-color: var(--primary);
  background: var(--primary-bg);
}
.sidebar-search i { color: var(--text3); font-size: 14px; }
.sidebar-search input {
  background: none;
  border: none;
  outline: none;
  font-size: 13px;
  color: var(--text);
  width: 100%;
}
.sidebar-search input::placeholder { color: var(--text3); }
.tree-container {
  flex: 1;
  overflow-y: auto;
  padding: 4px 0;
  scrollbar-width: thin;
  scrollbar-color: var(--border) transparent;
}
.tree-loading {
  padding: 20px;
  color: var(--text2);
  font-size: 13px;
  display: flex;
  align-items: center;
  gap: 8px;
}

/* ── Nœuds ── */

/* ── Nœuds (version riche) ── */
.tree-node {
  display: flex; align-items: center; gap: 9px;
  padding: 6px 9px; margin: 1px 6px;
  border-radius: 10px; cursor: pointer; position: relative;
  transition: background .12s; user-select: none;
}
.tree-node:hover { background: rgba(var(--primary-rgb), .05); }
.tree-node.active { background: var(--primary-bg); }
.tree-node.active::before {
  content: ''; position: absolute; left: 0; top: 9px; bottom: 9px;
  width: 3px; border-radius: 3px; background: var(--primary);
}
.n-tile {
  width: 30px; height: 30px; flex-shrink: 0; border-radius: 9px;
  display: flex; align-items: center; justify-content: center;
  background: var(--primary-bg); color: var(--primary); font-size: 15px;
}
.n-tile.cold { background: rgba(96,165,250,.14); color: #60a5fa; }
.tree-node.active .n-tile { background: var(--primary); color: #fff; }
.tree-node.active .n-tile.cold { background: #60a5fa; color: #fff; }
.n-body { flex: 1; min-width: 0; }
.n-name {
  font-size: 13px; font-weight: 700; color: var(--text);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.tree-node.active .n-name { color: var(--primary); }
.n-meta { font-size: 11px; color: var(--text3); font-weight: 500; margin-top: 1px; }
.n-right { display: flex; align-items: center; gap: 6px; flex-shrink: 0; }
.n-pill { font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 7px; white-space: nowrap; }
.n-pill.red   { background: rgba(226,75,74,.14); color: #E24B4A; }
.n-pill.amber { background: rgba(239,159,39,.14); color: #EF9F27; }
.n-pill.ok    { background: rgba(31,175,116,.14); color: #1FAF74; }
.pui-tag {
  font-size: 9px; font-weight: 800; letter-spacing: .06em;
  padding: 1px 5px; border-radius: 5px; vertical-align: middle;
  background: rgba(139,92,246,.16); color: #8B5CF6;
}
.pui-tag-lg { font-size: 11px; padding: 2px 8px; border-radius: 7px; margin-left: 4px; }
/* Sacs chapeautés par la PUI : décalage + rail violet (visuel uniquement) */
.under-pui { margin-left: 14px; padding-left: 6px; border-left: 2px solid rgba(139,92,246,.35); }
/* Scellé : contrôle en retard */
.seal-late { color: #EF9F27; font-weight: 600; white-space: nowrap; }
.node-toggle {
  width: 20px; height: 20px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  font-size: 11px; color: var(--text3);
  transition: transform .18s; border-radius: 4px;
}
.node-toggle.open { transform: rotate(90deg); }
.node-toggle.leaf { opacity: 0; pointer-events: none; }


.node-badge {
  font-size: 10px;
  padding: 1px 5px;
  border-radius: 8px;
  flex-shrink: 0;
  font-weight: 600;
}
.badge-danger { background: rgba(226,75,74,.15); color: #E24B4A; }
.badge-warn   { background: rgba(239,159,39,.15); color: #EF9F27; }
.tree-children { display: none; }
.tree-children.open { display: block; }

/* ── Main ── */
.loc-main {
  overflow-y: auto;
  background: var(--bg);
  border-radius: 20px;
  box-shadow: 0 12px 30px rgba(30, 40, 80, .15);
  scrollbar-width: thin;
  scrollbar-color: var(--border) transparent;
}
.main-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  height: 100%;
  color: var(--text2);
  gap: 12px;
}
.main-empty i { font-size: 44px; opacity: .25; }
.main-empty p { font-size: 14px; }
#mainContent { padding: 24px 28px; animation: fadeIn .18s ease; }
@keyframes fadeIn { from { opacity:0;transform:translateY(4px) } to { opacity:1;transform:none } }

/* Breadcrumb */
.loc-breadcrumb {
  display: flex;
  align-items: center;
  gap: 5px;
  font-size: 12px;
  color: var(--text2);
  margin-bottom: 18px;
  flex-wrap: wrap;
}
.loc-breadcrumb a { color: var(--text2); text-decoration: none; cursor: pointer; transition: color .12s; }
.loc-breadcrumb a:hover { color: var(--primary); }
.loc-breadcrumb .sep { opacity: .4; }
.loc-breadcrumb .cur { color: var(--text); font-weight: 500; }
.scelle-bar { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; background:var(--bg3); border:1px solid var(--border); border-radius:12px; padding:10px 14px; margin-bottom:18px; }
.scelle-info { font-size:14px; color:var(--text); display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
.scelle-sub { color:var(--text2); font-size:13px; }
.scelle-never { color:#d97706; }

/* En-tête fusionné (carte : titre + actions + ligne scellé) */
.loc-header {
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: 14px;
  padding: 16px 18px;
  margin-bottom: 16px;
}
.loc-header-top {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
}
.loc-seal {
  display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
  margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--border);
  font-size: 13px; color: var(--text2);
}
.loc-title {
  font-size: 20px;
  font-weight: 700;
  color: var(--text);
  display: flex;
  align-items: center;
  gap: 9px;
}
.loc-title i { color: var(--primary); }
.loc-subtitle { font-size: 12px; color: var(--text2); margin-top: 4px; }
.loc-cold-badge {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 11px;
  color: #60a5fa;
  background: rgba(96,165,250,.1);
  border: 1px solid rgba(96,165,250,.2);
  border-radius: 6px;
  padding: 3px 8px;
  margin-top: 5px;
}
.loc-actions { display: flex; gap: 7px; flex-shrink: 0; flex-wrap: wrap; }

/* Stats */
.stats-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 8px;
  margin-bottom: 22px;
}
.stat-card {
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 12px 14px;
}
.stat-val { font-size: 24px; font-weight: 700; color: var(--text); line-height: 1; }
.stat-val.warn   { color: #EF9F27; }
.stat-val.danger { color: #E24B4A; }
.stat-val.ok     { color: var(--primary); }
.stat-val.muted  { color: var(--text2); }
.stat-lbl { font-size: 11px; color: var(--text2); margin-top: 4px; }
/* Santé compacte (remplace les 4 grosses cartes) */
.loc-health { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 20px; }
.loc-hchip { flex: 1; min-width: 110px; background: var(--bg2); border: 1px solid var(--border); border-radius: 12px; padding: 11px 14px; }
.loc-hchip .hn { font-size: 1.5rem; font-weight: 800; line-height: 1; color: var(--text); font-variant-numeric: tabular-nums; }
.loc-hchip .hn.danger { color: #E24B4A; }
.loc-hchip .hn.warn { color: #EF9F27; }
.loc-hchip .hl { font-size: .7rem; text-transform: uppercase; letter-spacing: .05em; color: var(--text3); margin-top: 4px; }

/* Section */
.section-title {
  font-size: 11px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--text2);
  margin-bottom: 9px;
}

/* Produits inline */
.produits-table-wrap {
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: 10px;
  overflow: hidden;
  margin-bottom: 22px;
}
.produits-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}
.produits-table thead th {
  padding: 9px 14px;
  text-align: left;
  font-size: 11px;
  font-weight: 600;
  letter-spacing: .05em;
  text-transform: uppercase;
  color: var(--text2);
  background: var(--bg3);
  border-bottom: 1px solid var(--border);
}
.produits-table tbody tr {
  border-bottom: 1px solid var(--border);
  transition: background .1s;
}
.produits-table tbody tr:last-child { border-bottom: none; }
.produits-table tbody tr:hover { background: rgba(255,255,255,.03); }
.produits-table tbody tr.row-danger { background: rgba(226,75,74,.06); }
.produits-table tbody tr.row-warn   { background: rgba(239,159,39,.06); }
.produits-table tbody tr.row-low    { background: rgba(239,159,39,.03); }
.produits-table td { padding: 9px 14px; color: var(--text); vertical-align: middle; }
.prod-nom { font-weight: 500; }
.prod-note {
  display: inline-block;
  margin-left: 8px;
  font-size: 10px;
  font-weight: 400;
  background: rgba(239,159,39,.15);
  color: #EF9F27;
  padding: 1px 6px;
  border-radius: 4px;
}
.prod-qty  { color: var(--text2); white-space: nowrap; }
.prod-date { color: var(--text2); white-space: nowrap; }
.prod-status { white-space: nowrap; }
.prod-edit { text-align: right; }
.prod-badge {
  font-size: 10px; font-weight: 600;
  padding: 2px 7px; border-radius: 6px;
  white-space: nowrap;
}
.badge-danger { background: rgba(226,75,74,.15); color: #E24B4A; }
.badge-warn   { background: rgba(239,159,39,.15); color: #EF9F27; }
.badge-low    { background: rgba(239,159,39,.10); color: #EF9F27; }
.badge-ok     { background: rgba(var(--primary-rgb),.1); color: var(--primary); }
.btn-icon-sm {
  display: inline-flex; align-items: center; justify-content: center;
  width: 26px; height: 26px; border-radius: 6px;
  color: var(--text2); text-decoration: none;
  transition: background .15s, color .15s;
}
.btn-icon-sm:hover { background: rgba(255,255,255,.07); color: var(--primary); }

/* Enfants */
.children-grid { display: flex; flex-direction: column; gap: 5px; margin-bottom: 22px; }
.child-card {
  display: flex;
  align-items: center;
  gap: 11px;
  padding: 11px 13px;
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: 9px;
  cursor: pointer;
  transition: border-color .13s, background .13s;
}
.child-card:hover { border-color: var(--primary); background: rgba(29,158,117,.04); }
.child-icon {
  font-size: 16px;
  width: 30px; height: 30px;
  background: rgba(29,158,117,.1);
  border-radius: 7px;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
  color: var(--primary);
}
.child-icon.cold { background: rgba(96,165,250,.1); color: #60a5fa; }
.child-info { flex: 1; min-width: 0; }
.child-name { font-size: 13px; font-weight: 500; color: var(--text); }
.child-meta { font-size: 11px; color: var(--text2); margin-top: 2px; }
.child-chip { font-size: 11px; padding: 2px 8px; border-radius: 10px; font-weight: 500; flex-shrink: 0; }
.chip-danger { background: rgba(226,75,74,.1); color: #E24B4A; }
.chip-warn   { background: rgba(239,159,39,.1); color: #EF9F27; }
.chip-ok     { background: rgba(29,158,117,.1); color: var(--primary); }
.chip-low    { background: rgba(245,158,11,.1); color: #f59e0b; }
.child-arrow { color: var(--text3); font-size: 13px; }

/* Boutons */
.btn-primary {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 7px 13px;
  background: var(--primary); color: #fff;
  border: none; border-radius: 8px;
  font-size: 13px; font-weight: 500; cursor: pointer;
  transition: background .13s; text-decoration: none;
}
.btn-primary:hover { opacity: .85; }
.btn-ghost {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 7px 13px;
  background: transparent; color: var(--text2);
  border: 1px solid var(--border); border-radius: 8px;
  font-size: 13px; cursor: pointer;
  transition: border-color .13s, color .13s; text-decoration: none;
}
.btn-ghost:hover { border-color: var(--border2); color: var(--text); }
.btn-danger-ghost {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 7px 13px;
  background: transparent; color: #E24B4A;
  border: 1px solid rgba(226,75,74,.3); border-radius: 8px;
  font-size: 13px; cursor: pointer; transition: background .13s;
}
.btn-danger-ghost:hover { background: rgba(226,75,74,.08); }
.btn-icon {
  width: 28px; height: 28px;
  border: 1px solid var(--border); border-radius: 7px;
  background: transparent; color: var(--text2);
  display: flex; align-items: center; justify-content: center;
  cursor: pointer; font-size: 14px;
  transition: color .13s, border-color .13s;
}
.btn-icon:hover { color: var(--text); border-color: var(--border2); }

/* Modale */
.modal-overlay {
  position: fixed; inset: 0;
  background: rgba(0,0,0,.55);
  display: none; align-items: center; justify-content: center;
  z-index: 9999; backdrop-filter: blur(3px);
}
.modal-overlay.open { display: flex; }
.modal-box {
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: 14px; width: 440px;
  max-width: calc(100vw - 32px);
  animation: slideUp .18s ease;
}
@keyframes slideUp { from { opacity:0;transform:translateY(14px) } to { opacity:1;transform:none } }
.modal-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 15px 18px;
  border-bottom: 1px solid var(--border);
  font-size: 14px; font-weight: 600; color: var(--text);
}
.modal-body { padding: 18px; display: flex; flex-direction: column; gap: 13px; }
.modal-footer {
  display: flex; justify-content: flex-end; gap: 8px;
  padding: 13px 18px;
  border-top: 1px solid var(--border);
}
.form-group { display: flex; flex-direction: column; gap: 4px; }
.form-group label { font-size: 12px; color: var(--text2); }
.form-group label .req { color: #E24B4A; }
.form-group input, .form-group select {
  background: var(--bg);
  border: 1px solid var(--border);
  border-radius: 7px; padding: 7px 11px;
  font-size: 13px; color: var(--text);
  outline: none; transition: border-color .13s;
}
.form-group input:focus, .form-group select:focus { border-color: var(--primary); }
.form-group select option { background: var(--bg2); }
.form-group-inline { margin-top: 2px; }
.form-group-inline label { flex-direction: row !important; align-items: center; gap: 8px; cursor: pointer; }
.toggle-label { font-size: 13px !important; color: var(--text) !important; display: flex; align-items: center; gap: 8px; }
.toggle-label input[type=checkbox] { accent-color: var(--primary); width: 15px; height: 15px; flex-shrink: 0; }
.form-row-temp { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
/* Sélecteur d'icône */
.icone-picker { display: flex; flex-wrap: wrap; gap: 6px; max-height: 200px; overflow-y: auto; padding: 2px; }
.icone-sep { flex-basis: 100%; font-size: 10px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: var(--text3); margin: 8px 2px 1px; }
.icone-opt svg { display: block; }
.icone-opt {
  width: 34px; height: 34px; border: 1px solid var(--border); border-radius: 9px;
  background: var(--bg); display: flex; align-items: center; justify-content: center;
  font-size: 18px; cursor: pointer; transition: border-color .12s, background .12s;
}
.icone-opt:hover { border-color: var(--primary); }
.icone-opt.sel { border-color: var(--primary); background: var(--primary-bg); box-shadow: 0 0 0 1px var(--primary) inset; }
.icone-opt.none { font-size: 10px; color: var(--text3); font-weight: 700; }
.modal-error {
  font-size: 12px; color: #E24B4A; display: none;
  padding: 6px 10px;
  background: rgba(226,75,74,.07);
  border-radius: 6px;
  border: 1px solid rgba(226,75,74,.2);
}
.modal-error.show { display: block; }
.spin { animation: spin 1s linear infinite; display: inline-block; }
@keyframes spin { to { transform: rotate(360deg); } }

/* ══════════════════════════════════════
   SECTION LABELS
══════════════════════════════════════ */
.section-label {
  display: flex; align-items: center; gap: 8px;
  font-size: 11px; font-weight: 700;
  letter-spacing: .08em; text-transform: uppercase;
  color: var(--text2); margin-bottom: 12px;
}
.section-label i { font-size: 14px; color: var(--primary); }
.section-count {
  background: rgba(var(--primary-rgb),.1);
  color: var(--primary);
  font-size: 10px; font-weight: 700;
  padding: 1px 7px; border-radius: 10px;
}

/* ══════════════════════════════════════
   SAC GRID (compartiments / sous-sacs)
══════════════════════════════════════ */
.sac-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
  gap: 12px; margin-bottom: 28px;
}
.sac-card {
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: 14px;
  padding: 16px 14px;
  cursor: pointer;
  transition: transform .18s, border-color .18s, box-shadow .18s;
  position: relative; overflow: hidden;
}
.sac-card::before {
  content: ''; position: absolute;
  top: 0; left: 0; right: 0; height: 3px;
  border-radius: 14px 14px 0 0;
}
.sac-card.sac-urgent::before  { background: #E24B4A; }
.sac-card.sac-warn::before    { background: #EF9F27; }
.sac-card.sac-ok::before      { background: var(--primary); opacity: .5; }
.sac-card:hover {
  transform: translateY(-3px);
  border-color: rgba(var(--primary-rgb),.35);
  box-shadow: 0 8px 24px rgba(0,0,0,.12);
}
.sac-card-top {
  display: flex; align-items: flex-start;
  justify-content: space-between; margin-bottom: 10px;
}
.sac-card-icon {
  width: 38px; height: 38px;
  background: rgba(var(--primary-rgb),.1);
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 18px; color: var(--primary);
}
.sac-card-icon.cold { background: rgba(96,165,250,.1); color: #60a5fa; }
.sac-urgence-dot { font-size: 16px; }
.sac-card-name {
  font-size: 13px; font-weight: 700;
  color: var(--text); line-height: 1.3;
  margin-bottom: 8px;
}
.sac-card-meta {
  display: flex; align-items: baseline; gap: 4px;
  margin-bottom: 8px;
}
.sac-meta-nb {
  font-size: 22px; font-weight: 800;
  color: var(--text); line-height: 1;
}
.sac-meta-lbl { font-size: 11px; color: var(--text2); }
.sac-card-status {
  font-size: 11px; font-weight: 600;
  padding: 3px 9px; border-radius: 20px;
  display: inline-block;
}
.sac-status-urgent { background: rgba(226,75,74,.12); color: #E24B4A; }
.sac-status-warn   { background: rgba(239,159,39,.12); color: #EF9F27; }
.sac-status-ok     { background: rgba(29,158,117,.1);  color: #1D9E75; }

/* ══════════════════════════════════════
   MED SUMMARY BAR
══════════════════════════════════════ */
.med-summary {
  display: flex; gap: 10px; margin-bottom: 16px; flex-wrap: wrap;
}
.med-sum-item {
  display: flex; align-items: center; gap: 6px;
  padding: 8px 14px; border-radius: 10px;
  border: 1px solid var(--border);
  background: var(--bg2);
}
.sum-nb { font-size: 20px; font-weight: 800; color: var(--text); }
.sum-lbl { font-size: 11px; color: var(--text2); text-transform: uppercase; letter-spacing:.05em; }
.sum-danger { border-color: rgba(226,75,74,.3); background: rgba(226,75,74,.06); }
.sum-danger .sum-nb { color: #E24B4A; }
.sum-warn   { border-color: rgba(239,159,39,.3); background: rgba(239,159,39,.06); }
.sum-warn   .sum-nb { color: #EF9F27; }
.sum-ok     { border-color: rgba(29,158,117,.2); background: rgba(29,158,117,.05); }
.sum-ok     .sum-nb { color: #1D9E75; }

/* ══════════════════════════════════════
   MED TABLE — Tableau médicaments propre
══════════════════════════════════════ */
.mt-summary {
  display: flex; gap: 8px; margin-bottom: 14px; flex-wrap: wrap;
}
.mt-sum-chip {
  display: flex; align-items: center; gap: 6px;
  padding: 6px 14px; border-radius: 20px;
  border: 1px solid var(--border); background: var(--bg2);
}
.mt-sum-nb  { font-size: 17px; font-weight: 800; }
.mt-sum-lbl { font-size: 11px; color: var(--text2); text-transform: uppercase; letter-spacing:.04em; }
.mt-sum-total   .mt-sum-nb { color: var(--text); }
.mt-sum-expired { border-color: rgba(107,114,128,.3); background: rgba(107,114,128,.06); }
.mt-sum-expired .mt-sum-nb { color: #9CA3AF; }
.mt-sum-critical{ border-color: rgba(226,75,74,.3);  background: rgba(226,75,74,.06); }
.mt-sum-critical .mt-sum-nb { color: #E24B4A; }
.mt-sum-warning { border-color: rgba(239,159,39,.3); background: rgba(239,159,39,.06); }
.mt-sum-warning .mt-sum-nb { color: #EF9F27; }
.mt-sum-ok      { border-color: rgba(29,158,117,.25); background: rgba(29,158,117,.05); }
.mt-sum-ok  .mt-sum-nb { color: #1D9E75; }

.med-table-wrap {
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: 14px;
  overflow: hidden;
  margin-bottom: 24px;
}
.med-table { width: 100%; border-collapse: collapse; }
.mt-card-head { display: flex; align-items: center; justify-content: space-between; padding: 11px 16px; border-bottom: 1px solid var(--border); }
.mt-card-title { font-weight: 700; font-size: 13px; text-transform: uppercase; letter-spacing: .05em; color: var(--text2); }
.mt-th-lot, .mt-td-lot { font-size: 12.5px; color: var(--text2); font-variant-numeric: tabular-nums; }
.mt-th {
  padding: 10px 14px;
  font-size: 10px; font-weight: 700;
  letter-spacing: .08em; text-transform: uppercase;
  color: var(--text2); background: var(--bg3);
  border-bottom: 1px solid var(--border);
  text-align: left; white-space: nowrap;
}
.mt-th-num    { width: 36px; text-align: center; color: var(--text3); }
.mt-th-qty    { width: 90px; }
.mt-th-date   { width: 120px; }
.mt-th-status { width: 80px; text-align: center; }
.mt-th-action { width: 36px; }

.mt-row { border-bottom: 1px solid var(--border); transition: background .1s; }
.mt-row:last-child { border-bottom: none; }
.mt-row:hover { background: rgba(var(--primary-rgb),.025); }
.mt-row.mt-row-expired  { background: rgba(107,114,128,.04); }
.mt-row.mt-row-critical { background: rgba(226,75,74,.05); }
.mt-row.mt-row-warning  { background: rgba(239,159,39,.04); }

.mt-td { padding: 11px 14px; vertical-align: middle; }
.mt-td-num {
  text-align: center; font-size: 11px;
  color: var(--text3); font-weight: 600;
  font-variant-numeric: tabular-nums;
}
.mt-td-nom {
  display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
}
.mt-dot {
  width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0;
}
.dot-expired  { background: #9CA3AF; }
.dot-critical { background: #E24B4A; box-shadow: 0 0 6px rgba(226,75,74,.5); }
.dot-warning  { background: #EF9F27; }
.dot-ok       { background: #1D9E75; }
.mt-nom {
  font-size: 13px; font-weight: 600; color: var(--text);
  line-height: 1.3;
}
.mt-dose {
  font-size: 11.5px; color: var(--text3); font-weight: 500; white-space: nowrap;
}
.mt-dose b { color: var(--text2); font-weight: 600; }
.mt-nom2 { font-weight: 500; font-size: 12px; color: var(--text3); }
.mt-note {
  font-size: 10px; font-weight: 600;
  padding: 2px 7px; border-radius: 4px;
  background: rgba(239,159,39,.1); color: #EF9F27;
}
.mt-qty  { font-size: 14px; font-weight: 700; color: var(--text); font-variant-numeric: tabular-nums; }
.mt-unit { font-size: 11px; color: var(--text2); margin-left: 3px; }
.mt-td-date {
  font-size: 12px; font-weight: 500; color: var(--text2);
  white-space: nowrap;
}
.mt-row.mt-row-critical .mt-td-date { color: #E24B4A; font-weight: 700; }
.mt-row.mt-row-warning  .mt-td-date { color: #EF9F27; font-weight: 600; }
.mt-td-status { text-align: center; }
.mt-pill {
  display: inline-flex; align-items: center; justify-content: center;
  font-size: 11px; font-weight: 700;
  padding: 3px 10px; border-radius: 20px;
  white-space: nowrap;
}
.mt-pill-expired  { background: rgba(107,114,128,.15); color: #9CA3AF; }
.mt-pill-critical { background: rgba(226,75,74,.15);   color: #E24B4A; }
.mt-pill-warning  { background: rgba(239,159,39,.15);  color: #EF9F27; }
.mt-pill-ok       { background: rgba(29,158,117,.12);  color: #1D9E75; }
.mt-edit {
  width: 28px; height: 28px; border-radius: 7px;
  display: flex; align-items: center; justify-content: center;
  color: var(--text3); text-decoration: none; font-size: 14px;
  transition: background .15s, color .15s;
}
.mt-edit:hover { background: rgba(var(--primary-rgb),.1); color: var(--primary); }
</style>

<script>


// ══════════════════════════════════════════
// ÉTAT
// ══════════════════════════════════════════
let treeData    = [];
let activeId    = null;
let expandedIds = new Set();
let allNodes    = [];

// ══════════════════════════════════════════
// INIT
// ══════════════════════════════════════════
async function init() {
  try {
    const res = await fetch('locations.php?ajax=tree');
    treeData  = await res.json();
    flattenTree(treeData, allNodes);
    renderTree();
    const _ne = treeData.length;
    const _np = treeData.reduce((s, n) => s + (parseInt(n.nb_produits) || 0), 0);
    const _sb = document.getElementById('treeSummary');
    if (_sb) _sb.innerHTML = `${_ne} emplacement${_ne>1?'s':''}<br>${_np} produit${_np>1?'s':''}`;
    if (!_selFromUrl() && treeData.length > 0) selectNode(treeData[0].id, false);
  } catch(e) {
    document.getElementById('treeContainer').innerHTML =
      '<div class="tree-loading" style="color:#E24B4A"><i class="ti ti-alert-circle"></i> Erreur de chargement</div>';
  }
}

function flattenTree(nodes, arr, depth = 0) {
  nodes.forEach(n => {
    arr.push({ id: n.id, nom: n.nom, depth });
    if (n.children) flattenTree(n.children, arr, depth + 1);
  });
}

// ══════════════════════════════════════════
// ICÔNES (SVG couleur : sacs, sacs à dos, perfusion — ou emoji)
// ══════════════════════════════════════════
const ICON_COLORS = {red:'#E24B4A',org:'#F97316',amb:'#F59E0B',grn:'#10B981',teal:'#14B8A6',blue:'#3B82F6',vio:'#8B5CF6',pink:'#EC4899',grey:'#64748B',navy:'#1E2A4A'};
const ICON_COLOR_ORDER = ['red','org','amb','grn','teal','blue','vio','pink','grey','navy'];
function _svgSac(c,s){return `<svg viewBox="0 0 48 48" width="${s}" height="${s}" aria-hidden="true"><path d="M16 17a8 8 0 0 1 16 0" fill="none" stroke="${c}" stroke-width="2.6" stroke-linecap="round" opacity=".55"/><rect x="7" y="16" width="34" height="23" rx="6" fill="${c}"/><rect x="7" y="16" width="34" height="10" rx="6" fill="#fff" opacity=".14"/><rect x="21.4" y="21.5" width="5.2" height="12" rx="1.6" fill="#fff"/><rect x="18" y="25" width="12" height="5.2" rx="1.6" fill="#fff"/></svg>`;}
function _svgDos(c,s){return `<svg viewBox="0 0 48 48" width="${s}" height="${s}" aria-hidden="true"><path d="M16 17a8 8 0 0 1 16 0" fill="none" stroke="${c}" stroke-width="2.6" stroke-linecap="round" opacity=".55"/><rect x="8" y="13" width="32" height="30" rx="9" fill="${c}"/><rect x="8" y="13" width="32" height="10" rx="9" fill="#fff" opacity=".12"/><rect x="16" y="25" width="16" height="15" rx="4" fill="#fff" opacity=".92"/><rect x="22.4" y="28" width="3.2" height="9" rx="1.2" fill="${c}"/><rect x="19" y="31" width="10" height="3.2" rx="1.2" fill="${c}"/></svg>`;}
function _svgPerf(c,s){return `<svg viewBox="0 0 48 48" width="${s}" height="${s}" aria-hidden="true"><rect x="16" y="9" width="16" height="22" rx="6" fill="${c}"/><rect x="16" y="9" width="16" height="8" rx="6" fill="#fff" opacity=".18"/><rect x="22.5" y="31" width="3" height="6" fill="${c}" opacity=".6"/><path d="M24 37c3 3 3 6 0 6s-3-3 0-6z" fill="${c}"/></svg>`;}
function isSvgIcon(code){ return /^(sac|dos|perf)-\w+$/.test(code||''); }
function iconHtml(code, size){
  const m = /^(sac|dos|perf)-(\w+)$/.exec(code||'');
  if (m && ICON_COLORS[m[2]]) {
    const c = ICON_COLORS[m[2]];
    return m[1]==='sac' ? _svgSac(c,size) : m[1]==='dos' ? _svgDos(c,size) : _svgPerf(c,size);
  }
  return code ? `<span style="font-size:${Math.round(size*0.8)}px">${code}</span>` : '';
}

// ══════════════════════════════════════════
// ARBRE
// ══════════════════════════════════════════
function renderTree(filter = '') {
  const c = document.getElementById('treeContainer');
  c.innerHTML = '';
  // Chaque PUI est suivie de SES sacs rattachés (pui_id), décalés dessous
  // (rail violet). Les sacs non rattachés sont listés à la fin. Purement
  // visuel/organisationnel : la hiérarchie réelle (parent_id) n'est pas modifiée.
  const puis   = treeData.filter(n => n.type === 'pui');
  const autres = treeData.filter(n => n.type !== 'pui');
  if (puis.length) {
    const puiIds = new Set(puis.map(p => String(p.id)));
    puis.forEach(pui => {
      c.appendChild(buildTreeDOM([pui], 0, filter));
      const sacs = autres.filter(b => String(b.pui_id) === String(pui.id));
      if (sacs.length) {
        const under = document.createElement('div');
        under.className = 'under-pui';
        under.appendChild(buildTreeDOM(sacs, 0, filter));
        c.appendChild(under);
      }
    });
    // Sacs non rattachés à une PUI (ou PUI inexistante) → en bas, sans rail
    const orphelins = autres.filter(b => !b.pui_id || !puiIds.has(String(b.pui_id)));
    if (orphelins.length) c.appendChild(buildTreeDOM(orphelins, 0, filter));
  } else {
    c.appendChild(buildTreeDOM(autres, 0, filter));
  }
}

function buildTreeDOM(nodes, depth, filter) {
  const wrap = document.createElement('div');
  nodes.forEach(node => {
    const hasChildren = node.children && node.children.length > 0;
    const matchSelf   = !filter || node.nom.toLowerCase().includes(filter.toLowerCase());
    const matchChild  = filter ? nodeContainsFilter(node, filter) : true;
    if (filter && !matchSelf && !matchChild) return;

    const isOpen   = expandedIds.has(node.id) || (!!filter && matchChild);
    const isActive = node.id === activeId;
    const perimes  = parseInt(node.nb_perimes) || 0;
    const crit     = parseInt(node.nb_crit)    || 0;

    const badgeHtml = perimes > 0
      ? `<span class="node-badge badge-danger">${perimes} périmé${perimes>1?'s':''}</span>`
      : crit > 0
      ? `<span class="node-badge badge-warn">${crit} critique${crit>1?'s':''}</span>`
      : '';

    const nodeEl = document.createElement('div');
    nodeEl.className = 'tree-node' + (isActive ? ' active' : '');
    nodeEl.style.paddingLeft = (10 + depth * 16) + 'px';
    nodeEl.dataset.id = node.id;

    // Méta compacte : on ne garde que ce qui compte (périmés + contrôle scellé
    // en retard). On retire « X compartiments · Y produits » pour alléger le nœud.
    const metaParts = [];
    if (perimes > 0) metaParts.push(`<span style="color:#E24B4A;font-weight:600">${perimes} périmé${perimes>1?'s':''}</span>`);
    const SEAL_OVERDUE_DAYS = 30;
    if (depth === 0 && node.type !== 'pui' && node.controle_le) {
      const _d = Math.floor((Date.now() - new Date(node.controle_le).getTime()) / 86400000);
      if (_d > SEAL_OVERDUE_DAYS) metaParts.push(`<span class="seal-late">⏰ contrôle en retard</span>`);
    }
    const meta = metaParts.join(' · ');
    // Icône : celle choisie (SVG couleur ou emoji), sinon une icône par défaut
    const isPui   = node.type === 'pui';
    const defEmoji = isPui ? '🏢' : node.chaine_froid==1 ? '❄️' : (depth===0 ? '🚑' : '💊');
    const iconMk  = node.icone ? iconHtml(node.icone, 26) : `<span style="font-size:16px">${defEmoji}</span>`;
    let tileBg, tileFg;
    if (perimes > 0)                 { tileBg = 'rgba(226,75,74,.14)';  tileFg = '#E24B4A'; }  // périmés → rouge
    else if (crit > 0)               { tileBg = 'rgba(239,159,39,.14)'; tileFg = '#EF9F27'; }  // critiques → ambre
    else if (isPui)                  { tileBg = 'rgba(139,92,246,.16)'; tileFg = '#8B5CF6'; }  // PUI → violet
    else if (node.chaine_froid == 1) { tileBg = 'rgba(96,165,250,.16)'; tileFg = '#60a5fa'; }  // froid → bleu
    else                             { tileBg = 'rgba(31,175,116,.14)';  tileFg = '#1FAF74'; }  // OK → vert
    // Tuile neutre quand l'icône est un SVG coloré (elle porte déjà sa couleur)
    if (isSvgIcon(node.icone)) { tileBg = 'var(--bg3)'; }

    nodeEl.innerHTML = `
      <span class="n-tile" style="background:${tileBg};color:${tileFg}">${iconMk}</span>
      <div class="n-body">
        <div class="n-name">${escHtml(node.nom)}${isPui ? ' <span class="pui-tag">PUI</span>' : ''}</div>
        ${meta ? `<div class="n-meta">${meta}</div>` : ''}
      </div>
      <div class="n-right">
        <span class="node-toggle ${hasChildren ? (isOpen?'open':'') : 'leaf'}" data-toggle="${node.id}">
          <i class="ti ti-chevron-right" aria-hidden="true"></i>
        </span>
      </div>
    `;

    nodeEl.querySelector('[data-toggle]').addEventListener('click', e => {
      e.stopPropagation();
      if (!hasChildren) return;
      expandedIds.has(node.id) ? expandedIds.delete(node.id) : expandedIds.add(node.id);
      renderTree(document.getElementById('searchTree').value);
    });
    nodeEl.addEventListener('click', () => selectNode(node.id));
    wrap.appendChild(nodeEl);

    if (hasChildren) {
      const childWrap = document.createElement('div');
      childWrap.className = 'tree-children' + (isOpen ? ' open' : '');
      childWrap.appendChild(buildTreeDOM(node.children, depth + 1, filter));
      wrap.appendChild(childWrap);
    }
  });
  return wrap;
}

function nodeContainsFilter(node, filter) {
  if (node.nom.toLowerCase().includes(filter.toLowerCase())) return true;
  return (node.children || []).some(c => nodeContainsFilter(c, filter));
}

function expandParentsOf(id, nodes, parents = []) {
  for (const n of nodes) {
    if (n.id === id) { parents.forEach(p => expandedIds.add(p)); return true; }
    if (n.children && expandParentsOf(id, n.children, [...parents, n.id])) return true;
  }
  return false;
}

// ══════════════════════════════════════════
// SÉLECTION
// ══════════════════════════════════════════
async function selectNode(id, autoExpand = true) {
  const wasActive = (activeId === id);
  activeId = id;
  expandParentsOf(id, treeData);
  if (autoExpand) {
    if (wasActive) {
      expandedIds.has(id) ? expandedIds.delete(id) : expandedIds.add(id);
    } else {
      expandedIds.add(id);
    }
  }
  renderTree(document.getElementById('searchTree').value);

  const el = document.querySelector(`.tree-node[data-id="${id}"]`);
  if (el) el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });

  document.getElementById('mainEmpty').style.display   = 'none';
  document.getElementById('mainContent').style.display = 'none';

  const [res, resP] = await Promise.all([
    fetch(`locations.php?ajax=node&id=${id}`),
    fetch(`locations.php?ajax=products&id=${id}`)
  ]);
  const data     = await res.json();
  const products = await resP.json();
  if (data.error) return;
  renderDetail(data, products);
}

// ══════════════════════════════════════════
// DÉTAIL
// ══════════════════════════════════════════
function toggleLotGroup(gid){
  var open=false;
  document.querySelectorAll('.sub-'+gid).forEach(function(r){
    r.style.display = (r.style.display==='none')?'':'none';
    if (r.style.display!=='none') open=true;
  });
  var chev=document.getElementById('chev-'+gid);
  if(chev) chev.style.transform = open?'rotate(180deg)':'';
}
function openDelete(el){
  document.getElementById('delNom').textContent = el.dataset.delNom || 'ce produit';
  document.getElementById('delConfirm').href = 'products.php?suppr=' + el.dataset.delId + '&csrf_token=<?= $csrf ?>' + (el.dataset.delRet ? '&ret=' + el.dataset.delRet : '');
  document.getElementById('delOverlay').classList.add('open');
}
function closeDelete(){ document.getElementById('delOverlay').classList.remove('open'); }
var _ctrlId = null;
function openControle(btn){
  _ctrlId = btn.dataset.ctrlId;
  document.getElementById('ctrlNom').textContent = 'Emplacement : ' + (btn.dataset.ctrlNom || '');
  document.getElementById('ctrlConforme').checked = true;
  document.getElementById('ctrlScelle').value = '';
  document.getElementById('ctrlNotes').value = '';
  document.getElementById('ctrlError').textContent = '';
  document.getElementById('ctrlOverlay').classList.add('open');
}
function closeControle(){ document.getElementById('ctrlOverlay').classList.remove('open'); }
async function saveControle(){
  var err = document.getElementById('ctrlError');
  var fd = new FormData();
  fd.append('emplacement_id', _ctrlId);
  fd.append('scelle_numero', document.getElementById('ctrlScelle').value.trim());
  if (document.getElementById('ctrlConforme').checked) fd.append('conforme', '1');
  fd.append('notes', document.getElementById('ctrlNotes').value.trim());
  fd.append('csrf_token', '<?= $csrf ?>');
  try {
    var r = await fetch('locations.php?ajax=controle', { method:'POST', body: fd });
    var d = await r.json();
    if (d.error) { err.textContent = d.error; return; }
    closeControle();
    selectNode(parseInt(_ctrlId), false);
  } catch(e) { err.textContent = 'Erreur réseau'; }
}
function _selFromUrl(){
  var sp = new URLSearchParams(location.search).get('sel');
  if (sp) { selectNode(parseInt(sp), true); return true; }
  return false;
}
(function(){
  if (document.getElementById('mt-lot-css')) return;
  var st=document.createElement('style'); st.id='mt-lot-css';
  st.textContent='.mt-lotbadge{font-size:11px;color:var(--primary);font-weight:700;margin-left:6px;white-space:nowrap}.mt-lotbadge .ti{font-size:12px;transition:transform .2s;vertical-align:middle}.mt-sublot{background:var(--glass,rgba(0,0,0,.035))}.mt-sublotlabel{font-size:12.5px;color:var(--text2);font-family:var(--mono,monospace)}';
  document.head.appendChild(st);
})();
function renderDetail({ node, breadcrumb, enfants, stats, last_remp }, products = []) {
  // Fil d'ariane : uniquement les parents (on ne répète pas le nom courant,
  // déjà affiché dans le titre). Vide pour un emplacement de 1er niveau.
  const bcHtml = breadcrumb.slice(0, -1).map(b =>
    `<a onclick="selectNode(${b.id})">${escHtml(b.nom)}</a>`
  ).join('<span class="sep">›</span>');

  const perimes = parseInt(stats.exp)   || 0;
  const crit    = parseInt(stats.crit)  || 0;
  const low     = parseInt(stats.low)   || 0;
  const total   = parseInt(stats.total) || 0;

  const rempDays  = last_remp !== false && last_remp ? Math.floor((Date.now() - new Date(last_remp).getTime()) / 86400000) : null;
  const rempText  = rempDays !== null ? rempDays + ' j' : 'Jamais';
  const rempClass = rempDays === null ? 'muted' : rempDays > 30 ? 'warn' : 'ok';

  const coldBlock = node.chaine_froid == 1 ? `
    <div class="loc-cold-badge">
      <i class="ti ti-snowflake" aria-hidden="true"></i>
      Chaîne du froid${node.temp_min || node.temp_max ? ` · ${node.temp_min??'?'}°C → ${node.temp_max??'?'}°C` : ''}
    </div>` : '';

  const enfantsHtml = enfants.length === 0 ? '' : `
    <div class="section-label">
      <i class="ti ti-briefcase-medical" aria-hidden="true"></i>
      Compartiments <span class="section-count">${enfants.length}</span>
    </div>
    <div class="med-table-wrap" style="margin-bottom:24px">
      <table class="med-table">
        <thead>
          <tr>
            <th class="mt-th mt-th-num">#</th>
            <th class="mt-th">Compartiment</th>
            <th class="mt-th" style="width:110px">Produits</th>
            <th class="mt-th" style="width:140px">Statut</th>
            <th class="mt-th" style="width:36px"></th>
          </tr>
        </thead>
        <tbody>
          ${enfants.map((e, i) => {
            const ep = parseInt(e.nb_perimes)||0, ec = parseInt(e.nb_crit)||0, np = parseInt(e.nb_produits)||0;
            const rowCls = ep > 0 ? 'mt-row-critical' : ec > 0 ? 'mt-row-warning' : '';
            const dot    = ep > 0 ? 'dot-critical' : ec > 0 ? 'dot-warning' : 'dot-ok';
            const pill   = ep > 0
              ? `<span class="mt-pill mt-pill-critical">🔴 ${ep} périmé${ep>1?'s':''}</span>`
              : ec > 0
              ? `<span class="mt-pill mt-pill-warning">🟡 ${ec} critique${ec>1?'s':''}</span>`
              : `<span class="mt-pill mt-pill-ok">🟢 OK</span>`;
            const cold = e.chaine_froid==1
              ? `<span class="mt-note">❄️ Froid</span>` : '';
            return `
              <tr class="mt-row ${rowCls}" onclick="selectNode(${e.id})" style="cursor:pointer"
                  onkeydown="if(event.key==='Enter')selectNode(${e.id})" tabindex="0">
                <td class="mt-td mt-td-num">${i+1}</td>
                <td class="mt-td mt-td-nom">
                  <span class="mt-dot ${dot}"></span>
                  <span class="mt-nom">${escHtml(e.nom)}</span>
                  ${cold}
                </td>
                <td class="mt-td">
                  <span class="mt-qty">${np}</span> <span class="mt-unit">produit${np!=1?'s':''}</span>
                </td>
                <td class="mt-td">${pill}</td>
                <td class="mt-td mt-td-action">
                  <i class="ti ti-chevron-right" style="color:var(--text3);font-size:14px" aria-hidden="true"></i>
                </td>
              </tr>`;
          }).join('')}
        </tbody>
      </table>
    </div>`;

  // Boutons d'action (PHP injecte la condition gestionnaire)
  const canEdit = <?php echo peut_faire(ROLE_GESTIONNAIRE) ? 'true' : 'false'; ?>;
  const canMod   = <?php echo action_permise('modifier')   ? 'true' : 'false'; ?>;
  const canRenew = <?php echo action_permise('renouveler') ? 'true' : 'false'; ?>;
  const canDel   = <?php echo action_permise('supprimer')  ? 'true' : 'false'; ?>;
  const canQr    = <?php echo nav_peut($nav_perms,'qrcodes') ? 'true' : 'false'; ?>;
  let actionsBtn = '';
  if (canQr)   actionsBtn += `<a class="btn-ghost" href="qrcodes.php?emplacement_id=${node.id}">▦ QR Code</a>`;
  if (canEdit) actionsBtn += `<button class="btn-primary" data-ctrl-id="${node.id}" data-ctrl-nom="${escHtml(node.nom||'')}" onclick="openControle(this)">🔒 Contrôler le scellé</button>`;
  if (canEdit) actionsBtn += `<button class="btn-ghost" onclick="openModal(true)">➕ Sous-emplacement</button>`;
  if (canEdit) actionsBtn += `<button class="btn-ghost" onclick="openModalEdit(${node.id})">✏️ Modifier</button>`;
  if (canDel)  actionsBtn += `<button class="btn-danger-ghost" onclick="deleteEmplacement(${node.id})">🗑️</button>`;

  // ── Liste des produits — tableau propre ──
  let produitsHtml = '';
  if (products.length > 0) {
    const nbExp  = products.filter(p => parseInt(p.jours_restants) < 0 && p.date_peremption !== '9999-12-31').length;
    const nbCrit = products.filter(p => { const j=parseInt(p.jours_restants); return j>=0&&j<=7&&p.date_peremption!=='9999-12-31'; }).length;
    const nbWarn = products.filter(p => { const j=parseInt(p.jours_restants); return j>7&&j<=30; }).length;
    const nbOk   = products.filter(p => parseInt(p.jours_restants) > 30 || p.date_peremption==='9999-12-31').length;

    const statusOf = (p) => {
      const j = parseInt(p.jours_restants);
      const isNone = p.date_peremption === '9999-12-31';
      let rowCls='', pill='', pillCls='', dot='dot-ok';
      if (!isNone) {
        if (j < 0)        { rowCls='mt-row-expired';  pill='Périmé'; pillCls='mt-pill-expired';  dot='dot-expired'; }
        else if (j <= 7)  { rowCls='mt-row-critical'; pill=j+'j';    pillCls='mt-pill-critical'; dot='dot-critical'; }
        else if (j <= 30) { rowCls='mt-row-warning';  pill=j+'j';    pillCls='mt-pill-warning';  dot='dot-warning'; }
        else              { pill=j+'j';               pillCls='mt-pill-ok'; }
      }
      const dateStr = isNone ? '—' : new Date(p.date_peremption).toLocaleDateString('fr-FR', {day:'2-digit', month:'short', year:'numeric'});
      return {rowCls, pill, pillCls, dot, dateStr};
    };
    const actionCell = (id, rq, nom) => `
            <div style="display:flex;gap:6px;justify-content:flex-end;align-items:center">
              ${canMod ? `<a href="products.php?emplacement=${node.id}&edit=${id}&ret=${node.id}" class="mt-edit" title="Modifier"><i class="ti ti-edit" aria-hidden="true"></i></a>` : ''}
              ${canRenew ? `<a href="products.php?emplacement=${node.id}&renew=${id}${rq?('&rq='+rq):''}&ret=${node.id}" class="mt-edit" title="Renouveler">♻️</a>` : ''}
              ${canDel ? `<a href="#" class="mt-edit" title="Supprimer" data-del-id="${id}" data-del-nom="${escHtml(nom||'')}" data-del-ret="${node.id}" onclick="openDelete(this);return false;">🗑️</a>` : ''}
            </div>`;
    const unitCount = (l) => Math.max(1, Math.round(parseFloat(l.quantite)||1));

    // Regrouper par nom ; déroulé UNITÉ par UNITÉ (une ligne par ampoule)
    const groups = {};
    products.forEach(p => { const gk=(p.nom||'')+''+(p.dosage||''); (groups[gk] = groups[gk] || []).push(p); });

    const rows = Object.keys(groups).sort((a,b)=>a.localeCompare(b,'fr')).map((gkey, gi) => {
      const lots = groups[gkey].slice().sort((a,b)=> new Date(a.date_peremption) - new Date(b.date_peremption));
      const totalUnits = lots.reduce((s,l)=> s + unitCount(l), 0);
      const first = lots[0];
      const nom = first.nom;
      const st = statusOf(first);
      const unite = escHtml(first.unite||'');
      const expandable = totalUnits > 1;
      // Dotation = quantité minimale attendue (quantite_min). Qté colorée :
      // rouge si sous la dotation, bleu s'il y en a plus, normale si pile.
      const dotation = Math.max(0, Math.round(parseFloat(first.quantite_min)||0));
      let qtyStyle = '';
      if (dotation > 0 && totalUnits < dotation)      qtyStyle = 'color:#C0271F;font-weight:700';
      else if (dotation > 0 && totalUnits > dotation) qtyStyle = 'color:#3B82F6;font-weight:700';

      let html = `
        <tr class="mt-row ${st.rowCls}" ${expandable ? `onclick="toggleLotGroup('g${gi}')" style="cursor:pointer"` : ''}>
          <td class="mt-td mt-td-nom">
            <span class="mt-dot ${st.dot}"></span>
            <span class="mt-nom">${escHtml(first.dci||nom)}${(first.dci && first.dci.toLowerCase()!==(nom||'').toLowerCase()) ? ` <span class="mt-nom2">(${escHtml(nom)})</span>` : ''}</span>
            ${(first.dosage||first.forme) ? `<span class="mt-dose">${first.dosage?`<b>${escHtml(first.dosage)}</b>`:''}${(first.dosage&&first.forme)?' · ':''}${first.forme?escHtml(first.forme):''}</span>` : ''}
            ${expandable ? `<span class="mt-lotbadge">${totalUnits} unités <i class="ti ti-chevron-down" id="chev-g${gi}"></i></span>` : ''}
          </td>
          <td class="mt-td mt-td-lot">${expandable ? '<span style="color:var(--text3)">—</span>' : escHtml(first.numero_lot||'—')}</td>
          <td class="mt-td mt-td-date">${st.dateStr}</td>
          <td class="mt-td mt-td-qty">${dotation>0?dotation:'—'}</td>
          <td class="mt-td mt-td-qty"><span class="mt-qty" style="${qtyStyle}">${totalUnits}</span> <span class="mt-unit">${unite}</span></td>
          <td class="mt-td mt-td-status">${st.pill ? `<span class="mt-pill ${st.pillCls}">${st.pill}</span>` : ''}</td>
          <td class="mt-td mt-td-action">${expandable ? '' : actionCell(first.id, null, nom)}</td>
        </tr>`;

      if (expandable) {
        let un = 0;
        lots.forEach(l => {
          const ls = statusOf(l);
          const qty = unitCount(l);
          for (let u = 0; u < qty; u++) {
            un++;
            html += `
        <tr class="mt-sublot sub-g${gi}" style="display:none">
          <td class="mt-td mt-td-nom" style="padding-left:24px">
            <span class="mt-dot ${ls.dot}"></span>
            <span class="mt-sublotlabel">unité ${un}</span>
          </td>
          <td class="mt-td mt-td-lot">${escHtml(l.numero_lot||'—')}</td>
          <td class="mt-td mt-td-date">${ls.dateStr}</td>
          <td class="mt-td mt-td-qty"></td>
          <td class="mt-td mt-td-qty"><span class="mt-qty">1</span> <span class="mt-unit">${escHtml(l.unite||'')}</span></td>
          <td class="mt-td mt-td-status">${ls.pill ? `<span class="mt-pill ${ls.pillCls}">${ls.pill}</span>` : ''}</td>
          <td class="mt-td mt-td-action">${actionCell(l.id, 1, nom)}</td>
        </tr>`;
          }
        });
      }
      return html;
    }).join('');

    produitsHtml = `
      <div class="med-table-wrap">
        <div class="mt-card-head"><span class="mt-card-title">Produits</span><span class="section-count">${products.length}</span></div>
        <table class="med-table">
          <thead>
            <tr>
              <th class="mt-th mt-th-nom">Produit</th>
              <th class="mt-th mt-th-lot">Lot</th>
              <th class="mt-th mt-th-date">Péremption</th>
              <th class="mt-th mt-th-qty">Dotation</th>
              <th class="mt-th mt-th-qty">Qté</th>
              <th class="mt-th mt-th-status">Statut</th>
              <th class="mt-th mt-th-action"></th>
            </tr>
          </thead>
          <tbody>${rows}</tbody>
        </table>
      </div>`;
  }

  document.getElementById('mainContent').innerHTML = `
    ${breadcrumb.length > 1 ? `<div class="loc-breadcrumb">${bcHtml}</div>` : ''}
    <div class="loc-header">
      <div class="loc-header-top">
        <div>
          <div class="loc-title">
            <span class="loc-emoji">${node.icone ? iconHtml(node.icone, 28) : (node.type==='pui'?'🏢':(node.chaine_froid==1?'❄️':'🚑'))}</span>
            ${escHtml(node.nom)}
            ${node.type==='pui' ? '<span class="pui-tag pui-tag-lg">PUI</span>' : ''}
          </div>
          ${node.type==='pui' ? `<div class="loc-subtitle" style="color:#8B5CF6">Pharmacie à usage intérieur — stock central du site</div>` : ''}
          ${node.description ? `<div class="loc-subtitle">${escHtml(node.description)}</div>` : ''}
          ${coldBlock}
        </div>
        <div class="loc-actions">${actionsBtn}</div>
      </div>
      <div class="loc-seal">
        <span aria-hidden="true">🔒</span>
        ${node.scelle_numero ? `Scellé <b>n°${escHtml(node.scelle_numero)}</b>` : '<span class="scelle-never">Aucun scellé</span>'}
        ${node.controle_le ? `<span class="scelle-sub">· contrôlé le ${new Date(node.controle_le).toLocaleDateString('fr-FR')}${node.controle_par_nom ? ' par '+escHtml(node.controle_par_nom) : ''}</span>` : `<span class="scelle-sub scelle-never">· jamais contrôlé</span>`}
      </div>
    </div>

    <div class="loc-health">
      <div class="loc-hchip"><div class="hn">${total}</div><div class="hl">Produits</div></div>
      <div class="loc-hchip"><div class="hn ${perimes>0?'danger':''}">${perimes}</div><div class="hl">Périmé${perimes>1?'s':''}</div></div>
      <div class="loc-hchip"><div class="hn ${crit>0?'warn':''}">${crit}</div><div class="hl">Critiques &lt; 7j</div></div>
      <div class="loc-hchip"><div class="hn">${rempText}</div><div class="hl">Dernier rempotage</div></div>
    </div>

    ${produitsHtml}
  `;
  document.getElementById('mainContent').style.display = 'block';
}

// ══════════════════════════════════════════
// MODALE
// ══════════════════════════════════════════
function buildParentSelect(excludeId = null) {
  const sel = document.getElementById('editParent');
  sel.innerHTML = '<option value="">— Aucun (racine) —</option>';
  allNodes.forEach(n => {
    if (n.id === excludeId) return;
    const opt = document.createElement('option');
    opt.value = n.id;
    opt.textContent = '\u00a0\u00a0'.repeat(n.depth) + n.nom;
    sel.appendChild(opt);
  });
}

const ICON_CHOICES = <?= json_encode($ICON_CHOICES, JSON_UNESCAPED_UNICODE) ?>;
function renderIconePicker(selected) {
  const box    = document.getElementById('editIconePicker');
  const hidden = document.getElementById('editIcone');
  hidden.value = selected || '';
  const opt = (val, inner, cls) =>
    `<div class="icone-opt ${cls||''} ${((selected||'')===val)?'sel':''}" data-ic="${val}">${inner}</div>`;
  const sep = (t) => `<div class="icone-sep">${t}</div>`;
  let html = opt('', 'Défaut', 'none');
  html += sep('Sacs');            ICON_COLOR_ORDER.forEach(k => html += opt('sac-'+k,  _svgSac(ICON_COLORS[k], 24)));
  html += sep('Sacs à dos');      ICON_COLOR_ORDER.forEach(k => html += opt('dos-'+k,  _svgDos(ICON_COLORS[k], 24)));
  html += sep('Perfusion');       ICON_COLOR_ORDER.forEach(k => html += opt('perf-'+k, _svgPerf(ICON_COLORS[k], 24)));
  html += sep('Autres (emoji)');  ICON_CHOICES.forEach(e => html += opt(e, e));
  box.innerHTML = html;
  box.querySelectorAll('.icone-opt').forEach(el => {
    el.addEventListener('click', () => {
      hidden.value = el.dataset.ic;
      box.querySelectorAll('.icone-opt').forEach(x => x.classList.remove('sel'));
      el.classList.add('sel');
    });
  });
}

function buildPuiSelect(selectedId) {
  const sel  = document.getElementById('editPui');
  const puis = (treeData || []).filter(n => n.type === 'pui');
  sel.innerHTML = '<option value="">— Aucune —</option>' +
    puis.map(p => `<option value="${p.id}">${escHtml(p.nom)}</option>`).join('');
  sel.value = selectedId ? String(selectedId) : '';
}
function togglePuiRow() {
  const isPui = document.getElementById('editType').value === 'pui';
  document.getElementById('puiRow').style.display = isPui ? 'none' : '';
}

function openModal(asSub = false) {
  document.getElementById('editId').value            = '';
  document.getElementById('editNom').value           = '';
  document.getElementById('editType').value          = 'standard';
  document.getElementById('editDesc').value          = '';
  document.getElementById('editChaineFroid').checked = false;
  document.getElementById('editTempMin').value       = '';
  document.getElementById('editTempMax').value       = '';
  document.getElementById('tempRow').style.display   = 'none';
  document.getElementById('modalError').classList.remove('show');
  document.getElementById('modalTitle').textContent  = asSub ? 'Nouveau sous-emplacement' : 'Nouvel emplacement';
  renderIconePicker('');
  buildPuiSelect('');
  togglePuiRow();
  buildParentSelect();
  if (asSub && activeId) document.getElementById('editParent').value = activeId;
  document.getElementById('modalOverlay').classList.add('open');
  setTimeout(() => document.getElementById('editNom').focus(), 80);
}

async function openModalEdit(id) {
  const res  = await fetch(`locations.php?ajax=node&id=${id}`);
  const data = await res.json();
  if (data.error) return;
  const n = data.node;
  document.getElementById('editId').value            = n.id;
  document.getElementById('editNom').value           = n.nom;
  document.getElementById('editType').value          = n.type || 'standard';
  document.getElementById('editDesc').value          = n.description || '';
  document.getElementById('editChaineFroid').checked = n.chaine_froid == 1;
  document.getElementById('editTempMin').value       = n.temp_min || '';
  document.getElementById('editTempMax').value       = n.temp_max || '';
  document.getElementById('tempRow').style.display   = n.chaine_froid == 1 ? 'grid' : 'none';
  document.getElementById('modalError').classList.remove('show');
  document.getElementById('modalTitle').textContent  = 'Modifier l\'emplacement';
  renderIconePicker(n.icone || '');
  buildPuiSelect(n.pui_id || '');
  togglePuiRow();
  buildParentSelect(n.id);
  document.getElementById('editParent').value = n.parent_id || '';
  document.getElementById('modalOverlay').classList.add('open');
  setTimeout(() => document.getElementById('editNom').focus(), 80);
}

function closeModal() {
  document.getElementById('modalOverlay').classList.remove('open');
}

document.getElementById('editChaineFroid').addEventListener('change', function() {
  document.getElementById('tempRow').style.display = this.checked ? 'grid' : 'none';
});
document.getElementById('editType').addEventListener('change', togglePuiRow);

async function saveEmplacement() {
  const nom   = document.getElementById('editNom').value.trim();
  const errEl = document.getElementById('modalError');
  if (!nom) { errEl.textContent = 'Le nom est obligatoire.'; errEl.classList.add('show'); return; }

  const res  = await fetch('locations.php?ajax=save', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': '<?= $csrf ?>' },
    body: JSON.stringify({
      id:           document.getElementById('editId').value || null,
      nom,
      type:         document.getElementById('editType').value || 'standard',
      icone:        document.getElementById('editIcone').value || null,
      pui_id:       document.getElementById('editPui').value || null,
      parent_id:    document.getElementById('editParent').value || null,
      description:  document.getElementById('editDesc').value.trim(),
      chaine_froid: document.getElementById('editChaineFroid').checked,
      temp_min:     document.getElementById('editTempMin').value,
      temp_max:     document.getElementById('editTempMax').value,
    })
  });
  const data = await res.json();
  if (data.error) { errEl.textContent = data.error; errEl.classList.add('show'); return; }

  closeModal();
  await reloadTree();
  selectNode(data.id);
}

async function deleteEmplacement(id) {
  if (!confirm('Supprimer cet emplacement ?\n(Impossible s\'il contient des produits actifs ou des sous-emplacements)')) return;
  const res  = await fetch('locations.php?ajax=delete', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': '<?= $csrf ?>' },
    body: JSON.stringify({ id })
  });
  const data = await res.json();
  if (data.error) { alert(data.error); return; }
  await reloadTree();
  if (!_selFromUrl() && treeData.length > 0) selectNode(treeData[0].id, false);
  else {
    activeId = null;
    document.getElementById('mainContent').style.display = 'none';
    document.getElementById('mainEmpty').style.display   = 'flex';
  }
}

async function reloadTree() {
  const res = await fetch('locations.php?ajax=tree');
  treeData  = await res.json();
  allNodes  = [];
  flattenTree(treeData, allNodes);
  renderTree(document.getElementById('searchTree').value);
}

// ══════════════════════════════════════════
// SEARCH + KEYBOARD
// ══════════════════════════════════════════
document.getElementById('searchTree').addEventListener('input', function() {
  renderTree(this.value);
});

document.addEventListener('keydown', e => {
  const tag = document.activeElement.tagName;
  if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
  if (e.key === 'n' && !e.ctrlKey && !e.metaKey) openModal();
  if (e.key === 'Escape') closeModal();
});

document.getElementById('modalOverlay').addEventListener('click', e => {
  if (e.target === document.getElementById('modalOverlay')) closeModal();
});

// ══════════════════════════════════════════
// UTILS
// ══════════════════════════════════════════
function escHtml(s) {
  return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

init();
</script>

<?php require_once 'includes/footer.php'; ?>

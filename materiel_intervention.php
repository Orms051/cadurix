<?php
/**
 * Cadurix v5.1 — Créer une intervention biomédical
 * Mobile-friendly, signature tactile
 */
require_once 'includes/auth.php';
$pageTitle = 'Nouvelle intervention';

$site_id     = site_actif();
$materiel_id = (int)($_GET['materiel_id'] ?? 0);
$revision_id = (int)($_GET['revision_id'] ?? 0);

if (!$materiel_id || !$site_id) { header('Location: materiel.php'); exit; }

// Charger l'équipement
$stmt = $pdo->prepare("SELECT m.*, mt.nom AS type_nom, mt.icone AS type_icone FROM materiels m LEFT JOIN materiel_types mt ON mt.id=m.type_id WHERE m.id=? AND m.site_id=?");
$stmt->execute([$materiel_id, $site_id]);
$mat = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$mat) { header('Location: materiel.php'); exit; }

// Révision liée
$revision = null;
if ($revision_id) {
    $s = $pdo->prepare("SELECT * FROM materiel_revisions WHERE id=? AND materiel_id=?");
    $s->execute([$revision_id, $materiel_id]);
    $revision = $s->fetch(PDO::FETCH_ASSOC);
}

// Traitement
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $f = [
        'materiel_id'     => $materiel_id,
        'user_id'         => currentUserId(),
        'type'            => $_POST['type'] ?? 'autre',
        'type_libre'      => trim($_POST['type_libre'] ?? '') ?: null,
        'date_intervention' => $_POST['date_intervention'] ?? date('Y-m-d'),
        'date_prochaine'  => $_POST['date_prochaine'] ?? null ?: null,
        'organisme'       => trim($_POST['organisme'] ?? '') ?: null,
        'technicien'      => trim($_POST['technicien'] ?? '') ?: null,
        'numero_suivi'    => trim($_POST['numero_suivi'] ?? '') ?: null,
        'description'     => trim($_POST['description'] ?? '') ?: null,
        'cout'            => $_POST['cout'] !== '' ? (float)$_POST['cout'] : null,
        'statut_apres'    => $_POST['statut_apres'] ?? 'operationnel',
        'signature'       => $_POST['signature'] ?? null ?: null,
    ];

    // Pièces remplacées
    $pieces = [];
    $p_noms = $_POST['piece_nom'] ?? [];
    $p_qtes = $_POST['piece_qte'] ?? [];
    foreach ($p_noms as $i => $pnom) {
        $pnom = trim($pnom);
        if ($pnom) $pieces[] = ['nom' => $pnom, 'qte' => (int)($p_qtes[$i] ?? 1)];
    }
    $pieces_json = !empty($pieces) ? json_encode($pieces) : null;

    $pdo->prepare("INSERT INTO materiel_interventions (materiel_id,user_id,type,type_libre,date_intervention,date_prochaine,organisme,technicien,numero_suivi,description,pieces_remplacees,cout,statut_apres,signature) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$f['materiel_id'],$f['user_id'],$f['type'],$f['type_libre'],$f['date_intervention'],$f['date_prochaine'],$f['organisme'],$f['technicien'],$f['numero_suivi'],$f['description'],$pieces_json,$f['cout'],$f['statut_apres'],$f['signature']]);
    $inter_id = (int)$pdo->lastInsertId();

    // Mettre à jour le statut de l'équipement
    $new_statut = match($f['statut_apres']) {
        'hors_service' => 'hors_service',
        'maintenance'  => 'maintenance',
        default        => 'en_service',
    };
    $pdo->prepare("UPDATE materiels SET statut=?,statut_date=NOW() WHERE id=?")->execute([$new_statut,$materiel_id]);

    // Mettre à jour la révision si liée
    if ($revision_id && $revision) {
        $freq = $revision['frequence_mois'];
        $prochaine = date('Y-m-d', strtotime($f['date_intervention']." +$freq months"));
        $pdo->prepare("UPDATE materiel_revisions SET derniere_revision=?,prochaine_revision=? WHERE id=?")
            ->execute([$f['date_intervention'],$prochaine,$revision_id]);
    }

    // Notification si HS
    if ($new_statut === 'hors_service') {
        $pdo->prepare("INSERT INTO notifications (type,titre,message,lien) VALUES('alerte',?,?,?)")
            ->execute(["⚠️ {$mat['nom']} hors service","Mis hors service suite à intervention","/materiel_fiche.php?id=$materiel_id"]);
    }

    // Upload photo si présente
    if (!empty($_FILES['photo']['name'][0])) {
        $dir = 'uploads/materiel/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        foreach ($_FILES['photo']['name'] as $i => $fname) {
            if ($_FILES['photo']['error'][$i] !== UPLOAD_ERR_OK) continue;
            $ext  = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png','webp'])) continue;
            // Valider le contenu reel (MIME) et la taille, pas seulement l'extension
            $mime = @mime_content_type($_FILES['photo']['tmp_name'][$i]);
            if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)) continue;
            if ($_FILES['photo']['size'][$i] > 5 * 1024 * 1024) continue; // max 5 Mo
            $file = $dir.'inter_'.$inter_id.'_'.$i.'_'.time().'.'.$ext;
            move_uploaded_file($_FILES['photo']['tmp_name'][$i], $file);
            $pdo->prepare("INSERT INTO materiel_photos (materiel_id,intervention_id,type,fichier) VALUES(?,?,?,?)")
                ->execute([$materiel_id,$inter_id,$i===0?'avant':'apres',$file]);
        }
    }

    audit_log($pdo,'INTERVENTION_MATERIEL','materiel_interventions',$inter_id,null,$f);
    flashSuccess("Intervention enregistrée !");
    header("Location: materiel_fiche.php?id=$materiel_id"); exit;
}

require_once 'includes/header.php';
?>
<style>
.sig-canvas{width:100%;height:150px;background:var(--surface2);border:1px solid var(--border);border-radius:12px;touch-action:none;display:block;cursor:crosshair}
.piece-row{display:flex;gap:8px;margin-bottom:6px;align-items:center}
</style>

<div class="page-header">
  <div class="page-title-group">
    <div style="font-size:.8rem;color:var(--text3);margin-bottom:.25rem">
      <?= h($mat['type_icone']??'🔧') ?> <?= h($mat['nom']) ?>
    </div>
    <div class="page-title">🔧 Nouvelle intervention</div>
    <?php if ($revision): ?>
    <div style="display:inline-flex;align-items:center;gap:.5rem;padding:.3rem .75rem;background:rgba(59,130,246,.1);border:1px solid rgba(59,130,246,.2);border-radius:8px;font-size:.8rem;margin-top:.25rem">
      ⏰ Révision : <?= h($revision['nom']) ?>
    </div>
    <?php endif; ?>
  </div>
  <a href="materiel_fiche.php?id=<?= $materiel_id ?>" class="btn btn-ghost">← Retour</a>
</div>

<form method="POST" enctype="multipart/form-data">
  <?= csrf_field() ?>

  <!-- Type + Date -->
  <div class="card" style="margin-bottom:1rem">
    <div class="card-title">📋 Intervention</div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Type *</label>
        <select name="type" class="form-control" onchange="toggleTypeLibre(this.value)">
          <option value="revision_periodique" <?= $revision?'selected':'' ?>>🔄 Révision périodique</option>
          <option value="reparation">🔨 Réparation</option>
          <option value="remplacement_piece">🔩 Remplacement de pièce</option>
          <option value="mise_a_jour">💾 Mise à jour logicielle</option>
          <option value="verification">✅ Vérification</option>
          <option value="checklist_reception">📋 Réception équipement</option>
          <option value="autre">🔧 Autre</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Date *</label>
        <input type="date" name="date_intervention" class="form-control" value="<?= date('Y-m-d') ?>" required>
      </div>
    </div>
    <div class="form-group" id="type-libre-group" style="display:none">
      <label class="form-label">Préciser le type</label>
      <input type="text" name="type_libre" class="form-control" placeholder="Type d'intervention libre...">
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Technicien / Opérateur</label>
        <input type="text" name="technicien" class="form-control" placeholder="Nom du technicien..." value="<?= h(trim(($_SESSION['user_prenom']??'').' '.($_SESSION['user_nom']??''))) ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Organisme</label>
        <input type="text" name="organisme" class="form-control" placeholder="Stryker France, interne...">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">N° de suivi / Dossier</label>
        <input type="text" name="numero_suivi" class="form-control" placeholder="REF-2024-001...">
      </div>
      <div class="form-group">
        <label class="form-label">Prochaine intervention</label>
        <input type="date" name="date_prochaine" class="form-control" value="<?= $revision?date('Y-m-d',strtotime(date('Y-m-d').' +'.$revision['frequence_mois'].' months')):'' ?>">
      </div>
    </div>
    <div class="form-group">
      <label class="form-label">Description</label>
      <textarea name="description" class="form-control" rows="3" placeholder="Détail de l'intervention effectuée..."></textarea>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Coût (€)</label>
        <input type="number" name="cout" class="form-control" step="0.01" min="0" placeholder="0.00">
      </div>
      <div class="form-group">
        <label class="form-label">Statut après intervention</label>
        <select name="statut_apres" class="form-control">
          <option value="operationnel">✅ Opérationnel</option>
          <option value="maintenance">🔧 En maintenance</option>
          <option value="hors_service">🔴 Hors service</option>
        </select>
      </div>
    </div>
  </div>

  <!-- Pièces remplacées -->
  <div class="card" style="margin-bottom:1rem">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.75rem">
      <div class="card-title" style="margin-bottom:0">🔩 Pièces remplacées</div>
      <button type="button" class="btn btn-ghost btn-sm" onclick="addPiece()">+ Ajouter</button>
    </div>
    <div id="pieces-list"></div>
    <p style="font-size:.78rem;color:var(--text3)">Optionnel — listez les pièces remplacées lors de cette intervention.</p>
  </div>

  <!-- Photos -->
  <div class="card" style="margin-bottom:1rem">
    <div class="card-title">📷 Photos (optionnel)</div>
    <p style="font-size:.8rem;color:var(--text2);margin-bottom:.75rem">La première photo = avant, la deuxième = après l'intervention.</p>
    <input type="file" name="photo[]" class="form-control" accept="image/*" multiple>
  </div>

  <!-- Signature -->
  <div class="card" style="margin-bottom:1rem">
    <div class="card-title">✍️ Signature</div>
    <p style="font-size:.8rem;color:var(--text2);margin-bottom:.75rem">Signez dans le cadre ci-dessous.</p>
    <canvas id="sig-canvas" class="sig-canvas"></canvas>
    <input type="hidden" name="signature" id="sig-data">
    <div style="display:flex;gap:.5rem;margin-top:.5rem">
      <button type="button" class="btn btn-ghost btn-sm" onclick="clearSig()">🗑️ Effacer</button>
    </div>
  </div>

  <button type="submit" class="btn btn-primary" style="width:100%;padding:1rem" onclick="saveSig()">
    💾 Enregistrer l'intervention
  </button>
</form>

<script>
// ── Pièces remplacées ─────────────────────────────────
let pieceCount = 0;
function addPiece() {
  pieceCount++;
  const div = document.createElement('div');
  div.className = 'piece-row';
  div.innerHTML = `
    <input type="text" name="piece_nom[]" class="form-control" placeholder="Nom de la pièce..." style="flex:2">
    <input type="number" name="piece_qte[]" class="form-control" value="1" min="1" style="flex:.5;max-width:70px">
    <button type="button" class="btn btn-ghost btn-xs" onclick="this.parentNode.remove()">✕</button>
  `;
  document.getElementById('pieces-list').appendChild(div);
}

// ── Type libre ────────────────────────────────────────
function toggleTypeLibre(val) {
  document.getElementById('type-libre-group').style.display = val==='autre' ? 'block' : 'none';
}

// ── Signature ─────────────────────────────────────────
const canvas = document.getElementById('sig-canvas');
const ctx    = canvas.getContext('2d');
let drawing  = false;
let hasSig   = false;

function initCanvas() {
  canvas.width  = canvas.offsetWidth;
  canvas.height = 150;
  ctx.strokeStyle = '#e2e8f0';
  ctx.lineWidth   = 2.5;
  ctx.lineCap     = 'round';
  ctx.lineJoin    = 'round';
}
initCanvas();
window.addEventListener('resize', initCanvas);

function getPos(e) {
  const r = canvas.getBoundingClientRect();
  const t = e.touches ? e.touches[0] : e;
  return [t.clientX-r.left, t.clientY-r.top];
}

canvas.addEventListener('pointerdown', e => { drawing=true; hasSig=true; const [x,y]=getPos(e); ctx.beginPath(); ctx.moveTo(x,y); });
canvas.addEventListener('pointermove', e => { if(!drawing) return; const [x,y]=getPos(e); ctx.lineTo(x,y); ctx.stroke(); });
canvas.addEventListener('pointerup',   () => { drawing=false; });

function clearSig() { ctx.clearRect(0,0,canvas.width,canvas.height); hasSig=false; document.getElementById('sig-data').value=''; }
function saveSig()  { if(hasSig) document.getElementById('sig-data').value=canvas.toDataURL('image/png',.6); }
</script>

<?php require_once 'includes/footer.php'; ?>

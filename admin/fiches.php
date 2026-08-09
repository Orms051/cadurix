<?php
/**
 * Cadurix v5.1 — Admin : Configuration des modèles de fiches
 * Accès : Administrateur + Super Admin
 */
require_once __DIR__ . '/../includes/auth.php';
requiert_role(ROLE_ADMIN);
$pageTitle = 'Config fiches de vérification';
$site_id   = site_actif();
if (!$site_id) { header('Location: ../dashboard.php'); exit; }

$msg = $err = '';

// ================================================================
//  TRAITEMENTS POST
// ================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    // ── Créer / modifier modèle ──────────────────────────────────
    if ($action === 'save_modele') {
        $id          = (int)($_POST['modele_id'] ?? 0);
        $nom         = trim($_POST['nom'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $type        = $_POST['type'] ?? 'generique';
        $frequence   = $_POST['frequence'] ?? 'quotidien';
        $actif       = isset($_POST['actif']) ? 1 : 0;

        $types_valides = ['emplacement','materiel','vehicule','generique'];
        $freq_valides  = ['quotidien','hebdomadaire','mensuel','ponctuel'];

        if (!$nom) {
            $err = 'Le nom du modèle est obligatoire.';
        } elseif (!in_array($type, $types_valides) || !in_array($frequence, $freq_valides)) {
            $err = 'Valeurs invalides.';
        } else {
            if ($id) {
                $pdo->prepare("UPDATE modeles_fiches SET nom=?,description=?,type=?,frequence=?,actif=?,modifie_le=NOW() WHERE id=? AND site_id=?")
                    ->execute([$nom,$description,$type,$frequence,$actif,$id,$site_id]);
                $msg = "Modèle mis à jour.";
            } else {
                $pdo->prepare("INSERT INTO modeles_fiches (site_id,nom,description,type,frequence,actif,cree_par) VALUES(?,?,?,?,?,?,?)")
                    ->execute([$site_id,$nom,$description,$type,$frequence,$actif,$_SESSION['user_id']]);
                $id = (int)$pdo->lastInsertId();
                $msg = "Modèle créé.";
            }
            audit_log($pdo, $id ? 'MODIF_FICHE_MODELE' : 'CREATION_FICHE_MODELE', 'modeles_fiches', $id);
            header("Location: fiches.php?modele_id=$id&msg=".urlencode($msg)); exit;
        }
    }

    // ── Supprimer modèle ─────────────────────────────────────────
    if ($action === 'delete_modele') {
        $id = (int)($_POST['modele_id'] ?? 0);
        $nb = (int)$pdo->query("SELECT COUNT(*) FROM fiches_remplies WHERE modele_id=$id")->fetchColumn();
        if ($nb > 0) {
            $err = "Impossible de supprimer : $nb fiche(s) remplie(s) liée(s) à ce modèle.";
        } else {
            $pdo->prepare("DELETE FROM modeles_fiches WHERE id=? AND site_id=?")->execute([$id,$site_id]);
            $msg = "Modèle supprimé.";
            audit_log($pdo, 'SUPPRESSION_FICHE_MODELE', 'modeles_fiches', $id);
            header('Location: fiches.php?msg='.urlencode($msg)); exit;
        }
    }

    // ── Créer / modifier section ──────────────────────────────────
    if ($action === 'save_section') {
        $id        = (int)($_POST['section_id'] ?? 0);
        $modele_id = (int)($_POST['modele_id'] ?? 0);
        $titre     = trim($_POST['titre'] ?? '');
        $ordre     = (int)($_POST['ordre'] ?? 0);

        if (!$titre || !$modele_id) {
            $err = 'Titre et modèle obligatoires.';
        } else {
            if ($id) {
                $pdo->prepare("UPDATE modele_sections SET titre=?,ordre=? WHERE id=?")->execute([$titre,$ordre,$id]);
                $msg = "Section mise à jour.";
            } else {
                $nb_sections = (int)$pdo->query("SELECT COUNT(*) FROM modele_sections WHERE modele_id=$modele_id")->fetchColumn();
                $pdo->prepare("INSERT INTO modele_sections (modele_id,titre,ordre) VALUES(?,?,?)")->execute([$modele_id,$titre,$nb_sections]);
                $msg = "Section créée.";
            }
            header("Location: fiches.php?modele_id=$modele_id&msg=".urlencode($msg)); exit;
        }
    }

    // ── Supprimer section ─────────────────────────────────────────
    if ($action === 'delete_section') {
        $id        = (int)($_POST['section_id'] ?? 0);
        $modele_id = (int)($_POST['modele_id'] ?? 0);
        $pdo->prepare("DELETE FROM modele_sections WHERE id=?")->execute([$id]);
        $msg = "Section supprimée.";
        header("Location: fiches.php?modele_id=$modele_id&msg=".urlencode($msg)); exit;
    }

    // ── Créer / modifier point ────────────────────────────────────
    if ($action === 'save_point') {
        $id         = (int)($_POST['point_id'] ?? 0);
        $section_id = (int)($_POST['section_id'] ?? 0);
        $modele_id  = (int)($_POST['modele_id'] ?? 0);
        $libelle    = trim($_POST['libelle'] ?? '');
        $type       = $_POST['type_champ'] ?? 'oui_non';
        $obligatoire= isset($_POST['obligatoire']) ? 1 : 0;
        $bloquant   = isset($_POST['bloquant']) ? 1 : 0;
        $biomed     = isset($_POST['peut_signaler_biomed']) ? 1 : 0;
        $val_min    = $_POST['valeur_min'] !== '' ? (float)$_POST['valeur_min'] : null;
        $val_max    = $_POST['valeur_max'] !== '' ? (float)$_POST['valeur_max'] : null;
        $unite      = trim($_POST['unite'] ?? '') ?: null;
        $ordre      = (int)($_POST['ordre'] ?? 0);

        $types_valides = ['oui_non','numerique','texte','photo'];

        if (!$libelle || !$section_id) {
            $err = 'Libellé et section obligatoires.';
        } elseif (!in_array($type, $types_valides)) {
            $err = 'Type de champ invalide.';
        } else {
            if ($id) {
                $pdo->prepare("UPDATE modele_points SET libelle=?,type=?,obligatoire=?,bloquant=?,peut_signaler_biomed=?,valeur_min=?,valeur_max=?,unite=?,ordre=? WHERE id=?")
                    ->execute([$libelle,$type,$obligatoire,$bloquant,$biomed,$val_min,$val_max,$unite,$ordre,$id]);
                $msg = "Point mis à jour.";
            } else {
                $nb_points = (int)$pdo->query("SELECT COUNT(*) FROM modele_points WHERE section_id=$section_id")->fetchColumn();
                $pdo->prepare("INSERT INTO modele_points (section_id,libelle,type,obligatoire,bloquant,peut_signaler_biomed,valeur_min,valeur_max,unite,ordre) VALUES(?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$section_id,$libelle,$type,$obligatoire,$bloquant,$biomed,$val_min,$val_max,$unite,$nb_points]);
                $msg = "Point ajouté.";
            }
            header("Location: fiches.php?modele_id=$modele_id&msg=".urlencode($msg)); exit;
        }
    }

    // ── Supprimer point ───────────────────────────────────────────
    if ($action === 'delete_point') {
        $id        = (int)($_POST['point_id'] ?? 0);
        $modele_id = (int)($_POST['modele_id'] ?? 0);
        $pdo->prepare("DELETE FROM modele_points WHERE id=?")->execute([$id]);
        $msg = "Point supprimé.";
        header("Location: fiches.php?modele_id=$modele_id&msg=".urlencode($msg)); exit;
    }

    // ── Dupliquer modèle ──────────────────────────────────────────
    if ($action === 'dupliquer_modele') {
        $id = (int)($_POST['modele_id'] ?? 0);
        $modele = $pdo->query("SELECT * FROM modeles_fiches WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
        if ($modele) {
            $pdo->prepare("INSERT INTO modeles_fiches (site_id,nom,description,type,frequence,actif,cree_par) VALUES(?,?,?,?,?,0,?)")
                ->execute([$site_id, 'Copie — '.$modele['nom'], $modele['description'], $modele['type'], $modele['frequence'], $_SESSION['user_id']]);
            $new_modele_id = (int)$pdo->lastInsertId();

            // Dupliquer sections + points
            $sections = $pdo->query("SELECT * FROM modele_sections WHERE modele_id=$id ORDER BY ordre")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($sections as $s) {
                $pdo->prepare("INSERT INTO modele_sections (modele_id,titre,ordre) VALUES(?,?,?)")->execute([$new_modele_id,$s['titre'],$s['ordre']]);
                $new_section_id = (int)$pdo->lastInsertId();
                $points = $pdo->query("SELECT * FROM modele_points WHERE section_id={$s['id']} ORDER BY ordre")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($points as $p) {
                    $pdo->prepare("INSERT INTO modele_points (section_id,libelle,type,obligatoire,bloquant,peut_signaler_biomed,valeur_min,valeur_max,unite,ordre) VALUES(?,?,?,?,?,?,?,?,?,?)")
                        ->execute([$new_section_id,$p['libelle'],$p['type'],$p['obligatoire'],$p['bloquant'],$p['peut_signaler_biomed'],$p['valeur_min'],$p['valeur_max'],$p['unite'],$p['ordre']]);
                }
            }
            $msg = "Modèle dupliqué avec ses sections et points.";
            header("Location: fiches.php?modele_id=$new_modele_id&msg=".urlencode($msg)); exit;
        }
    }
}

// ================================================================
//  DONNÉES
// ================================================================
if (isset($_GET['msg'])) $msg = $_GET['msg'];

// Modèle actif (si édition)
$modele_actif = null;
$modele_id    = (int)($_GET['modele_id'] ?? 0);
if ($modele_id) {
    $modele_actif = $pdo->query("SELECT * FROM modeles_fiches WHERE id=$modele_id AND site_id=$site_id")->fetch(PDO::FETCH_ASSOC);
}

// Liste tous les modèles du site
$modeles = $pdo->query("
    SELECT m.*, 
           COUNT(DISTINCT s.id) AS nb_sections,
           COUNT(DISTINCT p.id) AS nb_points,
           COUNT(DISTINCT f.id) AS nb_fiches
    FROM modeles_fiches m
    LEFT JOIN modele_sections s ON s.modele_id = m.id
    LEFT JOIN modele_points p   ON p.section_id = s.id
    LEFT JOIN fiches_remplies f ON f.modele_id = m.id
    WHERE m.site_id = $site_id
    GROUP BY m.id
    ORDER BY m.actif DESC, m.nom
")->fetchAll(PDO::FETCH_ASSOC);

// Sections + points du modèle actif
$sections = [];
if ($modele_actif) {
    $secs = $pdo->query("SELECT * FROM modele_sections WHERE modele_id=$modele_id ORDER BY ordre,id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($secs as $s) {
        $points = $pdo->query("SELECT * FROM modele_points WHERE section_id={$s['id']} ORDER BY ordre,id")->fetchAll(PDO::FETCH_ASSOC);
        $sections[] = array_merge($s, ['points' => $points]);
    }
}

$types_fiches   = ['emplacement'=>'📍 Emplacement','materiel'=>'🔧 Matériel','vehicule'=>'🚑 Véhicule','generique'=>'📋 Générique'];
$freqs          = ['quotidien'=>'Quotidien','hebdomadaire'=>'Hebdomadaire','mensuel'=>'Mensuel','ponctuel'=>'Ponctuel'];
$types_champs   = ['oui_non'=>'✅ Oui / Non','numerique'=>'🔢 Numérique','texte'=>'📝 Texte libre','photo'=>'📷 Photo'];

require_once __DIR__ . '/../includes/header.php';
?>
<style>
.fiches-layout{display:grid;grid-template-columns:280px 1fr;gap:1.25rem;align-items:start}
@media(max-width:900px){.fiches-layout{grid-template-columns:1fr}}
.modele-item{padding:.7rem .875rem;border-radius:8px;border:1px solid var(--border);background:var(--glass);margin-bottom:.4rem;cursor:pointer;transition:all .15s;display:flex;align-items:center;gap:.6rem}
.modele-item:hover{border-color:var(--border2)}
.modele-item.active{border-color:var(--primary);background:rgba(59,130,246,.08)}
.modele-item .modele-nom{font-weight:600;font-size:.875rem;flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.modele-item .modele-meta{font-size:.7rem;color:var(--text3)}
.section-block{background:var(--glass);border:1px solid var(--border);border-radius:10px;margin-bottom:.875rem;overflow:hidden}
.section-header{display:flex;align-items:center;gap:.75rem;padding:.75rem 1rem;background:rgba(255,255,255,.02);border-bottom:1px solid var(--border)}
.section-title{font-weight:700;font-size:.9rem;flex:1}
.point-row{display:flex;align-items:center;gap:.75rem;padding:.55rem 1rem;border-bottom:1px solid rgba(255,255,255,.03);font-size:.82rem}
.point-row:last-child{border-bottom:none}
.point-row:hover{background:rgba(255,255,255,.02)}
.point-libelle{flex:1;min-width:0}
.point-badges{display:flex;gap:.3rem;flex-shrink:0}
.tag{display:inline-block;padding:.1rem .45rem;border-radius:4px;font-size:.68rem;font-weight:600}
.tag-type{background:rgba(59,130,246,.12);color:#60a5fa}
.tag-bloquant{background:rgba(239,68,68,.12);color:#f87171}
.tag-biomed{background:rgba(139,92,246,.12);color:#a78bfa}
.tag-obligatoire{background:rgba(245,158,11,.1);color:#fbbf24}
.add-section-form{background:rgba(59,130,246,.05);border:1px dashed rgba(59,130,246,.3);border-radius:8px;padding:.875rem;margin-bottom:.875rem}
.add-point-row{background:rgba(16,185,129,.03);border:1px dashed rgba(16,185,129,.2);border-radius:6px;padding:.65rem .875rem;margin:.4rem 0}
.form-inline{display:flex;gap:.5rem;align-items:flex-end;flex-wrap:wrap}
.badge-actif{background:rgba(16,185,129,.12);color:#34d399;border:1px solid rgba(16,185,129,.25);padding:.15rem .55rem;border-radius:99px;font-size:.7rem}
.badge-inactif{background:rgba(107,114,128,.1);color:#9ca3af;border:1px solid rgba(107,114,128,.2);padding:.15rem .55rem;border-radius:99px;font-size:.7rem}
</style>

<div class="page-header">
  <div class="page-title-group">
    <div class="page-title">📋 Configuration des fiches</div>
    <div class="page-sub">Modèles de vérification terrain</div>
  </div>
  <button class="btn btn-primary" onclick="document.getElementById('modal-modele').style.display='flex'">➕ Nouveau modèle</button>
</div>

<?php if ($msg): ?><div class="alert alert-success" style="margin-bottom:1rem">✅ <?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger" style="margin-bottom:1rem">❌ <?= h($err) ?></div><?php endif; ?>

<div class="fiches-layout">

  <!-- ═══ COLONNE GAUCHE : liste modèles ═══ -->
  <div>
    <div class="card" style="padding:.75rem">
      <div style="font-size:.7rem;text-transform:uppercase;letter-spacing:1px;color:var(--text3);font-weight:700;padding:.25rem .25rem .75rem">
        <?= count($modeles) ?> modèle(s)
      </div>
      <?php if (empty($modeles)): ?>
      <p style="color:var(--text3);font-size:.85rem;text-align:center;padding:1rem">Aucun modèle créé.</p>
      <?php endif; ?>
      <?php foreach ($modeles as $m): ?>
      <a href="fiches.php?modele_id=<?= $m['id'] ?>" class="modele-item <?= $modele_id===$m['id']?'active':'' ?>" style="text-decoration:none;color:inherit">
        <div style="flex:1;min-width:0">
          <div class="modele-nom"><?= h($m['nom']) ?></div>
          <div class="modele-meta">
            <?= $types_fiches[$m['type']] ?? $m['type'] ?> · <?= $m['nb_sections'] ?> section(s) · <?= $m['nb_points'] ?> point(s)
          </div>
        </div>
        <span class="<?= $m['actif']?'badge-actif':'badge-inactif' ?>"><?= $m['actif']?'Actif':'Inactif' ?></span>
      </a>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- ═══ COLONNE DROITE : éditeur ═══ -->
  <div>
    <?php if (!$modele_actif): ?>
    <div class="card" style="text-align:center;padding:3rem;color:var(--text3)">
      <div style="font-size:3rem;margin-bottom:1rem">📋</div>
      <div style="font-weight:600;margin-bottom:.5rem">Sélectionnez un modèle</div>
      <div style="font-size:.85rem">ou créez-en un nouveau</div>
    </div>

    <?php else: ?>
    <!-- En-tête modèle actif -->
    <div class="card" style="margin-bottom:1rem">
      <div style="display:flex;align-items:flex-start;gap:1rem;flex-wrap:wrap">
        <div style="flex:1;min-width:0">
          <div style="font-size:1.1rem;font-weight:700;margin-bottom:.3rem"><?= h($modele_actif['nom']) ?></div>
          <div style="font-size:.8rem;color:var(--text3);display:flex;gap:.75rem;flex-wrap:wrap">
            <span><?= $types_fiches[$modele_actif['type']] ?? $modele_actif['type'] ?></span>
            <span>🔄 <?= $freqs[$modele_actif['frequence']] ?? $modele_actif['frequence'] ?></span>
            <span class="<?= $modele_actif['actif']?'badge-actif':'badge-inactif' ?>"><?= $modele_actif['actif']?'Actif':'Inactif' ?></span>
          </div>
          <?php if ($modele_actif['description']): ?>
          <div style="font-size:.8rem;color:var(--text2);margin-top:.4rem"><?= h($modele_actif['description']) ?></div>
          <?php endif; ?>
        </div>
        <div style="display:flex;gap:.4rem;flex-shrink:0">
          <button class="btn btn-ghost btn-sm" onclick="ouvrirEditModele(<?= htmlspecialchars(json_encode($modele_actif), ENT_QUOTES) ?>)">✏️ Modifier</button>
          <form method="POST" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="dupliquer_modele">
            <input type="hidden" name="modele_id" value="<?= $modele_actif['id'] ?>">
            <button class="btn btn-ghost btn-sm" title="Dupliquer ce modèle">📋 Dupliquer</button>
          </form>
          <form method="POST" style="display:inline" onsubmit="return confirm('Supprimer ce modèle et toutes ses sections/points ?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_modele">
            <input type="hidden" name="modele_id" value="<?= $modele_actif['id'] ?>">
            <button class="btn btn-ghost btn-sm" style="color:#f87171">🗑️</button>
          </form>
        </div>
      </div>
    </div>

    <!-- Sections + points -->
    <?php foreach ($sections as $s): ?>
    <div class="section-block">
      <div class="section-header">
        <span style="color:var(--text3);font-size:.75rem">§</span>
        <div class="section-title"><?= h($s['titre']) ?></div>
        <span style="font-size:.72rem;color:var(--text3)"><?= count($s['points']) ?> point(s)</span>
        <button class="btn btn-ghost btn-xs" onclick="ouvrirEditSection(<?= $s['id'] ?>, '<?= addslashes(h($s['titre'])) ?>', <?= $s['ordre'] ?>, <?= $modele_actif['id'] ?>)">✏️</button>
        <form method="POST" style="display:inline" onsubmit="return confirm('Supprimer cette section et tous ses points ?')">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete_section">
          <input type="hidden" name="section_id" value="<?= $s['id'] ?>">
          <input type="hidden" name="modele_id" value="<?= $modele_actif['id'] ?>">
          <button class="btn btn-ghost btn-xs" style="color:#f87171">🗑️</button>
        </form>
      </div>

      <!-- Points -->
      <?php foreach ($s['points'] as $p): ?>
      <div class="point-row">
        <div class="point-libelle">
          <?= h($p['libelle']) ?>
          <?php if ($p['unite']): ?><span style="color:var(--text3);font-size:.75rem"> (<?= h($p['unite']) ?>)</span><?php endif; ?>
          <?php if ($p['valeur_min'] !== null || $p['valeur_max'] !== null): ?>
          <span style="color:var(--text3);font-size:.72rem"> [<?= $p['valeur_min']??'?' ?>–<?= $p['valeur_max']??'?' ?>]</span>
          <?php endif; ?>
        </div>
        <div class="point-badges">
          <span class="tag tag-type"><?= $types_champs[$p['type']] ?? $p['type'] ?></span>
          <?php if ($p['bloquant']): ?><span class="tag tag-bloquant">Bloquant</span><?php endif; ?>
          <?php if ($p['peut_signaler_biomed']): ?><span class="tag tag-biomed">🔬 Bio</span><?php endif; ?>
          <?php if (!$p['obligatoire']): ?><span style="color:var(--text3);font-size:.68rem">Optionnel</span><?php endif; ?>
        </div>
        <button class="btn btn-ghost btn-xs" onclick="ouvrirEditPoint(<?= htmlspecialchars(json_encode($p), ENT_QUOTES) ?>, <?= $s['id'] ?>, <?= $modele_actif['id'] ?>)">✏️</button>
        <form method="POST" style="display:inline" onsubmit="return confirm('Supprimer ce point ?')">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete_point">
          <input type="hidden" name="point_id" value="<?= $p['id'] ?>">
          <input type="hidden" name="modele_id" value="<?= $modele_actif['id'] ?>">
          <button class="btn btn-ghost btn-xs" style="color:#f87171">🗑️</button>
        </form>
      </div>
      <?php endforeach; ?>

      <!-- Ajouter point inline -->
      <div style="padding:.5rem .875rem">
        <button class="btn btn-ghost btn-xs" style="color:#34d399;border-color:rgba(16,185,129,.25)" onclick="ouvrirAddPoint(<?= $s['id'] ?>, <?= $modele_actif['id'] ?>)">
          ➕ Ajouter un point
        </button>
      </div>
    </div>
    <?php endforeach; ?>

    <!-- Ajouter section -->
    <div class="add-section-form">
      <div style="font-size:.78rem;font-weight:600;color:var(--text2);margin-bottom:.6rem">➕ Nouvelle section</div>
      <form method="POST" class="form-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_section">
        <input type="hidden" name="modele_id" value="<?= $modele_actif['id'] ?>">
        <input type="text" name="titre" class="form-control" placeholder="Titre de la section..." required style="flex:1;min-width:200px">
        <button type="submit" class="btn btn-primary btn-sm">Ajouter</button>
      </form>
    </div>

    <?php endif; ?>
  </div>
</div>

<!-- ================================================================
     MODALES
================================================================ -->

<!-- Modal : Créer/modifier modèle -->
<div id="modal-modele" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:1000;align-items:center;justify-content:center;padding:1rem">
  <div style="background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:1.5rem;width:100%;max-width:480px">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem">
      <div style="font-weight:700;font-size:1rem" id="modal-modele-title">➕ Nouveau modèle</div>
      <button onclick="document.getElementById('modal-modele').style.display='none'" style="background:none;border:none;color:var(--text3);font-size:1.2rem;cursor:pointer">✕</button>
    </div>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_modele">
      <input type="hidden" name="modele_id" id="modal-modele-id" value="0">
      <div class="form-group">
        <label class="form-label">Nom *</label>
        <input type="text" name="nom" id="modal-nom" class="form-control" required placeholder="Ex: Vérification VSAV quotidienne">
      </div>
      <div class="form-group">
        <label class="form-label">Description</label>
        <input type="text" name="description" id="modal-description" class="form-control" placeholder="Description courte...">
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
        <div class="form-group">
          <label class="form-label">Type</label>
          <select name="type" id="modal-type" class="form-control">
            <?php foreach ($types_fiches as $k=>$v): ?>
            <option value="<?= $k ?>"><?= $v ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Fréquence</label>
          <select name="frequence" id="modal-frequence" class="form-control">
            <?php foreach ($freqs as $k=>$v): ?>
            <option value="<?= $k ?>"><?= $v ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-group" style="display:flex;align-items:center;gap:.5rem">
        <input type="checkbox" name="actif" id="modal-actif" value="1" style="accent-color:var(--primary)">
        <label for="modal-actif" style="font-size:.875rem;cursor:pointer">Modèle actif (visible sur le terrain)</label>
      </div>
      <div style="display:flex;gap:.75rem;margin-top:1rem">
        <button type="submit" class="btn btn-primary">💾 Enregistrer</button>
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('modal-modele').style.display='none'">Annuler</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal : Créer/modifier section -->
<div id="modal-section" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:1000;align-items:center;justify-content:center;padding:1rem">
  <div style="background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:1.5rem;width:100%;max-width:400px">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem">
      <div style="font-weight:700">✏️ Modifier la section</div>
      <button onclick="document.getElementById('modal-section').style.display='none'" style="background:none;border:none;color:var(--text3);font-size:1.2rem;cursor:pointer">✕</button>
    </div>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_section">
      <input type="hidden" name="section_id" id="sec-id" value="">
      <input type="hidden" name="modele_id" id="sec-modele-id" value="">
      <input type="hidden" name="ordre" id="sec-ordre" value="0">
      <div class="form-group">
        <label class="form-label">Titre *</label>
        <input type="text" name="titre" id="sec-titre" class="form-control" required>
      </div>
      <div style="display:flex;gap:.75rem;margin-top:1rem">
        <button type="submit" class="btn btn-primary">💾 Enregistrer</button>
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('modal-section').style.display='none'">Annuler</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal : Créer/modifier point -->
<div id="modal-point" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:1000;align-items:center;justify-content:center;padding:1rem">
  <div style="background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:1.5rem;width:100%;max-width:520px;max-height:90vh;overflow-y:auto">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem">
      <div style="font-weight:700" id="modal-point-title">➕ Nouveau point</div>
      <button onclick="document.getElementById('modal-point').style.display='none'" style="background:none;border:none;color:var(--text3);font-size:1.2rem;cursor:pointer">✕</button>
    </div>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_point">
      <input type="hidden" name="point_id" id="pt-id" value="0">
      <input type="hidden" name="section_id" id="pt-section-id" value="">
      <input type="hidden" name="modele_id" id="pt-modele-id" value="">
      <div class="form-group">
        <label class="form-label">Libellé *</label>
        <input type="text" name="libelle" id="pt-libelle" class="form-control" required placeholder="Ex: Défibrillateur opérationnel ?">
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
        <div class="form-group">
          <label class="form-label">Type de champ</label>
          <select name="type_champ" id="pt-type" class="form-control" onchange="toggleNumFields(this.value)">
            <?php foreach ($types_champs as $k=>$v): ?>
            <option value="<?= $k ?>"><?= $v ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Unité (optionnel)</label>
          <input type="text" name="unite" id="pt-unite" class="form-control" placeholder="Ex: mmHg, °C...">
        </div>
      </div>
      <div id="num-fields" style="display:none;grid-template-columns:1fr 1fr;gap:.75rem">
        <div class="form-group">
          <label class="form-label">Valeur min</label>
          <input type="number" name="valeur_min" id="pt-vmin" class="form-control" step="any">
        </div>
        <div class="form-group">
          <label class="form-label">Valeur max</label>
          <input type="number" name="valeur_max" id="pt-vmax" class="form-control" step="any">
        </div>
      </div>
      <div style="display:flex;flex-wrap:wrap;gap:1rem;margin-top:.5rem">
        <label style="display:flex;align-items:center;gap:.4rem;font-size:.875rem;cursor:pointer">
          <input type="checkbox" name="obligatoire" id="pt-obligatoire" value="1" style="accent-color:var(--primary)" checked>
          Obligatoire
        </label>
        <label style="display:flex;align-items:center;gap:.4rem;font-size:.875rem;cursor:pointer">
          <input type="checkbox" name="bloquant" id="pt-bloquant" value="1" style="accent-color:#ef4444">
          <span style="color:#f87171">Bloquant</span>
        </label>
        <label style="display:flex;align-items:center;gap:.4rem;font-size:.875rem;cursor:pointer">
          <input type="checkbox" name="peut_signaler_biomed" id="pt-biomed" value="1" style="accent-color:#8b5cf6">
          <span style="color:#a78bfa">🔬 Signalement biomédical</span>
        </label>
      </div>
      <div style="display:flex;gap:.75rem;margin-top:1.25rem">
        <button type="submit" class="btn btn-primary">💾 Enregistrer</button>
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('modal-point').style.display='none'">Annuler</button>
      </div>
    </form>
  </div>
</div>

<script>
function ouvrirEditModele(m) {
    document.getElementById('modal-modele-title').textContent = '✏️ Modifier le modèle';
    document.getElementById('modal-modele-id').value    = m.id;
    document.getElementById('modal-nom').value          = m.nom;
    document.getElementById('modal-description').value  = m.description || '';
    document.getElementById('modal-type').value         = m.type;
    document.getElementById('modal-frequence').value    = m.frequence;
    document.getElementById('modal-actif').checked      = m.actif == 1;
    document.getElementById('modal-modele').style.display = 'flex';
}

function ouvrirEditSection(id, titre, ordre, modeleId) {
    document.getElementById('sec-id').value       = id;
    document.getElementById('sec-titre').value    = titre;
    document.getElementById('sec-ordre').value    = ordre;
    document.getElementById('sec-modele-id').value= modeleId;
    document.getElementById('modal-section').style.display = 'flex';
}

function ouvrirAddPoint(sectionId, modeleId) {
    document.getElementById('modal-point-title').textContent = '➕ Nouveau point';
    document.getElementById('pt-id').value         = 0;
    document.getElementById('pt-section-id').value = sectionId;
    document.getElementById('pt-modele-id').value  = modeleId;
    document.getElementById('pt-libelle').value    = '';
    document.getElementById('pt-type').value       = 'oui_non';
    document.getElementById('pt-unite').value      = '';
    document.getElementById('pt-vmin').value       = '';
    document.getElementById('pt-vmax').value       = '';
    document.getElementById('pt-obligatoire').checked = true;
    document.getElementById('pt-bloquant').checked    = false;
    document.getElementById('pt-biomed').checked      = false;
    toggleNumFields('oui_non');
    document.getElementById('modal-point').style.display = 'flex';
}

function ouvrirEditPoint(p, sectionId, modeleId) {
    document.getElementById('modal-point-title').textContent = '✏️ Modifier le point';
    document.getElementById('pt-id').value         = p.id;
    document.getElementById('pt-section-id').value = sectionId;
    document.getElementById('pt-modele-id').value  = modeleId;
    document.getElementById('pt-libelle').value    = p.libelle;
    document.getElementById('pt-type').value       = p.type;
    document.getElementById('pt-unite').value      = p.unite || '';
    document.getElementById('pt-vmin').value       = p.valeur_min || '';
    document.getElementById('pt-vmax').value       = p.valeur_max || '';
    document.getElementById('pt-obligatoire').checked = p.obligatoire == 1;
    document.getElementById('pt-bloquant').checked    = p.bloquant == 1;
    document.getElementById('pt-biomed').checked      = p.peut_signaler_biomed == 1;
    toggleNumFields(p.type);
    document.getElementById('modal-point').style.display = 'flex';
}

function toggleNumFields(type) {
    const el = document.getElementById('num-fields');
    el.style.display = type === 'numerique' ? 'grid' : 'none';
}

// Fermer modal en cliquant dehors
['modal-modele','modal-section','modal-point'].forEach(id => {
    document.getElementById(id).addEventListener('click', function(e) {
        if (e.target === this) this.style.display = 'none';
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

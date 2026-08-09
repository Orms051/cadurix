<?php
/**
 * Cadurix v5 — Import de médicaments depuis Excel (.xlsx)
 * Librairie SimpleXLSX embarquée (pas de Composer requis)
 * Accès : Gestionnaire minimum
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/libs/SimpleXLSX.php';

$site_id = site_actif();
if (!$site_id) { header('Location: /dashboard.php'); exit; }
$message = '';
$erreur  = '';
$preview = [];
$erreurs_import = [];
$import_ok = 0;

// ----------------------------------------------------------------
//  Emplacements disponibles pour l'user
// ----------------------------------------------------------------
$empl_autorises = emplacements_autorises();
if ($empl_autorises === null) {
    // Tous les emplacements du site
    $stmt = $pdo->prepare("SELECT e.id, e.nom, p.nom AS parent_nom FROM emplacements e LEFT JOIN emplacements p ON p.id = e.parent_id WHERE e.site_id = ? AND e.actif = 1 ORDER BY e.nom");
    $stmt->execute([$site_id]);
} else {
    if (empty($empl_autorises)) {
        $emplacements = [];
    } else {
        $ph = implode(',', array_fill(0, count($empl_autorises), '?'));
        $stmt = $pdo->prepare("SELECT e.id, e.nom, p.nom AS parent_nom FROM emplacements e LEFT JOIN emplacements p ON p.id = e.parent_id WHERE e.id IN ($ph) AND e.actif = 1 ORDER BY e.nom");
        $stmt->execute($empl_autorises);
    }
}
$emplacements = isset($stmt) ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
$empl_index   = array_column($emplacements, null, 'nom'); // nom → données

// ----------------------------------------------------------------
//  Traitement upload
// ----------------------------------------------------------------
$etape = 'upload'; // upload | preview | done

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // ÉTAPE 1 : Upload et preview
    if (isset($_FILES['fichier_xlsx']) && empty($_POST['confirmer'])) {
        $fichier = $_FILES['fichier_xlsx'];
        $ext = strtolower(pathinfo($fichier['name'], PATHINFO_EXTENSION));

        if ($fichier['error'] !== UPLOAD_ERR_OK) {
            $erreur = 'Erreur lors du téléversement.';
        } elseif ($ext !== 'xlsx') {
            $erreur = 'Seuls les fichiers .xlsx sont acceptés.';
        } elseif ($fichier['size'] > 5 * 1024 * 1024) {
            $erreur = 'Fichier trop volumineux (max 5 Mo).';
        } else {
            $xlsx = SimpleXLSX::parse($fichier['tmp_name']);
            if (!$xlsx) {
                $erreur = 'Impossible de lire le fichier Excel : ' . SimpleXLSX::parseError();
            } else {
                $rows = $xlsx->rows(0); // Première feuille
                $entetes = array_map('strtolower', array_map('trim', $rows[0] ?? []));

                // Colonnes attendues
                $cols_attendues = ['nom','emplacement','reference','numero_lot','quantite','unite','date_peremption','alerte_jours','quantite_min','chaine_froid','notes'];
                $cols_map = [];
                foreach ($cols_attendues as $col) {
                    $idx = array_search($col, $entetes);
                    $cols_map[$col] = ($idx !== false) ? $idx : null;
                }

                if ($cols_map['nom'] === null || $cols_map['emplacement'] === null || $cols_map['date_peremption'] === null) {
                    $erreur = 'Colonnes obligatoires manquantes : "nom", "emplacement", "date_peremption"';
                } else {
                    $lignes = array_slice($rows, 1);
                    foreach ($lignes as $num => $row) {
                        if (empty(array_filter($row))) continue; // Ligne vide

                        $get = function($col) use ($row, $cols_map) {
                            $idx = $cols_map[$col];
                            return ($idx !== null && isset($row[$idx])) ? trim($row[$idx]) : '';
                        };

                        $ligne = [
                            'ligne_num'      => $num + 2,
                            'nom'            => $get('nom'),
                            'emplacement'    => $get('emplacement'),
                            'reference'      => $get('reference'),
                            'numero_lot'     => $get('numero_lot'),
                            'quantite'       => (float)str_replace(',', '.', $get('quantite') ?: '0'),
                            'quantite_min'   => (float)str_replace(',', '.', $get('quantite_min') ?: '0'),
                            'unite'          => $get('unite') ?: 'unité',
                            'date_peremption'=> $get('date_peremption'),
                            'alerte_jours'   => (int)($get('alerte_jours') ?: 30),
                            'chaine_froid'   => in_array(strtolower($get('chaine_froid')), ['1','oui','yes','true']) ? 1 : 0,
                            'notes'          => $get('notes'),
                            'erreurs'        => [],
                            'warnings'       => [],
                        ];

                        // Validations
                        if (empty($ligne['nom'])) $ligne['erreurs'][] = 'Nom manquant';
                        if (empty($ligne['emplacement'])) $ligne['erreurs'][] = 'Emplacement manquant';
                        elseif (!isset($empl_index[$ligne['emplacement']])) {
                            $ligne['erreurs'][] = 'Emplacement inconnu ou non autorisé : "' . $ligne['emplacement'] . '"';
                        }

                        // Convertir date (gère DD/MM/YYYY et YYYY-MM-DD et Excel serial)
                        if (!empty($ligne['date_peremption'])) {
                            $d = null;
                            if (is_numeric($ligne['date_peremption'])) {
                                // Numéro de série Excel
                                $ts = ($ligne['date_peremption'] - 25569) * 86400;
                                $d = date('Y-m-d', $ts);
                            } elseif (preg_match('#(\d{2})/(\d{2})/(\d{4})#', $ligne['date_peremption'], $m)) {
                                $d = $m[3] . '-' . $m[2] . '-' . $m[1];
                            } elseif (preg_match('#\d{4}-\d{2}-\d{2}#', $ligne['date_peremption'])) {
                                $d = $ligne['date_peremption'];
                            }
                            if (!$d || !strtotime($d)) {
                                $ligne['erreurs'][] = 'Date de péremption invalide : "' . $ligne['date_peremption'] . '" (format attendu : JJ/MM/AAAA)';
                            } else {
                                $ligne['date_sql'] = $d;
                                if (strtotime($d) < time()) $ligne['warnings'][] = 'Produit déjà périmé';
                            }
                        } else {
                            $ligne['erreurs'][] = 'Date de péremption manquante';
                        }

                        if ($ligne['alerte_jours'] < 1 || $ligne['alerte_jours'] > 365) {
                            $ligne['alerte_jours'] = 30;
                            $ligne['warnings'][] = 'Alerte jours corrigée à 30';
                        }

                        // Résoudre l'emplacement
                        if (isset($empl_index[$ligne['emplacement']])) {
                            $ligne['emplacement_id'] = $empl_index[$ligne['emplacement']]['id'];
                        }

                        $preview[] = $ligne;
                    }

                    if (empty($preview)) {
                        $erreur = 'Aucune ligne de données trouvée dans le fichier.';
                    } else {
                        // Stocker en session pour confirmation
                        $_SESSION['import_preview'] = $preview;
                        $etape = 'preview';
                    }
                }
            }
        }
    }

    // ÉTAPE 2 : Confirmation et import
    if (isset($_POST['confirmer']) && !empty($_SESSION['import_preview'])) {
        $preview = $_SESSION['import_preview'];
        $stmt = $pdo->prepare("
            INSERT INTO produits (emplacement_id, nom, reference, numero_lot, quantite, quantite_min, unite, date_peremption, alerte_jours, chaine_froid, notes, cree_par)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt_mouv = $pdo->prepare("
            INSERT INTO mouvements_stock (produit_id, user_id, type, quantite_avant, quantite_delta, quantite_apres, motif)
            VALUES (?,?,?,0,?,?,'Import Excel')
        ");

        $pdo->beginTransaction();
        try {
            foreach ($preview as $ligne) {
                if (!empty($ligne['erreurs'])) { $erreurs_import[] = $ligne; continue; }

                $stmt->execute([
                    $ligne['emplacement_id'],
                    $ligne['nom'],
                    $ligne['reference'] ?: null,
                    $ligne['numero_lot'] ?: null,
                    $ligne['quantite'],
                    $ligne['quantite_min'],
                    $ligne['unite'],
                    $ligne['date_sql'],
                    $ligne['alerte_jours'],
                    $ligne['chaine_froid'],
                    $ligne['notes'] ?: null,
                    $_SESSION['user_id'],
                ]);
                $new_id = $pdo->lastInsertId();
                $stmt_mouv->execute([$new_id, $_SESSION['user_id'], 'import', $ligne['quantite'], $ligne['quantite']]);
                $import_ok++;
            }
            $pdo->commit();
            unset($_SESSION['import_preview']);
            audit_log($pdo, 'IMPORT_EXCEL', 'produits', null, null, ['nb_produits' => $import_ok, 'site_id' => $site_id]);
            $etape = 'done';
        } catch (Exception $e) {
            $pdo->rollBack();
            $erreur = 'Erreur lors de l\'import : ' . $e->getMessage();
            $etape = 'preview';
            $preview = $_SESSION['import_preview'] ?? [];
        }
    }
} elseif (!empty($_SESSION['import_preview'])) {
    $preview = $_SESSION['import_preview'];
    $etape = 'preview';
}

// Compter erreurs dans preview
$nb_ok_preview    = count(array_filter($preview, fn($l) => empty($l['erreurs'])));
$nb_err_preview   = count(array_filter($preview, fn($l) => !empty($l['erreurs'])));
$nb_warn_preview  = count(array_filter($preview, fn($l) => !empty($l['warnings'])));

$pageTitle = 'Import Excel';
require_once __DIR__ . '/includes/header.php';
?>

<style>
/* ── Import — thème dynamique ── */
.imp-drop-zone {
  border: 2px dashed var(--border2);
  border-radius: var(--radius);
  padding: 3rem;
  text-align: center;
  cursor: pointer;
  transition: all .2s;
  position: relative;
  background: var(--glass);
}
.imp-drop-zone:hover, .imp-drop-zone.dragover {
  border-color: var(--primary);
  background: var(--primary-bg);
}
.imp-drop-zone input[type=file] { position:absolute;inset:0;opacity:0;cursor:pointer; }
.imp-drop-icon  { font-size: 3rem; margin-bottom: .75rem; }
.imp-drop-title { font-size: 1.1rem; font-weight: 600; margin-bottom: .4rem; color: var(--text); }
.imp-drop-sub   { font-size: .85rem; color: var(--text2); }

.imp-stats { display:grid; grid-template-columns:repeat(3,1fr); gap:1rem; margin-bottom:1.5rem; }
.imp-stat  { background:var(--bg2); border:1px solid var(--border); border-radius:10px; padding:1rem; text-align:center; }
.imp-stat-n { font-size:2rem; font-weight:700; }
.imp-stat-l { font-size:.8rem; color:var(--text2); margin-top:.2rem; }

.imp-table { width:100%; border-collapse:collapse; font-size:.8rem; }
.imp-table th { padding:.5rem .75rem; text-align:left; color:var(--text3); font-weight:600; font-size:.72rem; text-transform:uppercase; border-bottom:1px solid var(--border); }
.imp-table td { padding:.6rem .75rem; border-bottom:1px solid var(--border); vertical-align:middle; }
.imp-table tr.row-ok:hover td  { background:var(--glass); }
.imp-table tr.row-err td  { background:rgba(239,68,68,.05); }
.imp-table tr.row-warn td { background:rgba(245,158,11,.04); }

.imp-badge    { display:inline-block; padding:.15rem .5rem; border-radius:99px; font-size:.7rem; font-weight:500; }
.imp-badge-ok { background:rgba(16,185,129,.15); color:#6ee7b7; }
.imp-badge-err{ background:rgba(239,68,68,.15);  color:#fca5a5; }
.imp-badge-warn{background:rgba(245,158,11,.15); color:#fcd34d; }

.col-tag { background:var(--bg2); border:1px solid var(--border); border-radius:.3rem; padding:.2rem .6rem; font-size:.75rem; font-family:var(--mono); }
.col-tag.required { border-color:var(--primary); color:var(--primary); }
.template-cols { display:flex; flex-wrap:wrap; gap:.4rem; margin-top:.75rem; }

.alert-info2 { background:var(--primary-bg); border:1px solid rgba(var(--primary-rgb),.3); color:var(--primary); padding:.875rem 1.25rem; border-radius:8px; margin-bottom:1.25rem; font-size:.9rem; }
.btn-success { background:#10B981; color:#fff; display:inline-flex; align-items:center; gap:.4rem; padding:.5rem 1.1rem; border-radius:8px; font-size:.875rem; font-weight:500; cursor:pointer; border:none; }
.btn-success:hover { background:#0d9e6e; }
</style>

<div class="page-header">
  <div class="page-title-group">
    <div class="page-title">📥 Import Excel</div>
    <div class="page-sub">Médicaments → Import .xlsx</div>
  </div>
  <a href="products.php" class="btn btn-ghost">← Retour médicaments</a>
</div>

<?php if ($erreur): ?><div class="flash flash-error">✗ <?= htmlspecialchars($erreur) ?></div><?php endif; ?>

<!-- ÉTAPE UPLOAD -->
<?php if ($etape === 'upload'): ?>
<div class="card">
  <div class="card-header"><div class="card-title">📄 Fichier Excel</div></div>
  <form method="POST" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="imp-drop-zone" id="drop-zone" class="imp-drop-zone">
      <input type="file" name="fichier_xlsx" accept=".xlsx" required id="file-input">
      <div class="imp-drop-icon">📊</div>
      <div class="imp-drop-title">Glissez votre fichier Excel ici</div>
      <div class="imp-drop-sub">ou cliquez pour sélectionner — Format .xlsx uniquement, max 5 Mo</div>
      <div id="file-name" style="margin-top:1rem;font-weight:600;color:var(--primary)"></div>
    </div>
    <div style="margin-top:1.25rem">
      <button type="submit" class="btn btn-primary">📥 Analyser le fichier</button>
    </div>
  </form>
</div>

<div class="card">
  <div class="card-header"><div class="card-title">📋 Format attendu du fichier</div></div>
  <p style="font-size:.875rem;color:var(--muted);margin-bottom:1rem">La première ligne doit être l'en-tête. Colonnes <span style="color:var(--primary)">obligatoires</span> en bleu :</p>
  <div class="template-cols">
    <span class="col-tag required">nom</span>
    <span class="col-tag required">emplacement</span>
    <span class="col-tag required">date_peremption</span>
    <span class="col-tag">reference</span>
    <span class="col-tag">numero_lot</span>
    <span class="col-tag">quantite</span>
    <span class="col-tag">quantite_min</span>
    <span class="col-tag">unite</span>
    <span class="col-tag">alerte_jours</span>
    <span class="col-tag">chaine_froid</span>
    <span class="col-tag">notes</span>
  </div>
  <p style="font-size:.8rem;color:var(--muted);margin-top:1rem">
    📅 Date au format <strong>JJ/MM/AAAA</strong> ou <strong>AAAA-MM-JJ</strong><br>
    🏷 Emplacement : nom exact de l'emplacement tel qu'il existe dans Cadurix<br>
    ❄️ Chaîne du froid : <strong>oui</strong> / <strong>non</strong> (ou 1/0)
  </p>

  <!-- Emplacements disponibles -->
  <div style="margin-top:1rem">
    <p style="font-size:.8rem;color:var(--muted);margin-bottom:.5rem">Emplacements disponibles sur ce site :</p>
    <div style="display:flex;flex-wrap:wrap;gap:.4rem">
      <?php foreach ($emplacements as $e): ?>
      <span style="background:var(--surface2);border:1px solid var(--border);border-radius:.3rem;padding:.2rem .6rem;font-size:.75rem">
        <?= htmlspecialchars($e['nom']) ?>
      </span>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- ÉTAPE PREVIEW -->
<?php elseif ($etape === 'preview'): ?>
<div class="imp-stats">
  <div class="imp-stat">
    <div class="imp-stat-n" style="color:var(--success)"><?= $nb_ok_preview ?></div>
    <div class="imp-stat-l">✓ Prêts à importer</div>
  </div>
  <div class="imp-stat">
    <div class="imp-stat-n" style="color:var(--warning)"><?= $nb_warn_preview ?></div>
    <div class="imp-stat-l">⚠ Avertissements</div>
  </div>
  <div class="imp-stat">
    <div class="imp-stat-n" style="color:var(--danger)"><?= $nb_err_preview ?></div>
    <div class="imp-stat-l">✗ Erreurs (ignorées)</div>
  </div>
</div>

<?php if ($nb_ok_preview > 0): ?>
<div class="alert-info2">
  ℹ <?= $nb_ok_preview ?> ligne(s) seront importées.
  <?= $nb_err_preview > 0 ? $nb_err_preview . ' ligne(s) avec erreurs seront <strong>ignorées</strong>.' : '' ?>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header">
    <div class="card-title">👁 Aperçu (<?= count($preview) ?> lignes)</div>
  </div>
  <div style="overflow-x:auto">
  <table class="imp-table">
    <tr>
      <th>#</th><th>Statut</th><th>Nom</th><th>Emplacement</th>
      <th>Lot</th><th>Qté</th><th>Unité</th><th>Péremption</th><th>❄️</th>
    </tr>
    <?php foreach ($preview as $l): ?>
    <tr class="<?= !empty($l['erreurs']) ? 'row-err' : (!empty($l['warnings']) ? 'row-warn' : 'row-ok') ?>">
      <td style="color:var(--muted)"><?= $l['ligne_num'] ?></td>
      <td>
        <?php if (!empty($l['erreurs'])): ?>
        <span class="imp-badge imp-badge-err">✗ Erreur</span>
        <?php elseif (!empty($l['warnings'])): ?>
        <span class="imp-badge imp-badge-warn">⚠ Warning</span>
        <?php else: ?>
        <span class="imp-badge imp-badge-ok">✓ OK</span>
        <?php endif; ?>
      </td>
      <td><strong><?= htmlspecialchars($l['nom']) ?></strong></td>
      <td><?= htmlspecialchars($l['emplacement']) ?></td>
      <td style="color:var(--muted)"><?= htmlspecialchars($l['numero_lot']) ?></td>
      <td><?= $l['quantite'] ?></td>
      <td style="color:var(--muted)"><?= htmlspecialchars($l['unite']) ?></td>
      <td><?= isset($l['date_sql']) ? date('d/m/Y', strtotime($l['date_sql'])) : htmlspecialchars($l['date_peremption']) ?></td>
      <td><?= $l['chaine_froid'] ? '❄️' : '' ?></td>
    </tr>
    <?php if (!empty($l['erreurs']) || !empty($l['warnings'])): ?>
    <tr class="<?= !empty($l['erreurs']) ? 'row-err' : 'row-warn' ?>">
      <td></td>
      <td colspan="8" style="padding-top:0;padding-bottom:.6rem">
        <?php foreach ($l['erreurs']   as $e): ?><span class="imp-badge imp-badge-err" style="margin:.1rem">✗ <?= htmlspecialchars($e) ?></span><?php endforeach; ?>
        <?php foreach ($l['warnings']  as $w): ?><span class="imp-badge imp-badge-warn" style="margin:.1rem">⚠ <?= htmlspecialchars($w) ?></span><?php endforeach; ?>
      </td>
    </tr>
    <?php endif; ?>
    <?php endforeach; ?>
  </table>
  </div>

  <div style="margin-top:1.5rem;display:flex;gap:.75rem">
    <?php if ($nb_ok_preview > 0): ?>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="confirmer" value="1">
      <button type="submit" class="btn btn-success">✅ Confirmer l'import de <?= $nb_ok_preview ?> produit(s)</button>
    </form>
    <?php endif; ?>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="annuler" value="1">
      <button type="submit" class="btn btn-ghost" onclick="<?php unset($_SESSION['import_preview']); ?>">✕ Annuler</button>
    </form>
  </div>
</div>

<!-- ÉTAPE DONE -->
<?php elseif ($etape === 'done'): ?>
<div class="card" style="text-align:center;padding:3rem">
  <div style="font-size:4rem;margin-bottom:1rem">✅</div>
  <div style="font-size:1.5rem;font-weight:700;margin-bottom:.5rem"><?= $import_ok ?> produit(s) importé(s)</div>
  <div style="color:var(--muted);margin-bottom:2rem">L'import a été effectué et l'audit log a été mis à jour.</div>
  <div style="display:flex;gap:1rem;justify-content:center">
    <a href="products.php" class="btn btn-primary">📦 Voir les médicaments</a>
    <a href="import.php" class="btn btn-ghost">📥 Nouvel import</a>
  </div>
</div>
<?php endif; ?>



<script>
// Drag & Drop
const dz = document.getElementById('drop-zone');
const fi = document.getElementById('file-input');
if (dz && fi) {
  fi.addEventListener('change', () => {
    document.getElementById('file-name').textContent = fi.files[0]?.name || '';
  });
  dz.addEventListener('dragover', e => { e.preventDefault(); dz.classList.add('dragover'); });
  dz.addEventListener('dragleave', () => dz.classList.remove('dragover'));
  dz.addEventListener('drop', e => {
    e.preventDefault(); dz.classList.remove('dragover');
    fi.files = e.dataTransfer.files;
    document.getElementById('file-name').textContent = fi.files[0]?.name || '';
  });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

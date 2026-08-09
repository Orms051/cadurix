<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.

require_once 'includes/auth.php';
$site_id = site_actif();
if (!$site_id) { header('Location: dashboard.php'); exit; }
$pageTitle = 'Médicaments';

// ── AJAX autocomplétion ───────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'autocomplete') {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) { echo json_encode([]); exit; }
    $sid  = site_actif();
    $like = '%' . $q . '%';
    $results = [];

    // 1. Chercher d'abord dans les stocks existants du site
    try {
        $st = $pdo->prepare("
            SELECT DISTINCT p.nom, p.reference, p.unite, p.alerte_jours,
                   p.chaine_froid, p.temp_stockage_min, p.temp_stockage_max,
                   p.quantite_min, COUNT(*) as nb_stocks, 'stock' as source
            FROM produits p
            JOIN emplacements e ON e.id=p.emplacement_id
            WHERE e.site_id=? AND p.statut='actif' AND p.nom LIKE ?
            GROUP BY p.nom,p.reference,p.unite,p.alerte_jours,p.chaine_froid,
                     p.temp_stockage_min,p.temp_stockage_max,p.quantite_min
            ORDER BY p.nom LIMIT 5
        ");
        $st->execute([$sid, $like]);
        $results = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    // 2. Compléter avec la base de référence SDIS
    $noms_existants = array_column($results, 'nom');
    try {
        $ref_q = $pdo->prepare("
            SELECT nom, dci as reference, unite, alerte_jours,
                   froid as chaine_froid, temp_min as temp_stockage_min,
                   temp_max as temp_stockage_max, 0 as quantite_min,
                   0 as nb_stocks, 'ref' as source,
                   forme, dosage, categorie
            FROM medicaments_ref
            WHERE actif=1 AND (nom LIKE ? OR dci LIKE ?)
            ORDER BY nom LIMIT 8
        ");
        $ref_q->execute([$like, $like]);
        foreach ($ref_q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!in_array($r['nom'], $noms_existants)) {
                $results[] = $r;
            }
        }
    } catch (Exception $e) {}

    echo json_encode(array_slice($results, 0, 10));
    exit;
}

// ── AJAX données produit pour duplication ────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_product') {
    header('Content-Type: application/json');
    $id = (int)($_GET['id'] ?? 0);
    $st = $pdo->prepare("SELECT p.* FROM produits p JOIN emplacements e ON e.id=p.emplacement_id WHERE p.id=? AND e.site_id=?");
    $st->execute([$id, $site_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        echo json_encode([
            'nom'               => $row['nom'],
            'reference'         => $row['reference'],
            'emplacement_id'    => $row['emplacement_id'],
            'quantite'          => $row['quantite'],
            'quantite_min'      => $row['quantite_min'],
            'unite'             => $row['unite'],
            'alerte_jours'      => $row['alerte_jours'],
            'chaine_froid'      => $row['chaine_froid'],
            'temp_stockage_min' => $row['temp_stockage_min'],
            'temp_stockage_max' => $row['temp_stockage_max'],
            'notes'             => $row['notes'],
        ]);
    } else {
        echo json_encode(['error' => 'not found']);
    }
    exit;
}

// ── AJAX saisie rapide — enregistrement multiple ─────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'saisie_rapide' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!canEdit() || !action_permise('creer')) { echo json_encode(['error' => 'Permission refusée']); exit; }
    $data = json_decode(file_get_contents('php://input'), true);
    if (empty($data['lignes'])) { echo json_encode(['error' => 'Aucune ligne reçue']); exit; }
    $sid = site_actif();
    $ok = 0; $errors = [];
    $stmt = $pdo->prepare("INSERT INTO produits (emplacement_id,nom,numero_lot,quantite,unite,date_peremption,alerte_jours,chaine_froid,notes,cree_par) VALUES(?,?,?,?,?,?,?,?,?,?)");
    $stmt_mv = $pdo->prepare("INSERT INTO mouvements_stock (produit_id,user_id,type,quantite_avant,quantite_delta,quantite_apres,motif) VALUES(?,?,0,?,?,'Saisie rapide')");
    foreach ($data['lignes'] as $i => $l) {
        $nom   = trim($l['nom'] ?? '');
        $empl  = (int)($l['emplacement_id'] ?? 0);
        $date  = trim($l['date_peremption'] ?? '');
        if (!$nom || !$empl || !$date) { $errors[] = "Ligne ".($i+1)." : nom, emplacement et date requis"; continue; }
        // Valider date
        $d = null;
        if (preg_match('#(\d{2})/(\d{2})/(\d{4})#', $date, $m)) $d = $m[3].'-'.$m[2].'-'.$m[1];
        elseif (preg_match('#\d{4}-\d{2}-\d{2}#', $date)) $d = $date;
        if (!$d) { $errors[] = "Ligne ".($i+1)." : date invalide"; continue; }
        $qte  = max(0, (float)str_replace(',', '.', $l['quantite'] ?? '0'));
        $stmt->execute([$empl, $nom, $l['lot']??null, $qte, $l['unite']??'unité', $d, 30, isset($l['froid'])&&$l['froid']?1:0, $l['notes']??null, currentUserId()]);
        $new_id = (int)$pdo->lastInsertId();
        if ($qte > 0) $stmt_mv->execute([$new_id, currentUserId(), 'entree', $qte, $qte]);
        audit_log($pdo, 'CREATION_PRODUIT', 'produits', $new_id, null, ['nom'=>$nom,'saisie_rapide'=>true]);
        $ok++;
    }
    echo json_encode(['ok' => $ok, 'errors' => $errors]);
    exit;
}

// ── AJAX Reconnaissance photo médicament ─────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'scan_photo') {
    header('Content-Type: application/json');

    // Vérifier si activé
    try {
        $scan_actif = $pdo->query("SELECT valeur FROM parametres WHERE cle='scan_photo_actif'")->fetchColumn();
        if (!$scan_actif) { echo json_encode(['error' => 'Reconnaissance photo désactivée.']); exit; }
    } catch (Exception $e) {}

    // Récupérer clé API
    $api_key = '';
    try {
        $api_key = $pdo->query("SELECT valeur FROM ai_settings WHERE cle='api_key'")->fetchColumn();
    } catch (Exception $e) {}
    if (!$api_key) { echo json_encode(['error' => 'Clé API non configurée.']); exit; }

    // Récupérer l'image base64
    $image_data = $_POST['image'] ?? '';
    $mime_type  = $_POST['mime']  ?? 'image/jpeg';
    if (!$image_data) { echo json_encode(['error' => 'Aucune image reçue.']); exit; }

    // Nettoyer le base64
    if (str_contains($image_data, ',')) {
        $image_data = explode(',', $image_data)[1];
    }

    // Prompt Claude Vision
    $prompt = 'Analyse cette photo de médicament ou matériel médical et extrait les informations suivantes en JSON UNIQUEMENT (sans texte avant ou après) :
{
  "nom": "Nom complet du medicament/produit",
  "dci": "DCI ou principe actif si visible",
  "dosage": "Dosage/concentration si visible",
  "forme": "Forme pharmaceutique (ampoule, comprime, flacon...)",
  "unite": "Unite de comptage (ampoule, flacon, comprime, unite)",
  "numero_lot": "Numero de lot si visible (cherche LOT, Lot, N lot, Batch)",
  "date_peremption": "Date peremption format YYYY-MM-DD si visible (cherche EXP, Exp, Peremption, Use before)",
  "temperature_min": null,
  "temperature_max": null,
  "chaine_froid": false,
  "confiance": "haute/moyenne/faible"
}
Si une information nest pas lisible ou absente, mets null. Pour la date, convertis au format YYYY-MM-DD.';

    $payload = [
        'model'      => 'claude-haiku-4-5-20251001',
        'max_tokens' => 500,
        'messages'   => [[
            'role'    => 'user',
            'content' => [
                [
                    'type'   => 'image',
                    'source' => [
                        'type'       => 'base64',
                        'media_type' => $mime_type,
                        'data'       => $image_data,
                    ]
                ],
                ['type' => 'text', 'text' => $prompt]
            ]
        ]]
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $api_key,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$response || $httpCode !== 200) {
        echo json_encode(['error' => 'Erreur API Claude : HTTP ' . $httpCode]);
        exit;
    }

    $data = json_decode($response, true);
    $text = $data['content'][0]['text'] ?? '';

    // Extraire le JSON de la réponse
    $json_str = $text;
    if (preg_match('/\{[\s\S]*\}/s', $text, $m)) {
        $json_str = $m[0];
    }
    $result = json_decode($json_str, true);

    if (!$result) {
        echo json_encode(["error" => "Impossible d'analyser la photo.", "raw" => $text]);
        exit;
    }

    echo json_encode(['success' => true, 'data' => $result]);
    exit;
}

// ── Mode vue ──────────────────────────────────────────────
$viewMode = $_GET['view'] ?? 'list';

$site_id     = site_actif();
$filterEmpl  = (int)($_GET['emplacement'] ?? $_GET['location'] ?? 0);
$filterStatus= $_GET['status'] ?? '';
$horizon     = $_GET['horizon'] ?? '1';
if (!in_array($horizon, ['1','2','3','6','12','all'], true)) $horizon = '1';
$sortMap = ['nom'=>'p.nom','lot'=>'p.numero_lot','emplacement'=>'e.nom','qte'=>'p.quantite','peremption'=>'p.date_peremption','statut'=>'p.date_peremption'];
$sort = $_GET['sort'] ?? 'peremption';
if (!isset($sortMap[$sort])) $sort = 'peremption';
$dir  = (strtolower($_GET['dir'] ?? 'asc') === 'desc') ? 'desc' : 'asc';
$canMod   = action_permise('modifier');
$canRenew = action_permise('renouveler');
$canDel   = action_permise('supprimer');

$canCreer = action_permise('creer');

$canDup   = action_permise('dupliquer');

$canArch  = action_permise('archiver');
if (!function_exists('pf_url')) {
  function pf_url(array $ov): string {
    $base = [
      'emplacement' => $GLOBALS['filterEmpl']  ?? 0 ?: null,
      'status'      => $GLOBALS['filterStatus'] ?? '' ?: null,
      'horizon'     => $GLOBALS['horizon']      ?? '2',
      'view'        => (($GLOBALS['viewMode'] ?? 'list') !== 'list') ? ($GLOBALS['viewMode'] ?? null) : null,
      'sort'        => (($GLOBALS['sort'] ?? 'peremption') !== 'peremption') ? ($GLOBALS['sort'] ?? null) : null,
      'dir'         => (($GLOBALS['dir'] ?? 'asc') !== 'asc') ? ($GLOBALS['dir'] ?? null) : null,
    ];
    $q = array_merge($base, $ov);
    foreach ($q as $k=>$v) { if ($v === '' || $v === null || $v === false) unset($q[$k]); }
    return 'products.php' . ($q ? '?'.http_build_query($q) : '');
  }
}
if (!function_exists('psort_th')) {
  function psort_th(string $key, string $label, string $class=''): string {
    $sort = $GLOBALS['sort'] ?? 'peremption';
    $dir  = $GLOBALS['dir']  ?? 'asc';
    $newDir = ($sort === $key && $dir === 'asc') ? 'desc' : 'asc';
    $arrow  = $sort === $key ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
    return '<th'.($class ? ' class="'.$class.'"' : '').'><a href="'.h(pf_url(['sort'=>$key,'dir'=>$newDir])).'" class="sort-th">'.$label.$arrow.'</a></th>';
  }
}
$fs = "e.site_id = " . intval($site_id);
$fe = sql_filtre_emplacements('e');

// ── Archivage (remplace suppression définitive) ───────────
if (isset($_GET['archive']) && action_permise('archiver')) {
    verifyCsrf();
    $id = (int)$_GET['archive'];
    $stmt = $pdo->prepare("SELECT p.* FROM produits p JOIN emplacements e ON e.id=p.emplacement_id WHERE p.id=? AND e.site_id=?");
    $stmt->execute([$id, $site_id]);
    $old = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($old) {
        $pdo->prepare("UPDATE produits SET statut='archive', modifie_par=? WHERE id=?")
            ->execute([currentUserId(), $id]);
        audit_log($pdo, 'ARCHIVAGE_PRODUIT', 'produits', $id, $old, ['statut'=>'archive']);
        flashSuccess("Produit « {$old['nom']} » archivé.");
    }
    header('Location: products.php' . ($filterEmpl ? "?emplacement=$filterEmpl" : ''));
    exit;
}

// ── Suppression définitive (admin seulement) ─────────────
if (isset($_GET['suppr']) && action_permise('supprimer')) {
    verifyCsrf();
    $id = (int)$_GET['suppr'];
    $stmt = $pdo->prepare("SELECT p.* FROM produits p JOIN emplacements e ON e.id=p.emplacement_id WHERE p.id=? AND e.site_id=?");
    $stmt->execute([$id, $site_id]);
    $old = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($old) {
        try {
            $pdo->prepare("DELETE FROM produits WHERE id=?")->execute([$id]);
            audit_log($pdo, 'SUPPRESSION_PRODUIT', 'produits', $id, $old);
            flashSuccess("Produit « {$old['nom']} » supprimé définitivement.");
        } catch (Throwable $e) {
            // Échec (contrainte / référence) → repli en retrait logique
            try {
                $pdo->prepare("UPDATE produits SET statut='retire', modifie_par=?, modifie_le=NOW() WHERE id=?")
                    ->execute([currentUserId(), $id]);
                audit_log($pdo, 'RETRAIT_PRODUIT', 'produits', $id, $old, ['statut'=>'retire']);
                flashSuccess("Produit « {$old['nom']} » retiré du stock.");
            } catch (Throwable $e2) {
                flashError("Suppression impossible : " . $e2->getMessage());
            }
        }
    } else {
        flashError("Produit introuvable ou non autorisé pour ce site.");
    }
    $ret = (int)($_GET['ret'] ?? 0);
    header('Location: ' . ($ret ? "locations.php?sel=$ret" : 'products.php' . ($filterEmpl ? "?emplacement=$filterEmpl" : '')));
    exit;
}

// ── Renouvellement de lot (péremption) ────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['type_form'] ?? '') === 'renouveler' && action_permise('renouveler')) {
    verifyCsrf();
    $pid      = (int)($_POST['produit_id'] ?? 0);
    $new_lot  = trim($_POST['new_lot'] ?? '') ?: null;
    $new_date = $_POST['new_date'] ?? '';
    $qte_remp = max(0, (float)str_replace(',', '.', $_POST['qte_remplacer'] ?? '0'));

    $stmt = $pdo->prepare("SELECT p.* FROM produits p JOIN emplacements e ON e.id=p.emplacement_id WHERE p.id=? AND e.site_id=?");
    $stmt->execute([$pid, $site_id]);
    $old = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$old || !$new_date || $qte_remp <= 0) {
        flashError("Renouvellement impossible : donnees incompletes.");
    } else {
        $qte_old  = (float)$old['quantite'];
        $qte_remp = min($qte_remp, $qte_old);
        if ($qte_remp >= $qte_old) {
            // Lot entier -> mise a jour de la ligne
            $pdo->prepare("UPDATE produits SET numero_lot=?, date_peremption=?, modifie_par=?, modifie_le=NOW() WHERE id=?")
                ->execute([$new_lot, $new_date, currentUserId(), $pid]);
            $pdo->prepare("INSERT INTO mouvements_stock (produit_id, user_id, type, quantite_avant, quantite_delta, quantite_apres, motif) VALUES(?,?,'entree',?,0,?,'Renouvellement lot (entier)')")
                ->execute([$pid, currentUserId(), $qte_old, $qte_old]);
            audit_log($pdo, 'RENOUVELLEMENT_LOT', 'produits', $pid,
                ['numero_lot'=>$old['numero_lot'], 'date_peremption'=>$old['date_peremption'], 'quantite'=>$qte_old],
                ['numero_lot'=>$new_lot, 'date_peremption'=>$new_date, 'quantite'=>$qte_old]);
        } else {
            // Partiel -> l'ancien lot diminue, nouveau lot cree
            $reste = $qte_old - $qte_remp;
            $pdo->prepare("UPDATE produits SET quantite=?, modifie_par=?, modifie_le=NOW() WHERE id=?")
                ->execute([$reste, currentUserId(), $pid]);
            $pdo->prepare("INSERT INTO produits (emplacement_id, nom, reference, numero_lot, quantite, quantite_min, unite, date_peremption, alerte_jours, chaine_froid, temp_stockage_min, temp_stockage_max, notes, cree_par)
                           SELECT emplacement_id, nom, reference, ?, ?, quantite_min, unite, ?, alerte_jours, chaine_froid, temp_stockage_min, temp_stockage_max, notes, ?
                           FROM produits WHERE id=?")
                ->execute([$new_lot, $qte_remp, $new_date, currentUserId(), $pid]);
            $newId = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO mouvements_stock (produit_id, user_id, type, quantite_avant, quantite_delta, quantite_apres, motif) VALUES(?,?,'sortie',?,?,?,'Renouvellement - retrait perime')")
                ->execute([$pid, currentUserId(), $qte_old, -$qte_remp, $reste]);
            $pdo->prepare("INSERT INTO mouvements_stock (produit_id, user_id, type, quantite_avant, quantite_delta, quantite_apres, motif) VALUES(?,?,'entree',0,?,?,'Renouvellement - nouveau lot')")
                ->execute([$newId, currentUserId(), $qte_remp, $qte_remp]);
            audit_log($pdo, 'RENOUVELLEMENT_LOT', 'produits', $pid,
                ['numero_lot'=>$old['numero_lot'], 'date_peremption'=>$old['date_peremption'], 'quantite_remplacee'=>$qte_remp],
                ['numero_lot'=>$new_lot, 'date_peremption'=>$new_date, 'nouveau_produit_id'=>$newId]);
        }
        flashSuccess("Lot renouvele : {$qte_remp} {$old['unite']} de « {$old['nom']} » (nouvelle date " . date('d/m/Y', strtotime($new_date)) . ").");
    }
    $ret = (int)($_POST['ret'] ?? 0);
    header('Location: ' . ($ret ? "locations.php?sel=$ret" : 'products.php' . ($filterEmpl ? "?emplacement=$filterEmpl" : '')));
    exit;
}

// ── Création / Modification ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && canEdit()) {
    verifyCsrf();
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0 && !action_permise('modifier')) { flashError("Vous n'avez pas le droit de modifier un produit."); header('Location: products.php'); exit; }

    if ($id === 0 && !action_permise('creer'))   { flashError("Vous n'avez pas le droit de créer un produit."); header('Location: products.php'); exit; }
    $f  = [
        'emplacement_id'    => (int)$_POST['emplacement_id'],
        'nom'               => trim($_POST['nom']            ?? ''),
        'reference'         => trim($_POST['reference']      ?? '') ?: null,
        'numero_lot'        => trim($_POST['numero_lot']     ?? '') ?: null,
        'gtin'              => trim($_POST['gtin']           ?? '') ?: null,
        'quantite'          => max(0, (float)str_replace(',', '.', $_POST['quantite'] ?? '0')),
        'quantite_min'      => $_POST['quantite_min'] !== '' ? max(0, (float)$_POST['quantite_min']) : 0,
        'unite'             => trim($_POST['unite']          ?? 'unité'),
        'date_peremption'   => $_POST['date_peremption']     ?? '',
        'alerte_jours'      => max(1, (int)($_POST['alerte_jours'] ?? 30)),
        'chaine_froid'      => isset($_POST['chaine_froid']) ? 1 : 0,
        'temp_stockage_min' => $_POST['temp_stockage_min'] !== '' ? (float)$_POST['temp_stockage_min'] : null,
        'temp_stockage_max' => $_POST['temp_stockage_max'] !== '' ? (float)$_POST['temp_stockage_max'] : null,
        'notes'             => trim($_POST['notes'] ?? '') ?: null,
    ];

    if (!$f['nom'] || !$f['date_peremption'] || !$f['emplacement_id']) {
        flashError("Nom, emplacement et date de péremption sont obligatoires.");
    } elseif (currentRole() === 'saisie' && $id) {
        // Saisie : mise à jour date uniquement
        $stmt = $pdo->prepare("SELECT p.* FROM produits p JOIN emplacements e ON e.id=p.emplacement_id WHERE p.id=? AND e.site_id=?");
        $stmt->execute([$id, $site_id]);
        $old = $stmt->fetch(PDO::FETCH_ASSOC);
        $pdo->prepare("UPDATE produits SET date_peremption=?, modifie_par=?, modifie_le=NOW() WHERE id=?")
            ->execute([$f['date_peremption'], currentUserId(), $id]);
        audit_log($pdo, 'MODIF_PEREMPTION', 'produits', $id,
            ['date_peremption' => $old['date_peremption']],
            ['date_peremption' => $f['date_peremption']]);
        flashSuccess("Date de péremption mise à jour.");
    } elseif (canEdit()) {
        if ($id) {
            $stmt = $pdo->prepare("SELECT p.* FROM produits p JOIN emplacements e ON e.id=p.emplacement_id WHERE p.id=? AND e.site_id=?");
            $stmt->execute([$id, $site_id]);
            $old = $stmt->fetch(PDO::FETCH_ASSOC);

            $pdo->prepare("UPDATE produits SET
                emplacement_id=?, nom=?, reference=?, numero_lot=?, gtin=?,
                quantite=?, quantite_min=?, unite=?, date_peremption=?,
                alerte_jours=?, chaine_froid=?, temp_stockage_min=?,
                temp_stockage_max=?, notes=?, modifie_par=?, modifie_le=NOW()
                WHERE id=?")
                ->execute([
                    $f['emplacement_id'], $f['nom'], $f['reference'], $f['numero_lot'], $f['gtin'],
                    $f['quantite'], $f['quantite_min'], $f['unite'], $f['date_peremption'],
                    $f['alerte_jours'], $f['chaine_froid'], $f['temp_stockage_min'],
                    $f['temp_stockage_max'], $f['notes'], currentUserId(), $id
                ]);

            // Mouvement stock si quantité changée
            if ($old && $old['quantite'] != $f['quantite']) {
                $delta = $f['quantite'] - $old['quantite'];
                $pdo->prepare("INSERT INTO mouvements_stock (produit_id, user_id, type, quantite_avant, quantite_delta, quantite_apres, motif) VALUES(?,?,?,?,?,?,'Modification manuelle')")
                    ->execute([$id, currentUserId(), $delta > 0 ? 'entree' : 'sortie', $old['quantite'], $delta, $f['quantite']]);
            }

            audit_log($pdo, 'MODIF_PRODUIT', 'produits', $id, $old, $f);
            flashSuccess("Produit « {$f['nom']} » mis à jour.");
        } else {
            $pdo->prepare("INSERT INTO produits (emplacement_id, nom, reference, numero_lot, gtin, quantite, quantite_min, unite, date_peremption, alerte_jours, chaine_froid, temp_stockage_min, temp_stockage_max, notes, cree_par) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([
                    $f['emplacement_id'], $f['nom'], $f['reference'], $f['numero_lot'], $f['gtin'],
                    $f['quantite'], $f['quantite_min'], $f['unite'], $f['date_peremption'],
                    $f['alerte_jours'], $f['chaine_froid'], $f['temp_stockage_min'],
                    $f['temp_stockage_max'], $f['notes'], currentUserId()
                ]);
            $newId = (int)$pdo->lastInsertId();

            // Mouvement stock initial
            if ($f['quantite'] > 0) {
                $pdo->prepare("INSERT INTO mouvements_stock (produit_id, user_id, type, quantite_avant, quantite_delta, quantite_apres, motif) VALUES(?,?,?,0,?,?,'Création produit')")
                    ->execute([$newId, currentUserId(), 'entree', $f['quantite'], $f['quantite']]);
            }

            audit_log($pdo, 'CREATION_PRODUIT', 'produits', $newId, null, $f);
            flashSuccess("Produit « {$f['nom']} » créé.");
        }
    }
    header('Location: products.php' . ($filterEmpl ? "?emplacement=$filterEmpl" : ''));
    exit;
}

// ── Édition ───────────────────────────────────────────────
$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT p.* FROM produits p JOIN emplacements e ON e.id=p.emplacement_id WHERE p.id=? AND e.site_id=?");
    $stmt->execute([(int)$_GET['edit'], $site_id]);
    $editing = $stmt->fetch(PDO::FETCH_ASSOC);
}

$renewProduct = null;
$renewQty = isset($_GET['rq']) ? max(1,(int)$_GET['rq']) : null;
if (isset($_GET['renew'])) {
    $s = $pdo->prepare("SELECT p.* FROM produits p JOIN emplacements e ON e.id=p.emplacement_id WHERE p.id=? AND e.site_id=?");
    $s->execute([(int)$_GET['renew'], $site_id]);
    $renewProduct = $s->fetch(PDO::FETCH_ASSOC);
}

// ── Infos emplacement filtré ──────────────────────────────
$filterEmplData = null;
if ($filterEmpl) {
    $s = $pdo->prepare("SELECT e.*, ep.nom AS parent_nom, ep.id AS parent_id_val FROM emplacements e LEFT JOIN emplacements ep ON e.parent_id=ep.id WHERE e.id=?");
    $s->execute([$filterEmpl]);
    $filterEmplData = $s->fetch(PDO::FETCH_ASSOC);
}

// ── Liste produits ────────────────────────────────────────
$where  = "WHERE p.statut = 'actif' AND $fs AND $fe";
$params = [];
if ($filterEmpl)                  { $where .= ' AND p.emplacement_id=?';                                                $params[] = $filterEmpl; }
if ($filterStatus === 'expired')  { $where .= ' AND p.date_peremption < CURDATE()'; }
if ($filterStatus === 'critical') { $where .= ' AND p.date_peremption >= CURDATE() AND DATEDIFF(p.date_peremption,CURDATE()) <= 7'; }
if ($filterStatus === 'warning')  { $where .= ' AND DATEDIFF(p.date_peremption,CURDATE()) BETWEEN 8 AND 30'; }
if ($filterStatus === 'ok')       { $where .= ' AND DATEDIFF(p.date_peremption,CURDATE()) > 30'; }
if ($filterStatus === 'cold')     { $where .= ' AND p.chaine_froid=1'; }
if ($filterStatus === 'lowstock') { $where .= ' AND p.quantite_min > 0 AND p.quantite <= p.quantite_min'; }
if ($horizon !== 'all')           { $where .= ' AND p.date_peremption <= (CURDATE() + INTERVAL '.(int)$horizon.' MONTH)'; }
if ($filterStatus === 'nocode')   { $where .= " AND COALESCE(NULLIF(p.gtin,''),NULLIF(p.ean,''),NULLIF(p.datamatrix,'')) IS NULL"; }

$orderSql = "ORDER BY {$sortMap[$sort]} $dir, p.date_peremption ASC";
$stmt = $pdo->prepare("
    SELECT p.*,
           e.nom AS loc_name, ep.nom AS loc_parent_name,
           DATEDIFF(p.date_peremption, CURDATE()) AS days_left
    FROM produits p
    JOIN emplacements e  ON e.id = p.emplacement_id
    LEFT JOIN emplacements ep ON ep.id = e.parent_id
    $where
    $orderSql
");
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

$nbNocode = 0;
try {
    $nbNocode = (int)$pdo->query("SELECT COUNT(*) FROM produits p JOIN emplacements e ON e.id=p.emplacement_id WHERE p.statut='actif' AND $fs AND $fe AND COALESCE(NULLIF(p.gtin,''),NULLIF(p.ean,''),NULLIF(p.datamatrix,'')) IS NULL")->fetchColumn();
} catch (Exception $e) {}

// Emplacements pour le select — tous niveaux confondus
$allEmpls = $pdo->prepare("
    SELECT e.id, e.nom, e.parent_id, e.actif,
           ep.nom AS parent_nom
    FROM emplacements e
    LEFT JOIN emplacements ep ON e.parent_id = ep.id
    WHERE e.site_id = ? AND e.actif = 1 AND $fe
    ORDER BY COALESCE(ep.nom, e.nom), e.parent_id IS NULL DESC, e.nom
");
$allEmpls->execute([$site_id]);
$allEmpls = $allEmpls->fetchAll(PDO::FETCH_ASSOC);

// Construire un arbre récursif pour afficher tous les niveaux
function buildEmplTree(array $empls, $parentId = null): array {
    $tree = [];
    foreach ($empls as $e) {
        if ($e['parent_id'] == $parentId) {
            $e['children'] = buildEmplTree($empls, $e['id']);
            $tree[] = $e;
        }
    }
    return $tree;
}

function renderEmplOptions(array $tree, $selectedId = null, int $depth = 0): string {
    $html = '';
    $prefix = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $depth);
    $arrow  = $depth > 0 ? '↳ ' : '📦 ';
    foreach ($tree as $e) {
        $selected = $selectedId == $e['id'] ? 'selected' : '';
        $html .= "<option value=\"{$e['id']}\" $selected>{$prefix}{$arrow}" . htmlspecialchars($e['nom'], ENT_QUOTES) . "</option>";
        if (!empty($e['children'])) {
            $html .= renderEmplOptions($e['children'], $selectedId, $depth + 1);
        }
    }
    return $html;
}

$emplTree = buildEmplTree($allEmpls);
$emplsParent = []; $emplsChild = [];
foreach ($allEmpls as $e) {
    if ($e['parent_id'] === null) $emplsParent[] = $e;
    else $emplsChild[] = $e;
}
$total         = $pdo->query("SELECT COUNT(*) FROM produits p JOIN emplacements e ON e.id=p.emplacement_id WHERE p.statut='actif' AND $fs AND $fe")->fetchColumn();
$totalCold     = $pdo->query("SELECT COUNT(*) FROM produits p JOIN emplacements e ON e.id=p.emplacement_id WHERE p.statut='actif' AND p.chaine_froid=1 AND $fs AND $fe")->fetchColumn();
$totalLowStock = $pdo->query("SELECT COUNT(*) FROM produits p JOIN emplacements e ON e.id=p.emplacement_id WHERE p.statut='actif' AND p.quantite_min>0 AND p.quantite<=p.quantite_min AND $fs AND $fe")->fetchColumn();

require_once 'includes/header.php';
?>

<style>
/* ══════════════════════════════════════
   products.php — Design Cadurix
══════════════════════════════════════ */

/* ── Page header ── */
.page-header {
  display: flex; align-items: center;
  justify-content: space-between;
  margin-bottom: 24px; gap: 16px; flex-wrap: wrap;
}
.page-title-group { flex: 1; min-width: 0; }
.page-title {
  font-size: 22px; font-weight: 700;
  color: var(--text); margin-bottom: 2px;
  display: flex; align-items: center; gap: 10px;
}
.page-sub { font-size: 13px; color: var(--text2); }
.breadcrumb {
  font-size: 12px; color: var(--text2);
  margin-bottom: 6px; display: flex; align-items: center; gap: 5px; flex-wrap: wrap;
}
.breadcrumb a { color: var(--text2); text-decoration: none; transition: color .15s; }
.breadcrumb a:hover { color: var(--primary); }

/* ── KPI cards ── */
.kpi-strip {
  display: grid; grid-template-columns: repeat(4, 1fr);
  gap: 12px; margin-bottom: 24px;
}
.kpi-card {
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: 14px;
  padding: 16px 18px;
  position: relative; overflow: hidden;
  transition: border-color .2s, transform .2s;
}
.kpi-card:hover { border-color: rgba(var(--primary-rgb),.3); transform: translateY(-1px); }
.kpi-card::before {
  content: ''; position: absolute;
  top: 0; left: 0; right: 0; height: 3px;
  background: var(--kpi-color, var(--primary));
  opacity: .6;
}
.kpi-card.kpi-danger { --kpi-color: #E24B4A; }
.kpi-card.kpi-warn   { --kpi-color: #EF9F27; }
.kpi-card.kpi-ok     { --kpi-color: var(--primary); }
.kpi-card.kpi-muted  { --kpi-color: var(--text3); }
.kpi-icon {
  font-size: 18px; margin-bottom: 10px;
  width: 34px; height: 34px;
  background: rgba(var(--primary-rgb),.08);
  border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
}
.kpi-card.kpi-danger .kpi-icon { background: rgba(226,75,74,.1); }
.kpi-card.kpi-warn   .kpi-icon { background: rgba(239,159,39,.1); }
.kpi-value {
  font-size: 30px; font-weight: 800;
  color: var(--text); line-height: 1;
  font-variant-numeric: tabular-nums;
}
.kpi-label {
  font-size: 11px; color: var(--text2);
  margin-top: 4px; letter-spacing: .04em;
  text-transform: uppercase; font-weight: 500;
}

/* ── Barre filtres ── */
.filters-wrap {
  display: flex; align-items: center;
  gap: 8px; margin-bottom: 20px; flex-wrap: wrap;
}
.filter-view-toggle {
  display: flex;
  border: 1px solid var(--border);
  border-radius: 8px; overflow: hidden; flex-shrink: 0;
}
.filter-view-toggle a {
  padding: 6px 14px; font-size: 12px;
  display: flex; align-items: center; gap: 5px;
  text-decoration: none; transition: all .15s;
  font-weight: 500;
}
.filter-chip {
  padding: 5px 12px; font-size: 12px; font-weight: 500;
  border-radius: 20px; border: 1px solid var(--border);
  text-decoration: none; color: var(--text2);
  transition: all .15s; white-space: nowrap;
  display: inline-flex; align-items: center; gap: 5px;
}
.filter-chip:hover { border-color: rgba(var(--primary-rgb),.4); color: var(--primary); }
.filter-chip.active { background: var(--primary); border-color: var(--primary); color: #fff; }
.filter-chip.chip-danger.active  { background: #E24B4A; border-color: #E24B4A; }
.filter-chip.chip-warn.active    { background: #EF9F27; border-color: #EF9F27; }
.filter-chip.chip-ok.active      { background: #1D9E75; border-color: #1D9E75; }
.hz-select { display:flex; align-items:center; gap:5px; flex-wrap:wrap; width:100%; margin-bottom:8px; }
.hz-lbl { font-size:11.5px; font-weight:700; color:var(--text3); text-transform:uppercase; letter-spacing:.4px; margin-right:2px; }
.hz-chip { padding:5px 11px; border:1px solid var(--border); border-radius:20px; font-size:12px; font-weight:600; color:var(--text2); text-decoration:none; white-space:nowrap; }
.hz-chip:hover { border-color:var(--primary); color:var(--primary); }
.hz-chip.active { background:var(--text); color:var(--bg); border-color:var(--text); }
.sort-th { color:inherit; text-decoration:none; cursor:pointer; white-space:nowrap; }
.sort-th:hover { color:var(--primary); }

/* ── Table principale ── */
.products-wrap {
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: 16px;
  overflow: hidden;
}
.products-header {
  display: flex; align-items: center;
  justify-content: space-between;
  padding: 14px 20px;
  border-bottom: 1px solid var(--border);
  background: var(--bg3);
}
.products-header-title {
  font-size: 13px; font-weight: 600;
  color: var(--text); display: flex;
  align-items: center; gap: 8px;
}
.products-count {
  font-size: 11px; font-weight: 600;
  background: rgba(var(--primary-rgb),.1);
  color: var(--primary);
  padding: 2px 8px; border-radius: 10px;
}
.products-table { width: 100%; border-collapse: collapse; }
.products-table thead th {
  padding: 10px 16px;
  font-size: 11px; font-weight: 600;
  letter-spacing: .06em; text-transform: uppercase;
  color: var(--text2);
  background: var(--bg3);
  border-bottom: 1px solid var(--border);
  text-align: left; white-space: nowrap;
}
.products-table tbody tr {
  border-bottom: 1px solid var(--border);
  transition: background .1s;
}
.products-table tbody tr:last-child { border-bottom: none; }
.products-table tbody tr:hover { background: rgba(var(--primary-rgb),.03); }
.products-table tbody tr.row-expired  { background: rgba(107,114,128,.04); }
.products-table tbody tr.row-critical { background: rgba(226,75,74,.04); }
.products-table tbody tr.row-warning  { background: rgba(239,159,39,.03); }
.products-table td { padding: 11px 16px; vertical-align: middle; }

/* Colonne produit */
.prod-name { font-weight: 600; font-size: 14px; color: var(--text); }
.prod-tags { display: flex; gap: 5px; flex-wrap: wrap; margin-top: 4px; }
.prod-tag {
  font-size: 10px; font-weight: 600;
  padding: 2px 7px; border-radius: 5px;
}
.tag-cold  { background: rgba(96,165,250,.12); color: #60A5FA; }
.tag-low   { background: rgba(245,158,11,.12); color: #F59E0B; }
.prod-note { font-size: 11px; color: var(--text3); margin-top: 2px; }

/* Emplacement */
.loc-parent { font-size: 10px; color: var(--text3); }
.loc-name   { font-size: 13px; color: var(--text); font-weight: 500; }

/* Lot */
.lot-val { font-family: var(--mono); font-size: 12px; color: var(--text2); }

/* Qté */
.qty-val { font-family: var(--mono); font-size: 13px; font-weight: 600; color: var(--text); }
.qty-unit { font-size: 10px; color: var(--text3); }
.qty-min  { font-size: 10px; color: #F59E0B; }

/* Date */
.date-val { font-family: var(--mono); font-size: 13px; font-weight: 600; color: var(--text); }

/* Badges statut */
.status-pill {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 4px 10px; border-radius: 20px;
  font-size: 11px; font-weight: 700; white-space: nowrap;
}
.pill-expired  { background: rgba(107,114,128,.15); color: #9CA3AF; }
.pill-critical { background: rgba(226,75,74,.15);   color: #E24B4A; }
.pill-warning  { background: rgba(239,159,39,.15);  color: #EF9F27; }
.pill-ok       { background: rgba(29,158,117,.12);  color: #1D9E75; }

/* Actions */
.row-actions { display: flex; gap: 4px; justify-content: flex-end; }
.act-btn {
  width: 28px; height: 28px; border-radius: 7px;
  display: inline-flex; align-items: center; justify-content: center;
  font-size: 13px; text-decoration: none; cursor: pointer;
  border: 1px solid transparent; background: transparent;
  color: var(--text2); transition: all .15s;
}
.act-btn:hover { background: var(--bg3); border-color: var(--border); color: var(--text); }
.act-btn.act-danger:hover { background: rgba(226,75,74,.1); border-color: rgba(226,75,74,.3); color: #E24B4A; }

/* ── Empty state ── */
.empty-state {
  display: flex; flex-direction: column;
  align-items: center; justify-content: center;
  padding: 60px 20px; color: var(--text2); gap: 12px;
  text-align: center;
}
.empty-state-icon {
  font-size: 48px; opacity: .25;
}
.empty-state-text { font-size: 15px; }

/* ── Vue groupée ── */
.grp-card {
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: 14px; overflow: hidden;
  border-left-width: 3px !important;
  transition: box-shadow .2s;
}
.grp-card:hover { box-shadow: 0 4px 20px rgba(0,0,0,.08); }
.grp-header {
  display: flex; align-items: center;
  justify-content: space-between;
  padding: 14px 18px; cursor: pointer;
  transition: background .1s;
}
.grp-header:hover { background: rgba(var(--primary-rgb),.03); }
.grp-name { font-size: 14px; font-weight: 600; color: var(--text); }
.grp-meta { font-size: 12px; color: var(--text2); display: flex; gap: 10px; margin-top: 3px; flex-wrap: wrap; }
.grp-th { padding: 8px 14px; font-size: 11px; font-weight: 600; letter-spacing:.05em; text-transform: uppercase; color: var(--text2); text-align: left; }
.grp-td { padding: 10px 14px; font-size: 13px; }

/* ── Formulaire ── */
.form-panel-card {
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: 16px;
  padding: 24px; margin-bottom: 20px;
}
.form-panel-title {
  font-size: 16px; font-weight: 700;
  color: var(--text); margin-bottom: 20px;
  display: flex; align-items: center; gap: 10px;
  padding-bottom: 16px;
  border-bottom: 1px solid var(--border);
}

/* ── Flash messages ── */
.flash-banner {
  display: flex; align-items: center; gap: 10px;
  padding: 12px 16px; border-radius: 10px;
  font-size: 13px; margin-bottom: 16px;
  border: 1px solid transparent;
}
.flash-warn { background: rgba(239,159,39,.1); border-color: rgba(239,159,39,.25); color: #EF9F27; }
.flash-info { background: rgba(var(--primary-rgb),.08); border-color: rgba(var(--primary-rgb),.2); color: var(--primary); }
.flash-locked { background: rgba(107,114,128,.08); border-color: rgba(107,114,128,.2); color: var(--text2); }

/* ── Mobile cards ── */
.mobile-cards { display: none; }
.mobile-card {
  background: var(--bg2); border: 1px solid var(--border);
  border-radius: 12px; padding: 14px; border-left-width: 3px;
}
.mobile-card-name { font-size: 15px; font-weight: 700; color: var(--text); margin-bottom: 6px; }
.mobile-card-info { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin: 8px 0 10px; }
.mcf-label { font-size: 10px; color: var(--text3); text-transform: uppercase; letter-spacing:.05em; margin-bottom:2px; }
.mcf-val   { font-size: 13px; color: var(--text); font-weight: 600; font-family: var(--mono); }
.mobile-card-actions { display: flex; gap: 6px; border-top: 1px solid var(--border); padding-top: 10px; }
.mobile-card-actions .btn { flex:1; justify-content:center; font-size:12px; padding:8px; }
.mc-expired  { border-left-color: #6b7280; }
.mc-critical { border-left-color: #E24B4A; }
.mc-warning  { border-left-color: #EF9F27; }
.mc-ok       { border-left-color: var(--primary); }

@media (max-width: 768px) {
  .products-table { display: none !important; }
  .mobile-cards { display: flex !important; flex-direction: column; gap: 10px; }
  .kpi-strip { grid-template-columns: 1fr 1fr; }
  .filters-wrap { overflow-x: auto; flex-wrap: nowrap; padding-bottom: 4px; scrollbar-width: none; }
  .filters-wrap::-webkit-scrollbar { display: none; }
  .filter-chip { flex-shrink: 0; }
  .form-row, .form-row3 { grid-template-columns: 1fr !important; }
  .page-header { flex-direction: column !important; align-items: stretch !important; gap: 8px !important; }
  .page-header > div:last-child { display: flex; flex-wrap: wrap; gap: 6px; }
  .page-header .btn { flex: 1; min-width: 100px; justify-content: center; font-size: 12px; }
  #saisie-rapide-panel > .card > div:nth-child(2) { overflow-x: auto; -webkit-overflow-scrolling: touch; }
  #sr-table { min-width: 700px; }
}

@media (max-width: 420px) {
  .kpi-strip { grid-template-columns: 1fr 1fr; }
  .kpi-value { font-size: 26px !important; }
}
</style>

<?php
// Grouper emplacements
$emplsParent = []; $emplsChild = [];
foreach ($allEmpls as $e) {
    if ($e['parent_id'] === null) $emplsParent[] = $e;
    else $emplsChild[] = $e;
}
?>

<div class="page-header">
  <div class="page-title-group">
    <?php if ($filterEmplData): ?>
    <div class="breadcrumb">
      <a href="/products.php">Tous</a> ›
      <?php if ($filterEmplData['parent_id_val']): ?>
        <a href="/locations.php?view=<?= $filterEmplData['parent_id_val'] ?>"><?= h($filterEmplData['parent_nom']) ?></a> ›
      <?php endif; ?>
      <span style="color:var(--text)"><?= h($filterEmplData['nom']) ?></span>
    </div>
    <?php endif; ?>
    <div class="page-title">💉 Médicaments & Matériel</div>
    <div class="page-sub">
      <?= $filterEmplData ? h($filterEmplData['nom']).' — ' : '' ?>Gestion des péremptions et stocks
    </div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <?php if (canEdit()): ?>
    <?php if($canCreer): ?><button class="btn btn-primary" id="btn-ajouter" style="display:inline-flex;align-items:center;gap:6px"><i class="ti ti-plus"></i> Ajouter</button><?php endif; ?>
    <button class="btn btn-ghost" id="btn-saisie-rapide" title="Saisir plusieurs produits d'un coup" style="display:inline-flex;align-items:center;gap:6px"><i class="ti ti-bolt"></i> Saisie rapide</button>
    <?php endif; ?>
    <?php if (peut_faire(ROLE_GESTIONNAIRE)): ?>
    <a href="import.php" class="btn btn-ghost" style="display:inline-flex;align-items:center;gap:6px"><i class="ti ti-file-import"></i> Import</a>
    <?php endif; ?>
    <a href="export_pdf.php" class="btn btn-ghost" target="_blank" style="display:inline-flex;align-items:center;gap:6px"><i class="ti ti-file-type-pdf"></i> PDF</a>
  </div>
</div>

<!-- SAISIE RAPIDE -->
<?php if (canEdit()): ?>
<div id="saisie-rapide-panel" style="display:none;margin-bottom:20px">
  <div class="card">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
      <div>
        <div class="card-title" style="margin-bottom:2px">⚡ Saisie rapide</div>
        <div style="font-size:.8rem;color:var(--text2)">Ajoutez plusieurs produits en une seule fois. Colonnes obligatoires : Nom, Emplacement, Péremption.</div>
      </div>
      <button type="button" class="btn btn-ghost btn-xs" onclick="toggleSaisieRapide()">✕ Fermer</button>
    </div>

    <div style="overflow-x:auto;border:1px solid var(--border);border-radius:8px">
      <table id="sr-table" style="width:100%;border-collapse:collapse;min-width:900px">
        <thead>
          <tr style="background:var(--bg3)">
            <th style="padding:8px 10px;font-size:11px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:var(--text3);text-align:left;width:220px">Nom *</th>
            <th style="padding:8px 10px;font-size:11px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:var(--text3);text-align:left;width:180px">Emplacement *</th>
            <th style="padding:8px 10px;font-size:11px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:var(--text3);text-align:left;width:130px">N° Lot</th>
            <th style="padding:8px 10px;font-size:11px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:var(--text3);text-align:left;width:120px">Péremption *</th>
            <th style="padding:8px 10px;font-size:11px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:var(--text3);text-align:center;width:70px">Qté</th>
            <th style="padding:8px 10px;font-size:11px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:var(--text3);text-align:left;width:90px">Unité</th>
            <th style="padding:8px 10px;font-size:11px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:var(--text3);text-align:center;width:50px">❄️</th>
            <th style="padding:8px 10px;font-size:11px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:var(--text3);text-align:left">Notes</th>
            <th style="width:36px"></th>
          </tr>
        </thead>
        <tbody id="sr-tbody">
          <!-- lignes injectées en JS -->
        </tbody>
      </table>
    </div>

    <div style="display:flex;align-items:center;gap:10px;margin-top:14px;flex-wrap:wrap">
      <button type="button" class="btn btn-ghost btn-sm" onclick="srAddRow()">
        <i class="ti ti-plus"></i> Ajouter une ligne
      </button>
      <button type="button" class="btn btn-primary" onclick="srSubmit()" id="sr-submit-btn">
        <i class="ti ti-check"></i> Enregistrer tout
      </button>
      <button type="button" class="btn btn-ghost btn-sm" onclick="srClear()" title="Vider toutes les lignes">
        <i class="ti ti-trash"></i> Vider
      </button>
      <span id="sr-status" style="font-size:.82rem;color:var(--text2)"></span>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
// Emplacements pour JS (saisie rapide)
$empl_for_js = [];
foreach ($allEmpls as $e) {
    $empl_for_js[] = [
        'id'  => $e['id'],
        'nom' => ($e['parent_nom'] ? $e['parent_nom'].' › ' : '') . $e['nom'],
    ];
}
?>
<script>
const SR_EMPLS = <?= json_encode($empl_for_js) ?>;
</script>

<?php if (isLecteur()): ?>
  <div class="flash-banner flash-locked"><i class="ti ti-eye"></i> Mode consultation uniquement.</div>
<?php elseif (currentRole() === 'saisie'): ?>
  <div class="flash-banner flash-info"><i class="ti ti-pencil"></i> Mode saisie — mise à jour des dates de péremption uniquement.</div>
<?php endif; ?>

<?php if ($totalLowStock > 0): ?>
<div class="flash-banner flash-warn">
  <i class="ti ti-package"></i>
  <strong><?= $totalLowStock ?> produit(s)</strong> en dessous du stock minimum —
  <a href="products.php?status=lowstock" style="color:#EF9F27;font-weight:700;margin-left:4px">Voir →</a>
</div>
<?php endif; ?>

<!-- FORMULAIRE -->
<?php if (canEdit()): ?>
<div id="form-panel" style="<?= $editing ? '' : 'display:none;' ?>margin-bottom:20px;">
  <div class="form-panel-card">
    <div class="form-panel-title"><?= $editing ? '<i class="ti ti-edit"></i> Modifier le produit' : '<i class="ti ti-plus"></i> Nouveau produit' ?></div>
    <form method="POST">
      <?= csrfField() ?>
      <?php if ($editing): ?><input type="hidden" name="id" value="<?= $editing['id'] ?>"><?php endif; ?>

      <div class="form-row">
        <div class="form-group" style="position:relative">
          <label class="form-label" style="display:flex;align-items:center;justify-content:space-between">
            <span>Nom *</span>
            <?php
            $scan_photo_ok = false;
            try { $scan_photo_ok = (bool)$pdo->query("SELECT valeur FROM parametres WHERE cle='scan_photo_actif'")->fetchColumn(); } catch(Exception $e) {}
            ?>
            <?php if ($scan_photo_ok): ?>
            <button type="button" id="btn-scan-photo"
                    style="display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:7px;
                           background:var(--primary-bg);border:1px solid rgba(var(--primary-rgb),.3);
                           color:var(--primary);font-size:11px;font-weight:600;cursor:pointer"
                    title="Scanner la boîte avec la caméra">
              📷 Scanner la boîte
            </button>
            <?php endif; ?>
          </label>
          <input type="text" name="nom" id="nom-input" class="form-control" required
                 value="<?= h($editing['nom'] ?? '') ?>"
                 placeholder="Ex: Adrénaline 1mg/ml, Doliprane 1000mg…"
                 autocomplete="off" oninput="acNom(this.value)">
          <div id="ac-list"></div>
        </div>

        <!-- Zone scan photo -->
        <div id="scan-photo-zone" style="display:none;margin-top:-8px;margin-bottom:16px">
          <div style="background:var(--bg3);border:1px solid var(--border);border-radius:10px;padding:14px">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
              <div style="font-size:.82rem;font-weight:600;color:var(--text)">📷 Reconnaissance photo</div>
              <button type="button" onclick="closeScanPhoto()"
                      style="background:none;border:none;cursor:pointer;color:var(--text3);font-size:16px">✕</button>
            </div>
            <!-- Caméra -->
            <div id="scan-camera-wrap" style="display:none;position:relative;border-radius:8px;overflow:hidden;background:#000;margin-bottom:10px">
              <video id="scan-video" style="width:100%;max-height:200px;object-fit:cover;display:block" autoplay playsinline></video>
              <button type="button" onclick="takePhoto()"
                      style="position:absolute;bottom:10px;left:50%;transform:translateX(-50%);
                             background:white;border:none;border-radius:50%;width:50px;height:50px;
                             font-size:22px;cursor:pointer;box-shadow:0 2px 10px rgba(0,0,0,.3)">📷</button>
            </div>
            <!-- Canvas caché pour capture -->
            <canvas id="scan-canvas" style="display:none"></canvas>
            <!-- Boutons -->
            <div style="display:flex;gap:8px;flex-wrap:wrap">
              <button type="button" onclick="startCamera()" id="btn-camera"
                      class="btn btn-ghost btn-sm" style="flex:1">
                <i class="ti ti-camera"></i> Caméra
              </button>
              <label class="btn btn-ghost btn-sm" style="flex:1;cursor:pointer;justify-content:center">
                <i class="ti ti-upload"></i> Fichier
                <input type="file" accept="image/*" style="display:none" onchange="uploadPhoto(this)">
              </label>
            </div>
            <!-- Résultat -->
            <div id="scan-result" style="display:none;margin-top:10px"></div>
            <!-- Loading -->
            <div id="scan-loading" style="display:none;text-align:center;padding:12px;color:var(--text2);font-size:.82rem">
              <div style="font-size:20px;margin-bottom:6px">🤖</div>
              Claude analyse la photo…
            </div>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Emplacement *</label>
          <select name="emplacement_id" class="form-control" required>
            <option value="">— Choisir —</option>
            <?= renderEmplOptions($emplTree, $editing['emplacement_id'] ?? $filterEmpl) ?>
          </select>
        </div>
      </div>

      <?php if (currentRole() !== 'saisie'): ?>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Référence</label>
          <input type="text" name="reference" class="form-control"
                 value="<?= h($editing['reference'] ?? '') ?>" placeholder="Code produit…">
        </div>
        <div class="form-group">
          <label class="form-label">Numéro de lot</label>
          <input type="text" name="numero_lot" class="form-control"
                 value="<?= h($editing['numero_lot'] ?? '') ?>" placeholder="LOT2024A…">
        </div>
        <div class="form-group">
          <label class="form-label">Code-barres (scan) <span style="font-weight:400;color:var(--text3);font-size:11px">— GTIN / EAN / CIP13</span></label>
          <input type="text" name="gtin" class="form-control"
                 value="<?= h($editing['gtin'] ?: ($editing['ean'] ?: ($editing['datamatrix'] ?? ''))) ?>" placeholder="Ex : 3400930000000 — utile pour le matériel (seringues…)">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Quantité</label>
          <input type="number" name="quantite" class="form-control" step="0.01" min="0"
                 value="<?= $editing['quantite'] ?? '0' ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Unité</label>
          <input type="text" name="unite" class="form-control"
                 value="<?= h($editing['unite'] ?? 'unité') ?>"
                 placeholder="unité, ampoule, flacon, comprimé…">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Stock minimum (alerte si ≤)</label>
          <input type="number" name="quantite_min" class="form-control" step="0.01" min="0"
                 value="<?= $editing['quantite_min'] ?? '0' ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Alerter X jours avant péremption</label>
          <input type="number" name="alerte_jours" class="form-control" min="1" max="365"
                 value="<?= $editing['alerte_jours'] ?? '30' ?>">
        </div>
      </div>
      <?php endif; ?>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Date de péremption *</label>
          <input type="date" name="date_peremption" class="form-control"
                 value="<?= $editing['date_peremption'] ?? '' ?>" required>
        </div>
        <?php if (currentRole() !== 'saisie'): ?>
        <div class="form-group">
          <label class="form-label">Température de stockage (°C)</label>
          <div style="display:flex;gap:8px">
            <input type="number" name="temp_stockage_min" class="form-control" step="0.1" placeholder="Min"
                   value="<?= $editing['temp_stockage_min'] ?? '' ?>" style="width:50%">
            <input type="number" name="temp_stockage_max" class="form-control" step="0.1" placeholder="Max"
                   value="<?= $editing['temp_stockage_max'] ?? '' ?>" style="width:50%">
          </div>
        </div>
        <?php endif; ?>
      </div>

      <?php if (currentRole() !== 'saisie'): ?>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label" style="display:flex;align-items:center;gap:8px">
            <input type="checkbox" name="chaine_froid" value="1"
                   <?= ($editing['chaine_froid'] ?? 0) ? 'checked' : '' ?>
                   style="accent-color:var(--primary)">
            ❄️ Nécessite la chaîne du froid (2-8°C)
          </label>
        </div>
        <div class="form-group">
          <label class="form-label">Notes</label>
          <input type="text" name="notes" class="form-control"
                 value="<?= h($editing['notes'] ?? '') ?>"
                 placeholder="Informations complémentaires…">
        </div>
      </div>
      <?php endif; ?>

      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <button type="submit" class="btn btn-primary">💾 Enregistrer</button>
        <a href="products.php<?= $filterEmpl ? "?emplacement=$filterEmpl" : '' ?>" class="btn btn-ghost">Annuler</a>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php
// Compute KPI counts
$nbExpired  = count(array_filter($products, fn($p) => (int)$p['days_left'] < 0));
$nbCritical = count(array_filter($products, fn($p) => (int)$p['days_left'] >= 0 && (int)$p['days_left'] <= 7));
$nbWarning  = count(array_filter($products, fn($p) => (int)$p['days_left'] > 7  && (int)$p['days_left'] <= 30));
$nbOk       = count(array_filter($products, fn($p) => (int)$p['days_left'] > 30));
?>

<!-- KPI STRIP -->
<div class="kpi-strip">
  <a href="products.php<?= $filterEmpl?"?emplacement=$filterEmpl":'' ?>" class="kpi-card kpi-muted" style="text-decoration:none">
    <div class="kpi-icon">💊</div>
    <div class="kpi-value"><?= $total ?></div>
    <div class="kpi-label">Total produits</div>
  </a>
  <a href="products.php?status=expired<?= $filterEmpl?"&emplacement=$filterEmpl":'' ?>" class="kpi-card <?= $nbExpired>0?'kpi-danger':'kpi-muted' ?>" style="text-decoration:none">
    <div class="kpi-icon">💀</div>
    <div class="kpi-value"><?= $nbExpired ?></div>
    <div class="kpi-label">Périmés</div>
  </a>
  <a href="products.php?status=critical<?= $filterEmpl?"&emplacement=$filterEmpl":'' ?>" class="kpi-card <?= $nbCritical>0?'kpi-warn':'kpi-muted' ?>" style="text-decoration:none">
    <div class="kpi-icon">⚠️</div>
    <div class="kpi-value"><?= $nbCritical ?></div>
    <div class="kpi-label">Critiques (&lt;7j)</div>
  </a>
  <a href="products.php?status=ok<?= $filterEmpl?"&emplacement=$filterEmpl":'' ?>" class="kpi-card kpi-ok" style="text-decoration:none">
    <div class="kpi-icon">✅</div>
    <div class="kpi-value"><?= $nbOk ?></div>
    <div class="kpi-label">En règle</div>
  </a>
</div>

<!-- FILTRES -->
<div class="filters-wrap">
  <div class="filter-view-toggle">
    <a href="?<?= http_build_query(array_merge($_GET,['view'=>'list'])) ?>"
       style="background:<?= $viewMode!=='grouped'?'var(--primary)':'transparent' ?>;color:<?= $viewMode!=='grouped'?'#fff':'var(--text2)' ?>">
      <i class="ti ti-list" style="font-size:13px"></i> Liste
    </a>
    <a href="?<?= http_build_query(array_merge($_GET,['view'=>'grouped'])) ?>"
       style="background:<?= $viewMode==='grouped'?'var(--primary)':'transparent' ?>;color:<?= $viewMode==='grouped'?'#fff':'var(--text2)' ?>">
      <i class="ti ti-stack-2" style="font-size:13px"></i> Par produit
    </a>
  </div>
  <div class="hz-select">
    <span class="hz-lbl">⏱️ Horizon</span>
    <a href="<?= pf_url(['horizon'=>'1'])  ?>" class="hz-chip <?= $horizon==='1' ?'active':'' ?>">1 mois</a>
    <a href="<?= pf_url(['horizon'=>'2'])  ?>" class="hz-chip <?= $horizon==='2' ?'active':'' ?>">2 mois</a>
    <a href="<?= pf_url(['horizon'=>'3'])  ?>" class="hz-chip <?= $horizon==='3' ?'active':'' ?>">3 mois</a>
    <a href="<?= pf_url(['horizon'=>'6'])  ?>" class="hz-chip <?= $horizon==='6' ?'active':'' ?>">6 mois</a>
    <a href="<?= pf_url(['horizon'=>'12']) ?>" class="hz-chip <?= $horizon==='12'?'active':'' ?>">12 mois</a>
    <a href="<?= pf_url(['horizon'=>'all'])?>" class="hz-chip <?= $horizon==='all'?'active':'' ?>">📦 Tout le stock</a>
  </div>
  <a href="<?= pf_url(['status'=>'']) ?>"         class="filter-chip <?= !$filterStatus?'active':'' ?>">Tous (<?= count($products) ?>)</a>
  <a href="<?= pf_url(['status'=>'expired']) ?>"  class="filter-chip chip-danger <?= $filterStatus==='expired' ?'active':'' ?>">💀 Périmés</a>
  <a href="<?= pf_url(['status'=>'critical']) ?>" class="filter-chip chip-warn   <?= $filterStatus==='critical'?'active':'' ?>">⚠️ Critiques</a>
  <a href="<?= pf_url(['status'=>'warning']) ?>"  class="filter-chip chip-warn   <?= $filterStatus==='warning' ?'active':'' ?>">🟡 Avertissement</a>
  <a href="<?= pf_url(['status'=>'ok']) ?>"       class="filter-chip chip-ok     <?= $filterStatus==='ok'      ?'active':'' ?>">✅ OK</a>
  <?php if ($totalCold > 0): ?>
  <a href="<?= pf_url(['status'=>'cold']) ?>" class="filter-chip <?= $filterStatus==='cold'?'active':'' ?>">❄️ Froid (<?= $totalCold ?>)</a>
  <?php endif; ?>
  <?php if ($totalLowStock > 0): ?>
  <a href="<?= pf_url(['status'=>'lowstock']) ?>" class="filter-chip chip-warn <?= $filterStatus==='lowstock'?'active':'' ?>">📦 Stock bas (<?= $totalLowStock ?>)</a>
  <?php endif; ?>
  <?php if ($nbNocode > 0 || $filterStatus==='nocode'): ?>
  <a href="<?= pf_url(['status'=>'nocode','horizon'=>'all']) ?>" class="filter-chip <?= $filterStatus==='nocode'?'active':'' ?>">📷 Sans code (<?= $nbNocode ?>)</a>
  <?php endif; ?>
</div>

<?php if ($viewMode === 'grouped'): ?>
<!-- VUE GROUPÉE ─────────────────────────────────────────-->
<?php
$grouped = [];
foreach ($products as $p) { $grouped[$p['nom']][] = $p; }
ksort($grouped);
if (empty($grouped)): ?>
<div class="card" style="text-align:center;padding:40px;color:var(--text2)">
  <div style="font-size:36px;margin-bottom:10px">💊</div> Aucun produit.
</div>
<?php else: ?>
<div style="display:flex;flex-direction:column;gap:10px">
<?php foreach ($grouped as $nom => $items):
  $has_exp  = array_filter($items, fn($p)=>expiryStatus($p['date_peremption'])==='expired');
  $has_crit = array_filter($items, fn($p)=>expiryStatus($p['date_peremption'])==='critical');
  $has_warn = array_filter($items, fn($p)=>expiryStatus($p['date_peremption'])==='warning');
  $worst  = $has_exp?'expired':($has_crit?'critical':($has_warn?'warning':'ok'));
  $bcolor = match($worst){'expired'=>'#6b7280','critical'=>'#EF4444','warning'=>'#F59E0B',default=>'var(--primary)'};
  $first  = $items[0];
  $key    = md5($nom);
?>
<div class="card grp-card" style="border-left:3px solid <?= $bcolor ?>;padding:0;overflow:hidden">
  <div class="grp-header" onclick="toggleGrp('<?= $key ?>')">
    <div style="display:flex;align-items:center;gap:10px;flex:1;min-width:0">
      <i class="ti ti-pill" style="font-size:16px;color:<?= $bcolor ?>;flex-shrink:0"></i>
      <div>
        <div style="font-size:14px;font-weight:600"><?= h($nom) ?></div>
        <div style="font-size:11px;color:var(--text3);margin-top:2px;display:flex;gap:8px;flex-wrap:wrap">
          <span><?= count($items) ?> stock<?= count($items)>1?'s':'' ?></span>
          <?php if($first['unite']):?><span>· <?= h($first['unite']) ?></span><?php endif;?>
          <?php if($first['chaine_froid']):?><span style="color:#60a5fa">· ❄️ Chaîne du froid</span><?php endif;?>
          <?php if($has_exp):?><span style="color:#9CA3AF">· <?=count($has_exp)?> périmé<?=count($has_exp)>1?'s':''?></span><?php endif;?>
          <?php if($has_crit):?><span style="color:#EF4444">· <?=count($has_crit)?> critique<?=count($has_crit)>1?'s':''?></span><?php endif;?>
          <?php if($has_warn):?><span style="color:#F59E0B">· <?=count($has_warn)?> avert.</span><?php endif;?>
        </div>
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:8px;flex-shrink:0">
      <?php if(canEdit()):?>
      <a href="products.php?edit=<?=$first['id']?>" class="btn btn-ghost btn-xs" onclick="event.stopPropagation()">✏️</a>
      <?php endif;?>
      <i class="ti ti-chevron-down grp-chev" id="chev-<?=$key?>" style="font-size:14px;color:var(--text3);transition:transform .2s"></i>
    </div>
  </div>
  <div class="grp-body" id="grp-<?=$key?>" style="display:none;border-top:1px solid var(--border)">
    <table style="width:100%;border-collapse:collapse">
      <thead><tr style="background:var(--bg3)">
        <th class="grp-th">Emplacement</th>
        <th class="grp-th">Lot</th>
        <th class="grp-th" style="text-align:center">Qté</th>
        <th class="grp-th">Péremption</th>
        <th class="grp-th">Statut</th>
        <?php if(canEdit()):?><th class="grp-th"></th><?php endif;?>
      </tr></thead>
      <tbody>
      <?php foreach($items as $p):
        $days=$p['days_left']; $st=expiryStatus($p['date_peremption']);
        $bc=match($st){'expired'=>'badge-expired','critical'=>'badge-critical','warning'=>'badge-warning',default=>'badge-ok'};
        $lbl=match(true){$days<0=>"Périmé J+".abs($days),$days==0=>"Aujourd'hui",$days==1=>"Demain",default=>"J-$days"};
        $isLow=$p['quantite_min']>0&&$p['quantite']<=$p['quantite_min'];
        $rbg=match($st){'expired'=>'rgba(107,114,128,.03)','critical'=>'rgba(239,68,68,.03)','warning'=>'rgba(245,158,11,.02)',default=>''};
      ?>
      <tr style="border-top:1px solid var(--border);<?=$rbg?>">
        <td class="grp-td">
          <?php if($p['loc_parent_name']):?><div style="font-size:10px;color:var(--text3)"><?=h($p['loc_parent_name'])?></div><?php endif;?>
          <div style="font-size:13px;font-weight:500"><?=h($p['loc_name'])?></div>
        </td>
        <td class="grp-td" style="font-family:var(--mono);font-size:11px;color:var(--text2)"><?=h($p['numero_lot']?:'—')?></td>
        <td class="grp-td" style="text-align:center;font-family:var(--mono)"><?=$p['quantite']?><?php if($isLow):?><span style="color:#fbbf24;font-size:10px"> ⚠</span><?php endif;?></td>
        <td class="grp-td" style="font-family:var(--mono);font-weight:600"><?=date('d/m/Y',strtotime($p['date_peremption']))?></td>
        <td class="grp-td"><span class="badge <?=$bc?>"><?=$lbl?></span></td>
        <?php if(canEdit()):?>
        <td class="grp-td">
          <div style="display:flex;gap:4px">
            <?php if($canMod): ?><a href="products.php?edit=<?=$p['id']?>" class="btn btn-ghost btn-xs" title="Modifier">✏️</a><?php endif; ?>
            <?php if($canRenew): ?><button type="button" class="btn btn-ghost btn-xs" title="Renouveler (périmé)" data-rn="<?= htmlspecialchars(json_encode(['id'=>$p['id'],'nom'=>$p['nom'],'lot'=>$p['numero_lot'],'date'=>$p['date_peremption'],'qte'=>$p['quantite'],'unite'=>$p['unite']]),ENT_QUOTES) ?>" onclick="openRenew(this)">♻️</button><?php endif; ?>
            <?php if($canDup): ?><button class="btn btn-ghost btn-xs" title="Dupliquer" onclick="dupliquer(<?=$p['id']?>)">📋</button><?php endif; ?>
            <?php if($canArch):?>
            <a href="products.php?archive=<?=$p['id']?>&csrf_token=<?=csrfToken()?>" class="btn btn-ghost btn-xs" data-confirm="Archiver ?">📦</a>
            <?php endif;?>
            <?php if($canDel):?>
            <button type="button" class="btn btn-danger btn-xs" title="Supprimer" data-del-url="products.php?suppr=<?=$p['id']?>&csrf_token=<?=csrfToken()?>" data-del-nom="<?= h($p['nom']) ?>" onclick="openDelete(this)">🗑️</button>
            <?php endif;?>
          </div>
        </td>
        <?php endif;?>
      </tr>
      <?php endforeach;?>
      </tbody>
    </table>
  </div>
</div>
<?php endforeach;?>
</div>
<?php endif;?>

<?php else: ?>
<!-- TABLEAU LISTE ───────────────────────────────────────-->
<div class="products-wrap">
  <div class="products-header">
    <div class="products-header-title">
      <i class="ti ti-pill" aria-hidden="true" style="color:var(--primary)"></i>
      Inventaire
      <span class="products-count"><?= count($products) ?> produit<?= count($products)>1?'s':'' ?></span>
    </div>
  </div>

  <?php if (empty($products)): ?>
  <div class="empty-state">
    <div class="empty-state-icon">💊</div>
    <div class="empty-state-text">Aucun produit dans cet emplacement.</div>
    <?php if (canEdit()): ?>
    <button class="btn btn-ghost btn-sm" style="margin-top:4px" onclick="toggleForm()">+ En ajouter un</button>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="products-table">
      <thead>
        <tr>
          <?= psort_th('nom','Produit') ?>
          <?= psort_th('lot','Lot / Réf','hide-mobile') ?>
          <?= psort_th('emplacement','Emplacement') ?>
          <?= psort_th('qte','Qté') ?>
          <?= psort_th('peremption','Péremption') ?>
          <?= psort_th('statut','Statut') ?>
          <?php if (canEdit()): ?><th></th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($products as $p):
        $days   = (int)$p['days_left'];
        $status = expiryStatus($p['date_peremption']);
        $bc     = match($status) { 'expired'=>'badge-expired','critical'=>'badge-critical','warning'=>'badge-warning', default=>'badge-ok' };
        $label  = match(true) { $days<0 => "Périmé (J+".abs($days).")", $days===0 => "Aujourd'hui !", $days===1 => "Demain !", default => "J-$days" };
        $rowClass = match($status) { 'expired'=>'row-expired','critical'=>'row-critical','warning'=>'row-warning', default=>'' };
        $isLow  = $p['quantite_min'] > 0 && $p['quantite'] <= $p['quantite_min'];
      ?>
        <tr class="<?= $rowClass ?>">
          <td>
            <div class="prod-name"><?= h($p['nom']) ?></div>
            <div class="prod-tags">
              <?php if ($p['chaine_froid']): ?><span class="prod-tag tag-cold">❄️ Froid</span><?php endif; ?>
              <?php if ($isLow): ?><span class="prod-tag tag-low">📦 Stock bas</span><?php endif; ?>
            </div>
            <?php if ($p['notes']): ?><div class="prod-note"><?= h($p['notes']) ?></div><?php endif; ?>
          </td>
          <td class="hide-mobile">
            <div class="lot-val"><?= h($p['numero_lot'] ?: '—') ?></div>
            <?php if($p['reference']): ?><div style="font-size:10px;color:var(--text3)"><?= h($p['reference']) ?></div><?php endif; ?>
          </td>
          <td>
            <?php if ($p['loc_parent_name']): ?><div class="loc-parent"><?= h($p['loc_parent_name']) ?></div><?php endif; ?>
            <div class="loc-name"><?= h($p['loc_name']) ?></div>
          </td>
          <td>
            <div class="qty-val"><?= $p['quantite'] ?> <span class="qty-unit"><?= h($p['unite']) ?></span></div>
            <?php if ($p['quantite_min'] > 0): ?><div class="qty-min">min: <?= $p['quantite_min'] ?></div><?php endif; ?>
          </td>
          <td><div class="date-val"><?= date('d/m/Y', strtotime($p['date_peremption'])) ?></div></td>
          <td><?php
            $pillClass = match($status) { 'expired'=>'pill-expired','critical'=>'pill-critical','warning'=>'pill-warning', default=>'pill-ok' };
            $pillDot   = match($status) { 'expired'=>'⚫','critical'=>'🔴','warning'=>'🟡', default=>'🟢' };
          ?><span class="status-pill <?= $pillClass ?>"><?= $pillDot ?> <?= $label ?></span></td>
          <?php if (canEdit()): ?>
          <td>
            <div style="display:flex;gap:4px">
              <?php if($canMod): ?><a href="products.php?edit=<?= $p['id'] ?>" class="btn btn-ghost btn-xs" title="Modifier">✏️</a><?php endif; ?>
              <?php if($canRenew): ?><button type="button" class="btn btn-ghost btn-xs" title="Renouveler (périmé)" data-rn="<?= htmlspecialchars(json_encode(['id'=>$p['id'],'nom'=>$p['nom'],'lot'=>$p['numero_lot'],'date'=>$p['date_peremption'],'qte'=>$p['quantite'],'unite'=>$p['unite']]),ENT_QUOTES) ?>" onclick="openRenew(this)">♻️</button><?php endif; ?>
              <?php if($canDup): ?><button class="btn btn-ghost btn-xs" title="Dupliquer" onclick="dupliquer(<?= $p['id'] ?>)">📋</button><?php endif; ?>
              <?php if ($canArch): ?>
              <a href="products.php?archive=<?= $p['id'] ?>&csrf_token=<?= csrfToken() ?><?= $filterEmpl?"&emplacement=$filterEmpl":'' ?>"
                 class="btn btn-ghost btn-xs" title="Archiver"
                 data-confirm="Archiver « <?= h($p['nom']) ?> » ?">📦</a>
              <?php endif; ?>
              <?php if ($canDel): ?>
              <button type="button" class="btn btn-danger btn-xs" title="Supprimer définitivement"
                 data-del-url="products.php?suppr=<?= $p['id'] ?>&csrf_token=<?= csrfToken() ?><?= $filterEmpl?"&emplacement=$filterEmpl":'' ?>"
                 data-del-nom="<?= h($p['nom']) ?>" onclick="openDelete(this)">🗑️</button>
              <?php endif; ?>
            </div>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- CARDS MOBILE -->
  <?php if (!empty($products)): ?>
  <div class="mobile-cards">
  <?php foreach ($products as $p):
    $days   = (int)$p['days_left'];
    $status = expiryStatus($p['date_peremption']);
    $bc     = match($status){'expired'=>'badge-expired','critical'=>'badge-critical','warning'=>'badge-warning',default=>'badge-ok'};
    $mcls   = match($status){'expired'=>'mc-expired','critical'=>'mc-critical','warning'=>'mc-warning',default=>'mc-ok'};
    $lbl    = match(true){$days<0=>"Périmé J+".abs($days),$days===0=>"Aujourd'hui !",$days===1=>"Demain !",default=>"J-$days"};
    $isLow  = $p['quantite_min']>0 && $p['quantite']<=$p['quantite_min'];
  ?>
  <div class="mobile-card <?= $mcls ?>">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;margin-bottom:6px">
      <div class="mobile-card-name"><?= h($p['nom']) ?></div>
      <span class="badge <?= $bc ?>" style="flex-shrink:0"><?= $lbl ?></span>
    </div>
    <div style="display:flex;gap:4px;flex-wrap:wrap;margin-bottom:8px">
      <?php if($p['chaine_froid']):?><span class="badge badge-cold" style="font-size:10px">❄️ Froid</span><?php endif;?>
      <?php if($isLow):?><span class="badge badge-stock" style="font-size:10px">📦 Stock bas</span><?php endif;?>
    </div>
    <div class="mobile-card-info">
      <div><div class="mcf-label">Emplacement</div>
        <div style="font-size:12px;color:var(--text);font-weight:600">
          <?php if($p['loc_parent_name']):?><span style="color:var(--text3);font-size:10px"><?=h($p['loc_parent_name'])?> ›</span><br><?php endif;?>
          <?=h($p['loc_name'])?>
        </div>
      </div>
      <div><div class="mcf-label">Péremption</div><div class="mcf-val"><?=date('d/m/Y',strtotime($p['date_peremption']))?></div></div>
      <div><div class="mcf-label">Quantité</div><div class="mcf-val"><?=$p['quantite']?> <span style="color:var(--text3);font-size:11px;font-weight:400"><?=h($p['unite'])?></span></div></div>
      <div><div class="mcf-label">N° Lot</div><div class="mcf-val" style="font-size:11px"><?=h($p['numero_lot']?:'—')?></div></div>
    </div>
    <?php if(canEdit()):?>
    <div class="mobile-card-actions">
      <?php if($canMod): ?><a href="products.php?edit=<?=$p['id']?>" class="btn btn-ghost btn-xs" style="flex:1;justify-content:center">✏️ Modifier</a><?php endif; ?>
      <?php if($canRenew): ?><button type="button" class="btn btn-ghost btn-xs" style="flex:1;justify-content:center" title="Renouveler" data-rn="<?= htmlspecialchars(json_encode(['id'=>$p['id'],'nom'=>$p['nom'],'lot'=>$p['numero_lot'],'date'=>$p['date_peremption'],'qte'=>$p['quantite'],'unite'=>$p['unite']]),ENT_QUOTES) ?>" onclick="openRenew(this)">♻️ Renouveler</button><?php endif; ?>
      <?php if($canDup): ?><button class="btn btn-ghost btn-xs" onclick="dupliquer(<?=$p['id']?>)" style="flex:1;justify-content:center">📋 Dupliquer</button><?php endif; ?>
      <?php if($canDel):?>
      <button type="button" class="btn btn-danger btn-xs" title="Supprimer" data-del-url="products.php?suppr=<?=$p['id']?>&csrf_token=<?=csrfToken()?>" data-del-nom="<?= h($p['nom']) ?>" onclick="openDelete(this)">🗑️</button>
      <?php endif;?>
    </div>
    <?php endif;?>
  </div>
  <?php endforeach;?>
  </div>
  <?php endif;?>
</div>

<?php endif; // end viewMode ?>

<style>
/* ── Autocomplétion ── */
#ac-list {
  display: none;
  position: absolute;
  top: 100%; left: 0; right: 0;
  z-index: 999;
  background: var(--bg2);
  border: 1px solid var(--border);
  border-top: none;
  border-radius: 0 0 8px 8px;
  max-height: 220px;
  overflow-y: auto;
  box-shadow: 0 8px 24px rgba(0,0,0,.35);
}
.ac-item {
  padding: 10px 14px;
  cursor: pointer;
  border-bottom: 1px solid var(--border);
  transition: background .1s;
}
.ac-item:last-child { border-bottom: none; }
.ac-item:hover, .ac-item.active { background: var(--primary-bg); }
.ac-name { font-size: 13px; font-weight: 600; color: var(--text); }
.ac-meta { font-size: 11px; color: var(--text3); margin-top: 2px; display: flex; gap: 8px; }

/* ── Vue groupée ── */
.grp-card { transition: box-shadow .15s; }
.grp-card:hover { box-shadow: 0 2px 12px rgba(0,0,0,.15); }
.grp-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 16px;
  cursor: pointer;
  user-select: none;
  transition: background .1s;
}
.grp-header:hover { background: var(--glass); }
.grp-th {
  padding: 6px 12px;
  font-size: 10px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--text3);
  text-align: left;
}
.grp-td { padding: 8px 12px; }
</style>

<script>
// ══════════════════════════════════════
// SAISIE RAPIDE
// ══════════════════════════════════════
var srRowCount = 0;

function srAddRow() {
  const tbody = document.getElementById('sr-tbody');
  if (!tbody) return;
  const i = srRowCount++;
  const tr = document.createElement('tr');
  tr.id = 'sr-row-' + i;

  // Construire options emplacements
  let emplOpts = '<option value="">— Emplacement —</option>';
  (window.srEmpls || []).forEach(function(e) {
    emplOpts += '<option value="' + e.id + '">' + escHtml(e.nom) + '</option>';
  });

  // Construire options unités
  const unites = ['ampoule','flacon','comprimé','gélule','seringue','poche','unité','sachet','tube','kit'];
  let uniteOpts = unites.map(function(u) { return '<option value="' + u + '">' + u + '</option>'; }).join('');

  tr.innerHTML =
    '<td style="padding:4px 6px"><div style="position:relative">' +
      '<input type="text" class="sr-input" placeholder="Nom médicament" style="width:100%" ' +
             'oninput="srAcNom(this,'+i+')" autocomplete="off">' +
      '<div id="sr-ac-'+i+'" style="display:none;position:absolute;top:100%;left:0;right:0;background:var(--bg2);border:1px solid var(--border);border-radius:6px;z-index:999;max-height:160px;overflow-y:auto"></div>' +
    '</div></td>' +
    '<td style="padding:4px 6px"><select class="sr-input" style="width:100%">' + emplOpts + '</select></td>' +
    '<td style="padding:4px 6px"><input type="text" class="sr-input" placeholder="N° lot" style="width:100%"></td>' +
    '<td style="padding:4px 6px"><input type="date" class="sr-input" style="width:100%"></td>' +
    '<td style="padding:4px 6px;text-align:center"><input type="number" class="sr-input" value="1" min="1" style="width:55px;text-align:center"></td>' +
    '<td style="padding:4px 6px"><select class="sr-input">' + uniteOpts + '</select></td>' +
    '<td style="padding:4px 6px;text-align:center"><input type="checkbox" style="accent-color:var(--primary);width:16px;height:16px"></td>' +
    '<td style="padding:4px 6px"><input type="text" class="sr-input" placeholder="Notes" style="width:100%"></td>' +
    '<td style="padding:4px 6px;text-align:center">' +
      '<button type="button" onclick="srRemoveRow('+i+')" style="background:none;border:none;cursor:pointer;color:var(--text3);font-size:16px" title="Supprimer">✕</button>' +
    '</td>';

  tbody.appendChild(tr);
}

function srRemoveRow(i) {
  const row = document.getElementById('sr-row-' + i);
  if (row) row.remove();
}

function srClear() {
  const tbody = document.getElementById('sr-tbody');
  if (tbody) tbody.innerHTML = '';
  srRowCount = 0;
  const st = document.getElementById('sr-status');
  if (st) st.textContent = '';
}

var srAcTimer = null;
function srAcNom(input, i) {
  clearTimeout(srAcTimer);
  const q = input.value.trim();
  const list = document.getElementById('sr-ac-' + i);
  if (!list) return;
  if (q.length < 2) { list.style.display = 'none'; return; }
  srAcTimer = setTimeout(function() {
    fetch('products.php?ajax=autocomplete&q=' + encodeURIComponent(q))
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (!data.length) { list.style.display = 'none'; return; }
        list.innerHTML = data.map(function(d) {
          var dosageHtml = d.dosage ? '<span style="color:var(--text3);font-size:11px;margin-left:6px">' + escHtml(d.dosage) + '</span>' : '';
          return '<div style="padding:7px 10px;cursor:pointer;font-size:13px;border-bottom:1px solid var(--border)">' + escHtml(d.nom) + dosageHtml + '</div>';
        }).join('');
        Array.from(list.children).forEach(function(el, idx) {
          el.addEventListener('mousedown', function() { srAcSelect(i, data[idx].nom); });
        });
        list.style.display = 'block';
      });
  }, 200);
}

function srAcSelect(i, nom) {
  const row = document.getElementById('sr-row-' + i);
  if (!row) return;
  const input = row.querySelector('input[type="text"]');
  const list  = document.getElementById('sr-ac-' + i);
  if (input) input.value = nom;
  if (list)  list.style.display = 'none';
}

function srSubmit() {
  const tbody = document.getElementById('sr-tbody');
  if (!tbody) return;
  const rows  = Array.from(tbody.querySelectorAll('tr'));
  const items = [];

  rows.forEach(function(row) {
    const inputs = row.querySelectorAll('input, select');
    const nom    = inputs[0] ? inputs[0].value.trim() : '';
    const empl   = inputs[1] ? inputs[1].value : '';
    const lot    = inputs[2] ? inputs[2].value.trim() : '';
    const date   = inputs[3] ? inputs[3].value : '';
    const qte    = inputs[4] ? inputs[4].value : '1';
    const unite  = inputs[5] ? inputs[5].value : 'unité';
    const froid  = inputs[6] ? (inputs[6].checked ? 1 : 0) : 0;
    const notes  = inputs[7] ? inputs[7].value.trim() : '';
    if (nom && empl && date) {
      items.push({ nom, emplacement_id: empl, numero_lot: lot, date_peremption: date, quantite: qte, unite, chaine_froid: froid, notes });
    }
  });

  if (!items.length) {
    alert('Aucune ligne valide. Remplissez au moins Nom, Emplacement et Date de péremption.');
    return;
  }

  const btn = document.getElementById('sr-submit-btn');
  const st  = document.getElementById('sr-status');
  if (btn) btn.disabled = true;
  if (st)  st.textContent = 'Enregistrement...';

  fetch('products.php?ajax=saisie_rapide', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ lignes: items, csrf_token: document.querySelector('meta[name="csrf"]')?.content || '' })
  })
  .then(function(r) { return r.json(); })
  .then(function(data) {
    if (btn) btn.disabled = false;
    if (data.ok !== undefined) {
      if (st) st.textContent = '✅ ' + data.ok + ' produit(s) enregistré(s)';
      if (data.errors && data.errors.length) {
        if (st) st.textContent += ' · ⚠️ ' + data.errors.length + ' erreur(s)';
      }
      setTimeout(function() { window.location.reload(); }, 1500);
    } else {
      if (st) st.textContent = '⚠️ ' + (data.error || 'Erreur inconnue');
    }
  })
  .catch(function() {
    if (btn) btn.disabled = false;
    if (st) st.textContent = '⚠️ Erreur réseau';
  });
}

// Initialiser les emplacements pour la saisie rapide
document.addEventListener('DOMContentLoaded', function() {
  window.srEmpls = <?= json_encode($empl_for_js) ?>;

  // Boutons header — branchés ici car le HTML est rendu avant ce script
  var btnAjouter = document.getElementById('btn-ajouter');
  if (btnAjouter) btnAjouter.addEventListener('click', toggleForm);

  var btnSR = document.getElementById('btn-saisie-rapide');
  if (btnSR) btnSR.addEventListener('click', toggleSaisieRapide);

  var btnScan = document.getElementById('btn-scan-photo');
  if (btnScan) btnScan.addEventListener('click', toggleScanPhoto);
});

function toggleSaisieRapide() {
  const panel = document.getElementById('saisie-rapide-panel');
  if (!panel) return;
  const open = panel.style.display !== 'none';
  panel.style.display = open ? 'none' : 'block';
  if (!open) panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function toggleForm() {
  const p = document.getElementById('form-panel');
  if (p) { p.style.display = p.style.display === 'none' ? 'block' : 'none'; }
}

// ── Vue groupée accordéon ──────────────────────────────
function toggleGrp(key) {
  const body = document.getElementById('grp-'+key);
  const chev = document.getElementById('chev-'+key);
  if (!body) return;
  const open = body.style.display !== 'none';
  body.style.display = open ? 'none' : 'block';
  if (chev) chev.style.transform = open ? '' : 'rotate(180deg)';
}

// ── Autocomplétion ─────────────────────────────────────
let acTimer = null, acIdx = -1, acData = [];

function acNom(val) {
  clearTimeout(acTimer);
  const list = document.getElementById('ac-list');
  if (!list) return;
  if (val.length < 2) { list.style.display='none'; return; }
  acTimer = setTimeout(async () => {
    const res  = await fetch('products.php?ajax=autocomplete&q=' + encodeURIComponent(val));
    acData = await res.json();
    acIdx  = -1;
    if (!acData.length) { list.style.display='none'; return; }
    list.innerHTML = acData.map((d,i) => `
      <div class="ac-item" data-i="${i}" onmousedown="acSelect(${i})">
        <div style="display:flex;align-items:center;gap:6px">
          <div class="ac-name" style="flex:1">${escHtml(d.nom)}</div>
          ${d.source==='ref' ? '<span style="font-size:9px;padding:1px 5px;border-radius:4px;background:var(--primary-bg);color:var(--primary);flex-shrink:0">REF</span>' : '<span style="font-size:9px;padding:1px 5px;border-radius:4px;background:rgba(16,185,129,.1);color:#10B981;flex-shrink:0">STOCK</span>'}
        </div>
        <div class="ac-meta">
          ${d.dosage ? '<span>'+escHtml(d.dosage)+'</span>' : ''}
          ${d.unite ? '<span>'+escHtml(d.unite)+'</span>' : ''}
          ${d.categorie ? '<span style="color:var(--text3)">'+escHtml(d.categorie)+'</span>' : ''}
          ${d.nb_stocks > 0 ? '<span style="color:#10B981">'+d.nb_stocks+' stock(s)</span>' : ''}
          ${d.chaine_froid == 1 ? '<span style="color:#60a5fa">❄️ Froid</span>' : ''}
        </div>
      </div>`).join('');
    list.style.display = 'block';
  }, 180);
}

function acSelect(i) {
  const d    = acData[i];
  const inp  = document.getElementById('nom-input');
  const list = document.getElementById('ac-list');
  if (!d || !inp) return;
  inp.value = d.nom;
  list.style.display = 'none';
  // Remplir les autres champs automatiquement
  const fields = {
    'unite':             d.unite,
    'alerte_jours':      d.alerte_jours,
    'quantite_min':      d.quantite_min,
    'temp_stockage_min': d.temp_stockage_min,
    'temp_stockage_max': d.temp_stockage_max,
  };
  Object.entries(fields).forEach(([name, val]) => {
    const el = document.querySelector(`[name="${name}"]`);
    if (el && val !== null && val !== undefined && val !== '') el.value = val;
  });
  // Chaîne du froid
  const cf = document.querySelector('[name="chaine_froid"]');
  if (cf) cf.checked = d.chaine_froid == 1;
}

// Navigation clavier dans l'autocomplete
document.addEventListener('keydown', e => {
  const list = document.getElementById('ac-list');
  if (!list || list.style.display === 'none') return;
  const items = list.querySelectorAll('.ac-item');
  if (!items.length) return;
  if (e.key === 'ArrowDown') {
    e.preventDefault();
    acIdx = Math.min(acIdx+1, items.length-1);
    items.forEach((el,i) => el.classList.toggle('active', i===acIdx));
  } else if (e.key === 'ArrowUp') {
    e.preventDefault();
    acIdx = Math.max(acIdx-1, 0);
    items.forEach((el,i) => el.classList.toggle('active', i===acIdx));
  } else if (e.key === 'Enter' && acIdx >= 0) {
    e.preventDefault();
    acSelect(acIdx);
  } else if (e.key === 'Escape') {
    list.style.display = 'none';
  }
});

// Fermer si clic ailleurs
document.addEventListener('click', e => {
  const inp  = document.getElementById('nom-input');
  const list = document.getElementById('ac-list');
  if (inp && list && !inp.contains(e.target) && !list.contains(e.target)) {
    list.style.display = 'none';
  }
});

function escHtml(s) {
  return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ── Duplication produit ────────────────────────────────
async function dupliquer(id) {
  const res  = await fetch('products.php?ajax=get_product&id=' + id);
  const data = await res.json();
  if (data.error) { alert('Produit introuvable.'); return; }

  // Ouvrir le formulaire
  const panel = document.getElementById('form-panel');
  if (panel) panel.style.display = 'block';
  panel.scrollIntoView({ behavior: 'smooth', block: 'start' });

  // Vider l'ID pour forcer une création
  const idField = document.querySelector('input[name="id"]');
  if (idField) idField.value = '';

  // Remplir les champs (sauf lot et date de péremption)
  const map = {
    'nom':               data.nom,
    'reference':         data.reference   || '',
    'emplacement_id':    data.emplacement_id,
    'quantite':          data.quantite,
    'quantite_min':      data.quantite_min,
    'unite':             data.unite        || '',
    'alerte_jours':      data.alerte_jours,
    'temp_stockage_min': data.temp_stockage_min || '',
    'temp_stockage_max': data.temp_stockage_max || '',
    'notes':             data.notes        || '',
  };
  Object.entries(map).forEach(([name, val]) => {
    const el = document.querySelector(`[name="${name}"]`);
    if (el) el.value = val;
  });

  // Chaîne du froid
  const cf = document.querySelector('[name="chaine_froid"]');
  if (cf) cf.checked = data.chaine_froid == 1;

  // Vider lot et date péremption
  const lot  = document.querySelector('[name="numero_lot"]');
  const date = document.querySelector('[name="date_peremption"]');
  if (lot)  lot.value  = '';
  if (date) date.value = '';

  // Focus sur le champ lot
  setTimeout(() => { if (lot) lot.focus(); }, 200);

  // Indicateur visuel
  const title = document.querySelector('#form-panel .card-title');
  if (title) {
    title.textContent = '📋 Dupliquer — ' + data.nom;
    title.style.color = 'var(--primary)';
    setTimeout(() => { title.style.color = ''; }, 3000);
  }
}

// ══════════════════════════════════════
// RECONNAISSANCE PHOTO MÉDICAMENT
// ══════════════════════════════════════
var scanStream = null;

function toggleScanPhoto() {
  var zone = document.getElementById('scan-photo-zone');
  if (!zone) return;
  var open = zone.style.display !== 'none';
  zone.style.display = open ? 'none' : 'block';
  if (open) stopCamera();
}

function closeScanPhoto() {
  var zone = document.getElementById('scan-photo-zone');
  if (zone) zone.style.display = 'none';
  stopCamera();
}

function startCamera() {
  var wrap = document.getElementById('scan-camera-wrap');
  var video = document.getElementById('scan-video');
  if (!wrap || !video) return;
  navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
    .then(function(stream) {
      scanStream = stream;
      video.srcObject = stream;
      wrap.style.display = 'block';
    })
    .catch(function(e) { alert('Caméra non disponible : ' + e.message); });
}

function stopCamera() {
  if (scanStream) {
    scanStream.getTracks().forEach(function(t) { t.stop(); });
    scanStream = null;
  }
  var wrap = document.getElementById('scan-camera-wrap');
  if (wrap) wrap.style.display = 'none';
}

function takePhoto() {
  var video  = document.getElementById('scan-video');
  var canvas = document.getElementById('scan-canvas');
  if (!video || !canvas) return;
  canvas.width  = video.videoWidth;
  canvas.height = video.videoHeight;
  canvas.getContext('2d').drawImage(video, 0, 0);
  var dataUrl = canvas.toDataURL('image/jpeg', 0.8);
  stopCamera();
  analyzePhoto(dataUrl, 'image/jpeg');
}

function uploadPhoto(input) {
  var file = input.files[0];
  if (!file) return;
  var reader = new FileReader();
  reader.onload = function(e) { analyzePhoto(e.target.result, file.type); };
  reader.readAsDataURL(file);
}

function analyzePhoto(dataUrl, mimeType) {
  var loading = document.getElementById('scan-loading');
  var result  = document.getElementById('scan-result');
  if (loading) loading.style.display = 'block';
  if (result)  result.style.display  = 'none';
  var base64 = dataUrl.indexOf(',') >= 0 ? dataUrl.split(',')[1] : dataUrl;
  fetch('products.php?ajax=scan_photo', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'image=' + encodeURIComponent(base64) + '&mime=' + encodeURIComponent(mimeType)
  })
  .then(function(r) { return r.json(); })
  .then(function(data) {
    if (loading) loading.style.display = 'none';
    if (data.error) {
      if (result) { result.style.display='block'; result.innerHTML='<div style="color:#EF4444;font-size:.82rem">⚠️ '+escHtml(data.error)+'</div>'; }
      return;
    }
    applyPhotoData(data.data);
    showPhotoResult(data.data);
  })
  .catch(function() {
    if (loading) loading.style.display = 'none';
    if (result) { result.style.display='block'; result.innerHTML='<div style="color:#EF4444;font-size:.82rem">⚠️ Erreur réseau</div>'; }
  });
}

function applyPhotoData(d) {
  var fields = { 'nom': d.nom, 'numero_lot': d.numero_lot, 'date_peremption': d.date_peremption, 'unite': d.unite };
  Object.keys(fields).forEach(function(k) {
    var el = document.querySelector('[name="' + k + '"]');
    if (el && fields[k]) el.value = fields[k];
  });
  var cf = document.querySelector('[name="chaine_froid"]');
  if (cf && d.chaine_froid) cf.checked = true;
  if (d.temperature_min) { var tmin = document.querySelector('[name="temp_stockage_min"]'); if (tmin) tmin.value = d.temperature_min; }
  if (d.temperature_max) { var tmax = document.querySelector('[name="temp_stockage_max"]'); if (tmax) tmax.value = d.temperature_max; }
}

function showPhotoResult(d) {
  var result = document.getElementById('scan-result');
  if (!result) return;
  var confColor = d.confiance === 'haute' ? '#10B981' : (d.confiance === 'moyenne' ? '#F59E0B' : '#EF4444');
  var html = '<div style="background:var(--bg2);border:1px solid var(--border);border-radius:8px;padding:10px 12px;font-size:.78rem">';
  html += '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">';
  html += '<span style="font-weight:600;color:var(--text)">✅ Champs remplis automatiquement</span>';
  html += '<span style="padding:2px 8px;border-radius:99px;background:'+confColor+'22;color:'+confColor+';font-size:10px">Confiance : '+(d.confiance||'?')+'</span></div>';
  [['Nom',d.nom],['Lot',d.numero_lot],['Péremption',d.date_peremption],['Forme',d.forme],['DCI',d.dci]].forEach(function(item) {
    if (item[1]) html += '<div style="display:flex;gap:6px;margin-bottom:3px"><span style="color:var(--text3);width:70px;flex-shrink:0">'+item[0]+'</span><span style="color:var(--text);font-weight:500">'+escHtml(String(item[1]))+'</span></div>';
  });
  html += '<div style="margin-top:8px;padding-top:8px;border-top:1px solid var(--border);color:var(--text3);font-size:.72rem">V&eacute;rifiez les champs avant d\'enregistrer</div></div>';
  result.innerHTML = html;
  result.style.display = 'block';
}
</script>

<!-- ═══ Modale : Supprimer un produit ═══ -->
<div id="del-modal" class="rn-overlay" onclick="if(event.target===this)closeDelete()">
  <div class="rn-box" style="width:min(420px,100%)">
    <div class="rn-head">🗑️ Supprimer le produit<button type="button" class="rn-x" onclick="closeDelete()">✕</button></div>
    <p style="font-size:14px;color:var(--text2);margin:0 0 20px;line-height:1.5">Voulez-vous vraiment supprimer <b id="del-nom" style="color:var(--text)"></b> ?<br>Cette action est <b>définitive</b>.</p>
    <div class="rn-actions">
      <button type="button" class="btn btn-ghost" onclick="closeDelete()">Annuler</button>
      <a id="del-confirm" href="#" class="btn btn-danger">Supprimer définitivement</a>
    </div>
  </div>
</div>
<!-- ═══ Modale : Renouveler un lot périmé ═══ -->
<div id="renew-modal" class="rn-overlay" onclick="if(event.target===this)closeRenew()">
  <div class="rn-box">
    <div class="rn-head">♻️ Renouveler un lot périmé<button type="button" class="rn-x" onclick="closeRenew()">✕</button></div>
    <form method="POST" action="products.php<?= $filterEmpl ? "?emplacement=$filterEmpl" : '' ?>">
      <?= csrfField() ?>
      <input type="hidden" name="type_form" value="renouveler">
      <input type="hidden" name="produit_id" id="rn-pid">
      <input type="hidden" name="ret" value="<?= (int)($_GET['ret'] ?? 0) ?>">
      <div class="rn-current">
        <div class="rn-nom" id="rn-nom"></div>
        <div class="rn-sub">Lot actuel : <b id="rn-lot"></b> · périme le <b id="rn-date"></b> · <b id="rn-qte"></b> <span id="rn-unite"></span></div>
      </div>
      <div class="rn-field">
        <label>Combien remplaces-tu ?</label>
        <input type="number" name="qte_remplacer" id="rn-qterem" class="form-control" min="1" step="1" required>
        <div class="rn-hint">sur <span id="rn-qtemax"></span> au total — laisse tout pour remplacer le lot entier</div>
      </div>
      <div class="rn-field">
        <label>Nouveau N° de lot</label>
        <input type="text" name="new_lot" id="rn-newlot" class="form-control" placeholder="LOT2025B…">
      </div>
      <div class="rn-field">
        <label>Nouvelle date de péremption</label>
        <input type="date" name="new_date" id="rn-newdate" class="form-control" required>
      </div>
      <div class="rn-actions">
        <button type="button" class="btn btn-ghost" onclick="closeRenew()">Annuler</button>
        <button type="submit" class="btn btn-primary">♻️ Valider le renouvellement</button>
      </div>
    </form>
  </div>
</div>
<style>
.rn-overlay{position:fixed;inset:0;background:rgba(8,12,24,.6);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);display:none;align-items:flex-start;justify-content:center;z-index:9999;padding:40px 16px;overflow-y:auto}
.rn-overlay.open{display:flex}
.rn-box{background:var(--bg2,var(--card,#fff));border:1px solid var(--border);border-radius:18px;width:min(460px,100%);margin:0 auto;max-height:calc(100vh - 80px);overflow-y:auto;padding:22px}
.rn-head{display:flex;align-items:center;font-size:1.05rem;font-weight:800;margin-bottom:16px}
.rn-x{margin-left:auto;background:none;border:none;font-size:20px;cursor:pointer;color:var(--text2)}
.rn-current{background:var(--glass,rgba(0,0,0,.04));border:1px solid var(--border);border-radius:12px;padding:12px 14px;margin-bottom:16px}
.rn-nom{font-weight:700;font-size:1rem}
.rn-sub{font-size:12.5px;color:var(--text2);margin-top:4px}
.rn-field{margin-bottom:14px}
.rn-field label{display:block;font-size:12.5px;font-weight:600;margin-bottom:5px}
.rn-hint{font-size:11.5px;color:var(--text3);margin-top:4px}
.rn-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:8px}
</style>
<script>
function openRenew(btn){
  var d = JSON.parse(btn.dataset.rn);
  document.getElementById('rn-pid').value = d.id;
  document.getElementById('rn-nom').textContent = d.nom;
  document.getElementById('rn-lot').textContent = d.lot || '—';
  document.getElementById('rn-date').textContent = d.date ? d.date.split('-').reverse().join('/') : '—';
  document.getElementById('rn-qte').textContent = d.qte;
  document.getElementById('rn-unite').textContent = d.unite || '';
  document.getElementById('rn-qtemax').textContent = d.qte;
  var q = document.getElementById('rn-qterem'); var qmax = Math.max(1, Math.floor(parseFloat(d.qte)||1)); q.max = qmax; q.value = qmax;
  document.getElementById('rn-newlot').value = '';
  document.getElementById('rn-newdate').value = '';
  document.getElementById('renew-modal').classList.add('open');
}
function closeRenew(){ document.getElementById('renew-modal').classList.remove('open'); }
function openDelete(btn){
  document.getElementById('del-nom').textContent = btn.dataset.delNom || 'ce produit';
  document.getElementById('del-confirm').href = btn.dataset.delUrl;
  document.getElementById('del-modal').classList.add('open');
}
function closeDelete(){ document.getElementById('del-modal').classList.remove('open'); }
<?php if ($renewProduct): ?>
document.addEventListener('DOMContentLoaded', function(){
  openRenew({dataset:{rn: JSON.stringify({
    id:    <?= (int)$renewProduct['id'] ?>,
    nom:   <?= json_encode($renewProduct['nom'], JSON_UNESCAPED_UNICODE) ?>,
    lot:   <?= json_encode($renewProduct['numero_lot'], JSON_UNESCAPED_UNICODE) ?>,
    date:  <?= json_encode($renewProduct['date_peremption']) ?>,
    qte:   <?= json_encode($renewProduct['quantite']) ?>,
    unite: <?= json_encode($renewProduct['unite'], JSON_UNESCAPED_UNICODE) ?>
  })}});
  <?php if ($renewQty): ?>
  var _rq = document.getElementById('rn-qterem');
  if (_rq) { _rq.value = Math.min(<?= (int)$renewQty ?>, parseInt(_rq.max) || <?= (int)$renewQty ?>); }
  <?php endif; ?>
});
<?php endif; ?>
document.addEventListener('keydown', function(e){ if(e.key==='Escape') closeRenew(); });
</script>

<?php require_once 'includes/footer.php'; ?>

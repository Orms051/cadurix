<?php
/**
 * Cadurix v5 — Scanner de rempotage terrain
 * Mobile first — fonctionne avec caméra smartphone
 * Lit : QR Code emplacements + DataMatrix GS1 + EAN13
 *
 * CORRECTIONS v5.4.1 :
 *  - Parser GS1 remplacé par regex (robuste FNC1 / longueur variable)
 *  - EAN_13 / EAN_8 retirés des formats ZXing (évite confusion DataMatrix)
 *  - $code_chiffres défini correctement
 *  - event passé en paramètre (Safari iOS)
 *  - JSON.stringify() pour injection JS
 *  - action 'retire' gérée côté PHP
 *  - CSRF via header X-CSRF-Token
 *  - cURL timeouts 3s / 2s
 *  - Suppression double chargement ZXing + JsBarcode
 *  - parseLocalDate() helper Safari
 */
require_once 'includes/auth.php';
require_once __DIR__ . '/scanner/barcode_lookup.php';

$kiosque = isset($_GET['kiosque']);
requiert_role(ROLE_SAISIE);

$site_id = site_actif();
if (!$site_id) { header('Location: dashboard.php'); exit; }

// ════════════════════════════════════════════════════════════
//  HELPERS
// ════════════════════════════════════════════════════════════


/**
 * Wrapper cURL avec timeouts courts — adapté mobile/terrain
 */
function cadurix_curl_get(string $url): ?string {
    if (!function_exists('curl_init')) return null;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 3,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,   // true en prod — false si Laragon local
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 Cadurix/5.4',
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $r   = curl_exec($ch);
    $err = curl_errno($ch);
    curl_close($ch);
    return ($err === 0 && $r !== false) ? $r : null;
}

// ════════════════════════════════════════════════════════════
//  API JSON
// ════════════════════════════════════════════════════════════
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    $action = $_GET['api'];

    // ── Emplacement par ID (scan QR) ──────────────────────
    if ($action === 'emplacement') {
        $id = (int)($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("
            SELECT e.*, ep.nom AS parent_nom
            FROM emplacements e
            LEFT JOIN emplacements ep ON ep.id = e.parent_id
            WHERE e.id = ? AND e.site_id = ? AND e.actif = 1
        ");
        $stmt->execute([$id, $site_id]);
        $empl = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$empl) {
            echo json_encode(['ok'=>false,'msg'=>'Emplacement non trouvé ou non autorisé']);
            exit;
        }
        $stmt = $pdo->prepare("
            SELECT id, nom, ean, gtin, numero_lot, date_peremption, quantite, unite
            FROM produits WHERE emplacement_id = ? AND statut = 'actif'
            ORDER BY nom
        ");
        $stmt->execute([$id]);
        $produits = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok'=>true, 'emplacement'=>$empl, 'produits'=>$produits]);
        exit;
    }

    // ── Scan article ──────────────────────────────────────
    if ($action === 'scan_article') {
        $code = trim($_GET['code'] ?? '');
        if (!$code) { echo json_encode(['ok'=>false,'msg'=>'Code vide']); exit; }

        $parsed = parse_gs1($code);
        $gtin   = $parsed['gtin'];
        $lot    = $parsed['lot'];
        $date_exp = $parsed['date_exp'];

        // CIP13 = GTIN 14 chiffres sans le premier digit (toujours 0 pour médicaments FR)
        $cip13 = ($gtin && strlen($gtin) === 14) ? substr($gtin, 1) : $gtin;

        // EAN brut de secours (EAN13 pur sans GS1)
        $ean_brut = preg_replace('/[^0-9]/', '', $code);
        if (strlen($ean_brut) > 14) $ean_brut = substr($ean_brut, -13);
        if (!$cip13 && strlen($ean_brut) >= 8) $cip13 = $ean_brut;

        // 1. Chercher dans medicaments_ref par GTIN/CIP13 (comme terrain.php)
        $med_nom = null;
        foreach (array_unique(array_filter([$cip13, $ean_brut, $gtin])) as $ct) {
            if ($med_nom || !$ct) continue;
            $s = $pdo->prepare("SELECT nom FROM medicaments_ref WHERE gtin=? AND actif=1 LIMIT 1");
            $s->execute([$ct]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            if ($r) $med_nom = $r['nom'];
        }
        if ($med_nom) {
            echo json_encode([
                'ok'      => true,
                'trouve'  => true,
                'produit' => ['nom' => $med_nom, 'date_peremption' => $date_exp, 'numero_lot' => $lot],
                'parsed'  => compact('gtin','date_exp','lot'),
                'code_raw'=> $code,
            ]);
            exit;
        }

        // 2. Chercher produit dans stock local par GTIN
        $produit = null;
        if ($gtin) {
            $stmt = $pdo->prepare("
                SELECT p.* FROM produits p
                LEFT JOIN emplacements e ON e.id = p.emplacement_id
                WHERE p.gtin = ? AND p.statut = 'actif' AND (e.site_id = ? OR p.emplacement_id IS NULL)
                LIMIT 1
            ");
            $stmt->execute([$gtin, $site_id]);
            $produit = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        // 3. Sinon par EAN
        if (!$produit) {
            $ean_code = preg_replace('/[^0-9]/', '', $code);
            if (strlen($ean_code) >= 8) {
                $stmt = $pdo->prepare("
                    SELECT p.* FROM produits p
                    LEFT JOIN emplacements e ON e.id = p.emplacement_id
                    WHERE p.ean = ? AND p.statut = 'actif' AND (e.site_id = ? OR p.emplacement_id IS NULL)
                    LIMIT 1
                ");
                $stmt->execute([$ean_code, $site_id]);
                $produit = $stmt->fetch(PDO::FETCH_ASSOC);
            }
        }

        echo json_encode([
            'ok'       => true,
            'trouve'   => (bool)$produit,
            'produit'  => $produit,
            'parsed'   => compact('gtin','date_exp','lot'),
            'code_raw' => $code,
        ]);
        exit;
    }

    // ── Lookup BDPM ANSM ──────────────────────────────────
    if ($action === 'lookup_bdpm') {
        $code_scan = trim($_GET['gtin'] ?? '');
        $lot_param = trim($_GET['lot'] ?? '');
        if (!$code_scan) { echo json_encode(['ok'=>false,'msg'=>'Code manquant']); exit; }

        $parsed        = parse_gs1($code_scan);
        $gtin          = $parsed['gtin'];
        $lot_parsed    = $parsed['lot'] ?: $lot_param;
        $date_exp_parsed = $parsed['date_exp'];

        $cip13 = ($gtin && strlen($gtin) === 14) ? substr($gtin, 1) : $gtin;

        // EAN brut de secours
        $ean_brut = preg_replace('/[^0-9]/', '', $code_scan);
        if (strlen($ean_brut) > 14) $ean_brut = substr($ean_brut, -13);
        if (!$cip13 && strlen($ean_brut) >= 8) $cip13 = $ean_brut;

        // $code_chiffres = version numérique pure pour APIs EAN
        $code_chiffres = preg_replace('/[^0-9]/', '', $code_scan);
        if (strlen($code_chiffres) > 14) $code_chiffres = substr($code_chiffres, -13);

        $nom    = null;
        $forme  = null;
        $dosage = null;
        $source = 'cache';

        // 1. BDD locale medicaments_ref
        $codes = array_unique(array_filter([$cip13, $gtin, $ean_brut, $code_chiffres]));
        foreach ($codes as $c) {
            if ($nom || !$c) continue;
            $stmt = $pdo->prepare("SELECT * FROM medicaments_ref WHERE gtin = ? AND actif=1 LIMIT 1");
            $stmt->execute([$c]);
            $ref = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($ref) {
                $nom    = $ref['nom'];
                $forme  = $ref['forme'];
                $dosage = $ref['dosage'];
            }
        }

        // 2. APIs externes si toujours pas trouvé
        if (!$nom) {
            // Open Food Facts — EAN mondial
            if (strlen($code_chiffres) >= 8) {
                $r = cadurix_curl_get("https://world.openfoodfacts.org/api/v0/product/{$code_chiffres}.json");
                if ($r) {
                    $d = json_decode($r, true);
                    if (($d['status'] ?? 0) === 1) {
                        $n = $d['product']['product_name_fr']
                          ?? $d['product']['product_name']
                          ?? null;
                        if ($n && strlen(trim($n)) > 2) {
                            $nom    = trim($n);
                            $source = 'openfoodfacts';
                        }
                    }
                }
            }

            // BDPM ANSM — CIP13 français
            if (!$nom) {
                foreach ($codes as $code_test) {
                    if ($nom || !$code_test) continue;
                    $r = cadurix_curl_get(
                        "https://base-donnees-publique.medicaments.gouv.fr/affichageDoc.php?specif={$code_test}&typedoc=N"
                    );
                    if ($r && preg_match('/<title>([^<]+)<\/title>/i', $r, $m)) {
                        $t = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
                        $t = preg_replace('/\s*[-]\s*Base de donn.*/iu', '', $t);
                        $t = trim($t);
                        if ($t && strlen($t) > 3 && strlen($t) < 200
                            && stripos($t, 'erreur') === false
                            && stripos($t, '404') === false) {
                            $nom    = $t;
                            $source = 'bdpm';
                        }
                    }
                }
            }

            // UPCitemdb — EAN universel
            if (!$nom && strlen($code_chiffres) >= 8) {
                $r = cadurix_curl_get("https://api.upcitemdb.com/prod/trial/lookup?upc={$code_chiffres}");
                if ($r) {
                    $d = json_decode($r, true);
                    if (!empty($d['items'][0]['title'])) {
                        $nom    = $d['items'][0]['title'];
                        $source = 'upcitemdb';
                    }
                }
            }

            // Sauvegarder en cache local si trouvé
            if ($nom) {
                try {
                    $pdo->prepare("INSERT IGNORE INTO medicaments_ref (nom, dci, forme, dosage, gtin, actif) VALUES (?,?,?,?,?,1)")
                        ->execute([$nom, null, $forme, $dosage, $cip13 ?: $code_chiffres]);
                } catch (Exception $e) {}
            }
        }

        // Vérifier lot rappelé ANSM
        $rappel = null;
        if ($lot_parsed) {
            $stmt = $pdo->prepare("SELECT * FROM lots_rappels WHERE numero_lot = ? LIMIT 1");
            $stmt->execute([$lot_parsed]);
            $rappel = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        echo json_encode([
            'ok'     => true,
            'nom'    => $nom,
            'forme'  => $forme,
            'dosage' => $dosage,
            'source' => $source,
            'rappel' => $rappel,
            'cip13'  => $cip13,
        ]);
        exit;
    }

    // ── Valider rempotage ─────────────────────────────────
    if ($action === 'valider' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // CSRF via header (JSON body incompatible avec $_POST)
        $csrf_header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (function_exists('csrf_check_header')) {
            csrf_check_header($csrf_header);
        } else {
            // Fallback si votre csrf_check() lit $_POST — à adapter selon votre implémentation
            // csrf_check();
        }

        $data      = json_decode(file_get_contents('php://input'), true);
        $empl_id   = (int)($data['emplacement_id'] ?? 0);
        $articles  = $data['articles'] ?? [];
        $signature = null;
        $notes     = trim($data['notes'] ?? '');

        if (!$empl_id || empty($articles)) {
            echo json_encode(['ok'=>false,'msg'=>'Données manquantes']);
            exit;
        }

        try {
            $pdo->beginTransaction();

            $nb_scannes     = 0;
            $nb_crees       = 0;
            $nb_non_scannes = 0;

            $pdo->prepare("
                INSERT INTO rempotages (emplacement_id, user_id, site_id, signature, notes, statut)
                VALUES (?,?,?,?,?,'en_cours')
            ")->execute([$empl_id, currentUserId(), $site_id, $signature, $notes]);
            $rempotage_id = (int)$pdo->lastInsertId();

            foreach ($articles as $art) {
                $action_art = $art['action'] ?? 'scanne';
                $produit_id = null;

                if ($action_art === 'scanne' || $action_art === 'cree') {
                    $nom_a     = trim($art['nom'] ?? '');
                    $date_perm = $art['date_peremption'] ?? null;
                    $lot       = $art['numero_lot'] ?? null;
                    $qte       = max(0.1, (float)($art['quantite'] ?? 1));
                    $unite     = $art['unite'] ?? 'unité';
                    $prod_id   = (int)($art['produit_id'] ?? 0) ?: null;

                    if ($prod_id) {
                        // Produit existant — mise à jour
                        $produit_id = $prod_id;
                        $pdo->prepare("UPDATE produits SET numero_lot=COALESCE(?,numero_lot),date_peremption=COALESCE(?,date_peremption),quantite=quantite+?,emplacement_id=COALESCE(?,emplacement_id),modifie_par=?,modifie_le=NOW() WHERE id=?")
                            ->execute([$lot,$date_perm,$qte,$empl_id,currentUserId(),$prod_id]);
                        $nb_scannes++;
                    } else {
                        // Produit inexistant — création automatique dans le stock
                        $pdo->prepare("INSERT INTO produits (nom,quantite,unite,numero_lot,date_peremption,emplacement_id,statut,cree_par,cree_le) VALUES (?,?,?,?,?,?,'actif',?,NOW())")
                            ->execute([$nom_a,$qte,$unite,$lot,$date_perm,$empl_id,currentUserId()]);
                        $produit_id = (int)$pdo->lastInsertId();
                        $nb_crees++;
                    }

                } elseif ($action_art === 'retire') {
                    // ✅ Marquer le produit comme retiré
                    $produit_id = (int)($art['produit_id'] ?? 0);
                    if ($produit_id) {
                        $pdo->prepare("
                            UPDATE produits
                            SET statut='retire', modifie_par=?, modifie_le=NOW()
                            WHERE id=?
                        ")->execute([currentUserId(), $produit_id]);
                    }

                } elseif ($action_art === 'non_scanne') {
                    $produit_id = (int)($art['produit_id'] ?? 0) ?: null;
                    $nb_non_scannes++;
                }

                // Enregistrer l'article dans rempotage_articles
                $pdo->prepare("
                    INSERT INTO rempotage_articles
                        (rempotage_id, produit_id, action, nom, ean, numero_lot, date_peremption, quantite, unite)
                    VALUES (?,?,?,?,?,?,?,?,?)
                ")->execute([
                    $rempotage_id,
                    $produit_id ?: null, // NULL si pas de produit en stock
                    $action_art,
                    $art['nom'] ?? '',
                    $art['ean'] ?? null,
                    $art['numero_lot'] ?? null,
                    $art['date_peremption'] ?? null,
                    $art['quantite'] ?? 1,
                    $art['unite'] ?? 'unité',
                ]);
            }

            $pdo->prepare("
                UPDATE rempotages
                SET statut='valide', valide_le=NOW(), nb_scannes=?, nb_crees=?, nb_non_scannes=?
                WHERE id=?
            ")->execute([$nb_scannes, $nb_crees, $nb_non_scannes, $rempotage_id]);

            audit_log($pdo, 'REMPOTAGE', 'rempotages', $rempotage_id, null, [
                'emplacement_id' => $empl_id,
                'nb_scannes'     => $nb_scannes,
                'nb_crees'       => $nb_crees,
            ]);

            $pdo->commit();
            echo json_encode(['ok'=>true, 'rempotage_id'=>$rempotage_id]);

        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['ok'=>false, 'msg'=>$e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'Action inconnue']);
    exit;
}

// ════════════════════════════════════════════════════════════
//  EMPLACEMENTS (select)
// ════════════════════════════════════════════════════════════
$stmt = $pdo->prepare("
    SELECT e.id, e.nom, ep.nom AS parent_nom, e.chaine_froid
    FROM emplacements e
    LEFT JOIN emplacements ep ON ep.id = e.parent_id
    WHERE e.site_id = ? AND e.actif = 1
    ORDER BY COALESCE(ep.nom, e.nom), e.nom
");
$stmt->execute([$site_id]);
$tous_empls = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Scanner — Rempotage';
require_once 'includes/header.php';
?>

<!-- JsBarcode (une seule fois) -->
<script src="/assets/js/jsbarcode.all.min.js"></script>
<!-- ZXing 0.21.3 — une seule fois -->
<script src="https://cdn.jsdelivr.net/npm/@zxing/library@0.21.3/umd/index.min.js"></script>
<script src="/scanner/scanner-zxing.js"></script>

<style>
/* ═══════════════════════════════════════════════════════
   SCAN.PHP — Design natif mobile Cadurix
   Propre, moderne, pratique sur terrain
═══════════════════════════════════════════════════════ */
*{-webkit-tap-highlight-color:transparent;box-sizing:border-box}
.main-content,.page-content{padding:0!important}

/* ── Layout ── */
.app-header{
  background:var(--bg2);
  border-bottom:1px solid var(--border);
  padding:10px 16px;
  display:flex;align-items:center;gap:12px;
  flex-shrink:0;
  position:sticky;top:0;z-index:10;
}
.app-logo{
  width:36px;height:36px;border-radius:10px;
  background:linear-gradient(135deg,#3b82f6,#8b5cf6);
  display:flex;align-items:center;justify-content:center;
  font-size:18px;flex-shrink:0;
}
.app-title{font-size:1rem;font-weight:800;flex:1;color:var(--text)}
.app-back{
  color:var(--text2);text-decoration:none;font-size:.82rem;
  padding:7px 12px;border-radius:9px;border:1px solid var(--border);
  background:var(--bg3);font-weight:600;
}
.app-body{flex:1;overflow-y:auto;-webkit-overflow-scrolling:touch;padding-bottom:80px}
.screen{display:none;padding:14px;min-height:100%}
.screen.active{display:block}

/* ── Stepper ── */
.stepper{display:flex;align-items:center;margin-bottom:16px;padding:12px 16px;background:var(--bg2);border:1px solid var(--border);border-radius:14px}
.step{display:flex;align-items:center;gap:7px;flex:1}
.step-dot{
  width:30px;height:30px;border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  font-size:.75rem;font-weight:800;flex-shrink:0;
  border:2px solid var(--border);color:var(--text3);
  background:var(--bg3);transition:all .2s;
}
.step-dot.active{background:var(--primary);border-color:var(--primary);color:#fff;box-shadow:0 0 12px var(--primary-glow)}
.step-dot.done{background:var(--green);border-color:var(--green);color:#fff}
.step-label{font-size:.72rem;color:var(--text3);font-weight:500;line-height:1.2}
.step-label.active{color:var(--text);font-weight:700}
.step-label.done{color:var(--green)}
.step-line{flex:1;height:2px;background:var(--border);margin:0 6px;border-radius:2px}
.step-line.done{background:var(--green)}

/* ── Scanner ── */
.scanner-wrap{
  position:relative;border-radius:16px;overflow:hidden;
  background:#000;width:100%;aspect-ratio:4/3;
  box-shadow:0 8px 32px rgba(0,0,0,.4);
}
#scanner-empl-video,#scanner-article-video{width:100%;height:100%;object-fit:cover;display:block}
.scanner-overlay{position:absolute;inset:0;display:flex;align-items:center;justify-content:center}
.scanner-frame{
  width:72%;height:72%;position:relative;
  box-shadow:0 0 0 9999px rgba(0,0,0,.6);
  border-radius:4px;
}
.scanner-line{
  position:absolute;top:0;left:0;right:0;height:2px;
  background:linear-gradient(90deg,transparent,var(--primary),transparent);
  animation:scan-line 1.8s ease-in-out infinite;
  box-shadow:0 0 12px var(--primary);
}
@keyframes scan-line{0%,100%{top:0;opacity:1}50%{top:calc(100% - 2px)}85%{opacity:1}95%{opacity:0}}
.scanner-corner{position:absolute;width:24px;height:24px}
.sc-tl{top:-1px;left:-1px;border-top:3px solid #fff;border-left:3px solid #fff;border-radius:4px 0 0 0}
.sc-tr{top:-1px;right:-1px;border-top:3px solid #fff;border-right:3px solid #fff;border-radius:0 4px 0 0}
.sc-bl{bottom:-1px;left:-1px;border-bottom:3px solid #fff;border-left:3px solid #fff;border-radius:0 0 0 4px}
.sc-br{bottom:-1px;right:-1px;border-bottom:3px solid #fff;border-right:3px solid #fff;border-radius:0 0 4px 0}
.scanner-badge{
  position:absolute;bottom:10px;left:50%;transform:translateX(-50%);
  background:rgba(0,0,0,.65);backdrop-filter:blur(8px);
  color:#fff;font-size:.72rem;font-weight:600;
  padding:4px 12px;border-radius:20px;white-space:nowrap;
  border:1px solid rgba(255,255,255,.15);
}

/* ── Cards ── */
.card{
  background:var(--bg2);border:1px solid var(--border);
  border-radius:16px;padding:16px;margin-bottom:12px;
}
.card-title{
  font-size:.68rem;text-transform:uppercase;letter-spacing:1.5px;
  color:var(--text3);font-weight:700;margin-bottom:10px;
}
.card-icon-title{
  display:flex;align-items:center;gap:10px;margin-bottom:12px;
}
.card-icon-title .icon{
  width:38px;height:38px;border-radius:10px;
  background:rgba(59,130,246,.12);border:1px solid rgba(59,130,246,.2);
  display:flex;align-items:center;justify-content:center;font-size:18px;
}
.card-icon-title .title{font-size:.95rem;font-weight:800;color:var(--text)}
.card-icon-title .sub{font-size:.78rem;color:var(--text3);margin-top:2px}

/* ── Emplacement info ── */
.empl-card{
  background:linear-gradient(135deg,rgba(59,130,246,.08),rgba(139,92,246,.08));
  border:1px solid rgba(59,130,246,.25);border-radius:14px;
  padding:14px 16px;margin-bottom:12px;
  display:flex;align-items:center;gap:12px;
}
.empl-card-icon{
  width:44px;height:44px;border-radius:12px;
  background:rgba(59,130,246,.15);
  display:flex;align-items:center;justify-content:center;font-size:22px;
  flex-shrink:0;
}
.empl-card-nom{font-size:1rem;font-weight:800;color:var(--text)}
.empl-card-sub{font-size:.78rem;color:var(--text3);margin-top:2px}

/* ── Boutons ── */
.btn{
  display:flex;align-items:center;justify-content:center;gap:8px;
  padding:15px 20px;border-radius:14px;font-size:1rem;font-weight:700;
  cursor:pointer;border:none;font-family:var(--font);
  transition:all .15s;width:100%;text-decoration:none;
  -webkit-tap-highlight-color:transparent;
}
.btn:active{transform:scale(.97)}
.btn-primary{background:var(--primary);color:#fff;box-shadow:0 4px 20px var(--primary-glow)}
.btn-success{background:var(--green);color:#fff;box-shadow:0 4px 16px rgba(16,185,129,.35)}
.btn-danger{background:var(--red);color:#fff}
.btn-ghost{background:var(--bg3);border:1px solid var(--border);color:var(--text)}
.btn-amber{background:#f59e0b;color:#000;font-weight:800}
.btn-sm{padding:10px 16px;font-size:.875rem;border-radius:10px;width:auto}
.btn-row{display:flex;gap:10px;margin-top:12px}
.btn-row .btn{flex:1}

/* ── Select ── */
.select-wrap{position:relative;margin-bottom:10px}
.select-wrap select{
  width:100%;padding:15px 44px 15px 16px;
  background:var(--bg3);border:1px solid var(--border);
  border-radius:13px;color:var(--text);font-family:var(--font);
  font-size:.95rem;-webkit-appearance:none;appearance:none;outline:none;
  font-weight:500;
}
.select-wrap select:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(59,130,246,.15)}
.select-arrow{position:absolute;right:14px;top:50%;transform:translateY(-50%);pointer-events:none;color:var(--text3);font-size:.85rem}

/* ── Input fields ── */
.input-field{
  width:100%;padding:14px 16px;
  background:var(--bg3);border:1px solid var(--border);border-radius:13px;
  color:var(--text);font-family:var(--font);font-size:.95rem;
  outline:none;margin-bottom:10px;
}
.input-field:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(59,130,246,.15)}
.input-date{
  width:100%;padding:14px;background:var(--bg3);border:1px solid var(--border);
  border-radius:13px;color:var(--text);font-family:var(--font);
  font-size:1rem;font-weight:700;text-align:center;
  outline:none;-webkit-appearance:none;
}
.input-date:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(59,130,246,.15)}
.form-label{
  font-size:.7rem;text-transform:uppercase;letter-spacing:.8px;
  color:var(--text3);font-weight:700;margin-bottom:6px;display:block;
}

/* ── Résultat scan — bottom sheet style ── */
.scan-result-card{
  border-radius:16px;padding:16px;margin-bottom:12px;
  animation:slide-up .25s ease;
}
@keyframes slide-up{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
.scan-result-card.found{
  background:rgba(16,185,129,.06);
  border:1.5px solid rgba(16,185,129,.3);
}
.scan-result-card.not-found{
  background:rgba(245,158,11,.06);
  border:1.5px solid rgba(245,158,11,.3);
}
.scan-result-nom{font-size:1rem;font-weight:800;color:var(--text);margin-bottom:10px;line-height:1.3}
.scan-result-pills{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px}
.pill{
  font-size:.75rem;font-weight:700;padding:4px 10px;
  border-radius:20px;display:inline-flex;align-items:center;gap:4px;
}
.pill-lot{background:rgba(139,92,246,.12);color:#a78bfa;border:1px solid rgba(139,92,246,.2)}
.pill-date{background:rgba(16,185,129,.12);color:#34d399;border:1px solid rgba(16,185,129,.2)}
.pill-warn{background:rgba(245,158,11,.12);color:#fcd34d;border:1px solid rgba(245,158,11,.2)}

/* ── Articles liste ── */
.article-item{
  display:flex;align-items:center;gap:10px;
  padding:11px 13px;
  background:var(--bg3);border:1px solid var(--border);
  border-radius:12px;margin-bottom:8px;
  transition:all .15s;
}
.article-icon{
  width:38px;height:38px;border-radius:10px;
  display:flex;align-items:center;justify-content:center;
  font-size:17px;flex-shrink:0;
}
.ai-ok{background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.2)}
.ai-new{background:rgba(59,130,246,.12);border:1px solid rgba(59,130,246,.2)}
.ai-warn{background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.2)}
.article-info{flex:1;min-width:0}
.article-nom{font-size:.875rem;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.article-sub{font-size:.72rem;color:var(--text3);margin-top:3px;display:flex;flex-wrap:wrap;gap:4px}
.article-remove{
  color:var(--text3);font-size:1rem;padding:8px;
  flex-shrink:0;background:rgba(239,68,68,.08);
  border-radius:8px;border:none;cursor:pointer;
}

/* ── Badges ── */
.badge{display:inline-block;padding:.2rem .6rem;border-radius:99px;font-size:.68rem;font-weight:700}
.b-ok{background:rgba(16,185,129,.12);color:#34d399;border:1px solid rgba(16,185,129,.2)}
.b-warn{background:rgba(245,158,11,.12);color:#fcd34d;border:1px solid rgba(245,158,11,.2)}
.b-err{background:rgba(239,68,68,.12);color:#f87171;border:1px solid rgba(239,68,68,.2)}
.b-blue{background:rgba(59,130,246,.12);color:#93c5fd;border:1px solid rgba(59,130,246,.2)}

/* ── Récap stats ── */
.recap-stat{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:16px}
.recap-box{
  background:var(--bg3);border:1px solid var(--border);
  border-radius:14px;padding:14px 10px;text-align:center;
}
.recap-val{font-size:2rem;font-weight:900;line-height:1}
.recap-lbl{font-size:.65rem;color:var(--text3);text-transform:uppercase;letter-spacing:.5px;margin-top:4px}

/* ── Non scannés ── */
.non-scanne{
  display:flex;align-items:center;justify-content:space-between;
  padding:11px 13px;
  background:rgba(245,158,11,.05);border:1px solid rgba(245,158,11,.2);
  border-radius:12px;margin-bottom:8px;
}

/* ── Flash messages ── */
.flash{
  padding:11px 14px;border-radius:12px;font-size:.875rem;
  font-weight:600;margin-bottom:12px;
  display:flex;align-items:center;gap:10px;
}
.flash-ok{background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.25);color:#34d399}
.flash-err{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.25);color:#f87171}
.flash-warn{background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.25);color:#fcd34d}

/* ── Produit found / not found (compat) ── */
.produit-found{background:rgba(16,185,129,.06);border:1px solid rgba(16,185,129,.25);border-radius:14px;padding:14px;margin-bottom:12px}
.produit-not-found{background:rgba(245,158,11,.06);border:1px solid rgba(245,158,11,.25);border-radius:14px;padding:14px;margin-bottom:12px}

/* ── Écran succès ── */
.done-screen{
  display:flex;flex-direction:column;align-items:center;
  justify-content:center;padding:40px 20px;min-height:60vh;
  text-align:center;
}
.done-check{
  width:80px;height:80px;border-radius:50%;
  background:rgba(16,185,129,.15);border:2px solid rgba(16,185,129,.3);
  display:flex;align-items:center;justify-content:center;
  font-size:2.5rem;margin-bottom:20px;
  animation:pop .4s cubic-bezier(.175,.885,.32,1.275);
}
@keyframes pop{from{transform:scale(0)}to{transform:scale(1)}}


/* ═══ Mise en page PC uniquement — mobile inchangé ═══ */
@media (min-width: 1024px) {
  /* Caméra souvent indispo sur PC → on réduit la zone scanner */
  .scanner-wrap { max-width: 420px; margin-inline: auto; }

  /* Écrans simples : centrés, pas étirés */
  #screen-empl, #screen-recap { max-width: 560px; margin-inline: auto; }

  /* Écran de scan : 2 colonnes — scanner à gauche, liste à droite */
  #screen-scan.active {
    display: grid;
    grid-template-columns: minmax(320px, 420px) 1fr;
    gap: 18px 26px;
    align-items: start;
    max-width: 1100px;
    margin-inline: auto;
  }
  #screen-scan .stepper,
  #screen-scan #empl-info-card,
  #screen-scan .btn-row       { grid-column: 1 / -1; }   /* pleine largeur */
  #screen-scan #scan-left     { grid-column: 1; }
  #screen-scan #articles-card {
    grid-column: 2;
    display: block !important;   /* liste toujours visible à droite sur PC */
    align-self: stretch;
    margin-bottom: 0;
  }
  /* Message quand la liste est vide */
  #screen-scan #articles-list:empty::after {
    content: "Aucun article scanné pour le moment.";
    display: block; color: var(--text3);
    font-size: .85rem; padding: 10px 2px;
  }
}


</style>
</head>
<body>

<div style="display:flex;flex-direction:column;height:calc(100vh - 56px)">

<!-- Header -->
<div class="app-header">
  <div class="app-logo">💊</div>
  <div class="app-title" id="header-title">Scanner — Rempotage</div>
  <a href="rempotages.php" class="app-back">Historique</a>
</div>

<div class="app-body">

<!-- ══ ÉCRAN 1 — Sélection emplacement ══ -->
<div class="screen active" id="screen-empl">

  <!-- Scanner -->
  <div style="margin-bottom:12px">
    <div class="scanner-wrap" id="scanner-empl-wrap">
      <video id="scanner-empl-video" autoplay playsinline muted></video>
      <div class="scanner-overlay">
        <div class="scanner-frame">
          <div class="scanner-line"></div>
          <div class="scanner-corner sc-tl"></div>
          <div class="scanner-corner sc-tr"></div>
          <div class="scanner-corner sc-bl"></div>
          <div class="scanner-corner sc-br"></div>
        </div>
      </div>
      <div class="scanner-badge">📷 QR Code emplacement</div>
    </div>
  </div>

  <!-- Résultat scan -->
  <div id="empl-scan-result"></div>

  <!-- Séparateur -->
  <div style="display:flex;align-items:center;gap:10px;margin:4px 0 12px">
    <div style="flex:1;height:1px;background:var(--border)"></div>
    <div style="font-size:.75rem;color:var(--text3);font-weight:600">OU</div>
    <div style="flex:1;height:1px;background:var(--border)"></div>
  </div>

  <!-- Sélection manuelle -->
  <div class="card">
    <div class="card-icon-title">
      <div class="icon">📋</div>
      <div><div class="title">Sélection manuelle</div><div class="sub">Choisir dans la liste</div></div>
    </div>
    <div class="select-wrap">
      <select id="empl-select">
        <option value="">— Choisir un emplacement —</option>
        <?php foreach ($tous_empls as $e): ?>
        <option value="<?= $e['id'] ?>">
          <?= $e['parent_nom'] ? h($e['parent_nom']).' › ' : '' ?><?= h($e['nom']) ?>
          <?= $e['chaine_froid'] ? ' ❄️' : '' ?>
        </option>
        <?php endforeach; ?>
      </select>
      <span class="select-arrow">▾</span>
    </div>
    <button class="btn btn-primary" onclick="validerEmplacement()">
      Confirmer l'emplacement →
    </button>
  </div>
</div>

<!-- ══ ÉCRAN 2 — Scanner articles ══ -->
<div class="screen" id="screen-scan">
  <div class="stepper">
    <div class="step"><div class="step-dot done">✓</div><div class="step-label">Emplacement</div></div>
    <div class="step-line done"></div>
    <div class="step"><div class="step-dot active">2</div><div class="step-label active">Articles</div></div>
    <div class="step-line"></div>
    <div class="step"><div class="step-dot">3</div><div class="step-label">Validation</div></div>
  </div>

  <div class="empl-card" id="empl-info-card">
    <div class="empl-card-icon">📍</div>
    <div>
      <div class="empl-card-nom" id="empl-nom-display">—</div>
      <div class="empl-card-sub" id="empl-stats-display">—</div>
    </div>
  </div>

  <div id="scan-left">
  <!-- Scanner article -->
  <div style="margin-bottom:12px">
    <div class="scanner-wrap" id="scanner-article-wrap">
      <video id="scanner-article-video" autoplay playsinline muted></video>
      <div class="scanner-overlay">
        <div class="scanner-frame">
          <div class="scanner-line"></div>
          <div class="scanner-corner sc-tl"></div>
          <div class="scanner-corner sc-tr"></div>
          <div class="scanner-corner sc-bl"></div>
          <div class="scanner-corner sc-br"></div>
        </div>
      </div>
      <div class="scanner-badge">DataMatrix GS1 · EAN · QR</div>
    </div>
  </div>

  <!-- Saisie manuelle -->
  <div style="display:flex;gap:8px;margin-bottom:12px">
    <input type="text" id="input-code-manuel" class="input-field" style="margin-bottom:0;flex:1"
           placeholder="Saisir code manuellement…" inputmode="text">
    <button class="btn btn-ghost btn-sm" onclick="rechercherCodeManuel()" style="flex-shrink:0;min-width:48px">🔍</button>
  </div>

  <!-- Résultat scan -->
  <div id="scan-result-zone"></div>
  </div><!-- /#scan-left -->

  <div class="card" id="articles-card" style="display:none">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
      <div class="card-title" style="margin-bottom:0">✅ Articles scannés</div>
      <span class="badge b-ok" id="nb-scannes-badge">0</span>
    </div>
    <div id="articles-list"></div>
  </div>

  <div class="btn-row">
    <button class="btn btn-ghost btn-sm" onclick="goScreen('screen-empl')">← Retour</button>
    <button class="btn btn-amber" onclick="goRecap()" id="btn-recap">Récapitulatif →</button>
  </div>
</div>

<!-- ══ ÉCRAN 3 — Récapitulatif + Signature ══ -->
<div class="screen" id="screen-recap">
  <div class="stepper">
    <div class="step"><div class="step-dot done">✓</div><div class="step-label">Emplacement</div></div>
    <div class="step-line done"></div>
    <div class="step"><div class="step-dot done">✓</div><div class="step-label">Articles</div></div>
    <div class="step-line done"></div>
    <div class="step"><div class="step-dot active">3</div><div class="step-label active">Validation</div></div>
  </div>

  <div class="recap-stat">
    <div class="recap-box"><div class="recap-val" style="color:var(--green)" id="recap-scannes">0</div><div class="recap-lbl">Scannés</div></div>
    <div class="recap-box"><div class="recap-val" style="color:var(--primary)" id="recap-crees">0</div><div class="recap-lbl">Créés</div></div>
    <div class="recap-box"><div class="recap-val" style="color:var(--amber)" id="recap-non-scannes">0</div><div class="recap-lbl">Non scannés</div></div>
  </div>

  <div id="non-scannes-zone"></div>

  <div class="card">
    <div class="card-title">📝 Notes (optionnel)</div>
    <textarea id="recap-notes" class="input-field" rows="2" placeholder="Remarques, anomalies observées…" style="resize:none"></textarea>
  </div>

  <button class="btn btn-success" onclick="validerRempotage()" id="btn-valider">✅ Valider le rempotage</button>
  <button class="btn btn-ghost" style="margin-top:10px" onclick="goScreen('screen-scan')">← Retour aux articles</button>
</div>

<!-- ══ ÉCRAN 4 — Succès ══ -->
<div class="screen" id="screen-done">
  <div class="done-screen">
    <div class="done-check">✅</div>
    <div style="font-size:1.5rem;font-weight:900;margin-bottom:8px">Rempotage validé !</div>
    <div style="color:var(--text3);font-size:.9rem;margin-bottom:6px" id="done-stats">—</div>
    <div style="color:var(--text3);font-size:.8rem;margin-bottom:32px" id="done-date">—</div>
  </div>
  <button class="btn btn-primary" onclick="nouveauRempotage()">📦 Nouveau rempotage</button>
  <a href="rempotages.php" class="btn btn-ghost" style="margin-top:10px">📋 Voir l'historique</a>
</div>

</div><!-- .app-body -->

<!-- Modal création article -->
<div id="modal-creation" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.8);z-index:999;overflow-y:auto">
  <div style="background:var(--bg2);border-radius:var(--radius) var(--radius) 0 0;padding:20px;position:absolute;bottom:0;left:0;right:0;max-height:90vh;overflow-y:auto">
    <div style="font-size:1rem;font-weight:800;margin-bottom:4px">➕ Créer l'article</div>
    <div style="font-size:.8rem;color:var(--text2);margin-bottom:16px" id="modal-code-display">Code : —</div>

    <label class="form-label">Nom du produit *</label>
    <input type="text" id="new-nom" class="input-field" placeholder="Ex: Adrénaline 1mg/ml…">

    <label class="form-label">Date de péremption *</label>
    <input type="date" id="new-date" class="input-date" style="margin-bottom:10px">

    <label class="form-label">N° de lot</label>
    <input type="text" id="new-lot" class="input-field" placeholder="LOT2024A…">

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
      <div>
        <label class="form-label">Quantité</label>
        <input type="number" id="new-qte" class="input-field" value="1" min="0" step="0.5">
      </div>
      <div>
        <label class="form-label">Unité</label>
        <input type="text" id="new-unite" class="input-field" placeholder="unité, ampoule…">
      </div>
    </div>

    <div class="btn-row">
      <button class="btn btn-ghost" onclick="fermerModal()">Annuler</button>
      <button class="btn btn-success" onclick="confirmerCreation()">✅ Ajouter</button>
    </div>
  </div>
</div>

<script>
// ════════════════════════════════════════════════════════════
//  STATE
// ════════════════════════════════════════════════════════════
const state = {
  emplacement: null,
  articles: [],
  produitsOriginaux: [],
  scannerEmpl: null,
  codeEnAttente: null,
};

// Token CSRF — récupéré depuis le meta tag injecté par header.php
// Adapter selon votre implémentation (meta, cookie, ou variable JS)
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

// ════════════════════════════════════════════════════════════
//  UTILS
// ════════════════════════════════════════════════════════════
function escHtml(s) {
  return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/**
 * Helper date Safari — évite le bug UTC de new Date('YYYY-MM-DD')
 */
function parseLocalDate(str) {
  if (!str) return null;
  const parts = str.split('-');
  if (parts.length !== 3) return new Date(str);
  return new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]));
}

// ════════════════════════════════════════════════════════════
//  NAVIGATION
// ════════════════════════════════════════════════════════════
function goScreen(id) {
  var current   = document.querySelector('.screen.active');
  var currentId = current ? current.id : null;

  document.querySelectorAll('.screen').forEach(function(s){ s.classList.remove('active'); });
  document.getElementById(id).classList.add('active');
  document.querySelector('.app-body').scrollTop = 0;

  // Stopper le scanner si on change d'écran
  if (id !== 'screen-empl' && id !== 'screen-scan') stopScanner();

  // Démarrer le bon scanner seulement si on change d'écran
  if (id === 'screen-empl' && currentId !== 'screen-empl') startScannerEmpl();
  if (id === 'screen-scan' && currentId !== 'screen-scan') { stopScanner(); startScannerArticle(); }
}

// ── Scanner caméra : moteur partagé (scanner/scanner-zxing.js) ──
var _lastScanVideoId = null;

function stopScanner()         { CadurixScanner.stop(); }
function startScannerEmpl()    { _lastScanVideoId = 'scanner-empl-video';    CadurixScanner.start('scanner-empl-video',    onScanEmplacement, { onError: scanErr }); }
function startScannerArticle() { _lastScanVideoId = 'scanner-article-video'; CadurixScanner.start('scanner-article-video', onScanArticle,     { onError: scanErr }); }

// Erreur caméra : log Debug Center + message utilisateur (comportement d'origine)
function scanErr(e2) {
  try {
    fetch('/api/log_js_error.php', { method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ type:'SCANNER_ERROR', message: e2.message || String(e2),
        source:'scan.php', page_name:'Cadurix — Scanner', page_url: window.location.href }) });
  } catch (le) {}
  showNoCameraMsg(_lastScanVideoId, '⚠️ Caméra indisponible : ' + (e2.message || e2.name));
}

function showNoCameraMsg(videoId, msg) {
  msg = msg || '📱 Aucune caméra — utilisez la saisie manuelle.';
  var video = document.getElementById(videoId);
  if (!video) return;
  var wrap = video.closest('.scanner-wrap');
  if (wrap) {
    wrap.innerHTML = '<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;min-height:120px;padding:16px;text-align:center;gap:10px">'
      + '<div style="font-size:2.5rem">📷</div>'
      + '<div style="font-size:.82rem;color:var(--text2);line-height:1.5">' + msg + '</div>'
      + '</div>';
    wrap.style.background = 'var(--bg3)';
    wrap.style.border = '1px dashed var(--border)';
  }
}

// ════════════════════════════════════════════════════════════
//  EMPLACEMENT
// ════════════════════════════════════════════════════════════
function onScanEmplacement(code) {
  // Format URL : https://cadurix.fr/scan.php?empl=27
  let match = code.match(/[?&]empl=(\d+)/);
  // Format custom legacy : cadurix://emplacement/27
  if (!match) match = code.match(/cadurix:\/\/emplacement\/(\d+)/);

  if (match) {
    chargerEmplacement(parseInt(match[1]));
  } else {
    document.getElementById('empl-scan-result').innerHTML =
      `<div class="flash flash-err">❌ QR non reconnu : ${escHtml(code)}</div>`;
  }
}

function validerEmplacement() {
  const id = parseInt(document.getElementById('empl-select').value);
  if (!id) { alert('Sélectionnez un emplacement'); return; }
  chargerEmplacement(id);
}

async function chargerEmplacement(id) {
  try {
    const r    = await fetch(`scan.php?api=emplacement&id=${id}`);
    const data = await r.json();
    if (!data.ok) { alert(data.msg); return; }

    state.emplacement       = data.emplacement;
    state.produitsOriginaux = data.produits;
    state.articles          = [];

    const nom = data.emplacement.parent_nom
      ? `${data.emplacement.parent_nom} › ${data.emplacement.nom}`
      : data.emplacement.nom;

    document.getElementById('header-title').textContent     = nom;
    document.getElementById('empl-nom-display').textContent  = nom;
    document.getElementById('empl-stats-display').textContent =
      `${data.produits.length} produit(s) actuellement · Rempotage en cours`;

    stopScanner();

    // Afficher un message de confirmation avec compte à rebours
    document.getElementById('empl-scan-result').innerHTML =
      '<div style="text-align:center;padding:16px">'
      + '<div style="font-size:2rem;margin-bottom:8px">✅</div>'
      + '<div style="font-weight:700;font-size:1rem;margin-bottom:4px">' + nom + '</div>'
      + '<div style="font-size:.85rem;color:var(--text2)">Démarrage du scanner dans <span id="countdown">10</span>s…</div>'
      + '</div>';

    var _count = 5;
    var _timer = setInterval(function() {
      _count--;
      var el = document.getElementById('countdown');
      if (el) el.textContent = _count;
      if (_count <= 0) {
        clearInterval(_timer);
        goScreen('screen-scan');
      }
    }, 1000);

  } catch(e) { alert('Erreur de connexion'); }
}

// ════════════════════════════════════════════════════════════
//  SCAN ARTICLE
// ════════════════════════════════════════════════════════════
let lastCode = '', lastCodeTime = 0;

async function onScanArticle(code) {
  const now = Date.now();
  if (code === lastCode && now - lastCodeTime < 2000) return;
  lastCode = code; lastCodeTime = now;
  if (navigator.vibrate) navigator.vibrate(50);
  await traiterCode(code);
}

async function rechercherCodeManuel() {
  const code = document.getElementById('input-code-manuel').value.trim();
  if (!code) return;
  await traiterCode(code);
  document.getElementById('input-code-manuel').value = '';
}

async function traiterCode(code) {
  const zone = document.getElementById('scan-result-zone');
  zone.innerHTML = `<div class="flash flash-warn">⏳ Recherche en cours…</div>`;

  try {
    const r    = await fetch(`scan.php?api=scan_article&code=${encodeURIComponent(code)}`);
    const data = await r.json();

    if (data.trouve && data.produit && data.produit.nom) {
      afficherProduitTrouve(data.produit, data.parsed, code);
    } else {
      zone.innerHTML = `<div class="flash flash-warn">🔍 Recherche BDPM…</div>`;
      const codeRecherche = (data.parsed && data.parsed.gtin) ? data.parsed.gtin : code;
      const lotRecherche  = (data.parsed && data.parsed.lot)  ? data.parsed.lot  : '';
      try {
        const bdpm     = await fetch(`scan.php?api=lookup_bdpm&gtin=${encodeURIComponent(codeRecherche)}&lot=${encodeURIComponent(lotRecherche)}`);
        const bdpmData = await bdpm.json();

        if (bdpmData.rappel) {
          zone.innerHTML = `<div class="flash flash-err">🚨 LOT RAPPELÉ ANSM — ${escHtml(bdpmData.rappel.motif||'Rappel en vigueur')} — NE PAS UTILISER</div>`;
          if (navigator.vibrate) navigator.vibrate([200,100,200,100,200]);
          setTimeout(() => ouvrirModalCreation(code, data.parsed, bdpmData), 2500);
          return;
        }
        ouvrirModalCreation(code, data.parsed, bdpmData);
      } catch(e) {
        ouvrirModalCreation(code, data.parsed, null);
      }
    }
  } catch(e) {
    zone.innerHTML = `<div class="flash flash-err">❌ Erreur : ${e.message||'réseau'}</div>`;
    try {
      fetch('/api/log_js_error.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
          type: 'FETCH_ERROR',
          message: e.message || String(e),
          source: 'scan.php traiterCode',
          stack: e.stack || '',
          page_url: window.location.href,
          page_name: 'Cadurix — Scanner'
        })
      });
    } catch(le) {}
  }
}

function afficherProduitTrouve(produit, parsed, codeRaw) {
  parsed = parsed || {};
  const zone = document.getElementById('scan-result-zone');

  // ✅ parseLocalDate — fix Safari iOS
  const dateActuelle = produit.date_peremption
    ? parseLocalDate(produit.date_peremption).toLocaleDateString('fr-FR')
    : '—';

  let alertePerem = '';
  const dateCheck = parsed.date_exp || produit.date_peremption;
  if (dateCheck) {
    const dExp = parseLocalDate(dateCheck);
    const diff = (dExp - new Date()) / (1000*60*60*24);
    if (diff < 0) {
      alertePerem = `<div class="flash flash-err" style="margin-bottom:10px">🚫 PRODUIT PÉRIMÉ — ne pas utiliser</div>`;
      if (navigator.vibrate) navigator.vibrate([200,100,200]);
    } else if (diff < 90) {
      alertePerem = `<div class="flash flash-warn" style="margin-bottom:10px">⚠️ Péremption dans ${Math.round(diff)} jours</div>`;
    }
  }

  // ✅ JSON.stringify pour injection JS sécurisée
  zone.innerHTML = `
    <div class="produit-found">
      ${alertePerem}
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
        <span style="font-size:1.2rem">✅</span>
        <div>
          <div style="font-weight:700;font-size:.95rem">${escHtml(produit.nom)}</div>
          <div style="font-size:.78rem;color:var(--text2)">Produit reconnu</div>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:.82rem;margin-bottom:12px">
        <div>
          <div style="color:var(--text2);font-size:.7rem">DATE ACTUELLE</div>
          <div style="font-weight:600">${dateActuelle}</div>
        </div>
        <div>
          <div style="color:var(--text2);font-size:.7rem">NOUVELLE DATE</div>
          <input type="date" id="new-date-found"
                 value="${escHtml(parsed.date_exp || produit.date_peremption || '')}"
                 style="background:var(--bg2);border:1px solid var(--border);border-radius:8px;padding:6px 8px;color:var(--text);font-family:var(--font);font-size:.85rem;width:100%">
        </div>
        <div>
          <div style="color:var(--text2);font-size:.7rem">LOT ACTUEL</div>
          <div style="font-weight:600">${escHtml(produit.numero_lot || '—')}</div>
        </div>
        <div>
          <div style="color:var(--text2);font-size:.7rem">NOUVEAU LOT</div>
          <input type="text" id="new-lot-found"
                 value="${escHtml(parsed.lot || produit.numero_lot || '')}"
                 placeholder="Numéro de lot"
                 style="background:var(--bg2);border:1px solid var(--border);border-radius:8px;padding:6px 8px;color:var(--text);font-family:var(--font);font-size:.85rem;width:100%">
        </div>
      </div>
      <div style="display:flex;gap:8px">
        <button class="btn btn-success btn-sm" style="flex:1"
          onclick='ajouterArticle(${produit.id}, ${JSON.stringify(produit.nom)}, ${JSON.stringify(produit.ean||"")}, ${JSON.stringify(produit.gtin||"")}, ${parseFloat(produit.quantite)||1}, ${JSON.stringify(produit.unite||"unité")})'>
          ✅ Ajouter
        </button>
        <button class="btn btn-ghost btn-sm" onclick="document.getElementById('scan-result-zone').innerHTML=''">✕</button>
      </div>
    </div>
  `;
}

function ajouterArticle(produitId, nom, ean, gtin, qte, unite) {
  const dateEl = document.getElementById('new-date-found');
  const lotEl  = document.getElementById('new-lot-found');

  const existant = state.articles.findIndex(a => a.produit_id === produitId);
  if (existant >= 0) state.articles.splice(existant, 1);

  state.articles.push({
    action: 'scanne',
    produit_id: produitId,
    nom, ean, gtin,
    quantite: qte,
    unite,
    numero_lot: lotEl ? lotEl.value : '',
    date_peremption: dateEl ? dateEl.value : '',
    isNew: false,
  });

  if (navigator.vibrate) navigator.vibrate([50,30,50]);
  document.getElementById('scan-result-zone').innerHTML =
    `<div class="flash flash-ok">✅ ${escHtml(nom)} ajouté !</div>`;
  setTimeout(() => { document.getElementById('scan-result-zone').innerHTML = ''; }, 2000);
  rafraichirListe();
}

// ════════════════════════════════════════════════════════════
//  MODAL CRÉATION
// ════════════════════════════════════════════════════════════
function ouvrirModalCreation(code, parsed, bdpmData) {
  parsed = parsed || {};
  state.codeEnAttente = { code, parsed };
  document.getElementById('modal-code-display').textContent = `Code : ${code}`;
  document.getElementById('new-date').value  = parsed.date_exp || '';
  document.getElementById('new-lot').value   = parsed.lot || '';
  document.getElementById('new-qte').value   = '1';
  document.getElementById('new-unite').value = 'unité';

  const nomBdpm = bdpmData?.nom || '';
  document.getElementById('new-nom').value = nomBdpm;

  const sourceHtml = nomBdpm
    ? `<div class="flash flash-ok" style="margin-bottom:12px">✅ Nom récupéré via BDPM ANSM</div>`
    : `<div class="flash flash-warn" style="margin-bottom:12px">⚠️ Médicament non trouvé — saisir le nom manuellement</div>`;

  const modalBody = document.querySelector('#modal-creation > div');
  const existingBadge = modalBody.querySelector('.bdpm-badge');
  if (existingBadge) existingBadge.remove();
  const badgeEl = document.createElement('div');
  badgeEl.className = 'bdpm-badge';
  badgeEl.innerHTML = sourceHtml;
  modalBody.insertBefore(badgeEl, modalBody.querySelector('.form-label'));

  document.getElementById('modal-creation').style.display = 'block';
  document.getElementById('scan-result-zone').innerHTML = nomBdpm
    ? `<div class="flash flash-ok">✅ ${escHtml(nomBdpm)} — confirmez les informations</div>`
    : `<div class="flash flash-warn">⚠️ Article non reconnu — création rapide</div>`;

  setTimeout(() => {
    document.getElementById(nomBdpm ? 'new-date' : 'new-nom').focus();
  }, 300);
}

function fermerModal() {
  document.getElementById('modal-creation').style.display = 'none';
  document.getElementById('scan-result-zone').innerHTML = '';
  state.codeEnAttente = null;
}

function confirmerCreation() {
  const nom  = document.getElementById('new-nom').value.trim();
  const date = document.getElementById('new-date').value;
  const lot  = document.getElementById('new-lot').value.trim();
  const qte  = parseFloat(document.getElementById('new-qte').value) || 1;
  const unite= document.getElementById('new-unite').value.trim() || 'unité';

  if (!nom)  { alert('Le nom est obligatoire'); return; }
  if (!date) { alert('La date de péremption est obligatoire'); return; }

  const c = state.codeEnAttente;
  state.articles.push({
    action: 'cree',
    produit_id: null,
    nom,
    ean:  c?.parsed?.gtin ? '' : (c?.code || ''),
    gtin: c?.parsed?.gtin || '',
    numero_lot: lot,
    date_peremption: date,
    quantite: qte,
    unite,
    isNew: true,
  });

  fermerModal();
  if (navigator.vibrate) navigator.vibrate([50,30,50]);
  document.getElementById('scan-result-zone').innerHTML =
    `<div class="flash flash-ok">➕ ${escHtml(nom)} créé et ajouté !</div>`;
  setTimeout(() => { document.getElementById('scan-result-zone').innerHTML = ''; }, 2000);
  rafraichirListe();
}

// ════════════════════════════════════════════════════════════
//  LISTE ARTICLES
// ════════════════════════════════════════════════════════════
function rafraichirListe() {
  const card  = document.getElementById('articles-card');
  const list  = document.getElementById('articles-list');
  const badge = document.getElementById('nb-scannes-badge');

  if (state.articles.length === 0) { card.style.display = 'none'; return; }
  card.style.display = 'block';
  badge.textContent  = state.articles.length;

  list.innerHTML = state.articles.map((a, i) => {
    const icon  = a.isNew ? '➕' : '✅';
    const cls   = a.isNew ? 'ai-new' : 'ai-ok';
    const bdg   = a.isNew ? '<span class="badge b-new">Nouveau</span>' : '<span class="badge b-ok">Scanné</span>';
    // ✅ parseLocalDate — fix Safari
    const date  = a.date_peremption ? parseLocalDate(a.date_peremption).toLocaleDateString('fr-FR') : '—';

    let peremAlert = '';
    if (a.date_peremption) {
      const diff = (parseLocalDate(a.date_peremption) - new Date()) / (1000*60*60*24);
      if (diff < 0) peremAlert = ' <span class="badge b-err">PÉRIMÉ</span>';
      else if (diff < 90) peremAlert = ` <span class="badge b-warn">${Math.round(diff)}j</span>`;
    }

    return `
      <div class="article-item">
        <div class="article-icon ${cls}">${icon}</div>
        <div class="article-info">
          <div class="article-nom">${escHtml(a.nom)}</div>
          <div class="article-sub">
            ${bdg}${peremAlert}
            ${a.numero_lot ? `<span style="margin-left:4px;color:var(--text2);font-size:.72rem">Lot: ${escHtml(a.numero_lot)}</span>` : ''}
            <span style="margin-left:4px;color:var(--text2);font-size:.72rem">📅 ${date}</span>
          </div>
        </div>
        <button class="article-remove" onclick="retirerArticle(${i})">🗑</button>
      </div>
    `;
  }).join('');
}

function retirerArticle(i) {
  state.articles.splice(i, 1);
  rafraichirListe();
}

// ════════════════════════════════════════════════════════════
//  RÉCAPITULATIF
// ════════════════════════════════════════════════════════════
function goRecap() {
  if (state.articles.length === 0) { alert('Scannez au moins un article avant de continuer.'); return; }

  const idsScanned   = state.articles.filter(a => a.produit_id).map(a => a.produit_id);
  const nonScannes   = state.produitsOriginaux.filter(p => !idsScanned.includes(p.id));
  const nbScannes    = state.articles.filter(a => !a.isNew).length;
  const nbCrees      = state.articles.filter(a => a.isNew).length;

  document.getElementById('recap-scannes').textContent     = nbScannes;
  document.getElementById('recap-crees').textContent       = nbCrees;
  document.getElementById('recap-non-scannes').textContent = nonScannes.length;

  const zone = document.getElementById('non-scannes-zone');
  if (nonScannes.length > 0) {
    zone.innerHTML = `
      <div class="card">
        <div class="card-title">⚠️ Produits non scannés (${nonScannes.length})</div>
        <div style="font-size:.8rem;color:var(--text2);margin-bottom:10px">
          Ces produits étaient dans l'emplacement mais n'ont pas été scannés.
        </div>
        ${nonScannes.map(p => {
          // ✅ parseLocalDate + JSON.stringify
          const date   = p.date_peremption ? parseLocalDate(p.date_peremption).toLocaleDateString('fr-FR') : '—';
          const perime = p.date_peremption && parseLocalDate(p.date_peremption) < new Date();
          return `
          <div class="non-scanne" id="ns-${p.id}">
            <div>
              <div style="font-size:.875rem;font-weight:600">${escHtml(p.nom)}</div>
              <div style="font-size:.75rem;color:${perime?'#f87171':'var(--text2)'}">
                ${perime?'⚫ Périmé':'📅'} ${date}
              </div>
            </div>
            <div style="display:flex;gap:6px">
              <button class="btn btn-ghost btn-sm" style="padding:6px 10px;font-size:.75rem"
                      onclick="marquerNonScanne(event, ${p.id}, ${JSON.stringify(p.nom)})">
                Garder
              </button>
              <button class="btn btn-danger btn-sm" style="padding:6px 10px;font-size:.75rem"
                      onclick="marquerRetire(event, ${p.id}, ${JSON.stringify(p.nom)})">
                Retirer
              </button>
            </div>
          </div>
          `;
        }).join('')}
      </div>
    `;
  } else {
    zone.innerHTML = '';
  }

  stopScanner(state.scannerArticle);
  goScreen('screen-recap');
}

// ✅ event passé en paramètre — compatible Safari iOS
function marquerNonScanne(event, id, nom) {
  const el = event.target.closest('.non-scanne');
  if (el) el.style.opacity = '.4';
}

function marquerRetire(event, id, nom) {
  // Éviter doublon
  if (!state.articles.find(a => a.produit_id === id && a.action === 'retire')) {
    state.articles.push({ action: 'retire', produit_id: id, nom, isNew: false });
  }
  const el = event.target.closest('.non-scanne');
  if (el) {
    el.style.background   = 'rgba(239,68,68,.08)';
    el.style.borderColor  = 'rgba(239,68,68,.3)';
    const nomEl = el.querySelector('div > div:first-child');
    if (nomEl) nomEl.insertAdjacentHTML('afterend', '<span class="badge b-err" style="margin-top:4px;display:inline-block">Retiré</span>');
    el.querySelectorAll('button').forEach(b => b.remove());
  }
}

// ════════════════════════════════════════════════════════════
//  SIGNATURE
// ════════════════════════════════════════════════════════════

// ════════════════════════════════════════════════════════════
//  VALIDATION FINALE
// ════════════════════════════════════════════════════════════
async function validerRempotage() {
  const btn = document.getElementById('btn-valider');
  btn.disabled    = true;
  btn.textContent = '⏳ Enregistrement…';

  // Ajouter les non scannés restants
  const idsScanned = state.articles.filter(a => a.produit_id).map(a => a.produit_id);
  state.produitsOriginaux
    .filter(p => !idsScanned.includes(p.id))
    .forEach(p => {
      if (!state.articles.find(a => a.produit_id === p.id)) {
        state.articles.push({
          action: 'non_scanne',
          produit_id: p.id,
          nom: p.nom,
          quantite: p.quantite,
          unite: p.unite,
          isNew: false,
        });
      }
    });

  const signature = null;
  const notes     = document.getElementById('recap-notes').value.trim();

  try {
    const r = await fetch('scan.php?api=valider', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken,   // ✅ CSRF via header pour JSON
      },
      body: JSON.stringify({
        emplacement_id: state.emplacement.id,
        articles: state.articles,
        signature,
        notes,
      }),
    });
    const data = await r.json();

    if (data.ok) {
      const nbS = state.articles.filter(a => a.action === 'scanne').length;
      const nbC = state.articles.filter(a => a.action === 'cree').length;
      const nbN = state.articles.filter(a => a.action === 'non_scanne').length;

      document.getElementById('done-stats').textContent =
        `${nbS} scannés · ${nbC} créés · ${nbN} non scannés`;
      document.getElementById('done-date').textContent =
        `Le ${new Date().toLocaleDateString('fr-FR')} à ${new Date().toLocaleTimeString('fr-FR',{hour:'2-digit',minute:'2-digit'})}`;

      if (navigator.vibrate) navigator.vibrate([100,50,100,50,200]);
      goScreen('screen-done');
    } else {
      alert('Erreur : ' + data.msg);
      btn.disabled    = false;
      btn.textContent = '✅ Valider le rempotage';
    }
  } catch(e) {
    alert('Erreur réseau');
    btn.disabled    = false;
    btn.textContent = '✅ Valider le rempotage';
  }
}

function nouveauRempotage() {
  state.emplacement = null;
  state.articles    = [];
  state.produitsOriginaux = [];
  document.getElementById('header-title').textContent      = 'Scanner — Rempotage';
  document.getElementById('empl-scan-result').innerHTML    = '';
  document.getElementById('scan-result-zone').innerHTML    = '';
  document.getElementById('articles-list').innerHTML       = '';
  document.getElementById('articles-card').style.display   = 'none';
  goScreen('screen-empl');
}

// ════════════════════════════════════════════════════════════
//  INIT
// ════════════════════════════════════════════════════════════
window.addEventListener('load', () => {
  // Marquer screen-empl actif avant de démarrer le scanner
  document.getElementById('screen-empl').classList.add('active');
  startScannerEmpl();
});

</script>

</div><!-- scanner container -->

<?php require_once 'includes/footer.php'; ?>

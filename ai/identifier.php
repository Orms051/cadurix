<?php
// © Cadurix v5.4 — IA Photo : identification de produit par photo
// À placer dans : ai/identifier.php
// Phase 1 (usine) : identifie + rapproche + crée la référence (medicaments_ref),
// avec budget, limite par utilisateur, hors-ligne, et file de relecture anti-doublon.

require_once __DIR__ . '/../includes/auth.php';

// ── Garde module ─────────────────────────────────────────────
if (!module_actif('ia_photo')) {
    flashError("Le module Identification IA (photo) n'est pas activé pour ce site.");
    header('Location: /dashboard.php'); exit;
}
requireGestionnaire(); // saisie de référence = au moins gestionnaire

$site_id = (int)(site_actif() ?? 0);
$user_id = currentUserId();

// ── Liste fermée des formes (cohérence anti-doublon) ─────────
$FORMES = [
    'Comprimé', 'Gélule', 'Ampoule injectable', 'Solution injectable (IV/IM)',
    'Solution pour perfusion', 'Sirop / Solution buvable', 'Suppositoire',
    'Spray / Aérosol', 'Poudre', 'Patch transdermique', 'Collyre',
    'Pommade / Crème', 'Dispositif médical', 'Autre',
];

// ── Config IA (clé + modèle + tarifs) ────────────────────────
function ip_param(PDO $pdo, string $cle, string $def = ''): string {
    foreach ([['ai_settings','cle','valeur'], ['parametres','cle','valeur']] as $t) {
        try {
            $st = $pdo->prepare("SELECT {$t[2]} FROM {$t[0]} WHERE {$t[1]}=? LIMIT 1");
            $st->execute([$cle]);
            $v = $st->fetchColumn();
            if ($v !== false && $v !== null && $v !== '') return (string)$v;
        } catch (Throwable $e) {}
    }
    return $def;
}
$api_key = ip_param($pdo, 'api_key') ?: ip_param($pdo, 'anthropic_api_key');
$model   = ip_param($pdo, 'model', 'claude-haiku-4-5-20251001');

$TARIFS = [
    'claude-haiku-4-5-20251001' => ['in'=>0.80,'out'=>4.00],
    'claude-haiku-3'            => ['in'=>0.25,'out'=>1.25],
    'claude-sonnet-4-20250514'  => ['in'=>3.00,'out'=>15.00],
    'claude-opus-4'             => ['in'=>15.00,'out'=>75.00],
];
$TAUX_EUR = 0.92;
$tarif    = $TARIFS[$model] ?? $TARIFS['claude-haiku-4-5-20251001'];

// ── Budget & limites ─────────────────────────────────────────
$BUDGET_MOIS  = (float) module_param('ia_photo', 'budget_mois_eur', 5.0);
$ALERTE_PCT   = (int)   module_param('ia_photo', 'alerte_pct', 80);
$LIMITE_HEURE = 20; // photos / utilisateur / heure (garde-fou)

function ip_cout_mois(PDO $pdo, int $site_id): float {
    try {
        $st = $pdo->prepare("SELECT COALESCE(SUM(cout_eur),0) FROM ia_photo_usage WHERE site_id=? AND MONTH(cree_le)=MONTH(NOW()) AND YEAR(cree_le)=YEAR(NOW())");
        $st->execute([$site_id]);
        return (float)$st->fetchColumn();
    } catch (Throwable $e) { return 0.0; }
}
function ip_count_heure(PDO $pdo, int $user_id): int {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM ia_photo_usage WHERE user_id=? AND cree_le > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $st->execute([$user_id]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

// ════════════════════════════════════════════════════════════
//  ROUTEUR AJAX (réponses JSON)
// ════════════════════════════════════════════════════════════
$action  = $_POST['action'] ?? ($_GET['ajax'] ?? '');
$is_ajax = in_array($action, ['analyze','search_ref'], true);

if ($is_ajax) {
    header('Content-Type: application/json; charset=utf-8');
    if (!hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
        echo json_encode(['ok'=>false,'error'=>'Session expirée, rechargez la page.']); exit;
    }

    // ── Recherche de références (fusion / rapprochement manuel) ──
    if ($action === 'search_ref') {
        $q = trim($_POST['q'] ?? '');
        if (mb_strlen($q) < 2) { echo json_encode(['ok'=>true,'results'=>[]]); exit; }
        try {
            $like = '%'.$q.'%';
            $st = $pdo->prepare("SELECT id, nom, dci, forme, dosage, gtin, source, verifie FROM medicaments_ref WHERE actif=1 AND (nom LIKE ? OR dci LIKE ? OR gtin=?) ORDER BY (gtin=?) DESC, nom LIMIT 12");
            $st->execute([$like,$like,$q,$q]);
            echo json_encode(['ok'=>true,'results'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Throwable $e) { echo json_encode(['ok'=>false,'error'=>'Recherche impossible']); }
        exit;
    }

    // ── Analyse Vision ──────────────────────────────────────
    if ($action === 'analyze') {
        if (!$api_key) { echo json_encode(['ok'=>false,'error'=>'Clé API non configurée (Admin → IA).']); exit; }

        $cout_mois = ip_cout_mois($pdo, $site_id);
        if ($BUDGET_MOIS > 0 && $cout_mois >= $BUDGET_MOIS) {
            echo json_encode(['ok'=>false,'error'=>"Budget IA photo du mois atteint ({$BUDGET_MOIS} €).",'budget_bloque'=>true]); exit;
        }
        if (ip_count_heure($pdo, $user_id) >= $LIMITE_HEURE) {
            echo json_encode(['ok'=>false,'error'=>"Limite de {$LIMITE_HEURE} photos/heure atteinte. Réessayez plus tard."]); exit;
        }

        $b64   = $_POST['image'] ?? '';
        $media = $_POST['media_type'] ?? 'image/jpeg';
        $code  = trim($_POST['code'] ?? '');
        if (!$b64) { echo json_encode(['ok'=>false,'error'=>'Aucune image reçue.']); exit; }
        if (!in_array($media, ['image/jpeg','image/png','image/webp'], true)) $media = 'image/jpeg';

        $formes_str = implode(', ', $FORMES);
        $prompt = "Tu es un assistant d'identification de produits pharmaceutiques et de matériel médical pour les services de secours français (SDIS/SSSM).
Analyse la photo de l'emballage et réponds UNIQUEMENT par un objet JSON valide, sans aucun texte autour, sans balises de code.
Champs attendus :
{
  \"nom\": \"nom commercial lisible sur la boîte\",
  \"dci\": \"dénomination commune internationale (molécule) si visible, sinon null\",
  \"forme\": \"une valeur parmi : $formes_str\",
  \"dosage\": \"dosage si visible (ex: 1000 mg, 500 mg/5 ml), sinon null\",
  \"fabricant\": \"laboratoire si visible, sinon null\",
  \"code_barres\": \"GTIN / EAN si lisible (chiffres uniquement), sinon null\",
  \"numero_lot\": \"numéro de lot si lisible, sinon null\",
  \"date_peremption\": \"format AAAA-MM ou AAAA-MM-JJ si lisible, sinon null\",
  \"confiance\": entier de 0 à 100 indiquant ta certitude sur le nom
}
Ne devine pas : si un champ n'est pas lisible, mets null. N'invente jamais un dosage ni un lot.";

        $payload = [
            'model'      => $model,
            'max_tokens' => 500,
            'messages'   => [[
                'role' => 'user',
                'content' => [
                    ['type'=>'image','source'=>['type'=>'base64','media_type'=>$media,'data'=>$b64]],
                    ['type'=>'text','text'=>$prompt],
                ],
            ]],
        ];

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json','x-api-key: '.$api_key,'anthropic-version: 2023-06-01'],
            CURLOPT_TIMEOUT        => 40,
        ]);
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($resp === false || $cerr) { echo json_encode(['ok'=>false,'error'=>'Réseau : '.$cerr,'offline'=>true]); exit; }
        $data = json_decode($resp, true);
        if ($http !== 200 || empty($data['content'][0]['text'])) {
            echo json_encode(['ok'=>false,'error'=>$data['error']['message'] ?? ('Erreur API HTTP '.$http)]); exit;
        }

        $txt = trim(preg_replace('/```json|```/', '', $data['content'][0]['text']));
        $fields = json_decode($txt, true);
        if (!is_array($fields)) { echo json_encode(['ok'=>false,'error'=>'Réponse IA illisible.']); exit; }

        $ti = (int)($data['usage']['input_tokens']  ?? 0);
        $to = (int)($data['usage']['output_tokens'] ?? 0);
        $cout = round(($ti/1_000_000*$tarif['in'] + $to/1_000_000*$tarif['out']) * $TAUX_EUR, 6);

        try {
            $pdo->prepare("INSERT INTO ia_photo_usage (site_id,user_id,tokens_input,tokens_output,cout_eur,identifie,nom_detecte) VALUES (?,?,?,?,?,?,?)")
                ->execute([$site_id,$user_id,$ti,$to,$cout, !empty($fields['nom'])?1:0, mb_substr((string)($fields['nom']??''),0,200)]);
        } catch (Throwable $e) {}

        // Rapprochement : code-barres (scanné ou lu) puis nom/dci
        $code_match = $code ?: trim((string)($fields['code_barres'] ?? ''));
        $matches = [];
        try {
            if ($code_match) {
                $st = $pdo->prepare("SELECT id,nom,dci,forme,dosage,gtin,source,verifie FROM medicaments_ref WHERE actif=1 AND gtin=? LIMIT 5");
                $st->execute([$code_match]);
                $matches = $st->fetchAll(PDO::FETCH_ASSOC);
            }
            if (!$matches && !empty($fields['nom'])) {
                $like = '%'.trim($fields['nom']).'%';
                $st = $pdo->prepare("SELECT id,nom,dci,forme,dosage,gtin,source,verifie FROM medicaments_ref WHERE actif=1 AND (nom LIKE ? OR dci LIKE ?) ORDER BY nom LIMIT 8");
                $st->execute([$like,$like]);
                $matches = $st->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (Throwable $e) {}

        echo json_encode([
            'ok'=>true,'fields'=>$fields,'code'=>$code_match,'matches'=>$matches,
            'cout'=>$cout,'cout_mois'=>round($cout_mois+$cout,4),'budget'=>$BUDGET_MOIS,
        ]);
        exit;
    }
}

// ════════════════════════════════════════════════════════════
//  ACTIONS FORMULAIRE (création / relecture) — avec redirection
// ════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$is_ajax) {
    csrf_check();
    $act = $_POST['action'] ?? '';

    if ($act === 'create') {
        $nom = trim($_POST['nom'] ?? '');
        if ($nom === '') { flashError("Le nom est obligatoire."); header('Location: identifier.php'); exit; }
        $gtin = trim($_POST['code'] ?? '') ?: null;
        if ($gtin) {
            $ex = $pdo->prepare("SELECT id,nom FROM medicaments_ref WHERE gtin=? LIMIT 1");
            $ex->execute([$gtin]);
            if ($r = $ex->fetch(PDO::FETCH_ASSOC)) {
                flashError("Ce code-barres est déjà rattaché à « {$r['nom']} ». Utilisez la fusion si besoin.");
                header('Location: identifier.php'); exit;
            }
        }
        try {
            $pdo->prepare("INSERT INTO medicaments_ref (nom,dci,forme,dosage,unite,gtin,actif,source,verifie,cree_par,cree_le) VALUES (?,?,?,?,?,?,1,'ia',0,?,NOW())")
                ->execute([
                    $nom,
                    trim($_POST['dci'] ?? '') ?: null,
                    trim($_POST['forme'] ?? '') ?: null,
                    trim($_POST['dosage'] ?? '') ?: null,
                    trim($_POST['unite'] ?? '') ?: 'unité',
                    $gtin, $user_id,
                ]);
            audit_log($pdo, 'IA_PHOTO_CREATE_REF', 'medicaments_ref', (int)$pdo->lastInsertId(), null, ['nom'=>$nom]);
            flashSuccess("Référence « $nom » créée (à vérifier).");
        } catch (Throwable $e) { flashError("Création impossible : ".$e->getMessage()); }
        header('Location: identifier.php'); exit;
    }

    if ($act === 'attach') {
        $ref_id = (int)($_POST['ref_id'] ?? 0);
        $gtin   = trim($_POST['code'] ?? '');
        if ($ref_id && $gtin) {
            try {
                $pdo->prepare("UPDATE medicaments_ref SET gtin=? WHERE id=? AND (gtin IS NULL OR gtin='')")->execute([$gtin,$ref_id]);
                audit_log($pdo, 'IA_PHOTO_ATTACH_GTIN', 'medicaments_ref', $ref_id, null, ['gtin'=>$gtin]);
                flashSuccess("Code-barres rattaché à la fiche existante.");
            } catch (Throwable $e) { flashError("Échec : ".$e->getMessage()); }
        }
        header('Location: identifier.php'); exit;
    }

    if ($act === 'validate') {
        $id = (int)($_POST['ref_id'] ?? 0);
        if ($id) { $pdo->prepare("UPDATE medicaments_ref SET verifie=1 WHERE id=?")->execute([$id]); flashSuccess("Référence validée."); }
        header('Location: identifier.php'); exit;
    }

    if ($act === 'delete') {
        $id = (int)($_POST['ref_id'] ?? 0);
        if ($id) { $pdo->prepare("DELETE FROM medicaments_ref WHERE id=? AND source='ia'")->execute([$id]); flashSuccess("Référence supprimée."); }
        header('Location: identifier.php'); exit;
    }

    if ($act === 'merge') {
        $src = (int)($_POST['ref_id'] ?? 0);
        $dst = (int)($_POST['target_id'] ?? 0);
        if ($src && $dst && $src !== $dst) {
            try {
                $pdo->beginTransaction();
                $g = $pdo->prepare("SELECT gtin FROM medicaments_ref WHERE id=?"); $g->execute([$src]);
                $gtin_src = $g->fetchColumn();
                if ($gtin_src) $pdo->prepare("UPDATE medicaments_ref SET gtin=? WHERE id=? AND (gtin IS NULL OR gtin='')")->execute([$gtin_src,$dst]);
                $pdo->prepare("DELETE FROM medicaments_ref WHERE id=? AND source='ia'")->execute([$src]);
                $pdo->commit();
                audit_log($pdo, 'IA_PHOTO_MERGE_REF', 'medicaments_ref', $dst, ['supprime'=>$src], null);
                flashSuccess("Fiches fusionnées.");
            } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); flashError("Fusion impossible : ".$e->getMessage()); }
        }
        header('Location: identifier.php'); exit;
    }
}

// ── Données d'affichage ──────────────────────────────────────
$cout_mois  = ip_cout_mois($pdo, $site_id);
$pct_budget = $BUDGET_MOIS > 0 ? (int)round($cout_mois / $BUDGET_MOIS * 100) : 0;

$a_verifier = [];
try {
    $a_verifier = $pdo->query("SELECT id,nom,dci,forme,dosage,gtin,cree_le FROM medicaments_ref WHERE source='ia' AND verifie=0 ORDER BY cree_le DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$pageTitle = 'Identification IA';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.ia-wrap{max-width:760px;margin:0 auto}
.ia-budget{display:flex;align-items:center;gap:12px;margin-bottom:18px;font-size:13px;color:var(--text2)}
.ia-budget .bar{flex:1;height:8px;background:var(--border2);border-radius:8px;overflow:hidden}
.ia-budget .bar i{display:block;height:100%;border-radius:8px}
.ia-drop{display:block;border:2px dashed var(--border2);border-radius:16px;padding:30px 20px;text-align:center;cursor:pointer;transition:.15s;background:var(--glass)}
.ia-drop:hover{border-color:var(--primary);background:var(--primary-bg)}
.ia-drop input{display:none}
.ia-preview{max-width:100%;max-height:280px;border-radius:12px;margin:14px auto;display:none}
.ia-result{margin-top:18px;display:none}
.ia-field-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media(max-width:600px){.ia-field-grid{grid-template-columns:1fr}}
.ia-conf{display:inline-block;padding:2px 10px;border-radius:20px;font-size:12px;font-weight:700}
.ia-match{border:1px solid var(--border);border-radius:10px;padding:10px 14px;margin-bottom:8px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:var(--bg2)}
.verif-row{display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:var(--glass);border:1px solid var(--border);border-radius:10px;padding:10px 14px;margin-bottom:8px}
.verif-row form{margin:0;display:inline}
.badge-ia{background:rgba(245,158,11,.15);color:#F59E0B;font-size:10px;font-weight:700;padding:2px 8px;border-radius:12px}
.ia-spinner{display:none;text-align:center;padding:20px;color:var(--text2)}




/* ── Mobile (Tap & Go) ───────────────────────────── */
@media (max-width: 768px) {
  .ia-wrap { max-width: 100%; }

  /* Budget compacté : la barre passe sur sa propre ligne */
  .ia-budget { flex-wrap: wrap; font-size: 12px; gap: 6px 10px; }
  .ia-budget .bar { order: 3; flex-basis: 100%; height: 10px; }

  /* Zone capture plus généreuse au doigt */
  .ia-drop { padding: 40px 16px; }

  /* Cibles tactiles : boutons hauts, bouton principal pleine largeur */
  .ia-wrap .btn { min-height: 46px; }
  .ia-wrap .btn-primary { width: 100%; justify-content: center; }

  /* Lignes "à vérifier" : tout empilé, actions pleine largeur */
  .verif-row { flex-direction: column; align-items: stretch; gap: 8px; }
  .verif-row form, .verif-row > button { width: 100%; }

  /* Correspondances trouvées : bouton dessous, pleine largeur */
  .ia-match { flex-direction: column; align-items: stretch; }
  .ia-match form, .ia-match .btn { width: 100%; }

  /* Modal fusion → feuille du bas (plus naturel au pouce) */
  #merge-modal { align-items: flex-end; }
  #merge-modal > .card { width: 100%; max-width: 100%; border-radius: 18px 18px 0 0; max-height: 85vh; overflow: auto; }
}

</style>

<div class="page-header">
  <div class="page-title-group">
    <div class="page-title">🖼️ Identification IA</div>
    <div class="page-sub">Photographie un produit non reconnu — l'IA l'identifie et l'ajoute à la base.</div>
  </div>
</div>

<div class="ia-wrap">

  <div class="ia-budget">
    <span>💶 Budget du mois</span>
    <div class="bar"><i style="width:<?= min(100,$pct_budget) ?>%;background:<?= $pct_budget>=100?'var(--red)':($pct_budget>=$ALERTE_PCT?'var(--amber)':'var(--green)') ?>"></i></div>
    <span><?= number_format($cout_mois,2,',',' ') ?> € / <?= number_format($BUDGET_MOIS,2,',',' ') ?> €</span>
  </div>
  <?php if ($BUDGET_MOIS>0 && $pct_budget>=$ALERTE_PCT && $pct_budget<100): ?>
    <div class="card" style="background:rgba(245,158,11,.12);border-color:#F59E0B;color:#F59E0B;padding:10px 14px;margin-bottom:14px;font-weight:600">⚠️ Budget IA photo à <?= $pct_budget ?> % du plafond mensuel.</div>
  <?php elseif ($BUDGET_MOIS>0 && $pct_budget>=100): ?>
    <div class="card" style="background:rgba(239,68,68,.12);border-color:#EF4444;color:#EF4444;padding:10px 14px;margin-bottom:14px;font-weight:600">🛑 Budget IA photo épuisé — l'analyse est suspendue.</div>
  <?php endif; ?>

  <label class="ia-drop" id="ia-drop">
    <div style="font-size:40px">📷</div>
    <div style="font-weight:700;margin-top:6px">Prendre une photo / choisir un fichier</div>
    <div style="font-size:12px;color:var(--text3);margin-top:4px">La photo n'est pas conservée — seules les infos lues sont enregistrées.</div>
    <input type="file" id="ia-file" accept="image/*" capture="environment">
  </label>
  <img id="ia-preview" class="ia-preview" alt="aperçu">

  <div class="ia-spinner" id="ia-spinner">🔎 Analyse en cours…</div>
  <div class="card" id="ia-error" style="display:none;background:rgba(239,68,68,.1);border-color:#EF4444;color:#EF4444;padding:12px 16px;margin-top:14px"></div>

  <div class="ia-result" id="ia-result">
    <div class="card">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
        <div class="card-title" style="margin-bottom:0">Résultat de l'analyse</div>
        <span class="ia-conf" id="ia-conf"></span>
      </div>

      <div id="ia-matches-wrap" style="display:none;margin-bottom:16px">
        <div style="font-size:12px;color:var(--text2);font-weight:600;margin-bottom:8px">Déjà en base — réutilisez plutôt que de créer un doublon :</div>
        <div id="ia-matches"></div>
        <div style="font-size:12px;color:var(--text3);margin:10px 0 4px">Sinon, créez une nouvelle fiche ci-dessous.</div>
      </div>

      <form method="post" id="ia-create-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="code" id="f-code">
        <div class="ia-field-grid">
          <div class="form-group"><label class="form-label">Nom *</label><input class="form-control" name="nom" id="f-nom" required></div>
          <div class="form-group"><label class="form-label">DCI (molécule)</label><input class="form-control" name="dci" id="f-dci"></div>
          <div class="form-group">
            <label class="form-label">Forme</label>
            <select class="form-control" name="forme" id="f-forme">
              <option value="">—</option>
              <?php foreach ($FORMES as $f): ?><option value="<?= h($f) ?>"><?= h($f) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label class="form-label">Dosage</label><input class="form-control" name="dosage" id="f-dosage"></div>
        </div>
        <div class="ia-field-grid">
          <div class="form-group"><label class="form-label">Lot (info — ira au stock)</label><input class="form-control" id="f-lot" disabled></div>
          <div class="form-group"><label class="form-label">Péremption (info — ira au stock)</label><input class="form-control" id="f-perem" disabled></div>
        </div>
        <div style="margin-top:8px"><button type="submit" class="btn btn-primary">💾 Créer la référence (à vérifier)</button></div>
      </form>
    </div>
  </div>

  <div class="card" style="margin-top:22px">
    <div class="card-title">📋 À vérifier — créées par l'IA (<?= count($a_verifier) ?>)</div>
    <?php if (empty($a_verifier)): ?>
      <div style="color:var(--text3);font-size:13px;padding:6px 0">Aucune référence en attente de validation.</div>
    <?php else: foreach ($a_verifier as $r): ?>
      <div class="verif-row">
        <div style="flex:1;min-width:160px">
          <div style="font-weight:700"><?= h($r['nom']) ?> <span class="badge-ia">IA</span></div>
          <div style="font-size:11.5px;color:var(--text3)">
            <?= h($r['dci'] ?: '—') ?> · <?= h($r['forme'] ?: '—') ?> · <?= h($r['dosage'] ?: '—') ?>
            <?php if ($r['gtin']): ?> · <span style="font-family:var(--mono)">GTIN <?= h($r['gtin']) ?></span><?php endif; ?>
          </div>
        </div>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="validate"><input type="hidden" name="ref_id" value="<?= (int)$r['id'] ?>"><button class="btn btn-ghost btn-sm" style="color:var(--green)">✅ Valider</button></form>
        <button class="btn btn-ghost btn-sm" onclick="openMerge(<?= (int)$r['id'] ?>,'<?= h(addslashes($r['nom'])) ?>')">🔀 Fusionner</button>
        <form method="post" onsubmit="return confirm('Supprimer cette référence ?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="ref_id" value="<?= (int)$r['id'] ?>"><button class="btn btn-ghost btn-sm" style="color:#EF4444">🗑️</button></form>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<div id="merge-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;align-items:center;justify-content:center">
  <div class="card" style="width:90%;max-width:520px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
      <div class="card-title" style="margin-bottom:0">Fusionner « <span id="merge-name"></span> »</div>
      <button class="btn btn-ghost btn-sm" onclick="document.getElementById('merge-modal').style.display='none'">✕</button>
    </div>
    <div style="font-size:12px;color:var(--text2);margin-bottom:8px">Cherche la fiche à conserver. La fiche IA sera supprimée, son code-barres transféré.</div>
    <input class="form-control" id="merge-search" placeholder="Rechercher un médicament…" oninput="searchRef(this.value)">
    <div id="merge-results" style="margin-top:10px;max-height:300px;overflow:auto"></div>
  </div>
</div>

<script>
var CSRF = document.querySelector('meta[name="csrf-token"]').content;

document.getElementById('ia-file').addEventListener('change', function(e){
  var file = e.target.files[0]; if(!file) return;
  var img = new Image(), reader = new FileReader();
  reader.onload = function(ev){ img.src = ev.target.result; };
  img.onload = function(){
    var max = 1280, w = img.width, h = img.height;
    if (w > h && w > max){ h = h*max/w; w = max; } else if (h > max){ w = w*max/h; h = max; }
    var cv = document.createElement('canvas'); cv.width = w; cv.height = h;
    cv.getContext('2d').drawImage(img, 0, 0, w, h);
    var dataUrl = cv.toDataURL('image/jpeg', 0.8);
    document.getElementById('ia-preview').src = dataUrl;
    document.getElementById('ia-preview').style.display = 'block';
    analyze(dataUrl.split(',')[1]);
  };
  reader.readAsDataURL(file);
});

function showErr(msg){ var e=document.getElementById('ia-error'); e.textContent='⚠️ '+msg; e.style.display='block'; }

function analyze(b64){
  document.getElementById('ia-error').style.display='none';
  document.getElementById('ia-result').style.display='none';
  if (!navigator.onLine){ showErr("Hors ligne — l'identification IA nécessite une connexion. Réessaie une fois le réseau revenu."); return; }
  document.getElementById('ia-spinner').style.display='block';
  var fd = new FormData();
  fd.append('action','analyze'); fd.append('csrf',CSRF);
  fd.append('image',b64); fd.append('media_type','image/jpeg');
  fetch('identifier.php',{method:'POST',body:fd})
    .then(function(r){return r.json();})
    .then(function(d){
      document.getElementById('ia-spinner').style.display='none';
      if(!d.ok){ showErr(d.error||'Erreur'); return; }
      renderResult(d);
    })
    .catch(function(){ document.getElementById('ia-spinner').style.display='none'; showErr("Échec de l'analyse (réseau ?)."); });
}

function renderResult(d){
  var f = d.fields||{};
  document.getElementById('f-nom').value    = f.nom||'';
  document.getElementById('f-dci').value    = f.dci||'';
  document.getElementById('f-dosage').value = f.dosage||'';
  document.getElementById('f-lot').value    = f.numero_lot||'';
  document.getElementById('f-perem').value  = f.date_peremption||'';
  document.getElementById('f-code').value   = d.code||'';
  var sel = document.getElementById('f-forme');
  if (f.forme){ for (var i=0;i<sel.options.length;i++){ if(sel.options[i].value===f.forme){ sel.selectedIndex=i; break; } } }

  var c = parseInt(f.confiance||0,10);
  var conf = document.getElementById('ia-conf');
  conf.textContent = 'Confiance ' + c + ' %';
  conf.style.background = c>=70?'rgba(16,185,129,.15)':(c>=40?'rgba(245,158,11,.15)':'rgba(239,68,68,.15)');
  conf.style.color = c>=70?'#10B981':(c>=40?'#F59E0B':'#EF4444');

  var mw = document.getElementById('ia-matches-wrap'), mc = document.getElementById('ia-matches');
  mc.innerHTML='';
  if (d.matches && d.matches.length){
    mw.style.display='block';
    d.matches.forEach(function(m){
      var div = document.createElement('div'); div.className='ia-match';
      div.innerHTML = '<div style="flex:1;min-width:140px"><div style="font-weight:700">'+esc(m.nom)+'</div>'+
        '<div style="font-size:11px;color:var(--text3)">'+esc(m.dci||'—')+' · '+esc(m.forme||'—')+(m.gtin?(' · GTIN '+esc(m.gtin)):'')+'</div></div>';
      if (d.code && !m.gtin){
        var fmA = document.createElement('form'); fmA.method='post'; fmA.style.margin='0';
        fmA.innerHTML = '<input type="hidden" name="csrf" value="'+CSRF+'"><input type="hidden" name="action" value="attach"><input type="hidden" name="ref_id" value="'+m.id+'"><input type="hidden" name="code" value="'+esc(d.code)+'"><button class="btn btn-primary btn-sm">🔗 Associer le code</button>';
        div.appendChild(fmA);
      }
      mc.appendChild(div);
    });
  } else { mw.style.display='none'; }

  document.getElementById('ia-result').style.display='block';
}

function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }

var mergeSrc=null;
function openMerge(id,name){ mergeSrc=id; document.getElementById('merge-name').textContent=name; document.getElementById('merge-results').innerHTML=''; document.getElementById('merge-search').value=''; document.getElementById('merge-modal').style.display='flex'; }
var mt=null;
function searchRef(q){
  clearTimeout(mt);
  mt=setTimeout(function(){
    if(q.trim().length<2){ document.getElementById('merge-results').innerHTML=''; return; }
    var fd=new FormData(); fd.append('action','search_ref'); fd.append('csrf',CSRF); fd.append('q',q);
    fetch('identifier.php',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
      var box=document.getElementById('merge-results'); box.innerHTML='';
      (d.results||[]).forEach(function(m){
        var f=document.createElement('form'); f.method='post'; f.style.margin='0 0 6px';
        f.innerHTML='<input type="hidden" name="csrf" value="'+CSRF+'"><input type="hidden" name="action" value="merge"><input type="hidden" name="ref_id" value="'+mergeSrc+'"><input type="hidden" name="target_id" value="'+m.id+'">'+
          '<button class="ia-match" style="width:100%;text-align:left;cursor:pointer;border:none"><div><div style="font-weight:700">'+esc(m.nom)+'</div><div style="font-size:11px;color:var(--text3)">'+esc(m.dci||'—')+' · '+esc(m.forme||'—')+'</div></div></button>';
        box.appendChild(f);
      });
      if(!(d.results||[]).length) box.innerHTML='<div style="color:var(--text3);font-size:12px">Aucun résultat.</div>';
    });
  },300);
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

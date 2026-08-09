<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.

if (!defined('ADMIN_HUB')) { require_once __DIR__ . '/../includes/auth.php'; requireAdmin(); }
// ============================================================
//  Cadurix — admin/cout.php
//  Tableau de bord des coûts du site
// ============================================================
require_once '../config/db.php';
require_once '../includes/header.php';

if (!peut_faire(ROLE_ADMIN)) {
    http_response_code(403); exit('Accès refusé');
}

// Tarifs Anthropic par modèle ($/1M tokens)
$TARIFS = [
    'claude-haiku-4-5-20251001'  => ['in' => 0.80,  'out' => 4.00,  'label' => 'Haiku 4.5'],
    'claude-haiku-3'             => ['in' => 0.25,  'out' => 1.25,  'label' => 'Haiku 3'],
    'claude-sonnet-4-20250514'   => ['in' => 3.00,  'out' => 15.00, 'label' => 'Sonnet 4'],
    'claude-opus-4'              => ['in' => 15.00, 'out' => 75.00, 'label' => 'Opus 4'],
];
define('TAUX_EUR', 0.92);

// Lit le modèle actif depuis ai_settings
$modele_actif = 'claude-haiku-4-5-20251001'; // fallback
try {
    $row = $pdo->query("SELECT valeur FROM ai_settings WHERE cle='model' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($row && isset($TARIFS[$row['valeur']])) $modele_actif = $row['valeur'];
} catch(Exception $e) {}

$tarif = $TARIFS[$modele_actif];

// ── Stats IA ────────────────────────────────────────────────
$statsIA = ['nb_conversations'=>0,'total_tokens'=>0,'tokens_mois'=>0,'conv_mois'=>0,'premiere_conv'=>null];
$statsMois  = [];
$statsPages = [];
try {
    // Détecte les colonnes disponibles
    $cols = $pdo->query("SHOW COLUMNS FROM ai_conversations")->fetchAll(PDO::FETCH_COLUMN);
    $tokCol = in_array('tokens', $cols) ? 'tokens' : (in_array('tokens_input', $cols) ? 'tokens_input+COALESCE(tokens_output,0)' : '0');

    $statsIA = $pdo->query("
        SELECT
            COUNT(*)                                          AS nb_conversations,
            COALESCE(SUM(tokens), 0)                          AS total_tokens,
            COALESCE(SUM(cout_eur), 0)                        AS cout_total,
            COALESCE(SUM(CASE WHEN MONTH(cree_le)=MONTH(CURDATE()) AND YEAR(cree_le)=YEAR(CURDATE()) THEN tokens  END), 0) AS tokens_mois,
            COALESCE(SUM(CASE WHEN MONTH(cree_le)=MONTH(CURDATE()) AND YEAR(cree_le)=YEAR(CURDATE()) THEN cout_eur END), 0) AS cout_mois,
            COALESCE(SUM(CASE WHEN MONTH(cree_le)=MONTH(CURDATE()) AND YEAR(cree_le)=YEAR(CURDATE()) THEN 1 END), 0)       AS conv_mois,
            MIN(cree_le) AS premiere_conv
        FROM ai_conversations
    ")->fetch(PDO::FETCH_ASSOC);

    $statsMois = $pdo->query("
        SELECT DATE_FORMAT(cree_le,'%Y-%m') AS mois,
               DATE_FORMAT(cree_le,'%b %Y') AS mois_label,
               COUNT(*) AS nb_conv,
               COALESCE(SUM(tokens),0)   AS tokens,
               COALESCE(SUM(cout_eur),0) AS cout
        FROM ai_conversations
        WHERE cree_le >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(cree_le,'%Y-%m') ORDER BY mois ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $statsPages = $pdo->query("
        SELECT COALESCE(page,'Autre') AS page,
               COUNT(*) AS nb_conv,
               COALESCE(SUM(tokens),0)   AS tokens,
               COALESCE(SUM(cout_eur),0) AS cout
        FROM ai_conversations GROUP BY page ORDER BY cout DESC LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {
    // Table IA non disponible — on continue sans les stats
}

// ── Dépenses fixes ───────────────────────────────────────────
// Crée la table si elle n'existe pas encore
$pdo->exec("CREATE TABLE IF NOT EXISTS `depenses_fixes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nom` VARCHAR(100) NOT NULL,
  `type` ENUM('hebergement','domaine','logiciel','autre') NOT NULL DEFAULT 'autre',
  `montant_annuel` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `description` VARCHAR(200) DEFAULT NULL,
  `actif` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$depenses    = $pdo->query("SELECT * FROM depenses_fixes WHERE actif=1 ORDER BY type,nom")->fetchAll(PDO::FETCH_ASSOC);
$totalAnnuel = array_sum(array_column($depenses, 'montant_annuel'));
$totalMens   = $totalAnnuel / 12;

// ── Calculs IA ───────────────────────────────────────────────
$totTok    = (int)$statsIA['total_tokens'];
$moisTok   = (int)$statsIA['tokens_mois'];
// Coût lu directement depuis la BDD
$coutTotal = (float)($statsIA['cout_total'] ?? 0);
$coutMois  = (float)($statsIA['cout_mois']  ?? 0);

// ── AJAX ─────────────────────────────────────────────────────
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    if ($_GET['ajax']==='save' && $_SERVER['REQUEST_METHOD']==='POST') {
        csrf_check();
        $d = json_decode(file_get_contents('php://input'), true);
        if (!empty($d['id'])) {
            $pdo->prepare("UPDATE depenses_fixes SET nom=?,type=?,montant_annuel=?,description=? WHERE id=?")
                ->execute([$d['nom'],$d['type'],$d['montant'],$d['desc'],$d['id']]);
        } else {
            $pdo->prepare("INSERT INTO depenses_fixes (nom,type,montant_annuel,description) VALUES (?,?,?,?)")
                ->execute([$d['nom'],$d['type'],$d['montant'],$d['desc']]);
        }
        echo json_encode(['ok'=>true]); exit;
    }
    if ($_GET['ajax']==='delete' && isset($_GET['id'])) {
        $pdo->prepare("UPDATE depenses_fixes SET actif=0 WHERE id=?")->execute([(int)$_GET['id']]);
        echo json_encode(['ok'=>true]); exit;
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Coûts — Cadurix</title>
<style>
.cout-kpi{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px;margin-bottom:28px}
.ck{background:var(--bg2);border:1px solid var(--border);border-radius:14px;padding:18px 20px;position:relative;overflow:hidden;transition:transform .2s,border-color .2s;text-decoration:none;display:block}
.ck:hover{transform:translateY(-2px);border-color:rgba(var(--primary-rgb),.3)}
.ck::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:var(--ck-c,var(--primary))}
.ck-blue{--ck-c:#3B82F6}.ck-green{--ck-c:#1D9E75}.ck-orange{--ck-c:#EF9F27}.ck-purple{--ck-c:#8B5CF6}
.ck-ico{font-size:20px;margin-bottom:10px}
.ck-val{font-size:26px;font-weight:800;color:var(--text);line-height:1;font-variant-numeric:tabular-nums}
.ck-val small{font-size:13px;font-weight:500;color:var(--text2);margin-left:3px}
.ck-lbl{font-size:11px;color:var(--text2);margin-top:5px;text-transform:uppercase;letter-spacing:.05em}
.ck-sub{font-size:11px;color:var(--text3);margin-top:3px}

.csect{background:var(--bg2);border:1px solid var(--border);border-radius:16px;overflow:hidden;margin-bottom:20px}
.csect-hd{display:flex;align-items:center;justify-content:space-between;padding:13px 18px;background:var(--bg3);border-bottom:1px solid var(--border)}
.csect-ttl{font-size:13px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:7px}
.csect-bd{padding:18px}

.dep-table{width:100%;border-collapse:collapse}
.dep-th{padding:8px 14px;font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--text2);text-align:left;border-bottom:1px solid var(--border);background:var(--bg3)}
.dep-tr{border-bottom:1px solid var(--border);transition:background .1s}
.dep-tr:last-child{border-bottom:none}
.dep-tr:hover{background:rgba(var(--primary-rgb),.025)}
.dep-td{padding:11px 14px;font-size:13px;vertical-align:middle}
.dep-badge{font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px}
.b-hebergement{background:rgba(59,130,246,.12);color:#3B82F6}
.b-domaine{background:rgba(139,92,246,.12);color:#8B5CF6}
.b-logiciel{background:rgba(29,158,117,.12);color:#1D9E75}
.b-autre{background:rgba(107,114,128,.12);color:#9CA3AF}
.dep-acts{display:flex;gap:4px;justify-content:flex-end}
.act{width:28px;height:28px;border-radius:7px;display:inline-flex;align-items:center;justify-content:center;font-size:13px;border:1px solid transparent;background:transparent;color:var(--text2);cursor:pointer;transition:all .15s;text-decoration:none}
.act:hover{background:var(--bg3);border-color:var(--border);color:var(--text)}
.act.del:hover{background:rgba(226,75,74,.1);border-color:rgba(226,75,74,.3);color:#E24B4A}

.chart-wrap{height:160px;display:flex;align-items:flex-end;gap:5px;padding-top:8px}
.cb-wrap{flex:1;display:flex;flex-direction:column;align-items:center;gap:3px}
.cb{width:100%;border-radius:5px 5px 0 0;background:rgba(var(--primary-rgb),.2);border:1px solid rgba(var(--primary-rgb),.3);transition:background .2s;position:relative;min-height:4px}
.cb:hover{background:rgba(var(--primary-rgb),.5)}
.cb-tip{position:absolute;bottom:calc(100% + 4px);left:50%;transform:translateX(-50%);background:var(--bg3);border:1px solid var(--border);border-radius:6px;padding:4px 8px;font-size:10px;color:var(--text);white-space:nowrap;opacity:0;pointer-events:none;transition:opacity .15s;z-index:10}
.cb:hover .cb-tip{opacity:1}
.cb-lbl{font-size:9px;color:var(--text3);text-align:center}

.bilan-row{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid var(--border);font-size:13px}
.bilan-row:last-child{border-bottom:none}
.bilan-row.total{font-weight:700;font-size:15px;padding-top:14px}
.b-ok{color:#1D9E75}

.tarif-card{background:var(--bg3);border:1px solid var(--border);border-radius:10px;padding:14px;margin-bottom:8px}
.tarif-model{font-size:13px;font-weight:700;color:var(--text);margin-bottom:8px}
.tarif-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:3px}
.tarif-lbl{font-size:11px;color:var(--text2)}
.tarif-val{font-size:12px;font-weight:600;color:var(--text);font-variant-numeric:tabular-nums}

.modal-ov{display:none;position:fixed;inset:0;z-index:200;background:rgba(0,0,0,.6);backdrop-filter:blur(4px);align-items:center;justify-content:center}
.modal-ov.open{display:flex}
.modal-box{background:var(--bg2);border:1px solid var(--border);border-radius:18px;padding:28px;width:440px;max-width:95vw}
.modal-ttl{font-size:17px;font-weight:700;color:var(--text);margin-bottom:20px;display:flex;align-items:center;gap:8px}
.modal-acts{display:flex;gap:8px;justify-content:flex-end;margin-top:20px}

.info-ban{display:flex;align-items:flex-start;gap:10px;padding:12px 16px;border-radius:10px;margin-bottom:20px;background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.2);font-size:13px;color:#60A5FA;line-height:1.5}
</style>
</head>
<body>

<div class="page-header">
  <div class="page-title-group">
    <div class="page-title">💰 Coûts &amp; Budget</div>
    <div class="page-sub">Suivi des dépenses d'hébergement, domaine et IA</div>
  </div>
  <button class="btn btn-primary" onclick="openModal()">
    <i class="ti ti-plus"></i> Ajouter une dépense
  </button>
</div>

<div class="info-ban">
  <i class="ti ti-info-circle" style="font-size:16px;flex-shrink:0;margin-top:1px"></i>
  <div>Coûts IA estimés sur la base de <strong><?= $tarif['label'] ?></strong> (modèle actif) —
  <?= $tarif['in'] ?>$/1M input · <?= $tarif['out'] ?>$/1M output.
  Taux : 1 USD = <?= TAUX_EUR ?> €. Répartition estimée 70% input / 30% output.</div>
</div>

<!-- KPI -->
<div class="cout-kpi">
  <div class="ck ck-blue">
    <div class="ck-ico">🏠</div>
    <div class="ck-val"><?= number_format($totalAnnuel,2,',',' ') ?><small>€/an</small></div>
    <div class="ck-lbl">Dépenses fixes</div>
    <div class="ck-sub"><?= number_format($totalMens,2,',',' ') ?> €/mois</div>
  </div>
  <div class="ck ck-purple">
    <div class="ck-ico">🤖</div>
    <div class="ck-val"><?= number_format($coutTotal,4,',',' ') ?><small>€</small></div>
    <div class="ck-lbl">Coût IA total</div>
    <div class="ck-sub"><?= number_format($totTok,0,',',' ') ?> tokens consommés</div>
  </div>
  <div class="ck ck-orange">
    <div class="ck-ico">📅</div>
    <div class="ck-val"><?= number_format($coutMois,4,',',' ') ?><small>€</small></div>
    <div class="ck-lbl">Coût IA ce mois</div>
    <div class="ck-sub"><?= number_format($moisTok,0,',',' ') ?> tokens · <?= $statsIA['conv_mois'] ?> conv.</div>
  </div>
  <div class="ck ck-green">
    <div class="ck-ico">💬</div>
    <div class="ck-val"><?= $statsIA['nb_conversations'] ?></div>
    <div class="ck-lbl">Conversations IA</div>
    <div class="ck-sub">Depuis <?= $statsIA['premiere_conv'] ? date('d/m/Y',strtotime($statsIA['premiere_conv'])) : '—' ?></div>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 300px;gap:20px;align-items:start">
<div>

<!-- GRAPHIQUE -->
<div class="csect">
  <div class="csect-hd">
    <div class="csect-ttl"><i class="ti ti-chart-bar"></i> Tokens IA par mois</div>
  </div>
  <div class="csect-bd">
    <?php if (empty($statsMois)): ?>
    <div style="text-align:center;padding:30px;color:var(--text2)">📊 Aucune donnée</div>
    <?php else: $maxT = max(array_column($statsMois,'tokens')) ?: 1; ?>
    <div class="chart-wrap">
      <?php foreach ($statsMois as $m): $pct=round($m['tokens']/$maxT*100); ?>
      <div class="cb-wrap">
        <div class="cb" style="height:<?= max($pct,2) ?>%">
          <div class="cb-tip"><?= $m['mois_label'] ?><br><?= number_format($m['tokens'],0,',',' ') ?> tokens<br><?= $m['nb_conv'] ?> conv.</div>
        </div>
        <div class="cb-lbl"><?= substr($m['mois_label'],0,3) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- DÉPENSES FIXES -->
<div class="csect">
  <div class="csect-hd">
    <div class="csect-ttl"><i class="ti ti-receipt"></i> Dépenses fixes</div>
    <button class="btn btn-ghost btn-sm" onclick="openModal()"><i class="ti ti-plus"></i> Ajouter</button>
  </div>
  <?php if (empty($depenses)): ?>
  <div class="csect-bd" style="text-align:center;padding:40px;color:var(--text2)">
    <div style="font-size:36px;margin-bottom:10px">🏠</div>
    Aucune dépense enregistrée.<br>
    <button class="btn btn-ghost btn-sm" style="margin-top:10px" onclick="openModal()">+ Ajouter la première</button>
  </div>
  <?php else: ?>
  <table class="dep-table">
    <thead><tr>
      <th class="dep-th">Désignation</th>
      <th class="dep-th">Type</th>
      <th class="dep-th">Mensuel</th>
      <th class="dep-th">Annuel</th>
      <th class="dep-th"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($depenses as $d): ?>
    <tr class="dep-tr">
      <td class="dep-td">
        <div style="font-weight:600"><?= htmlspecialchars($d['nom']) ?></div>
        <?php if ($d['description']): ?><div style="font-size:11px;color:var(--text2)"><?= htmlspecialchars($d['description']) ?></div><?php endif; ?>
      </td>
      <td class="dep-td"><span class="dep-badge b-<?= $d['type'] ?>"><?= ucfirst($d['type']) ?></span></td>
      <td class="dep-td" style="font-weight:600;font-variant-numeric:tabular-nums"><?= number_format($d['montant_annuel']/12,2,',',' ') ?> €</td>
      <td class="dep-td" style="font-weight:600;font-variant-numeric:tabular-nums"><?= number_format($d['montant_annuel'],2,',',' ') ?> €</td>
      <td class="dep-td">
        <div class="dep-acts">
          <button class="act" onclick='editDep(<?= json_encode($d) ?>)' title="Modifier"><i class="ti ti-edit"></i></button>
          <button class="act del" onclick="delDep(<?= $d['id'] ?>,'<?= htmlspecialchars($d['nom']) ?>')" title="Supprimer"><i class="ti ti-trash"></i></button>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<!-- PAR FONCTIONNALITÉ -->
<div class="csect">
  <div class="csect-hd">
    <div class="csect-ttl"><i class="ti ti-layout-list"></i> Consommation par fonctionnalité</div>
  </div>
  <?php if (empty($statsPages)): ?>
  <div class="csect-bd" style="text-align:center;padding:30px;color:var(--text2)">Aucune donnée</div>
  <?php else: $maxP = max(array_column($statsPages,'tokens')) ?: 1; ?>
  <table class="dep-table">
    <thead><tr>
      <th class="dep-th">Page</th>
      <th class="dep-th">Conv.</th>
      <th class="dep-th">Tokens</th>
      <th class="dep-th">Coût est.</th>
      <th class="dep-th" style="width:100px">Usage</th>
    </tr></thead>
    <tbody>
    <?php foreach ($statsPages as $p):
      $tok=(int)$p['tokens'];
      $c=(float)($p['cout'] ?? 0);
      $pct=round($tok/$maxP*100);
    ?>
    <tr class="dep-tr">
      <td class="dep-td" style="font-weight:600"><?= htmlspecialchars($p['page']) ?></td>
      <td class="dep-td" style="color:var(--text2)"><?= $p['nb_conv'] ?></td>
      <td class="dep-td" style="font-variant-numeric:tabular-nums"><?= number_format($tok,0,',',' ') ?></td>
      <td class="dep-td" style="color:var(--text2)"><?= number_format($c,5,',',' ') ?> €</td>
      <td class="dep-td">
        <div style="height:6px;background:var(--bg3);border-radius:3px;overflow:hidden">
          <div style="height:100%;width:<?= $pct ?>%;background:rgba(var(--primary-rgb),.5);border-radius:3px"></div>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

</div><!-- /col gauche -->

<!-- COLONNE DROITE -->
<div>

<!-- BILAN -->
<div class="csect" style="margin-bottom:20px">
  <div class="csect-hd"><div class="csect-ttl"><i class="ti ti-calculator"></i> Bilan annuel</div></div>
  <div class="csect-bd">
    <div class="bilan-row">
      <span>🏠 Hébergement + domaine</span>
      <span style="font-weight:600;font-variant-numeric:tabular-nums"><?= number_format($totalAnnuel,2,',',' ') ?> €</span>
    </div>
    <div class="bilan-row">
      <span>🤖 IA (projection annuelle)</span>
      <span style="font-weight:600;font-variant-numeric:tabular-nums"><?= number_format($coutMois*12,4,',',' ') ?> €</span>
    </div>
    <div class="bilan-row total">
      <span>Total estimé</span>
      <span class="b-ok" style="font-variant-numeric:tabular-nums"><?= number_format($totalAnnuel+$coutMois*12,2,',',' ') ?> €/an</span>
    </div>
    <div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--border)">
      <div style="font-size:11px;color:var(--text2);margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em">Coût mensuel moyen</div>
      <div style="font-size:26px;font-weight:800;color:var(--text)">
        <?= number_format(($totalAnnuel+$coutMois*12)/12,2,',',' ') ?>
        <span style="font-size:14px;color:var(--text2);font-weight:500">€/mois</span>
      </div>
    </div>
  </div>
</div>

<!-- TARIFS -->
<div class="csect">
  <div class="csect-hd"><div class="csect-ttl"><i class="ti ti-tag"></i> Tarifs Anthropic</div></div>
  <div class="csect-bd">
    <?php foreach ($TARIFS as $k => $t): ?>
    <div class="tarif-card" style="<?= $k===$modele_actif?'border-color:rgba(var(--primary-rgb),.4)':'' ?>">
      <div class="tarif-model"><?= $k===$modele_actif?'⭐ ':'' ?><?= $t['label'] ?><?= $k===$modele_actif?' <span style="font-size:10px;color:var(--primary)">(actif)</span>':'' ?></div>
      <div class="tarif-row"><span class="tarif-lbl">Input</span><span class="tarif-val">$<?= $t['in'] ?>/1M tokens</span></div>
      <div class="tarif-row"><span class="tarif-lbl">Output</span><span class="tarif-val">$<?= $t['out'] ?>/1M tokens</span></div>
    </div>
    <?php endforeach; ?>
    <div style="font-size:11px;color:var(--text3);margin-top:8px">* Tarifs mai 2026</div>
  </div>
</div>

</div><!-- /col droite -->
</div>

<!-- MODAL -->
<div class="modal-ov" id="modal">
  <div class="modal-box">
    <div class="modal-ttl"><i class="ti ti-receipt"></i><span id="modal-ttl">Nouvelle dépense</span></div>
    <input type="hidden" id="dep-id">
    <div class="form-group">
      <label class="form-label">Désignation *</label>
      <input type="text" class="form-input" id="dep-nom" placeholder="Ex: Hébergement o2switch">
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Type *</label>
        <select class="form-input" id="dep-type">
          <option value="hebergement">Hébergement</option>
          <option value="domaine">Domaine</option>
          <option value="logiciel">Logiciel</option>
          <option value="autre">Autre</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Montant annuel (€) *</label>
        <input type="number" class="form-input" id="dep-montant" placeholder="0.00" step="0.01" min="0">
      </div>
    </div>
    <div class="form-group">
      <label class="form-label">Description</label>
      <input type="text" class="form-input" id="dep-desc" placeholder="Notes, renouvellement...">
    </div>
    <div class="modal-acts">
      <button class="btn btn-ghost" onclick="closeModal()">Annuler</button>
      <button class="btn btn-primary" onclick="saveDepense()"><i class="ti ti-check"></i> Enregistrer</button>
    </div>
  </div>
</div>

<script>
function openModal(d=null){
  document.getElementById('dep-id').value=d?.id||'';
  document.getElementById('dep-nom').value=d?.nom||'';
  document.getElementById('dep-type').value=d?.type||'hebergement';
  document.getElementById('dep-montant').value=d?.montant_annuel||'';
  document.getElementById('dep-desc').value=d?.description||'';
  document.getElementById('modal-ttl').textContent=d?'✏️ Modifier':'➕ Nouvelle dépense';
  document.getElementById('modal').classList.add('open');
  setTimeout(()=>document.getElementById('dep-nom').focus(),100);
}
function closeModal(){ document.getElementById('modal').classList.remove('open'); }
function editDep(d){ openModal(d); }

async function saveDepense(){
  const data={
    id:document.getElementById('dep-id').value,
    nom:document.getElementById('dep-nom').value.trim(),
    type:document.getElementById('dep-type').value,
    montant:parseFloat(document.getElementById('dep-montant').value),
    desc:document.getElementById('dep-desc').value.trim()
  };
  if(!data.nom||!data.montant){alert('Désignation et montant requis.');return;}
  const r=await(await fetch('cout.php?ajax=save',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)})).json();
  if(r.ok){closeModal();location.reload();}
}

async function delDep(id,nom){
  if(!confirm(`Supprimer « ${nom} » ?`))return;
  await fetch(`cout.php?ajax=delete&id=${id}`);
  location.reload();
}

document.getElementById('modal').addEventListener('click',function(e){if(e.target===this)closeModal();});
</script>

<?php require_once '../includes/footer.php'; ?>

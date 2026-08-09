<?php
// © Cadurix — Onglet hub : Statistiques (inclus par admin/index.php)
if (!defined('ADMIN_HUB')) { header('Location: /admin/?tab=stats'); exit; }
require_once __DIR__ . '/../config/roles.php';
require_once __DIR__ . '/../config/perms_modules.php';

$st_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'purge_stats') {
    csrf_check();
    $mois = max(1, (int)($_POST['mois'] ?? 6));
    try {
        $pdo->prepare("DELETE FROM activite_stats WHERE cree_le < (NOW() - INTERVAL ? MONTH)")->execute([$mois]);
        $st_msg = "Statistiques de plus de $mois mois purgées.";
    } catch (Throwable $e) { $st_msg = "Erreur : " . $e->getMessage(); }
}

$periode = (int)($_GET['periode'] ?? 30); if (!in_array($periode, [7, 30, 90], true)) $periode = 30;
$f_role  = $_GET['srole'] ?? '';
$f_plat  = $_GET['splat'] ?? '';
$since   = (new DateTime())->modify("-$periode days")->format('Y-m-d H:i:s');
$cond = "cree_le >= ?"; $args = [$since];
if ($f_role !== '')                      { $cond .= " AND role=?";       $args[] = $f_role; }
if (in_array($f_plat, ['pc', 'mobile'])) { $cond .= " AND plateforme=?"; $args[] = $f_plat; }

$tuiles = []; $pages = []; $cx = []; $trend = [];
try {
    $q = $pdo->prepare("SELECT cible, COUNT(*) n, SUM(plateforme='mobile') mob FROM activite_stats WHERE type='tuile' AND $cond GROUP BY cible ORDER BY n DESC");
    $q->execute($args); $tuiles = $q->fetchAll(PDO::FETCH_ASSOC);
    $q = $pdo->prepare("SELECT cible, COUNT(*) n, SUM(plateforme='mobile') mob FROM activite_stats WHERE type='page' AND $cond GROUP BY cible ORDER BY n DESC LIMIT 25");
    $q->execute($args); $pages = $q->fetchAll(PDO::FETCH_ASSOC);
    $q = $pdo->prepare("SELECT DATE(cree_le) j, COUNT(*) n FROM activite_stats WHERE $cond GROUP BY DATE(cree_le) ORDER BY j");
    $q->execute($args); $trend = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
try {
    $q = $pdo->prepare("SELECT u.login,u.prenom,u.nom, COUNT(*) n FROM connexions_historique ch JOIN utilisateurs u ON u.id=ch.user_id WHERE ch.cree_le>=? AND ch.succes=1 GROUP BY ch.user_id ORDER BY n DESC LIMIT 20");
    $q->execute([$since]); $cx = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$MODS = modules_permissions();
$mlabel = fn($k) => ($MODS[$k]['icon'] ?? '•') . ' ' . ($MODS[$k]['label'] ?? $k);
$maxT = max(1, ...(array_map(fn($r) => (int)$r['n'], $tuiles) ?: [1]));
$maxP = max(1, ...(array_map(fn($r) => (int)$r['n'], $pages) ?: [1]));
$maxTr= max(1, ...(array_map(fn($r) => (int)$r['n'], $trend) ?: [1]));
$qs = fn($extra) => '?tab=stats&' . http_build_query(array_merge(['periode'=>$periode,'srole'=>$f_role,'splat'=>$f_plat], $extra));
?>
<style>
.st-filters{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:18px}
.st-filters select{padding:8px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg);color:var(--text);font-size:.85rem}
.st-seg{display:inline-flex;border:1px solid var(--border);border-radius:9px;overflow:hidden}
.st-seg a{padding:8px 14px;font-size:.85rem;color:var(--text2);text-decoration:none}
.st-seg a.on{background:var(--primary);color:#fff;font-weight:700}
.st-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}@media(max-width:900px){.st-grid{grid-template-columns:1fr}}
.st-row{display:flex;align-items:center;gap:10px;margin-bottom:9px;font-size:.86rem}
.st-row .lab{width:150px;flex-shrink:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.st-bar-wrap{flex:1;background:var(--border2,#e2e8f0);border-radius:6px;height:16px;overflow:hidden;min-width:0}
.st-bar{height:100%;background:linear-gradient(90deg,var(--primary),#60a5fa);border-radius:6px}
.st-n{width:96px;text-align:right;flex-shrink:0;color:var(--text2);font-size:.8rem}
.st-empty{color:var(--text3);font-size:.85rem;padding:10px 0}
.st-trend{display:flex;align-items:flex-end;gap:3px;height:80px;margin-top:6px}
.st-trend .tb{flex:1;background:var(--primary);border-radius:3px 3px 0 0;min-height:2px;opacity:.8}
</style>

<?php if ($st_msg): ?><div class="card" style="background:rgba(22,163,74,.1);border-color:rgba(22,163,74,.35);margin-bottom:16px;padding:12px 16px">✅ <?= h($st_msg) ?></div><?php endif; ?>

<div class="st-filters">
  <div class="st-seg">
    <?php foreach ([7=>'7 j',30=>'30 j',90=>'90 j'] as $d=>$lab): ?>
      <a href="<?= h($qs(['periode'=>$d])) ?>" class="<?= $periode===$d?'on':'' ?>"><?= $lab ?></a>
    <?php endforeach; ?>
  </div>
  <select onchange="location.href='<?= h($qs([])) ?>'.replace(/srole=[^&]*/,'srole='+encodeURIComponent(this.value))">
    <option value="">Tous les rôles</option>
    <?php foreach (roles_definis() as $rk=>$rc): ?>
      <option value="<?= $rk ?>" <?= $f_role===$rk?'selected':'' ?>><?= $rc['icon'] ?> <?= h($rc['label']) ?></option>
    <?php endforeach; ?>
  </select>
  <select onchange="location.href='<?= h($qs([])) ?>'.replace(/splat=[^&]*/,'splat='+encodeURIComponent(this.value))">
    <option value="">💻 + 📱 Tout</option>
    <option value="pc"     <?= $f_plat==='pc'?'selected':'' ?>>💻 PC seul</option>
    <option value="mobile" <?= $f_plat==='mobile'?'selected':'' ?>>📱 Mobile seul</option>
  </select>
</div>

<div class="card" style="margin-bottom:18px">
  <h3 style="margin:0 0 14px;font-size:1rem;font-weight:800">📈 Activité par jour</h3>
  <?php if ($trend): ?>
  <div class="st-trend"><?php foreach ($trend as $t): ?><div class="tb" style="height:<?= round((int)$t['n']/$maxTr*100) ?>%" title="<?= h($t['j']) ?> — <?= (int)$t['n'] ?>"></div><?php endforeach; ?></div>
  <?php else: ?><div class="st-empty">Pas encore de données.</div><?php endif; ?>
</div>

<div class="st-grid">
  <div class="card">
    <h3 style="margin:0 0 14px;font-size:1rem;font-weight:800">🎯 Tuiles les plus utilisées</h3>
    <?php if ($tuiles): foreach ($tuiles as $r): $mp=(int)$r['n']?round((int)$r['mob']/(int)$r['n']*100):0; ?>
      <div class="st-row"><span class="lab"><?= h($mlabel($r['cible'])) ?></span>
        <span class="st-bar-wrap"><span class="st-bar" style="width:<?= round((int)$r['n']/$maxT*100) ?>%"></span></span>
        <span class="st-n"><?= (int)$r['n'] ?> · 📱<?= $mp ?>%</span></div>
    <?php endforeach; else: ?><div class="st-empty">Aucun clic de tuile.</div><?php endif; ?>
  </div>
  <div class="card">
    <h3 style="margin:0 0 14px;font-size:1rem;font-weight:800">📄 Pages les plus visitées</h3>
    <?php if ($pages): foreach ($pages as $r): $mp=(int)$r['n']?round((int)$r['mob']/(int)$r['n']*100):0; ?>
      <div class="st-row"><span class="lab"><?= h($r['cible']) ?></span>
        <span class="st-bar-wrap"><span class="st-bar" style="width:<?= round((int)$r['n']/$maxP*100) ?>%"></span></span>
        <span class="st-n"><?= (int)$r['n'] ?> · 📱<?= $mp ?>%</span></div>
    <?php endforeach; else: ?><div class="st-empty">Aucune page vue.</div><?php endif; ?>
  </div>
</div>

<div class="card" style="margin-top:18px">
  <h3 style="margin:0 0 14px;font-size:1rem;font-weight:800">👥 Qui se connecte le plus</h3>
  <?php if ($cx): $maxC=max(1,...array_map(fn($r)=>(int)$r['n'],$cx)); foreach ($cx as $r): ?>
    <div class="st-row"><span class="lab"><?= h(trim($r['prenom'].' '.$r['nom'])) ?: h($r['login']) ?></span>
      <span class="st-bar-wrap"><span class="st-bar" style="width:<?= round((int)$r['n']/$maxC*100) ?>%"></span></span>
      <span class="st-n"><?= (int)$r['n'] ?> conn.</span></div>
  <?php endforeach; else: ?><div class="st-empty">Aucune connexion.</div><?php endif; ?>
</div>

<div class="card" style="margin-top:18px">
  <h3 style="margin:0 0 10px;font-size:1rem;font-weight:800">🧹 Purge RGPD</h3>
  <p style="font-size:.85rem;color:var(--text2);margin:0 0 12px">Supprime le détail des statistiques au-delà d'une ancienneté choisie.</p>
  <form method="POST" action="?tab=stats" onsubmit="return confirm('Purger définitivement au-delà de cette ancienneté ?');" style="display:flex;gap:8px;align-items:center">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="purge_stats">
    <select name="mois" style="padding:8px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg);color:var(--text)">
      <option value="3">+ de 3 mois</option><option value="6" selected>+ de 6 mois</option><option value="12">+ de 12 mois</option>
    </select>
    <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--red);border-color:var(--red)">🗑️ Purger</button>
  </form>
</div>

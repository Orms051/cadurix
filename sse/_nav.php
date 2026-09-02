<?php
// Barre de navigation commune SSE / NRBC.
// Utilisation : $sse_active = 'dashboard'|'equipements'|'consommables'|'controles'|'categories';
//               include (chemin vers) sse/_nav.php  — APRÈS includes/header.php.
$sse_active = $sse_active ?? '';
$__sid = (int)(function_exists('site_actif') ? (site_actif() ?? 0) : 0);
$__nbEq = $__nbCs = 0;
try {
    if (isset($pdo) && $__sid) {
        $__nbEq = (int)$pdo->query("SELECT COUNT(*) FROM sse_equipements WHERE site_id=$__sid AND actif=1")->fetchColumn();
        $__nbCs = (int)$pdo->query("SELECT COUNT(*) FROM sse_consommables WHERE site_id=$__sid AND statut='actif'")->fetchColumn();
    }
} catch (Throwable $e) {}
?>
<style>
.sse-nav { display:flex; gap:8px; margin-bottom:20px; flex-wrap:wrap; }
.sse-nav a { display:inline-flex; align-items:center; gap:6px; padding:9px 15px; border-radius:10px; font-size:.85rem; font-weight:700; text-decoration:none; background:var(--bg2); border:1px solid var(--border); color:var(--text2); transition:.15s; }
.sse-nav a:hover { border-color:var(--primary); color:var(--primary); }
.sse-nav a.active { background:linear-gradient(135deg,#3b82f6,#8b5cf6); border-color:transparent; color:#fff; box-shadow:0 6px 18px -8px rgba(59,130,246,.6); }
.sse-nav a .c { font-size:.72rem; opacity:.75; font-weight:600; }
.sse-nav a.active .c { opacity:.9; }
/* KPI compacts partagés (SSE) */
.sse-ck { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:12px; margin-bottom:20px; }
.sse-ck-card { position:relative; display:flex; align-items:center; gap:12px; background:var(--bg2); border:1px solid var(--border); border-radius:13px; padding:12px 15px; overflow:hidden; box-shadow:0 1px 2px rgba(20,30,60,.04); }
.sse-ck-card::before { content:""; position:absolute; left:0; top:0; bottom:0; width:3px; background:var(--kc,var(--primary)); }
.sse-ck-card .ic { width:36px; height:36px; border-radius:10px; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:1.05rem; }
.sse-ck-card .n { font-size:1.5rem; font-weight:800; line-height:1; font-variant-numeric:tabular-nums; }
.sse-ck-card .l { font-size:.68rem; color:var(--text3); font-weight:700; text-transform:uppercase; letter-spacing:.04em; margin-top:3px; }
.sse-ck-card .f { font-size:.66rem; color:var(--text3); margin-top:1px; }
</style>
<div class="sse-nav">
  <a href="/sse.php" class="<?= $sse_active==='dashboard'?'active':'' ?>">📊 Dashboard</a>
  <a href="/sse/equipements.php" class="<?= $sse_active==='equipements'?'active':'' ?>">⚙️ Équipements <span class="c">(<?= $__nbEq ?>)</span></a>
  <a href="/sse/consommables.php" class="<?= $sse_active==='consommables'?'active':'' ?>">📦 Consommables <span class="c">(<?= $__nbCs ?>)</span></a>
  <a href="/sse/controles.php" class="<?= $sse_active==='controles'?'active':'' ?>">📋 Contrôles</a>
  <a href="/sse/categories.php" class="<?= $sse_active==='categories'?'active':'' ?>">🏷️ Catégories</a>
</div>

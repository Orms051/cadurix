<?php
/**
 * Cadurix v5 — Page de maintenance
 */
$maint_file = __DIR__ . '/config/maintenance.json';
$maint = [];
if (file_exists($maint_file)) {
    $maint = json_decode(file_get_contents($maint_file), true) ?? [];
}
$message   = $maint['message']       ?? 'Nous effectuons une mise à jour pour améliorer votre expérience.';
$retour    = $maint['retour_estime'] ?? '';
$debut     = $maint['debut']         ?? null;
$duree_min = $debut ? round((time() - strtotime($debut)) / 60) : 0;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Cadurix — Maintenance</title>
<meta http-equiv="refresh" content="60">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#05070f;--surface:rgba(255,255,255,.03);
  --border:rgba(255,255,255,.08);--border2:rgba(255,255,255,.13);
  --text:#f0f2ff;--muted:rgba(240,242,255,.45);
  --accent:#6366f1;--accent2:#818cf8;
  --warn:#f59e0b;
  --fd:'Syne',sans-serif;--fb:'DM Sans',sans-serif;
}
body{font-family:var(--fb);background:var(--bg);color:var(--text);min-height:100vh;display:flex;align-items:center;justify-content:center;overflow:hidden}
.bg-orbs{position:fixed;inset:0;z-index:0;pointer-events:none;overflow:hidden}
.orb{position:absolute;border-radius:50%;filter:blur(90px);animation:drift 14s ease-in-out infinite}
.o1{width:600px;height:600px;background:radial-gradient(circle,rgba(99,102,241,.18),transparent 65%);top:-150px;left:-150px;animation-delay:0s}
.o2{width:450px;height:450px;background:radial-gradient(circle,rgba(245,158,11,.1),transparent 65%);bottom:-100px;right:-100px;animation-delay:-6s}
.o3{width:350px;height:350px;background:radial-gradient(circle,rgba(129,140,248,.08),transparent 65%);top:50%;left:50%;transform:translate(-50%,-50%);animation-delay:-11s}
@keyframes drift{0%,100%{transform:translate(0,0)}33%{transform:translate(25px,-18px)}66%{transform:translate(-18px,25px)}}
.gear{position:fixed;pointer-events:none;z-index:0;opacity:.035;line-height:1}
.g1{bottom:-4rem;right:-3rem;font-size:13rem;animation:spin 35s linear infinite}
.g2{top:-3rem;left:-3rem;font-size:9rem;animation:spin 22s linear infinite reverse}
@keyframes spin{from{transform:rotate(0)}to{transform:rotate(360deg)}}

.wrap{position:relative;z-index:1;width:100%;max-width:520px;padding:2rem;animation:fadeUp .9s ease both}
@keyframes fadeUp{from{opacity:0;transform:translateY(28px)}to{opacity:1;transform:translateY(0)}}

.logo{display:flex;align-items:center;gap:14px;margin-bottom:2.5rem;animation:fadeUp .9s ease .1s both}
.logo-mark{width:50px;height:50px;background:linear-gradient(135deg,var(--accent),var(--accent2));border-radius:15px;display:flex;align-items:center;justify-content:center;font-size:23px;box-shadow:0 0 35px rgba(99,102,241,.45)}
.logo-name{font-family:var(--fd);font-size:1.7rem;font-weight:800;letter-spacing:-.5px}
.logo-name em{color:var(--accent2);font-style:normal}

.badge{display:inline-flex;align-items:center;gap:8px;padding:.35rem 1rem;background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.25);border-radius:99px;font-size:.78rem;font-weight:600;color:#fbbf24;margin-bottom:1.75rem;animation:fadeUp .9s ease .2s both}
.bdot{width:7px;height:7px;background:var(--warn);border-radius:50%;animation:blink 1.5s ease-in-out infinite}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.2}}

.card{background:var(--surface);border:1px solid var(--border2);border-radius:24px;padding:2.25rem;backdrop-filter:blur(28px);box-shadow:0 32px 80px rgba(0,0,0,.55),inset 0 1px 0 rgba(255,255,255,.06);margin-bottom:1.25rem;animation:fadeUp .9s ease .3s both}
.card-ico{width:56px;height:56px;background:rgba(99,102,241,.12);border:1px solid rgba(99,102,241,.22);border-radius:16px;display:flex;align-items:center;justify-content:center;font-size:1.6rem;margin-bottom:1.25rem}
.card-title{font-family:var(--fd);font-size:1.4rem;font-weight:700;margin-bottom:.6rem;letter-spacing:-.3px}
.card-msg{color:var(--muted);font-size:.9rem;line-height:1.65;margin-bottom:1.5rem}

.retour{display:flex;align-items:center;gap:10px;padding:.875rem 1.125rem;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:12px;font-size:.85rem;margin-bottom:1.25rem}
.retour-lbl{color:var(--muted)}
.retour-val{font-weight:700;margin-left:auto;color:var(--accent2)}

.duree{display:flex;align-items:center;gap:6px;font-size:.78rem;color:var(--muted);margin-bottom:1.25rem}

.refresh{background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:12px;padding:.875rem 1.125rem}
.refresh-top{display:flex;justify-content:space-between;font-size:.75rem;color:var(--muted);margin-bottom:.6rem}
.refresh-track{height:3px;background:rgba(255,255,255,.06);border-radius:99px;overflow:hidden}
.refresh-fill{height:100%;background:linear-gradient(90deg,var(--accent),var(--accent2));border-radius:99px;animation:fillbar 60s linear forwards}
@keyframes fillbar{from{width:0}to{width:100%}}

.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:.75rem;animation:fadeUp .9s ease .4s both}
.info-box{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:1rem;text-align:center}
.info-ico{font-size:1.4rem;margin-bottom:.4rem}
.info-val{font-size:.85rem;font-weight:600}
.info-lbl{font-size:.7rem;color:var(--muted);margin-top:.2rem}

.footer{margin-top:1.5rem;font-size:.72rem;color:rgba(255,255,255,.18);text-align:center;letter-spacing:1.5px;text-transform:uppercase;animation:fadeUp .9s ease .5s both}
</style>
</head>
<body>
<div class="bg-orbs"><div class="orb o1"></div><div class="orb o2"></div><div class="orb o3"></div></div>
<div class="gear g1">⚙</div>
<div class="gear g2">⚙</div>

<div class="wrap">
  <div class="logo">
    <div class="logo-mark">💊</div>
    <div class="logo-name">Caduri<em>x</em></div>
  </div>

  <div class="badge"><div class="bdot"></div>Maintenance en cours</div>

  <div class="card">
    <div class="card-ico">🔧</div>
    <div class="card-title">Mise à jour en cours</div>
    <div class="card-msg"><?= htmlspecialchars($message) ?></div>

    <?php if ($retour): ?>
    <div class="retour">
      <span>🕐</span>
      <span class="retour-lbl">Retour estimé</span>
      <span class="retour-val"><?= htmlspecialchars($retour) ?></span>
    </div>
    <?php endif; ?>

    <?php if ($duree_min > 0): ?>
    <div class="duree">
      ⏱ En cours depuis
      <?php
        if ($duree_min < 60) echo $duree_min . ' min';
        else echo floor($duree_min/60).'h'.str_pad($duree_min%60,2,'0',STR_PAD_LEFT);
      ?>
    </div>
    <?php endif; ?>

    <div class="refresh">
      <div class="refresh-top"><span>🔄 Rafraîchissement auto</span><span id="cd">60s</span></div>
      <div class="refresh-track"><div class="refresh-fill"></div></div>
    </div>
  </div>

  <div class="info-grid">
    <div class="info-box"><div class="info-ico">🔒</div><div class="info-val">Données sécurisées</div><div class="info-lbl">Chiffrement actif</div></div>
    <div class="info-box"><div class="info-ico">⚡</div><div class="info-val">Bientôt de retour</div><div class="info-lbl">Intervention en cours</div></div>
  </div>

  <div class="footer">Cadurix · Gestion des péremptions · <?= date('Y') ?></div>
</div>

<script>
let s=60; const el=document.getElementById('cd');
const t=setInterval(()=>{s--;if(el)el.textContent=s+'s';if(s<=0)clearInterval(t)},1000);
</script>
</body>
</html>

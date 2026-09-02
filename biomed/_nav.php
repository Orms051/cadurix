<?php
// Barre de navigation commune Biomédical.
// Utilisation : $bio_active = 'portail'|'equipements';  include (chemin)/biomed/_nav.php  APRÈS header.php.
$bio_active = $bio_active ?? '';
?>
<style>
.bio-nav { display:flex; gap:8px; margin-bottom:20px; flex-wrap:wrap; }
.bio-nav a { display:inline-flex; align-items:center; gap:6px; padding:9px 15px; border-radius:10px; font-size:.85rem; font-weight:700; text-decoration:none; background:var(--bg2); border:1px solid var(--border); color:var(--text2); transition:.15s; }
.bio-nav a:hover { border-color:var(--primary); color:var(--primary); }
.bio-nav a.active { background:linear-gradient(135deg,#3b82f6,#8b5cf6); border-color:transparent; color:#fff; box-shadow:0 6px 18px -8px rgba(59,130,246,.6); }
</style>
<div class="bio-nav">
  <a href="/biomed/dashboard.php" class="<?= $bio_active==='portail'?'active':'' ?>">👨‍🔬 Portail</a>
  <a href="/materiel.php" class="<?= $bio_active==='equipements'?'active':'' ?>">🔧 Équipements</a>
</div>

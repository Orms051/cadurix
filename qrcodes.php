<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.
require_once 'includes/auth.php';
requireGestionnaire();

$site_id = site_actif();
if (!$site_id) { header('Location: dashboard.php'); exit; }

// Infos site
$stmt = $pdo->prepare("SELECT s.nom, o.nom AS org_nom FROM sites s JOIN organisations o ON o.id=s.organisation_id WHERE s.id=?");
$stmt->execute([$site_id]);
$site_info = $stmt->fetch(PDO::FETCH_ASSOC);

// ── Emplacements ─────────────────────────────────────────
$stmt = $pdo->prepare("SELECT e.*, ep.nom AS parent_nom FROM emplacements e LEFT JOIN emplacements ep ON ep.id=e.parent_id WHERE e.site_id=? AND e.actif=1 ORDER BY COALESCE(ep.nom,e.nom), e.nom");
$stmt->execute([$site_id]);
$emplacements = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── SSE Équipements ───────────────────────────────────────
$sse_equip = [];
try {
    $stmt = $pdo->prepare("SELECT e.*, c.nom AS cat_nom, c.icone FROM sse_equipements e LEFT JOIN sse_categories c ON c.id=e.categorie_id WHERE e.site_id=? AND e.actif=1 ORDER BY c.nom, e.nom");
    $stmt->execute([$site_id]);
    $sse_equip = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {}

// ── Matériel biomédical ───────────────────────────────────
$biomed = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM materiels WHERE site_id=? AND statut!='reforme' ORDER BY nom");
    $stmt->execute([$site_id]);
    $biomed = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {}

$tab = $_GET['type'] ?? 'empl';
$pageTitle = 'QR Codes';
require_once 'includes/header.php';
?>
<style>
/* ── Tabs ── */
.qr-tabs { display:flex; gap:4px; margin-bottom:20px; border-bottom:2px solid var(--border); }
.qr-tab {
  padding:10px 20px; font-size:.88rem; font-weight:600;
  color:var(--text2); text-decoration:none; border-bottom:2px solid transparent;
  margin-bottom:-2px; transition:.15s; border-radius:8px 8px 0 0;
  cursor:pointer; background:none; border-left:none; border-right:none; border-top:none;
  font-family:inherit;
}
.qr-tab:hover { color:var(--text); background:var(--glass); }
.qr-tab.active { color:var(--primary); border-bottom-color:var(--primary); background:var(--primary-bg); }

/* ── Layout 2 colonnes ── */
.qr-layout { display:grid; grid-template-columns:320px 1fr; gap:20px; align-items:start; }
@media(max-width:900px){ .qr-layout { grid-template-columns:1fr; } }

/* ── Panneau sélection ── */
.qr-panel {
  background:var(--bg2); border:1px solid var(--border); border-radius:14px;
  position:sticky; top:80px; max-height:calc(100vh - 120px); display:flex; flex-direction:column;
}
.qr-panel-head {
  padding:14px 16px; border-bottom:1px solid var(--border);
  display:flex; align-items:center; justify-content:space-between; flex-shrink:0;
}
.qr-panel-title { font-weight:700; font-size:.88rem; }
.qr-panel-actions { display:flex; gap:6px; }
.qr-panel-actions button {
  font-size:.72rem; padding:3px 10px; border-radius:6px; cursor:pointer; font-family:inherit;
  background:var(--glass); border:1px solid var(--border); color:var(--text2); font-weight:600;
}
.qr-panel-actions button:hover { border-color:var(--primary); color:var(--primary); }
.qr-search {
  padding:10px 14px; border-bottom:1px solid var(--border); flex-shrink:0;
}
.qr-search input {
  width:100%; background:var(--bg); border:1px solid var(--border);
  border-radius:8px; padding:7px 12px; color:var(--text); font-family:inherit;
  font-size:.82rem; outline:none;
}
.qr-search input:focus { border-color:var(--primary); }
.qr-list { overflow-y:auto; flex:1; padding:8px; }
.qr-item {
  display:flex; align-items:center; gap:8px; padding:7px 10px;
  border-radius:8px; cursor:pointer; transition:.12s; margin-bottom:2px;
}
.qr-item:hover { background:var(--glass); }
.qr-item.checked { background:var(--primary-bg); }
.qr-item input { accent-color:var(--primary); width:15px; height:15px; flex-shrink:0; cursor:pointer; }
.qr-item-label { font-size:.82rem; font-weight:500; color:var(--text); flex:1; }
.qr-item-sub { font-size:.72rem; color:var(--text3); }
.qr-item-icon { font-size:1rem; flex-shrink:0; }
.qr-panel-foot {
  padding:12px 16px; border-top:1px solid var(--border); flex-shrink:0;
  display:flex; gap:8px; align-items:center;
}
.qr-count { font-size:.78rem; color:var(--text3); flex:1; }

/* ── Options ── */
.qr-options {
  display:flex; gap:12px; margin-bottom:16px; flex-wrap:wrap; align-items:center;
  background:var(--bg2); border:1px solid var(--border); border-radius:10px; padding:12px 16px;
}
.qr-opt-group { display:flex; flex-direction:column; gap:4px; }
.qr-opt-label { font-size:.72rem; font-weight:700; color:var(--text3); text-transform:uppercase; letter-spacing:.5px; }
.qr-opt-group select {
  background:var(--bg); border:1px solid var(--border); color:var(--text);
  border-radius:7px; padding:5px 10px; font-family:inherit; font-size:.82rem; outline:none;
}

/* ── Planche ── */
.planche-wrap { background:#d1d5db; border-radius:12px; padding:20px; }
.planche {
  width:210mm; min-height:200px; background:white; margin:0 auto;
  padding:10mm 5mm; display:flex; flex-wrap:wrap; gap:0; align-content:flex-start;
}
.etiquette {
  width:48mm; height:25mm; padding:2mm 2.5mm; display:flex;
  align-items:center; gap:2mm; border:.3pt dashed #ccc;
  overflow:hidden; page-break-inside:avoid; box-sizing:border-box;
}
.etiq-qr { flex-shrink:0; width:18mm; height:18mm; }
.etiq-qr canvas { width:100%!important; height:100%!important; }
.etiq-info { flex:1; min-width:0; overflow:hidden; }
.etiq-logo { font-size:5pt; color:#ef4444; font-weight:700; margin-bottom:.5mm; }
.etiq-parent { font-size:5.5pt; color:#888; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-bottom:.5mm; }
.etiq-nom { font-size:6.5pt; font-weight:800; color:#111; line-height:1.2; word-break:break-word; margin-bottom:1mm; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
.etiq-id { font-size:5pt; color:#aaa; font-family:monospace; }
.etiq-cold { font-size:6pt; color:#2563eb; }
.etiq-badge { font-size:5pt; font-weight:700; padding:0 3px; border-radius:2px; }

.planche-empty { text-align:center; padding:3rem; color:#999; font-size:.875rem; width:100%; }

@media print {
  @page { size:A4 portrait; margin:0; }
  .no-print { display:none!important; }
  .planche-wrap { padding:0; background:white; border-radius:0; }
  body { background:white; }
  .sidebar,.topbar,footer,.qr-tabs,.qr-panel,.qr-options,.page-header { display:none!important; }
  .main-content { margin:0!important; padding:0!important; }
  .qr-layout { display:block; }
  .etiquette { border:.3pt dashed #ddd; }
}
</style>

<!-- HEADER PAGE -->
<div class="page-header no-print">
  <div class="page-title-group">
    <div class="page-title">🏷️ QR Codes</div>
    <div class="page-sub">Site : <strong><?= h($site_info['org_nom'].' — '.$site_info['nom']) ?></strong></div>
  </div>
  <div style="display:flex;gap:8px">
    <button class="btn btn-primary" onclick="window.print()">🖨️ Imprimer</button>
  </div>
</div>

<!-- ONGLETS -->
<div class="qr-tabs no-print">
  <?php
  $role_actif = role_sur_site($site_id);
  $can_empl   = nav_peut($nav_perms, 'emplacements') || isAdmin();
  $can_sse    = nav_peut($nav_perms, 'sse');
  $can_biomed = nav_peut($nav_perms, 'equipements') || nav_peut($nav_perms, 'biomed') || isAdmin();
  // Déterminer l'onglet par défaut selon le rôle
  if ($tab === 'empl'   && !$can_empl)   $tab = $can_sse ? 'sse' : ($can_biomed ? 'biomed' : 'empl');
  if ($tab === 'sse'    && !$can_sse)    $tab = $can_empl ? 'empl' : ($can_biomed ? 'biomed' : 'empl');
  if ($tab === 'biomed' && !$can_biomed) $tab = $can_empl ? 'empl' : ($can_sse ? 'sse' : 'empl');
  ?>
  <?php if ($can_empl): ?>
  <button class="qr-tab <?= $tab==='empl'?'active':'' ?>" onclick="switchTab('empl')">📦 Emplacements <span id="cnt-empl" style="font-size:.72rem;background:var(--border);border-radius:10px;padding:1px 7px;margin-left:4px"><?= count($emplacements) ?></span></button>
  <?php endif; ?>
  <?php if ($can_sse && !empty($sse_equip)): ?>
  <button class="qr-tab <?= $tab==='sse'?'active':'' ?>" onclick="switchTab('sse')">☣️ SSE / NRBC <span id="cnt-sse" style="font-size:.72rem;background:var(--border);border-radius:10px;padding:1px 7px;margin-left:4px"><?= count($sse_equip) ?></span></button>
  <?php endif; ?>
  <?php if ($can_biomed && !empty($biomed)): ?>
  <button class="qr-tab <?= $tab==='biomed'?'active':'' ?>" onclick="switchTab('biomed')">🔬 Biomédical <span id="cnt-biomed" style="font-size:.72rem;background:var(--border);border-radius:10px;padding:1px 7px;margin-left:4px"><?= count($biomed) ?></span></button>
  <?php endif; ?>
</div>

<!-- OPTIONS -->
<div class="qr-options no-print">
  <div class="qr-opt-group">
    <span class="qr-opt-label">Affichage</span>
    <select id="opt-style" onchange="regenerer()">
      <option value="normal">Standard (Nom + QR)</option>
      <option value="compact">Compact (QR seul)</option>
    </select>
  </div>
  <div class="qr-opt-group">
    <span class="qr-opt-label">Copies</span>
    <select id="opt-copies" onchange="regenerer()">
      <option value="1">× 1</option>
      <option value="2">× 2</option>
      <option value="3">× 3</option>
      <option value="4">× 4</option>
    </select>
  </div>
  <div style="margin-left:auto;font-size:.82rem;color:var(--text3)" id="stat-label">0 étiquette(s)</div>
</div>

<!-- LAYOUT -->
<div class="qr-layout">

  <!-- PANNEAU GAUCHE -->
  <div class="qr-panel no-print">
    <div class="qr-panel-head">
      <span class="qr-panel-title" id="panel-title">Emplacements</span>
      <div class="qr-panel-actions">
        <button onclick="selectAll(true)">Tout</button>
        <button onclick="selectAll(false)">Aucun</button>
      </div>
    </div>
    <div class="qr-search">
      <input type="text" id="qr-search" placeholder="🔍 Rechercher…" oninput="filtrerListe(this.value)">
    </div>
    <div class="qr-list" id="qr-list"></div>
    <div class="qr-panel-foot">
      <span class="qr-count" id="sel-count">0 sélectionné(s)</span>
    </div>
  </div>

  <!-- PLANCHE DROITE -->
  <div>
    <div class="planche-wrap">
      <div class="planche" id="planche">
        <div class="planche-empty">Sélectionnez des éléments à gauche</div>
      </div>
    </div>
  </div>

</div>

<?php
// Données JSON pour le JS
$empl_json  = json_encode($emplacements);
$sse_json   = json_encode($sse_equip);
$biomed_json= json_encode($biomed);
$origin     = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
?>

<script>
/* ── QR Code lib inline ── */
var QRCode=(function(){"use strict";var QR8bitByte=function(d){this.mode=QRMode.MODE_8BIT_BYTE;this.data=d;this.parsedData=[];for(var i=0;i<d.length;i++){var c=d.charCodeAt(i);if(c>0xFF){this.parsedData.push(0xEF,0xBB,0xBF);var b=encodeURIComponent(d.charAt(i)).substr(1).split('%');for(var j=0;j<b.length;j++)this.parsedData.push(parseInt('0x'+b[j],16));}else this.parsedData.push(c);}if(this.parsedData.length!=d.length){this.parsedData.unshift(0xEF,0xBB,0xBF);}};QR8bitByte.prototype={getLength:function(){return this.parsedData.length;},write:function(buf){for(var i=0;i<this.parsedData.length;i++)buf.put(this.parsedData[i],8);}};var QRMode={MODE_NUMBER:1<<0,MODE_ALPHA_NUM:1<<1,MODE_8BIT_BYTE:1<<2,MODE_KANJI:1<<3};var QRErrorCorrectLevel={L:1,M:0,Q:3,H:2};var QRMaskPattern={PATTERN000:0,PATTERN001:1,PATTERN010:2,PATTERN011:3,PATTERN100:4,PATTERN101:5,PATTERN110:6,PATTERN111:7};var QRUtil={PATTERN_POSITION_TABLE:[[],[6,18],[6,22],[6,26],[6,30],[6,34],[6,22,38],[6,24,42],[6,26,46],[6,28,50],[6,30,54],[6,32,58],[6,34,62],[6,26,46,66],[6,26,48,70],[6,26,50,74],[6,30,54,78],[6,30,56,82],[6,30,58,86],[6,34,62,90],[6,28,50,72,94],[6,26,50,74,98],[6,30,54,78,102],[6,28,54,80,106],[6,32,58,84,110],[6,30,58,86,114],[6,34,62,90,118],[6,26,50,74,98,122],[6,30,54,78,102,126],[6,26,52,78,104,130],[6,30,56,82,108,132],[6,34,60,86,112,136],[6,30,58,86,114,142],[6,34,62,90,118,146],[6,30,54,78,102,126,150],[6,24,50,76,102,128,154],[6,28,54,80,106,132,158],[6,32,58,84,110,136,162],[6,26,54,82,110,138,166],[6,30,58,86,114,142,170]],G15:1<<10|1<<8|1<<5|1<<4|1<<2|1<<1|1<<0,G18:1<<12|1<<11|1<<10|1<<9|1<<8|1<<5|1<<2|1<<0,G15_MASK:1<<14|1<<12|1<<10|1<<4|1<<1,getBCHTypeInfo:function(d){var r=d<<10;while(QRUtil.getBCHDigit(r)-QRUtil.getBCHDigit(QRUtil.G15)>=0)r^=QRUtil.G15<<(QRUtil.getBCHDigit(r)-QRUtil.getBCHDigit(QRUtil.G15));return((d<<10)|r)^QRUtil.G15_MASK;},getBCHTypeNumber:function(d){var r=d<<12;while(QRUtil.getBCHDigit(r)-QRUtil.getBCHDigit(QRUtil.G18)>=0)r^=QRUtil.G18<<(QRUtil.getBCHDigit(r)-QRUtil.getBCHDigit(QRUtil.G18));return(d<<12)|r;},getBCHDigit:function(d){var r=0;while(d!=0){r++;d>>>=1;}return r;},getPatternPosition:function(v){return QRUtil.PATTERN_POSITION_TABLE[v-1];},getMask:function(mp,i,j){switch(mp){case QRMaskPattern.PATTERN000:return(i+j)%2==0;case QRMaskPattern.PATTERN001:return i%2==0;case QRMaskPattern.PATTERN010:return j%3==0;case QRMaskPattern.PATTERN011:return(i+j)%3==0;case QRMaskPattern.PATTERN100:return(Math.floor(i/2)+Math.floor(j/3))%2==0;case QRMaskPattern.PATTERN101:return(i*j)%2+(i*j)%3==0;case QRMaskPattern.PATTERN110:return((i*j)%2+(i*j)%3)%2==0;case QRMaskPattern.PATTERN111:return((i*j)%3+(i+j)%2)%2==0;}throw new Error("bad pattern:"+mp);},getErrorCorrectPolynomial:function(l){var a=new QRPolynomial([1],0);for(var i=0;i<l;i++)a=a.multiply(new QRPolynomial([1,QRMath.gexp(i)],0));return a;},getLengthInBits:function(m,v){if(1<=v&&v<10){switch(m){case QRMode.MODE_NUMBER:return 10;case QRMode.MODE_ALPHA_NUM:return 9;case QRMode.MODE_8BIT_BYTE:return 8;case QRMode.MODE_KANJI:return 8;}}else if(v<27){switch(m){case QRMode.MODE_NUMBER:return 12;case QRMode.MODE_ALPHA_NUM:return 11;case QRMode.MODE_8BIT_BYTE:return 16;case QRMode.MODE_KANJI:return 10;}}else{switch(m){case QRMode.MODE_NUMBER:return 14;case QRMode.MODE_ALPHA_NUM:return 13;case QRMode.MODE_8BIT_BYTE:return 16;case QRMode.MODE_KANJI:return 12;}}throw new Error("type:"+m+"/"+v);},getLostPoint:function(qrcode){var ml=qrcode.getModuleCount(),lp=0,row,col;for(row=0;row<ml;row++){for(col=0;col<ml;col++){var sc=0,d=qrcode.isDark(row,col);for(var r=-1;r<=1;r++){if(row+r<0||ml<=row+r)continue;for(var c=-1;c<=1;c++){if(col+c<0||ml<=col+c)continue;if(r==0&&c==0)continue;if(d==qrcode.isDark(row+r,col+c))sc++;}}if(sc>5)lp+=(3+sc-5);}}for(row=0;row<ml-1;row++){for(col=0;col<ml-1;col++){var count=0;if(qrcode.isDark(row,col))count++;if(qrcode.isDark(row+1,col))count++;if(qrcode.isDark(row,col+1))count++;if(qrcode.isDark(row+1,col+1))count++;if(count==0||count==4)lp+=3;}}for(row=0;row<ml;row++){for(col=0;col<ml-6;col++){if(qrcode.isDark(row,col)&&!qrcode.isDark(row,col+1)&&qrcode.isDark(row,col+2)&&qrcode.isDark(row,col+3)&&qrcode.isDark(row,col+4)&&!qrcode.isDark(row,col+5)&&qrcode.isDark(row,col+6))lp+=40;}}for(col=0;col<ml;col++){for(row=0;row<ml-6;row++){if(qrcode.isDark(row,col)&&!qrcode.isDark(row+1,col)&&qrcode.isDark(row+2,col)&&qrcode.isDark(row+3,col)&&qrcode.isDark(row+4,col)&&!qrcode.isDark(row+5,col)&&qrcode.isDark(row+6,col))lp+=40;}}var dc=0;for(row=0;row<ml;row++)for(col=0;col<ml;col++)if(qrcode.isDark(row,col))dc++;var ra=Math.abs(100*dc/ml/ml-50)/5;lp+=ra*10;return lp;}};var QRMath={glog:function(n){if(n<1)throw new Error("glog("+n+")");return QRMath.LOG_TABLE[n];},gexp:function(n){while(n<0)n+=255;while(n>=256)n-=255;return QRMath.EXP_TABLE[n];},EXP_TABLE:new Array(256),LOG_TABLE:new Array(256)};for(var i=0;i<8;i++)QRMath.EXP_TABLE[i]=1<<i;for(var i=8;i<256;i++)QRMath.EXP_TABLE[i]=QRMath.EXP_TABLE[i-4]^QRMath.EXP_TABLE[i-5]^QRMath.EXP_TABLE[i-6]^QRMath.EXP_TABLE[i-8];for(var i=0;i<255;i++)QRMath.LOG_TABLE[QRMath.EXP_TABLE[i]]=i;var QRPolynomial=function(num,shift){if(num.length==undefined)throw new Error(num.length+"/"+shift);var offset=0;while(offset<num.length&&num[offset]==0)offset++;this.num=new Array(num.length-offset+shift);for(var i=0;i<num.length-offset;i++)this.num[i]=num[i+offset];};QRPolynomial.prototype={get:function(i){return this.num[i];},getLength:function(){return this.num.length;},multiply:function(e){var num=new Array(this.getLength()+e.getLength()-1);for(var i=0;i<this.getLength();i++)for(var j=0;j<e.getLength();j++)num[i+j]^=QRMath.gexp(QRMath.glog(this.get(i))+QRMath.glog(e.get(j)));return new QRPolynomial(num,0);},mod:function(e){if(this.getLength()-e.getLength()<0)return this;var r=QRMath.glog(this.get(0))-QRMath.glog(e.get(0));var num=new Array(this.getLength());for(var i=0;i<this.getLength();i++)num[i]=this.get(i);for(var i=0;i<e.getLength();i++)num[i]^=QRMath.gexp(QRMath.glog(e.get(i))+r);return new QRPolynomial(num,0).mod(e);}};var QRRSBlock=function(tc,dc){this.totalCount=tc;this.dataCount=dc;};QRRSBlock.RS_BLOCK_TABLE=[[1,26,19],[1,26,16],[1,26,13],[1,26,9],[1,44,34],[1,44,28],[1,44,22],[1,44,16],[1,70,55],[1,70,44],[2,35,17],[2,35,13],[1,100,80],[2,50,32],[2,50,24],[4,25,9],[1,134,108],[2,67,43],[2,33,15,2,34,16],[2,33,11,2,34,12],[2,86,68],[4,43,27],[4,43,19],[4,43,15],[2,98,78],[4,49,31],[2,32,14,4,33,15],[4,39,13,1,40,14],[2,121,97],[2,60,38,2,61,39],[4,40,18,2,41,19],[4,40,14,2,41,15],[2,146,116],[3,58,36,2,59,37],[4,36,16,4,37,17],[4,36,12,4,37,13],[2,86,68,2,87,69],[4,69,43,1,70,44],[6,43,19,2,44,20],[6,43,15,2,44,16],[4,101,81],[1,80,50,4,81,51],[4,50,22,4,51,23],[3,36,12,8,37,13],[2,116,92,2,117,93],[6,58,36,2,59,37],[4,46,20,6,47,21],[7,42,14,4,43,15],[4,133,107],[8,59,37,1,60,38],[8,44,20,4,45,21],[12,33,11,4,34,12],[3,145,115,1,146,116],[4,64,40,5,65,41],[11,36,16,5,37,17],[11,36,12,5,37,13],[5,109,87,1,110,88],[5,65,41,5,66,42],[5,54,24,7,55,25],[11,36,12],[5,122,98,1,123,99],[7,73,45,3,74,46],[15,43,19,2,44,20],[3,45,15,13,46,16],[1,135,107,5,136,108],[10,74,46,1,75,47],[1,50,22,15,51,23],[2,42,14,17,43,15],[5,150,120,1,151,121],[9,69,43,4,70,44],[17,50,22,1,51,23],[2,42,14,19,43,15],[3,141,113,4,142,114],[3,70,44,11,71,45],[17,47,21,4,48,22],[9,39,13,16,40,14],[3,135,107,5,136,108],[3,67,41,13,68,42],[15,54,24,5,55,25],[15,43,15,10,44,16],[4,144,116,4,145,117],[17,68,42],[17,50,22,6,51,23],[19,46,16,6,47,17],[2,139,111,7,140,112],[17,74,46],[7,54,24,16,55,25],[34,37,13],[4,151,121,5,152,122],[4,75,47,14,76,48],[11,54,24,14,55,25],[16,45,15,14,46,16],[6,147,117,4,148,118],[6,73,45,14,74,46],[11,54,24,16,55,25],[30,46,16,2,47,17],[8,132,106,4,133,107],[8,75,47,13,76,48],[7,54,24,22,55,25],[22,45,15,13,46,16],[10,142,114,2,143,115],[19,74,46,4,75,47],[28,50,22,6,51,23],[33,46,16,4,47,17],[8,152,122,4,153,123],[22,73,45,3,74,46],[8,53,23,26,54,24],[12,45,15,28,46,16],[3,147,117,10,148,118],[3,73,45,23,74,46],[4,54,24,31,55,25],[11,45,15,31,46,16],[7,146,116,7,147,117],[21,73,45,7,74,46],[1,53,23,37,54,24],[19,45,15,26,46,16],[5,145,115,10,146,116],[19,75,47,10,76,48],[15,54,24,25,55,25],[23,45,15,25,46,16],[13,145,115,3,146,116],[2,74,46,29,75,47],[42,54,24,1,55,25],[23,45,15,28,46,16],[17,145,115],[10,74,46,23,75,47],[10,54,24,35,55,25],[19,45,15,35,46,16],[17,145,115,1,146,116],[14,74,46,21,75,47],[29,54,24,19,55,25],[11,45,15,46,46,16],[13,145,115,6,146,116],[14,74,46,23,75,47],[44,54,24,7,55,25],[59,46,16,1,47,17],[12,151,121,7,152,122],[12,75,47,26,76,48],[39,54,24,14,55,25],[22,45,15,41,46,16],[6,151,121,14,152,122],[6,75,47,34,76,48],[46,54,24,10,55,25],[2,45,15,64,46,16],[17,152,122,4,153,123],[29,74,46,14,75,47],[49,54,24,10,55,25],[24,45,15,46,46,16],[4,152,122,18,153,123],[13,74,46,32,75,47],[48,54,24,14,55,25],[42,45,15,32,46,16],[20,147,117,4,148,118],[40,75,47,7,76,48],[43,54,24,22,55,25],[10,45,15,67,46,16],[19,148,118,6,149,119],[18,75,47,31,76,48],[34,54,24,34,55,25],[20,45,15,61,46,16]];QRRSBlock.getRSBlocks=function(tv,ecl){var d=QRRSBlock.getRsBlockTable(tv,ecl);if(d==undefined)throw new Error("bad rs block @ typeNumber:"+tv+"/errorCorrectLevel:"+ecl);var l=d.length/3,list=[];for(var i=0;i<l;i++){var c=d[i*3],tb=d[i*3+1],db=d[i*3+2];for(var j=0;j<c;j++)list.push(new QRRSBlock(tb,db));}return list;};QRRSBlock.getRsBlockTable=function(tv,ecl){switch(ecl){case QRErrorCorrectLevel.L:return QRRSBlock.RS_BLOCK_TABLE[(tv-1)*4];case QRErrorCorrectLevel.M:return QRRSBlock.RS_BLOCK_TABLE[(tv-1)*4+1];case QRErrorCorrectLevel.Q:return QRRSBlock.RS_BLOCK_TABLE[(tv-1)*4+2];case QRErrorCorrectLevel.H:return QRRSBlock.RS_BLOCK_TABLE[(tv-1)*4+3];}};var QRBitBuffer=function(){this.buffer=[];this.length=0;};QRBitBuffer.prototype={get:function(i){var bl=Math.floor(i/8);return((this.buffer[bl]>>>(7-i%8))&1)==1;},put:function(d,l){for(var i=0;i<l;i++)this.putBit(((d>>>(l-i-1))&1)==1);},getLengthInBits:function(){return this.length;},putBit:function(b){var bl=Math.floor(this.length/8);if(this.buffer.length<=bl)this.buffer.push(0);if(b)this.buffer[bl]|=0x80>>>(this.length%8);this.length++;}};var QRCodeModel=function(tv,ecl){this.typeNumber=tv;this.errorCorrectLevel=ecl;this.modules=null;this.moduleCount=0;this.dataCache=null;this.dataList=[];};QRCodeModel.prototype={addData:function(d){var nd=new QR8bitByte(d);this.dataList.push(nd);this.dataCache=null;},isDark:function(r,c){if(r<0||this.moduleCount<=r||c<0||this.moduleCount<=c)throw new Error(r+","+c);return this.modules[r][c];},getModuleCount:function(){return this.moduleCount;},make:function(){this.makeImpl(false,this.getBestMaskPattern());},makeImpl:function(t,mp){this.moduleCount=this.typeNumber*4+17;this.modules=new Array(this.moduleCount);for(var row=0;row<this.moduleCount;row++){this.modules[row]=new Array(this.moduleCount);for(var col=0;col<this.moduleCount;col++)this.modules[row][col]=null;}this.setupPositionProbePattern(0,0);this.setupPositionProbePattern(this.moduleCount-7,0);this.setupPositionProbePattern(0,this.moduleCount-7);this.setupPositionAdjustPattern();this.setupTimingPattern();this.setupTypeInfo(t,mp);if(this.typeNumber>=7)this.setupTypeNumber(t);if(this.dataCache==null)this.dataCache=QRCodeModel.createData(this.typeNumber,this.errorCorrectLevel,this.dataList);this.mapData(this.dataCache,mp);},setupPositionProbePattern:function(row,col){for(var r=-1;r<=7;r++){if(row+r<=-1||this.moduleCount<=row+r)continue;for(var c=-1;c<=7;c++){if(col+c<=-1||this.moduleCount<=col+c)continue;if((0<=r&&r<=6&&(c==0||c==6))||(0<=c&&c<=6&&(r==0||r==6))||(2<=r&&r<=4&&2<=c&&c<=4))this.modules[row+r][col+c]=true;else this.modules[row+r][col+c]=false;}}},getBestMaskPattern:function(){var mp=0,ml=0;for(var i=0;i<8;i++){this.makeImpl(true,i);var lp=QRUtil.getLostPoint(this);if(i==0||ml>lp){ml=lp;mp=i;}}return mp;},setupTimingPattern:function(){for(var r=8;r<this.moduleCount-8;r++){if(this.modules[r][6]!=null)continue;this.modules[r][6]=(r%2==0);}for(var c=8;c<this.moduleCount-8;c++){if(this.modules[6][c]!=null)continue;this.modules[6][c]=(c%2==0);}},setupPositionAdjustPattern:function(){var pos=QRUtil.getPatternPosition(this.typeNumber);for(var i=0;i<pos.length;i++){for(var j=0;j<pos.length;j++){var row=pos[i],col=pos[j];if(this.modules[row][col]!=null)continue;for(var r=-2;r<=2;r++){for(var c=-2;c<=2;c++){if(r==-2||r==2||c==-2||c==2||(r==0&&c==0))this.modules[row+r][col+c]=true;else this.modules[row+r][col+c]=false;}}}}},setupTypeNumber:function(t){var bi=QRUtil.getBCHTypeNumber(this.typeNumber);for(var i=0;i<18;i++){var md=((!t)&&((bi>>i)&1)==1);this.modules[Math.floor(i/3)][i%3+this.moduleCount-8-3]=md;}for(var i=0;i<18;i++){var md=((!t)&&((bi>>i)&1)==1);this.modules[i%3+this.moduleCount-8-3][Math.floor(i/3)]=md;}},setupTypeInfo:function(t,mp){var d=(this.errorCorrectLevel<<3)|mp,bi=QRUtil.getBCHTypeInfo(d);for(var i=0;i<15;i++){var md=((!t)&&((bi>>i)&1)==1);if(i<6)this.modules[i][8]=md;else if(i<8)this.modules[i+1][8]=md;else this.modules[this.moduleCount-15+i][8]=md;}for(var i=0;i<15;i++){var md=((!t)&&((bi>>i)&1)==1);if(i<8)this.modules[8][this.moduleCount-i-1]=md;else if(i<9)this.modules[8][15-i-1+1]=md;else this.modules[8][15-i-1]=md;}this.modules[this.moduleCount-8][8]=(!t);},mapData:function(data,mp){var inc=-1,row=this.moduleCount-1,bitIndex=7,byteIndex=0;for(var col=this.moduleCount-1;col>0;col-=2){if(col==6)col--;while(true){for(var c=0;c<2;c++){if(this.modules[row][col-c]==null){var d=false;if(byteIndex<data.length)d=((data[byteIndex]>>bitIndex)&1)==1;if(QRUtil.getMask(mp,row,col-c))d=!d;this.modules[row][col-c]=d;bitIndex--;if(bitIndex==-1){byteIndex++;bitIndex=7;}}}row+=inc;if(row<0||this.moduleCount<=row){row-=inc;inc=-inc;break;}}}}};QRCodeModel.PAD0=0xEC;QRCodeModel.PAD1=0x11;QRCodeModel.createData=function(tv,ecl,dl){var rsb=QRRSBlock.getRSBlocks(tv,ecl),buf=new QRBitBuffer();for(var i=0;i<dl.length;i++){var d=dl[i];buf.put(d.mode,4);buf.put(d.getLength(),QRUtil.getLengthInBits(d.mode,tv));d.write(buf);}var tc=0;for(var i=0;i<rsb.length;i++)tc+=rsb[i].dataCount;if(buf.getLengthInBits()>tc*8)throw new Error("code length overflow. ("+buf.getLengthInBits()+">"+tc*8+")");if(buf.getLengthInBits()+4<=tc*8)buf.put(0,4);while(buf.getLengthInBits()%8!=0)buf.putBit(false);while(true){if(buf.getLengthInBits()>=tc*8)break;buf.put(QRCodeModel.PAD0,8);if(buf.getLengthInBits()>=tc*8)break;buf.put(QRCodeModel.PAD1,8);}return QRCodeModel.createBytes(buf,rsb);};QRCodeModel.createBytes=function(buf,rsb){var offset=0,maxDcCount=0,maxEcCount=0,dcdata=new Array(rsb.length),ecdata=new Array(rsb.length);for(var r=0;r<rsb.length;r++){var dcCount=rsb[r].dataCount,ecCount=rsb[r].totalCount-dcCount;maxDcCount=Math.max(maxDcCount,dcCount);maxEcCount=Math.max(maxEcCount,ecCount);dcdata[r]=new Array(dcCount);for(var i=0;i<dcdata[r].length;i++)dcdata[r][i]=0xff&buf.buffer[i+offset];offset+=dcCount;var rsPoly=QRUtil.getErrorCorrectPolynomial(ecCount),rawPoly=new QRPolynomial(dcdata[r],rsPoly.getLength()-1),modPoly=rawPoly.mod(rsPoly);ecdata[r]=new Array(rsPoly.getLength()-1);for(var i=0;i<ecdata[r].length;i++){var mi=i+modPoly.getLength()-ecdata[r].length;ecdata[r][i]=(mi>=0)?modPoly.get(mi):0;}}var totalCodeCount=0;for(var i=0;i<rsb.length;i++)totalCodeCount+=rsb[i].totalCount;var data=new Array(totalCodeCount),index=0;for(var i=0;i<maxDcCount;i++)for(var r=0;r<rsb.length;r++)if(i<dcdata[r].length)data[index++]=dcdata[r][i];for(var i=0;i<maxEcCount;i++)for(var r=0;r<rsb.length;r++)if(i<ecdata[r].length)data[index++]=ecdata[r][i];return data;};var Drawing=function(el,opt){this._el=el;this._opt=opt;};Drawing.prototype={draw:function(qr){var opt=this._opt,el=this._el;var cv=document.createElement('canvas');cv.width=opt.width;cv.height=opt.height;el.appendChild(cv);var ctx=cv.getContext('2d');var cnt=qr.getModuleCount();var tileW=opt.width/cnt,tileH=opt.height/cnt;for(var row=0;row<cnt;row++){for(var col=0;col<cnt;col++){ctx.fillStyle=qr.isDark(row,col)?opt.colorDark:opt.colorLight;var w=Math.ceil((col+1)*tileW)-Math.floor(col*tileW);var h=Math.ceil((row+1)*tileH)-Math.floor(row*tileH);ctx.fillRect(Math.round(col*tileW),Math.round(row*tileH),w,h);}}},clear:function(){this._el.innerHTML='';}};function _typeNumber(t,e){var d=new QR8bitByte(t),l=d.getLength()*8+4+QRUtil.getLengthInBits(QRMode.MODE_8BIT_BYTE,1);for(var v=1;v<=40;v++){var rsb=QRRSBlock.getRSBlocks(v,e);var buf=new QRBitBuffer();var tc=0;for(var i=0;i<rsb.length;i++)tc+=rsb[i].dataCount;if(tc*8>=l)return v;}return 40;}function QRCode(el,opt){if(typeof opt==='string')opt={text:opt};this._opt=Object.assign({width:256,height:256,colorDark:'#000000',colorLight:'#ffffff',correctLevel:QRErrorCorrectLevel.H},opt);this._oDrawing=new Drawing(el,this._opt);if(this._opt.text)this.makeCode(this._opt.text);}QRCode.prototype={makeCode:function(t){var tv=_typeNumber(t,this._opt.correctLevel);this._oQRCode=new QRCodeModel(tv,this._opt.correctLevel);this._oQRCode.addData(t);this._oQRCode.make();this._oDrawing.clear();this._oDrawing.draw(this._oQRCode);}};QRCode.CorrectLevel=QRErrorCorrectLevel;return QRCode;})();

// ── Données ──────────────────────────────────────────────
const DATA = {
  empl:   <?= $empl_json ?>,
  sse:    <?= $sse_json ?>,
  biomed: <?= $biomed_json ?>,
};
const ORIGIN = '<?= $origin ?>';
let currentTab = '<?= $tab ?>';
let selected = {};  // { tab: Set<id> }
['empl','sse','biomed'].forEach(t => selected[t] = new Set());

// Tout sélectionner par défaut sur l'onglet initial
DATA[currentTab].forEach(item => selected[currentTab].add(item.id));

// ── Onglets ───────────────────────────────────────────────
function switchTab(tab) {
  currentTab = tab;
  document.querySelectorAll('.qr-tab').forEach(el => el.classList.toggle('active', el.onclick.toString().includes(`'${tab}'`)));
  document.getElementById('qr-search').value = '';
  renderList('');
  regenerer();
}

// ── Liste sélection ───────────────────────────────────────
function renderList(q) {
  const list = document.getElementById('qr-list');
  const items = DATA[currentTab].filter(item => {
    const nom = (item.nom || '').toLowerCase();
    const parent = (item.parent_nom || item.cat_nom || '').toLowerCase();
    return !q || nom.includes(q) || parent.includes(q);
  });

  if (!items.length) {
    list.innerHTML = '<div style="padding:1rem;text-align:center;color:var(--text3);font-size:.82rem">Aucun résultat</div>';
    return;
  }

  list.innerHTML = items.map(item => {
    const checked = selected[currentTab].has(item.id);
    const icon = currentTab === 'empl' ? (item.chaine_froid ? '❄️' : '📦')
               : currentTab === 'sse'  ? (item.icone || '⚙️')
               : '🔬';
    const sub = item.parent_nom || item.cat_nom || '';
    return `<label class="qr-item ${checked?'checked':''}" id="item-${item.id}">
      <input type="checkbox" ${checked?'checked':''} onchange="toggleItem(${item.id}, this.checked)" style="display:none">
      <span class="qr-item-icon">${icon}</span>
      <div style="flex:1;min-width:0">
        <div class="qr-item-label">${escHtml(item.nom)}</div>
        ${sub ? `<div class="qr-item-sub">↳ ${escHtml(sub)}</div>` : ''}
      </div>
      <span style="font-size:.7rem;color:${checked?'var(--primary)':'var(--border)'};font-weight:700">${checked?'✓':''}</span>
    </label>`;
  }).join('');

  updateCount();
}

function toggleItem(id, checked) {
  if (checked) selected[currentTab].add(id);
  else selected[currentTab].delete(id);
  const el = document.getElementById('item-'+id);
  if (el) el.classList.toggle('checked', checked);
  updateCount();
  regenerer();
}

function selectAll(val) {
  DATA[currentTab].forEach(item => {
    if (val) selected[currentTab].add(item.id);
    else selected[currentTab].delete(item.id);
  });
  renderList(document.getElementById('qr-search').value.toLowerCase());
  regenerer();
}

function filtrerListe(q) {
  renderList(q.toLowerCase());
}

function updateCount() {
  const n = selected[currentTab].size;
  document.getElementById('sel-count').textContent = `${n} sélectionné(s)`;
}

// ── Génération planche ────────────────────────────────────
function regenerer() {
  const style  = document.getElementById('opt-style').value;
  const copies = parseInt(document.getElementById('opt-copies').value);
  const planche = document.getElementById('planche');
  planche.innerHTML = '';

  const ids = selected[currentTab];
  if (!ids.size) {
    planche.innerHTML = '<div class="planche-empty">Sélectionnez des éléments à gauche</div>';
    document.getElementById('stat-label').textContent = '0 étiquette(s)';
    return;
  }

  const items = DATA[currentTab].filter(item => ids.has(item.id));
  let total = 0;

  items.forEach(item => {
    for (let c = 0; c < copies; c++) {
      const etiq = document.createElement('div');
      etiq.className = 'etiquette';

      // QR
      const qrWrap = document.createElement('div');
      qrWrap.className = 'etiq-qr';
      qrWrap.style.cssText = 'width:68px;height:68px;flex-shrink:0';

      let qrUrl;
      if (currentTab === 'empl')   qrUrl = `${ORIGIN}/scan.php?empl=${item.id}`;
      else if (currentTab === 'sse') qrUrl = `${ORIGIN}/sse/controles.php?equipement=${item.id}`;
      else qrUrl = `${ORIGIN}/materiel.php?id=${item.id}`;

      try {
        new QRCode(qrWrap, { text: qrUrl, width: 68, height: 68, colorDark: '#111', colorLight: '#fff', correctLevel: QRCode.CorrectLevel.M });
      } catch(e) {
        qrWrap.innerHTML = '<div style="width:68px;height:68px;background:#eee;display:flex;align-items:center;justify-content:center;font-size:8px;color:#999">ERR</div>';
      }

      // Infos
      const info = document.createElement('div');
      info.className = 'etiq-info';
      const icon = currentTab === 'empl' ? (item.chaine_froid ? '❄️' : '📦')
                 : currentTab === 'sse'  ? (item.icone || '⚙️') : '🔬';
      const sub  = item.parent_nom || item.cat_nom || '';
      const type = currentTab === 'empl' ? 'EMPL' : currentTab === 'sse' ? 'SSE' : 'BIO';

      if (style === 'normal') {
        info.innerHTML = `
          <div class="etiq-logo">💊 Cadurix <span class="etiq-badge" style="background:${currentTab==='sse'?'#7c3aed':currentTab==='biomed'?'#0ea5e9':'#ef4444'};color:white">${type}</span></div>
          ${sub ? `<div class="etiq-parent">${escHtml(sub)}</div>` : ''}
          <div class="etiq-nom">${icon} ${escHtml(item.nom)}</div>
          ${item.numero_serie ? `<div class="etiq-id">N° ${escHtml(item.numero_serie)}</div>` : `<div class="etiq-id">#${item.id}</div>`}
        `;
      } else {
        info.innerHTML = `<div class="etiq-nom">${escHtml(item.nom)}</div><div class="etiq-id">#${item.id}</div>`;
      }

      etiq.appendChild(qrWrap);
      etiq.appendChild(info);
      planche.appendChild(etiq);
      total++;
    }
  });

  document.getElementById('stat-label').textContent = `${total} étiquette(s)`;
}

function escHtml(s) {
  return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ── Init ─────────────────────────────────────────────────
window.addEventListener('load', () => {
  renderList('');
  regenerer();
});
</script>

<?php require_once 'includes/footer.php'; ?>

</main>

<div id="toast-container" style="position:fixed;bottom:24px;right:24px;z-index:9999;display:flex;flex-direction:column;gap:10px;pointer-events:none;max-width:320px;"></div>

<!-- Panneau notifications -->
<div id="notif-panel" style="display:none;position:fixed;top:60px;right:16px;z-index:9998;width:320px;background:var(--bg2);border:1px solid var(--border2);border-radius:var(--radius);box-shadow:0 16px 48px rgba(0,0,0,.6);overflow:hidden">
  <div style="padding:12px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
    <span style="font-size:.875rem;font-weight:700">🔔 Notifications</span>
    <button onclick="document.getElementById('notif-panel').style.display='none'" style="background:none;border:none;color:var(--text2);cursor:pointer;font-size:1.1rem">✕</button>
  </div>
  <div id="notif-list" style="max-height:320px;overflow-y:auto;padding:8px"></div>
</div>

<script>
// ── Auto-dismiss flash ────────────────────────────────
document.querySelectorAll('.flash').forEach(el => {
  setTimeout(() => {
    el.style.transition = 'opacity .5s, transform .5s';
    el.style.opacity    = '0';
    el.style.transform  = 'translateY(-6px)';
    setTimeout(() => el.remove(), 500);
  }, 5000);
});

// ── Confirm dialogs ───────────────────────────────────
document.addEventListener('click', function(e) {
  const el = e.target.closest('[data-confirm]');
  if (el && !confirm(el.dataset.confirm)) e.preventDefault();
});

// ── Toast system ──────────────────────────────────────
function showToast(message, type = 'success', duration = 4000) {
  const colors = {
    success: { bg:'rgba(16,185,129,.15)',  border:'rgba(16,185,129,.3)',  color:'#34d399', icon:'✅' },
    error:   { bg:'rgba(239,68,68,.15)',   border:'rgba(239,68,68,.3)',   color:'#f87171', icon:'❌' },
    warning: { bg:'rgba(245,158,11,.15)',  border:'rgba(245,158,11,.3)',  color:'#fcd34d', icon:'⚠️' },
    info:    { bg:'rgba(59,130,246,.15)',  border:'rgba(59,130,246,.3)',  color:'#60a5fa', icon:'ℹ️' },
  };
  const c = colors[type] || colors.info;
  const toast = document.createElement('div');
  toast.style.cssText = `pointer-events:all;display:flex;align-items:center;gap:10px;padding:12px 16px;background:${c.bg};border:1px solid ${c.border};border-radius:10px;color:${c.color};font-size:13px;font-weight:500;font-family:'Plus Jakarta Sans',sans-serif;box-shadow:0 8px 32px rgba(0,0,0,.4);opacity:0;transform:translateY(10px);transition:all .3s cubic-bezier(.16,1,.3,1);backdrop-filter:blur(12px);`;
  toast.innerHTML = `<span style="font-size:16px;flex-shrink:0">${c.icon}</span><span>${message}</span>`;
  document.getElementById('toast-container').appendChild(toast);
  requestAnimationFrame(() => { toast.style.opacity='1'; toast.style.transform='translateY(0)'; });
  setTimeout(() => {
    toast.style.opacity='0'; toast.style.transform='translateY(10px)';
    setTimeout(() => toast.remove(), 300);
  }, duration);
}

// ── Notifications temps réel ──────────────────────────
let notifCount = 0;
function checkNotifications() {
  fetch('/api/notifications.php')
    .then(r => r.json())
    .then(data => {
      notifCount = data.count || 0;
      const badge = document.getElementById('notif-badge');
      if (badge) { badge.textContent = notifCount > 0 ? notifCount : ''; badge.style.display = notifCount > 0 ? 'inline' : 'none'; }
      updateNotifPanel(data.items || []);
    }).catch(() => {});
}
function updateNotifPanel(items) {
  const list = document.getElementById('notif-list');
  if (!list) return;
  if (items.length === 0) { list.innerHTML = '<div style="padding:20px;text-align:center;color:var(--text3);font-size:.875rem">Aucune notification</div>'; return; }
  list.innerHTML = items.map(n => `<a href="${n.lien||'#'}" style="display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:8px;text-decoration:none;transition:background .15s;margin-bottom:2px" onmouseover="this.style.background='rgba(255,255,255,.05)'" onmouseout="this.style.background='transparent'"><span style="font-size:1.2rem;flex-shrink:0">${n.icone||'🔔'}</span><div style="flex:1;min-width:0"><div style="font-size:.82rem;font-weight:600;color:var(--text)">${n.titre}</div><div style="font-size:.75rem;color:var(--text2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${n.message||''}</div></div><div style="font-size:.7rem;color:var(--text3);flex-shrink:0">${n.temps||''}</div></a>`).join('');
}
function toggleNotifPanel() {
  const panel = document.getElementById('notif-panel');
  panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
}
checkNotifications();
setInterval(checkNotifications, 30000);

// ── Déconnexion automatique ───────────────────────────
(function() {
  const timeoutMin = <?php echo (int)($_SESSION['_timeout_minutes'] ?? 30); ?>;
  const timeoutMs  = timeoutMin * 60 * 1000;
  const warnMs     = 5 * 60 * 1000;
  const exempt = ['scan.php', 'terrain.php'];
  const page   = window.location.pathname.split('/').pop();
  if (exempt.some(p => page.includes(p))) return;
  let timer, warnTimer, warned = false;
  const popup = document.createElement('div');
  popup.id = 'timeout-popup';
  popup.style.cssText = 'display:none;position:fixed;bottom:80px;right:20px;z-index:99999;background:var(--bg2);border:1px solid var(--border2);border-radius:14px;padding:16px 20px;max-width:320px;box-shadow:0 8px 32px rgba(0,0,0,.5)';
  popup.innerHTML = `<div style="display:flex;align-items:center;gap:10px;margin-bottom:10px"><span style="font-size:1.4rem">⏱️</span><div><div style="font-weight:700;font-size:.9rem">Déconnexion imminente</div><div style="font-size:.8rem;color:var(--text2)">Inactif depuis <span id="timeout-count">5:00</span></div></div></div><div style="display:flex;gap:8px"><button onclick="stayConnected()" style="flex:1;padding:8px;background:var(--primary,#0F4C81);color:white;border:none;border-radius:8px;font-size:.85rem;font-weight:600;cursor:pointer">✅ Rester connecté</button><button onclick="window.location.href='/logout.php'" style="padding:8px 12px;background:rgba(239,68,68,.15);color:#f87171;border:1px solid rgba(239,68,68,.3);border-radius:8px;font-size:.85rem;cursor:pointer">Quitter</button></div>`;
  document.body.appendChild(popup);
  let countdown = 300, countInterval;
  function showWarning() {
    if (warned) return; warned = true; popup.style.display = 'block'; countdown = 300;
    countInterval = setInterval(() => {
      countdown--;
      const m = Math.floor(countdown/60), s = String(countdown%60).padStart(2,'0');
      const el = document.getElementById('timeout-count');
      if (el) el.textContent = m+':'+s;
      if (countdown <= 0) { clearInterval(countInterval); window.location.href = '/index.php?timeout=1'; }
    }, 1000);
  }
  function hideWarning() { popup.style.display='none'; warned=false; clearInterval(countInterval); }
  window.stayConnected = function() { hideWarning(); resetTimer(); fetch('/api/ping.php',{method:'GET',credentials:'same-origin'}).catch(()=>{}); };
  function resetTimer() {
    clearTimeout(timer); clearTimeout(warnTimer);
    if (warned) hideWarning();
    warnTimer = setTimeout(showWarning, timeoutMs - warnMs);
    timer = setTimeout(() => { if (!warned) window.location.href='/index.php?timeout=1'; }, timeoutMs);
  }
  ['mousemove','keydown','click','scroll','touchstart'].forEach(evt => document.addEventListener(evt, resetTimer, {passive:true}));
  resetTimer();
  setInterval(() => { fetch('/api/ping.php',{method:'GET',credentials:'same-origin'}).then(r=>r.json()).then(d=>{if(!d.ok)window.location.href='/index.php?timeout=1';}).catch(()=>{}); }, 2*60*1000);
})();

// ── Raccourcis clavier ────────────────────────────────
document.addEventListener('keydown', function(e) {
  const tag = document.activeElement.tagName;
  if (['INPUT','TEXTAREA','SELECT'].includes(tag)) return;
  if (document.activeElement.contentEditable === 'true') return;
  if (document.activeElement.isContentEditable) return;
  if (e.ctrlKey || e.metaKey || e.altKey) return;
  const shortcuts = { 'd':'/dashboard.php','p':'/products.php','t':'/terrain.php','s':'/scan.php','f':'/fiches.php','r':'/rempotages.php','n':'/sse.php','a':'/admin/','?':null };
  const key = e.key.toLowerCase();
  if (!shortcuts.hasOwnProperty(key)) return;
  if (key === '?') { const modal = document.getElementById('shortcuts-modal'); if (modal) modal.style.display = modal.style.display==='flex'?'none':'flex'; return; }
  if (shortcuts[key]) window.location.href = shortcuts[key];
});

document.addEventListener('DOMContentLoaded', function() {
  const modal = document.createElement('div');
  modal.id = 'shortcuts-modal';
  modal.style.cssText = 'display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:99999;align-items:center;justify-content:center';
  modal.innerHTML = `<div style="background:var(--bg2,#1e293b);border:1px solid var(--border,#334155);border-radius:16px;padding:28px;width:90%;max-width:460px;box-shadow:0 24px 60px rgba(0,0,0,.5)"><div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px"><div style="font-size:1.1rem;font-weight:700;color:var(--text,#e2e8f0)">⌨️ Raccourcis clavier</div><button onclick="document.getElementById('shortcuts-modal').style.display='none'" style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--text2,#94a3b8)">✕</button></div><div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">${[['D','Dashboard'],['P','Produits'],['T','Terrain'],['S','Scanner'],['F','Fiches'],['R','Rempotages'],['N','SSE/NRBC'],['A','Administration'],['?','Cette aide']].map(([k,v])=>`<div style="display:flex;align-items:center;gap:10px;padding:6px 0"><kbd style="background:var(--bg3,#0f172a);border:1px solid var(--border,#334155);border-radius:6px;padding:3px 10px;font-size:.85rem;font-weight:700;color:var(--text,#e2e8f0);min-width:30px;text-align:center">${k}</kbd><span style="font-size:.88rem;color:var(--text2,#94a3b8)">${v}</span></div>`).join('')}</div><p style="font-size:.75rem;color:var(--text3,#64748b);margin-top:16px">Ne fonctionne pas quand un champ de texte est actif</p></div>`;
  modal.addEventListener('click', (e) => { if (e.target === modal) modal.style.display = 'none'; });
  document.body.appendChild(modal);
});
</script>

<?php include_once __DIR__ . '/../ai/assistant.php'; ?>

<!-- ════════════════════════════════════════════════════
     BOTTOM NAVIGATION — Mobile (masquée sur desktop via CSS)
════════════════════════════════════════════════════ -->
<?php
$_bn_page = basename($_SERVER['PHP_SELF'], '.php');
$_bn_unread = 0;
if (isset($pdo)) {
    try {
        $_uid = currentUserId();
        $_s = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE (destinataire_id=? OR destinataire_id IS NULL) AND expediteur_id!=? AND lu=0 AND brouillon=0");
        $_s->execute([$_uid, $_uid]);
        $_bn_unread = (int)$_s->fetchColumn();
    } catch(Throwable $e) {}
}
?>
<?php $_bn_gate = function($m){ return !function_exists('aff_mob') || aff_mob($m); }; ?>
<nav class="bottom-nav" role="navigation" aria-label="Navigation principale">

  <a href="/dashboard.php"
     class="bottom-nav-item <?= $_bn_page==='dashboard'?'active':'' ?>"
     aria-label="Accueil">
    <div class="bn-icon">🏠</div>
    <span class="bn-label">Accueil</span>
  </a>

  <?php if ($_bn_gate('stupefiants')): ?>
  <a href="/stupefiants.php"
     class="bottom-nav-item <?= $_bn_page==='stupefiants'?'active':'' ?>"
     aria-label="Stupéfiants">
    <div class="bn-icon">🔒</div>
    <span class="bn-label">Stups</span>
  </a>
  <?php endif; ?>

  <?php if ($_bn_gate('scanner')): ?>
  <a href="/scan.php"
     class="bottom-nav-item bn-scan <?= $_bn_page==='scan'?'active':'' ?>"
     aria-label="Scanner">
    <div class="bn-icon">📷</div>
    <span class="bn-label">Scanner</span>
  </a>
  <?php endif; ?>

  <?php if ($_bn_gate('rempotages')): ?>
  <a href="/rempotage.php"
     class="bottom-nav-item <?= $_bn_page==='rempotage'?'active':'' ?>"
     aria-label="Rempotage">
    <div class="bn-icon">🔄</div>
    <span class="bn-label">Rempotage</span>
  </a>
  <?php endif; ?>

  <?php if ($_bn_gate('messagerie')): ?>
  <a href="/messaging.php"
     class="bottom-nav-item <?= $_bn_page==='messaging'?'active':'' ?>"
     aria-label="Messages">
    <div class="bn-wrap">
      <div class="bn-icon">💬</div>
      <?php if ($_bn_unread > 0): ?>
      <span class="bn-badge"><?= $_bn_unread > 9 ? '9+' : $_bn_unread ?></span>
      <?php endif; ?>
    </div>
    <span class="bn-label">Messages</span>
  </a>
  <?php endif; ?>

</nav>

<!-- Copyright — masqué sur mobile via CSS (.footer-copyright) -->
<div class="footer-copyright" style="position:fixed;bottom:0;left:0;right:0;z-index:50;background:var(--bg2);border-top:1px solid var(--border);padding:4px 16px;display:flex;align-items:center;justify-content:space-between;font-size:10px;color:var(--text3);">
  <span>© <?= date('Y') ?> <strong style="color:var(--text2)">Cadurix</strong> — Gestion du matériel médical</span>
  <span>v5.4 — Tous droits réservés</span>
</div>


<!-- ═══════════════════════════════════════════════════════
     CADURIX — Error Tracker v2
     Capture TOUTES les erreurs JS de toutes les pages
     Envoyé vers api/log_js_error.php → logs/js_errors.log
═══════════════════════════════════════════════════════ -->
<script>
// Error Tracker — chargé immédiatement, avant tout autre script
(function(){
  'use strict';

  var EP   = '/api/log_js_error.php';
  var PAGE = document.title || location.pathname.split('/').pop();
  var URL  = location.href;
  var QUEUE = []; // File d'attente si réseau pas encore dispo
  var SENDING = false;

  function send(payload) {
    var data = JSON.stringify(payload);
    // sendBeacon = fire-and-forget, parfait pour beforeunload
    if (navigator.sendBeacon) {
      var blob = new Blob([data], {type:'application/json'});
      navigator.sendBeacon(EP, blob);
    } else {
      // Fallback XHR synchrone (ne bloque pas le thread)
      try {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', EP, true);
        xhr.setRequestHeader('Content-Type','application/json');
        xhr.send(data);
      } catch(e) {}
    }
  }

  function track(type, message, source, line, col, stack) {
    send({
      type:      type,
      message:   String(message||'').slice(0,500),
      source:    String(source||'').slice(0,200),
      line:      line   || 0,
      col:       col    || 0,
      stack:     String(stack||'').slice(0,1000),
      page_url:  URL,
      page_name: PAGE,
    });
  }

  // ── 1. Erreurs JS classiques (onerror) ──────────────────
  window.onerror = function(msg, src, line, col, error) {
    track('JS_ERROR', msg, src, line, col, error ? error.stack : '');
    return false;
  };

  // ── 2. Promises rejetées non catchées ───────────────────
  window.addEventListener('unhandledrejection', function(e) {
    var r = e.reason;
    track('UNHANDLED_PROMISE',
      r instanceof Error ? r.message : String(r),
      '', 0, 0,
      r instanceof Error ? r.stack : ''
    );
  });

  // ── 3. Erreurs fetch (HTTP 4xx/5xx + réseau) ────────────
  var _fetch = window.fetch;
  window.fetch = function(url, opts) {
    // Ne pas intercepter les appels vers le tracker lui-même (évite boucle infinie)
    if (String(url).indexOf(EP) !== -1) return _fetch.apply(this, arguments);
    var start = Date.now();
    return _fetch.apply(this, arguments)
      .then(function(res) {
        if (!res.ok && res.status >= 400) {
          track('FETCH_HTTP_ERROR',
            'HTTP ' + res.status + ' ' + res.statusText,
            String(url), 0, 0, ''
          );
        }
        return res;
      })
      .catch(function(err) {
        track('FETCH_NETWORK_ERROR',
          err.message || String(err),
          String(url), 0, 0,
          err.stack || ''
        );
        throw err;
      });
  };

  // ── 4. console.error intercepté ─────────────────────────
  var _ce = console.error;
  console.error = function() {
    _ce.apply(console, arguments);
    var msg = Array.from(arguments).map(function(a) {
      try { return typeof a === 'object' ? JSON.stringify(a) : String(a); }
      catch(e) { return String(a); }
    }).join(' ');
    track('CONSOLE_ERROR', msg, '', 0, 0, '');
  };

  // ── 5. console.warn intercepté ──────────────────────────
  var _cw = console.warn;
  console.warn = function() {
    _cw.apply(console, arguments);
    var msg = Array.from(arguments).map(function(a) {
      try { return typeof a === 'object' ? JSON.stringify(a) : String(a); }
      catch(e) { return String(a); }
    }).join(' ');
    track('CONSOLE_WARN', msg, '', 0, 0, '');
  };

  // ── 6. Ressources non chargées (img, script, css) ───────
  window.addEventListener('error', function(e) {
    var t = e.target;
    if (t && t !== window && (t.src || t.href)) {
      track('RESOURCE_ERROR',
        'Ressource non chargée : ' + (t.src || t.href),
        t.src || t.href, 0, 0, ''
      );
    }
  }, true);

  // Test de démarrage supprimé — inutile et génère des faux positifs dans les logs

})();
</script>

<!-- ════════════════════════════════════════════════════
     PWA — enregistrement du service worker + invite d'installation
════════════════════════════════════════════════════ -->
<script>
(function(){
  // 1) Service worker : assets hors-ligne (jamais le PHP). HTTPS ou localhost requis.
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function(){
      navigator.serviceWorker.register('/service-worker.js').catch(function(){});
    });
  }

  // Déjà installée (mode standalone) → ne rien proposer
  var isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  if (isStandalone) return;

  // 2) Android / Chrome / Edge : bouton "Installer l'application"
  var deferred = null;
  window.addEventListener('beforeinstallprompt', function(e){
    e.preventDefault();
    deferred = e;
    if (document.getElementById('pwa-install')) return;
    var b = document.createElement('button');
    b.id = 'pwa-install';
    b.type = 'button';
    b.textContent = '📲 Installer l\'application';
    b.style.cssText = 'position:fixed;left:50%;transform:translateX(-50%);bottom:calc(env(safe-area-inset-bottom,0px) + 76px);z-index:9997;display:flex;align-items:center;gap:8px;padding:11px 18px;border:none;border-radius:99px;background:var(--primary,#3B82F6);color:#fff;font-family:inherit;font-size:.85rem;font-weight:700;box-shadow:0 10px 28px -8px rgba(0,0,0,.5);cursor:pointer';
    b.addEventListener('click', function(){
      if (!deferred) return;
      deferred.prompt();
      deferred.userChoice.finally(function(){ deferred = null; b.remove(); });
    });
    document.body.appendChild(b);
  });
  window.addEventListener('appinstalled', function(){
    var b = document.getElementById('pwa-install'); if (b) b.remove();
  });

  // 3) iOS Safari (pas de beforeinstallprompt) : rappel une seule fois
  var ua = window.navigator.userAgent;
  var isIOS = /iPad|iPhone|iPod/.test(ua) && !window.MSStream;
  var isSafari = /^((?!chrome|android|crios|fxios).)*safari/i.test(ua);
  if (isIOS && isSafari && !localStorage.getItem('pwa_ios_hint')) {
    window.addEventListener('load', function(){
      setTimeout(function(){
        var d = document.createElement('div');
        d.style.cssText = 'position:fixed;left:12px;right:12px;bottom:calc(env(safe-area-inset-bottom,0px) + 72px);z-index:9997;background:var(--bg2,#fff);border:1px solid var(--border,#dce6f7);border-radius:14px;padding:12px 14px;box-shadow:0 12px 32px -8px rgba(0,0,0,.4);font-family:inherit;font-size:.82rem;color:var(--text,#1e2a4a);display:flex;gap:10px;align-items:center';
        d.innerHTML = '<span style="font-size:1.4rem">📲</span><div style="flex:1;line-height:1.35">Installer Cadurix : appuyez sur <b>Partager</b> puis <b>« Sur l\'écran d\'accueil »</b>.</div><button type="button" aria-label="Fermer" style="background:none;border:none;color:var(--text3,#7488ae);font-size:1.2rem;line-height:1;cursor:pointer">&times;</button>';
        d.querySelector('button').addEventListener('click', function(){ d.remove(); try{ localStorage.setItem('pwa_ios_hint','1'); }catch(e){} });
        document.body.appendChild(d);
      }, 1500);
    });
  }
})();
</script>

</body>
/**
 * Cadurix — push_init.js
 * Activation des notifications push PWA
 * Inclure dans header.php APRÈS le chargement du DOM
 */

document.addEventListener('DOMContentLoaded', function() {
(function() {
  // Clé publique VAPID — à remplacer par ta vraie clé
  const VAPID_PUBLIC_KEY = window.VAPID_PUBLIC_KEY || '';

  // Vérifier support PWA
  if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;

  // Enregistrer le SW
  navigator.serviceWorker.register('/service-worker.js').then(reg => {
    window._sw_reg = reg;
    checkSubscription(reg);
  }).catch(err => console.warn('[Push] SW registration failed:', err));

  // Vérifier état abonnement
  async function checkSubscription(reg) {
    const sub = await reg.pushManager.getSubscription();
    updateBellUI(sub !== null);
  }

  // Activer les notifications
  window.activerNotifications = async function() {
    if (!VAPID_PUBLIC_KEY) {
      alert('Les notifications push ne sont pas encore configurées.\nElles seront disponibles une fois le site en production.');
      return;
    }

    const reg = window._sw_reg;
    if (!reg) return;

    // Demander permission
    const perm = await Notification.requestPermission();
    if (perm !== 'granted') {
      showToast('❌ Notifications refusées. Activez-les dans les paramètres de votre navigateur.', 'error');
      return;
    }

    try {
      // S'abonner
      const sub = await reg.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(VAPID_PUBLIC_KEY),
      });

      // Envoyer au serveur
      const res = await fetch('/push_subscribe.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action:   'subscribe',
          endpoint: sub.endpoint,
          keys: {
            p256dh: arrayBufferToBase64(sub.getKey('p256dh')),
            auth:   arrayBufferToBase64(sub.getKey('auth')),
          }
        })
      });

      const data = await res.json();
      if (data.ok) {
        updateBellUI(true);
        showToast('🔔 Notifications activées !', 'success');
      }
    } catch(err) {
      console.error('[Push] Subscribe error:', err);
      showToast('❌ Erreur lors de l\'activation des notifications.', 'error');
    }
  };

  // Désactiver les notifications
  window.desactiverNotifications = async function() {
    const reg = window._sw_reg;
    if (!reg) return;
    const sub = await reg.pushManager.getSubscription();
    if (!sub) return;

    await fetch('/push_subscribe.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'unsubscribe', endpoint: sub.endpoint })
    });

    await sub.unsubscribe();
    updateBellUI(false);
    showToast('🔕 Notifications désactivées.', 'info');
  };

  // UI — mettre à jour la cloche
  function updateBellUI(active) {
    const btn = document.getElementById('btn-notif');
    if (!btn) return;
    if (active) {
      btn.title = 'Notifications activées — Cliquer pour désactiver';
      btn.style.color = '#10b981';
      btn.onclick = window.desactiverNotifications;
    } else {
      btn.title = 'Activer les notifications push';
      btn.style.color = '';
      btn.onclick = window.activerNotifications;
    }
  }

  // Helper — convertir clé VAPID
  function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64  = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw     = window.atob(base64);
    return Uint8Array.from([...raw].map(c => c.charCodeAt(0)));
  }

  function arrayBufferToBase64(buffer) {
    return btoa(String.fromCharCode(...new Uint8Array(buffer)));
  }

  // Toast simple
  function showToast(msg, type) {
    const t = document.createElement('div');
    t.style.cssText = `position:fixed;bottom:20px;right:20px;z-index:9999;padding:.75rem 1.25rem;border-radius:10px;font-size:.875rem;font-weight:600;color:#fff;background:${type==='success'?'#10b981':type==='error'?'#ef4444':'#3b82f6'};box-shadow:0 4px 20px rgba(0,0,0,.3);transition:opacity .3s`;
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => { t.style.opacity = '0'; setTimeout(() => t.remove(), 300); }, 3000);
  }
})();
});
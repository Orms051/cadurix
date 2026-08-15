/**
 * Cadurix AI — Widget assistant v2 — Mobile first
 */
(function() {
'use strict';

const AI = {
    panel:       null,
    btn:         null,
    input:       null,
    messages:    null,
    suggestions: null,
    micBtn:      null,
    sendBtn:     null,
    history:     [],
    recognition: null,
    recording:   false,
    page:        document.body.dataset.page || window.location.pathname.replace(/.*\//, '').replace('.php', ''),
    pendingAction: null,

    init() {
        this.panel       = document.getElementById('ai-panel');
        this.btn         = document.getElementById('ai-btn');
        this.overlay     = document.getElementById('ai-overlay');
        this.input       = document.getElementById('ai-input');
        this.messages    = document.getElementById('ai-messages');
        this.suggestions = document.getElementById('ai-suggestions');
        this.micBtn      = document.getElementById('ai-mic');
        this.sendBtn     = document.getElementById('ai-send');

        if (!this.panel) return;

        this.btn.addEventListener('click',  () => this.toggle());
        document.getElementById('ai-close').addEventListener('click', () => this.close());
        // Fermer via overlay
        if (this.overlay) this.overlay.addEventListener('click', () => this.close());
        this.sendBtn.addEventListener('click', () => this.send());

        this.input.addEventListener('keydown', e => {
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); this.send(); }
        });
        this.input.addEventListener('input', () => {
            this.input.style.height = 'auto';
            this.input.style.height = Math.min(this.input.scrollHeight, 120) + 'px';
        });

        if (this.micBtn) this.micBtn.addEventListener('click', () => this.toggleVoice());

        // Photo : bouton 📷 → ouvre l'appareil / la galerie
        this.camBtn     = document.getElementById('ai-cam');
        this.photoInput = document.getElementById('ai-photo');
        if (this.camBtn && this.photoInput) {
            this.camBtn.addEventListener('click', () => this.photoInput.click());
            this.photoInput.addEventListener('change', e => {
                const f = e.target.files && e.target.files[0];
                if (f) this.sendPhoto(f);
                this.photoInput.value = '';
            });
        }

        // Empêcher scroll page derrière panneau sur iOS
        this.panel.addEventListener('touchmove', e => e.stopPropagation(), { passive: true });

        this.addMessage('assistant', '👋 Bonjour ! Je suis CadurixAI, votre assistant. Comment puis-je vous aider ?');
    },

    toggle() {
        const isOpen = this.panel.classList.contains('open');
        isOpen ? this.close() : this.open();
    },
    close() {
        this.panel.classList.remove('open');
        if (this.overlay) this.overlay.classList.remove('open');
    },
    open() {
        this.panel.classList.add('open');
        if (this.overlay) this.overlay.classList.add('open');
        if (window.innerWidth >= 768) this.input.focus();
    },

    async send(msg = null, opts = null) {
        const image = (opts && opts.image) || null;
        const text  = msg || this.input.value.trim();
        if (!text && !image) return;

        this.input.value = '';
        this.input.style.height = 'auto';
        this.addMessage('user', image ? ('📷 ' + (text || 'Photo envoyée')) : text);
        this.showTyping();
        this.sendBtn.disabled = true;

        try {
            const body = {
                message: text,
                page:    this.page,
                history: this.history.slice(-6),
            };
            if (image) { body.image = image; body.media_type = 'image/jpeg'; }

            const res = await fetch('/ai/api.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify(body),
            });

            const data = await res.json();
            this.hideTyping();

            if (data.error) {
                this.addMessage('system', '⚠️ ' + data.error);
            } else {
                this.history.push({ role: 'user',      content: text || '📷 photo' });
                this.history.push({ role: 'assistant', content: data.reply });
                this.addMessage('assistant', data.reply);

                if (data.action_result?.status === 'pending_confirm') {
                    this.pendingAction = data.action_result;
                    this.showActionCard(data.action_result);
                } else if (data.action_result?.status === 'success') {
                    this.addMessage('system', '✅ ' + data.action_result.message);
                }

                if (data.suggestions?.length) this.setSuggestions(data.suggestions);
            }
        } catch (e) {
            this.hideTyping();
            this.addMessage('system', '⚠️ Erreur de connexion : ' + e.message);
        }

        this.sendBtn.disabled = false;
    },

    // Photo d'une boîte → redimensionne puis envoie à l'IA (vision + outils)
    async sendPhoto(file) {
        if (!file.type || file.type.indexOf('image/') !== 0) {
            this.addMessage('system', '⚠️ Fichier non image.');
            return;
        }
        try {
            const b64 = await this.resizeToBase64(file, 1280, 0.8);
            const caption = this.input.value.trim();
            await this.send(caption || ' ', { image: b64 });
        } catch (e) {
            this.addMessage('system', '⚠️ Impossible de lire la photo : ' + e.message);
        }
    },

    // Redimensionne l'image (max maxDim px) et renvoie le base64 JPEG (sans préfixe data:)
    resizeToBase64(file, maxDim, quality) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onerror = () => reject(new Error('lecture échouée'));
            reader.onload = () => {
                const img = new Image();
                img.onerror = () => reject(new Error('image invalide'));
                img.onload = () => {
                    let w = img.width, h = img.height;
                    if (w > maxDim || h > maxDim) {
                        const s = Math.min(maxDim / w, maxDim / h);
                        w = Math.round(w * s); h = Math.round(h * s);
                    }
                    const c = document.createElement('canvas');
                    c.width = w; c.height = h;
                    c.getContext('2d').drawImage(img, 0, 0, w, h);
                    resolve(c.toDataURL('image/jpeg', quality).split(',')[1]);
                };
                img.src = reader.result;
            };
            reader.readAsDataURL(file);
        });
    },

    addMessage(role, text) {
        const div = document.createElement('div');
        div.className = 'ai-msg ' + role;
        div.textContent = text;
        this.messages.appendChild(div);
        this.messages.scrollTop = this.messages.scrollHeight;
        return div;
    },

    showTyping() {
        const el = document.createElement('div');
        el.className = 'ai-typing'; el.id = 'ai-typing';
        el.innerHTML = '<span></span><span></span><span></span>';
        this.messages.appendChild(el);
        this.messages.scrollTop = this.messages.scrollHeight;
    },

    hideTyping() { document.getElementById('ai-typing')?.remove(); },

    showActionCard(action) {
        const esc = s => String(s).replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]));
        const card = document.createElement('div');
        card.className = 'ai-action-card'; card.id = 'ai-action-card';
        const title = action.titre || ('🧾 ' + (action.emplacement?.nom || 'Action'));
        let html = '<div class="ai-action-title">' + esc(title) + '</div>';
        if (action.produits) {
            (action.produits || []).forEach(p => {
                html += '<div class="ai-action-item">• ' + esc(p.nom) + ' × ' + esc(p.quantite)
                      + (p.lot ? ' — lot ' + esc(p.lot) : '')
                      + (p.peremption ? ' — exp. ' + esc(p.peremption) : '') + '</div>';
            });
        } else if (action.lignes) {
            (action.lignes || []).forEach(l => {
                html += '<div class="ai-action-item">• ' + esc(l) + '</div>';
            });
        }
        const label = action.confirm_label ? ('✅ ' + esc(action.confirm_label)) : '✅ Confirmer';
        html += '<div class="ai-action-btns">'
              + '<button class="ai-btn-confirm" onclick="MedAI.confirmAction()">' + label + '</button>'
              + '<button class="ai-btn-cancel" onclick="MedAI.cancelAction()">✕ Annuler</button>'
              + '</div>';
        card.innerHTML = html;
        this.messages.appendChild(card);
        this.messages.scrollTop = this.messages.scrollHeight;
    },

    async confirmAction() {
        if (!this.pendingAction) return;
        document.getElementById('ai-action-card')?.remove();
        const pa = this.pendingAction;
        const action = (pa.action === 'rempotage')
            ? { ...pa, action: 'rempotage_confirme' }
            : { action: pa.action, params: pa.params || {} };
        this.showTyping();
        try {
            const res = await fetch('/ai/api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ message: JSON.stringify(action), page: 'confirm_action', history: [] }),
            });
            const data = await res.json();
            this.hideTyping();
            if (data.action_result?.status === 'success') {
                this.addMessage('system', '✅ ' + data.action_result.message);
                if (data.action_result.lien) {
                    setTimeout(() => { window.location.href = data.action_result.lien; }, 1500);
                }
            } else {
                this.addMessage('system', '⚠️ ' + (data.action_result?.message || 'Erreur lors de la confirmation'));
            }
        } catch(e) {
            this.hideTyping();
            this.addMessage('system', '⚠️ Erreur');
        }
        this.pendingAction = null;
    },

    cancelAction() {
        document.getElementById('ai-action-card')?.remove();
        this.pendingAction = null;
        this.addMessage('system', 'Action annulée.');
    },

    setSuggestions(sugs) {
        if (!this.suggestions) return;
        this.suggestions.innerHTML = '';
        sugs.forEach(s => {
            const btn = document.createElement('button');
            btn.className = 'ai-sug';
            btn.textContent = s;
            btn.addEventListener('click', () => this.send(s));
            this.suggestions.appendChild(btn);
        });
    },

    toggleVoice() { this.recording ? this.stopVoice() : this.startVoice(); },

    startVoice() {
        const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!SR) {
            this.addMessage('system', '⚠️ Reconnaissance vocale non supportée sur ce navigateur.');
            return;
        }
        this.recognition = new SR();
        this.recognition.lang = 'fr-FR';
        this.recognition.continuous = false;
        this.recognition.interimResults = false;
        this.recognition.onstart  = () => {
            this.recording = true;
            this.micBtn.classList.add('recording');
            this.addMessage('system', '🎤 Parlez…');
        };
        this.recognition.onresult = e => {
            this.input.value = e.results[0][0].transcript;
            this.send();
        };
        this.recognition.onerror  = e => {
            this.addMessage('system', '⚠️ Erreur micro : ' + e.error);
            this.stopVoice();
        };
        this.recognition.onend = () => this.stopVoice();
        this.recognition.start();
    },

    stopVoice() {
        this.recording = false;
        this.micBtn?.classList.remove('recording');
        this.recognition?.stop();
    },
};

window.MedAI = AI;
document.addEventListener('DOMContentLoaded', () => AI.init());
})();

<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.

/**
 * Cadurix AI — Widget flottant
 * À inclure dans footer.php
 */
if (!isset($pdo) || !isset($_SESSION['user_id'])) return;

// Module Assistant IA désactivé pour ce site → pas de widget
if (function_exists('module_actif') && !module_actif('assistant_ia')) return;

// Vérifier module actif
try {
    $ai_actif = $pdo->query("SELECT valeur FROM ai_settings WHERE cle='actif'")->fetchColumn();
    if (!$ai_actif) return;
} catch (Throwable) { return; }

$ai_page = basename($_SERVER['PHP_SELF'], '.php');
$csrf    = csrf_token();
?>
<meta name="csrf" content="<?= h($csrf) ?>">
<link rel="stylesheet" href="/ai/css/assistant.css?v=<?= time() ?>">

<!-- Bouton flottant -->
<button id="ai-btn" title="Assistant IA Cadurix" aria-label="Ouvrir l'assistant IA">
  🤖
  <span class="ai-notif"></span>
</button>

<!-- Overlay fond -->
<div id="ai-overlay"></div>

<!-- Panneau -->
<div id="ai-panel" role="dialog" aria-label="Assistant IA Cadurix">

  <!-- Drag handle (mobile) -->
  <div class="ai-handle"></div>

  <!-- Header -->
  <div class="ai-header">
    <div class="ai-avatar">🤖</div>
    <div class="ai-header-info">
      <div class="ai-title">CadurixAI</div>
      <div class="ai-subtitle"><?= h(trim(($_SESSION['user_prenom']??'').' '.($_SESSION['user_nom']??''))) ?></div>
      <div class="ai-context-inline">📍 <?= h($ai_page) ?> · <?= date('H:i') ?></div>
    </div>
    <button id="ai-close" class="ai-close" title="Fermer">✕</button>
  </div>

  <!-- Messages -->
  <div id="ai-messages" class="ai-messages"></div>

  <!-- Suggestions -->
  <div id="ai-suggestions" class="ai-suggestions">
    <button class="ai-sug" onclick="MedAI.send(this.textContent)">État du site</button>
    <button class="ai-sug" onclick="MedAI.send(this.textContent)">Alertes du jour</button>
    <button class="ai-sug" onclick="MedAI.send(this.textContent)">Aide</button>
  </div>

  <!-- Zone saisie -->
  <div class="ai-input-zone">
    <?php if (!function_exists('module_actif') || module_actif('saisie_vocale')): ?>
    <button id="ai-mic" class="ai-mic" title="Parler" type="button">🎤</button>
    <?php endif; ?>
    <button id="ai-cam" class="ai-mic" title="Prendre une photo d'une boîte" type="button">📷</button>
    <input id="ai-photo" type="file" accept="image/*" capture="environment" hidden>
    <textarea
      id="ai-input"
      class="ai-input"
      placeholder="Posez une question…"
      rows="1"
      autocomplete="off"
    ></textarea>
    <button id="ai-send" class="ai-send" title="Envoyer" type="button">➤</button>
  </div>
</div>

<script>
  document.body.dataset.page = '<?= h($ai_page) ?>';
</script>
<script src="/ai/js/assistant.js"></script>

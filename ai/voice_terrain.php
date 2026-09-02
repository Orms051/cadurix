<?php
/**
 * Cadurix — Voice Terrain Handler
 * Placement : ai/voice_terrain.php
 */

// Session déjà démarrée par auth.php si appelé depuis une page Cadurix
// Sinon la démarrer
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

// Auth
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    die(json_encode(['ok' => false, 'error' => 'Non connecté']));
}

// Lire le body JSON
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
$prompt = trim($data['prompt'] ?? '');

if (!$prompt) {
    die(json_encode(['ok' => false, 'error' => 'Prompt vide']));
}

// Racine du projet (ai/ est dans la racine)
$BASE    = dirname(__DIR__);
$IS_PROD = str_contains($_SERVER['HTTP_HOST'] ?? '', 'cadurix.fr');

// Clé API + moteur de modules depuis la BDD
try {
    require_once $BASE . '/config/db.php';
    require_once $BASE . '/config/permissions.php';
    require_once $BASE . '/config/modules.php';
    // Source unique de la clé/modèle : ai_settings (repli parametres pour compat).
    $api_key = $pdo->query("SELECT valeur FROM ai_settings WHERE cle='api_key'")->fetchColumn();
    if (!$api_key) { try { $api_key = $pdo->query("SELECT valeur FROM parametres WHERE cle='anthropic_api_key' LIMIT 1")->fetchColumn(); } catch (Throwable $e) {} }
    $model = $pdo->query("SELECT valeur FROM ai_settings WHERE cle='model'")->fetchColumn() ?: 'claude-haiku-4-5-20251001';
} catch (Exception $e) {
    die(json_encode(['ok' => false, 'error' => 'BDD : ' . $e->getMessage()]));
}

// Module saisie vocale désactivé pour ce site → bloquer
if (function_exists('module_actif') && !module_actif('saisie_vocale')) {
    http_response_code(403);
    die(json_encode(['ok' => false, 'error' => 'La saisie vocale est désactivée pour ce site.']));
}

if (!$api_key) {
    die(json_encode(['ok' => false, 'error' => 'Clé API non configurée → Admin → IA → Configuration']));
}

// Appel Claude Haiku
$payload = json_encode([
    'model'      => $model ?? 'claude-haiku-4-5-20251001',
    'max_tokens' => 200,
    'messages'   => [['role' => 'user', 'content' => $prompt]],
]);

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-api-key: ' . $api_key,
        'anthropic-version: 2023-06-01',
    ],
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_SSL_VERIFYPEER => $IS_PROD,
]);

$res  = curl_exec($ch);
$cerr = curl_error($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($cerr) {
    die(json_encode(['ok' => false, 'error' => 'cURL : ' . $cerr]));
}

if ($code !== 200) {
    die(json_encode(['ok' => false, 'error' => 'API Anthropic HTTP ' . $code . ' : ' . $res]));
}

$body = json_decode($res, true);
$text = $body['content'][0]['text'] ?? '';
$text = trim(preg_replace('/```json|```/', '', $text));

echo json_encode(['ok' => true, 'content' => $text]);

<?php
/**
 * Cadurix — JS Error Collector
 * Reçoit les erreurs JS depuis toutes les pages
 * Placement : api/log_js_error.php
 */
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['test'] ?? '') === '1') {
    die(json_encode(['ok' => true, 'test' => true, 'log_dir' => dirname(__DIR__).'/logs']));
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['ok' => false]));
}

$raw  = file_get_contents('php://input');
// sendBeacon peut envoyer du text/plain — essayer de parser
if (empty($raw)) { die(json_encode(['ok' => false, 'error' => 'empty'])); }
$data = json_decode($raw, true);
if (!$data) {
    // Essayer de décoder depuis form data
    parse_str($raw, $data);
    if (!$data) { die(json_encode(['ok' => false, 'error' => 'invalid json: '.substr($raw,0,50)])); }
}

$entry = [
    'time'      => date('Y-m-d H:i:s'),
    'type'      => $data['type']      ?? 'JS_ERROR',
    'message'   => $data['message']   ?? '',
    'source'    => $data['source']    ?? '',
    'line'      => $data['line']      ?? 0,
    'col'       => $data['col']       ?? 0,
    'stack'     => $data['stack']     ?? '',
    'page_url'  => $data['page_url']  ?? '',
    'page_name' => $data['page_name'] ?? '',
    'user_id'   => $_SESSION['user_id']   ?? null,
    'user_role' => $_SESSION['role']      ?? null,
    'ua'        => $_SERVER['HTTP_USER_AGENT'] ?? '',
    'ip'        => $_SERVER['REMOTE_ADDR'] ?? '',
];

$log_dir  = dirname(__DIR__) . '/logs';
$log_file = $log_dir . '/js_errors.log';

if (!is_dir($log_dir)) mkdir($log_dir, 0755, true);

file_put_contents($log_file, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);

echo json_encode(['ok' => true]);

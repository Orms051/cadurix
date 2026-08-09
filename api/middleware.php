<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.

/**
 * Cadurix API — Middleware JWT
 * À inclure en tête de chaque endpoint API
 *
 * Usage :
 *   require_once __DIR__ . '/middleware.php';
 *   api_require_perm('read');   // Vérifier une permission
 *   $user_id = $API_USER['sub'];
 *   $site_id = $API_USER['site_id'];
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../libs/jwt.php';

$JWT_SECRET = defined('JWT_SECRET') ? JWT_SECRET : hash('sha256', DB_PASS . DB_NAME . 'cadurix_jwt_salt_v1');

function api_error(int $code, string $msg, array $extra = []): void {
    http_response_code($code);
    echo json_encode(array_merge(['error' => true, 'message' => $msg, 'code' => $code], $extra));
    exit;
}

function api_success(mixed $data, int $code = 200, array $meta = []): void {
    http_response_code($code);
    $resp = ['success' => true, 'data' => $data];
    if ($meta) $resp['meta'] = $meta;
    echo json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// Décoder le JWT
$_jwt_token = CadurixJWT::from_request();
if (!$_jwt_token) api_error(401, 'Token JWT manquant. Utilisez Authorization: Bearer <token>');

try {
    $API_USER = CadurixJWT::decode($_jwt_token, $JWT_SECRET);
} catch (RuntimeException $e) {
    api_error(401, $e->getMessage());
}

// Vérifier que le token_id est toujours actif en BDD
global $pdo;
$_t = $pdo->prepare("SELECT actif FROM api_tokens WHERE id=?");
$_t->execute([$API_USER['token_id']]);
$_tr = $_t->fetch(PDO::FETCH_ASSOC);
if (!$_tr || !$_tr['actif']) api_error(401, 'Token révoqué');

// Mettre à jour derniere_utilisation + compteur
$pdo->prepare("UPDATE api_tokens SET derniere_utilisation=NOW(), nb_appels=nb_appels+1 WHERE id=?")
    ->execute([$API_USER['token_id']]);

// Logger l'appel
try {
    $pdo->prepare("INSERT INTO api_logs (token_id,methode,endpoint,ip,statut) VALUES (?,?,?,?,200)")
        ->execute([
            $API_USER['token_id'],
            $_SERVER['REQUEST_METHOD'],
            parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH),
            $_SERVER['REMOTE_ADDR'],
        ]);
} catch(Exception $e) {}

// Rate limiting API (100 appels/min par token)
try {
    $rl = $pdo->prepare("SELECT COUNT(*) FROM api_logs WHERE token_id=? AND cree_le > DATE_SUB(NOW(), INTERVAL 1 MINUTE)");
    $rl->execute([$API_USER['token_id']]);
    if ((int)$rl->fetchColumn() > 100) {
        api_error(429, 'Rate limit dépassé (100 req/min)', ['retry_after' => 60]);
    }
} catch(Exception $e) {}

/**
 * Vérifier qu'une permission est accordée
 */
function api_require_perm(string $perm): void {
    global $API_USER;
    $perms = $API_USER['perms'] ?? [];
    if (!in_array($perm, $perms) && !in_array('admin', $perms)) {
        api_error(403, "Permission '{$perm}' requise pour cette action");
    }
}

/**
 * Récupérer le site_id sécurisé (depuis le token ou le paramètre)
 */
function api_site_id(): int {
    global $API_USER, $pdo;
    // Si le token est lié à un site spécifique
    if (!empty($API_USER['site_id'])) return (int)$API_USER['site_id'];
    // Sinon, le client peut passer ?site_id=X
    $sid = (int)($_GET['site_id'] ?? $_POST['site_id'] ?? 0);
    if (!$sid) api_error(400, 'site_id requis');
    // Vérifier que l'user a accès à ce site
    if (!$API_USER['super']) {
        $chk = $pdo->prepare("SELECT 1 FROM user_site_permissions WHERE user_id=? AND site_id=?");
        $chk->execute([$API_USER['sub'], $sid]);
        if (!$chk->fetchColumn()) api_error(403, 'Accès refusé à ce site');
    }
    return $sid;
}

/**
 * Parser le body JSON
 */
function api_body(): array {
    static $body = null;
    if ($body === null) $body = json_decode(file_get_contents('php://input'), true) ?? [];
    return $body;
}

/**
 * Pagination standard
 */
function api_pagination(): array {
    $page  = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    return ['page' => $page, 'limit' => $limit, 'offset' => ($page - 1) * $limit];
}

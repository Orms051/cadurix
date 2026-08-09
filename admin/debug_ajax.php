<?php
/**
 * Cadurix — Debug AJAX Endpoint
 * Placement : admin/debug_ajax.php
 * Appelé par admin/debug.php via fetch('/admin/debug_ajax.php?dbg_action=...')
 */

// Toujours répondre en JSON, même en cas d'erreur
header('Content-Type: application/json');
header('X-Robots-Tag: noindex');

// Capturer toute erreur PHP et la retourner en JSON
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    echo json_encode(['ok'=>false,'error'=>"PHP $errno: $errstr in $errfile:$errline"]);
    exit;
});
register_shutdown_function(function() {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        echo json_encode(['ok'=>false,'error'=>"Fatal: {$e['message']} in {$e['file']}:{$e['line']}"]);
    }
});

// Auth — super_admin dans Cadurix = $_SESSION['super_admin'] === true
session_start();
$is_super = ($_SESSION['super_admin'] ?? false) === true;
$is_admin = in_array($_SESSION['role'] ?? '', ['super_admin', 'administrateur', 'admin']);
if (!$is_super && !$is_admin) {
    http_response_code(403);
    die(json_encode(['ok'=>false,'error'=>'Accès refusé — super_admin requis']));
}

// Activer log_errors si désactivé
@ini_set('log_errors', 1);
@ini_set('display_errors', 0);

// BDD
$BASE    = dirname(__DIR__);
$LOG_DIR = $BASE . '/logs';
$IS_PROD = str_contains($_SERVER['HTTP_HOST'] ?? '', 'cadurix.fr');

try {
    require_once $BASE . '/config/db.php';
} catch (Exception $e) {
    die(json_encode(['ok'=>false,'error'=>'BDD: '.$e->getMessage()]));
}

$action = $_GET['dbg_action'] ?? '';

// ── Vider un log ──────────────────────────────────────────────
if ($action === 'clear_log') {
    $log  = preg_replace('/[^a-z_]/', '', $_GET['log'] ?? '');
    $path = $LOG_DIR . '/' . $log . '.log';
    if ($log === 'php_errors') {            // viser le vrai journal système
        $sys = ini_get('error_log');
        if ($sys && file_exists($sys)) $path = $sys;
    }
    if (file_exists($path) && is_writable($path)) {
        file_put_contents($path, '');
        die(json_encode(['ok' => true]));
    }
    die(json_encode(['ok' => false, 'error' => 'Fichier introuvable ou non accessible en écriture']));
}

// ── Lire un log ───────────────────────────────────────────────
if ($action === 'get_logs') {
    $log    = preg_replace('/[^a-z_]/', '', $_GET['log'] ?? 'php_errors');
    $lines  = min((int)($_GET['lines'] ?? 100), 1000);
    $filter = trim($_GET['filter'] ?? '');

    $path = $LOG_DIR . '/' . $log . '.log';
    if ($log === 'php_errors') {
        $sys = ini_get('error_log');
        if ($sys && file_exists($sys)) $path = $sys;
    }
    // Scanner : les erreurs scanner sont logguées dans js_errors (type SCANNER_*)
    if ($log === 'scan_debug') { $path = $LOG_DIR . '/js_errors.log'; }

    if (!file_exists($path)) {
        die(json_encode(['lines'=>[],'total'=>0,'size'=>0,'mtime'=>0,'path'=>$path]));
    }

    $raw = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    if ($log === 'scan_debug') {
        $raw = array_values(array_filter($raw, fn($l) => stripos($l, 'SCANNER') !== false));
    }
    if ($filter) {
        $raw = array_values(array_filter($raw, fn($l) => stripos($l, $filter) !== false));
    }
    $total  = count($raw);
    $raw    = array_reverse(array_slice($raw, -$lines));
    $parsed = array_map(function($l) use ($log) {
        if ($log === 'js_errors') {
            $obj = json_decode($l, true);
            if ($obj) return $obj;
        }
        if ($log === 'scan_debug') {
            $o = json_decode($l, true);
            if ($o) return ['raw' => '['.($o['type']??'JS').'] '.($o['message']??'').' — '.($o['page_name'] ?? $o['source'] ?? ''), 'type' => 'error'];
        }
        return ['raw' => $l, 'type' => dbg_level($l)];
    }, $raw);

    die(json_encode(['lines'=>$parsed,'total'=>$total,'size'=>filesize($path),'mtime'=>filemtime($path),'path'=>$path]));
}

// ── Test BDD ──────────────────────────────────────────────────
if ($action === 'test_db') {
    $t = microtime(true);
    $pdo->query('SELECT 1');
    die(json_encode(['ok'=>true,'latency_ms'=>round((microtime(true)-$t)*1000,2)]));
}

// ── Test API Anthropic ────────────────────────────────────────
if ($action === 'test_anthropic') {
    $key = $pdo->query("SELECT valeur FROM parametres WHERE cle='anthropic_api_key'")->fetchColumn();
    if (!$key) die(json_encode(['ok'=>false,'error'=>'Clé non configurée → admin IA']));
    $ch = curl_init('https://api.anthropic.com/v1/models');
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_HTTPHEADER=>['x-api-key: '.$key,'anthropic-version: 2023-06-01'],
        CURLOPT_TIMEOUT=>10,
        CURLOPT_SSL_VERIFYPEER=>$IS_PROD,
    ]);
    $t    = microtime(true);
    curl_exec($ch);
    $lat  = round((microtime(true)-$t)*1000,2);
    $code = curl_getinfo($ch,CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    if ($cerr) die(json_encode(['ok'=>false,'error'=>'cURL: '.$cerr]));
    die(json_encode(['ok'=>$code===200,'status'=>$code,'latency_ms'=>$lat,'error'=>$code!==200?'HTTP '.$code:null]));
}

// ── Test SMTP ─────────────────────────────────────────────────
if ($action === 'test_smtp') {
    $host = $pdo->query("SELECT valeur FROM parametres WHERE cle='smtp_host'")->fetchColumn();
    $user = $pdo->query("SELECT valeur FROM parametres WHERE cle='smtp_user'")->fetchColumn();
    if (!$host || !$user) die(json_encode(['ok'=>false,'error'=>'SMTP non configuré']));
    die(json_encode(['ok'=>true,'host'=>$host,'user'=>$user]));
}

// ── Test permissions ──────────────────────────────────────────
if ($action === 'test_perms') {
    $dirs = [
        'logs/'    => $LOG_DIR,
        'backups/' => $BASE.'/backups',
        'uploads/' => $BASE.'/uploads',
        'config/'  => $BASE.'/config',
    ];
    $out = [];
    foreach ($dirs as $k => $p) {
        $out[$k] = ['exists'=>is_dir($p),'readable'=>is_readable($p),'writable'=>is_writable($p)];
    }
    die(json_encode($out));
}

// ── Score de santé ────────────────────────────────────────────
if ($action === 'health') {
    $score = 100; $issues = [];

    try {
        $t = microtime(true); $pdo->query('SELECT 1');
        $lat = (microtime(true)-$t)*1000;
        if ($lat > 500) { $score -= 10; $issues[] = "BDD lente (".round($lat)."ms)"; }
    } catch (Exception $e) { $score -= 30; $issues[] = 'BDD inaccessible: '.$e->getMessage(); }

    $existing = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['interventions_materiel','interventions_lignes','medicaments_ref','produits'] as $t) {
        if (!in_array($t, $existing)) { $score -= 15; $issues[] = "Table manquante : $t"; }
    }

    $phplog = ini_get('error_log');
    if ($phplog && file_exists($phplog)) {
        $tail = @file_get_contents($phplog, false, null, -30000) ?: '';
        $n    = substr_count(strtoupper($tail), 'FATAL ERROR');
        if ($n > 0) { $score -= min($n*5,20); $issues[] = "$n erreurs fatales PHP récentes"; }
    }

    $jslog = $LOG_DIR.'/js_errors.log';
    if (file_exists($jslog)) {
        $n = count(file($jslog, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) ?: []);
        if ($n > 50) { $score -= 5; $issues[] = "$n erreurs JS non traitées"; }
    }

    $free  = @disk_free_space($BASE) ?: 0;
    $total = @disk_total_space($BASE) ?: 1;
    $pct   = $free/$total*100;
    if ($pct < 10)      { $score -= 15; $issues[] = "Espace critique (".round($pct,1)."% libre)"; }
    elseif ($pct < 20)  { $score -= 5;  $issues[] = "Espace bas (".round($pct,1)."% libre)"; }

    if (!is_writable($LOG_DIR)) { $score -= 10; $issues[] = 'logs/ non accessible en écriture'; }

    die(json_encode(['score'=>max(0,$score),'issues'=>$issues]));
}

// ── Stats BDD ─────────────────────────────────────────────────
if ($action === 'db_stats') {
    $db     = $pdo->query("SELECT DATABASE()")->fetchColumn();
    $tables = $pdo->query("
        SELECT table_name, table_rows,
               ROUND((data_length+index_length)/1024,1) AS size_kb,
               update_time
        FROM information_schema.tables
        WHERE table_schema=DATABASE()
        ORDER BY (data_length+index_length) DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    $sess = 0;
    try { $sess = (int)$pdo->query("SELECT COUNT(*) FROM utilisateurs WHERE derniere_connexion > DATE_SUB(NOW(),INTERVAL 30 MINUTE)")->fetchColumn(); } catch(Exception $e){}
    die(json_encode(['db'=>$db,'tables'=>$tables,'sessions'=>$sess]));
}

// ── Espace disque ─────────────────────────────────────────────
if ($action === 'disk') {
    $free  = @disk_free_space($BASE) ?: 0;
    $total = @disk_total_space($BASE) ?: 1;
    die(json_encode([
        'free_gb'  => round($free/1073741824,2),
        'total_gb' => round($total/1073741824,2),
        'used_pct' => round(($total-$free)/$total*100,1),
    ]));
}

// ── Sessions actives ──────────────────────────────────────
if ($action === 'sessions') {
    try {
        $rows = $pdo->query("
            SELECT u.prenom, u.nom,
                   CASE WHEN u.super_admin=1 THEN 'super_admin' ELSE 'utilisateur' END AS role,
                   u.derniere_connexion AS last_seen, NULL AS site_nom
            FROM utilisateurs u
            WHERE u.derniere_connexion > DATE_SUB(NOW(), INTERVAL 30 MINUTE)
            ORDER BY u.derniere_connexion DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
        die(json_encode(['rows'=>$rows]));
    } catch(Exception $e) { die(json_encode(['rows'=>[], 'error'=>$e->getMessage()])); }
}

// ── Timeline JS (24h) ─────────────────────────────────────────
if ($action === 'timeline') {
    $jslog = $LOG_DIR.'/js_errors.log';
    $hours = array_fill(0, 24, ['e'=>0,'w'=>0]);
    if (file_exists($jslog)) {
        foreach (file($jslog, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
            $obj = json_decode($l, true);
            if (!$obj || empty($obj['time'])) continue;
            $h = (int)date('G', strtotime($obj['time']));
            str_contains(strtoupper($obj['type']??''), 'ERROR') ? $hours[$h]['e']++ : $hours[$h]['w']++;
        }
    }
    die(json_encode(['hours'=>$hours,'total'=>array_sum(array_column($hours,'e'))+array_sum(array_column($hours,'w'))]));
}

// ── Sécurité ──────────────────────────────────────────────
if ($action === 'security') {
    $result = ['failed_logins'=>[], 'blocked_ips'=>[], 'events'=>[]];
    try {
        $result['failed_logins'] = $pdo->query("
            SELECT login, tentatives_echec as nb, derniere_connexion as derniere, derniere_ip as ip
            FROM utilisateurs WHERE tentatives_echec > 0
            ORDER BY tentatives_echec DESC LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch(Exception $e) {}
    try {
        $result['blocked_ips'] = $pdo->query("
            SELECT ip, raison, bloque_jusqua, cree_le
            FROM security_ips WHERE actif=1
            ORDER BY cree_le DESC LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch(Exception $e) {
        try {
            $result['blocked_ips'] = $pdo->query("
                SELECT derniere_ip as ip, bloque_jusqu as bloque_jusqua
                FROM utilisateurs WHERE bloque_jusqu > NOW()
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch(Exception $e2) {}
    }
    try {
        $result['events'] = $pdo->query("
            SELECT type_evenement, ip_address, details, cree_le
            FROM security_events ORDER BY cree_le DESC LIMIT 20
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch(Exception $e) {}
    die(json_encode($result));
}

// ── Environnement serveur ─────────────────────────────────────
if ($action === 'env') {
    die(json_encode([
        'php_version'        => PHP_VERSION,
        'host'               => $_SERVER['HTTP_HOST'] ?? '',
        'is_prod'            => $IS_PROD,
        'https'              => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'memory_limit'       => ini_get('memory_limit'),
        'post_max_size'      => ini_get('post_max_size'),
        'upload_max'         => ini_get('upload_max_filesize'),
        'max_execution_time' => ini_get('max_execution_time'),
        'opcache'            => function_exists('opcache_get_status') && @opcache_get_status() ? true : false,
        'ext'                => [
            'curl'      => extension_loaded('curl'),
            'pdo_mysql' => extension_loaded('pdo_mysql'),
            'gd'        => extension_loaded('gd'),
            'mbstring'  => extension_loaded('mbstring'),
            'openssl'   => extension_loaded('openssl'),
        ],
    ]));
}

// ── Diagnostic complet (Afin de réaliser un copier / coller) ─────────────
if ($action === 'diag') {
    $L = [];
    $L[] = '===== CADURIX — DIAGNOSTIC DEBUG CENTER =====';
    $L[] = 'Date : ' . date('Y-m-d H:i:s');
    $L[] = 'Hôte : ' . ($_SERVER['HTTP_HOST'] ?? '') . ($IS_PROD ? ' (PROD)' : ' (LOCAL)');
    $L[] = 'PHP  : ' . PHP_VERSION . ' | mem ' . ini_get('memory_limit') . ' | post ' . ini_get('post_max_size') . ' | upload ' . ini_get('upload_max_filesize');

    $score = 100; $issues = [];
    try { $t=microtime(true); $pdo->query('SELECT 1'); $lat=(microtime(true)-$t)*1000;
        if ($lat>500){$score-=10;$issues[]="BDD lente (".round($lat)."ms)";}
    } catch(Exception $e){$score-=30;$issues[]='BDD inaccessible: '.$e->getMessage();}
    try {
        $existing=$pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        foreach(['interventions_materiel','interventions_lignes','medicaments_ref','produits'] as $tb){
            if(!in_array($tb,$existing)){$score-=15;$issues[]="Table manquante : $tb";}
        }
    } catch(Exception $e){}
    $L[] = '';
    $L[] = 'SCORE SANTÉ : ' . max(0,$score) . '/100';
    $L[] = $issues ? 'Problèmes : ' . implode(' | ', $issues) : 'Aucun problème détecté.';

    $phplog = ini_get('error_log');
    $L[] = ''; $L[] = '----- DERNIÈRES ERREURS PHP -----';
    if ($phplog && file_exists($phplog)) {
        $r = array_slice(file($phplog, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) ?: [], -20);
        $L[] = $r ? implode("\n", $r) : '(vide)';
    } else { $L[] = '(log PHP introuvable)'; }

    $jslog = $LOG_DIR.'/js_errors.log';
    $L[] = ''; $L[] = '----- DERNIÈRES ERREURS JS -----';
    if (file_exists($jslog)) {
        $r = array_slice(file($jslog, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) ?: [], -15);
        foreach ($r as $l) {
            $o = json_decode($l, true);
            $L[] = $o ? (($o['time']??'?').'  ['.($o['type']??'JS').'] '.($o['message']??'').' — '.($o['page_name']??$o['source']??'')) : $l;
        }
        if (!$r) $L[] = '(vide)';
    } else { $L[] = '(pas de js_errors.log)'; }

    header('Content-Type: text/plain; charset=utf-8');
    die(implode("\n", $L));
}

die(json_encode(['ok'=>false,'error'=>'Action inconnue : '.$action]));

// ── Helper ────────────────────────────────────────────────────
function dbg_level(string $s): string {
    $u = strtoupper($s);
    if (str_contains($u,'FATAL') || str_contains($u,'EXCEPTION')) return 'fatal';
    if (str_contains($u,'ERROR'))   return 'error';
    if (str_contains($u,'WARNING')) return 'warning';
    if (str_contains($u,'NOTICE'))  return 'notice';
    return 'info';
}

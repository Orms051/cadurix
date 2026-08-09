<?php
/**
 * Cadurix — Error Handler Global
 * Inclus dans auth.php — capture TOUTES les erreurs PHP du site
 * Logs vers : logs/app.log
 */

if (defined('ERROR_HANDLER_LOADED')) return;
define('ERROR_HANDLER_LOADED', true);

// ═══════════════════════════════════════════════════════════════
// CONFIG
// ═══════════════════════════════════════════════════════════════
define('APP_LOG', dirname(__DIR__) . '/logs/app.log');
define('APP_LOG_MAX_SIZE', 5 * 1024 * 1024); // 5 MB max — rotation auto

// ═══════════════════════════════════════════════════════════════
// FONCTION CENTRALE DE LOG
// ═══════════════════════════════════════════════════════════════
function app_log(
    string  $level,
    string  $message,
    string  $file     = '',
    int     $line     = 0,
    string  $trace    = '',
    array   $context  = []
): void {
    // Rotation si fichier trop grand
    if (file_exists(APP_LOG) && filesize(APP_LOG) > APP_LOG_MAX_SIZE) {
        rename(APP_LOG, APP_LOG . '.' . date('Ymd-His') . '.bak');
    }

    // Contexte utilisateur
    $user_id   = $_SESSION['user_id']      ?? null;
    $user_role = $_SESSION['role']         ?? ($_SESSION['super_admin'] ?? false ? 'super_admin' : null);
    $site_id   = $_SESSION['site_actif_id']?? null;
    $page      = $_SERVER['PHP_SELF']      ?? '';
    $method    = $_SERVER['REQUEST_METHOD']?? '';
    $ip        = $_SERVER['REMOTE_ADDR']   ?? '';

    // POST data — sans mots de passe
    $post_safe = [];
    $sensitive  = ['password','mot_de_passe','pwd','pass','token','csrf_token','api_key'];
    foreach ($_POST as $k => $v) {
        $post_safe[$k] = in_array(strtolower($k), $sensitive) ? '***' : (strlen((string)$v) > 200 ? substr($v, 0, 200).'…' : $v);
    }

    // Construire l'entrée
    $entry  = "\n" . str_repeat('─', 80) . "\n";
    $entry .= sprintf("[%s] %s\n", date('Y-m-d H:i:s'), strtoupper($level));
    $entry .= sprintf("Message  : %s\n", $message);

    if ($file) $entry .= sprintf("Fichier  : %s\n", $file);
    if ($line) $entry .= sprintf("Ligne    : %d\n", $line);

    // Contexte utilisateur
    if ($user_id) {
        $entry .= sprintf("User     : #%s%s%s\n",
            $user_id,
            $user_role ? " ($user_role)" : '',
            $site_id   ? " — site #$site_id" : ''
        );
    }

    $entry .= sprintf("Page     : %s %s\n", $method, $page);
    if ($ip) $entry .= sprintf("IP       : %s\n", $ip);

    // POST data
    if (!empty($post_safe)) {
        $entry .= sprintf("POST     : %s\n", json_encode($post_safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    // Contexte additionnel (ex: requête SQL)
    foreach ($context as $k => $v) {
        $entry .= sprintf("%-9s: %s\n", ucfirst($k), is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v);
    }

    // Stack trace
    if ($trace) {
        $entry .= "Stack    :\n";
        foreach (explode("\n", $trace) as $tline) {
            $entry .= "  " . $tline . "\n";
        }
    }

    file_put_contents(APP_LOG, $entry, FILE_APPEND | LOCK_EX);
}

// ═══════════════════════════════════════════════════════════════
// GESTIONNAIRE ERREURS PHP (Warning, Notice, Deprecated…)
// ═══════════════════════════════════════════════════════════════
set_error_handler(function(int $errno, string $errstr, string $errfile, int $errline): bool {
    // Ignorer les erreurs supprimées avec @
    if (!(error_reporting() & $errno)) return false;

    $levels = [
        E_WARNING          => 'WARNING',
        E_NOTICE           => 'NOTICE',
        E_USER_ERROR       => 'USER_ERROR',
        E_USER_WARNING     => 'USER_WARNING',
        E_USER_NOTICE      => 'USER_NOTICE',
        E_DEPRECATED       => 'DEPRECATED',
        E_USER_DEPRECATED  => 'DEPRECATED',
        E_STRICT           => 'STRICT',
    ];

    $level = $levels[$errno] ?? "ERROR($errno)";

    // Ne logguer que Warning et au-dessus (pas les notices en prod)
    $IS_PROD = str_contains($_SERVER['HTTP_HOST'] ?? '', 'cadurix.fr');
    if ($IS_PROD && in_array($errno, [E_NOTICE, E_USER_NOTICE, E_STRICT])) { // dépréciations laissées passer le temps du test
    return true;
}

    app_log($level, $errstr, $errfile, $errline, '');
    return true; // Ne pas exécuter le gestionnaire PHP natif
});

// ═══════════════════════════════════════════════════════════════
// GESTIONNAIRE EXCEPTIONS NON CATCHÉES
// ═══════════════════════════════════════════════════════════════
set_exception_handler(function(Throwable $e): void {
    app_log(
        'EXCEPTION',
        get_class($e) . ': ' . $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    );

    // Répondre proprement si c'est une requête AJAX
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) ||
        str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') ||
        isset($_POST['action']) || isset($_GET['ajax'])) {
        if (!headers_sent()) header('Content-Type: application/json');
        echo json_encode([
            'ok'      => false,
            'error'   => $e->getMessage(),
            'file'    => basename($e->getFile()),
            'line'    => $e->getLine(),
        ]);
    } else {
        // Page normale — afficher une page d'erreur propre
        if (!headers_sent()) http_response_code(500);
        echo '<div style="font-family:monospace;padding:20px;color:#f87171;background:#0f1117">
            <strong>⚠️ Une erreur est survenue.</strong><br>
            L\'erreur a été enregistrée automatiquement.<br>
            <small style="color:#64748b">'.htmlspecialchars($e->getMessage()).'</small>
        </div>';
    }
    exit;
});

// ═══════════════════════════════════════════════════════════════
// FATAL ERRORS (parse error, memory limit, etc.)
// Ces erreurs ne sont PAS catchées par set_error_handler
// ═══════════════════════════════════════════════════════════════
register_shutdown_function(function(): void {
    $e = error_get_last();
    if (!$e) return;

    $fatal_types = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_CORE_WARNING, E_COMPILE_WARNING];
    if (!in_array($e['type'], $fatal_types)) return;

    app_log(
        'FATAL',
        $e['message'],
        $e['file'],
        $e['line'],
        ''
    );
});

// ═══════════════════════════════════════════════════════════════
// WRAPPER PDO — log les requêtes SQL qui plantent
// Usage : utiliser pdo_query() et pdo_exec() à la place de $pdo->query()
// ═══════════════════════════════════════════════════════════════
function pdo_log_query(string $sql, array $params = [], ?\Throwable $e = null, float $duration = 0): void {
    if ($e) {
        app_log('SQL_ERROR', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString(), [
            'requête'    => trim(preg_replace('/\s+/', ' ', $sql)),
            'paramètres' => json_encode($params, JSON_UNESCAPED_UNICODE),
        ]);
    } elseif ($duration > 1.0) {
        // Requête lente > 1 seconde
        app_log('SQL_SLOW', sprintf('Requête lente (%.2fs)', $duration), '', 0, '', [
            'requête'    => trim(preg_replace('/\s+/', ' ', $sql)),
            'paramètres' => json_encode($params, JSON_UNESCAPED_UNICODE),
            'durée'      => round($duration, 3) . 's',
        ]);
    }
}

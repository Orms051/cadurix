<?php
/**
 * Cadurix — Debug Center
 * Inclus par admin/index.php via onglet 'debug'
 * Accès : super_admin uniquement
 */

if (!defined('ADMIN_HUB')) {
    require_once '../includes/auth.php';
    requireAdmin();
}

$BASE    = dirname(__DIR__);
$LOG_DIR = $BASE . '/logs';
$IS_PROD = str_contains($_SERVER['HTTP_HOST'] ?? '', 'cadurix.fr');

// ═══════════════════════════════════════════════════════════════
// AJAX — toutes les actions debug passent par dbg_action=
// ═══════════════════════════════════════════════════════════════
$action = $_GET['dbg_action'] ?? '';
if ($action) {
    header('Content-Type: application/json');
    header('X-Robots-Tag: noindex');

    // ── Vider un log ──────────────────────────────────────────
    if ($action === 'clear_log') {
        $log  = preg_replace('/[^a-z_]/', '', $_GET['log'] ?? '');
        $path = $LOG_DIR . '/' . $log . '.log';
        if ($log === 'php_errors') {
            $sys = ini_get('error_log');
            if ($sys && file_exists($sys)) $path = $sys;
        }
        if (file_exists($path) && is_writable($path)) {
            file_put_contents($path, '');
            die(json_encode(['ok' => true]));
        }
        if (!file_exists($path)) {
            @file_put_contents($path, '');
            die(json_encode(['ok' => true]));
        }
        die(json_encode(['ok' => false, 'error' => 'Non accessible : '.$path]));
    }

    // ── Lire un log ───────────────────────────────────────────
    if ($action === 'get_logs') {
        $log    = preg_replace('/[^a-z_]/', '', $_GET['log'] ?? 'php_errors');
        $lines  = min((int)($_GET['lines'] ?? 100), 1000);
        $filter = trim($_GET['filter'] ?? '');

        // PHP error log = log système si dispo
        $path = $LOG_DIR . '/' . $log . '.log';
        if ($log === 'php_errors') {
            $sys = ini_get('error_log');
            if ($sys && file_exists($sys)) $path = $sys;
        }

        if (!file_exists($path)) {
            die(json_encode(['lines'=>[],'total'=>0,'size'=>0,'mtime'=>0,'path'=>$path]));
        }

        $raw = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
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
            return ['raw' => $l, 'type' => dbg_level($l)];
        }, $raw);

        die(json_encode(['lines'=>$parsed,'total'=>$total,'size'=>filesize($path),'mtime'=>filemtime($path),'path'=>$path]));
    }

    // ── Tests système ────────────────────────────────────────
    if ($action === 'test_db') {
        try {
            $t = microtime(true);
            $pdo->query('SELECT 1');
            die(json_encode(['ok'=>true,'latency_ms'=>round((microtime(true)-$t)*1000,2)]));
        } catch (Exception $e) { die(json_encode(['ok'=>false,'error'=>$e->getMessage()])); }
    }

    if ($action === 'test_anthropic') {
        try {
            $key = $pdo->query("SELECT valeur FROM parametres WHERE cle='anthropic_api_key'")->fetchColumn();
            if (!$key) die(json_encode(['ok'=>false,'error'=>'Clé non configurée → admin IA']));
            $ch = curl_init('https://api.anthropic.com/v1/models');
            curl_setopt_array($ch,[
                CURLOPT_RETURNTRANSFER=>true,
                CURLOPT_HTTPHEADER=>['x-api-key: '.$key,'anthropic-version: 2023-06-01'],
                CURLOPT_TIMEOUT=>10, CURLOPT_SSL_VERIFYPEER=>$IS_PROD,
            ]);
            $t    = microtime(true);
            curl_exec($ch);
            $lat  = round((microtime(true)-$t)*1000,2);
            $code = curl_getinfo($ch,CURLINFO_HTTP_CODE);
            $cerr = curl_error($ch);
            curl_close($ch);
            if ($cerr) die(json_encode(['ok'=>false,'error'=>'cURL: '.$cerr]));
            die(json_encode(['ok'=>$code===200,'status'=>$code,'latency_ms'=>$lat,'error'=>$code!==200?'HTTP '.$code:null]));
        } catch (Exception $e) { die(json_encode(['ok'=>false,'error'=>$e->getMessage()])); }
    }

    if ($action === 'test_smtp') {
        try {
            $host = $pdo->query("SELECT valeur FROM parametres WHERE cle='smtp_host'")->fetchColumn();
            $user = $pdo->query("SELECT valeur FROM parametres WHERE cle='smtp_user'")->fetchColumn();
            if (!$host || !$user) die(json_encode(['ok'=>false,'error'=>'SMTP non configuré']));
            die(json_encode(['ok'=>true,'host'=>$host,'user'=>$user]));
        } catch (Exception $e) { die(json_encode(['ok'=>false,'error'=>$e->getMessage()])); }
    }

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

    // ── Score de santé ────────────────────────────────────────
    if ($action === 'health') {
        $score = 100; $issues = [];

        // BDD latence
        try {
            $t = microtime(true); $pdo->query('SELECT 1');
            $lat = (microtime(true)-$t)*1000;
            if ($lat > 500) { $score -= 10; $issues[] = "BDD lente ({$lat}ms)"; }
        } catch (Exception $e) { $score -= 30; $issues[] = 'BDD inaccessible'; }

        // Tables critiques
        $existing = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        foreach (['interventions_materiel','interventions_lignes','medicaments_ref','produits'] as $t) {
            if (!in_array($t, $existing)) { $score -= 15; $issues[] = "Table manquante : $t"; }
        }

        // Erreurs PHP récentes
        $phplog = ini_get('error_log');
        if ($phplog && file_exists($phplog)) {
            $tail = @file_get_contents($phplog, false, null, -30000) ?: '';
            $n    = substr_count(strtoupper($tail), 'FATAL ERROR');
            if ($n > 0) { $score -= min($n*5,20); $issues[] = "$n erreurs fatales PHP récentes"; }
        }

        // Erreurs JS
        $jslog = $LOG_DIR.'/js_errors.log';
        if (file_exists($jslog)) {
            $n = count(file($jslog, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) ?: []);
            if ($n > 50) { $score -= 5; $issues[] = "$n erreurs JS non traitées"; }
        }

        // Espace disque
        $free  = @disk_free_space($BASE) ?: 0;
        $total = @disk_total_space($BASE) ?: 1;
        $pct   = $free / $total * 100;
        if ($pct < 10) { $score -= 15; $issues[] = "Espace critique (".round($pct,1)."% libre)"; }
        elseif ($pct < 20) { $score -= 5; $issues[] = "Espace bas (".round($pct,1)."% libre)"; }

        // Permissions logs
        if (!is_writable($LOG_DIR)) { $score -= 10; $issues[] = 'logs/ non accessible en écriture'; }

        die(json_encode(['score'=>max(0,$score),'issues'=>$issues]));
    }

    // ── Stats BDD ─────────────────────────────────────────────
    if ($action === 'db_stats') {
        try {
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
        } catch (Exception $e) { die(json_encode(['error'=>$e->getMessage()])); }
    }

    // ── Disque ────────────────────────────────────────────────
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
                SELECT u.prenom, u.nom, u.role, u.last_seen, s.nom AS site_nom
                FROM utilisateurs u
                LEFT JOIN sites s ON u.site_id=s.id
                WHERE u.last_seen > DATE_SUB(NOW(),INTERVAL 30 MINUTE)
                ORDER BY u.last_seen DESC
            ")->fetchAll(PDO::FETCH_ASSOC);
            die(json_encode(['rows'=>$rows]));
        } catch (Exception $e) { die(json_encode(['error'=>$e->getMessage()])); }
    }

    // ── Timeline JS ───────────────────────────────────────────
    if ($action === 'timeline') {
        $jslog = $LOG_DIR.'/js_errors.log';
        $hours = array_fill(0, 24, ['e'=>0,'w'=>0]);
        if (file_exists($jslog)) {
            $lines = file($jslog, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) ?: [];
            foreach ($lines as $l) {
                $obj = json_decode($l, true);
                if (!$obj || empty($obj['time'])) continue;
                $h = (int)date('G', strtotime($obj['time']));
                $t = strtoupper($obj['type'] ?? '');
                if (str_contains($t,'ERROR')) $hours[$h]['e']++;
                else $hours[$h]['w']++;
            }
        }
        die(json_encode(['hours'=>$hours,'total'=>array_sum(array_column($hours,'e'))+array_sum(array_column($hours,'w'))]));
    }

    // ── Sécurité ──────────────────────────────────────────────────────────────
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
        } catch(Exception $e) {}
        try {
            $result['events'] = $pdo->query("
                SELECT action, ip, created_at as cree_le
                FROM audit_log
                WHERE action IN ('CONNEXION','DECONNEXION','LOGIN_FAILED','LOCKOUT')
                ORDER BY created_at DESC LIMIT 20
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch(Exception $e) {}
        die(json_encode($result));
    }

    die(json_encode(['ok'=>false,'error'=>'Action inconnue']));
}

// ═══════════════════════════════════════════════════════════════
// HELPER — Classify PHP log level
// ═══════════════════════════════════════════════════════════════
function dbg_level(string $s): string {
    $u = strtoupper($s);
    if (str_contains($u,'FATAL') || str_contains($u,'EXCEPTION')) return 'fatal';
    if (str_contains($u,'ERROR'))   return 'error';
    if (str_contains($u,'WARNING')) return 'warning';
    if (str_contains($u,'NOTICE'))  return 'notice';
    return 'info';
}

// ═══════════════════════════════════════════════════════════════
// INFOS PHP pour affichage
// ═══════════════════════════════════════════════════════════════
$PHP_INFO = [
    'Version PHP'      => PHP_VERSION,
    'SAPI'             => PHP_SAPI,
    'Environnement'    => $IS_PROD ? '🌐 PRODUCTION' : '💻 LOCAL',
    'error_log'        => ini_get('error_log') ?: '(système)',
    'display_errors'   => ini_get('display_errors') ? '⚠️ ON' : '✅ OFF',
    'log_errors'       => ini_get('log_errors') ? '✅ ON' : '❌ OFF',
    'memory_limit'     => ini_get('memory_limit'),
    'max_execution'    => ini_get('max_execution_time').'s',
    'upload_max'       => ini_get('upload_max_filesize'),
    'Heure serveur'    => date('d/m/Y H:i:s'),
];
?>
<!-- ═══════════════════════════════════════════════════════════
     CADURIX — DEBUG CENTER
     Styles préfixés .dc- pour éviter tout conflit avec l'admin
═══════════════════════════════════════════════════════════ -->
<style>
.dc{font-family:'JetBrains Mono','Consolas',monospace;--red:#ef4444;--orange:#f97316;--yellow:#eab308;--blue:#3b82f6;--green:#22c55e;--teal:#10b981}

/* ── Layout deux colonnes ── */
.dc-layout{display:grid;grid-template-columns:210px 1fr;border:1px solid var(--border);border-radius:14px;overflow:hidden;min-height:75vh;margin-top:8px}
.dc-aside{background:var(--bg2);border-right:1px solid var(--border);padding:16px 0;display:flex;flex-direction:column;gap:0}
.dc-aside-section{margin-bottom:22px}
.dc-aside-label{font-size:10px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.08em;padding:0 16px 8px;display:block}
.dc-nav{display:flex;align-items:center;gap:8px;padding:8px 16px;font-size:12.5px;color:var(--text2);cursor:pointer;transition:background .12s;border-right:2px solid transparent;white-space:nowrap}
.dc-nav:hover{background:var(--glass,rgba(255,255,255,.04));color:var(--text)}
.dc-nav.on{background:rgba(var(--primary-rgb),.1);color:var(--primary);border-right-color:var(--primary)}
.dc-nav-dot{margin-left:auto;width:7px;height:7px;border-radius:50%;display:none}
.dc-nav-dot.on{display:block;background:var(--red);animation:dc-pulse 2s infinite}
@keyframes dc-pulse{0%,100%{opacity:1}50%{opacity:.4}}

/* ── Zone contenu ── */
.dc-main{padding:22px;background:var(--bg);overflow-y:auto}
.dc-panel{display:none}
.dc-panel.on{display:block}

/* ── Toolbar haut ── */
.dc-bar{display:flex;align-items:center;gap:12px;padding:10px 16px;background:var(--bg2);border:1px solid var(--border);border-radius:10px;margin-bottom:18px;font-size:12px;flex-wrap:wrap}
.dc-bar-env{padding:3px 10px;border-radius:99px;font-size:11px;font-weight:700}
.dc-bar-sep{width:1px;height:16px;background:var(--border)}
.dc-bar-right{margin-left:auto;display:flex;align-items:center;gap:12px}
.dc-autorefresh{display:flex;align-items:center;gap:6px;color:var(--text2);cursor:pointer}
.dc-autorefresh input{accent-color:var(--primary);cursor:pointer}
.dc-refresh-ts{font-size:11px;color:var(--text3)}

/* ── Cards ── */
.dc-card{background:var(--bg2);border:1px solid var(--border);border-radius:10px;padding:18px;margin-bottom:16px}
.dc-card-head{display:flex;align-items:center;gap:10px;margin-bottom:16px}
.dc-card-head h3{font-size:13.5px;font-weight:700;color:var(--text);flex:1}
.dc-card-head .dc-acts{display:flex;gap:8px}

/* ── Score santé ── */
.dc-score-wrap{display:flex;align-items:center;gap:28px;margin-bottom:18px}
.dc-score-num{font-size:60px;font-weight:900;line-height:1;letter-spacing:-2px}
.dc-score-right{flex:1}
.dc-score-track{height:16px;background:var(--bg3);border-radius:8px;overflow:hidden;margin-bottom:8px}
.dc-score-fill{height:100%;border-radius:8px;transition:width .9s cubic-bezier(.16,1,.3,1)}
.dc-score-label{font-size:12px;color:var(--text3)}
.dc-issue{display:flex;align-items:center;gap:8px;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);border-radius:7px;padding:8px 12px;margin-bottom:6px;font-size:12px;color:#fca5a5}

/* ── Timeline ── */
.dc-timeline{height:110px;display:flex;align-items:flex-end;gap:3px;background:var(--bg2);border:1px solid var(--border);border-radius:10px;padding:12px 14px;margin-bottom:8px}
.dc-tbar{flex:1;border-radius:3px 3px 0 0;min-height:4px;cursor:default;position:relative}
.dc-tbar.now{outline:2px solid rgba(var(--primary-rgb),.6);outline-offset:1px;border-radius:3px}
.dc-tbar::before{content:attr(data-h);position:absolute;bottom:-16px;left:50%;transform:translateX(-50%);font-size:9px;color:var(--text3);display:none}
.dc-timeline:hover .dc-tbar::before{display:block}
.dc-tbar-tip{position:absolute;bottom:calc(100% + 6px);left:50%;transform:translateX(-50%);background:var(--bg2);border:1px solid var(--border);color:var(--text);font-size:10px;padding:3px 8px;border-radius:5px;white-space:nowrap;pointer-events:none;opacity:0;transition:opacity .15s;z-index:10}
.dc-tbar:hover .dc-tbar-tip{opacity:1}

/* ── Tests ── */
.dc-tests{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:12px}
.dc-test{background:var(--bg3);border:1px solid var(--border);border-radius:10px;padding:16px;display:flex;flex-direction:column;gap:6px}
.dc-test h4{font-size:11px;color:var(--text3);font-weight:600;margin-bottom:2px}
.dc-test-st{font-size:13px;font-weight:700}
.dc-test-st.ok{color:var(--green)}
.dc-test-st.fail{color:var(--red)}
.dc-test-st.wait{color:var(--text3)}
.dc-test-info{font-size:11px;color:var(--text3);line-height:1.5}
.dc-test-btn{margin-top:6px}

/* ── PHP info table ── */
.dc-kv{width:100%;border-collapse:collapse;font-size:12px}
.dc-kv td{padding:6px 10px;border-bottom:1px solid var(--border)}
.dc-kv td:first-child{color:var(--text3);width:42%}
.dc-kv tr:last-child td{border-bottom:none}

/* ── Disque ── */
.dc-disk-num{font-size:32px;font-weight:900;color:var(--text);margin-bottom:4px}
.dc-disk-sub{font-size:12px;color:var(--text3);margin-bottom:12px}
.dc-disk-track{height:16px;background:var(--bg3,#0f172a);border-radius:8px;overflow:hidden;margin-bottom:8px}
.dc-disk-fill{height:100%;border-radius:8px;transition:width .7s}
.dc-disk-ok{background:linear-gradient(90deg,#22c55e,#10b981)}
.dc-disk-warn{background:linear-gradient(90deg,#eab308,#f97316)}
.dc-disk-crit{background:linear-gradient(90deg,#ef4444,#dc2626)}

/* ── Log viewer ── */
.dc-log-bar{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:12px;align-items:center}
.dc-log-bar select,
.dc-log-bar input{background:var(--bg2);border:1px solid var(--border);color:var(--text);padding:5px 10px;border-radius:7px;font-family:inherit;font-size:12px;outline:none}
.dc-log-bar input:focus{border-color:var(--primary)}
.dc-log-meta{font-size:11px;color:var(--text3);margin-left:auto}
.dc-log-pills{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:10px}
.dc-pill{padding:3px 10px;border-radius:99px;font-size:11px;font-weight:700}
.dc-pill-fatal{background:rgba(239,68,68,.18);color:#fca5a5}
.dc-pill-error{background:rgba(249,115,22,.18);color:#fdba74}
.dc-pill-warn{background:rgba(234,179,8,.18);color:#fde047}
.dc-pill-notice{background:rgba(59,130,246,.18);color:#93c5fd}
.dc-pill-ok{background:rgba(34,197,94,.18);color:#86efac}

.dc-log-box{background:var(--bg2);border:1px solid var(--border);border-radius:10px;max-height:560px;overflow-y:auto;font-size:12px}
.dc-line{display:flex;align-items:flex-start;gap:10px;padding:5px 14px;border-bottom:1px solid var(--border);cursor:pointer;transition:background .08s;line-height:1.6}
.dc-line:hover{background:var(--primary-bg)}
.dc-line-n{color:var(--text3);min-width:38px;text-align:right;user-select:none;padding-top:1px;flex-shrink:0;font-size:11px}
.dc-line-ts{color:var(--text3);min-width:70px;flex-shrink:0;font-size:11px;padding-top:1px}
.dc-line-badge{font-size:9px;font-weight:800;padding:2px 7px;border-radius:4px;flex-shrink:0;min-width:68px;text-align:center;margin-top:3px;letter-spacing:.04em}
.dc-line-msg{flex:1;word-break:break-all;color:var(--text);font-size:11.5px}
.dc-line-sub{font-size:10px;color:var(--text3);margin-left:8px}
.dc-detail{display:none;background:var(--bg3);border:1px solid var(--border);border-radius:6px;padding:10px 14px;margin:4px 0 8px 48px;font-size:11px;white-space:pre-wrap;word-break:break-all;color:var(--text);border-left:3px solid var(--primary)}
.dc-line.open .dc-detail{display:block}

.dc-line.lv-fatal  {border-left:3px solid var(--red);background:rgba(239,68,68,.05)}
.dc-line.lv-error  {border-left:3px solid var(--orange);background:rgba(249,115,22,.04)}
.dc-line.lv-warning{border-left:3px solid var(--yellow)}
.dc-line.lv-notice {border-left:3px solid var(--blue)}
.dc-line.lv-info   {}

/* ── Badge styles par niveau ── */
.dc-lbadge-fatal  {background:rgba(239,68,68,.2);color:#fca5a5}
.dc-lbadge-error  {background:rgba(249,115,22,.2);color:#fdba74}
.dc-lbadge-warning{background:rgba(234,179,8,.2);color:#fde047}
.dc-lbadge-notice {background:rgba(59,130,246,.2);color:#93c5fd}
.dc-lbadge-info   {background:rgba(16,185,129,.15);color:var(--text2)}

/* ── BDD ── */
.dc-dbtable{width:100%;border-collapse:collapse;font-size:12px}
.dc-dbtable th{text-align:left;padding:6px 10px;border-bottom:2px solid var(--border);color:var(--text3);font-size:10px;text-transform:uppercase;letter-spacing:.05em}
.dc-dbtable td{padding:5px 10px;border-bottom:1px solid var(--border)}
.dc-dbtable tr:hover td{background:var(--glass,rgba(255,255,255,.03))}
.dc-tn{color:var(--primary);font-family:monospace}
.dc-szbar{display:inline-block;height:4px;background:var(--primary);border-radius:2px;vertical-align:middle;margin-left:8px;opacity:.5}

/* ── Bouton copier erreur ── */
.dc-copy-btn{margin-left:auto;flex-shrink:0;background:none;border:1px solid var(--border);border-radius:5px;padding:2px 7px;font-size:11px;cursor:pointer;color:var(--text3);transition:all .12s;white-space:nowrap}
.dc-copy-btn:hover{background:var(--primary);border-color:var(--primary);color:#fff}
.dc-copy-ok{background:var(--primary)!important;border-color:var(--primary)!important;color:#fff!important}

/* ── Onglets F12 ── */
.dc-net-table{width:100%;border-collapse:collapse;font-size:11.5px}
.dc-net-table th{text-align:left;padding:5px 10px;border-bottom:2px solid var(--border);color:var(--text3);font-size:10px;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap}
.dc-net-table td{padding:5px 10px;border-bottom:1px solid var(--border);vertical-align:middle}
.dc-net-table tr:hover td{background:var(--primary-bg)}
.dc-net-method{font-size:9px;font-weight:800;padding:2px 6px;border-radius:4px;white-space:nowrap}
.dc-net-2xx{background:rgba(34,197,94,.15);color:#22c55e}
.dc-net-4xx{background:rgba(249,115,22,.15);color:#f97316}
.dc-net-5xx{background:rgba(239,68,68,.15);color:#ef4444}
.dc-net-pen{background:rgba(234,179,8,.15);color:#eab308}
.dc-perf-bar{height:8px;border-radius:4px;background:var(--primary);display:inline-block;vertical-align:middle}
.dc-sec-row{display:flex;align-items:center;gap:10px;padding:8px 12px;border-bottom:1px solid var(--border);font-size:12px}
.dc-sec-row:hover{background:var(--primary-bg)}
.dc-console-line{display:flex;gap:10px;padding:4px 12px;border-bottom:1px solid var(--border);font-size:11.5px;align-items:flex-start}
.dc-console-line.cl-error{border-left:3px solid var(--red);background:rgba(239,68,68,.04)}
.dc-console-line.cl-warn{border-left:3px solid var(--yellow)}
.dc-console-line.cl-info{border-left:3px solid var(--blue)}

/* ── Sessions ── */
.dc-empty{padding:32px;text-align:center;color:var(--text3);font-size:13px}

/* ── Spinner ── */
.dc-spin{display:inline-block;width:14px;height:14px;border:2px solid var(--border);border-top-color:var(--primary);border-radius:50%;animation:dc-spin .6s linear infinite;vertical-align:middle}
@keyframes dc-spin{to{transform:rotate(360deg)}}

/* ── Responsive ── */
@media(max-width:720px){
  .dc-layout{grid-template-columns:1fr}
  .dc-aside{flex-direction:row;overflow-x:auto;border-right:none;border-bottom:1px solid var(--border);padding:0}
  .dc-aside-section{display:flex;margin:0}
  .dc-aside-label{display:none}
  .dc-nav{padding:10px 12px;border-right:none;border-bottom:2px solid transparent}
  .dc-nav.on{border-right:none;border-bottom-color:var(--primary)}
  .dc-tests{grid-template-columns:1fr 1fr}
}
</style>

<!-- ══════════════════════════════════════════════════════
     TOOLBAR
══════════════════════════════════════════════════════ -->
<div class="dc">
<div class="dc-bar">
  <span style="font-size:14px;font-weight:800;color:var(--text)">🐛 Debug Center</span>
  <div class="dc-bar-sep"></div>
  <span id="dc-health-badge" style="font-size:13px;font-weight:800">— /100</span>
  <div class="dc-bar-sep"></div>
  <span class="dc-bar-env <?= $IS_PROD ? 'style="background:rgba(239,68,68,.15);color:#fca5a5"' : 'style="background:rgba(99,102,241,.15);color:#a5b4fc"' ?>">
    <?= $IS_PROD ? '🌐 PRODUCTION' : '💻 LOCAL' ?>
  </span>
  <div class="dc-bar-right">
    <button class="btn btn-sm" onclick="dcDiag()" title="Copier un diagnostic complet pour Claude" style="font-size:11px;padding:5px 10px">📋 Diagnostic Claude</button>
    <div class="dc-bar-sep"></div>
    <button class="btn btn-sm btn-ghost" onclick="dcClearAll()" title="Vider tous les logs" style="font-size:11px;padding:5px 10px">🗑️ Tout effacer</button>
    <div class="dc-bar-sep"></div>
    <label class="dc-autorefresh">
      <input type="checkbox" id="dc-ar-cb" checked> Auto-refresh 30s
    </label>
    <span class="dc-refresh-ts" id="dc-ts">—</span>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════
     LAYOUT
══════════════════════════════════════════════════════ -->
<div class="dc-layout">

  <!-- ── Sidebar navigation ── -->
  <aside class="dc-aside">

    <div class="dc-aside-section">
      <span class="dc-aside-label">Vue d'ensemble</span>
      <div class="dc-nav on" onclick="dcTab('health',this)">❤️ Santé globale</div>
      <div class="dc-nav" onclick="dcTab('tests',this)">🔧 Tests système</div>
      <div class="dc-nav" onclick="dcTab('disk',this)">💾 Espace disque</div>
    </div>

    <div class="dc-aside-section">
      <span class="dc-aside-label">Logs</span>
      <div class="dc-nav" onclick="dcTab('lphp',this)">
        🐘 PHP Errors <span class="dc-nav-dot" id="dc-dot-php"></span>
      </div>
      <div class="dc-nav" onclick="dcTab('ljs',this)">
        ⚡ JS Errors <span class="dc-nav-dot" id="dc-dot-js"></span>
      </div>
      <div class="dc-nav" onclick="dcTab('lscan',this)">📷 Scanner</div>
      <div class="dc-nav" onclick="dcTab('lapp',this)">📋 App</div>
      <div class="dc-nav" onclick="dcTab('lbackup',this)">💾 Backup</div>
    </div>

    <div class="dc-aside-section">
      <span class="dc-aside-label">Base de données</span>
      <div class="dc-nav" onclick="dcTab('db',this)">🗃️ Tables & Stats</div>
      <div class="dc-nav" onclick="dcTab('sessions',this)">👥 Sessions actives</div>
    </div>
    <div class="dc-aside-section">
      <span class="dc-aside-label">Outils F12</span>
      <div class="dc-nav" onclick="dcTab('network',this)">🌐 Réseau</div>
      <div class="dc-nav" onclick="dcTab('perf',this)">⚡ Performance</div>
      <div class="dc-nav" onclick="dcTab('security',this)">🔒 Sécurité</div>
      <div class="dc-nav" onclick="dcTab('console',this)">💬 Console live</div>
    </div>

  </aside>

  <!-- ── Panels ── -->
  <main class="dc-main">

    <!-- ══ SANTÉ ══ -->
    <div class="dc-panel on" id="dc-p-health">
      <div class="dc-card">
        <div class="dc-card-head">
          <h3>❤️ Score de santé</h3>
          <div class="dc-acts">
            <button class="btn btn-sm btn-ghost" onclick="dcHealth()">↻ Recalculer</button>
          </div>
        </div>
        <div id="dc-health-body"><span class="dc-spin"></span></div>
      </div>
      <div class="dc-card">
        <div class="dc-card-head"><h3>🖥️ Environnement serveur</h3></div>
        <div id="dc-env-body"><span class="dc-spin"></span></div>
      </div>
      <div class="dc-card">
        <div class="dc-card-head"><h3>📊 Timeline erreurs JS — 24 heures glissantes</h3></div>
        <div class="dc-timeline" id="dc-timeline"><div style="margin:auto;color:var(--text3);font-size:12px">Chargement…</div></div>
        <div id="dc-tl-legend" style="font-size:11px;color:var(--text3);padding-top:4px"></div>
      </div>
    </div>

    <!-- ══ TESTS ══ -->
    <div class="dc-panel" id="dc-p-tests">
      <div class="dc-card">
        <div class="dc-card-head">
          <h3>🔧 Tests système en temps réel</h3>
          <div class="dc-acts">
            <button class="btn btn-sm" onclick="dcTestAll()">▶ Tout tester</button>
          </div>
        </div>
        <div class="dc-tests">
          <?php foreach ([
            ['db',        '🗃️','Base de données',   'test_db'],
            ['anthropic', '🤖','API Anthropic',      'test_anthropic'],
            ['smtp',      '📧','SMTP Email',         'test_smtp'],
            ['perms',     '📁','Permissions dossiers','test_perms'],
          ] as [$id, $ico, $lbl, $act]): ?>
          <div class="dc-test">
            <h4><?= $ico ?> <?= $lbl ?></h4>
            <div class="dc-test-st wait" id="dc-ts-<?= $id ?>">En attente…</div>
            <div class="dc-test-info" id="dc-ti-<?= $id ?>">—</div>
            <button class="btn btn-sm btn-ghost dc-test-btn" onclick="dcTest('<?= $id ?>','<?= $act ?>')">Tester</button>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="dc-card">
        <div class="dc-card-head"><h3>⚙️ Environnement PHP</h3></div>
        <table class="dc-kv">
          <?php foreach ($PHP_INFO as $k => $v): ?>
          <tr><td><?= $k ?></td><td><?= htmlspecialchars($v) ?></td></tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>

    <!-- ══ DISQUE ══ -->
    <div class="dc-panel" id="dc-p-disk">
      <div class="dc-card">
        <div class="dc-card-head">
          <h3>💾 Espace disque serveur</h3>
          <div class="dc-acts"><button class="btn btn-sm btn-ghost" onclick="dcDisk()">↻</button></div>
        </div>
        <div id="dc-disk-body"><span class="dc-spin"></span></div>
      </div>
    </div>

    <!-- ══ LOGS ══ -->
    <?php
    $log_defs = [
      'lphp'    => ['🐘 PHP Errors',    'php_errors'],
      'ljs'     => ['⚡ JS Errors',     'js_errors'],
      'lscan'   => ['📷 Scanner Debug', 'scan_debug'],
      'lapp'    => ['📋 App Log',       'app'],
      'lbackup' => ['💾 Backup Log',    'backup'],
    ];
    foreach ($log_defs as $pid => [$title, $lkey]):
    ?>
    <div class="dc-panel" id="dc-p-<?= $pid ?>">
      <div class="dc-card">
        <div class="dc-card-head">
          <h3><?= $title ?></h3>
          <div class="dc-acts">
            <button class="btn btn-sm btn-ghost" onclick="dcLog('<?= $lkey ?>')">↻ Rafraîchir</button>
            <button class="btn btn-sm btn-danger" onclick="dcClear('<?= $lkey ?>')">🗑️ Vider</button>
          </div>
        </div>
        <div class="dc-log-bar">
          <select id="dc-ln-<?= $lkey ?>" onchange="dcLog('<?= $lkey ?>')">
            <option value="50">50 lignes</option>
            <option value="100" selected>100 lignes</option>
            <option value="200">200 lignes</option>
            <option value="500">500 lignes</option>
          </select>
          <select id="dc-lv-<?= $lkey ?>" onchange="dcLog('<?= $lkey ?>')" style="background:var(--bg2);border:1px solid var(--border);color:var(--text);padding:5px 10px;border-radius:7px;font-family:inherit;font-size:12px;outline:none">
            <option value="">Tous niveaux</option>
            <option value="FATAL">💀 Fatal</option>
            <option value="ERROR">🔴 Error</option>
            <option value="WARNING">🟡 Warning</option>
            <option value="DEBUG">🔵 Debug</option>
            <option value="INFO">ℹ️ Info</option>
          </select>
          <input type="text" id="dc-fi-<?= $lkey ?>" placeholder="Filtrer… ex : terrain.php"
                 onkeydown="if(event.key==='Enter')dcLog('<?= $lkey ?>')">
          <button class="btn btn-sm" onclick="dcLog('<?= $lkey ?>')">🔍</button>
          <span class="dc-log-meta" id="dc-lm-<?= $lkey ?>"></span>
        </div>
        <div class="dc-log-pills" id="dc-lp-<?= $lkey ?>"></div>
        <div class="dc-log-box" id="dc-lb-<?= $lkey ?>">
          <div class="dc-empty">Cliquez sur ↻ pour charger les logs</div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>

    <!-- ══ BDD ══ -->
    <div class="dc-panel" id="dc-p-db">
      <div class="dc-card">
        <div class="dc-card-head">
          <h3>🗃️ Tables & Statistiques</h3>
          <div class="dc-acts"><button class="btn btn-sm btn-ghost" onclick="dcDb()">↻ Rafraîchir</button></div>
        </div>
        <div id="dc-db-body"><span class="dc-spin"></span></div>
      </div>
    </div>

    <!-- ══ RÉSEAU ══ -->
    <div class="dc-panel" id="dc-p-network">
      <div class="dc-card">
        <div class="dc-card-head">
          <h3>🌐 Réseau — Erreurs fetch/XHR</h3>
          <div class="dc-acts"><button class="btn btn-sm btn-ghost" onclick="window.dcLoadNetwork&&dcLoadNetwork()">↻</button></div>
        </div>
        <p style="font-size:11px;color:var(--text3);margin-bottom:10px">Erreurs réseau capturées automatiquement. Clic 📋 = copier pour Claude.</p>
        <table class="dc-net-table">
          <thead><tr><th>#</th><th>Heure</th><th>Type</th><th>URL</th><th>Page</th><th></th></tr></thead>
          <tbody id="dc-net-rows"><tr><td colspan="6" class="dc-empty">Cliquez ↻</td></tr></tbody>
        </table>
      </div>
    </div>

    <!-- ══ PERFORMANCE ══ -->
    <div class="dc-panel" id="dc-p-perf">
      <div class="dc-card">
        <div class="dc-card-head"><h3>⚡ Performance — Pages &amp; Erreurs</h3>
          <div class="dc-acts"><button class="btn btn-sm btn-ghost" onclick="window.dcLoadPerf&&dcLoadPerf()">↻</button></div>
        </div>
        <div id="dc-perf-body"><div class="dc-empty">Cliquez ↻</div></div>
      </div>
    </div>

    <!-- ══ SÉCURITÉ ══ -->
    <div class="dc-panel" id="dc-p-security">
      <div class="dc-card">
        <div class="dc-card-head"><h3>🔒 Sécurité — Connexions &amp; Tentatives</h3>
          <div class="dc-acts"><button class="btn btn-sm btn-ghost" onclick="window.dcLoadSecurity&&dcLoadSecurity()">↻</button></div>
        </div>
        <div id="dc-sec-body"><div class="dc-empty">Cliquez ↻</div></div>
      </div>
    </div>

    <!-- ══ CONSOLE LIVE ══ -->
    <div class="dc-panel" id="dc-p-console">
      <div class="dc-card">
        <div class="dc-card-head"><h3>💬 Console live — JS temps réel</h3>
          <div class="dc-acts">
            <button class="btn btn-sm btn-ghost" onclick="window.dcLoadConsole&&dcLoadConsole()">↻</button>
            <button class="btn btn-sm btn-danger" onclick="window.dcConsoleClear&&dcConsoleClear()">🗑️ Vider</button>
          </div>
        </div>
        <p style="font-size:11px;color:var(--text3);margin-bottom:10px">Clic sur une ligne = copier l'erreur complète pour Claude.</p>
        <div class="dc-log-box" id="dc-console-box" style="max-height:500px">
          <div class="dc-empty">Cliquez ↻</div>
        </div>
      </div>
    </div>

    <!-- ══ SESSIONS ══ -->
    <div class="dc-panel" id="dc-p-sessions">
      <div class="dc-card">
        <div class="dc-card-head">
          <h3>👥 Sessions actives — 30 dernières minutes</h3>
          <div class="dc-acts"><button class="btn btn-sm btn-ghost" onclick="dcSessions()">↻</button></div>
        </div>
        <div id="dc-sess-body"><span class="dc-spin"></span></div>
      </div>
    </div>

  </main>
</div><!-- /layout -->
</div><!-- /dc -->

<script>
(function(){
'use strict';

// ── URL de base (on est dans admin/index.php?tab=debug) ──────
const API = '/admin/debug_ajax.php?';
const Q   = p => fetch(API + new URLSearchParams(p)).then(r => {
  if (!r.ok) throw new Error('HTTP ' + r.status);
  return r.text().then(t => {
    try { return JSON.parse(t); }
    catch(e) { console.error('JSON parse error:', t.slice(0,200)); throw new Error('Réponse invalide du serveur'); }
  });
});

// ── Onglets ──────────────────────────────────────────────────
let current = 'health';

window.dcTab = function(id, el) {
  document.querySelectorAll('.dc-panel').forEach(p => p.classList.remove('on'));
  document.querySelectorAll('.dc-nav').forEach(n => n.classList.remove('on'));
  const panel = document.getElementById('dc-p-' + id);
  if (panel) panel.classList.add('on');
  if (el) el.classList.add('on');
  current = id;
  dcLoad(id);
};

function dcLoad(id) {
  if (id === 'health')   { dcHealth(); dcTimeline(); dcEnv(); }
  if (id === 'tests')    dcTestAll();
  if (id === 'disk')     dcDisk();
  if (id === 'db')       dcDb();
  if (id === 'sessions') dcSessions();
  const logMap = {lphp:'php_errors',ljs:'js_errors',lscan:'scan_debug',lapp:'app',lbackup:'backup'};
  if (logMap[id]) dcLog(logMap[id]);
}

// ── Auto-refresh ─────────────────────────────────────────────
let timer = null;
document.getElementById('dc-ar-cb').addEventListener('change', function() {
  clearInterval(timer);
  if (this.checked) startAR();
});
function startAR() {
  clearInterval(timer);
  timer = setInterval(() => { dcLoad(current); dcCheckDots(); }, 30000);
}
startAR();

function dcCheckDots() {
  [['php_errors','php'],['js_errors','js']].forEach(([log,key]) => {
    Q({dbg_action:'get_logs',log,lines:1}).then(d => {
      const dot = document.getElementById('dc-dot-' + key);
      if (dot && (d.total||0) > 0) dot.classList.add('on');
    }).catch(()=>{});
  });
}

function ts() {
  document.getElementById('dc-ts').textContent = 'Actualisé ' + new Date().toLocaleTimeString('fr-FR');
}

// ── Santé ────────────────────────────────────────────────────
window.dcHealth = function() {
  Q({dbg_action:'health'}).then(d => {
    const s = (d && d.score !== undefined) ? d.score : 0;
    const c = s >= 80 ? '#22c55e' : s >= 50 ? '#eab308' : '#ef4444';
    const badge = document.getElementById('dc-health-badge');
    if (badge) { badge.textContent = s + '/100'; badge.style.color = c; }

    let h = `<div class="dc-score-wrap">
      <div class="dc-score-num" style="color:${c}">${s}</div>
      <div class="dc-score-right">
        <div class="dc-score-track">
          <div class="dc-score-fill" style="width:${s}%;background:${c}"></div>
        </div>
        <div class="dc-score-label">
          ${s>=80 ? '✅ Système opérationnel' : s>=50 ? '⚠️ Des problèmes ont été détectés' : '🚨 Attention requise immédiatement'}
        </div>
      </div>
    </div>`;

    if (d.issues && d.issues.length) {
      h += '<div style="font-size:11px;font-weight:700;color:var(--text3);margin-bottom:8px;text-transform:uppercase;letter-spacing:.05em">Problèmes détectés</div>';
      d.issues.forEach(i => { h += `<div class="dc-issue">⚠️ ${esc(i)}</div>`; });
    } else {
      h += '<div style="color:#22c55e;font-size:13px;margin-top:4px">✅ Aucun problème détecté</div>';
    }

    document.getElementById('dc-health-body').innerHTML = h;
    ts();
  }).catch(e => { document.getElementById('dc-health-body').innerHTML = err(e.message); });
};

// ── Timeline ─────────────────────────────────────────────────
window.dcTimeline = function() {
  Q({dbg_action:'timeline'}).then(d => {
    const hours = d.hours || [];
    const now   = new Date().getHours();
    const maxV  = Math.max(1, ...hours.map(h => h.e + h.w));
    const chart = document.getElementById('dc-timeline');
    chart.innerHTML = '';
    hours.forEach((h, i) => {
      const tot = h.e + h.w;
      const pct = Math.max(5, (tot / maxV) * 100);
      const col = h.e > 0 ? '#f97316' : tot > 0 ? '#eab308' : 'rgba(255,255,255,.06)';
      const div = document.createElement('div');
      div.className = 'dc-tbar' + (i === now ? ' now' : '');
      div.style.cssText = `height:${pct}%;background:${col};opacity:${i===now?1:.6}`;
      div.setAttribute('data-h', String(i) + 'h');
      div.innerHTML = `<span class="dc-tbar-tip">${i}h00 — ${tot} erreur${tot!==1?'s':''}</span>`;
      chart.appendChild(div);
    });
    const lg = document.getElementById('dc-tl-legend');
    if (lg) lg.textContent = `${d.total||0} erreurs JS sur les 24h — barre orange = JS Error / jaune = autre`;
  }).catch(()=>{});
};

// ── Tests ────────────────────────────────────────────────────
window.dcTest = function(id, action) {
  const st = document.getElementById('dc-ts-' + id);
  const ti = document.getElementById('dc-ti-' + id);
  if (!st || !ti) return;
  st.className = 'dc-test-st wait';
  st.innerHTML = '<span class="dc-spin"></span>';
  ti.textContent = '—';
  Q({dbg_action: action}).then(d => {
    if (d.ok !== false && !d.error) {
      st.className = 'dc-test-st ok';
      st.textContent = '✅ OK';
      if (d.latency_ms) ti.textContent = 'Latence : ' + d.latency_ms + ' ms';
      else if (d.host)  ti.textContent = d.host + ' · ' + (d.user||'');
      else if (typeof d === 'object') {
        // Permissions
        ti.innerHTML = Object.entries(d).map(([k,v]) =>
          (v.exists ? (v.writable ? '✅ ' : '⚠️ ') : '❌ ') + k
        ).join('<br>');
      }
    } else {
      st.className = 'dc-test-st fail';
      st.textContent = '❌ Erreur';
      ti.textContent = d.error || 'Erreur inconnue';
    }
  }).catch(e => {
    st.className = 'dc-test-st fail';
    st.textContent = '❌ Requête échouée';
    ti.textContent = e.message;
  });
};

window.dcTestAll = function() {
  [['db','test_db'],['anthropic','test_anthropic'],['smtp','test_smtp'],['perms','test_perms']]
    .forEach(([id,act]) => dcTest(id, act));
};

// ── Logs ─────────────────────────────────────────────────────
window.dcLog = function(lkey) {
  const lines  = document.getElementById('dc-ln-' + lkey)?.value || 100;
  const filter = document.getElementById('dc-fi-' + lkey)?.value || '';
  const box    = document.getElementById('dc-lb-' + lkey);
  if (!box) return;
  box.innerHTML = '<div class="dc-empty"><span class="dc-spin"></span> Chargement…</div>';

  const levelFilter = document.getElementById('dc-lv-' + lkey)?.value || '';
  const filterFull  = [filter, levelFilter].filter(Boolean).join(' ');
  Q({dbg_action:'get_logs', log:lkey, lines, filter:filterFull}).then(d => {
    // Méta
    const meta = document.getElementById('dc-lm-' + lkey);
    if (meta) {
      const sz = d.size ? (d.size/1024).toFixed(1)+' KB' : '';
      const mt = d.mtime ? new Date(d.mtime*1000).toLocaleTimeString('fr-FR') : '';
      meta.textContent = [d.total+' lignes', sz, mt ? 'modifié '+mt : ''].filter(Boolean).join(' · ');
    }

    if (!d.lines || !d.lines.length) {
      box.innerHTML = '<div class="dc-empty">✅ Log vide — aucune erreur enregistrée</div>';
      document.getElementById('dc-lp-' + lkey).innerHTML =
        '<span class="dc-pill dc-pill-ok">✅ Aucune erreur</span>';
      return;
    }

    // Pills stats
    dcPills(lkey, d.lines);

    // Lignes
    box.innerHTML = d.lines.map((line, i) =>
      lkey === 'js_errors' && typeof line === 'object' && !line.raw
        ? renderJs(line, i)
        : renderPhp(line, i)
    ).join('');

    ts();
  }).catch(e => {
    box.innerHTML = '<div class="dc-empty" style="color:#ef4444">'+esc(e.message)+'</div>';
  });
};

function renderPhp(line, i) {
  const raw = typeof line === 'string' ? line : (line.raw || '');
  const lv  = phpLevel(raw);
  const copyText = raw;
  return `<div class="dc-line lv-${lv}" onclick="this.classList.toggle('open')">
    <span class="dc-line-n">${i+1}</span>
    <span class="dc-line-badge dc-lbadge-${lv}">${lv.toUpperCase()}</span>
    <span class="dc-line-msg">${esc(raw)}</span>
    <button class="dc-copy-btn" title="Copier l'erreur" onclick="event.stopPropagation();dcCopyErr(${JSON.stringify(copyText)})">📋</button>
  </div>`;
}

function renderJs(obj, i) {
  const lv   = jsLevel(obj.type);
  const time = obj.time ? String(obj.time).slice(11,19) : '';
  const detail = [
    obj.stack    ? 'Stack trace :\n' + obj.stack : '',
    obj.source   ? 'Fichier : ' + obj.source + ' ligne ' + obj.line + ' col ' + obj.col : '',
    obj.page_url ? 'Page : ' + obj.page_url : '',
    obj.user_id  ? 'Utilisateur : #' + obj.user_id + ' (' + (obj.user_role||'') + ')' : '',
    obj.ua       ? 'Navigateur : ' + String(obj.ua).slice(0,100) : '',
  ].filter(Boolean).join('\n\n');

  const copyFull = [
    'Type: '+(obj.type||'JS'),
    'Message: '+(obj.message||''),
    time ? 'Heure: '+time : '',
    detail,
  ].filter(Boolean).join('\n');
  return `<div class="dc-line lv-${lv}" onclick="this.classList.toggle('open')">
    <span class="dc-line-n">${i+1}</span>
    <span class="dc-line-ts">${esc(time)}</span>
    <span class="dc-line-badge dc-lbadge-${lv}">${esc(obj.type||'JS')}</span>
    <div class="dc-line-msg">
      <strong>${esc(obj.message||'')}</strong>
      ${obj.page_name ? `<span class="dc-line-sub">${esc(obj.page_name)}</span>` : ''}
      ${detail ? `<div class="dc-detail">${esc(detail)}</div>` : ''}
    </div>
    <button class="dc-copy-btn" title="Copier pour Claude" onclick="event.stopPropagation();dcCopyErr(${JSON.stringify(copyFull)})">📋</button>
  </div>`;
}

function dcPills(lkey, lines) {
  const el = document.getElementById('dc-lp-' + lkey);
  if (!el) return;
  const c = {fatal:0, error:0, warning:0, notice:0};
  lines.forEach(l => {
    const raw = typeof l === 'string' ? l : (l.raw || l.message || l.type || '');
    const lv  = phpLevel(raw);
    c[lv] = (c[lv]||0) + 1;
  });
  let h = '';
  if (c.fatal)   h += `<span class="dc-pill dc-pill-fatal">💀 Fatal : ${c.fatal}</span>`;
  if (c.error)   h += `<span class="dc-pill dc-pill-error">🔴 Error : ${c.error}</span>`;
  if (c.warning) h += `<span class="dc-pill dc-pill-warn">🟡 Warning : ${c.warning}</span>`;
  if (c.notice)  h += `<span class="dc-pill dc-pill-notice">🔵 Notice : ${c.notice}</span>`;
  if (!h)        h  = '<span class="dc-pill dc-pill-ok">✅ Aucune erreur</span>';
  el.innerHTML = h;
}

window.dcClearAll = function() {
  if (!confirm('Vider TOUS les logs (PHP, JS, App, Scanner, Backup) ?')) return;
  const logs = ['php_errors','js_errors','app','scan_debug','backup'];
  Promise.all(logs.map(l => Q({dbg_action:'clear_log',log:l}))).then(() => {
    dcLoad(current);
    alert('✅ Tous les logs ont été vidés');
  }).catch(e => alert('Erreur : ' + e.message));
};

window.dcClear = function(lkey) {
  if (!confirm('Vider le log "' + lkey + '" ?')) return;
  Q({dbg_action:'clear_log', log:lkey}).then(d => {
    if (d.ok) dcLog(lkey); else alert('Erreur : ' + (d.error||'?'));
  }).catch(e => alert(e.message));
};

// ── Disque ───────────────────────────────────────────────────
window.dcDisk = function() {
  Q({dbg_action:'disk'}).then(d => {
    if (!d || d.error) { document.getElementById('dc-disk-body').innerHTML = err(d?.error || 'Erreur'); return; }
    const p = d.used_pct ?? 0;
    const cls = p > 90 ? 'dc-disk-crit' : p > 75 ? 'dc-disk-warn' : 'dc-disk-ok';
    document.getElementById('dc-disk-body').innerHTML = `
      <div class="dc-disk-num">${d.free_gb} GB libres</div>
      <div class="dc-disk-sub">sur ${d.total_gb} GB total — ${p}% utilisé</div>
      <div class="dc-disk-track"><div class="${cls}" style="height:100%;width:${p}%;border-radius:8px;transition:width .7s"></div></div>
      <div style="font-size:12px;color:var(--text3)">
        ${p>90 ? '🚨 Espace critique !' : p>75 ? '⚠️ Espace bas' : '✅ Espace suffisant'}
      </div>`;
    ts();
  }).catch(e => { document.getElementById('dc-disk-body').innerHTML = err(e.message); });
};

// ── BDD ──────────────────────────────────────────────────────
window.dcDb = function() {
  Q({dbg_action:'db_stats'}).then(d => {
    if (d.error) { document.getElementById('dc-db-body').innerHTML = err(d.error); return; }
    const crit   = ['interventions_materiel','interventions_lignes','medicaments_ref','produits','utilisateurs','sites'];
    const maxSz  = Math.max(1, ...d.tables.map(t => parseFloat(t.size_kb||0)));
    let h = `
      <div style="display:flex;align-items:center;gap:16px;margin-bottom:14px;font-size:12px">
        <span>BDD : <strong style="color:var(--primary)">${esc(d.db)}</strong></span>
        <span style="color:var(--text3)">${d.tables.length} tables</span>
        <span style="color:#22c55e;font-weight:700">${d.sessions} session${d.sessions!==1?'s':''} active${d.sessions!==1?'s':''}</span>
      </div>
      <table class="dc-dbtable">
        <thead><tr><th>Table</th><th>Lignes</th><th>Taille</th><th>Modifiée</th></tr></thead>
        <tbody>`;
    d.tables.forEach(t => {
      const isCrit = crit.includes(t.table_name);
      const bw     = Math.max(4, (parseFloat(t.size_kb||0)/maxSz)*80);
      const upd    = t.update_time ? new Date(t.update_time).toLocaleDateString('fr-FR') : '—';
      h += `<tr>
        <td class="dc-tn" style="${isCrit?'font-weight:800':''}">${esc(t.table_name)}${isCrit?' ⭐':''}</td>
        <td>${parseInt(t.table_rows||0).toLocaleString('fr-FR')}</td>
        <td>${parseFloat(t.size_kb||0).toFixed(1)} KB<span class="dc-szbar" style="width:${bw}px"></span></td>
        <td style="color:var(--text3)">${upd}</td>
      </tr>`;
    });
    h += '</tbody></table>';
    document.getElementById('dc-db-body').innerHTML = h;
    ts();
  }).catch(e => { document.getElementById('dc-db-body').innerHTML = err(e.message); });
};

// ── Sessions ─────────────────────────────────────────────────
window.dcSessions = function() {
  Q({dbg_action:'sessions'}).then(d => {
    if (d.error) { document.getElementById('dc-sess-body').innerHTML = err(d.error); return; }
    if (!d.rows || !d.rows.length) {
      document.getElementById('dc-sess-body').innerHTML = '<div class="dc-empty">Aucune session active en ce moment</div>';
      return;
    }
    let h = `<table class="dc-dbtable"><thead><tr><th>Utilisateur</th><th>Rôle</th><th>Site</th><th>Dernière activité</th></tr></thead><tbody>`;
    d.rows.forEach(u => {
      const ago = Math.floor((Date.now() - new Date(u.last_seen).getTime()) / 1000);
      const s   = ago < 60 ? ago+'s' : Math.floor(ago/60)+'min';
      h += `<tr>
        <td>${esc(u.prenom+' '+u.nom)}</td>
        <td style="color:var(--primary)">${esc(u.role)}</td>
        <td>${esc(u.site_nom||'—')}</td>
        <td style="color:var(--text3)">il y a ${s}</td>
      </tr>`;
    });
    h += '</tbody></table>';
    document.getElementById('dc-sess-body').innerHTML = h;
    ts();
  }).catch(e => { document.getElementById('dc-sess-body').innerHTML = err(e.message); });
};

// ── Helpers ──────────────────────────────────────────────────
function phpLevel(s) {
  const u = s.toUpperCase();
  if (u.includes('FATAL') || u.includes('EXCEPTION')) return 'fatal';
  if (u.includes('ERROR'))   return 'error';
  if (u.includes('WARNING')) return 'warning';
  if (u.includes('NOTICE'))  return 'notice';
  return 'info';
}
function jsLevel(t) {
  const u = (t||'').toUpperCase();
  if (u.includes('FATAL'))                             return 'fatal';
  if (u.includes('ERROR') || u.includes('REJECT'))    return 'error';
  if (u.includes('WARN')  || u.includes('NETWORK'))   return 'warning';
  return 'info';
}
function esc(s) {
  return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function err(msg) {
  return `<div style="color:#ef4444;padding:16px">❌ ${esc(msg)}</div>`;
}

// ── Copier erreur ────────────────────────────────────────────
window.dcCopyErr = function(text) {
  navigator.clipboard?.writeText(text).then(() => {
    const all = document.querySelectorAll('.dc-copy-btn');
    all.forEach(b => { if (b.textContent === '📋') { b.classList.add('dc-copy-ok'); b.textContent = '✅'; setTimeout(() => { b.classList.remove('dc-copy-ok'); b.textContent = '📋'; }, 1200); } });
  }).catch(() => { const ta = document.createElement('textarea'); ta.value = text; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta); });
};

// ════════════════════════════════════════════════════════════
//  ONGLETS F12
// ════════════════════════════════════════════════════════════

// ── 🌐 RÉSEAU ────────────────────────────────────────────────
window.dcLoadNetwork = function() {
  const box = document.getElementById('dc-net-rows');
  if (!box) return;
  box.innerHTML = '<tr><td colspan="6" class="dc-empty"><span class="dc-spin"></span> Chargement…</td></tr>';

  // Charger les erreurs réseau depuis js_errors.log (type FETCH_*)
  Q({dbg_action:'get_logs', log:'js_errors', lines:200}).then(d => {
    const nets = (d.lines || []).filter(l =>
      l.type && (l.type.includes('FETCH') || l.type.includes('NETWORK') || l.type.includes('XHR'))
    );
    if (!nets.length) {
      box.innerHTML = '<tr><td colspan="6" class="dc-empty">✅ Aucune erreur réseau enregistrée</td></tr>';
      return;
    }
    box.innerHTML = nets.map((l, i) => {
      const time = l.time ? String(l.time).slice(11,19) : '—';
      const type = l.type || 'FETCH';
      const url  = l.source || '—';
      const page = l.page_name || '—';
      const cls  = type.includes('ERROR') || type.includes('NETWORK') ? 'dc-net-5xx' : 'dc-net-4xx';
      const copy = `Type: ${type}
Message: ${l.message||''}
URL: ${url}
Page: ${page}
Heure: ${time}`;
      return `<tr>
        <td>${i+1}</td>
        <td style="color:var(--text3)">${esc(time)}</td>
        <td><span class="dc-net-method ${cls}">${esc(type)}</span></td>
        <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(url)}">${esc(url)}</td>
        <td style="color:var(--text3)">${esc(page)}</td>
        <td><button class="dc-copy-btn" onclick="dcCopyErr(${JSON.stringify(copy)})">📋</button></td>
      </tr>`;
    }).join('');
    ts();
  }).catch(e => { box.innerHTML = `<tr><td colspan="6">${err(e.message)}</td></tr>`; });
};

// ── ⚡ PERFORMANCE ────────────────────────────────────────────
window.dcLoadPerf = function() {
  const body = document.getElementById('dc-perf-body');
  if (!body) return;
  body.innerHTML = '<div class="dc-empty"><span class="dc-spin"></span> Chargement…</div>';

  Q({dbg_action:'get_logs', log:'js_errors', lines:500}).then(d => {
    const lines = d.lines || [];
    if (!lines.length) {
      body.innerHTML = '<div class="dc-empty">✅ Aucune donnée de performance</div>';
      return;
    }

    // Pages avec le plus d'erreurs
    const pages = {};
    lines.forEach(l => {
      const p = l.page_name || l.page_url || 'Inconnu';
      pages[p] = (pages[p] || 0) + 1;
    });
    const sorted = Object.entries(pages).sort((a,b) => b[1]-a[1]).slice(0,15);
    const maxV = sorted[0]?.[1] || 1;

    // Répartition par type
    const types = {};
    lines.forEach(l => { const t = l.type||'JS'; types[t] = (types[t]||0)+1; });

    let h = `
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">
      <div class="dc-card" style="margin:0">
        <div class="dc-card-head"><h3>📊 Total erreurs</h3></div>
        <div style="font-size:48px;font-weight:900;color:var(--primary);line-height:1">${lines.length}</div>
        <div style="font-size:12px;color:var(--text3);margin-top:4px">sur les dernières ${d.total} entrées</div>
      </div>
      <div class="dc-card" style="margin:0">
        <div class="dc-card-head"><h3>📈 Types d'erreurs</h3></div>
        ${Object.entries(types).slice(0,5).map(([t,n]) =>
          `<div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:6px">
            <span style="color:var(--text2)">${esc(t)}</span>
            <span style="font-weight:700;color:var(--primary)">${n}</span>
          </div>`
        ).join('')}
      </div>
    </div>
    <div class="dc-card" style="margin:0">
      <div class="dc-card-head"><h3>🗂️ Pages avec le plus d'erreurs</h3></div>
      ${sorted.map(([p,n]) => {
        const w = Math.max(4, (n/maxV)*100);
        const col = n > 10 ? '#ef4444' : n > 5 ? '#f97316' : '#3b82f6';
        return `<div style="margin-bottom:10px">
          <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px">
            <span style="color:var(--text2);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:80%">${esc(p)}</span>
            <span style="font-weight:800;color:${col}">${n}</span>
          </div>
          <div style="height:6px;background:var(--bg3);border-radius:3px;overflow:hidden">
            <div style="height:100%;width:${w}%;background:${col};border-radius:3px;transition:width .5s"></div>
          </div>
        </div>`;
      }).join('')}
    </div>`;
    body.innerHTML = h;
    ts();
  }).catch(e => { body.innerHTML = err(e.message); });
};

// ── 🔒 SÉCURITÉ ──────────────────────────────────────────────
window.dcLoadSecurity = function() {
  const body = document.getElementById('dc-sec-body');
  if (!body) return;
  body.innerHTML = '<div class="dc-empty"><span class="dc-spin"></span> Chargement…</div>';

  Q({dbg_action:'security'}).then(d => {
    let h = '';

    // Tentatives échouées
    h += `<div class="dc-card-head"><h3 style="margin-bottom:12px">🔑 Comptes avec tentatives échouées</h3></div>`;
    if (d.failed_logins && d.failed_logins.length) {
      h += `<table class="dc-net-table" style="margin-bottom:16px">
        <thead><tr><th>Login</th><th>Échecs</th><th>Dernière tentative</th><th>IP</th></tr></thead>
        <tbody>`;
      d.failed_logins.forEach(r => {
        const col = r.nb >= 5 ? '#ef4444' : r.nb >= 3 ? '#f97316' : '#eab308';
        h += `<tr>
          <td style="font-weight:700">${esc(r.login||'—')}</td>
          <td><span style="color:${col};font-weight:800">${r.nb}</span></td>
          <td style="color:var(--text3)">${r.derniere ? new Date(r.derniere).toLocaleString('fr-FR') : '—'}</td>
          <td style="color:var(--text3);font-size:11px">${esc(r.ip||'—')}</td>
        </tr>`;
      });
      h += '</tbody></table>';
    } else {
      h += '<div class="dc-empty" style="margin-bottom:16px">✅ Aucun compte bloqué</div>';
    }

    // IPs bloquées
    h += `<div style="font-size:11px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px">🚫 IPs bloquées</div>`;
    if (d.blocked_ips && d.blocked_ips.length) {
      h += `<table class="dc-net-table" style="margin-bottom:16px">
        <thead><tr><th>IP</th><th>Raison</th><th>Bloquée jusqu'à</th></tr></thead>
        <tbody>`;
      d.blocked_ips.forEach(r => {
        h += `<tr>
          <td style="font-weight:700;color:#ef4444">${esc(r.ip||'—')}</td>
          <td style="color:var(--text3)">${esc(r.raison||'—')}</td>
          <td style="color:var(--text3)">${r.bloque_jusqua ? new Date(r.bloque_jusqua).toLocaleString('fr-FR') : '—'}</td>
        </tr>`;
      });
      h += '</tbody></table>';
    } else {
      h += '<div class="dc-empty" style="margin-bottom:16px">✅ Aucune IP bloquée</div>';
    }

    // Événements récents
    h += `<div style="font-size:11px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px">📋 Événements récents</div>`;
    if (d.events && d.events.length) {
      h += '<div class="dc-log-box" style="max-height:300px">';
      d.events.forEach(e => {
        const isLogin = (e.action||'').includes('CONNEXION');
        const isFail  = (e.action||'').includes('FAILED') || (e.action||'').includes('LOCKOUT');
        const col = isFail ? 'cl-error' : isLogin ? 'cl-info' : 'cl-warn';
        const ico = isFail ? '🚨' : isLogin ? '✅' : '⚠️';
        const t = e.cree_le ? String(e.cree_le).slice(11,19) : '—';
        h += `<div class="dc-console-line ${col}">
          <span style="color:var(--text3);min-width:60px;font-size:11px">${esc(t)}</span>
          <span>${ico} ${esc(e.action||'—')}</span>
          <span style="color:var(--text3);margin-left:auto;font-size:11px">${esc(e.ip||'—')}</span>
        </div>`;
      });
      h += '</div>';
    } else {
      h += '<div class="dc-empty">Aucun événement récent</div>';
    }

    body.innerHTML = h;
    ts();
  }).catch(e => { body.innerHTML = err(e.message); });
};

// ── 💬 CONSOLE LIVE ──────────────────────────────────────────
let _consoleTimer = null;
let _lastConsoleTs = null;

window.dcLoadConsole = function() {
  const box = document.getElementById('dc-console-box');
  if (!box) return;
  clearInterval(_consoleTimer);
  box.innerHTML = '<div class="dc-empty"><span class="dc-spin"></span> Connexion…</div>';

  function fetchConsole() {
    Q({dbg_action:'get_logs', log:'js_errors', lines:50}).then(d => {
      const lines = (d.lines || []).reverse();
      if (!lines.length) {
        box.innerHTML = `<div class="dc-empty">💬 Console live — en attente d'erreurs…</div>`;
        return;
      }
      // Ne recharger que si nouvelles entrées
      const latest = lines[lines.length-1]?.time;
      if (latest === _lastConsoleTs && box.children.length > 1) return;
      _lastConsoleTs = latest;

      box.innerHTML = lines.map(l => {
        const lv   = jsLevel(l.type);
        const time = l.time ? String(l.time).slice(11,19) : '—';
        const cls  = lv === 'fatal' || lv === 'error' ? 'cl-error' : lv === 'warning' ? 'cl-warn' : 'cl-info';
        const copy = `[${time}] ${l.type||'JS'}: ${l.message||''}
Page: ${l.page_name||''}
${l.stack||''}`;
        return `<div class="dc-console-line ${cls}" style="cursor:pointer" onclick="dcCopyErr(${JSON.stringify(copy)});this.style.background='rgba(59,130,246,.1)'">
          <span style="color:var(--text3);min-width:65px;font-size:10px">${esc(time)}</span>
          <span class="dc-line-badge dc-lbadge-${lv}" style="font-size:9px">${esc(l.type||'JS')}</span>
          <span style="flex:1;font-size:11.5px;word-break:break-all">${esc(l.message||'')}</span>
          ${l.page_name ? `<span style="color:var(--text3);font-size:10px;margin-left:8px;white-space:nowrap">${esc(l.page_name)}</span>` : ''}
        </div>`;
      }).join('');
      ts();
    }).catch(() => {});
  }

  fetchConsole();
  _consoleTimer = setInterval(fetchConsole, 5000);
};

window.dcConsoleClear = function() {
  if (!confirm('Vider le log JS errors ?')) return;
  Q({dbg_action:'clear_log', log:'js_errors'}).then(() => {
    clearInterval(_consoleTimer);
    _lastConsoleTs = null;
    dcLoadConsole();
  });
};

// ── Override dcTab — sans boucle infinie ─────────────────────
const _origTab = window.dcTab;
window.dcTab = function(id, el) {
  _origTab(id, el);
  if (id === 'network')  dcLoadNetwork();
  if (id === 'perf')     dcLoadPerf();
  if (id === 'security') dcLoadSecurity();
  if (id === 'console')  dcLoadConsole();
  // Stopper console live si on change d'onglet
  if (id !== 'console' && _consoleTimer) { clearInterval(_consoleTimer); _consoleTimer = null; }
};

// ── Environnement serveur ────────────────────────────────────
window.dcEnv = function() {
  const box = document.getElementById('dc-env-body');
  if (!box) return;
  const row = (k,v) => `<div style="display:flex;justify-content:space-between;gap:12px;padding:6px 0;border-bottom:1px solid var(--border);font-size:13px"><span style="color:var(--text3)">${k}</span><span style="font-weight:600;text-align:right">${v}</span></div>`;
  const e = (b,n) => (b?'✅':'❌')+' '+n;
  Q({dbg_action:'env'}).then(d => {
    const x = d.ext || {};
    box.innerHTML =
      row('PHP', esc(d.php_version)) +
      row('Environnement', (d.is_prod?'🔴 PRODUCTION':'🟢 Local')+' — '+esc(d.host||'')) +
      row('HTTPS', d.https?'✅ oui':'❌ non') +
      row('memory_limit', esc(d.memory_limit)) +
      row('post_max_size', esc(d.post_max_size)) +
      row('upload_max_filesize', esc(d.upload_max)) +
      row('max_execution_time', esc(d.max_execution_time)+'s') +
      row('OPcache', d.opcache?'✅ actif':'❌ inactif') +
      row('Extensions', [e(x.curl,'curl'),e(x.pdo_mysql,'pdo_mysql'),e(x.gd,'gd'),e(x.mbstring,'mbstring'),e(x.openssl,'openssl')].join(' · '));
  }).catch(err => { box.innerHTML = '<div class="dc-empty" style="color:#ef4444">'+esc(err.message)+'</div>'; });
};

// ── Diagnostic complet pour Claude ───────────────────────────
window.dcDiag = function() {
  fetch(API + new URLSearchParams({dbg_action:'diag'}))
    .then(r => r.text())
    .then(txt => {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(txt)
          .then(() => alert('✅ Diagnostic copié — colle-le dans Claude.'))
          .catch(() => dcDiagFallback(txt));
      } else { dcDiagFallback(txt); }
    })
    .catch(e => alert('Erreur diagnostic : ' + e.message));
};
function dcDiagFallback(txt) {
  const w = window.open('', '_blank');
  if (w) {
    w.document.title = 'Diagnostic Cadurix';
    w.document.write('<pre style="white-space:pre-wrap;font:13px/1.5 monospace;padding:16px">'+txt.replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]))+'</pre>');
  } else { alert(txt.slice(0,800)); }
}

// ── Pause du polling quand l'onglet est en arrière-plan ──────
document.addEventListener('visibilitychange', function() {
  if (document.hidden) {
    clearInterval(timer);
    if (typeof _consoleTimer !== 'undefined' && _consoleTimer) { clearInterval(_consoleTimer); _consoleTimer = null; }
  } else {
    if (document.getElementById('dc-ar-cb')?.checked) startAR();
    if (current === 'console') dcLoadConsole();
  }
});

// ── Init ─────────────────────────────────────────────────────
dcHealth();
dcTimeline();
dcEnv();
dcCheckDots();

})();
</script>

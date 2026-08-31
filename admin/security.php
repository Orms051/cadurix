<?php
/**
 * Cadurix v5.2 — Tableau de bord sécurité
 */
if (!defined('ADMIN_HUB')) require_once '../includes/auth.php';
if (!isAdmin()) { accessDenied(); }

require_once '../includes/security.php';
$sec   = init_security($pdo);
$stats = $sec->getStats();

// ── Actions POST ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $redirect_after = defined('ADMIN_HUB') ? '?tab=security' : 'security.php';

    if ($action === 'lockdown') {
        $sec->triggerLockdown(currentUserId(), $_POST['raison'] ?? 'Lockdown manuel', $_POST['niveau'] ?? 'lockdown');
        flashSuccess('🔒 Lockdown activé.');
        if (!headers_sent()) { header("Location: $redirect_after"); exit; }
    }
    if ($action === 'unlock') {
        $sec->unlock(currentUserId(), 'Déverrouillage manuel admin');
        flashSuccess('✅ Site déverrouillé.');
        if (!headers_sent()) { header("Location: $redirect_after"); exit; }
    }
    if ($action === 'unban_ip') {
        $ip = $_POST['ip'] ?? '';
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            $sec->unbanIp($ip);
            flashSuccess("IP $ip débannie.");
        }
        if (!headers_sent()) { header("Location: $redirect_after"); exit; }
    }
    if ($action === 'ban_ip') {
        $ip = trim($_POST['ip'] ?? '');
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            $pdo->prepare("INSERT INTO security_ips (ip,score,statut,raison,banni_jusqu) VALUES(?,100,'banni','Manuel',DATE_ADD(NOW(),INTERVAL 1440 MINUTE)) ON DUPLICATE KEY UPDATE statut='banni',score=100,raison='Manuel',banni_jusqu=DATE_ADD(NOW(),INTERVAL 1440 MINUTE)")->execute([$ip]);
            flashSuccess("IP $ip bannie pour 24h.");
        }
        if (!headers_sent()) { header("Location: $redirect_after"); exit; }
    }
    if ($action === 'clear_events') {
        $pdo->query("DELETE FROM security_events WHERE cree_le < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        flashSuccess('Événements de plus de 30 jours supprimés.');
        if (!headers_sent()) { header("Location: $redirect_after"); exit; }
    }
    if ($action === 'save_sec_config') {
        $cfg = [
            'sec_login_fail_threshold'  => ['int',    (string)max(1,(int)($_POST['sec_login_fail_threshold']  ?? 10))],
            'sec_global_fail_threshold' => ['int',    (string)max(1,(int)($_POST['sec_global_fail_threshold'] ?? 50))],
            'sec_lockdown_duration'     => ['int',    (string)max(1,(int)($_POST['sec_lockdown_duration']     ?? 30))],
            'sec_ban_duration'          => ['int',    (string)max(1,(int)($_POST['sec_ban_duration']          ?? 60))],
            'sec_auto_lockdown'         => ['bool',   isset($_POST['sec_auto_lockdown']) ? '1' : '0'],
            'sec_notify_email'          => ['bool',   isset($_POST['sec_notify_email'])  ? '1' : '0'],
            'sec_force_2fa'             => ['bool',   isset($_POST['sec_force_2fa'])      ? '1' : '0'],
            'sec_alert_email'           => ['string', trim($_POST['sec_alert_email']     ?? '')],
            'sec_whitelist_ips'         => ['string', trim($_POST['sec_whitelist_ips']   ?? '')],
        ];
        $email = $cfg['sec_alert_email'][1];
        $wl    = $cfg['sec_whitelist_ips'][1];
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flashError("Email d'alerte invalide.");
        } elseif ($wl !== '' && json_decode($wl) === null) {
            flashError('Whitelist IPs : JSON invalide (ex : ["1.2.3.4"]).');
        } else {
            foreach ($cfg as $cle => [$type, $val]) {
                $pdo->prepare("INSERT INTO parametres (cle,valeur,type,groupe) VALUES(?,?,?,'security') ON DUPLICATE KEY UPDATE valeur=?")
                    ->execute([$cle, $val, $type, $val]);
            }
            audit_log($pdo,'MODIF_PARAMETRES_SECURITE','parametres',0);
            flashSuccess('⚙️ Paramètres de sécurité enregistrés.');
        }
        if (!headers_sent()) { header("Location: $redirect_after"); exit; }
    }
    // Fallback JS si headers déjà envoyés (inclus via ADMIN_HUB)
    echo "<script>window.location='" . htmlspecialchars($redirect_after) . "'</script>";
    exit;
}

// ── Données ───────────────────────────────────────────────
$events = $pdo->query("
    SELECT se.*, u.prenom, u.nom
    FROM security_events se
    LEFT JOIN utilisateurs u ON u.id = se.user_id
    ORDER BY se.cree_le DESC LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

$ips_list = $pdo->query("
    SELECT * FROM security_ips
    ORDER BY score DESC, derniere_vue DESC
    LIMIT 30
")->fetchAll(PDO::FETCH_ASSOC);

// ── Identifiants (login) tentés par chaque IP de la liste ──
$loginsByIp = [];
if ($ips_list) {
    $ipset = array_values(array_unique(array_column($ips_list, 'ip')));
    if ($ipset) {
        $ph = implode(',', array_fill(0, count($ipset), '?'));
        try {
            $st = $pdo->prepare("SELECT ip, TRIM(SUBSTRING(details, 7)) AS login, COUNT(*) AS n
                                 FROM security_events
                                 WHERE type='login_fail' AND details LIKE 'Login:%' AND ip IN ($ph)
                                 GROUP BY ip, login ORDER BY n DESC");
            $st->execute($ipset);
            foreach ($st as $r) {
                $lg = trim((string)$r['login']);
                $lg = ($lg === '') ? '∅ vide' : $lg;
                $loginsByIp[$r['ip']][] = ['login'=>$lg, 'n'=>(int)$r['n']];
            }
        } catch (Throwable $e) {}
    }
}

$lockdown_history = $pdo->query("
    SELECT ld.*, u1.login AS declenche_login, u2.login AS debloque_login
    FROM security_lockdown ld
    LEFT JOIN utilisateurs u1 ON u1.id = ld.declenche_par
    LEFT JOIN utilisateurs u2 ON u2.id = ld.debloque_par
    ORDER BY ld.id DESC LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// Paramètres de sécurité (valeurs courantes + défauts)
$sec_defaults = ['sec_login_fail_threshold'=>'10','sec_global_fail_threshold'=>'50','sec_lockdown_duration'=>'30',
                 'sec_ban_duration'=>'60','sec_auto_lockdown'=>'1','sec_notify_email'=>'1','sec_force_2fa'=>'0',
                 'sec_alert_email'=>'','sec_whitelist_ips'=>''];
$sec_cfg = $sec_defaults;
try { foreach ($pdo->query("SELECT cle,valeur FROM parametres WHERE groupe='security'") as $row) { $sec_cfg[$row['cle']] = $row['valeur']; } } catch (Throwable $e) {}

$pageTitle = 'Sécurité';
if (!defined('ADMIN_HUB')) require_once '../includes/header.php';
?>

<style>
/* ════════ Sécurité — refonte v5.4 ════════ */
.sec-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:20px}
.sec-title{font-size:22px;font-weight:800;letter-spacing:-.02em;display:flex;align-items:center;gap:10px}
.sec-title .ic{width:38px;height:38px;border-radius:11px;background:var(--primary);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:19px;box-shadow:0 6px 16px rgba(var(--primary-rgb),.35)}
.sec-sub{color:var(--text2);font-size:13px;margin-top:5px;margin-left:48px}
.sec-actions{display:flex;gap:8px;flex-wrap:wrap}
.sec-btn{border:none;cursor:pointer;font-family:inherit;font-weight:700;border-radius:11px;font-size:13px;display:inline-flex;align-items:center;gap:7px;padding:10px 15px;transition:all .15s}
.sec-btn-ghost{background:var(--bg);color:var(--text2);border:1.5px solid var(--border)}
.sec-btn-ghost:hover{border-color:var(--border2);color:var(--text)}
.sec-btn-ok{background:#16a34a;color:#fff}
.sec-btn-danger{background:var(--bg);color:#dc2626;border:1.5px solid rgba(239,68,68,.4)}
.sec-btn-danger:hover{background:rgba(239,68,68,.08)}

.sec-banner{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);border-radius:14px;padding:14px 18px;margin-bottom:20px;display:flex;align-items:center;gap:14px}
.sec-banner .bi{font-size:26px;animation:secpulse 2s infinite}
@keyframes secpulse{0%,100%{opacity:1}50%{opacity:.45}}

.sec-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px}
.sec-kpi{background:var(--bg);border:1px solid var(--border);border-radius:16px;padding:16px;box-shadow:0 8px 22px rgba(30,40,80,.06)}
.sec-kpi .k-top{display:flex;align-items:center;gap:9px;margin-bottom:10px}
.sec-kpi .k-ic{width:34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px}
.sec-kpi .k-lbl{font-size:12px;font-weight:700;color:var(--text2)}
.sec-kpi .k-val{font-size:28px;font-weight:800;line-height:1}
.sec-kpi .k-foot{font-size:11px;color:var(--text3);margin-top:3px}

.sec-grid2{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:18px}
.sec-card{background:var(--bg);border-radius:18px;box-shadow:0 12px 30px rgba(30,40,80,.10);padding:18px 20px}
.sec-card-h{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:14px}
.sec-card-t{font-weight:800;font-size:14.5px;display:flex;align-items:center;gap:8px}
.sec-ip-form{display:flex;gap:6px}
.sec-ip-form input{font-family:var(--mono,monospace);font-size:12px;padding:7px 10px;border:1.5px solid var(--border);border-radius:9px;background:var(--bg);color:var(--text);width:140px}
.sec-ip-form input:focus{outline:none;border-color:#ef4444}
.sec-mini-danger{border:1.5px solid rgba(239,68,68,.4);background:var(--bg);color:#dc2626;font-weight:700;font-size:12px;border-radius:9px;padding:0 12px;cursor:pointer}
.sec-row{display:flex;align-items:center;gap:10px;padding:9px 12px;background:var(--bg2);border:1px solid var(--border);border-radius:11px;margin-bottom:6px}
.sec-row:last-child{margin-bottom:0}
.sec-ip{font-family:var(--mono,monospace);font-size:13px;font-weight:700}
.sec-row-sub{font-size:11px;color:var(--text3);margin-top:1px}
.sec-tag{font-size:10px;font-weight:700;padding:2px 9px;border-radius:20px}
.sec-iconbtn{width:28px;height:28px;border-radius:8px;border:1px solid var(--border);background:var(--bg);cursor:pointer;font-size:13px;color:var(--text2)}
.sec-iconbtn:hover{background:rgba(22,163,74,.1);color:#16a34a;border-color:rgba(22,163,74,.3)}
.sec-ld{padding:11px 13px;background:var(--bg2);border:1px solid var(--border);border-radius:11px;margin-bottom:7px;border-left:3px solid var(--border)}
.sec-empty{text-align:center;padding:26px;color:var(--text3);font-size:13px}

.sec-evtable{width:100%;border-collapse:collapse}
.sec-evtable th{text-align:left;font-size:11px;font-weight:700;color:var(--text3);padding:8px 12px;border-bottom:1px solid var(--border)}
.sec-evtable td{padding:9px 12px;border-bottom:1px solid var(--border);font-size:12px}
.sec-evtable tr:last-child td{border-bottom:none}
.sec-evtable tr:hover td{background:var(--bg2)}
.sec-modal{display:none;position:fixed;inset:0;background:rgba(8,12,24,.72);z-index:9999;align-items:center;justify-content:center;flex-direction:column;gap:14px;backdrop-filter:blur(3px)}
@media(max-width:820px){.sec-kpis{grid-template-columns:repeat(2,1fr)}.sec-grid2{grid-template-columns:1fr}.sec-sub{margin-left:0}}
</style>

<div class="sec-head">
  <div>
    <div class="sec-title"><span class="ic">🛡️</span> Tableau de bord sécurité</div>
    <div class="sec-sub">Surveillance temps réel — <?= date('d/m/Y H:i') ?></div>
  </div>
  <div class="sec-actions">
    <button class="sec-btn sec-btn-ghost" onclick="document.getElementById('preview-modal').style.display='flex'" title="Voir la page affichée lors d'un lockdown">👁️ Aperçu lockdown</button>
    <?php if ($stats['lockdown_actif']): ?>
    <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="unlock">
      <button class="sec-btn sec-btn-ok">✅ Déverrouiller le site</button>
    </form>
    <?php else: ?>
    <button class="sec-btn sec-btn-danger" onclick="document.getElementById('lockdown-modal').style.display='flex'">🔒 Activer lockdown</button>
    <?php endif; ?>
  </div>
</div>

<?php if ($stats['lockdown_actif']): ?>
<div class="sec-banner">
  <div class="bi">🔒</div>
  <div>
    <div style="font-weight:800;color:#dc2626">LOCKDOWN ACTIF</div>
    <div style="font-size:13px;color:var(--text2)">Le site est en mode maintenance sécurisée. Seul vous pouvez y accéder.</div>
  </div>
</div>
<?php endif; ?>

<!-- KPIs -->
<?php
$k_ban = $stats['ips_bannies']>0;
$k_susp = $stats['ips_suspectes']>0;
$lf = $stats['login_fails_1h']; $k_lf_c = $lf>10?'#dc2626':($lf>5?'#d97706':'var(--primary)');
?>
<div class="sec-kpis">
  <div class="sec-kpi">
    <div class="k-top"><span class="k-ic" style="background:<?= $k_ban?'rgba(239,68,68,.12)':'rgba(var(--primary-rgb),.12)' ?>;color:<?= $k_ban?'#dc2626':'var(--primary)' ?>">🚫</span><span class="k-lbl">IPs bannies</span></div>
    <div class="k-val" style="color:<?= $k_ban?'#dc2626':'var(--text)' ?>"><?= $stats['ips_bannies'] ?></div>
    <div class="k-foot">actives</div>
  </div>
  <div class="sec-kpi">
    <div class="k-top"><span class="k-ic" style="background:<?= $k_susp?'rgba(245,158,11,.14)':'rgba(var(--primary-rgb),.12)' ?>;color:<?= $k_susp?'#d97706':'var(--primary)' ?>">⚠️</span><span class="k-lbl">IPs suspectes</span></div>
    <div class="k-val" style="color:<?= $k_susp?'#d97706':'var(--text)' ?>"><?= $stats['ips_suspectes'] ?></div>
    <div class="k-foot">score ≥ 50</div>
  </div>
  <div class="sec-kpi">
    <div class="k-top"><span class="k-ic" style="background:rgba(239,68,68,.12);color:<?= $k_lf_c ?>">🔑</span><span class="k-lbl">Échecs login</span></div>
    <div class="k-val" style="color:<?= $k_lf_c ?>"><?= $lf ?></div>
    <div class="k-foot">dernière heure</div>
  </div>
  <div class="sec-kpi">
    <div class="k-top"><span class="k-ic" style="background:rgba(var(--primary-rgb),.12);color:var(--primary)">📋</span><span class="k-lbl">Événements</span></div>
    <div class="k-val"><?= $stats['events_24h'] ?></div>
    <div class="k-foot">dernières 24h</div>
  </div>
</div>

<?php
if (!function_exists('sec_temps_relatif')) {
  function sec_temps_relatif($dt){
    $d = time() - strtotime($dt);
    if ($d < 60)    return "à l'instant";
    if ($d < 3600)  return "il y a ".floor($d/60)." min";
    if ($d < 86400) return "il y a ".floor($d/3600)." h";
    return "il y a ".floor($d/86400)." j";
  }
}
$ev_icons = ['login_fail'=>'🔑','sql_inject'=>'💉','scan'=>'🔎','lockdown'=>'🔒','ban_ip'=>'⛔','unlock'=>'🔓','suspicious'=>'⚠️'];
$ev_colors= ['login_fail'=>'#d97706','sql_inject'=>'#dc2626','scan'=>'#dc2626','lockdown'=>'#dc2626','ban_ip'=>'#dc2626','unlock'=>'#16a34a','suspicious'=>'#d97706'];
$ev_24h = 0; foreach ($events as $e) if (strtotime($e['cree_le']) > time()-86400) $ev_24h++;
$nb_ban = 0; foreach ($ips_list as $ir) if (($ir['statut']??'')==='banni') $nb_ban++;
$nb_suspect = 0; foreach ($ips_list as $ir) if (($ir['statut']??'')!=='banni') $nb_suspect++;
$nb_fail = 0; foreach ($events as $e) if (($e['type']??'')==='login_fail') $nb_fail++;
$fail_by_hour = array_fill(0, 24, 0);
try {
  foreach ($pdo->query("SELECT cree_le FROM security_events WHERE type='login_fail' AND cree_le > (NOW() - INTERVAL 24 HOUR)")->fetchAll(PDO::FETCH_COLUMN) as $dt) {
    $ha = floor((time() - strtotime($dt)) / 3600);
    if ($ha >= 0 && $ha < 24) $fail_by_hour[23 - $ha]++;
  }
} catch (Throwable $e) {}

$my_ip = $_SERVER['REMOTE_ADDR'] ?? '';
$ip_user = [];
$ip_addrs = array_values(array_unique(array_filter(array_column($ips_list,'ip'))));
if ($ip_addrs) {
  try {
    $ph = implode(',', array_fill(0, count($ip_addrs), '?'));
    $q = $pdo->prepare("SELECT ch.ip, u.prenom, u.nom, MAX(ch.cree_le) mx FROM connexions_historique ch JOIN utilisateurs u ON u.id=ch.user_id WHERE ch.ip IN ($ph) AND ch.succes=1 GROUP BY ch.ip, ch.user_id ORDER BY mx DESC");
    $q->execute($ip_addrs);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { if (!isset($ip_user[$r['ip']])) $ip_user[$r['ip']] = trim(($r['prenom']??'').' '.($r['nom']??'')); }
  } catch (Throwable $e) {}
}
$ipRow = function($ip_row) use ($ip_user, $my_ip, $loginsByIp){
  $color = $ip_row['statut']==='banni' ? '#dc2626' : ($ip_row['score']>=70 ? '#d97706' : 'var(--text2)');
  $stat_color = $ip_row['statut']==='banni' ? '#dc2626' : '#d97706';
  $stat_lbl   = $ip_row['statut']==='banni' ? '🔴 Bannie' : '🟡 Surveillée';
  ob_start(); ?>
  <div class="sec-row">
    <div style="flex:1;min-width:0">
      <div class="sec-ip" style="color:<?= $color ?>"><span class="sec-iplink" onclick="secOpenIP('<?= h($ip_row['ip']) ?>')" title="Voir les événements de cette IP"><?= h($ip_row['ip']) ?></span><?php $w = $ip_user[$ip_row['ip']] ?? ''; if ($ip_row['ip']===$my_ip): ?> <span class="sec-who sec-me">👤 Vous</span><?php elseif ($w!==''): ?> <span class="sec-who">👤 <?= h($w) ?></span><?php else: ?> <span class="sec-who sec-unknown">Inconnu</span><?php endif; ?></div>
      <div class="sec-row-sub">Score <?= $ip_row['score'] ?>/100 · <?= $ip_row['nb_tentatives'] ?> tentatives<?php if($ip_row['banni_jusqu']&&strtotime($ip_row['banni_jusqu'])>time()):?> · ban → <?= date('H:i',strtotime($ip_row['banni_jusqu'])) ?><?php endif;?></div>
      <div class="sec-scorebar"><span style="width:<?= min(100,(int)$ip_row['score']) ?>%;background:<?= $color ?>"></span></div>
      <?php $lg = $loginsByIp[$ip_row['ip']] ?? []; if ($lg): ?>
      <div class="sec-row-sub" style="margin-top:3px">🔑 Identifiants tentés :
        <?php $parts=[]; foreach (array_slice($lg,0,6) as $x){ $parts[] = '<b style="color:var(--text2)">'.h($x['login']).'</b>'.($x['n']>1?'<span style="color:var(--text3)"> ×'.$x['n'].'</span>':''); } echo implode(', ', $parts); if (count($lg)>6) echo ' …'; ?>
      </div>
      <?php endif; ?>
    </div>
    <span class="sec-tag sec-statbadge" style="background:<?= $stat_color ?>1e;color:<?= $stat_color ?>;border:1px solid <?= $stat_color ?>44"><?= $stat_lbl ?></span>
    <?php if($ip_row['statut']==='banni'):?>
    <form method="POST" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="unban_ip"><input type="hidden" name="ip" value="<?= h($ip_row['ip']) ?>">
      <button class="sec-iconbtn" title="Débannir">✓</button>
    </form>
    <?php endif;?>
  </div>
  <?php return ob_get_clean();
};
$evRow = function($e) use ($ev_icons,$ev_colors){
  $color = $ev_colors[$e['type']] ?? 'var(--text2)';
  $ic    = $ev_icons[$e['type']] ?? '•';
  $who   = trim(($e['prenom']??'').' '.($e['nom']??''));
  ob_start(); ?>
  <div class="sec-evrow" data-type="<?= h($e['type']) ?>" data-ip="<?= h($e['ip'] ?? '') ?>">
    <div class="ic" style="background:<?= $color ?>1e;color:<?= $color ?>"><?= $ic ?></div>
    <div class="meta">
      <div><span class="sec-tag" style="background:<?= $color ?>22;color:<?= $color ?>"><?= $e['type'] ?></span> <span style="font-family:var(--mono,monospace);font-size:11px"><?= h($e['ip'] ?? '—') ?></span><?php if($who):?> · <?= h($who) ?><?php endif;?></div>
      <div class="det"><?= h(substr($e['details']??'',0,120)) ?: '—' ?></div>
    </div>
    <div class="tm"><?= sec_temps_relatif($e['cree_le']) ?></div>
  </div>
  <?php return ob_get_clean();
};
?>
<style>
.sec-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px}
@media(max-width:640px){.sec-kpis{grid-template-columns:repeat(2,1fr)}}
.sec-kpi{background:var(--bg);border:1px solid var(--border);border-radius:14px;padding:14px 16px;cursor:pointer;text-align:left;font:inherit;color:inherit;width:100%;transition:border-color .15s,transform .15s}
.sec-kpi:hover{border-color:var(--primary);transform:translateY(-2px)}
.sec-kpi:active{transform:translateY(0)}
.sec-kpi .v{font-size:26px;font-weight:800;line-height:1}
.sec-kpi.alert .v{color:#dc2626}.sec-kpi.warn .v{color:#d97706}
.sec-kpi .l{font-size:11.5px;color:var(--text2);margin-top:5px;font-weight:600}
.sec-scorebar{height:4px;border-radius:3px;background:var(--border);margin-top:6px;overflow:hidden}
.sec-scorebar span{display:block;height:100%;border-radius:3px}
.sec-who{font-size:11px;font-weight:600;padding:1px 7px;border-radius:20px;background:var(--glass);color:var(--text2);margin-left:4px}
.sec-who.sec-me{background:var(--primary);color:#fff}
.sec-who.sec-unknown{color:var(--text3);font-style:italic;font-weight:500}
.sec-spark{display:flex;align-items:flex-end;gap:2px;height:66px;margin-top:10px}
.sec-spark-bar{flex:1;border-radius:2px 2px 0 0;min-height:2px}
.sec-iplink{cursor:pointer;text-decoration:underline;text-decoration-style:dotted;text-underline-offset:2px}
.sec-iplink:hover{text-decoration-style:solid}
.sec-statbadge{font-weight:700;white-space:nowrap;padding:3px 10px;border-radius:20px;font-size:11.5px}
#sec-ipnote{display:none;background:var(--glass);border:1px solid var(--border);border-radius:9px;padding:8px 12px;font-size:12.5px;color:var(--text2);margin-bottom:10px}
.sec-seeall{width:100%;margin-top:10px;padding:9px;border:1px dashed var(--border);border-radius:10px;background:transparent;color:var(--text2);font-weight:600;font-size:13px;cursor:pointer}
.sec-seeall:hover{border-color:var(--primary);color:var(--primary)}
.sec-chips{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px}
.sec-modal-box .sec-chips{position:sticky;top:0;background:var(--bg2,var(--bg));padding:6px 0;z-index:1}
.sec-chip{padding:5px 11px;border-radius:20px;font-size:12px;font-weight:600;border:1px solid var(--border);background:transparent;color:var(--text2);cursor:pointer}
.sec-chip.on{background:var(--primary);color:#fff;border-color:var(--primary)}
.sec-evrow{display:flex;align-items:center;gap:10px;padding:9px 0;border-top:1px solid var(--border)}
.sec-evrow:first-child{border-top:none}
.sec-evrow .ic{width:30px;height:30px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0}
.sec-evrow .meta{flex:1;min-width:0;font-size:13px}
.sec-evrow .det{color:var(--text2);font-size:11.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:1px}
.sec-evrow .tm{color:var(--text3);font-size:11px;white-space:nowrap;flex-shrink:0}
.sec-modal{position:fixed;inset:0;background:rgba(8,12,24,.6);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);display:none;align-items:flex-start;justify-content:center;z-index:9999;padding:40px 16px;overflow-y:auto}
.sec-modal.open{display:flex}
#lockdown-modal{align-items:center;padding:16px 16px}
.sec-modal-box{background:var(--bg2,var(--bg));border:1px solid var(--border);border-radius:18px;width:min(760px,100%);max-height:calc(100vh - 80px);overflow-y:auto;padding:22px;margin:0 auto}
.sec-modal-x{float:right;background:none;border:none;color:var(--text2);font-size:22px;cursor:pointer;line-height:1}
</style>
<div class="sec-kpis">
  <button type="button" class="sec-kpi alert" onclick="secOpen('ips')"><div class="v"><?= $nb_ban ?></div><div class="l">IPs bannies</div></button>
  <button type="button" class="sec-kpi warn" onclick="secOpen('ips')"><div class="v"><?= $nb_suspect ?></div><div class="l">IPs suspectes</div></button>
  <button type="button" class="sec-kpi" onclick="secOpenFilter('events','login_fail')"><div class="v"><?= $nb_fail ?></div><div class="l">Échecs login</div></button>
  <button type="button" class="sec-kpi" onclick="secOpen('events')"><div class="v"><?= count($events) ?></div><div class="l">Événements</div></button>
</div>
<div class="sec-card" style="margin-bottom:18px">
  <div class="sec-card-h">
    <div class="sec-card-t">📉 Échecs de connexion — 24 dernières heures</div>
    <span style="font-size:12px;color:var(--text3)"><?= array_sum($fail_by_hour) ?> au total</span>
  </div>
  <?php if (array_sum($fail_by_hour) === 0): ?>
    <div class="sec-empty" style="padding:14px 0">Aucun échec sur les 24 dernières heures ✅</div>
  <?php else: $mx = max(1, max($fail_by_hour)); ?>
  <div class="sec-spark">
    <?php foreach ($fail_by_hour as $i=>$v): ?>
      <div class="sec-spark-bar" style="height:<?= $v ? max(8, round($v/$mx*100)) : 2 ?>%;background:<?= $v>0?'#d97706':'var(--border)' ?>" title="il y a <?= 23-$i ?> h — <?= $v ?> échec(s)"></div>
    <?php endforeach; ?>
  </div>
  <div style="display:flex;justify-content:space-between;font-size:10px;color:var(--text3);margin-top:4px"><span>-24 h</span><span>maintenant</span></div>
  <?php endif; ?>
</div>
<div class="sec-grid2">
  <!-- IPs surveillées -->
  <div class="sec-card">
    <div class="sec-card-h">
      <div class="sec-card-t">🌐 IPs surveillées</div>
      <form method="POST" class="sec-ip-form"><?= csrf_field() ?><input type="hidden" name="action" value="ban_ip">
        <input type="text" name="ip" placeholder="IP à bannir">
        <button class="sec-mini-danger">🚫 Bannir</button>
      </form>
    </div>
    <?php if (empty($ips_list)): ?>
    <div class="sec-empty">Aucune IP suspecte détectée ✅</div>
    <?php else:
      foreach (array_slice($ips_list,0,5) as $ip_row) echo $ipRow($ip_row);
      if (count($ips_list) > 5): ?>
      <button class="sec-seeall" onclick="secOpen('ips')">Voir les <?= count($ips_list) ?> IPs surveillées →</button>
      <?php endif;
    endif;?>
  </div>

  <!-- Historique lockdowns -->
  <div class="sec-card">
    <div class="sec-card-h"><div class="sec-card-t">🔒 Historique lockdowns</div></div>
    <?php if (empty($lockdown_history)): ?>
    <div class="sec-empty">Aucun lockdown dans l'historique ✅</div>
    <?php else: foreach ($lockdown_history as $ld): ?>
    <div class="sec-ld" style="border-left-color:<?= $ld['actif']?'#dc2626':'var(--border)' ?>">
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:3px">
        <span style="font-size:11px;font-weight:800;color:<?= $ld['actif']?'#dc2626':'var(--text2)' ?>"><?= $ld['actif']?'🔒 ACTIF':'✅ TERMINÉ' ?></span>
        <span style="font-size:10px;color:var(--text3)"><?= date('d/m H:i', strtotime($ld['declenche_le'] ?? 'now')) ?></span>
      </div>
      <div style="font-size:12.5px;color:var(--text2)"><?= h($ld['raison'] ?? '—') ?></div>
      <div style="font-size:11px;color:var(--text3);margin-top:2px">Par <?= h($ld['declenche_login'] ?? 'Auto') ?><?php if($ld['debloque_login']):?> · débloqué par <?= h($ld['debloque_login']) ?><?php endif;?></div>
    </div>
    <?php endforeach; endif;?>
  </div>
</div>

<!-- Événements -->
<div class="sec-card">
  <div class="sec-card-h">
    <div class="sec-card-t">📋 Événements sécurité récents</div>
    <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="clear_events">
      <button class="sec-btn sec-btn-ghost" style="padding:7px 12px;font-size:12px" onclick="return confirm('Supprimer les événements de plus de 30 jours ?')">🗑️ Nettoyer</button>
    </form>
  </div>
  <?php if (empty($events)): ?>
  <div class="sec-empty">Aucun événement ✅</div>
  <?php else:
    foreach (array_slice($events,0,5) as $e) echo $evRow($e);
    if (count($events) > 5): ?>
    <button class="sec-seeall" onclick="secOpen('events')">Voir les <?= count($events) ?> événements →</button>
    <?php endif;
  endif;?>
</div>

<!-- Modale : toutes les IPs -->
<div class="sec-modal" id="sec-modal-ips" onclick="if(event.target===this)secClose('ips')">
  <div class="sec-modal-box">
    <button class="sec-modal-x" onclick="secClose('ips')">✕</button>
    <div class="sec-card-t" style="margin-bottom:14px">🌐 Toutes les IPs surveillées (<?= count($ips_list) ?>)</div>
    <?php foreach ($ips_list as $ip_row) echo $ipRow($ip_row); ?>
  </div>
</div>

<!-- Modale : tous les événements -->
<div class="sec-modal" id="sec-modal-events" onclick="if(event.target===this)secClose('events')">
  <div class="sec-modal-box">
    <button class="sec-modal-x" onclick="secClose('events')">✕</button>
    <div class="sec-card-t" style="margin-bottom:12px">📋 Tous les événements (<?= count($events) ?>)</div>
    <div class="sec-chips" id="sec-evchips">
      <button class="sec-chip on" data-f="all" onclick="secFilter('all',this)">Tout</button>
      <button class="sec-chip" data-f="login_fail" onclick="secFilter('login_fail',this)">🔑 Échecs</button>
      <button class="sec-chip" data-f="sql_inject" onclick="secFilter('sql_inject',this)">💉 Injections</button>
      <button class="sec-chip" data-f="scan" onclick="secFilter('scan',this)">🔎 Scans</button>
      <button class="sec-chip" data-f="ban_ip" onclick="secFilter('ban_ip',this)">⛔ Bans</button>
      <button class="sec-chip" data-f="lockdown" onclick="secFilter('lockdown',this)">🔒 Lockdown</button>
    </div>
    <div id="sec-ipnote"></div>
    <div id="sec-evlist">
      <?php foreach ($events as $e) echo $evRow($e); ?>
    </div>
  </div>
</div>

<script>
function secOpen(n){ document.getElementById('sec-modal-'+n).classList.add('open'); }
function secOpenFilter(n,type){ secOpen(n); var chip=document.querySelector('#sec-evchips .sec-chip[data-f="'+type+'"]'); if(chip) secFilter(type,chip); }
function secOpenIP(ip){ secOpen('events'); document.querySelectorAll('#sec-evchips .sec-chip').forEach(c=>c.classList.remove('on')); var n=0; document.querySelectorAll('#sec-evlist .sec-evrow').forEach(function(r){ var m=r.dataset.ip===ip; r.style.display=m?'':'none'; if(m)n++; }); var note=document.getElementById('sec-ipnote'); if(note){ note.style.display='block'; note.textContent='🔎 IP '+ip+' — '+n+' événement(s) récent(s). Cliquez un filtre pour élargir.'; } }
function secClose(n){ document.getElementById('sec-modal-'+n).classList.remove('open'); }
function secFilter(f,btn){
  var note=document.getElementById('sec-ipnote'); if(note) note.style.display='none';
  document.querySelectorAll('#sec-evchips .sec-chip').forEach(c=>c.classList.remove('on'));
  btn.classList.add('on');
  document.querySelectorAll('#sec-evlist .sec-evrow').forEach(function(r){
    r.style.display = (f==='all' || r.dataset.type===f) ? '' : 'none';
  });
}
document.addEventListener('keydown',function(e){ if(e.key==='Escape'){secClose('ips');secClose('events');} });
</script>

<!-- Paramètres de sécurité -->
<style>
.secp-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px}
.secp-f{display:flex;flex-direction:column;gap:5px;font-size:12.5px;font-weight:600;color:var(--text2)}
.secp-f input,.secp-f textarea{font-family:inherit;font-size:13.5px;font-weight:500;color:var(--text);padding:9px 12px;border:1.5px solid var(--border);border-radius:10px;background:var(--bg)}
.secp-f input:focus,.secp-f textarea:focus{outline:none;border-color:var(--primary)}
.secp-tog{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;color:var(--text);background:var(--bg2);border:1px solid var(--border);border-radius:10px;padding:9px 13px;cursor:pointer}
@media(max-width:680px){.secp-grid{grid-template-columns:1fr}}
</style>
<div class="sec-card" style="margin-top:18px">
  <div class="sec-card-h"><div class="sec-card-t">⚙️ Paramètres de sécurité</div></div>
  <form method="POST">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_sec_config">
    <div class="secp-grid">
      <label class="secp-f"><span>Échecs login avant ban IP</span>
        <input type="number" min="1" name="sec_login_fail_threshold" value="<?= h($sec_cfg['sec_login_fail_threshold']) ?>"></label>
      <label class="secp-f"><span>Échecs globaux avant lockdown</span>
        <input type="number" min="1" name="sec_global_fail_threshold" value="<?= h($sec_cfg['sec_global_fail_threshold']) ?>"></label>
      <label class="secp-f"><span>Durée lockdown auto (min)</span>
        <input type="number" min="1" name="sec_lockdown_duration" value="<?= h($sec_cfg['sec_lockdown_duration']) ?>"></label>
      <label class="secp-f"><span>Durée ban IP (min)</span>
        <input type="number" min="1" name="sec_ban_duration" value="<?= h($sec_cfg['sec_ban_duration']) ?>"></label>
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:14px">
      <label class="secp-tog"><input type="checkbox" name="sec_auto_lockdown" <?= $sec_cfg['sec_auto_lockdown']?'checked':'' ?>> Lockdown automatique</label>
      <label class="secp-tog"><input type="checkbox" name="sec_notify_email" <?= $sec_cfg['sec_notify_email']?'checked':'' ?>> Notifications email</label>
      <label class="secp-tog"><input type="checkbox" name="sec_force_2fa" <?= $sec_cfg['sec_force_2fa']?'checked':'' ?>> 🔐 Forcer la 2FA (admins) <span style="opacity:.55;font-weight:500">— enforcement à venir</span></label>
    </div>
    <div class="secp-f" style="margin-top:14px"><span>Email d'alerte sécurité</span>
      <input type="email" name="sec_alert_email" value="<?= h($sec_cfg['sec_alert_email']) ?>" placeholder="securite@exemple.fr"></div>
    <div class="secp-f" style="margin-top:14px"><span>IPs en liste blanche (JSON) — jamais bloquées</span>
      <textarea name="sec_whitelist_ips" rows="2" placeholder='["192.168.1.10","10.0.0.5"]'><?= h($sec_cfg['sec_whitelist_ips']) ?></textarea></div>
    <div style="margin-top:16px"><button class="sec-btn" style="background:var(--primary);color:#fff">💾 Enregistrer</button></div>
  </form>
</div>

<!-- Modal aperçu lockdown -->
<div id="preview-modal" class="sec-modal">
  <style>@keyframes lkspin{to{transform:rotate(360deg)}}@keyframes lkblink{0%,100%{opacity:1}50%{opacity:.2}}</style>
  <div style="width:90%;max-width:430px;background:#0a0410;border:1px solid rgba(239,68,68,.28);border-radius:20px;padding:40px 32px;text-align:center;box-shadow:0 24px 70px rgba(0,0,0,.6)">
    <div style="width:78px;height:78px;margin:0 auto 20px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:2rem;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);position:relative">
      🔒
      <span style="position:absolute;inset:-6px;border-radius:50%;border:2px solid rgba(239,68,68,.35);border-top-color:transparent;animation:lkspin 2.4s linear infinite"></span>
    </div>
    <div style="display:inline-flex;align-items:center;gap:7px;background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);color:#fca5a5;padding:5px 14px;border-radius:99px;font-size:.76rem;font-weight:700;margin-bottom:16px">
      <span style="width:7px;height:7px;border-radius:50%;background:#ef4444;animation:lkblink 1.4s infinite"></span>Mode protection activé
    </div>
    <h1 style="color:#fff;font-size:1.35rem;font-weight:800;margin-bottom:10px">Accès temporairement restreint</h1>
    <p style="color:rgba(253,232,232,.55);font-size:.9rem;line-height:1.6">Une activité inhabituelle a été détectée. Par mesure de sécurité, l'accès au site est momentanément suspendu.</p>
    <div style="margin-top:18px;padding-top:14px;border-top:1px solid rgba(239,68,68,.15);font-size:.82rem;color:#fca5a5;font-weight:600">🕐 Déverrouillage automatique dans 30 min</div>
  </div>
  <div style="display:flex;gap:10px;align-items:center">
    <div style="font-size:.8rem;color:#cbd5e1;background:rgba(255,255,255,.08);padding:8px 14px;border-radius:9px">👆 Ce que voient les utilisateurs lors d'un lockdown</div>
    <button onclick="document.getElementById('preview-modal').style.display='none'" style="background:#1A2240;border:1px solid #2A3450;color:#E8EEFF;padding:8px 16px;border-radius:9px;cursor:pointer;font-size:.85rem">✕ Fermer</button>
  </div>
</div>

<!-- Modal lockdown -->
<div id="lockdown-modal" class="sec-modal">
  <div style="background:var(--bg);border:1px solid rgba(239,68,68,.5);border-radius:16px;padding:26px;max-width:400px;width:90%;box-shadow:0 24px 70px rgba(0,0,0,.4)">
    <div style="font-size:1.1rem;font-weight:800;color:#dc2626;margin-bottom:16px">🔒 Activer le Lockdown</div>
    <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="lockdown">
      <div style="margin-bottom:14px">
        <label style="font-size:12px;font-weight:700;color:var(--text2);display:block;margin-bottom:5px">Niveau</label>
        <select name="niveau" style="width:100%;font-family:inherit;font-size:13.5px;padding:9px 12px;border:1.5px solid var(--border);border-radius:10px;background:var(--bg);color:var(--text)">
          <option value="alerte">⚠️ Alerte (accès limité)</option>
          <option value="lockdown" selected>🔒 Lockdown (accès bloqué)</option>
          <option value="urgence">🚨 Urgence (blocage total)</option>
        </select>
      </div>
      <div style="margin-bottom:16px">
        <label style="font-size:12px;font-weight:700;color:var(--text2);display:block;margin-bottom:5px">Raison</label>
        <input type="text" name="raison" placeholder="Ex: Tentative d'intrusion détectée" required style="width:100%;font-family:inherit;font-size:13.5px;padding:9px 12px;border:1.5px solid var(--border);border-radius:10px;background:var(--bg);color:var(--text)">
      </div>
      <div style="display:flex;gap:8px">
        <button type="submit" class="sec-btn" style="flex:1;background:#EF4444;color:#fff;justify-content:center">🔒 Confirmer</button>
        <button type="button" class="sec-btn sec-btn-ghost" style="flex:1;justify-content:center" onclick="document.getElementById('lockdown-modal').style.display='none'">Annuler</button>
      </div>
    </form>
  </div>
</div>

<?php
// ── Marque la notif sécurité comme « vue » (cookie sec_ack = signature courante) ──
// → le badge (onglet Sécurité + sidebar Administration) disparaît, et ne réapparaît
//   que s'il y a du nouveau (nouveau ban / lockdown / nouvel événement).
try {
    $__nbb = (int)$pdo->query("SELECT COUNT(*) FROM security_ips WHERE statut='banni'")->fetchColumn();
    $__lck = (int)$pdo->query("SELECT COUNT(*) FROM security_lockdown WHERE actif=1")->fetchColumn();
    $__lev = (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM security_events")->fetchColumn();
    $__sig = $__nbb.'|'.$__lck.'|'.$__lev;
    echo '<script>document.cookie="sec_ack='.h($__sig).';path=/;max-age=2592000";</script>';
} catch (Throwable $e) {}
?>
<?php if (!defined('ADMIN_HUB')) require_once '../includes/footer.php'; ?>

<?php
/**
 * Cadurix v5.2 — Système de sécurité avancé
 * Surveillance, détection, lockdown automatique
 */

class MedSecurity {

    private PDO $pdo;
    private string $ip;
    private array $config = [];

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->ip  = $this->getRealIP();
        $this->loadConfig();
    }

    // ── IP réelle (derrière proxy) ────────────────────────
    private function getRealIP(): string {
        $headers = ['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR'];
        foreach ($headers as $h) {
            if (!empty($_SERVER[$h])) {
                $ip = trim(explode(',', $_SERVER[$h])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    // ── Charger config ────────────────────────────────────
    private function loadConfig(): void {
        try {
            $rows = $this->pdo->query("SELECT cle, valeur FROM parametres WHERE groupe='security'")->fetchAll(PDO::FETCH_KEY_PAIR);
            $this->config = $rows ?: [];
        } catch (Exception $e) {}

        // Défauts
        $this->config += [
            'sec_login_fail_threshold'  => 10,
            'sec_global_fail_threshold' => 50,
            'sec_lockdown_duration'     => 30,
            'sec_ban_duration'          => 60,
            'sec_auto_lockdown'         => 1,
            'sec_notify_email'          => 1,
            'sec_alert_email'           => '',
            'sec_whitelist_ips'         => '',
        ];
    }

    // ── Vérifier si lockdown actif ────────────────────────
    public function isLockdown(): bool {
        try {
            $row = $this->pdo->query("
                SELECT actif, expire_le, niveau, ip_whitelist
                FROM security_lockdown
                WHERE actif = 1
                ORDER BY id DESC LIMIT 1
            ")->fetch(PDO::FETCH_ASSOC);

            if (!$row) return false;

            // Vérifier expiration auto
            if ($row['expire_le'] && strtotime($row['expire_le']) < time()) {
                $this->unlock(null, 'Expiration automatique');
                return false;
            }

            // Vérifier whitelist IP
            if ($row['ip_whitelist']) {
                $whitelist = json_decode($row['ip_whitelist'], true) ?: [];
                if (in_array($this->ip, $whitelist)) return false;
            }

            // Super admin toujours autorisé
            if ($_SESSION['super_admin'] ?? false) return false;

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    // ── Vérifier si IP bannie ─────────────────────────────
    public function isIpBanned(): bool {
        // Super admin connecté : jamais bloqué (voie de secours anti auto-verrouillage)
        if ($_SESSION['super_admin'] ?? false) return false;
        // Vérifier whitelist
        $whitelist_raw = $this->config['sec_whitelist_ips'] ?? '';
        if ($whitelist_raw) {
            $whitelist = json_decode($whitelist_raw, true) ?: [];
            if (in_array($this->ip, $whitelist)) return false;
        }

        try {
            $row = $this->pdo->prepare("
                SELECT statut, banni_jusqu FROM security_ips
                WHERE ip = ? AND statut = 'banni'
            ");
            $row->execute([$this->ip]);
            $data = $row->fetch(PDO::FETCH_ASSOC);
            if (!$data) return false;
            if ($data['banni_jusqu'] && strtotime($data['banni_jusqu']) < time()) {
                // Ban expiré
                $this->unbanIp($this->ip);
                return false;
            }
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    // ── Enregistrer tentative login échouée ───────────────
    public function recordLoginFail(string $login = ''): void {
        $this->updateIpScore(15, 'login_fail');
        $this->logEvent('login_fail', "Login: $login");
        $this->checkAutoBan();       // bannir l'IP au seuil sec_login_fail_threshold (échecs login)
        $this->checkAutoLockdown();
    }

    // ── Enregistrer tentative injection ───────────────────
    public function recordSqlInjection(string $url = ''): void {
        $this->updateIpScore(40, 'sql_inject');
        $this->logEvent('sql_inject', "URL: $url");
        $this->checkAutoBan(true); // Ban immédiat pour injection
        $this->checkAutoLockdown();
    }

    // ── Enregistrer requête suspecte ──────────────────────
    public function recordSuspicious(string $reason = ''): void {
        $this->updateIpScore(10, 'suspicious');
        $this->logEvent('suspicious', $reason);
        $this->checkAutoBan();
    }

    // ── Vérifier URL pour injection SQL ──────────────────
    public function checkSqlInjection(): void {
        $url   = $_SERVER['REQUEST_URI'] ?? '';
        $query = $_SERVER['QUERY_STRING'] ?? '';
        // Signatures d'injection réelles — resserrées pour NE PAS matcher les URLs légitimes
        // (ex. ?action=delete&from=5 ou une recherche « select ... from » ne déclenchent plus).
        $patterns = [
            'union\s+(all\s+)?select',                 // UNION [ALL] SELECT
            'information_schema',                        // énumération de schéma
            'drop\s+table', 'truncate\s+table',
            ';\s*(drop|delete|update|insert|alter)\s',  // requêtes empilées
            '(%27|\')\s*(or|and)\s+[\'"0-9]',          // \' OR 1 , \' AND \'
            '(sleep|benchmark|pg_sleep)\s*\(',          // injections temporelles
            'into\s+outfile',                           // exfiltration fichier
            '<script', 'javascript:', 'base64_decode', 'eval\s*\(', // XSS / RCE
        ];
        foreach ($patterns as $p) {
            if (preg_match('/' . $p . '/i', $url . $query)) {
                $this->recordSqlInjection($url);
                http_response_code(403);
                die($this->renderBlockedPage('Requête bloquée', 'Contenu suspect détecté.'));
            }
        }
    }

    // ── Mettre à jour score IP ────────────────────────────
    private function updateIpScore(int $points, string $raison): void {
        try {
            $this->pdo->prepare("
                INSERT INTO security_ips (ip, score, nb_tentatives, raison, derniere_vue)
                VALUES (?, ?, 1, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    score = LEAST(score + ?, 100),
                    nb_tentatives = nb_tentatives + 1,
                    raison = ?,
                    derniere_vue = NOW()
            ")->execute([$this->ip, $points, $raison, $points, $raison]);
        } catch (Exception $e) {}
    }

    // ── Vérifier auto-ban IP ──────────────────────────────
    private function checkAutoBan(bool $immediate = false): void {
        try {
            $row = $this->pdo->prepare("SELECT score, nb_tentatives FROM security_ips WHERE ip = ?");
            $row->execute([$this->ip]);
            $data = $row->fetch(PDO::FETCH_ASSOC);
            if (!$data) return;

            $threshold = (int)$this->config['sec_login_fail_threshold'];
            $should_ban = $immediate || $data['score'] >= 70 || $data['nb_tentatives'] >= $threshold;

            if ($should_ban) {
                $ban_minutes = (int)$this->config['sec_ban_duration'];
                $this->pdo->prepare("
                    UPDATE security_ips
                    SET statut = 'banni', banni_jusqu = DATE_ADD(NOW(), INTERVAL ? MINUTE)
                    WHERE ip = ?
                ")->execute([$ban_minutes, $this->ip]);
                $this->logEvent('ban_ip', "Score: {$data['score']}, Tentatives: {$data['nb_tentatives']}");
                $this->sendAlert("🚫 IP bannie : {$this->ip}", "Score: {$data['score']}, raison: {$data['raison']}");
            }
        } catch (Exception $e) {}
    }

    // ── Vérifier auto-lockdown ────────────────────────────
    private function checkAutoLockdown(): void {
        if (!(int)$this->config['sec_auto_lockdown']) return;

        try {
            // Compter tentatives globales dernière heure
            $count = $this->pdo->query("
                SELECT COUNT(*) FROM security_events
                WHERE type IN ('login_fail','brute_force')
                  AND cree_le > DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ")->fetchColumn();

            $threshold = (int)$this->config['sec_global_fail_threshold'];
            if ($count >= $threshold) {
                $this->triggerLockdown(null, "Seuil automatique atteint ($count tentatives/heure)", 'lockdown');
            }
        } catch (Exception $e) {}
    }

    // ── Déclencher le lockdown ────────────────────────────
    public function triggerLockdown(?int $user_id, string $raison, string $niveau = 'lockdown'): void {
        try {
            // Vérifier pas déjà actif
            $actif = $this->pdo->query("SELECT COUNT(*) FROM security_lockdown WHERE actif=1")->fetchColumn();
            if ($actif) return;

            $duration = (int)$this->config['sec_lockdown_duration'];
            $token    = bin2hex(random_bytes(32));
            $whitelist = json_encode([$this->getAdminIps()]);

            $this->pdo->prepare("
                INSERT INTO security_lockdown
                    (actif, niveau, raison, declenche_par, declenche_le, expire_le, token_urgence, ip_whitelist)
                VALUES (1, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? MINUTE), ?, ?)
            ")->execute([$niveau, $raison, $user_id, $duration, $token, $whitelist]);

            // Invalider toutes les sessions non-superadmin
            $this->invalidateSessions();

            // Log
            $this->logEvent('lockdown', $raison);

            // Email alerte
            $this->sendAlert(
                "🔒 LOCKDOWN ACTIVÉ — Cadurix",
                "Raison: $raison\n\nIP: {$this->ip}\nDate: " . date('d/m/Y H:i:s') .
                "\n\nURL de déblocage d'urgence:\nhttps://" . ($_SERVER['HTTP_HOST'] ?? 'votre-site') . "/security/emergency_unlock.php?token=$token\n\n" .
                "Ou accédez à admin/system.php pour déverrouiller."
            );
        } catch (Exception $e) {}
    }

    // ── Déverrouiller ─────────────────────────────────────
    public function unlock(?int $user_id, string $raison = 'Manuel'): void {
        try {
            $this->pdo->prepare("
                UPDATE security_lockdown
                SET actif = 0, debloque_le = NOW(), debloque_par = ?
                WHERE actif = 1
            ")->execute([$user_id]);
            $this->logEvent('unlock', $raison);
        } catch (Exception $e) {}
    }

    // ── Débloquer une IP ──────────────────────────────────
    public function unbanIp(string $ip): void {
        try {
            $this->pdo->prepare("
                UPDATE security_ips
                SET statut = 'surveille', banni_jusqu = NULL, score = GREATEST(score - 30, 0)
                WHERE ip = ?
            ")->execute([$ip]);
            $this->logEvent('ban_ip', "IP débannie: $ip");
        } catch (Exception $e) {}
    }

    // ── IPs admins (pour whitelist auto) ──────────────────
    private function getAdminIps(): string {
        return $this->ip; // IP de celui qui déclenche = whitelistée
    }

    // ── Invalider sessions non-admin ──────────────────────
    private function invalidateSessions(): void {
        try {
            // Marquer toutes les sessions actives comme expirées
            $this->pdo->query("DELETE FROM sessions_actives WHERE 1");
        } catch (Exception $e) {}
    }

    // ── Log événement ─────────────────────────────────────
    private function logEvent(string $type, string $details = ''): void {
        try {
            $this->pdo->prepare("
                INSERT INTO security_events (type, ip, user_id, details, user_agent, url)
                VALUES (?, ?, ?, ?, ?, ?)
            ")->execute([
                $type, $this->ip,
                $_SESSION['user_id'] ?? null,
                $details,
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                substr($_SERVER['REQUEST_URI'] ?? '', 0, 500),
            ]);
        } catch (Exception $e) {}
    }

    // ── Envoyer alerte email ──────────────────────────────
    private function sendAlert(string $subject, string $body): void {
        if (!(int)$this->config['sec_notify_email']) return;
        $email = $this->config['sec_alert_email'] ?? '';
        if (!$email) {
            // Fallback : email du super admin
            try {
                $email = $this->pdo->query("SELECT email FROM utilisateurs WHERE super_admin=1 AND actif=1 LIMIT 1")->fetchColumn();
            } catch (Exception $e) {}
        }
        if (!$email) return;

        $html = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;background:#f8fafc;padding:20px">
        <div style="max-width:500px;margin:0 auto;background:#fff;border-radius:8px;border:2px solid #ef4444;padding:24px">
          <h2 style="color:#ef4444;margin:0 0 16px">⚠️ Alerte Sécurité Cadurix</h2>
          <p style="color:#1e293b;line-height:1.6">' . nl2br(htmlspecialchars($body)) . '</p>
          <hr style="border:none;border-top:1px solid #e2e8f0;margin:16px 0">
          <p style="font-size:12px;color:#94a3b8">Cadurix v5.2 · ' . date('d/m/Y H:i:s') . '</p>
        </div></body></html>';

        $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: Cadurix Security <noreply@cadurix.fr>\r\n";
        @mail($email, $subject, $html, $headers);
    }

    // ── Afficher la page de blocage ───────────────────────
    public function renderLockdownPage(): string {
        $row = null;
        try {
            $row = $this->pdo->query("SELECT * FROM security_lockdown WHERE actif=1 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}

        $expire = $row && $row['expire_le']
            ? 'Déverrouillage automatique dans ' . ceil((strtotime($row['expire_le']) - time()) / 60) . ' min'
            : 'Déverrouillage manuel uniquement';

        return '<!DOCTYPE html><html lang="fr"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Cadurix — Accès sécurisé restreint</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,Segoe UI,Roboto,system-ui,sans-serif;background:#0a0410;color:#fde8e8;min-height:100vh;display:flex;align-items:center;justify-content:center;overflow:hidden;padding:20px}
.orbs{position:fixed;inset:0;z-index:0;pointer-events:none;overflow:hidden}
.orb{position:absolute;border-radius:50%;filter:blur(90px)}
.o1{width:560px;height:560px;background:radial-gradient(circle,rgba(239,68,68,.22),transparent 65%);top:-160px;left:-140px;animation:drift 16s ease-in-out infinite}
.o2{width:420px;height:420px;background:radial-gradient(circle,rgba(190,18,60,.16),transparent 65%);bottom:-120px;right:-100px;animation:drift 20s ease-in-out infinite reverse}
@keyframes drift{0%,100%{transform:translate(0,0)}50%{transform:translate(28px,-22px)}}
.scan{position:fixed;inset:0;z-index:0;pointer-events:none;background:repeating-linear-gradient(transparent,transparent 3px,rgba(239,68,68,.025) 4px)}
.shield{position:fixed;font-size:15rem;opacity:.04;bottom:-3rem;right:-2rem;z-index:0;animation:floaty 8s ease-in-out infinite}
@keyframes floaty{0%,100%{transform:translateY(0)}50%{transform:translateY(-18px)}}
.wrap{position:relative;z-index:1;width:100%;max-width:460px;animation:up .8s ease both}
@keyframes up{from{opacity:0;transform:translateY(24px)}to{opacity:1;transform:translateY(0)}}
.logo{display:flex;align-items:center;gap:12px;justify-content:center;margin-bottom:1.8rem}
.logo-mark{width:46px;height:46px;background:linear-gradient(135deg,#ef4444,#be123c);border-radius:13px;display:flex;align-items:center;justify-content:center;font-size:22px;box-shadow:0 0 32px rgba(239,68,68,.5)}
.logo-name{font-size:1.5rem;font-weight:800;letter-spacing:-.5px;color:#fff}
.logo-name em{color:#fca5a5;font-style:normal}
.card{background:rgba(255,255,255,.03);border:1px solid rgba(239,68,68,.25);border-radius:22px;padding:2.4rem 2rem;text-align:center;backdrop-filter:blur(24px);box-shadow:0 30px 70px rgba(0,0,0,.6),inset 0 1px 0 rgba(255,255,255,.05)}
.ring{width:84px;height:84px;margin:0 auto 1.4rem;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:2.2rem;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);position:relative}
.ring::before{content:"";position:absolute;inset:-6px;border-radius:50%;border:2px solid rgba(239,68,68,.35);border-top-color:transparent;animation:spin 2.4s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.badge{display:inline-flex;align-items:center;gap:7px;background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);color:#fca5a5;padding:.35rem 1rem;border-radius:99px;font-size:.76rem;font-weight:700;margin-bottom:1.3rem}
.bdot{width:7px;height:7px;border-radius:50%;background:#ef4444;animation:blink 1.4s infinite}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.2}}
h1{font-size:1.45rem;font-weight:800;color:#fff;margin-bottom:.7rem;letter-spacing:-.3px}
p{font-size:.9rem;color:rgba(253,232,232,.55);line-height:1.65;margin-bottom:.9rem}
.note{font-size:.8rem;color:rgba(253,232,232,.4)}
.timer{margin-top:1.4rem;padding-top:1.2rem;border-top:1px solid rgba(239,68,68,.15);font-size:.82rem;color:#fca5a5;font-weight:600}
.footer{margin-top:1.4rem;font-size:.7rem;color:rgba(255,255,255,.18);text-align:center;letter-spacing:1.5px;text-transform:uppercase}
</style></head>
<body>
<div class="orbs"><div class="orb o1"></div><div class="orb o2"></div></div>
<div class="scan"></div>
<div class="shield">🛡️</div>
<div class="wrap">
  <div class="logo"><div class="logo-mark">🛡️</div><div class="logo-name">Caduri<em>x</em></div></div>
  <div class="card">
    <div class="ring">🔒</div>
    <div class="badge"><span class="bdot"></span>Mode protection activé</div>
    <h1>Accès temporairement restreint</h1>
    <p>Une activité inhabituelle a été détectée. Par mesure de sécurité, l\'accès au site est momentanément suspendu.</p>
    <div class="timer">🕐 ' . $expire . '</div>
  </div>
  <div class="footer">Cadurix · Sécurité · ' . date('Y') . '</div>
</div>
</body></html>';
    }

    private function renderBlockedPage(string $title, string $msg): string {
        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Bloqué</title>
<style>body{font-family:system-ui;background:#080E1A;color:#E8EEFF;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
.c{text-align:center;padding:40px}.icon{font-size:48px;margin-bottom:16px}
h1{color:#EF4444;font-size:1.3rem}p{color:#8892AA;margin-top:8px}</style>
</head><body><div class="c"><div class="icon">⛔</div><h1>' . $title . '</h1><p>' . $msg . '</p></div></body></html>';
    }

    // ── Stats sécurité pour dashboard ─────────────────────
    public function getStats(): array {
        $stats = [
            'lockdown_actif' => false,
            'ips_bannies'    => 0,
            'ips_suspectes'  => 0,
            'events_24h'     => 0,
            'login_fails_1h' => 0,
            'top_ips'        => [],
        ];
        try {
            $stats['lockdown_actif'] = (bool)$this->pdo->query("SELECT COUNT(*) FROM security_lockdown WHERE actif=1")->fetchColumn();
            $stats['ips_bannies']    = (int)$this->pdo->query("SELECT COUNT(*) FROM security_ips WHERE statut='banni'")->fetchColumn();
            $stats['ips_suspectes']  = (int)$this->pdo->query("SELECT COUNT(*) FROM security_ips WHERE score>=50 AND statut!='banni'")->fetchColumn();
            $stats['events_24h']     = (int)$this->pdo->query("SELECT COUNT(*) FROM security_events WHERE cree_le > DATE_SUB(NOW(),INTERVAL 24 HOUR)")->fetchColumn();
            $stats['login_fails_1h'] = (int)$this->pdo->query("SELECT COUNT(*) FROM security_events WHERE type='login_fail' AND cree_le > DATE_SUB(NOW(),INTERVAL 1 HOUR)")->fetchColumn();
            $stats['top_ips']        = $this->pdo->query("SELECT ip,score,nb_tentatives,statut FROM security_ips ORDER BY score DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}
        return $stats;
    }

    public function getIP(): string { return $this->ip; }
}

// ── Initialisation globale ─────────────────────────────────
function init_security(PDO $pdo): MedSecurity {
    global $security;
    if (!isset($security)) {
        $security = new MedSecurity($pdo);
    }
    return $security;
}

// ── Middleware : vérifier à chaque requête ─────────────────
function security_middleware(PDO $pdo): void {
    $sec = init_security($pdo);

    // 1. Vérifier injection SQL dans l'URL
    $sec->checkSqlInjection();

    // 2. Vérifier IP bannie
    if ($sec->isIpBanned()) {
        http_response_code(403);
        die($sec->renderLockdownPage());
    }

    // 3. Vérifier lockdown
    if ($sec->isLockdown()) {
        http_response_code(503);
        die($sec->renderLockdownPage());
    }
}

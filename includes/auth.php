<?php
// ============================================================
//  Cadurix v5 — auth.php
//  Couche de compatibilité : fonctionne avec v4 ET v5
//  Inclus en premier sur chaque page protégée
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_strict_mode', 1);
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/error_handler.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/permissions.php';
require_once __DIR__ . '/../config/modules.php';
require_once __DIR__ . '/../config/audit.php';
require_once __DIR__ . '/security.php';

// Headers sécurité HTTP (envoyés une seule fois, avant tout output)
if (!headers_sent()) headers_securite();

// Régénération périodique
regenerer_session();

// Sécurité globale : IP bannie + lockdown + anti-injection SQL sur TOUTES les pages protégées
security_middleware($pdo);

// Forcer la connexion
if (!isset($_SESSION['user_id'])) {
    header('Location: /index.php');
    exit;
}

// Charger les permissions multisite
charger_permissions($pdo, $_SESSION['user_id']);

// ── Auto-définir le site actif si pas encore fait ───────────
if (!isset($_SESSION['site_actif_id'])) {
    if ($_SESSION['super_admin'] ?? false) {
        $_s = $pdo->query("SELECT id FROM sites WHERE actif=1 LIMIT 1");
        $_sid = (int)($_s->fetchColumn() ?: 0);
        if ($_sid) $_SESSION['site_actif_id'] = $_sid;
    } elseif (!empty($_SESSION['permissions'])) {
        $_SESSION['site_actif_id'] = array_key_first($_SESSION['permissions']);
    }
}
// ── Déconnexion automatique par inactivité ──────────────────
$_current_page = basename($_SERVER['PHP_SELF'] ?? '');
$_exempt_pages = ['scan.php', 'terrain.php', 'verify_2fa.php', 'ping.php'];

if (!in_array($_current_page, $_exempt_pages)) {
    // Lire le timeout depuis settings.json
    $_settings_file = __DIR__ . '/../config/settings.json';
    $_timeout_min   = 30; // défaut
    if (file_exists($_settings_file)) {
        $_s = json_decode(file_get_contents($_settings_file), true) ?? [];
        $_timeout_min = max(5, (int)($_s['session_timeout_minutes'] ?? 30));
    }
    $_timeout_sec = $_timeout_min * 60;

    if (isset($_SESSION['last_activity'])) {
        $inactif = time() - $_SESSION['last_activity'];
        if ($inactif > $_timeout_sec) {
            // Session expirée — déconnecter
            $user_id_log = $_SESSION['user_id'] ?? null;
            session_unset();
            session_destroy();
            header('Location: /index.php?timeout=1');
            exit;
        }
    }
    $_SESSION['last_activity'] = time();
// ── Expiration mot de passe (90 jours) ─────────────────────
if (!($_SESSION['super_admin'] ?? false)) {
    $_pwd_check = $pdo->prepare("SELECT mot_de_passe_le FROM utilisateurs WHERE id=?");
    $_pwd_check->execute([$_SESSION['user_id']]);
    $_pwd_row = $_pwd_check->fetch(PDO::FETCH_ASSOC);
    // Vérifier la date de changement du mot de passe (colonne dédiée)
    if ($_pwd_row && $_pwd_row['mot_de_passe_le']) {
        $_pwd_age = (time() - strtotime($_pwd_row['mot_de_passe_le'])) / 86400;
        if ($_pwd_age > 90) {
            // Forcer le changement de mot de passe
            if (!in_array(basename($_SERVER['PHP_SELF']), ['profile.php','logout.php','index.php'])) {
                $_SESSION['force_pwd_change'] = true;
                header('Location: /profile.php?force_pwd=1');
                exit;
            }
        }
    }
}


    // Passer le timeout au JS via une variable globale
    $_SESSION['_timeout_minutes'] = $_timeout_min;
}



// ============================================================
//  COMPATIBILITÉ v4 — fonctions conservées pour les anciens fichiers
// ============================================================

function currentRole(): string {
    // v5 : rôle sur le site actif
    $role = role_sur_site();
    if ($role === ROLE_SUPER_ADMIN) return 'admin';
    return match($role) {
        'administrateur' => 'admin',
        'gestionnaire'   => 'gestionnaire',
        'saisie'         => 'saisie',
        'lecteur'        => 'lecteur',
        'biomedical'     => 'biomedical',
        default          => 'lecteur',
    };
}

function currentUserId(): int    { return (int)($_SESSION['user_id'] ?? 0); }
function currentUserName(): string {
    return trim($_SESSION['user_nom'] ?? $_SESSION['user_name'] ?? 'Utilisateur');
}

function isAdmin(): bool        { return ($_SESSION['super_admin'] ?? false) || currentRole() === 'admin'; }
function isGestionnaire(): bool { return in_array(currentRole(), ['admin','gestionnaire']); }
function canEdit(): bool        { return in_array(currentRole(), ['admin','gestionnaire','saisie']); }
function canFullEdit(): bool    { return isGestionnaire(); }
function isLecteur(): bool      { return currentRole() === 'lecteur'; }

// ── Permissions par action (boutons) — stockées dans role_permissions ──
// Modules : action_modifier / action_renouveler / action_supprimer (niveau >=1 = autorisé)
// Défaut si non configuré : gestionnaire et plus (via ta vraie hiérarchie peut_faire).
function action_permise(string $action): bool {
    if ($_SESSION['super_admin'] ?? false) return true;
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            global $pdo;
            $role = function_exists('role_sur_site') ? role_sur_site() : null; // clé BRUTE (administrateur, gestionnaire…) = celle de role_permissions
            if ($role && isset($pdo) && $pdo instanceof PDO) {
                $st = $pdo->prepare("SELECT module, niveau FROM role_permissions WHERE role=? AND module IN ('action_modifier','action_renouveler','action_supprimer','action_historique','action_ajuster')");
                $st->execute([$role]);
                foreach ($st as $r) $cache[$r['module']] = (int)$r['niveau'];
            }
        } catch (Throwable $e) {}
    }
    $key = 'action_' . $action;
    if (array_key_exists($key, $cache)) return $cache[$key] >= 1;
    // Non configuré → défaut : gestionnaire et plus (hiérarchie officielle)
    return peut_faire(ROLE_GESTIONNAIRE);
}

function requireAdmin(): void        { if (!isAdmin())        accessDenied(); }
function requireGestionnaire(): void { if (!isGestionnaire()) accessDenied(); }
function requireCanEdit(): void      { if (!canEdit())        accessDenied(); }

function accessDenied(): void {
    flashError("Accès refusé — vous n'avez pas les droits nécessaires.");
    header('Location: /dashboard.php');
    exit;
}

// ============================================================
//  CSRF — compatibilité v4 (csrfField / verifyCsrf)
// ============================================================
function csrfToken(): string  { return csrf_token(); }
function csrfField(): string  { return csrf_field(); }
function verifyCsrf(): void   { csrf_check(); }

// ============================================================
//  AUDIT — compatibilité v4
// ============================================================
function auditLog(
    PDO    $pdo,
    string $action,
    string $entityType  = '',
    int    $entityId    = 0,
    string $entityName  = '',
    mixed  $oldValue    = null,
    mixed  $newValue    = null
): void {
    audit_log($pdo, $action, $entityType, $entityId ?: null, $oldValue, $newValue);
}

// ============================================================
//  FLASH MESSAGES
// ============================================================
function flashSuccess(string $msg): void { $_SESSION['flash_success'] = $msg; }
function flashError(string $msg): void   { $_SESSION['flash_error']   = $msg; }
function getFlash(): array {
    $r = [
        'success' => $_SESSION['flash_success'] ?? '',
        'error'   => $_SESSION['flash_error']   ?? '',
    ];
    unset($_SESSION['flash_success'], $_SESSION['flash_error']);
    return $r;
}

// ============================================================
//  MESSAGERIE — nb non lus (table v5)
// ============================================================
function unreadCount(PDO $pdo): int {
    try {
        $uid = currentUserId();
        // Essai table v5
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM messages
            WHERE (destinataire_id = ? OR destinataire_id IS NULL)
              AND expediteur_id != ?
              AND lu = 0
              AND parent_id IS NULL
        ");
        $stmt->execute([$uid, $uid]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable) {
        // Fallback table v4
        try {
            $uid = currentUserId();
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM messages
                WHERE (receiver_id = ? OR receiver_id IS NULL)
                  AND sender_id != ?
                  AND is_read = 0
                  AND parent_id IS NULL
            ");
            $stmt->execute([$uid, $uid]);
            return (int)$stmt->fetchColumn();
        } catch (Throwable) { return 0; }
    }
}

// ============================================================
//  HELPERS VISUELS
// ============================================================
function roleLabel(string $role = ''): string {
    return match($role ?: currentRole()) {
        'admin','administrateur' => 'Administrateur',
        'gestionnaire'           => 'Gestionnaire',
        'saisie'                 => 'Saisie',
        'lecteur'                => 'Lecteur',
        'biomedical'             => 'Biomédical',
        default                  => 'Utilisateur',
    };
}

function roleIcon(string $role = ''): string {
    return match($role ?: currentRole()) {
        'admin','administrateur' => '⭐',
        'gestionnaire'           => '🔧',
        'saisie'                 => '✏️',
        'lecteur'                => '👁',
        'biomedical'             => '🔬',
        default                  => '👤',
    };
}

function roleColor(string $role = ''): string {
    return match($role ?: currentRole()) {
        'admin','administrateur' => '#EF4444',
        'gestionnaire'           => '#F59E0B',
        'saisie'                 => '#10B981',
        'lecteur'                => '#8B5CF6',
        'biomedical'             => '#06B6D4',
        default                  => '#6B7280',
    };
}

function roleBadgeClass(string $role = ''): string {
    return match($role ?: currentRole()) {
        'admin','administrateur' => 'badge-critical',
        'gestionnaire'           => 'badge-warning',
        'saisie'                 => 'badge-ok',
        default                  => 'badge-blue',
    };
}

function expiryStatus(string $date): string {
    $expiry = new DateTime($date);
    $now    = new DateTime();
    if ($expiry < $now) return 'expired';
    $days = (int)$now->diff($expiry)->days;
    if ($days <= 7)  return 'critical';
    if ($days <= 30) return 'warning';
    return 'ok';
}

function daysLeft(string $date): int {
    $expiry = new DateTime($date);
    $now    = new DateTime();
    $diff   = $now->diff($expiry);
    return ($expiry < $now) ? -$diff->days : $diff->days;
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

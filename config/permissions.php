<?php
/**
 * Cadurix v5 — Permissions multisite
 * Toutes les vérifications de droits passent par ce fichier
 */

// ----------------------------------------------------------------
//  Constantes de rôles
// ----------------------------------------------------------------
define('ROLE_SUPER_ADMIN',   'super_admin');
define('ROLE_ADMIN',         'administrateur');
define('ROLE_GESTIONNAIRE',  'gestionnaire');
define('ROLE_SAISIE',        'saisie');
define('ROLE_LECTEUR',       'lecteur');
define('ROLE_BIOMEDICAL',    'biomedical');
define('ROLE_SSE',          'sse');

// Hiérarchie des rôles (plus le chiffre est élevé, plus de droits)
const ROLES_NIVEAUX = [
    ROLE_LECTEUR        => 1,
    ROLE_SAISIE         => 2,
    ROLE_BIOMEDICAL     => 2, // Même niveau que saisie mais accès différent
    ROLE_SSE            => 2, // Agent SSE/NRBC
    ROLE_GESTIONNAIRE   => 3,
    ROLE_ADMIN          => 4,
    ROLE_SUPER_ADMIN    => 99,
];

// ----------------------------------------------------------------
//  Vérification de connexion
// ----------------------------------------------------------------
function requiert_connexion(): void {
    if (empty($_SESSION['user_id'])) {
        header('Location: /index.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }
    regenerer_session();
}

// ----------------------------------------------------------------
//  Chargement des permissions de l'utilisateur connecté
//  Stocké en session pour éviter une requête à chaque page
//  → Rechargé automatiquement si la version globale a changé
//    (un admin a modifié un rôle / un droit).
// ----------------------------------------------------------------
function charger_permissions(PDO $pdo, int $user_id): void {
    // Version globale des permissions (bumpée quand un admin modifie un rôle / droit)
    $gv = 0;
    try { $gv = (int)($pdo->query("SELECT valeur FROM parametres WHERE cle='perms_version'")->fetchColumn() ?: 0); } catch (Throwable $e) {}

    if (isset($_SESSION['permissions_chargees'])
        && $_SESSION['permissions_user_id'] === $user_id
        && (int)($_SESSION['perms_version'] ?? -1) === $gv) {
        return; // Déjà en session ET à jour
    }

    // Vérifier super_admin
    $stmt = $pdo->prepare("SELECT super_admin FROM utilisateurs WHERE id = ? AND actif = 1");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        session_destroy();
        header('Location: /index.php?err=session');
        exit;
    }

    $_SESSION['super_admin'] = (bool) $user['super_admin'];

    if (!$user['super_admin']) {
        // Charger les permissions par site
        $stmt = $pdo->prepare("
            SELECT usp.site_id, usp.role, usp.locations_ids,
                   s.nom AS site_nom, s.organisation_id,
                   o.nom AS org_nom
            FROM user_site_permissions usp
            JOIN sites s         ON s.id = usp.site_id AND s.actif = 1
            JOIN organisations o ON o.id = s.organisation_id AND o.actif = 1
            WHERE usp.user_id = ?
        ");
        $stmt->execute([$user_id]);
        $perms = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $permissions = [];
        foreach ($perms as $p) {
            $permissions[$p['site_id']] = [
                'role'           => $p['role'],
                'site_nom'       => $p['site_nom'],
                'organisation_id'=> $p['organisation_id'],
                'org_nom'        => $p['org_nom'],
                'locations_ids'  => $p['locations_ids']
                    ? array_map('intval', explode(',', $p['locations_ids']))
                    : null, // null = tous les emplacements
            ];
        }
        $_SESSION['permissions'] = $permissions;
    }

    $_SESSION['perms_version']        = $gv;
    $_SESSION['permissions_chargees'] = true;
    $_SESSION['permissions_user_id']  = $user_id;
}

// ----------------------------------------------------------------
//  Invalidation ciblée : bumper la version globale des permissions.
//  À appeler après tout changement de rôle / droit dans l'admin.
//  Effet : chaque utilisateur recharge ses permissions à sa prochaine page.
// ----------------------------------------------------------------
function perms_bump(PDO $pdo): void {
    try {
        $t = (string) time();
        $pdo->prepare("INSERT INTO parametres (cle,valeur) VALUES('perms_version',?) ON DUPLICATE KEY UPDATE valeur=VALUES(valeur)")
            ->execute([$t]);
    } catch (Throwable $e) {}
}

// ----------------------------------------------------------------
//  Obtenir le site actif (en session)
// ----------------------------------------------------------------
function site_actif(): ?int {
    return $_SESSION['site_actif_id'] ?? null;
}

function definir_site_actif(int $site_id, PDO $pdo): bool {
    $user_id = $_SESSION['user_id'];

    // Super admin : peut accéder à tout
    if ($_SESSION['super_admin'] ?? false) {
        $stmt = $pdo->prepare("SELECT id FROM sites WHERE id = ? AND actif = 1");
        $stmt->execute([$site_id]);
        if (!$stmt->fetch()) return false;
        $_SESSION['site_actif_id'] = $site_id;
        return true;
    }

    // Vérifier que l'user a bien accès à ce site
    if (!isset($_SESSION['permissions'][$site_id])) return false;

    $_SESSION['site_actif_id'] = $site_id;
    return true;
}

// ----------------------------------------------------------------
//  Vérifications de droits
// ----------------------------------------------------------------

/**
 * L'utilisateur a-t-il accès au site donné ?
 */
function peut_acceder_site(int $site_id): bool {
    if ($_SESSION['super_admin'] ?? false) return true;
    return isset($_SESSION['permissions'][$site_id]);
}

/**
 * Quel est le rôle de l'user sur le site actif ?
 */
function role_sur_site(?int $site_id = null): ?string {
    if ($_SESSION['super_admin'] ?? false) return ROLE_SUPER_ADMIN;
    $sid = $site_id ?? site_actif();
    if (!$sid) return null;
    return $_SESSION['permissions'][$sid]['role'] ?? null;
}

/**
 * L'utilisateur a-t-il au moins le niveau de rôle demandé ?
 * Ex : peut_faire(ROLE_GESTIONNAIRE) → true si gestionnaire, admin ou super_admin
 */
function peut_faire(string $role_minimum, ?int $site_id = null): bool {
    $role_user = role_sur_site($site_id);
    if (!$role_user) return false;

    $niveau_user = ROLES_NIVEAUX[$role_user]      ?? 0;
    $niveau_min  = ROLES_NIVEAUX[$role_minimum]   ?? 99;

    return $niveau_user >= $niveau_min;
}

/**
 * L'utilisateur a-t-il accès à l'emplacement donné ?
 */
function peut_voir_emplacement(int $location_id, ?int $site_id = null): bool {
    if ($_SESSION['super_admin'] ?? false) return true;

    $sid = $site_id ?? site_actif();
    if (!$sid || !isset($_SESSION['permissions'][$sid])) return false;

    $locations_ids = $_SESSION['permissions'][$sid]['locations_ids'];

    // null = accès total sur ce site
    if ($locations_ids === null) return true;

    return in_array($location_id, $locations_ids, true);
}

/**
 * Retourne la liste des IDs d'emplacements autorisés pour l'user
 * null = tous, [] = aucun, [1,2,3] = liste restreinte
 */
function emplacements_autorises(?int $site_id = null): ?array {
    if ($_SESSION['super_admin'] ?? false) return null; // Tous

    $sid = $site_id ?? site_actif();
    if (!$sid || !isset($_SESSION['permissions'][$sid])) return [];

    return $_SESSION['permissions'][$sid]['locations_ids']; // null ou tableau
}

/**
 * Retourne la liste des sites accessibles par l'utilisateur
 */
function sites_accessibles(PDO $pdo): array {
    $user_id = $_SESSION['user_id'];

    if ($_SESSION['super_admin'] ?? false) {
        $stmt = $pdo->query("
            SELECT s.id, s.nom, s.organisation_id, s.site_parent_id,
                   o.nom AS org_nom, o.type AS org_type
            FROM sites s
            JOIN organisations o ON o.id = s.organisation_id
            WHERE s.actif = 1 AND o.actif = 1
            ORDER BY o.nom, s.nom
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $site_ids = array_keys($_SESSION['permissions'] ?? []);
    if (empty($site_ids)) return [];

    $placeholders = implode(',', array_fill(0, count($site_ids), '?'));
    $stmt = $pdo->prepare("
        SELECT s.id, s.nom, s.organisation_id, s.site_parent_id,
               o.nom AS org_nom, o.type AS org_type
        FROM sites s
        JOIN organisations o ON o.id = s.organisation_id
        WHERE s.id IN ($placeholders) AND s.actif = 1 AND o.actif = 1
        ORDER BY o.nom, s.nom
    ");
    $stmt->execute($site_ids);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ----------------------------------------------------------------
//  Clause SQL sécurisée pour filtrer les emplacements
//  À injecter dans vos requêtes sur produits/emplacements
// ----------------------------------------------------------------
function sql_filtre_emplacements(string $alias_emplacement = 'e'): string {
    if ($_SESSION['super_admin'] ?? false) return '1=1';

    $ids = emplacements_autorises();
    if ($ids === null) return '1=1';
    if (empty($ids)) return '1=0';

    $liste = implode(',', array_map('intval', $ids));
    return "{$alias_emplacement}.id IN ({$liste})";
}

function sql_filtre_site(string $alias_site = 's'): string {
    if ($_SESSION['super_admin'] ?? false) return '1=1';

    $sid = site_actif();
    if (!$sid) return '1=0';
    return "{$alias_site}.id = " . intval($sid);
}

// ----------------------------------------------------------------
//  Raccourcis de vérification avec redirection
// ----------------------------------------------------------------
function requiert_role(string $role_minimum, ?int $site_id = null): void {
    requiert_connexion();
    if (!peut_faire($role_minimum, $site_id)) {
        http_response_code(403);
        include __DIR__ . '/../includes/erreur_403.php';
        exit;
    }
}

function requiert_super_admin(): void {
    requiert_connexion();
    if (!($_SESSION['super_admin'] ?? false)) {
        http_response_code(403);
        include __DIR__ . '/../includes/erreur_403.php';
        exit;
    }
}

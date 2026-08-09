<?php
/**
 * Cadurix v5 — Système de modules
 * Activation / configuration des fonctionnalités par site, sans toucher au code.
 *
 * Cascade de résolution pour chaque module :
 *   1. Config du site actif    (modules.site_id = site courant)
 *   2. Config globale           (modules.site_id = 0)
 *   3. Valeur par défaut codée  (modules_registry())
 *
 * → Un module fonctionne avec ses défauts même sans aucune ligne en BDD.
 *   La table `modules` ne sert qu'à SURCHARGER ces défauts.
 *
 * Inclus automatiquement via includes/auth.php (après permissions.php).
 *
 * Usage dans le site :
 *   if (module_actif('scanner')) { ... }
 *   $delai = (int) module_param('scanner', 'delai_qr_medicament', 5);
 */

// ================================================================
//  REGISTRE DES MODULES CONNUS
//  defaut  = état si aucune config en BDD (true = actif)
//  params  = paramètres configurables : type, defaut, label (+ min/max)
//            types supportés : 'bool', 'int', 'float', 'text'
//  groupe  = regroupement dans le panneau admin
//  expirable = ce module peut recevoir une date d'expiration (expire_le)
// ================================================================
function modules_registry(): array {
    static $reg = null;
    if ($reg !== null) return $reg;

    $reg = [

        // ── Terrain / saisie ─────────────────────────────────
        'terrain' => [
            'nom' => 'Terrain', 'groupe' => 'Terrain', 'icone' => '📲',
            'defaut' => true, 'params' => [],
        ],
        'scanner' => [
            'nom' => 'Scanner code-barres', 'groupe' => 'Terrain', 'icone' => '📷',
            'defaut' => true,
            'params' => [
                'delai_qr_medicament' => ['type'=>'int',  'defaut'=>5,    'label'=>'Délai QR → médicament (s)', 'min'=>0, 'max'=>30],
                'anti_doublon'        => ['type'=>'int',  'defaut'=>2,    'label'=>'Anti-doublon (s)',          'min'=>0, 'max'=>10],
                'vibration'           => ['type'=>'bool', 'defaut'=>true, 'label'=>'Vibration au scan'],
            ],
        ],
        'scan_emplacement' => [
            'nom' => 'Scan emplacement (QR)', 'groupe' => 'Terrain', 'icone' => '🏷️',
            'defaut' => true, 'params' => [],
        ],

        // ── Stock ────────────────────────────────────────────
        'emplacements' => [
            'nom' => 'Emplacements', 'groupe' => 'Stock', 'icone' => '📦',
            'defaut' => true, 'params' => [],
        ],
        'rempotages' => [
            'nom' => 'Rempotages', 'groupe' => 'Stock', 'icone' => '🔄',
            'defaut' => true, 'params' => [],
        ],
        'medicaments' => [
            'nom' => 'Médicaments / Produits', 'groupe' => 'Stock', 'icone' => '💊',
            'defaut' => true, 'params' => [],
        ],
        'dotations' => [
            'nom' => 'Dotations réglementaires', 'groupe' => 'Stock', 'icone' => '📋',
            'defaut' => true, 'params' => [],
        ],
        'tracabilite' => [
            'nom' => 'Traçabilité des lots', 'groupe' => 'Stock', 'icone' => '🔍',
            'defaut' => true, 'params' => [],
        ],

        // ── Alertes ──────────────────────────────────────────
        'alertes' => [
            'nom' => 'Alertes péremption', 'groupe' => 'Alertes', 'icone' => '🔔',
            'defaut' => true,
            'params' => [
                'seuil_peremption_j' => ['type'=>'int',  'defaut'=>30,    'label'=>'Seuil péremption (jours)', 'min'=>1, 'max'=>365],
                'email'              => ['type'=>'bool', 'defaut'=>false, 'label'=>'Envoi email'],
            ],
        ],
        'fiches' => [
            'nom' => 'Fiches', 'groupe' => 'Alertes', 'icone' => '📄',
            'defaut' => true, 'params' => [],
        ],

        // ── IA ───────────────────────────────────────────────
        'assistant_ia' => [
            'nom' => 'Assistant IA', 'groupe' => 'IA', 'icone' => '🤖',
            'defaut' => true, 'params' => [],
        ],
        'saisie_vocale' => [
            'nom' => 'Saisie vocale', 'groupe' => 'IA', 'icone' => '🎤',
            'defaut' => true, 'params' => [],
        ],
        'ia_photo' => [
            'nom' => 'Identification IA (photo)', 'groupe' => 'IA', 'icone' => '🖼️',
            'defaut' => false,
            'params' => [
                'budget_mois_eur' => ['type'=>'float', 'defaut'=>5.0, 'label'=>'Budget max / mois (€)'],
                'alerte_pct'      => ['type'=>'int',   'defaut'=>80,  'label'=>'Alerte à (%)', 'min'=>10, 'max'=>100],
            ],
        ],

        // ── Communication ────────────────────────────────────
        'messagerie' => [
            'nom' => 'Messagerie', 'groupe' => 'Communication', 'icone' => '✉️',
            'defaut' => true, 'params' => [],
        ],
        'export_pdf' => [
            'nom' => 'Export PDF', 'groupe' => 'Communication', 'icone' => '🧾',
            'defaut' => true, 'params' => [],
        ],
        'notif_email' => [
            'nom' => 'Notifications email', 'groupe' => 'Communication', 'icone' => '📧',
            'defaut' => false, 'params' => [],
        ],
        'push_pwa' => [
            'nom' => 'Notifications push (PWA)', 'groupe' => 'Communication', 'icone' => '🔕',
            'defaut' => false, 'params' => [],
        ],
        'api_rest' => [
            'nom' => 'API REST', 'groupe' => 'Communication', 'icone' => '🔑',
            'defaut' => false, 'params' => [],
        ],

        // ── Sécurité ──────────────────────────────────────────
        '2fa_totp' => [
            'nom' => '2FA TOTP', 'groupe' => 'Sécurité', 'icone' => '🔐',
            'defaut' => false, 'params' => [],
        ],
        'anti_bruteforce' => [
            'nom' => 'Anti brute-force', 'groupe' => 'Sécurité', 'icone' => '🛡️',
            'defaut' => true, 'params' => [],
        ],
        'audit_log' => [
            'nom' => 'Journal d\'audit', 'groupe' => 'Sécurité', 'icone' => '🗂️',
            'defaut' => true, 'params' => [],
        ],

        // ── Modules spéciaux ──────────────────────────────────
        'sse_nrbc' => [
            'nom' => 'SSE / NRBC', 'groupe' => 'Spéciaux', 'icone' => '☣️',
            'defaut' => false, 'params' => [],
        ],
        'biomedical' => [
            'nom' => 'Biomédical', 'groupe' => 'Spéciaux', 'icone' => '🔬',
            'defaut' => true, 'params' => [],
        ],

        // ── Système ───────────────────────────────────────────
        'maintenance' => [
            'nom' => 'Mode maintenance', 'groupe' => 'Système', 'icone' => '🚧',
            'defaut' => false, 'expirable' => true, 'params' => [],
        ],
    ];

    return $reg;
}

// ================================================================
//  CHARGEMENT (1 requête par page, mise en cache statique)
//  Retourne ['global' => [...], 'site' => [...]]
// ================================================================
function _modules_charger(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    global $pdo;
    $cache = ['global' => [], 'site' => []];
    $sid   = function_exists('site_actif') ? (int)(site_actif() ?? 0) : 0;

    try {
        $stmt = $pdo->prepare("
            SELECT cle, site_id, actif, params, expire_le
            FROM modules
            WHERE site_id = 0 OR site_id = ?
        ");
        $stmt->execute([$sid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $now = time();
        foreach ($rows as $r) {
            // Ligne expirée → ignorée (on retombe sur le niveau inférieur)
            if (!empty($r['expire_le']) && strtotime($r['expire_le']) < $now) {
                continue;
            }
            $entry = [
                'actif'  => (bool) $r['actif'],
                'params' => $r['params'] ? (json_decode($r['params'], true) ?: []) : [],
            ];
            if ((int) $r['site_id'] === 0) {
                $cache['global'][$r['cle']] = $entry;
            } else {
                $cache['site'][$r['cle']] = $entry;
            }
        }
    } catch (Throwable $e) {
        // Table absente / erreur BDD → tout repart sur les défauts du registre
        $cache = ['global' => [], 'site' => []];
    }

    return $cache;
}

// ================================================================
//  API PUBLIQUE
// ================================================================

/**
 * Le module est-il actif ? (site → global → défaut codé)
 */
function module_actif(string $cle): bool {
    $data = _modules_charger();
    if (isset($data['site'][$cle]))   return $data['site'][$cle]['actif'];
    if (isset($data['global'][$cle])) return $data['global'][$cle]['actif'];

    $reg = modules_registry();
    return (bool) ($reg[$cle]['defaut'] ?? false);
}

/**
 * Valeur d'un paramètre de module (site → global → défaut registre → $defaut).
 */
function module_param(string $cle, string $param, mixed $defaut = null): mixed {
    $data = _modules_charger();
    if (isset($data['site'][$cle]['params'][$param]))   return $data['site'][$cle]['params'][$param];
    if (isset($data['global'][$cle]['params'][$param])) return $data['global'][$cle]['params'][$param];

    $reg = modules_registry();
    if (isset($reg[$cle]['params'][$param]['defaut'])) return $reg[$cle]['params'][$param]['defaut'];

    return $defaut;
}

// ================================================================
//  ÉCRITURE — utilisé par le panneau admin "Modules"
//  $site_id = 0 → configuration globale par défaut
// ================================================================
function module_definir(
    PDO    $pdo,
    string $cle,
    int    $site_id,
    bool   $actif,
    array  $params = [],
    ?string $expire_le = null
): bool {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO modules (cle, site_id, actif, params, expire_le)
            VALUES (:cle, :sid, :actif, :params, :exp)
            ON DUPLICATE KEY UPDATE
                actif     = VALUES(actif),
                params    = VALUES(params),
                expire_le = VALUES(expire_le)
        ");
        return $stmt->execute([
            ':cle'    => $cle,
            ':sid'    => $site_id,
            ':actif'  => $actif ? 1 : 0,
            ':params' => $params ? json_encode($params, JSON_UNESCAPED_UNICODE) : null,
            ':exp'    => $expire_le,
        ]);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Remet un module à son défaut sur un site (supprime la surcharge).
 */
function module_reinitialiser(PDO $pdo, string $cle, int $site_id): bool {
    try {
        return $pdo->prepare("DELETE FROM modules WHERE cle = ? AND site_id = ?")
                   ->execute([$cle, $site_id]);
    } catch (Throwable $e) {
        return false;
    }
}

// ================================================================
//  PROFILS DE CONFIGURATION
//  Un profil = un instantané { cle => {actif, params} } applicable
//  à un périmètre (0 = global, ou un site_id) en 1 clic.
// ================================================================

/** Config effective d'un périmètre (site → global → défaut), tous modules. */
function modules_config_effective(PDO $pdo, int $scope_site): array {
    $reg = modules_registry();
    $rows = [];
    try {
        $st = $pdo->prepare("SELECT cle, site_id, actif, params FROM modules WHERE site_id IN (0, ?)");
        $st->execute([$scope_site]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[(int)$r['site_id']][$r['cle']] = $r;
        }
    } catch (Throwable $e) {}

    $glob = $rows[0] ?? [];
    $site = $scope_site ? ($rows[$scope_site] ?? []) : [];

    $config = [];
    foreach ($reg as $cle => $def) {
        $row = ($scope_site && isset($site[$cle])) ? $site[$cle] : ($glob[$cle] ?? null);
        $rp  = ($row && $row['params']) ? (json_decode($row['params'], true) ?: []) : [];
        $params = [];
        foreach (($def['params'] ?? []) as $pk => $pdef) {
            $params[$pk] = $rp[$pk] ?? $pdef['defaut'];
        }
        $config[$cle] = [
            'actif'  => $row ? (bool)$row['actif'] : (bool)($def['defaut'] ?? false),
            'params' => $params,
        ];
    }
    return $config;
}

function module_profil_sauvegarder(PDO $pdo, string $nom, string $desc, array $config, ?int $user_id = null): bool {
    try {
        return $pdo->prepare("
            INSERT INTO module_profils (nom, description, config, cree_par)
            VALUES (:nom, :desc, :config, :uid)
            ON DUPLICATE KEY UPDATE description=VALUES(description), config=VALUES(config)
        ")->execute([
            ':nom'    => $nom,
            ':desc'   => $desc ?: null,
            ':config' => json_encode($config, JSON_UNESCAPED_UNICODE),
            ':uid'    => $user_id,
        ]);
    } catch (Throwable $e) { return false; }
}

function module_profil_lister(PDO $pdo): array {
    try {
        return $pdo->query("SELECT id, nom, description, cree_le FROM module_profils ORDER BY nom")
                   ->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

function module_profil_charger(PDO $pdo, int $id): ?array {
    try {
        $st = $pdo->prepare("SELECT * FROM module_profils WHERE id=?");
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        $r['config'] = json_decode($r['config'], true) ?: [];
        return $r;
    } catch (Throwable $e) { return null; }
}

function module_profil_supprimer(PDO $pdo, int $id): bool {
    try { return $pdo->prepare("DELETE FROM module_profils WHERE id=?")->execute([$id]); }
    catch (Throwable $e) { return false; }
}

/** Applique un profil à un périmètre. Retourne le nb de modules appliqués. */
function module_profil_appliquer(PDO $pdo, int $id, int $scope_site): int {
    $prof = module_profil_charger($pdo, $id);
    if (!$prof) return 0;
    $reg = modules_registry();
    $n = 0;
    foreach ($prof['config'] as $cle => $conf) {
        if (!isset($reg[$cle])) continue; // module disparu du registre → ignoré
        $actif  = !empty($conf['actif']);
        $params = is_array($conf['params'] ?? null) ? $conf['params'] : [];
        if (module_definir($pdo, $cle, $scope_site, $actif, $params, null)) $n++;
    }
    return $n;
}
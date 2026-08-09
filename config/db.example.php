<?php
// ============================================================
//  Cadurix v5 — Modèle de connexion base de données
// ------------------------------------------------------------
//  COPIEZ ce fichier en `config/db.php` (gitignoré) et adaptez.
//  Objectif : ne PLUS écrire les identifiants en clair dans le
//  code. On lit d'abord des variables d'environnement ; à défaut
//  on retombe sur des valeurs locales de dev (jamais la prod).
//
//  En prod (o2switch), définir les variables d'environnement
//  (via un .env chargé hors webroot, la config Apache/cPanel,
//  ou getenv) : DB_HOST, DB_NAME, DB_USER, DB_PASS.
// ============================================================

// Petite aide : variable d'env avec valeur de repli
function env_or(string $cle, string $defaut): string {
    $v = getenv($cle);
    return ($v !== false && $v !== '') ? $v : $defaut;
}

// ── Détection environnement ──────────────────────────────
$is_prod = isset($_SERVER['HTTP_HOST']) && str_contains($_SERVER['HTTP_HOST'], 'cadurix.fr');

if ($is_prod) {
    // ════════════════════════════════════════════════════
    //  PRODUCTION — valeurs fournies UNIQUEMENT par l'environnement
    //  (aucun secret en clair dans ce fichier)
    // ════════════════════════════════════════════════════
    define('DB_HOST', env_or('DB_HOST', 'localhost'));
    define('DB_NAME', env_or('DB_NAME', 'REMPLIR_EN_PROD'));
    define('DB_USER', env_or('DB_USER', 'REMPLIR_EN_PROD'));
    define('DB_PASS', env_or('DB_PASS', '')); // ← doit venir de l'environnement
} else {
    // ════════════════════════════════════════════════════
    //  LOCAL — Laragon (valeurs de dev par défaut, non sensibles)
    // ════════════════════════════════════════════════════
    define('DB_HOST', env_or('DB_HOST', 'localhost'));
    define('DB_NAME', env_or('DB_NAME', 'catalaunix_02845'));
    define('DB_USER', env_or('DB_USER', 'root'));
    define('DB_PASS', env_or('DB_PASS', ''));
}

define('DB_CHARSET', 'utf8mb4');

// PHP 8.5 a renommé les constantes MySQL de PDO ; compatible 8.3 ↔ 8.5
$pdoInit = defined('Pdo\Mysql::ATTR_INIT_COMMAND')
    ? \Pdo\Mysql::ATTR_INIT_COMMAND
    : PDO::MYSQL_ATTR_INIT_COMMAND;

try {
    $pdo = new PDO(
        "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=".DB_CHARSET,
        DB_USER, DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            $pdoInit                     => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ]
    );
} catch (PDOException $e) {
    if ($is_prod) {
        error_log('[Cadurix] DB connection error: ' . $e->getMessage());
        die('<div style="font-family:sans-serif;padding:40px;background:#0b0f1a;color:#f87171;min-height:100vh">
            <h2>❌ Erreur de connexion</h2>
            <p style="color:#94a3b8">Le service est temporairement indisponible. Veuillez réessayer dans quelques instants.</p>
        </div>');
    } else {
        die('<div style="font-family:sans-serif;padding:40px;background:#0b0f1a;color:#f87171;min-height:100vh">
            <h2>❌ Erreur de connexion MySQL</h2>
            <p style="color:#94a3b8;margin-top:10px">Vérifiez les paramètres dans <code>config/db.php</code> (copié depuis db.example.php)</p>
            <code style="color:#fbbf24;font-size:13px;display:block;margin-top:16px;background:#1e2535;padding:12px;border-radius:8px">'
            .htmlspecialchars($e->getMessage()).
            '</code>
        </div>');
    }
}

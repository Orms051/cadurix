<?php
// © Cadurix v5.4 — Tous droits réservés.
// ============================================================
//  config/admin_tabs.php — SOURCE UNIQUE des onglets du hub admin
//  Lu par index.php (menu + accès) ET la matrice « Onglets admin » de users.php.
//  Pour ajouter un onglet : UNE ligne ici (clé => label, icône, fichier, groupe, rôles par défaut).
//  Inclure :  require_once __DIR__ . '/../config/admin_tabs.php';
// ============================================================

if (!function_exists('admin_tabs_definis')) {
function admin_tabs_definis(): array {
    return [
        // ── Général ──
        'dashboard'   => ['label'=>'Tableau de bord','icon'=>'📊','file'=>'system.php',           'group'=>'general','defaut'=>['super_admin','admin']],
        'users'       => ['label'=>'Utilisateurs & accès','icon'=>'👥','file'=>'users.php',       'group'=>'general','defaut'=>['super_admin','admin']],
        'sites'       => ['label'=>'Organisations',  'icon'=>'🏢','file'=>'sites.php',            'group'=>'general','defaut'=>['super_admin']],
        'security'    => ['label'=>'Sécurité',       'icon'=>'🛡️','file'=>'security.php',         'group'=>'general','defaut'=>['super_admin']],
        'audit'       => ['label'=>'Journaux',       'icon'=>'📋','file'=>'audit.php',            'group'=>'general','defaut'=>['super_admin','admin']],
        // ── Contenu ──
        'alerts'       => ['label'=>'Emails',         'icon'=>'📧','file'=>'send_alerts.php',      'group'=>'contenu','defaut'=>['super_admin','admin','gestionnaire']],
        'medicaments' => ['label'=>'Médicaments',    'icon'=>'💊','file'=>'medicaments_ref.php',  'group'=>'contenu','defaut'=>['super_admin','admin','gestionnaire']],
        'fiches'      => ['label'=>'Fiches',         'icon'=>'📋','file'=>'fiches.php',           'group'=>'contenu','defaut'=>['super_admin','admin','gestionnaire']],
        'biomed'      => ['label'=>'Biomédical',     'icon'=>'🔬','file'=>'materiel.php',         'group'=>'contenu','defaut'=>['super_admin','admin']],
        // ── Système ──
        'debug'       => ['label'=>'Debug',          'icon'=>'🐛','file'=>'debug.php',            'group'=>'systeme','defaut'=>['super_admin']],
        'stats'       => ['label'=>'Statistiques',   'icon'=>'📊','file'=>'stats.php',            'group'=>'systeme','defaut'=>['super_admin','admin']],
        // ── Avancé ──
        'ia'          => ['label'=>'IA',             'icon'=>'🤖','file'=>'ai/dashboard.php',     'group'=>'avance', 'defaut'=>['super_admin','admin']],
        'finance'     => ['label'=>'Finance',        'icon'=>'💰','file'=>'cout.php',             'group'=>'avance', 'defaut'=>['super_admin','admin']],
        'apparence'   => ['label'=>'Apparence',      'icon'=>'🎨','file'=>'apparence.php',        'group'=>'avance', 'defaut'=>['super_admin','admin']],
        'api'         => ['label'=>'API',            'icon'=>'🔑','file'=>'api_tokens.php',       'group'=>'avance', 'defaut'=>['super_admin','admin']],
    ];
}
}

// Libellés des groupes (ordre d'affichage)
if (!function_exists('admin_tabs_groupes')) {
function admin_tabs_groupes(): array {
    return ['general'=>'Général','contenu'=>'Contenu','systeme'=>'Système','avance'=>'Avancé'];
}
}

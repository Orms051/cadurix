<?php
// © Cadurix v5.4 — Tous droits réservés.
// ============================================================
//  config/perms_modules.php — SOURCE UNIQUE des modules de PERMISSION
//  (NE PAS confondre avec config/modules.php = activation / feature-flags)
//  Vocabulaire des matrices d'accès : clé + label + icône + section.
//  Lu par la matrice de rôles globale ET la matrice par site.
//  Inclure :  require_once __DIR__ . '/../config/perms_modules.php';
// ============================================================

if (!function_exists('modules_permissions')) {
function modules_permissions(): array {
    return [
        // ── Général ──
        'dashboard'     => ['label'=>'Tableau de bord',          'icon'=>'📊', 'section'=>'Général'],
        'medicaments'   => ['label'=>'Médicaments',              'icon'=>'💊', 'section'=>'Général'],
        'emplacements'  => ['label'=>'Emplacements',             'icon'=>'📍', 'section'=>'Général'],
        'alertes'       => ['label'=>'Alertes',                  'icon'=>'📧', 'section'=>'Général'],
        // ── Données ──
        'rapports'      => ['label'=>'Export PDF / Rapports',    'icon'=>'📄', 'section'=>'Données'],
        'import_export' => ['label'=>'Import Excel',             'icon'=>'📥', 'section'=>'Données'],
        // ── Terrain ──
        'scanner'       => ['label'=>'Scanner',                  'icon'=>'📷', 'section'=>'Terrain'],
        'fiches'        => ['label'=>'Fiches de vérification',   'icon'=>'📋', 'section'=>'Terrain'],
        'rempotages'    => ['label'=>'Rempotages',               'icon'=>'🔄', 'section'=>'Terrain'],
        'terrain'       => ['label'=>'Terrain (interventions)',  'icon'=>'🚑', 'section'=>'Terrain'],
        'sse'           => ['label'=>'SSE / NRBC',               'icon'=>'🧪', 'section'=>'Terrain'],
        // ── Biomédical ──
        'equipements'   => ['label'=>'Équipements biomédical',   'icon'=>'🔬', 'section'=>'Biomédical'],
        'biomed'        => ['label'=>'Portail biomédical',       'icon'=>'🩺', 'section'=>'Biomédical'],
        // ── IA ──
        'ia'            => ['label'=>'Assistant IA',             'icon'=>'🤖', 'section'=>'IA'],
        // ── Messagerie ──
        'messagerie'    => ['label'=>'Messagerie',               'icon'=>'💬', 'section'=>'Messagerie'],
        // ── Administration ──
        'admin'         => ['label'=>'Administration',           'icon'=>'⚙️', 'section'=>'Administration'],
        'tracabilite'   => ['label'=>'Traçabilité des lots',     'icon'=>'🏷️', 'section'=>'Administration'],
        'vue_globale'   => ['label'=>'Vue globale (super admin)','icon'=>'🌐', 'section'=>'Administration'],
    ];
}
}

// Sections dans leur ordre d'apparition (pour grouper l'affichage)
if (!function_exists('modules_sections')) {
function modules_sections(): array {
    $out = [];
    foreach (modules_permissions() as $m) {
        if (!in_array($m['section'], $out, true)) $out[] = $m['section'];
    }
    return $out;
}
}

// ── Un module de permission est-il VISIBLE ? (feature-flag actif) ──
// Relie la clé "permission" (sse) au feature-flag (sse_nrbc). Pas de mapping = toujours visible.
if (!function_exists('module_perm_visible')) {
// Carte UNIQUE clé-permission -> feature-flag (source de vérité partagée nav + matrices)
function module_flag_map(): array {
    return [
        'emplacements'=>'emplacements', 'medicaments'=>'medicaments', 'alertes'=>'alertes',
        'scanner'=>'scanner', 'fiches'=>'fiches', 'rempotages'=>'rempotages', 'terrain'=>'terrain',
        'sse'=>'sse_nrbc', 'equipements'=>'biomedical', 'biomed'=>'biomedical',
        'messagerie'=>'messagerie', 'ia'=>'assistant_ia',
        'tracabilite'=>'tracabilite',
    ];
}
function module_perm_visible(string $permKey): bool {
    $map = module_flag_map();
    if (!isset($map[$permKey])) return true;                 // dashboard, admin, rapports… : pas de flag
    if (!function_exists('module_actif')) return true;
    return module_actif($map[$permKey]);
}
}

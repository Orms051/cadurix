<?php
// © Cadurix v5.4 — Tous droits réservés.
// ============================================================
//  config/themes.php — SOURCE UNIQUE des thèmes
//  Pour ajouter / modifier un thème : c'est ICI, et nulle part ailleurs.
//  Inclure avec :  require_once __DIR__ . '/config/themes.php';
// ============================================================

// Clé du thème de secours ultime (filet de sécurité si la BDD ne renvoie rien)
if (!defined('THEME_FALLBACK')) {
    define('THEME_FALLBACK', 'bleu_medical');
}

// ── Définitions des thèmes ─────────────────────────────────
// Clés : name, emoji, mode (light/dark), puis les couleurs :
//   bg (fond), bg2 (carte), bg3 (survol/élevé), sidebar, accent,
//   logo1/logo2 (dégradé logo), text/text2/text3 (hiérarchie), border/border2.
if (!function_exists('themes_predefinis')) {
function themes_predefinis(): array {
    return [
        // ───────── CLAIRS ─────────
        'bleu_medical'   => ['name'=>'Bleu Médical',   'emoji'=>'💙','mode'=>'light','bg'=>'#F7FAFE','bg2'=>'#FFFFFF','bg3'=>'#EEF4FC','sidebar'=>'#F0F6FF','accent'=>'#2563EB','logo1'=>'#1D4ED8','logo2'=>'#3B82F6','text'=>'#15233D','text2'=>'#5A6B86','text3'=>'#95A3BC','border'=>'#E1E9F5','border2'=>'#CFDBEE'],
        'ardoise'        => ['name'=>'Ardoise',        'emoji'=>'🪨','mode'=>'light','bg'=>'#F8F9FB','bg2'=>'#FFFFFF','bg3'=>'#EFF1F5','sidebar'=>'#F1F3F7','accent'=>'#4F46E5','logo1'=>'#4338CA','logo2'=>'#6366F1','text'=>'#1B2230','text2'=>'#5C6677','text3'=>'#98A1B0','border'=>'#E4E7EC','border2'=>'#D3D8E0'],
        'menthe_clinique'=> ['name'=>'Menthe Clinique','emoji'=>'🌿','mode'=>'light','bg'=>'#F5FBF8','bg2'=>'#FFFFFF','bg3'=>'#E9F6F0','sidebar'=>'#ECFAF4','accent'=>'#0E9F6E','logo1'=>'#047857','logo2'=>'#10B981','text'=>'#103128','text2'=>'#4A6B5E','text3'=>'#84A899','border'=>'#D4EDE3','border2'=>'#BCE3D3'],
        'sable'          => ['name'=>'Sable',          'emoji'=>'🏜️','mode'=>'light','bg'=>'#FDFBF7','bg2'=>'#FFFFFF','bg3'=>'#F6F1E9','sidebar'=>'#FBF6EE','accent'=>'#E0792B','logo1'=>'#C2410C','logo2'=>'#F59E0B','text'=>'#2B2018','text2'=>'#6E5D4E','text3'=>'#A89783','border'=>'#ECE3D5','border2'=>'#DECFB9'],

        // ───────── SOMBRES ─────────
        'nuit_bleue'     => ['name'=>'Nuit Bleue',     'emoji'=>'🌌','mode'=>'dark','bg'=>'#0B1220','bg2'=>'#131C2E','bg3'=>'#1B2740','sidebar'=>'#0E1626','accent'=>'#3B82F6','logo1'=>'#2563EB','logo2'=>'#60A5FA','text'=>'#E8EEF8','text2'=>'#8A98B5','text3'=>'#4E5C78','border'=>'#1F2B44','border2'=>'#2A3856'],
        'carbone'        => ['name'=>'Carbone',        'emoji'=>'⚫','mode'=>'dark','bg'=>'#0C0E12','bg2'=>'#14171D','bg3'=>'#1C2129','sidebar'=>'#0F1216','accent'=>'#06B6D4','logo1'=>'#0891B2','logo2'=>'#22D3EE','text'=>'#E6EAF0','text2'=>'#8A93A3','text3'=>'#4C5566','border'=>'#20242C','border2'=>'#2C3038'],
        'ambre_nocturne' => ['name'=>'Ambre Nocturne', 'emoji'=>'🟠','mode'=>'dark','bg'=>'#13100B','bg2'=>'#1C1812','bg3'=>'#261F17','sidebar'=>'#161108','accent'=>'#F59E0B','logo1'=>'#D97706','logo2'=>'#FBBF24','text'=>'#F5ECDC','text2'=>'#A8987F','text3'=>'#5E523F','border'=>'#2A2318','border2'=>'#382E1F'],
        'indigo_profond' => ['name'=>'Indigo Profond', 'emoji'=>'💜','mode'=>'dark','bg'=>'#0C0A1A','bg2'=>'#15122A','bg3'=>'#1E1A3A','sidebar'=>'#0F0C20','accent'=>'#8B5CF6','logo1'=>'#7C3AED','logo2'=>'#A78BFA','text'=>'#EAE7FB','text2'=>'#9089B8','text3'=>'#524C74','border'=>'#221E40','border2'=>'#2E2952'],
    ];
}
}

// ── Résolution d'un thème complet ──────────────────────────
// $cle_defaut        : clé du thème par défaut (admin), ex. 'bleu_medical'
// $user_theme_config : JSON du thème perso (colonne theme_config), ou null
// $couleur_accent    : ancienne colonne couleur_accent (compat), ou null
// Retourne TOUJOURS un thème complet (toutes les clés couleur garanties).
if (!function_exists('theme_resolve')) {
function theme_resolve(?string $cle_defaut = null, ?string $user_theme_config = null, ?string $couleur_accent = null): array {
    $themes = themes_predefinis();

    // 1) base = défaut admin, sinon filet de sécurité
    $theme = $themes[$cle_defaut] ?? $themes[THEME_FALLBACK];

    // 2) override utilisateur (profil)
    if (!empty($user_theme_config)) {
        $tc = json_decode($user_theme_config, true);
        if (is_array($tc)) {
            $theme = array_merge($theme, $tc);
        }
    } elseif (!empty($couleur_accent)) {
        $theme['accent'] = $couleur_accent;
    }

    // 3) validation : toute couleur hex invalide retombe sur le filet de sécurité
    foreach ($theme as $k => $v) {
        if (is_string($v) && str_starts_with($v, '#') && !preg_match('/^#[0-9A-Fa-f]{6}$/', $v)) {
            $theme[$k] = $themes[THEME_FALLBACK][$k] ?? $v;
        }
    }

    return $theme;
}
}

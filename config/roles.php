<?php
// © Cadurix v5.4 — Tous droits réservés.
// ============================================================
//  config/roles.php — SOURCE UNIQUE des rôles
//  Pour ajouter / modifier un rôle : ICI (label, icône, couleur, poste, desc).
//  La hiérarchie d'accès (peut_faire) reste gérée par includes/auth.php.
//  Inclure :  require_once __DIR__ . '/../config/roles.php';
// ============================================================

if (!function_exists('roles_definis')) {
function roles_definis(): array {
    return [
        'super_admin'    => ['label'=>'Super Admin',    'color'=>'#EF4444', 'icon'=>'⭐',  'poste'=>'Super Administrateur',  'desc'=>'Accès total, multi-organisations'],
        'administrateur' => ['label'=>'Administrateur', 'color'=>'#F59E0B', 'icon'=>'👑', 'poste'=>'Administrateur',         'desc'=>'Gestion complète du site'],
        'gestionnaire'   => ['label'=>'Gestionnaire',   'color'=>'#3B82F6', 'icon'=>'🗂️', 'poste'=>'Gestionnaire',           'desc'=>'Ajout, modification, suppression'],
        'saisie'         => ['label'=>'Saisie',         'color'=>'#10B981', 'icon'=>'✏️', 'poste'=>'Agent de saisie',        'desc'=>'Ajout et modification seulement'],
        'lecteur'        => ['label'=>'Lecteur',        'color'=>'#64748B', 'icon'=>'👁️', 'poste'=>'Lecteur',                'desc'=>'Consultation uniquement'],
        'biomedical'     => ['label'=>'Biomédical',     'color'=>'#8B5CF6', 'icon'=>'🔬', 'poste'=>'Technicien biomédical',  'desc'=>'Équipements et interventions'],
        'sse'            => ['label'=>'SSE / NRBC',     'color'=>'#06B6D4', 'icon'=>'🧪', 'poste'=>'Agent SSE/NRBC',         'desc'=>'Module SSE/NRBC uniquement'],
    ];
}
}

// Rôles assignables / éditables dans les matrices (tous sauf super_admin)
if (!function_exists('roles_editables')) {
function roles_editables(): array {
    $r = roles_definis();
    unset($r['super_admin']);
    return $r;
}
}

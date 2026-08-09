<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.

/**
 * logo.php — Logo Cadurix dynamique
 * Sert un SVG généré avec les couleurs stockées en BDD
 * Usage : <img src="logo.php"> ou <img src="logo.php?size=32">
 */

require_once __DIR__ . '/includes/auth.php';

// Paramètres GET
$size      = max(16, min(512, (int)($_GET['size'] ?? 200)));
$dark      = isset($_GET['dark']);          // ?dark pour version fond sombre
$icon_only = isset($_GET['icon']);          // ?icon pour icône seule (favicon/PWA)

// Lire les paramètres en BDD
function get_param(PDO $pdo, string $key, string $default): string {
    try {
        $stmt = $pdo->prepare("SELECT valeur FROM parametres WHERE cle = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['valeur'] : $default;
    } catch (Exception $e) {
        return $default;
    }
}

require_once __DIR__ . '/config/themes.php';

$variante   = get_param($pdo, 'logo_variante', 'A');
$avec_texte = !$icon_only && get_param($pdo, 'logo_texte', '1') === '1';

// override variante (aperçu admin)
if (isset($_GET['variante']) && in_array($_GET['variante'], ['A','B','C'], true)) $variante = $_GET['variante'];

// Couleurs héritées du thème par défaut du site (une seule source)
$theme_defaut = get_param($pdo, 'theme_defaut', THEME_FALLBACK);
$tcol         = theme_resolve($theme_defaut);
$couleur1     = $tcol['logo1'];
$couleur2     = $tcol['logo2'];

$is_preview   = isset($_GET['variante']);

// Sanitize couleurs hex
function hex_safe(string $c): string {
    return preg_match('/^#[0-9A-Fa-f]{3,6}$/', $c) ? htmlspecialchars($c) : '#0F4C81';
}
$c1 = hex_safe($couleur1);
$c2 = hex_safe($couleur2);

// Calcul couleur accent (éclaircissement simple)
function hex_lighten(string $hex, int $amount = 40): string {
    $hex = ltrim($hex, '#');
    $r = min(255, hexdec(substr($hex, 0, 2)) + $amount);
    $g = min(255, hexdec(substr($hex, 2, 2)) + $amount);
    $b = min(255, hexdec(substr($hex, 4, 2)) + $amount);
    return sprintf('#%02X%02X%02X', $r, $g, $b);
}
$accent = hex_lighten($c1, 50);

// Dimensions SVG
$vw = 512;
$vh = $avec_texte ? 640 : 512;

header('Content-Type: image/svg+xml');
header('Cache-Control: ' . (($is_preview ?? false) ? 'no-store' : 'public, max-age=3600'));

echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<svg width="<?= $size ?>" height="<?= $avec_texte ? round($size * 1.25) : $size ?>"
     viewBox="0 0 <?= $vw ?> <?= $vh ?>"
     role="img" xmlns="http://www.w3.org/2000/svg">
  <title>Cadurix</title>
  <desc>Logo Cadurix — gestion du matériel médical</desc>

  <?php if ($dark): ?>
  <!-- Fond sombre -->
  <rect width="<?= $vw ?>" height="<?= $vh ?>" rx="90" fill="#111820"/>
  <?php endif; ?>

  <?php if ($variante === 'A' || $variante === 'C'): ?>
  <!-- =========================================
       VARIANTE A / C — Bouclier + Croix médicale
       ========================================= -->

  <!-- Fond badge -->
  <rect width="<?= $vw ?>" height="<?= $vh ?>" rx="90" fill="<?= $dark ? 'none' : $c1 ?>"/>

  <!-- Bouclier -->
  <path d="M256 80 L390 138 L390 278
           C390 360 256 430 256 430
           C256 430 122 360 122 278 L122 138 Z"
        fill="<?= $c2 ?>"/>

  <!-- Reflet haut -->
  <path d="M256 92 L378 148 L378 220
           C378 220 330 205 256 205
           C182 205 134 220 134 220 L134 148 Z"
        fill="white" opacity="0.07"/>

  <!-- Contour bouclier -->
  <path d="M256 80 L390 138 L390 278
           C390 360 256 430 256 430
           C256 430 122 360 122 278 L122 138 Z"
        fill="none" stroke="<?= $accent ?>" stroke-width="3" opacity="0.6"/>

  <!-- Croix médicale — horizontale -->
  <rect x="170" y="222" width="172" height="56" rx="14" fill="white"/>
  <!-- Croix médicale — verticale -->
  <rect x="228" y="164" width="56" height="172" rx="14" fill="white"/>

  <!-- Points décoratifs -->
  <circle cx="145" cy="138" r="5" fill="<?= $accent ?>" opacity="0.7"/>
  <circle cx="367" cy="138" r="5" fill="<?= $accent ?>" opacity="0.7"/>
  <circle cx="256" cy="110" r="6" fill="<?= $accent ?>" opacity="0.9"/>

  <?php elseif ($variante === 'B'): ?>
  <!-- =========================================
       VARIANTE B — Bouclier + Caducée
       ========================================= -->

  <!-- Fond badge -->
  <rect width="<?= $vw ?>" height="<?= $vh ?>" rx="90" fill="<?= $dark ? 'none' : $c1 ?>"/>

  <!-- Bouclier -->
  <path d="M256 80 L390 138 L390 278
           C390 360 256 430 256 430
           C256 430 122 360 122 278 L122 138 Z"
        fill="<?= $c2 ?>"/>

  <!-- Reflet haut -->
  <path d="M256 92 L378 148 L378 220
           C378 220 330 205 256 205
           C182 205 134 220 134 220 L134 148 Z"
        fill="white" opacity="0.07"/>

  <!-- Contour bouclier -->
  <path d="M256 80 L390 138 L390 278
           C390 360 256 430 256 430
           C256 430 122 360 122 278 L122 138 Z"
        fill="none" stroke="<?= $accent ?>" stroke-width="3" opacity="0.6"/>

  <!-- Caducée — bâton central -->
  <rect x="242" y="155" width="28" height="180" rx="8" fill="white"/>

  <!-- Serpent gauche haut -->
  <path d="M242 180 Q195 180 195 205 Q195 230 242 230"
        fill="none" stroke="white" stroke-width="16" stroke-linecap="round"/>

  <!-- Serpent droit bas -->
  <path d="M270 240 Q317 240 317 265 Q317 290 270 290"
        fill="none" stroke="white" stroke-width="16" stroke-linecap="round"/>

  <!-- Ailes stylisées -->
  <path d="M256 155 Q220 135 200 145 Q220 148 242 160"
        fill="white" opacity="0.85"/>
  <path d="M256 155 Q292 135 312 145 Q292 148 270 160"
        fill="white" opacity="0.85"/>

  <!-- Points décoratifs -->
  <circle cx="145" cy="138" r="5" fill="<?= $accent ?>" opacity="0.7"/>
  <circle cx="367" cy="138" r="5" fill="<?= $accent ?>" opacity="0.7"/>
  <circle cx="256" cy="110" r="6" fill="<?= $accent ?>" opacity="0.9"/>

  <?php endif; ?>

  <?php if ($avec_texte): ?>
  <!-- Texte CADURIX -->
  <text x="256" y="500"
        text-anchor="middle"
        font-family="system-ui, -apple-system, sans-serif"
        font-weight="700"
        font-size="72"
        letter-spacing="10"
        fill="<?= $dark ? 'white' : $c1 ?>">CADURIX</text>
  <text x="256" y="545"
        text-anchor="middle"
        font-family="system-ui, -apple-system, sans-serif"
        font-weight="400"
        font-size="28"
        letter-spacing="4"
        fill="<?= $dark ? '#8899AA' : $c2 ?>">MATÉRIEL MÉDICAL</text>
  <?php endif; ?>

</svg>

#!/usr/bin/env php
<?php
// Protection : super admin uniquement
session_start();
if (empty($_SESSION['super_admin'])) { http_response_code(403); die('Accès refusé'); }
/**
 * Cadurix — Génération des icônes PWA
 * Exécuter : php generate_icons.php
 * Place les icônes dans /assets/icons/
 */

$sizes = [72, 96, 128, 144, 152, 192, 384, 512];
$output_dir = __DIR__ . '/../assets/icons/';

if (!is_dir($output_dir)) {
    mkdir($output_dir, 0755, true);
}

foreach ($sizes as $size) {
    $img = imagecreatetruecolor($size, $size);

    // Fond violet dégradé
    $bg = imagecolorallocate($img, 8, 14, 26);
    imagefill($img, 0, 0, $bg);

    // Cercle de fond primary
    $radius = (int)($size * 0.45);
    $cx = $size / 2;
    $cy = $size / 2;

    // Dessiner un cercle
    $violet = imagecolorallocate($img, 124, 58, 237);
    imagefilledellipse($img, (int)$cx, (int)$cy, $radius * 2, $radius * 2, $violet);

    // Texte "M" au centre
    $text_color = imagecolorallocate($img, 255, 255, 255);
    $font_size = (int)($size * 0.35);
    $font_x = (int)($cx - $font_size * 0.3);
    $font_y = (int)($cy + $font_size * 0.35);
    imagestring($img, 5, $font_x, $font_y - (int)($size * 0.07), 'M', $text_color);

    // Arrondir les coins
    $out = $output_dir . "icon-{$size}.png";
    imagepng($img, $out);
    imagedestroy($img);
    echo "✓ Généré : icon-{$size}.png\n";
}

echo "\n✅ Icônes générées dans $output_dir\n";
echo "💡 Pour de meilleures icônes, remplacez par vos PNG personnalisés.\n";

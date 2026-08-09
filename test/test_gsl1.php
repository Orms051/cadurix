<?php
require_once 'config/db.php';

$code = '010340093018843917261031102324';

// Parser position fixe
$gtin = null; $lot = null; $date_exp = null;
$c = preg_replace('/^\][a-zA-Z][0-9]/', '', $code);
$c = preg_replace('/[\x1c\x1d\x1e\x04\x00-\x08\x0b\x0c\x0e-\x1f]/', '|', $c);

foreach (explode('|', $c) as $seg) {
    $seg = preg_replace('/[^0-9a-zA-Z\-]/', '', $seg);
    $i = 0;
    while ($i < strlen($seg)) {
        $ai = substr($seg, $i, 2);
        if ($ai === '01' && strlen($seg) >= $i+16) { $gtin = substr($seg, $i+2, 14); $i += 16; }
        elseif ($ai === '17' && strlen($seg) >= $i+8) { $d = substr($seg, $i+2, 6); $date_exp = '20'.substr($d,0,2).'-'.substr($d,2,2).'-'.substr($d,4,2); $i += 8; }
        elseif ($ai === '10' && $i+2 < strlen($seg)) { $lot = trim(substr($seg, $i+2, 20)); break; }
        elseif ($ai === '21') { $i += 22; } else { $i++; }
    }
    if ($gtin) break;
}

$cip13 = $gtin;
if ($cip13 && strlen($cip13) === 14) $cip13 = substr($cip13, 1);

echo "GTIN: $gtin<br>";
echo "CIP13: $cip13<br>";
echo "Date: $date_exp<br>";
echo "Lot: $lot<br><br>";

// Chercher dans medicaments_ref
$stmt = $pdo->prepare("SELECT id, nom, gtin FROM medicaments_ref WHERE gtin=? LIMIT 1");
$stmt->execute([$cip13]);
$r = $stmt->fetch(PDO::FETCH_ASSOC);
echo $r ? "TROUVÉ: ".$r['nom'] : "NON TROUVÉ pour CIP13=$cip13";
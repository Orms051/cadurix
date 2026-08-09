<?php
require_once 'config/db.php';

$code = '62000000083614';
$codes = array_unique([$code, $code]);

foreach ($codes as $c) {
    $stmt = $pdo->prepare("SELECT * FROM medicaments_ref WHERE gtin = ? AND actif=1 LIMIT 1");
    $stmt->execute([$c]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Code $c : ".($r ? "TROUVÉ : ".$r['nom'] : "NON TROUVÉ")."<br>";
}

// Simuler ce que fait traiterCode JS
echo "<br>Test API scan_article :<br>";
$stmt2 = $pdo->prepare("SELECT p.id, p.nom FROM produits p WHERE p.ean=? OR p.gtin=? AND p.site_id=1 LIMIT 1");
$stmt2->execute([$code, $code]);
$p = $stmt2->fetch(PDO::FETCH_ASSOC);
echo $p ? "Produit trouvé : ".$p['nom'] : "Produit non trouvé → lookup_bdpm devrait être appelé";

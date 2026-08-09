<?php
// © Cadurix v5.4 — Briques partagées du scanner
// À placer dans : php/barcode_lookup.php
// Inclus par terrain.php ET scan.php. Ne produit AUCUNE sortie : que des fonctions.
//
// Objectif : une seule source de vérité pour
//   1) le parsing GS1 (DataMatrix / EAN)
//   2) la recherche d'un code-barres (medicaments_ref puis stock produits)
// Une correction ici = appliquée aux deux pages.

// ────────────────────────────────────────────────────────────
//  Parsing GS1 — version unifiée (la plus robuste des deux pages)
//  Gère : préfixe de symbologie ]Xn (ex ]d2), contrôles de tête,
//         FNC1 internes (\x1D/\x00/\x04), GTIN (AI 01), date (AI 17),
//         lot longueur variable (AI 10).
//  @return ['gtin'=>?string, 'lot'=>?string, 'date_exp'=>?string(Y-m-d)]
// ────────────────────────────────────────────────────────────
if (!function_exists('parse_gs1')) {
function parse_gs1(string $raw): array {
    $gtin = null; $lot = null; $date_exp = null;

    // 1. Préfixe d'identifiant de symbologie AIM en tête (]d2, ]C1, ]Q3…)
    $s = preg_replace('/^\][A-Za-z][0-9]/', '', $raw);
    // 2. Caractères de contrôle de tête (FNC1 \x1D, \x00, \x04…)
    $s = preg_replace('/^[\x00-\x1F\x7F]+/', '', $s);
    // 3. Séparateurs FNC1 internes → "|" (fiabilise la fin de lot)
    $s = str_replace(["\x1D", "\x00", "\x04"], '|', $s);

    // AI 01 — GTIN (14 chiffres)
    if (preg_match('/01(\d{14})/', $s, $m)) {
        $gtin = $m[1];
    }
    // AI 17 — Date péremption AAMMJJ (JJ=00 → dernier jour du mois)
    if (preg_match('/17(\d{6})/', $s, $m)) {
        $d = $m[1]; $yy = substr($d,0,2); $mm = substr($d,2,2); $dd = substr($d,4,2);
        if ($dd === '00') $dd = date('d', mktime(0,0,0,(int)$mm+1,0,2000+(int)$yy));
        $date_exp = '20'.$yy.'-'.$mm.'-'.$dd;
    }
    // AI 10 — Lot (longueur variable, s'arrête au séparateur "|" ou en fin)
    if (preg_match('/(?:\d{6}|\d{14}|\d{13})10([A-Za-z0-9\-\/]+?)(?=\||$)/', $s, $m)) {
        $lot = trim($m[1]);
    }

    return compact('gtin', 'lot', 'date_exp');
}
}

// ────────────────────────────────────────────────────────────
//  Codes candidats dérivés d'un code brut (CIP13, EAN, etc.)
// ────────────────────────────────────────────────────────────
if (!function_exists('barcode_candidates')) {
function barcode_candidates(array $parsed, string $raw): array {
    $gtin = $parsed['gtin'] ?? null;
    // CIP13 = GTIN 14 chiffres sans le 1er digit (toujours 0 pour les médicaments FR)
    $cip13 = ($gtin && strlen($gtin) === 14) ? substr($gtin, 1) : $gtin;
    // EAN brut de secours (EAN13 pur sans GS1)
    $ean_brut = preg_replace('/[^0-9]/', '', $raw);
    if (strlen($ean_brut) > 14) $ean_brut = substr($ean_brut, -13);
    if (!$cip13 && strlen($ean_brut) >= 8) $cip13 = $ean_brut;
    $lookup_code = $cip13 ?: (strlen($ean_brut) >= 8 ? $ean_brut : null);
    return compact('gtin', 'cip13', 'ean_brut', 'lookup_code');
}
}

// ────────────────────────────────────────────────────────────
//  Recherche dans medicaments_ref (référentiel) → nom ou null
// ────────────────────────────────────────────────────────────
if (!function_exists('lookup_medicament_ref')) {
function lookup_medicament_ref(PDO $pdo, array $cand): ?string {
    foreach (array_unique(array_filter([$cand['cip13'], $cand['ean_brut'], $cand['gtin']])) as $ct) {
        $s = $pdo->prepare("SELECT nom FROM medicaments_ref WHERE gtin=? AND actif=1 LIMIT 1");
        $s->execute([$ct]);
        if ($r = $s->fetch(PDO::FETCH_ASSOC)) return $r['nom'];
    }
    return null;
}
}

// ────────────────────────────────────────────────────────────
//  Recherche dans le stock (produits) → ligne produit ou null
//  Jointure via emplacements pour filtrer par site
//  (produits n'a PAS de colonne site_id → filtrage par e.site_id).
// ────────────────────────────────────────────────────────────
if (!function_exists('lookup_stock')) {
function lookup_stock(PDO $pdo, array $cand, int $site_id): ?array {
    $sql = "SELECT p.*, e.nom AS emplacement, e.id AS emplacement_id
            FROM produits p
            LEFT JOIN emplacements e ON e.id = p.emplacement_id
            WHERE %s AND p.statut='actif' AND (e.site_id = ? OR p.emplacement_id IS NULL)
            LIMIT 1";

    if (!empty($cand['gtin'])) {
        $st = $pdo->prepare(sprintf($sql, 'p.gtin = ?'));
        $st->execute([$cand['gtin'], $site_id]);
        if ($r = $st->fetch(PDO::FETCH_ASSOC)) return $r;
    }
    if (!empty($cand['lookup_code'])) {
        $st = $pdo->prepare(sprintf($sql, '(p.ean = ? OR p.gtin = ?)'));
        $st->execute([$cand['lookup_code'], $cand['lookup_code'], $site_id]);
        if ($r = $st->fetch(PDO::FETCH_ASSOC)) return $r;
    }
    return null;
}
}

// ────────────────────────────────────────────────────────────
//  TOUT-EN-UN : parse + recherche → résultat normalisé
//  Chaque page formate ensuite sa propre réponse JSON à partir de ça.
//  @return [
//    'found'=>bool, 'source'=>'ref'|'stock'|null, 'nom'=>?string,
//    'produit'=>?array, 'gtin'=>?string, 'lot'=>?string,
//    'date_exp'=>?string, 'ean'=>?string, 'raw'=>string
//  ]
// ────────────────────────────────────────────────────────────
if (!function_exists('barcode_lookup')) {
function barcode_lookup(PDO $pdo, string $raw, int $site_id): array {
    $parsed = parse_gs1($raw);
    $cand   = barcode_candidates($parsed, $raw);

    $base = [
        'gtin'     => $parsed['gtin'],
        'lot'      => $parsed['lot'],
        'date_exp' => $parsed['date_exp'],
        'ean'      => $cand['ean_brut'],
        'raw'      => $raw,
    ];

    $nom = lookup_medicament_ref($pdo, $cand);
    if ($nom !== null) {
        return $base + ['found'=>true, 'source'=>'ref', 'nom'=>$nom, 'produit'=>null];
    }

    $produit = lookup_stock($pdo, $cand, $site_id);
    return $base + [
        'found'   => (bool)$produit,
        'source'  => $produit ? 'stock' : null,
        'nom'     => $produit['nom'] ?? null,
        'produit' => $produit,
    ];
}
}

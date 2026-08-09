<?php
// © Cadurix v5.4 — Test du parser GS1 partagé
// À placer dans : php/test_gs1.php
// Lancer depuis la racine du projet :  php php/test_gs1.php
// (test isolé : ne touche pas la base, vérifie juste parse_gs1)

require __DIR__ . '/barcode_lookup.php';

$cases = [
    // [code brut scanné, attendu]
    ['01034531200000111726123110AB12CD',
        ['gtin'=>'03453120000011','date_exp'=>'2026-12-31','lot'=>'AB12CD']],

    // Avec préfixe symbologie ]d2 et séparateur FNC1 final
    ["]d201034531200000111726123110AB12CD\x1D",
        ['gtin'=>'03453120000011','date_exp'=>'2026-12-31','lot'=>'AB12CD']],

    // Jour de péremption = 00 → dernier jour du mois (févr. 2026 = 28)
    ['010345312000001117260200',
        ['gtin'=>'03453120000011','date_exp'=>'2026-02-28','lot'=>null]],
];

$fail = 0;
foreach ($cases as $i => [$raw, $exp]) {
    $got = parse_gs1($raw);
    $ok  = ($got['gtin'] === $exp['gtin'] && $got['date_exp'] === $exp['date_exp'] && $got['lot'] === $exp['lot']);
    printf("[%s] cas %d  gtin=%s  date=%s  lot=%s\n",
        $ok ? 'OK  ' : 'FAIL', $i + 1,
        var_export($got['gtin'], true), var_export($got['date_exp'], true), var_export($got['lot'], true));
    if (!$ok) {
        $fail++;
        printf("        attendu : gtin=%s date=%s lot=%s\n",
            var_export($exp['gtin'], true), var_export($exp['date_exp'], true), var_export($exp['lot'], true));
    }
}

echo $fail
    ? "\n$fail test(s) en échec.\n"
    : "\nTous les tests passent. Le parser partagé est OK.\n";

echo "\n--- Teste tes propres codes : ajoute-les dans \$cases, ou en ligne de commande ---\n";
if (!empty($argv[1])) {
    echo "Code fourni : " . $argv[1] . "\n";
    var_export(parse_gs1($argv[1]));
    echo "\n";
}

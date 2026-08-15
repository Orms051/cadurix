<?php
// © Cadurix v5.4 — Dotation : comparaison composition attendue (modèle) vs réel.
// Un emplacement (SAC ou PUI) peut être rattaché à un modèle de dotation
// (emplacements.dotation_modele_id). Le moteur compare les lignes attendues au
// stock réel de l'emplacement + ses sous-emplacements (pochettes).

/** Normalise un libellé pour le rapprochement (minuscules, sans accents, espaces compactés). */
function dotation_norm(string $s): string {
    $s = mb_strtolower(trim($s));
    $s = strtr($s, ['à'=>'a','â'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','î'=>'i','ï'=>'i','ô'=>'o','ö'=>'o','û'=>'u','ù'=>'u','ü'=>'u','ç'=>'c']);
    return preg_replace('/\s+/', ' ', $s);
}

/** Clé de rapprochement d'un produit : nom + dosage combinés (gère le dosage dans le nom OU en colonne). */
function dotation_cle(?string $nom, ?string $dosage): string {
    return dotation_norm(trim((string)$nom . ' ' . (string)$dosage));
}

/** IDs de l'emplacement + tous ses sous-emplacements (pochettes...). */
function dotation_subtree_ids(PDO $pdo, int $emp_id, int $site_id): array {
    $ids = [$emp_id];
    $frontier = $ids;
    $guard = 0;
    while ($frontier && $guard++ < 12) {
        $in = implode(',', array_map('intval', $frontier));
        $q = $pdo->prepare("SELECT id FROM emplacements WHERE site_id = ? AND parent_id IN ($in)");
        $q->execute([$site_id]);
        $children = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
        $frontier = array_values(array_diff($children, $ids));
        $ids = array_merge($ids, $frontier);
    }
    return $ids;
}

/**
 * Compare la dotation attendue d'un emplacement à son contenu réel.
 * Renvoie null si l'emplacement n'a pas de modèle rattaché.
 * Sinon : ['modele'=>..., 'lignes'=>[...], 'surplus'=>[...], 'stats'=>[...]].
 * Statut par ligne : 'manquant' | 'insuffisant' | 'conforme'.
 */
function dotation_ecarts(PDO $pdo, int $emp_id, int $site_id): ?array {
    $st = $pdo->prepare("SELECT dotation_modele_id FROM emplacements WHERE id = ? AND site_id = ?");
    $st->execute([$emp_id, $site_id]);
    $mid = (int)($st->fetchColumn() ?: 0);
    if (!$mid) return null;

    $mst = $pdo->prepare("SELECT * FROM dotation_modeles WHERE id = ? AND site_id = ? AND actif = 1");
    $mst->execute([$mid, $site_id]);
    $modele = $mst->fetch(PDO::FETCH_ASSOC);
    if (!$modele) return null;

    $lst = $pdo->prepare("SELECT * FROM dotation_lignes WHERE modele_id = ? ORDER BY ordre, nom");
    $lst->execute([$mid]);
    $lignes = $lst->fetchAll(PDO::FETCH_ASSOC);

    // Stock réel de l'emplacement + sous-emplacements (agrégé par produit)
    $ids = dotation_subtree_ids($pdo, $emp_id, $site_id);
    $inList = implode(',', array_map('intval', $ids));
    $reel = $pdo->query("SELECT nom, dosage, SUM(quantite) AS qte
                         FROM produits
                         WHERE statut = 'actif' AND emplacement_id IN ($inList)
                         GROUP BY nom, dosage")->fetchAll(PDO::FETCH_ASSOC);

    // Table de rapprochement : clé -> qté réelle cumulée, et suivi des clés consommées
    $reelMap = [];
    foreach ($reel as $r) {
        $k = dotation_cle($r['nom'], $r['dosage']);
        $reelMap[$k] = ['qte' => (float)$r['qte'] + ($reelMap[$k]['qte'] ?? 0), 'nom' => $r['nom'], 'dosage' => $r['dosage']];
    }

    $matched = [];
    $nbConf = 0; $nbInsuf = 0; $nbManq = 0;
    foreach ($lignes as &$L) {
        $k = dotation_cle($L['nom'], $L['dosage']);
        $reelQte = $reelMap[$k]['qte'] ?? 0;
        if (isset($reelMap[$k])) $matched[$k] = true;
        $att = (float)$L['quantite_attendue'];
        $L['qte_reel'] = $reelQte;
        if ($reelQte <= 0)            { $L['statut'] = 'manquant';    $nbManq++; }
        elseif ($reelQte < $att)      { $L['statut'] = 'insuffisant'; $nbInsuf++; }
        else                          { $L['statut'] = 'conforme';    $nbConf++; }
    }
    unset($L);

    // Surplus : produits présents mais hors dotation
    $surplus = [];
    foreach ($reelMap as $k => $r) {
        if (!isset($matched[$k])) $surplus[] = ['nom' => $r['nom'], 'dosage' => $r['dosage'], 'qte' => $r['qte']];
    }

    $nbLignes = count($lignes);
    $completude = $nbLignes > 0 ? round($nbConf / $nbLignes * 100) : 100;

    return [
        'modele'  => $modele,
        'lignes'  => $lignes,
        'surplus' => $surplus,
        'stats'   => [
            'total'       => $nbLignes,
            'conforme'    => $nbConf,
            'insuffisant' => $nbInsuf,
            'manquant'    => $nbManq,
            'surplus'     => count($surplus),
            'completude'  => $completude,
        ],
    ];
}

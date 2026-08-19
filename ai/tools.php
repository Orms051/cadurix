<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.

/**
 * Cadurix AI — Outils (function calling)
 * Lecture live de la base + actions d'écriture sous confirmation.
 */

/** Définitions des outils de LECTURE exposées au modèle. */
function ai_tools_defs(): array {
    return [
        [
            'name'        => 'lister_emplacements',
            'description' => "Liste la hiérarchie des emplacements du site (sacs, pochettes, sous-emplacements) avec leur ID exact. À utiliser pour connaître l'ID réel d'un emplacement avant un rempotage, ou pour situer un produit. Ne jamais deviner un ID.",
            'input_schema' => ['type' => 'object', 'properties' => [
                'filtre' => ['type' => 'string', 'description' => "Optionnel : filtrer par nom (ex. 'sac isp 1', 'ampoulier')."],
            ], 'required' => []],
        ],
        [
            'name'        => 'chercher_stock',
            'description' => "Cherche des produits en stock par nom et/ou par emplacement. Si un emplacement_id est donné, la recherche inclut cet emplacement ET tous ses sous-emplacements (pochettes...). Renvoie quantité, unité, lot, péremption et emplacement.",
            'input_schema' => ['type' => 'object', 'properties' => [
                'nom'            => ['type' => 'string', 'description' => "Nom (ou partie) du produit recherché."],
                'emplacement_id' => ['type' => 'integer', 'description' => "Restreindre à un emplacement précis (et ses sous-emplacements)."],
            ], 'required' => []],
        ],
        [
            'name'        => 'produits_peremption',
            'description' => "Liste les produits périmés ou qui périment bientôt sur le site (les plus proches d'abord). 'jours' définit la fenêtre (défaut 30).",
            'input_schema' => ['type' => 'object', 'properties' => [
                'jours' => ['type' => 'integer', 'description' => "Fenêtre en jours (défaut 30). Un produit déjà périmé a un nombre de jours négatif."],
            ], 'required' => []],
        ],
        [
            'name'        => 'etat_materiel',
            'description' => "État du matériel biomédical du site : nombre hors service et nombre de révisions urgentes (échéance ≤ 15 jours).",
            'input_schema' => ['type' => 'object', 'properties' => new stdClass(), 'required' => []],
        ],
        [
            'name'        => 'rappels_actifs',
            'description' => "Liste les rappels ANSM actifs (lots rappelés) sur le site.",
            'input_schema' => ['type' => 'object', 'properties' => new stdClass(), 'required' => []],
        ],
        [
            'name'        => 'dernieres_fiches',
            'description' => "Liste les dernières fiches de vérification remplies (modèle, statut conforme/anomalie, auteur, date). 'limit' défaut 5.",
            'input_schema' => ['type' => 'object', 'properties' => [
                'limit' => ['type' => 'integer', 'description' => "Nombre de fiches (défaut 5, max 20)."],
            ], 'required' => []],
        ],
        [
            'name'        => 'chercher_medicament',
            'description' => "Recherche un médicament dans la base de référence Cadurix (nom commercial, DCI, dosages).",
            'input_schema' => ['type' => 'object', 'properties' => [
                'terme' => ['type' => 'string', 'description' => "Nom commercial ou DCI recherché."],
            ], 'required' => ['terme']],
        ],
        [
            'name'        => 'chercher_materiel',
            'description' => "Cherche un matériel/équipement biomédical du site par nom (ex. 'défibrillateur'). Renvoie id, nom, marque, modèle, statut. À utiliser avant un signalement biomédical pour obtenir le materiel_id.",
            'input_schema' => ['type' => 'object', 'properties' => [
                'nom' => ['type' => 'string', 'description' => "Nom (ou partie) du matériel."],
            ], 'required' => ['nom']],
        ],
        [
            'name'        => 'stock_bas',
            'description' => "Liste des médicaments/produits à RÉAPPROVISIONNER : ceux dont la quantité est ≤ à la dotation attendue (quantite_min). Renvoie nom, DCI, dosage, quantité, dotation, manque (dotation - quantité), unité et emplacement. À utiliser dès qu'on demande « qu'est-ce qu'il faut réappro / recommander / racheter / ce qui manque ».",
            'input_schema' => ['type' => 'object', 'properties' => [
                'emplacement_id' => ['type' => 'integer', 'description' => "Optionnel : restreindre à un emplacement (et ses sous-emplacements)."],
            ], 'required' => []],
        ],
        [
            'name'        => 'fiches_anomalies',
            'description' => "Liste les dernières fiches de vérification en ANOMALIE (non conformes) du site : modèle, mode, auteur, date. À utiliser pour « quelles anomalies / fiches non conformes / problèmes signalés ».",
            'input_schema' => ['type' => 'object', 'properties' => [
                'limit' => ['type' => 'integer', 'description' => "Nombre de fiches (défaut 15, max 40)."],
            ], 'required' => []],
        ],
        [
            'name'        => 'revisions_biomedical',
            'description' => "Liste les révisions / maintenances biomédicales à venir ou en retard (échéance dans 'jours', défaut 60) : matériel, type de révision, échéance, organisme, jours restants (négatif = en retard).",
            'input_schema' => ['type' => 'object', 'properties' => [
                'jours' => ['type' => 'integer', 'description' => "Fenêtre en jours (défaut 60)."],
            ], 'required' => []],
        ],
        [
            'name'        => 'sse_etat',
            'description' => "État du module SSE/NRBC du site : équipements dont le contrôle ou la péremption approche, et consommables sous le minimum ou périmés. 'jours' définit la fenêtre (défaut 30).",
            'input_schema' => ['type' => 'object', 'properties' => [
                'jours' => ['type' => 'integer', 'description' => "Fenêtre en jours (défaut 30)."],
            ], 'required' => []],
        ],
        [
            'name'        => 'chercher_sse',
            'description' => "Cherche dans le module SSE/NRBC par nom : consommables (id, quantité, dotation, péremption) et équipements (id, statut, prochain contrôle). À utiliser AVANT d'ajuster un consommable SSE ou d'enregistrer un contrôle d'équipement, pour obtenir l'id exact.",
            'input_schema' => ['type' => 'object', 'properties' => [
                'nom' => ['type' => 'string', 'description' => "Nom (ou partie) recherché. Vide = tout lister."],
            ], 'required' => []],
        ],
        [
            'name'        => 'dotation_sacs',
            'description' => "État de complétude de TOUS les sacs/PUI ayant une dotation définie (composition attendue vs réel) : pour chacun, le % de complétude, le nombre de produits manquants et insuffisants. Trié du moins complet au plus complet. À utiliser pour « quels sacs sont incomplets / prêts à partir ? ».",
            'input_schema' => ['type' => 'object', 'properties' => new stdClass(), 'required' => []],
        ],
        [
            'name'        => 'dotation_manques',
            'description' => "Détail des écarts de dotation d'UN sac/emplacement précis (par nom) : la liste de ce qui MANQUE (attendu mais absent) et de ce qui est INSUFFISANT (présent sous le seuil), avec quantité attendue vs réelle. À utiliser pour « qu'est-ce qui manque dans le SAC X ? ».",
            'input_schema' => ['type' => 'object', 'properties' => [
                'emplacement' => ['type' => 'string', 'description' => "Nom (ou partie) du sac/emplacement."],
            ], 'required' => ['emplacement']],
        ],
    ];
}

/** Vrai si l'outil est en LECTURE seule (exécutable sans confirmation). */
function ai_tool_is_read(string $name): bool {
    return in_array($name, [
        'lister_emplacements', 'chercher_stock', 'produits_peremption',
        'etat_materiel', 'rappels_actifs', 'dernieres_fiches', 'chercher_medicament',
        'chercher_materiel', 'stock_bas', 'fiches_anomalies', 'revisions_biomedical', 'sse_etat', 'chercher_sse',
        'dotation_sacs', 'dotation_manques',
    ], true);
}

/**
 * Étend un terme de recherche avec les correspondances marque <-> molécule
 * issues de la base de référence BDPM (medicaments_ref). Ex. « midazolam »
 * ramène aussi « Buccolam » ; « Rivotril » ramène aussi « clonazépam ».
 */
function ai_synonymes_medicament(PDO $pdo, string $terme): array {
    $t = strtolower(trim($terme));
    $syn = [$t];
    if ($t === '') return $syn;

    // 1) Correspondances CRITIQUES curées (urgence / secours) — nom commercial <-> molécule.
    //    Indispensable car la BDPM a des DCI manquantes (ex. Buccolam sans DCI).
    $groupes = [
        ['buccolam', 'hypnovel', 'midazolam'],
        ['narcan', 'nalscue', 'prenoxad', 'naloxone'],
        ['rivotril', 'clonazepam', 'clonazépam'],
        ['exacyl', 'acide tranexamique', 'tranexamique'],
        ['aspegic', 'aspégic', 'kardegic', 'kardégic', 'acide acetylsalicylique', 'acide acétylsalicylique', 'aspirine'],
        ['cordarone', 'amiodarone'],
        ['adrenaline', 'adrénaline', 'epinephrine', 'épinéphrine', 'anahelp', 'anapen', 'jext', 'epipen'],
        ['solumedrol', 'solumédrol', 'solu-medrol', 'methylprednisolone', 'méthylprednisolone'],
        ['bricanyl', 'terbutaline'],
        ['profenid', 'profénid', 'ketoprofene', 'kétoprofène'],
        ['ventoline', 'salbutamol'],
        ['lasilix', 'furosemide', 'furosémide'],
        ['valium', 'diazepam', 'diazépam'],
        ['hypnomidate', 'etomidate', 'étomidate'],
        ['celocurine', 'célocurine', 'suxamethonium', 'suxaméthonium'],
        ['perfalgan', 'doliprane', 'dafalgan', 'paracetamol', 'paracétamol'],
        ['glucagen', 'glucagon'],
    ];
    foreach ($groupes as $g) {
        foreach ($g as $s) {
            if (strlen($s) >= 4 && (strpos($t, $s) !== false || strpos($s, $t) !== false)) {
                $syn = array_merge($syn, $g);
                break;
            }
        }
    }

    // 2) Correspondances issues de la base de référence BDPM (quand le DCI y est renseigné).
    try {
        $q = $pdo->prepare("SELECT DISTINCT nom, dci FROM medicaments_ref
                            WHERE actif = 1 AND (LOWER(nom) LIKE ? OR LOWER(dci) LIKE ?) LIMIT 40");
        $q->execute(['%' . $t . '%', '%' . $t . '%']);
        foreach ($q as $r) {
            if (!empty($r['nom'])) $syn[] = strtolower($r['nom']);
            if (!empty($r['dci'])) $syn[] = strtolower($r['dci']);
        }
    } catch (Throwable $e) {}

    $syn = array_values(array_unique(array_filter(array_map('trim', $syn))));
    return array_slice($syn, 0, 40);
}

/** Exécute un outil de lecture et renvoie un tableau (sérialisé en JSON pour le modèle). */
function ai_tool_run(PDO $pdo, string $name, array $in, int $site_id, int $user_id): array {
    try {
        switch ($name) {

            case 'lister_emplacements': {
                $sql = "SELECT e.id, e.nom, e.parent_id, p.nom AS parent_nom
                        FROM emplacements e
                        LEFT JOIN emplacements p ON p.id = e.parent_id
                        WHERE e.site_id = ? AND e.actif = 1";
                $args = [$site_id];
                if (!empty($in['filtre'])) {
                    $sql .= " AND (e.nom LIKE ? OR p.nom LIKE ?)";
                    $args[] = '%' . $in['filtre'] . '%';
                    $args[] = '%' . $in['filtre'] . '%';
                }
                $sql .= " ORDER BY COALESCE(p.nom, e.nom), e.nom LIMIT 200";
                $st = $pdo->prepare($sql);
                $st->execute($args);
                return ['emplacements' => $st->fetchAll(PDO::FETCH_ASSOC)];
            }

            case 'chercher_stock': {
                $sql = "SELECT pr.id, pr.nom, pr.dci, pr.dosage, pr.quantite, pr.unite, pr.numero_lot,
                               pr.date_peremption, e.id AS emplacement_id, e.nom AS emplacement
                        FROM produits pr
                        JOIN emplacements e ON e.id = pr.emplacement_id
                        WHERE e.site_id = ? AND pr.statut = 'actif'";
                $args = [$site_id];
                if (!empty($in['nom'])) {
                    // Étendre marque<->molécule via la base BDPM : « midazolam » retrouve « Buccolam »
                    $syn = ai_synonymes_medicament($pdo, $in['nom']);
                    $ors = [];
                    foreach ($syn as $s) {
                        $ors[] = "LOWER(pr.nom) LIKE ?"; $args[] = '%' . $s . '%';
                        $ors[] = "LOWER(pr.dci) LIKE ?"; $args[] = '%' . $s . '%';
                    }
                    $sql .= " AND (" . implode(' OR ', $ors) . ")";
                }
                if (!empty($in['emplacement_id'])) {
                    // Inclure l'emplacement ciblé ET tous ses sous-emplacements
                    $ids = [(int)$in['emplacement_id']];
                    $frontier = $ids;
                    $guard = 0;
                    while ($frontier && $guard++ < 10) {
                        $inList = implode(',', array_map('intval', $frontier));
                        $q = $pdo->prepare("SELECT id FROM emplacements WHERE site_id = ? AND parent_id IN ($inList)");
                        $q->execute([$site_id]);
                        $children = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
                        $frontier = array_values(array_diff($children, $ids));
                        $ids = array_merge($ids, $frontier);
                    }
                    $sql .= " AND pr.emplacement_id IN (" . implode(',', array_map('intval', $ids)) . ")";
                }
                $sql .= " ORDER BY pr.nom LIMIT 80";
                $st = $pdo->prepare($sql);
                $st->execute($args);
                return ['produits' => $st->fetchAll(PDO::FETCH_ASSOC)];
            }

            case 'produits_peremption': {
                $j = (int)($in['jours'] ?? 30);
                if ($j < 0) $j = 0;
                if ($j > 3650) $j = 3650;
                $sql = "SELECT pr.nom, pr.numero_lot, pr.date_peremption, pr.quantite,
                               e.nom AS emplacement, DATEDIFF(pr.date_peremption, CURDATE()) AS jours
                        FROM produits pr
                        JOIN emplacements e ON e.id = pr.emplacement_id
                        WHERE e.site_id = ? AND pr.statut = 'actif'
                          AND pr.date_peremption <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
                        ORDER BY pr.date_peremption ASC LIMIT 60";
                $st = $pdo->prepare($sql);
                $st->execute([$site_id, $j]);
                return ['fenetre_jours' => $j, 'produits' => $st->fetchAll(PDO::FETCH_ASSOC)];
            }

            case 'etat_materiel': {
                $hs = $pdo->prepare("SELECT COUNT(*) FROM materiels WHERE site_id = ? AND statut = 'hors_service'");
                $hs->execute([$site_id]);
                $rev = $pdo->prepare("SELECT COUNT(*) FROM materiel_revisions r
                                      JOIN materiels m ON m.id = r.materiel_id
                                      WHERE m.site_id = ? AND r.prochaine_revision <= DATE_ADD(CURDATE(), INTERVAL 15 DAY) AND r.actif = 1");
                $rev->execute([$site_id]);
                return [
                    'hors_service'           => (int)$hs->fetchColumn(),
                    'revisions_urgentes_15j' => (int)$rev->fetchColumn(),
                ];
            }

            case 'rappels_actifs': {
                $st = $pdo->prepare("SELECT numero_lot, motif, date_rappel, source_table
                                     FROM lots_rappels WHERE site_id = ? AND actif = 1
                                     ORDER BY date_rappel DESC LIMIT 50");
                $st->execute([$site_id]);
                return ['rappels' => $st->fetchAll(PDO::FETCH_ASSOC)];
            }

            case 'dernieres_fiches': {
                $lim = (int)($in['limit'] ?? 5);
                if ($lim < 1) $lim = 1;
                if ($lim > 20) $lim = 20;
                $st = $pdo->prepare("SELECT mf.nom AS modele, fr.statut, fr.mode, fr.cree_le,
                                            u.prenom, u.nom
                                     FROM fiches_remplies fr
                                     JOIN modeles_fiches mf ON mf.id = fr.modele_id
                                     LEFT JOIN utilisateurs u ON u.id = fr.user_id
                                     WHERE fr.site_id = ?
                                     ORDER BY fr.cree_le DESC LIMIT $lim");
                $st->execute([$site_id]);
                return ['fiches' => $st->fetchAll(PDO::FETCH_ASSOC)];
            }

            case 'chercher_medicament': {
                $t = trim($in['terme'] ?? '');
                if ($t === '') return ['erreur' => 'terme requis'];
                // Base de référence BDPM (medicaments_ref, ~21 000 lignes) : nom commercial <-> DCI
                $st = $pdo->prepare("SELECT nom, dci, forme, dosage, gtin
                                     FROM medicaments_ref
                                     WHERE actif = 1 AND (nom LIKE ? OR dci LIKE ?)
                                     ORDER BY (nom LIKE ?) DESC, nom
                                     LIMIT 25");
                $st->execute(['%' . $t . '%', '%' . $t . '%', $t . '%']);
                return ['resultats' => $st->fetchAll(PDO::FETCH_ASSOC)];
            }

            case 'chercher_materiel': {
                $t = trim($in['nom'] ?? '');
                if ($t === '') return ['erreur' => 'nom requis'];
                $st = $pdo->prepare("SELECT id, nom, marque, modele, statut
                                     FROM materiels WHERE site_id = ? AND nom LIKE ?
                                     ORDER BY nom LIMIT 25");
                $st->execute([$site_id, '%' . $t . '%']);
                return ['materiels' => $st->fetchAll(PDO::FETCH_ASSOC)];
            }

            case 'stock_bas': {
                $sql = "SELECT pr.id, pr.nom, pr.dci, pr.dosage, pr.quantite,
                               pr.quantite_min AS dotation, pr.unite,
                               (pr.quantite_min - pr.quantite) AS manque,
                               e.nom AS emplacement
                        FROM produits pr
                        JOIN emplacements e ON e.id = pr.emplacement_id
                        WHERE e.site_id = ? AND pr.statut = 'actif'
                          AND pr.quantite_min > 0 AND pr.quantite <= pr.quantite_min";
                $args = [$site_id];
                if (!empty($in['emplacement_id'])) {
                    // Inclure l'emplacement ciblé ET tous ses sous-emplacements
                    $ids = [(int)$in['emplacement_id']];
                    $frontier = $ids;
                    $guard = 0;
                    while ($frontier && $guard++ < 10) {
                        $inList = implode(',', array_map('intval', $frontier));
                        $q = $pdo->prepare("SELECT id FROM emplacements WHERE site_id = ? AND parent_id IN ($inList)");
                        $q->execute([$site_id]);
                        $children = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
                        $frontier = array_values(array_diff($children, $ids));
                        $ids = array_merge($ids, $frontier);
                    }
                    $sql .= " AND pr.emplacement_id IN (" . implode(',', array_map('intval', $ids)) . ")";
                }
                $sql .= " ORDER BY manque DESC, pr.nom LIMIT 80";
                $st = $pdo->prepare($sql);
                $st->execute($args);
                return ['a_reapprovisionner' => $st->fetchAll(PDO::FETCH_ASSOC)];
            }

            case 'fiches_anomalies': {
                $lim = (int)($in['limit'] ?? 15);
                if ($lim < 1) $lim = 1;
                if ($lim > 40) $lim = 40;
                $st = $pdo->prepare("SELECT mf.nom AS modele, fr.statut, fr.mode, fr.cree_le, u.prenom, u.nom
                                     FROM fiches_remplies fr
                                     JOIN modeles_fiches mf ON mf.id = fr.modele_id
                                     LEFT JOIN utilisateurs u ON u.id = fr.user_id
                                     WHERE fr.site_id = ? AND fr.statut = 'anomalie'
                                     ORDER BY fr.cree_le DESC LIMIT $lim");
                $st->execute([$site_id]);
                return ['anomalies' => $st->fetchAll(PDO::FETCH_ASSOC)];
            }

            case 'revisions_biomedical': {
                $j = (int)($in['jours'] ?? 60);
                if ($j < 0) $j = 0;
                if ($j > 3650) $j = 3650;
                $st = $pdo->prepare("SELECT m.nom AS materiel, r.nom AS revision, r.type,
                                            r.prochaine_revision, r.organisme, r.obligatoire,
                                            DATEDIFF(r.prochaine_revision, CURDATE()) AS jours
                                     FROM materiel_revisions r
                                     JOIN materiels m ON m.id = r.materiel_id
                                     WHERE m.site_id = ? AND r.actif = 1
                                       AND r.prochaine_revision <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
                                     ORDER BY r.prochaine_revision ASC LIMIT 50");
                $st->execute([$site_id, $j]);
                return ['fenetre_jours' => $j, 'revisions' => $st->fetchAll(PDO::FETCH_ASSOC)];
            }

            case 'sse_etat': {
                $j = (int)($in['jours'] ?? 30);
                if ($j < 0) $j = 0;
                if ($j > 3650) $j = 3650;
                $eq = $pdo->prepare("SELECT e.nom, e.statut, e.date_peremption, e.date_prochain_ctrl,
                                            c.nom AS categorie,
                                            DATEDIFF(e.date_peremption, CURDATE())   AS jours_peremption,
                                            DATEDIFF(e.date_prochain_ctrl, CURDATE()) AS jours_controle
                                     FROM sse_equipements e
                                     LEFT JOIN sse_categories c ON c.id = e.categorie_id
                                     WHERE e.site_id = ? AND e.actif = 1
                                       AND ((e.date_peremption   IS NOT NULL AND e.date_peremption   <= DATE_ADD(CURDATE(), INTERVAL ? DAY))
                                         OR (e.date_prochain_ctrl IS NOT NULL AND e.date_prochain_ctrl <= DATE_ADD(CURDATE(), INTERVAL ? DAY)))
                                     ORDER BY LEAST(COALESCE(e.date_peremption,'9999-12-31'), COALESCE(e.date_prochain_ctrl,'9999-12-31')) ASC
                                     LIMIT 40");
                $eq->execute([$site_id, $j, $j]);
                $co = $pdo->prepare("SELECT nom, reference, numero_lot, quantite, quantite_min, unite, date_peremption,
                                            DATEDIFF(date_peremption, CURDATE()) AS jours_peremption
                                     FROM sse_consommables
                                     WHERE site_id = ?
                                       AND ((quantite_min > 0 AND quantite <= quantite_min)
                                         OR (date_peremption IS NOT NULL AND date_peremption <= DATE_ADD(CURDATE(), INTERVAL ? DAY)))
                                     ORDER BY date_peremption ASC LIMIT 40");
                $co->execute([$site_id, $j]);
                return [
                    'fenetre_jours'          => $j,
                    'equipements_a_verifier' => $eq->fetchAll(PDO::FETCH_ASSOC),
                    'consommables_a_traiter' => $co->fetchAll(PDO::FETCH_ASSOC),
                ];
            }

            case 'chercher_sse': {
                $t = trim($in['nom'] ?? '');
                $like = '%' . $t . '%';
                $sc = "SELECT id, nom, quantite, quantite_min, unite, date_peremption, statut
                       FROM sse_consommables WHERE site_id = ?";
                $ac = [$site_id];
                if ($t !== '') { $sc .= " AND nom LIKE ?"; $ac[] = $like; }
                $sc .= " ORDER BY nom LIMIT 40";
                $q1 = $pdo->prepare($sc); $q1->execute($ac);
                $se = "SELECT id, nom, statut, date_peremption, date_prochain_ctrl
                       FROM sse_equipements WHERE site_id = ? AND actif = 1";
                $ae = [$site_id];
                if ($t !== '') { $se .= " AND nom LIKE ?"; $ae[] = $like; }
                $se .= " ORDER BY nom LIMIT 40";
                $q2 = $pdo->prepare($se); $q2->execute($ae);
                return [
                    'consommables' => $q1->fetchAll(PDO::FETCH_ASSOC),
                    'equipements'  => $q2->fetchAll(PDO::FETCH_ASSOC),
                ];
            }

            case 'dotation_sacs': {
                require_once __DIR__ . '/../includes/dotation.php';
                return ['sacs' => dotation_cockpit($pdo, $site_id)];
            }

            case 'dotation_manques': {
                require_once __DIR__ . '/../includes/dotation.php';
                $nom = trim($in['emplacement'] ?? '');
                if ($nom === '') return ['erreur' => 'emplacement requis'];
                $st = $pdo->prepare("SELECT id, nom FROM emplacements
                                     WHERE site_id = ? AND actif = 1 AND dotation_modele_id IS NOT NULL AND nom LIKE ?
                                     ORDER BY nom LIMIT 1");
                $st->execute([$site_id, '%' . $nom . '%']);
                $e = $st->fetch(PDO::FETCH_ASSOC);
                if (!$e) return ['erreur' => "Aucun sac « $nom » avec une dotation définie."];
                $ec = dotation_ecarts($pdo, (int)$e['id'], $site_id);
                if (!$ec) return ['erreur' => 'Pas de dotation sur ce sac.'];
                $fmt = fn($arr) => array_values(array_map(fn($l) => [
                    'produit'  => trim(($l['dci'] ?: $l['nom']) . ' ' . ($l['dosage'] ?? '')),
                    'attendu'  => (float)$l['quantite_attendue'],
                    'present'  => (float)$l['qte_reel'],
                ], $arr));
                return [
                    'emplacement' => $e['nom'],
                    'completude'  => $ec['stats']['completude'],
                    'manquant'    => $fmt(array_filter($ec['lignes'], fn($l) => $l['statut'] === 'manquant')),
                    'insuffisant' => $fmt(array_filter($ec['lignes'], fn($l) => $l['statut'] === 'insuffisant')),
                ];
            }
        }
        return ['erreur' => 'Outil inconnu : ' . $name];
    } catch (Throwable $e) {
        return ['erreur' => 'Échec outil ' . $name . ' : ' . $e->getMessage()];
    }
}

/* ════════════════════════════════════════════════════════════════
   ACTIONS D'ÉCRITURE — toujours sous confirmation utilisateur.
   Chaque action est activable/désactivable via ai_settings (clé action_*).
   ════════════════════════════════════════════════════════════════ */

/** Noms des actions d'écriture. */
function ai_action_names(): array {
    return ['rempotage', 'marquer_produit_retire', 'archiver_lot', 'ajuster_quantite', 'renouveler_lot', 'modifier_fiche', 'sse_ajuster_conso', 'sse_controle_equipement', 'completer_dotation', 'signaler_biomed', 'creer_alerte', 'generer_rapport'];
}

/** Normalise une date de péremption libre (MM/AAAA, JJ/MM/AAAA, AAAA-MM, AAAA-MM-JJ) en AAAA-MM-JJ, ou null. */
function ai_norm_date(?string $s): ?string {
    $s = trim((string)$s);
    if ($s === '') return null;
    if (preg_match('#^(\d{2})/(\d{4})$#', $s, $m))            return $m[2] . '-' . $m[1] . '-01';
    if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $s, $m))    return $m[3] . '-' . $m[2] . '-' . $m[1];
    if (preg_match('#^(\d{4})-(\d{2})$#', $s, $m))            return $m[1] . '-' . $m[2] . '-01';
    if (preg_match('#^\d{4}-\d{2}-\d{2}$#', $s))              return $s;
    return null;
}
function ai_tool_is_action(string $name): bool {
    return in_array($name, ai_action_names(), true);
}

/** Définitions des actions exposées au modèle, filtrées par ai_settings (défaut activé). */
function ai_actions_defs(PDO $pdo): array {
    $defs = [
        'action_rempotage' => [
            'name'        => 'rempotage',
            'description' => "Ajoute / rempote un ou plusieurs produits dans un emplacement (stock). À utiliser dès que l'utilisateur veut RANGER, AJOUTER, REMPOTER ou METTRE des produits dans un sac/pochette/emplacement. Détermine emplacement_id via lister_emplacements. Pour un médicament, le DOSAGE/concentration fait partie de l'identité du produit (ex. Adrénaline 1 mg/1 ml ≠ 5 mg/5 ml) : capture-le toujours. NE JAMAIS dire qu'un rempotage est fait sans passer par cet outil. Soumis à confirmation.",
            'input_schema' => ['type' => 'object', 'properties' => [
                'emplacement_id' => ['type' => 'integer', 'description' => "ID réel de l'emplacement cible (issu de lister_emplacements)."],
                'produits' => ['type' => 'array', 'description' => "Produits à rempoter.", 'items' => ['type' => 'object', 'properties' => [
                    'nom'        => ['type' => 'string', 'description' => "Nom du produit (ex. 'Adrénaline')."],
                    'dosage'     => ['type' => 'string', 'description' => "Dosage/concentration EXACT, ex. '1 mg/1 ml'. Obligatoire pour tout médicament : deux dosages = deux produits distincts. Si l'utilisateur ne l'a pas donné, DEMANDE-le avant de rempoter."],
                    'quantite'   => ['type' => 'number'],
                    'lot'        => ['type' => 'string'],
                    'peremption' => ['type' => 'string', 'description' => "MM/AAAA ou JJ/MM/AAAA ou AAAA-MM-JJ."],
                ], 'required' => ['nom', 'quantite']]],
            ], 'required' => ['emplacement_id', 'produits']],
        ],
        'action_marquer_retire' => [
            'name'        => 'marquer_produit_retire',
            'description' => "Marque un produit comme RETIRÉ du stock (périmé ou inutilisable) : met sa quantité à 0, son statut à 'retiré' et trace une sortie. Action sensible — sera soumise à la confirmation de l'utilisateur.",
            'input_schema' => ['type' => 'object', 'properties' => [
                'produit_id' => ['type' => 'integer', 'description' => "ID du produit à retirer (obtenu via chercher_stock)."],
            ], 'required' => ['produit_id']],
        ],
        'action_archiver_lot' => [
            'name'        => 'archiver_lot',
            'description' => "Archive un lot/produit (le sort de la liste active sans le supprimer, statut 'archive'). Utilise d'abord chercher_stock pour obtenir le produit_id. À utiliser quand l'utilisateur veut ARCHIVER / RANGER un lot terminé/plus utilisé. Soumis à confirmation.",
            'input_schema' => ['type' => 'object', 'properties' => [
                'produit_id' => ['type' => 'integer', 'description' => "ID du produit/lot à archiver (obtenu via chercher_stock)."],
            ], 'required' => ['produit_id']],
        ],
        'action_ajuster_quantite' => [
            'name'        => 'ajuster_quantite',
            'description' => "Ajuste la quantité en stock d'un produit précis. Deux modes : 'quantite' fixe la valeur absolue (ex. « il en reste 3 » → quantite=3) ; 'delta' ajoute/retire (ex. « j'en ai utilisé 2 » → delta=-2). Utilise d'abord chercher_stock pour obtenir le produit_id. Ne descend jamais sous 0. Soumis à confirmation.",
            'input_schema' => ['type' => 'object', 'properties' => [
                'produit_id' => ['type' => 'integer', 'description' => "ID du produit (via chercher_stock)."],
                'quantite'   => ['type' => 'number',  'description' => "Nouvelle quantité absolue. Utiliser SOIT quantite SOIT delta."],
                'delta'      => ['type' => 'number',  'description' => "Variation relative (négative pour retirer). Utiliser SOIT quantite SOIT delta."],
            ], 'required' => ['produit_id']],
        ],
        'action_renouveler_lot' => [
            'name'        => 'renouveler_lot',
            'description' => "Renouvelle un lot périmé/bientôt périmé : remplace tout ou partie de sa quantité par un lot neuf (nouveau numéro de lot + nouvelle péremption). Si la quantité renouvelée = tout le lot, la ligne est simplement mise à jour ; sinon l'ancien lot diminue et un nouveau lot est créé. Utilise d'abord chercher_stock pour obtenir le produit_id. Soumis à confirmation.",
            'input_schema' => ['type' => 'object', 'properties' => [
                'produit_id'     => ['type' => 'integer', 'description' => "ID du lot à renouveler (via chercher_stock)."],
                'date_peremption'=> ['type' => 'string',  'description' => "Nouvelle péremption. Formats : MM/AAAA, JJ/MM/AAAA, AAAA-MM ou AAAA-MM-JJ."],
                'numero_lot'     => ['type' => 'string',  'description' => "Nouveau numéro de lot (optionnel)."],
                'quantite'       => ['type' => 'number',  'description' => "Quantité à renouveler (optionnel). Par défaut : tout le lot."],
            ], 'required' => ['produit_id', 'date_peremption']],
        ],
        'action_modifier_fiche' => [
            'name'        => 'modifier_fiche',
            'description' => "Corrige/complète l'IDENTITÉ d'une fiche produit existante : nom commercial, DCI, dosage, forme. Ne touche NI la quantité NI le lot NI la péremption. Règle absolue : le DCI est la MOLÉCULE (ex. Paracétamol, Adrénaline), le nom commercial est la MARQUE (ex. Doliprane, Anahelp) — ne remplace JAMAIS un DCI correct par une marque. Pour compléter un DCI manquant, recopie la molécule dans le champ dci. Utilise chercher_stock pour le produit_id. Ne renseigne QUE les champs à changer. Un seul produit à la fois. Soumis à confirmation.",
            'input_schema' => ['type' => 'object', 'properties' => [
                'produit_id' => ['type' => 'integer', 'description' => "ID de la fiche à corriger (via chercher_stock)."],
                'nom'        => ['type' => 'string', 'description' => "Nouveau nom commercial (marque). Optionnel."],
                'dci'        => ['type' => 'string', 'description' => "DCI / molécule. Optionnel."],
                'dosage'     => ['type' => 'string', 'description' => "Dosage, ex. '1 mg/1 ml'. Optionnel."],
                'forme'      => ['type' => 'string', 'description' => "Forme, ex. 'solution injectable'. Optionnel."],
            ], 'required' => ['produit_id']],
        ],
        'action_sse_ajuster_conso' => [
            'name'        => 'sse_ajuster_conso',
            'description' => "Ajuste la quantité d'un CONSOMMABLE du module SSE/NRBC. Mode 'quantite' (valeur absolue) ou 'delta' (relatif, négatif pour retirer). Utilise chercher_sse pour l'id. Soumis à confirmation.",
            'input_schema' => ['type' => 'object', 'properties' => [
                'conso_id' => ['type' => 'integer', 'description' => "ID du consommable SSE (via chercher_sse)."],
                'quantite' => ['type' => 'number',  'description' => "Nouvelle quantité absolue."],
                'delta'    => ['type' => 'number',  'description' => "Variation relative (négative pour retirer)."],
            ], 'required' => ['conso_id']],
        ],
        'action_sse_controle_equipement' => [
            'name'        => 'sse_controle_equipement',
            'description' => "Enregistre un CONTRÔLE sur un équipement SSE/NRBC : résultat (ok / anomalie / hors_service). Met à jour le statut de l'équipement, et si une périodicité (mois) est fournie, calcule la date du prochain contrôle. Utilise chercher_sse pour l'id. Soumis à confirmation.",
            'input_schema' => ['type' => 'object', 'properties' => [
                'equipement_id'    => ['type' => 'integer', 'description' => "ID de l'équipement SSE (via chercher_sse)."],
                'resultat'         => ['type' => 'string',  'description' => "ok, anomalie ou hors_service."],
                'periodicite_mois' => ['type' => 'integer', 'description' => "Périodicité en mois pour le prochain contrôle (optionnel)."],
                'notes'            => ['type' => 'string',  'description' => "Observations (optionnel)."],
            ], 'required' => ['equipement_id', 'resultat']],
        ],
        'action_completer_dotation' => [
            'name'        => 'completer_dotation',
            'description' => "Complète un sac depuis la PUI du site : transfère les produits manquants ou insuffisants de sa dotation, depuis la PUI (FEFO), vers le sac. Prends le NOM du sac. Le manque non couvert par la PUI est signalé, jamais bloquant. Soumis à confirmation.",
            'input_schema' => ['type' => 'object', 'properties' => [
                'emplacement' => ['type' => 'string', 'description' => "Nom du sac à compléter (ex. « SAC ISP 1 »)."],
            ], 'required' => ['emplacement']],
        ],
        'action_signaler_biomed' => [
            'name'        => 'signaler_biomed',
            'description' => "Crée une DEMANDE d'intervention au service biomédical, rattachée à un matériel précis. Utilise d'abord chercher_materiel pour obtenir le materiel_id. Soumis à confirmation.",
            'input_schema' => ['type' => 'object', 'properties' => [
                'materiel_id' => ['type' => 'integer', 'description' => "ID du matériel concerné (via chercher_materiel)."],
                'titre'       => ['type' => 'string', 'description' => "Objet court de la demande (ex. 'Bruit anormal')."],
                'description' => ['type' => 'string', 'description' => "Détails du problème."],
                'priorite'    => ['type' => 'string', 'description' => "normale, haute ou urgente (défaut normale)."],
            ], 'required' => ['materiel_id', 'titre']],
        ],
        'action_creer_alerte' => [
            'name'        => 'creer_alerte',
            'description' => "Crée une ALERTE PRODUIT (péremption, stock bas ou chaîne du froid) rattachée à un produit précis. Utilise d'abord chercher_stock pour obtenir le produit_id. Soumis à confirmation.",
            'input_schema' => ['type' => 'object', 'properties' => [
                'produit_id' => ['type' => 'integer', 'description' => "ID du produit concerné (via chercher_stock)."],
                'type'       => ['type' => 'string', 'description' => "peremption, stock_bas ou chaine_froid."],
                'niveau'     => ['type' => 'string', 'description' => "avertissement (défaut), critique ou perime."],
                'message'    => ['type' => 'string', 'description' => "Texte de l'alerte."],
            ], 'required' => ['produit_id', 'type']],
        ],
        'action_rapport' => [
            'name'        => 'generer_rapport',
            'description' => "Ouvre la génération du rapport PDF du site. Soumis à confirmation.",
            'input_schema' => ['type' => 'object', 'properties' => new stdClass(), 'required' => []],
        ],
    ];
    $out = [];
    foreach ($defs as $cle => $def) {
        $actif = true;
        try {
            $v = $pdo->prepare("SELECT valeur FROM ai_settings WHERE cle = ?");
            $v->execute([$cle]);
            $val = $v->fetchColumn();
            if ($val !== false && $val !== null) $actif = ($val === '1' || $val === 1);
        } catch (Throwable $e) { $actif = true; }
        if ($actif) $out[] = $def;
    }
    return $out;
}

/** Construit une proposition lisible (SANS exécuter) pour la carte de confirmation. */
function ai_action_proposal(PDO $pdo, string $name, array $in, int $site_id): array {
    switch ($name) {

        case 'rempotage': {
            $empl_id  = (int)($in['emplacement_id'] ?? 0);
            $produits = is_array($in['produits'] ?? null) ? $in['produits'] : [];
            $st = $pdo->prepare("SELECT id, nom FROM emplacements WHERE id = ? AND site_id = ? AND actif = 1");
            $st->execute([$empl_id, $site_id]);
            $empl = $st->fetch(PDO::FETCH_ASSOC);
            if (!$empl)     return ['status' => 'error', 'message' => "Emplacement introuvable (ID $empl_id)."];
            if (!$produits) return ['status' => 'error', 'message' => "Aucun produit à rempoter."];
            // Le dosage fait partie de l'identité du produit : on l'intègre au nom.
            foreach ($produits as &$pp) {
                $pnom = trim($pp['nom'] ?? '');
                $pdos = trim($pp['dosage'] ?? '');
                if ($pdos !== '' && stripos($pnom, $pdos) === false) {
                    $pp['nom'] = trim($pnom . ' ' . $pdos);
                }
            }
            unset($pp);
            return [
                'status'         => 'pending_confirm',
                'action'         => 'rempotage',
                'emplacement'    => $empl,
                'emplacement_id' => $empl_id,
                'produits'       => $produits,
                'message'        => 'Rempotage à confirmer',
            ];
        }

        case 'marquer_produit_retire': {
            $pid = (int)($in['produit_id'] ?? 0);
            $st = $pdo->prepare("SELECT pr.id, pr.nom, pr.quantite, pr.numero_lot, pr.date_peremption, e.nom AS emplacement
                                 FROM produits pr JOIN emplacements e ON e.id = pr.emplacement_id
                                 WHERE pr.id = ? AND e.site_id = ? AND pr.statut = 'actif'");
            $st->execute([$pid, $site_id]);
            $p = $st->fetch(PDO::FETCH_ASSOC);
            if (!$p) return ['status' => 'error', 'message' => "Produit introuvable ou déjà retiré (ID $pid)."];
            $det = $p['nom']
                 . ($p['numero_lot'] ? ' — lot ' . $p['numero_lot'] : '')
                 . ($p['date_peremption'] ? ' — pér. ' . $p['date_peremption'] : '')
                 . ' — ' . $p['emplacement'];
            return [
                'status'        => 'pending_confirm',
                'action'        => 'marquer_produit_retire',
                'params'        => ['produit_id' => $pid],
                'titre'         => '🗑️ Retirer du stock',
                'lignes'        => [$det, 'Quantité ' . $p['quantite'] . ' → 0, statut « retiré »'],
                'confirm_label' => 'Retirer',
                'message'       => 'Retrait à confirmer',
            ];
        }

        case 'archiver_lot': {
            if (function_exists('action_permise') && !action_permise('archiver'))
                return ['status' => 'error', 'message' => "Votre rôle n'a pas le droit d'archiver un produit."];
            $pid = (int)($in['produit_id'] ?? 0);
            $st = $pdo->prepare("SELECT pr.id, pr.nom, pr.dci, pr.dosage, pr.quantite, pr.unite, pr.numero_lot, pr.date_peremption, e.nom AS emplacement
                                 FROM produits pr JOIN emplacements e ON e.id = pr.emplacement_id
                                 WHERE pr.id = ? AND e.site_id = ? AND pr.statut = 'actif'");
            $st->execute([$pid, $site_id]);
            $p = $st->fetch(PDO::FETCH_ASSOC);
            if (!$p) return ['status' => 'error', 'message' => "Produit introuvable ou déjà archivé (ID $pid)."];
            $ident = trim(($p['dci'] ?: $p['nom']) . ' ' . ($p['dosage'] ?? ''));
            $det = $ident
                 . ($p['numero_lot'] ? ' — lot ' . $p['numero_lot'] : '')
                 . ($p['date_peremption'] ? ' — pér. ' . $p['date_peremption'] : '')
                 . ' — ' . $p['emplacement'];
            return [
                'status'        => 'pending_confirm',
                'action'        => 'archiver_lot',
                'params'        => ['produit_id' => $pid],
                'titre'         => '📦 Archiver le lot',
                'lignes'        => [$det, 'Statut « actif » → « archivé » (quantité conservée)'],
                'confirm_label' => 'Archiver',
                'message'       => 'Archivage à confirmer',
            ];
        }

        case 'ajuster_quantite': {
            if (function_exists('action_permise') && !action_permise('ajuster'))
                return ['status' => 'error', 'message' => "Votre rôle n'a pas le droit d'ajuster les quantités."];
            $pid = (int)($in['produit_id'] ?? 0);
            $st = $pdo->prepare("SELECT pr.id, pr.nom, pr.dci, pr.dosage, pr.quantite, pr.unite, e.nom AS emplacement
                                 FROM produits pr JOIN emplacements e ON e.id = pr.emplacement_id
                                 WHERE pr.id = ? AND e.site_id = ? AND pr.statut = 'actif'");
            $st->execute([$pid, $site_id]);
            $p = $st->fetch(PDO::FETCH_ASSOC);
            if (!$p) return ['status' => 'error', 'message' => "Produit introuvable (ID $pid). Utilise chercher_stock."];
            $avant = (float)$p['quantite'];
            $hasQ = array_key_exists('quantite', $in) && $in['quantite'] !== null && $in['quantite'] !== '';
            $hasD = array_key_exists('delta', $in)    && $in['delta']    !== null && $in['delta']    !== '';
            if (!$hasQ && !$hasD) return ['status' => 'error', 'message' => "Précise soit la nouvelle quantité, soit la variation."];
            $apres = $hasQ ? (float)$in['quantite'] : $avant + (float)$in['delta'];
            if ($apres < 0) $apres = 0;
            $fmt = fn($n) => rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
            $ident = trim(($p['dci'] ?: $p['nom']) . ' ' . ($p['dosage'] ?? ''));
            return [
                'status'        => 'pending_confirm',
                'action'        => 'ajuster_quantite',
                'params'        => ['produit_id' => $pid, 'quantite' => $apres],
                'titre'         => '± Ajuster la quantité',
                'lignes'        => [
                    $ident . ' — ' . $p['emplacement'],
                    'Quantité ' . $fmt($avant) . ' → ' . $fmt($apres) . ' ' . ($p['unite'] ?? ''),
                ],
                'confirm_label' => 'Ajuster',
                'message'       => 'Ajustement à confirmer',
            ];
        }

        case 'renouveler_lot': {
            if (function_exists('action_permise') && !action_permise('renouveler'))
                return ['status' => 'error', 'message' => "Votre rôle n'a pas le droit de renouveler un lot."];
            $pid   = (int)($in['produit_id'] ?? 0);
            $ndate = ai_norm_date($in['date_peremption'] ?? '');
            $nlot  = trim($in['numero_lot'] ?? '') ?: null;
            if (!$ndate) return ['status' => 'error', 'message' => "Nouvelle date de péremption invalide (attendu MM/AAAA, JJ/MM/AAAA ou AAAA-MM-JJ)."];
            $st = $pdo->prepare("SELECT pr.id, pr.nom, pr.dci, pr.dosage, pr.quantite, pr.unite, pr.numero_lot, pr.date_peremption, e.nom AS emplacement
                                 FROM produits pr JOIN emplacements e ON e.id = pr.emplacement_id
                                 WHERE pr.id = ? AND e.site_id = ? AND pr.statut = 'actif'");
            $st->execute([$pid, $site_id]);
            $p = $st->fetch(PDO::FETCH_ASSOC);
            if (!$p) return ['status' => 'error', 'message' => "Lot introuvable (ID $pid). Utilise chercher_stock."];
            $qold = (float)$p['quantite'];
            $qren = array_key_exists('quantite', $in) && $in['quantite'] !== null && $in['quantite'] !== ''
                  ? min((float)$in['quantite'], $qold) : $qold;
            if ($qren <= 0) return ['status' => 'error', 'message' => "Quantité à renouveler invalide."];
            $fmt = fn($n) => rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
            $ident = trim(($p['dci'] ?: $p['nom']) . ' ' . ($p['dosage'] ?? ''));
            return [
                'status'        => 'pending_confirm',
                'action'        => 'renouveler_lot',
                'params'        => ['produit_id' => $pid, 'date_peremption' => $ndate, 'numero_lot' => $nlot, 'quantite' => $qren],
                'titre'         => '♻️ Renouveler le lot',
                'lignes'        => array_values(array_filter([
                    $ident . ' — ' . $p['emplacement'],
                    'Ancien : lot ' . ($p['numero_lot'] ?: '—') . ' · pér. ' . ($p['date_peremption'] ?: '—'),
                    'Nouveau : lot ' . ($nlot ?: '—') . ' · pér. ' . $ndate,
                    'Quantité renouvelée : ' . $fmt($qren) . ' ' . ($p['unite'] ?? '') . ($qren < $qold ? (' (sur ' . $fmt($qold) . ')') : ' (lot entier)'),
                ])),
                'confirm_label' => 'Renouveler',
                'message'       => 'Renouvellement à confirmer',
            ];
        }

        case 'modifier_fiche': {
            if (function_exists('action_permise') && !action_permise('modifier'))
                return ['status' => 'error', 'message' => "Votre rôle n'a pas le droit de modifier une fiche."];
            $pid = (int)($in['produit_id'] ?? 0);
            $st = $pdo->prepare("SELECT pr.id, pr.nom, pr.dci, pr.dosage, pr.forme, e.nom AS emplacement
                                 FROM produits pr JOIN emplacements e ON e.id = pr.emplacement_id
                                 WHERE pr.id = ? AND e.site_id = ? AND pr.statut = 'actif'");
            $st->execute([$pid, $site_id]);
            $p = $st->fetch(PDO::FETCH_ASSOC);
            if (!$p) return ['status' => 'error', 'message' => "Fiche introuvable (ID $pid). Utilise chercher_stock."];
            $labels = ['nom' => 'Nom commercial', 'dci' => 'DCI', 'dosage' => 'Dosage', 'forme' => 'Forme'];
            $params = ['produit_id' => $pid];
            $lignes = [trim(($p['dci'] ?: $p['nom']) . ' ' . ($p['dosage'] ?? '')) . ' — ' . $p['emplacement']];
            foreach ($labels as $f => $lbl) {
                if (!array_key_exists($f, $in)) continue;
                $new = trim((string)$in[$f]);
                if ($f === 'nom' && $new === '') continue; // le nom ne se vide pas
                $old = trim((string)($p[$f] ?? ''));
                if ($new === $old) continue;
                $params[$f] = $new;
                $lignes[] = $lbl . ' : ' . ($old !== '' ? '« ' . $old . ' »' : '(vide)') . ' → ' . ($new !== '' ? '« ' . $new . ' »' : '(vide)');
            }
            if (count($params) <= 1) return ['status' => 'error', 'message' => "Rien à modifier (valeurs identiques)."];
            return [
                'status'        => 'pending_confirm',
                'action'        => 'modifier_fiche',
                'params'        => $params,
                'titre'         => '✏️ Corriger la fiche',
                'lignes'        => $lignes,
                'confirm_label' => 'Enregistrer',
                'message'       => 'Correction à confirmer',
            ];
        }

        case 'sse_ajuster_conso': {
            if (function_exists('canEdit') && !canEdit())
                return ['status' => 'error', 'message' => "Votre rôle n'a pas le droit de modifier le stock SSE."];
            $cid = (int)($in['conso_id'] ?? 0);
            $st = $pdo->prepare("SELECT id, nom, quantite, unite FROM sse_consommables WHERE id = ? AND site_id = ?");
            $st->execute([$cid, $site_id]);
            $c = $st->fetch(PDO::FETCH_ASSOC);
            if (!$c) return ['status' => 'error', 'message' => "Consommable SSE introuvable (ID $cid). Utilise chercher_sse."];
            $avant = (float)$c['quantite'];
            $hasQ = array_key_exists('quantite', $in) && $in['quantite'] !== null && $in['quantite'] !== '';
            $hasD = array_key_exists('delta', $in)    && $in['delta']    !== null && $in['delta']    !== '';
            if (!$hasQ && !$hasD) return ['status' => 'error', 'message' => "Précise la nouvelle quantité ou la variation."];
            $apres = $hasQ ? (float)$in['quantite'] : $avant + (float)$in['delta'];
            if ($apres < 0) $apres = 0;
            $fmt = fn($n) => rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
            return [
                'status'        => 'pending_confirm',
                'action'        => 'sse_ajuster_conso',
                'params'        => ['conso_id' => $cid, 'quantite' => $apres],
                'titre'         => '± Ajuster (SSE/NRBC)',
                'lignes'        => [$c['nom'] . ' (SSE)', 'Quantité ' . $fmt($avant) . ' → ' . $fmt($apres) . ' ' . ($c['unite'] ?? '')],
                'confirm_label' => 'Ajuster',
                'message'       => 'Ajustement SSE à confirmer',
            ];
        }

        case 'sse_controle_equipement': {
            if (function_exists('canEdit') && !canEdit())
                return ['status' => 'error', 'message' => "Votre rôle n'a pas le droit d'enregistrer un contrôle SSE."];
            $eid = (int)($in['equipement_id'] ?? 0);
            $res = strtolower(trim($in['resultat'] ?? ''));
            if (!in_array($res, ['ok', 'anomalie', 'hors_service'], true))
                return ['status' => 'error', 'message' => "Résultat invalide (attendu : ok, anomalie ou hors_service)."];
            $st = $pdo->prepare("SELECT id, nom, statut FROM sse_equipements WHERE id = ? AND site_id = ? AND actif = 1");
            $st->execute([$eid, $site_id]);
            $e = $st->fetch(PDO::FETCH_ASSOC);
            if (!$e) return ['status' => 'error', 'message' => "Équipement SSE introuvable (ID $eid). Utilise chercher_sse."];
            $per   = (int)($in['periodicite_mois'] ?? 0);
            $notes = trim($in['notes'] ?? '');
            $lignes = array_values(array_filter([
                $e['nom'] . ' (SSE)',
                'Résultat : ' . $res,
                $per > 0 ? "Prochain contrôle : dans $per mois" : null,
                $notes !== '' ? $notes : null,
            ]));
            return [
                'status'        => 'pending_confirm',
                'action'        => 'sse_controle_equipement',
                'params'        => ['equipement_id' => $eid, 'resultat' => $res, 'periodicite_mois' => $per, 'notes' => $notes],
                'titre'         => '🧪 Contrôle SSE/NRBC',
                'lignes'        => $lignes,
                'confirm_label' => 'Enregistrer',
                'message'       => 'Contrôle à confirmer',
            ];
        }

        case 'completer_dotation': {
            if (function_exists('action_permise') && !action_permise('dotation'))
                return ['status' => 'error', 'message' => "Votre rôle n'a pas le droit de compléter une dotation."];
            require_once __DIR__ . '/../includes/dotation.php';
            $nom = trim($in['emplacement'] ?? '');
            if ($nom === '') return ['status' => 'error', 'message' => 'Nom du sac requis.'];
            $st = $pdo->prepare("SELECT id, nom FROM emplacements WHERE site_id=? AND actif=1 AND dotation_modele_id IS NOT NULL AND nom LIKE ? ORDER BY nom LIMIT 1");
            $st->execute([$site_id, '%' . $nom . '%']);
            $e = $st->fetch(PDO::FETCH_ASSOC);
            if (!$e) return ['status' => 'error', 'message' => "Aucun sac « $nom » avec une dotation définie."];
            $ec = dotation_ecarts($pdo, (int)$e['id'], $site_id);
            $manq = ($ec['stats']['manquant'] ?? 0) + ($ec['stats']['insuffisant'] ?? 0);
            if ($manq <= 0) return ['status' => 'error', 'message' => $e['nom'] . ' est déjà conforme, rien à compléter.'];
            return [
                'status'        => 'pending_confirm',
                'action'        => 'completer_dotation',
                'params'        => ['emplacement_id' => (int)$e['id']],
                'titre'         => '⚡ Compléter depuis la PUI',
                'lignes'        => [
                    $e['nom'] . ' — ' . $manq . ' produit(s) à compléter',
                    'Transfert depuis la PUI (FEFO). Le manque non couvert sera signalé.',
                ],
                'confirm_label' => 'Compléter',
                'message'       => 'Complétion à confirmer',
            ];
        }

        case 'signaler_biomed': {
            $mid   = (int)($in['materiel_id'] ?? 0);
            $titre = trim($in['titre'] ?? '');
            $desc  = trim($in['description'] ?? '');
            $prio  = strtolower(trim($in['priorite'] ?? 'normale'));
            if (!in_array($prio, ['normale', 'haute', 'urgente'], true)) $prio = 'normale';
            $st = $pdo->prepare("SELECT id, nom FROM materiels WHERE id = ? AND site_id = ?");
            $st->execute([$mid, $site_id]);
            $mat = $st->fetch(PDO::FETCH_ASSOC);
            if (!$mat)         return ['status' => 'error', 'message' => "Matériel introuvable (ID $mid). Utilise chercher_materiel."];
            if ($titre === '') return ['status' => 'error', 'message' => "Titre de la demande manquant."];
            return [
                'status'        => 'pending_confirm',
                'action'        => 'signaler_biomed',
                'params'        => ['materiel_id' => $mid, 'titre' => $titre, 'description' => $desc, 'priorite' => $prio],
                'titre'         => '🔧 Demande biomédicale',
                'lignes'        => array_values(array_filter([
                    'Matériel : ' . $mat['nom'],
                    $titre,
                    $desc,
                    'Priorité : ' . $prio,
                ])),
                'confirm_label' => 'Créer la demande',
                'message'       => 'Demande à confirmer',
            ];
        }

        case 'creer_alerte': {
            $pid    = (int)($in['produit_id'] ?? 0);
            $type   = strtolower(trim($in['type'] ?? ''));
            $niveau = strtolower(trim($in['niveau'] ?? 'avertissement'));
            $msg    = trim($in['message'] ?? '');
            if (!in_array($type, ['peremption', 'stock_bas', 'chaine_froid'], true))
                return ['status' => 'error', 'message' => "Type d'alerte invalide (attendu : peremption, stock_bas ou chaine_froid)."];
            if (!in_array($niveau, ['ok', 'avertissement', 'critique', 'perime'], true)) $niveau = 'avertissement';
            $st = $pdo->prepare("SELECT pr.id, pr.nom, e.nom AS emplacement
                                 FROM produits pr JOIN emplacements e ON e.id = pr.emplacement_id
                                 WHERE pr.id = ? AND e.site_id = ?");
            $st->execute([$pid, $site_id]);
            $p = $st->fetch(PDO::FETCH_ASSOC);
            if (!$p) return ['status' => 'error', 'message' => "Produit introuvable (ID $pid). Utilise chercher_stock."];
            $libelle = ['peremption' => 'Péremption', 'stock_bas' => 'Stock bas', 'chaine_froid' => 'Chaîne du froid'][$type];
            return [
                'status'        => 'pending_confirm',
                'action'        => 'creer_alerte',
                'params'        => ['produit_id' => $pid, 'type' => $type, 'niveau' => $niveau, 'message' => $msg],
                'titre'         => '🔔 Alerte produit',
                'lignes'        => array_values(array_filter([
                    'Produit : ' . $p['nom'] . ' — ' . $p['emplacement'],
                    'Type : ' . $libelle . ' (' . $niveau . ')',
                    $msg,
                ])),
                'confirm_label' => 'Créer l\'alerte',
                'message'       => 'Alerte à confirmer',
            ];
        }

        case 'generer_rapport': {
            return [
                'status'        => 'pending_confirm',
                'action'        => 'generer_rapport',
                'params'        => [],
                'titre'         => '📄 Rapport PDF',
                'lignes'        => ['Ouvrir la génération du rapport du site'],
                'confirm_label' => 'Ouvrir',
                'message'       => 'Rapport à générer',
            ];
        }
    }
    return ['status' => 'error', 'message' => 'Action inconnue : ' . $name];
}

/** Exécute réellement l'action APRÈS confirmation utilisateur (hors rempotage, géré par ai_execute_action). */
function ai_action_run(PDO $pdo, string $name, array $in, int $site_id, int $user_id): array {
    try {
        switch ($name) {

            case 'marquer_produit_retire': {
                $pid = (int)($in['produit_id'] ?? 0);
                $st = $pdo->prepare("SELECT pr.id, pr.nom, pr.quantite
                                     FROM produits pr JOIN emplacements e ON e.id = pr.emplacement_id
                                     WHERE pr.id = ? AND e.site_id = ? AND pr.statut = 'actif'");
                $st->execute([$pid, $site_id]);
                $p = $st->fetch(PDO::FETCH_ASSOC);
                if (!$p) return ['status' => 'error', 'message' => "Produit introuvable ou déjà retiré."];
                $avant = (float)$p['quantite'];
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE produits SET quantite = 0, statut = 'retire', modifie_par = ? WHERE id = ?")
                    ->execute([$user_id, $pid]);
                $pdo->prepare("INSERT INTO mouvements_stock (produit_id, user_id, type, quantite_avant, quantite_delta, quantite_apres, motif)
                               VALUES (?, ?, 'sortie', ?, ?, 0, ?)")
                    ->execute([$pid, $user_id, $avant, -$avant, 'Retire (perime/inutilisable) via assistant IA']);
                $pdo->commit();
                audit_log($pdo, 'PRODUIT_RETIRE_IA', 'produits', $pid, null, ['nom' => $p['nom'], 'via' => 'assistant_ia']);
                return ['status' => 'success', 'message' => $p['nom'] . ' retiré du stock.', 'lien' => '/products.php'];
            }

            case 'archiver_lot': {
                if (function_exists('action_permise') && !action_permise('archiver'))
                    return ['status' => 'error', 'message' => "Permission refusée (archiver)."];
                $pid = (int)($in['produit_id'] ?? 0);
                $st = $pdo->prepare("SELECT pr.id, pr.nom FROM produits pr JOIN emplacements e ON e.id = pr.emplacement_id
                                     WHERE pr.id = ? AND e.site_id = ? AND pr.statut = 'actif'");
                $st->execute([$pid, $site_id]);
                $p = $st->fetch(PDO::FETCH_ASSOC);
                if (!$p) return ['status' => 'error', 'message' => "Produit introuvable ou déjà archivé."];
                $pdo->prepare("UPDATE produits SET statut = 'archive', modifie_par = ?, modifie_le = NOW() WHERE id = ?")
                    ->execute([$user_id, $pid]);
                audit_log($pdo, 'PRODUIT_ARCHIVE_IA', 'produits', $pid, ['statut' => 'actif'], ['statut' => 'archive', 'via' => 'assistant_ia']);
                return ['status' => 'success', 'message' => $p['nom'] . ' archivé.', 'lien' => '/products.php'];
            }

            case 'ajuster_quantite': {
                if (function_exists('action_permise') && !action_permise('ajuster'))
                    return ['status' => 'error', 'message' => "Permission refusée (ajuster)."];
                $pid = (int)($in['produit_id'] ?? 0);
                $st = $pdo->prepare("SELECT pr.id, pr.nom, pr.quantite FROM produits pr JOIN emplacements e ON e.id = pr.emplacement_id
                                     WHERE pr.id = ? AND e.site_id = ? AND pr.statut = 'actif'");
                $st->execute([$pid, $site_id]);
                $p = $st->fetch(PDO::FETCH_ASSOC);
                if (!$p) return ['status' => 'error', 'message' => 'Produit introuvable.'];
                $avant = (float)$p['quantite'];
                $hasQ = array_key_exists('quantite', $in) && $in['quantite'] !== null && $in['quantite'] !== '';
                $hasD = array_key_exists('delta', $in)    && $in['delta']    !== null && $in['delta']    !== '';
                if (!$hasQ && !$hasD) return ['status' => 'error', 'message' => 'Quantité ou variation manquante.'];
                $apres = $hasQ ? (float)$in['quantite'] : $avant + (float)$in['delta'];
                if ($apres < 0) $apres = 0;
                $delta = $apres - $avant;
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE produits SET quantite = ?, modifie_par = ?, modifie_le = NOW() WHERE id = ?")
                    ->execute([$apres, $user_id, $pid]);
                if ($delta != 0) {
                    $pdo->prepare("INSERT INTO mouvements_stock (produit_id, user_id, type, quantite_avant, quantite_delta, quantite_apres, motif)
                                   VALUES (?, ?, ?, ?, ?, ?, 'Ajustement via assistant IA')")
                        ->execute([$pid, $user_id, $delta > 0 ? 'entree' : 'sortie', $avant, $delta, $apres]);
                }
                $pdo->commit();
                audit_log($pdo, 'MODIF_PRODUIT', 'produits', $pid, ['quantite' => $avant], ['quantite' => $apres, 'via' => 'assistant_ia']);
                $fmt = fn($n) => rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
                return ['status' => 'success', 'message' => $p['nom'] . ' : quantité ajustée à ' . $fmt($apres) . '.', 'lien' => '/products.php'];
            }

            case 'renouveler_lot': {
                if (function_exists('action_permise') && !action_permise('renouveler'))
                    return ['status' => 'error', 'message' => "Permission refusée (renouveler)."];
                $pid   = (int)($in['produit_id'] ?? 0);
                $ndate = ai_norm_date($in['date_peremption'] ?? '');
                $nlot  = trim($in['numero_lot'] ?? '') ?: null;
                if (!$ndate) return ['status' => 'error', 'message' => 'Date de péremption invalide.'];
                $st = $pdo->prepare("SELECT pr.* FROM produits pr JOIN emplacements e ON e.id = pr.emplacement_id
                                     WHERE pr.id = ? AND e.site_id = ? AND pr.statut = 'actif'");
                $st->execute([$pid, $site_id]);
                $old = $st->fetch(PDO::FETCH_ASSOC);
                if (!$old) return ['status' => 'error', 'message' => 'Lot introuvable.'];
                $qold = (float)$old['quantite'];
                $qren = array_key_exists('quantite', $in) && $in['quantite'] !== null && $in['quantite'] !== ''
                      ? min((float)$in['quantite'], $qold) : $qold;
                if ($qren <= 0) return ['status' => 'error', 'message' => 'Quantité à renouveler invalide.'];
                $pdo->beginTransaction();
                if ($qren >= $qold) {
                    // Lot entier → mise à jour de la ligne
                    $pdo->prepare("UPDATE produits SET numero_lot = ?, date_peremption = ?, modifie_par = ?, modifie_le = NOW() WHERE id = ?")
                        ->execute([$nlot, $ndate, $user_id, $pid]);
                    $pdo->prepare("INSERT INTO mouvements_stock (produit_id, user_id, type, quantite_avant, quantite_delta, quantite_apres, motif)
                                   VALUES (?, ?, 'entree', ?, 0, ?, 'Renouvellement lot (entier) via assistant IA')")
                        ->execute([$pid, $user_id, $qold, $qold]);
                    audit_log($pdo, 'RENOUVELLEMENT_LOT_IA', 'produits', $pid,
                        ['numero_lot' => $old['numero_lot'], 'date_peremption' => $old['date_peremption']],
                        ['numero_lot' => $nlot, 'date_peremption' => $ndate, 'via' => 'assistant_ia']);
                } else {
                    // Partiel → ancien lot diminué + nouveau lot créé
                    $reste = $qold - $qren;
                    $pdo->prepare("UPDATE produits SET quantite = ?, modifie_par = ?, modifie_le = NOW() WHERE id = ?")
                        ->execute([$reste, $user_id, $pid]);
                    $pdo->prepare("INSERT INTO produits (emplacement_id, nom, dci, dosage, forme, reference, numero_lot, quantite, quantite_min, unite, date_peremption, alerte_jours, chaine_froid, temp_stockage_min, temp_stockage_max, notes, cree_par)
                                   SELECT emplacement_id, nom, dci, dosage, forme, reference, ?, ?, quantite_min, unite, ?, alerte_jours, chaine_froid, temp_stockage_min, temp_stockage_max, notes, ?
                                   FROM produits WHERE id = ?")
                        ->execute([$nlot, $qren, $ndate, $user_id, $pid]);
                    $newId = (int)$pdo->lastInsertId();
                    $pdo->prepare("INSERT INTO mouvements_stock (produit_id, user_id, type, quantite_avant, quantite_delta, quantite_apres, motif)
                                   VALUES (?, ?, 'sortie', ?, ?, ?, 'Renouvellement - retrait perime via assistant IA')")
                        ->execute([$pid, $user_id, $qold, -$qren, $reste]);
                    $pdo->prepare("INSERT INTO mouvements_stock (produit_id, user_id, type, quantite_avant, quantite_delta, quantite_apres, motif)
                                   VALUES (?, ?, 'entree', 0, ?, ?, 'Renouvellement - nouveau lot via assistant IA')")
                        ->execute([$newId, $user_id, $qren, $qren]);
                    audit_log($pdo, 'RENOUVELLEMENT_LOT_IA', 'produits', $pid,
                        ['numero_lot' => $old['numero_lot'], 'quantite_remplacee' => $qren],
                        ['numero_lot' => $nlot, 'date_peremption' => $ndate, 'nouveau_produit_id' => $newId, 'via' => 'assistant_ia']);
                }
                $pdo->commit();
                $fmt = fn($n) => rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
                return ['status' => 'success', 'message' => 'Lot « ' . $old['nom'] . ' » renouvelé (' . $fmt($qren) . ' ' . $old['unite'] . ', péremption ' . $ndate . ').', 'lien' => '/products.php'];
            }

            case 'modifier_fiche': {
                if (function_exists('action_permise') && !action_permise('modifier'))
                    return ['status' => 'error', 'message' => "Permission refusée (modifier)."];
                $pid = (int)($in['produit_id'] ?? 0);
                $st = $pdo->prepare("SELECT pr.* FROM produits pr JOIN emplacements e ON e.id = pr.emplacement_id
                                     WHERE pr.id = ? AND e.site_id = ? AND pr.statut = 'actif'");
                $st->execute([$pid, $site_id]);
                $old = $st->fetch(PDO::FETCH_ASSOC);
                if (!$old) return ['status' => 'error', 'message' => 'Fiche introuvable.'];
                $sets = []; $vals = []; $avant = []; $apres = [];
                foreach (['nom', 'dci', 'dosage', 'forme'] as $f) {
                    if (!array_key_exists($f, $in)) continue;
                    $new = trim((string)$in[$f]);
                    if ($f === 'nom' && $new === '') continue;
                    $newVal = ($new === '') ? null : mb_substr($new, 0, ($f === 'dci' ? 150 : 100));
                    if ((string)($old[$f] ?? '') === (string)($newVal ?? '')) continue;
                    $sets[] = "$f = ?"; $vals[] = $newVal;
                    $avant[$f] = $old[$f]; $apres[$f] = $newVal;
                }
                if (!$sets) return ['status' => 'error', 'message' => 'Aucune modification.'];
                $vals[] = $user_id; $vals[] = $pid;
                $pdo->prepare("UPDATE produits SET " . implode(', ', $sets) . ", modifie_par = ?, modifie_le = NOW() WHERE id = ?")
                    ->execute($vals);
                audit_log($pdo, 'MODIF_PRODUIT', 'produits', $pid, $avant, $apres + ['via' => 'assistant_ia']);
                return ['status' => 'success', 'message' => 'Fiche « ' . ($apres['nom'] ?? $old['nom']) . ' » corrigée.', 'lien' => '/products.php'];
            }

            case 'sse_ajuster_conso': {
                if (function_exists('canEdit') && !canEdit())
                    return ['status' => 'error', 'message' => "Permission refusée (SSE)."];
                $cid = (int)($in['conso_id'] ?? 0);
                $st = $pdo->prepare("SELECT id, nom, quantite FROM sse_consommables WHERE id = ? AND site_id = ?");
                $st->execute([$cid, $site_id]);
                $c = $st->fetch(PDO::FETCH_ASSOC);
                if (!$c) return ['status' => 'error', 'message' => 'Consommable SSE introuvable.'];
                $avant = (float)$c['quantite'];
                $hasQ = array_key_exists('quantite', $in) && $in['quantite'] !== null && $in['quantite'] !== '';
                $hasD = array_key_exists('delta', $in)    && $in['delta']    !== null && $in['delta']    !== '';
                if (!$hasQ && !$hasD) return ['status' => 'error', 'message' => 'Quantité ou variation manquante.'];
                $apres = $hasQ ? (float)$in['quantite'] : $avant + (float)$in['delta'];
                if ($apres < 0) $apres = 0;
                $pdo->prepare("UPDATE sse_consommables SET quantite = ?, modifie_le = NOW() WHERE id = ? AND site_id = ?")
                    ->execute([$apres, $cid, $site_id]);
                audit_log($pdo, 'SSE_CONSO_AJUST_IA', 'sse_consommables', $cid, ['quantite' => $avant], ['quantite' => $apres, 'via' => 'assistant_ia']);
                $fmt = fn($n) => rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
                return ['status' => 'success', 'message' => $c['nom'] . ' (SSE) : quantité ajustée à ' . $fmt($apres) . '.', 'lien' => '/sse.php'];
            }

            case 'sse_controle_equipement': {
                if (function_exists('canEdit') && !canEdit())
                    return ['status' => 'error', 'message' => "Permission refusée (SSE)."];
                $eid = (int)($in['equipement_id'] ?? 0);
                $res = strtolower(trim($in['resultat'] ?? ''));
                if (!in_array($res, ['ok', 'anomalie', 'hors_service'], true))
                    return ['status' => 'error', 'message' => 'Résultat invalide.'];
                $st = $pdo->prepare("SELECT id, nom FROM sse_equipements WHERE id = ? AND site_id = ? AND actif = 1");
                $st->execute([$eid, $site_id]);
                $e = $st->fetch(PDO::FETCH_ASSOC);
                if (!$e) return ['status' => 'error', 'message' => 'Équipement SSE introuvable.'];
                $per      = (int)($in['periodicite_mois'] ?? 0);
                $notes    = trim($in['notes'] ?? '') ?: null;
                $prochain = $per > 0 ? date('Y-m-d', strtotime("+$per months")) : null;
                $mapStatut = ['ok' => 'operationnel', 'hors_service' => 'hs', 'anomalie' => 'revision'][$res];
                $pdo->beginTransaction();
                $pdo->prepare("INSERT INTO sse_controles (equipement_id, site_id, type_controle, date_controle, prochain_controle, periodicite_mois, resultat, operateur_id, notes)
                               VALUES (?, ?, 'Contrôle via assistant IA', CURDATE(), ?, ?, ?, ?, ?)")
                    ->execute([$eid, $site_id, $prochain, ($per > 0 ? $per : null), $res, $user_id, $notes]);
                $pdo->prepare("UPDATE sse_equipements SET statut = ?, date_prochain_ctrl = COALESCE(?, date_prochain_ctrl), modifie_le = NOW() WHERE id = ? AND site_id = ?")
                    ->execute([$mapStatut, $prochain, $eid, $site_id]);
                $pdo->commit();
                audit_log($pdo, 'SSE_CONTROLE_IA', 'sse_equipements', $eid, null, ['resultat' => $res, 'statut' => $mapStatut, 'via' => 'assistant_ia']);
                return ['status' => 'success', 'message' => 'Contrôle enregistré pour ' . $e['nom'] . ' (' . $res . ').', 'lien' => '/sse.php'];
            }

            case 'completer_dotation': {
                if (function_exists('action_permise') && !action_permise('dotation'))
                    return ['status' => 'error', 'message' => 'Permission refusée (dotation).'];
                require_once __DIR__ . '/../includes/dotation.php';
                $eid = (int)($in['emplacement_id'] ?? 0);
                $r = dotation_completer($pdo, $eid, $site_id, $user_id);
                if (($r['status'] ?? '') !== 'success') return ['status' => 'error', 'message' => $r['message'] ?? 'Erreur.'];
                return ['status' => 'success', 'message' => $r['message'], 'lien' => '/locations.php?sel=' . $eid];
            }

            case 'signaler_biomed': {
                $mid   = (int)($in['materiel_id'] ?? 0);
                $titre = mb_substr(trim($in['titre'] ?? ''), 0, 200);
                $desc  = trim($in['description'] ?? '') ?: null;
                $prio  = strtolower(trim($in['priorite'] ?? 'normale'));
                if (!in_array($prio, ['normale', 'haute', 'urgente'], true)) $prio = 'normale';
                if ($titre === '') return ['status' => 'error', 'message' => 'Titre manquant.'];
                $st = $pdo->prepare("SELECT id, nom FROM materiels WHERE id = ? AND site_id = ?");
                $st->execute([$mid, $site_id]);
                $mat = $st->fetch(PDO::FETCH_ASSOC);
                if (!$mat) return ['status' => 'error', 'message' => 'Matériel introuvable.'];
                $pdo->prepare("INSERT INTO materiel_demandes (materiel_id, demandeur_id, titre, description, priorite, statut)
                               VALUES (?, ?, ?, ?, ?, 'en_attente')")
                    ->execute([$mid, $user_id, $titre, $desc, $prio]);
                $did = (int)$pdo->lastInsertId();
                audit_log($pdo, 'DEMANDE_BIOMED_IA', 'materiel_demandes', $did, null, ['materiel' => $mat['nom'], 'priorite' => $prio]);
                return ['status' => 'success', 'message' => 'Demande biomédicale créée pour ' . $mat['nom'] . ' (priorité ' . $prio . ').'];
            }

            case 'creer_alerte': {
                $pid    = (int)($in['produit_id'] ?? 0);
                $type   = strtolower(trim($in['type'] ?? ''));
                $niveau = strtolower(trim($in['niveau'] ?? 'avertissement'));
                $msg    = mb_substr(trim($in['message'] ?? ''), 0, 255) ?: null;
                if (!in_array($type, ['peremption', 'stock_bas', 'chaine_froid'], true))
                    return ['status' => 'error', 'message' => "Type d'alerte invalide."];
                if (!in_array($niveau, ['ok', 'avertissement', 'critique', 'perime'], true)) $niveau = 'avertissement';
                $st = $pdo->prepare("SELECT pr.id, pr.nom FROM produits pr JOIN emplacements e ON e.id = pr.emplacement_id
                                     WHERE pr.id = ? AND e.site_id = ?");
                $st->execute([$pid, $site_id]);
                $p = $st->fetch(PDO::FETCH_ASSOC);
                if (!$p) return ['status' => 'error', 'message' => 'Produit introuvable.'];
                $pdo->prepare("INSERT INTO alertes (produit_id, type, niveau, message) VALUES (?, ?, ?, ?)")
                    ->execute([$pid, $type, $niveau, $msg]);
                $aid = (int)$pdo->lastInsertId();
                audit_log($pdo, 'ALERTE_PRODUIT_IA', 'alertes', $aid, null, ['produit' => $p['nom'], 'type' => $type]);
                return ['status' => 'success', 'message' => 'Alerte « ' . $type . ' » créée pour ' . $p['nom'] . '.'];
            }

            case 'generer_rapport': {
                return ['status' => 'success', 'message' => 'Rapport prêt.', 'lien' => '/export_pdf.php'];
            }
        }
        return ['status' => 'error', 'message' => 'Action inconnue : ' . $name];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['status' => 'error', 'message' => 'Erreur : ' . $e->getMessage()];
    }
}

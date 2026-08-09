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
    ];
}

/** Vrai si l'outil est en LECTURE seule (exécutable sans confirmation). */
function ai_tool_is_read(string $name): bool {
    return in_array($name, [
        'lister_emplacements', 'chercher_stock', 'produits_peremption',
        'etat_materiel', 'rappels_actifs', 'dernieres_fiches', 'chercher_medicament',
        'chercher_materiel',
    ], true);
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
                $sql = "SELECT pr.id, pr.nom, pr.quantite, pr.unite, pr.numero_lot,
                               pr.date_peremption, e.id AS emplacement_id, e.nom AS emplacement
                        FROM produits pr
                        JOIN emplacements e ON e.id = pr.emplacement_id
                        WHERE e.site_id = ? AND pr.statut = 'actif'";
                $args = [$site_id];
                if (!empty($in['nom'])) {
                    $sql .= " AND LOWER(pr.nom) LIKE ?";
                    $args[] = '%' . strtolower($in['nom']) . '%';
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
                $st = $pdo->prepare("SELECT nom_commercial, dci, dosages, unite
                                     FROM ai_medicaments
                                     WHERE actif = 1 AND (nom_commercial LIKE ? OR dci LIKE ?)
                                     LIMIT 25");
                $st->execute(['%' . $t . '%', '%' . $t . '%']);
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
    return ['rempotage', 'marquer_produit_retire', 'signaler_biomed', 'creer_alerte', 'generer_rapport'];
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

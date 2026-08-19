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

/**
 * Cockpit « prêt à partir » : complétude de tous les emplacements rattachés à
 * une dotation, triés du moins complet au plus complet.
 */
function dotation_cockpit(PDO $pdo, int $site_id): array {
    $sacs = [];
    $q = $pdo->prepare("SELECT id, nom FROM emplacements
                        WHERE site_id = ? AND actif = 1 AND dotation_modele_id IS NOT NULL
                        ORDER BY nom");
    $q->execute([$site_id]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $e) {
        $ec = dotation_ecarts($pdo, (int)$e['id'], $site_id);
        if (!$ec) continue;
        $sacs[] = [
            'id'          => (int)$e['id'],
            'nom'         => $e['nom'],
            'completude'  => $ec['stats']['completude'],
            'manquant'    => $ec['stats']['manquant'],
            'insuffisant' => $ec['stats']['insuffisant'],
            'total'       => $ec['stats']['total'],
        ];
    }
    usort($sacs, fn($a, $b) => $a['completude'] <=> $b['completude']);
    return $sacs;
}

/**
 * Complète un sac depuis la PUI du site : pour chaque manque (manquant/insuffisant),
 * transfère la quantité voulue de la PUI (FEFO) vers le sac. Règle « autoriser +
 * signaler » : on prend ce qui est dispo, jamais de négatif, le manque restant est noté.
 * Tout est tracé dans mouvements_stock. Renvoie un bilan.
 */
function dotation_completer(PDO $pdo, int $emp_id, int $site_id, int $user_id): array {
    $ec = dotation_ecarts($pdo, $emp_id, $site_id);
    if (!$ec) return ['status' => 'error', 'message' => "Ce sac n'a pas de dotation définie."];

    $besoins = [];
    foreach ($ec['lignes'] as $l) {
        $need = (float)$l['quantite_attendue'] - (float)$l['qte_reel'];
        if (in_array($l['statut'], ['manquant', 'insuffisant'], true) && $need > 0.0001) {
            $besoins[] = [
                'nom' => $l['nom'], 'dci' => $l['dci'], 'dosage' => $l['dosage'],
                'attendu' => (float)$l['quantite_attendue'], 'need' => $need,
                'cle' => dotation_cle($l['nom'], $l['dosage']),
            ];
        }
    }
    if (!$besoins) return ['status' => 'success', 'message' => 'Ce sac est déjà conforme.', 'ajoutes' => [], 'manques' => []];

    // PUI(s) du site + sous-emplacements
    $q = $pdo->prepare("SELECT id FROM emplacements WHERE site_id = ? AND type = 'pui' AND actif = 1");
    $q->execute([$site_id]);
    $pui_ids = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    $frontier = $pui_ids; $guard = 0;
    while ($frontier && $guard++ < 12) {
        $in = implode(',', array_map('intval', $frontier));
        $c = $pdo->prepare("SELECT id FROM emplacements WHERE site_id = ? AND parent_id IN ($in)");
        $c->execute([$site_id]);
        $ch = array_map('intval', $c->fetchAll(PDO::FETCH_COLUMN));
        $frontier = array_values(array_diff($ch, $pui_ids));
        $pui_ids  = array_merge($pui_ids, $frontier);
    }
    if (!$pui_ids || in_array($emp_id, $pui_ids, true))
        return ['status' => 'error', 'message' => "Aucune PUI configurée sur ce site (ou l'emplacement est lui-même une PUI)."];
    $inPui = implode(',', array_map('intval', $pui_ids));

    $ajoutes = []; $manques = [];
    try {
        $pdo->beginTransaction();
        foreach ($besoins as $b) {
            $lots = $pdo->query("SELECT id,nom,dci,dosage,forme,unite,quantite,numero_lot,date_peremption,alerte_jours,chaine_froid,gtin,ean
                                 FROM produits WHERE emplacement_id IN ($inPui) AND statut='actif' AND quantite > 0
                                 ORDER BY date_peremption ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
            $reste = $b['need']; $pris = 0;
            foreach ($lots as $lot) {
                if ($reste <= 0) break;
                if (dotation_cle($lot['nom'], $lot['dosage']) !== $b['cle']) continue;
                $dispo = (float)$lot['quantite'];
                $deb = min($dispo, $reste);
                if ($deb <= 0) continue;
                $nqp = $dispo - $deb;
                $pdo->prepare("UPDATE produits SET quantite=? WHERE id=?")->execute([$nqp, $lot['id']]);
                $pdo->prepare("INSERT INTO mouvements_stock (produit_id,user_id,type,quantite_avant,quantite_delta,quantite_apres,motif)
                               VALUES (?,?,'sortie',?,?,?,'Complétion dotation — débit PUI')")
                    ->execute([(int)$lot['id'], $user_id, $dispo, -$deb, $nqp]);
                // Ajout au sac (même lot si déjà présent, sinon nouvelle ligne)
                $ex = $pdo->prepare("SELECT id, quantite FROM produits WHERE emplacement_id=? AND nom=? AND COALESCE(numero_lot,'')=COALESCE(?,'') AND statut='actif' LIMIT 1");
                $ex->execute([$emp_id, $lot['nom'], $lot['numero_lot']]);
                $exr = $ex->fetch(PDO::FETCH_ASSOC);
                if ($exr) {
                    $nq = (float)$exr['quantite'] + $deb;
                    $pdo->prepare("UPDATE produits SET quantite=?, modifie_par=?, modifie_le=NOW() WHERE id=?")->execute([$nq, $user_id, $exr['id']]);
                    $pdo->prepare("INSERT INTO mouvements_stock (produit_id,user_id,type,quantite_avant,quantite_delta,quantite_apres,motif)
                                   VALUES (?,?,'entree',?,?,?,'Complétion dotation — depuis PUI')")
                        ->execute([(int)$exr['id'], $user_id, (float)$exr['quantite'], $deb, $nq]);
                } else {
                    $pdo->prepare("INSERT INTO produits (emplacement_id,nom,dci,dosage,forme,numero_lot,quantite,quantite_min,unite,date_peremption,alerte_jours,chaine_froid,gtin,ean,statut,cree_par)
                                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,'actif',?)")
                        ->execute([$emp_id, $lot['nom'], $lot['dci'], $lot['dosage'], $lot['forme'], $lot['numero_lot'], $deb, $b['attendu'], $lot['unite'], $lot['date_peremption'], $lot['alerte_jours'], $lot['chaine_froid'], $lot['gtin'], $lot['ean'], $user_id]);
                    $nid = (int)$pdo->lastInsertId();
                    $pdo->prepare("INSERT INTO mouvements_stock (produit_id,user_id,type,quantite_avant,quantite_delta,quantite_apres,motif)
                                   VALUES (?,?,'entree',0,?,?,'Complétion dotation — depuis PUI')")
                        ->execute([$nid, $user_id, $deb, $deb]);
                }
                $reste -= $deb; $pris += $deb;
            }
            $fmt = fn($n) => rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
            $label = trim(($b['dci'] ?: $b['nom']) . ' ' . ($b['dosage'] ?? ''));
            if ($pris > 0)          $ajoutes[] = $label . ' ×' . $fmt($pris);
            if ($reste > 0.0001)    $manques[] = $label . ' (manque ' . $fmt($reste) . ')';
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['status' => 'error', 'message' => 'Erreur : ' . $e->getMessage()];
    }
    if (function_exists('audit_log')) {
        audit_log($pdo, 'DOTATION_COMPLETION_PUI', 'emplacements', $emp_id, null, ['ajoutes' => count($ajoutes), 'manques' => count($manques)]);
    }
    $msg = $ajoutes ? (count($ajoutes) . ' produit(s) complété(s) depuis la PUI') : 'Rien à compléter depuis la PUI';
    if ($manques) $msg .= ' · ' . count($manques) . ' encore en manque (PUI insuffisante)';
    return ['status' => 'success', 'message' => $msg, 'ajoutes' => $ajoutes, 'manques' => $manques];
}

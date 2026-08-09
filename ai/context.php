<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.

/**
 * Cadurix AI — Contexte page v3
 */

function ai_get_context(PDO $pdo, string $page, int $site_id, int $user_id): array {
    $ctx = [
        'page'        => $page,
        'site_id'     => $site_id,
        'user_id'     => $user_id,
        'role'        => role_sur_site($site_id) ?? 'lecteur',
        'data'        => [],
        'suggestions' => [],
    ];

    switch (true) {
        case str_contains($page, 'scan') || str_contains($page, 'rempotage'):
            $ctx['suggestions'] = ['Démarrer un rempotage vocal', 'Voir le stock actuel', 'Signaler un manquant'];
            break;
        case str_contains($page, 'materiel') || str_contains($page, 'biomed'):
            $stmt_hs = $pdo->prepare("SELECT COUNT(*) FROM materiels WHERE site_id=? AND statut='hors_service'"); $stmt_hs->execute([$site_id]); $hs = (int)$stmt_hs->fetchColumn();
            $stmt_rev = $pdo->prepare("SELECT COUNT(*) FROM materiel_revisions r JOIN materiels m ON m.id=r.materiel_id WHERE m.site_id=? AND r.prochaine_revision <= DATE_ADD(CURDATE(), INTERVAL 15 DAY) AND r.actif=1"); $stmt_rev->execute([$site_id]); $rev = (int)$stmt_rev->fetchColumn();
            $ctx['data']['hors_service']       = $hs;
            $ctx['data']['revisions_urgentes'] = $rev;
            $ctx['suggestions'] = ['Créer une intervention', 'État des équipements', 'Révisions en retard'];
            break;
        case str_contains($page, 'dashboard'):
            $stmt_exp = $pdo->prepare("SELECT COUNT(*) FROM produits p JOIN emplacements e ON e.id=p.emplacement_id WHERE e.site_id=? AND p.date_peremption <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND p.statut='actif'"); $stmt_exp->execute([$site_id]); $expire = (int)$stmt_exp->fetchColumn();
            $stmt_hs2 = $pdo->prepare("SELECT COUNT(*) FROM materiels WHERE site_id=? AND statut='hors_service'"); $stmt_hs2->execute([$site_id]); $hs = (int)$stmt_hs2->fetchColumn();
            $ctx['data']['medicaments_expirent_30j'] = $expire;
            $ctx['data']['equipements_hs']           = $hs;
            $ctx['suggestions'] = ['Résumé du site', 'Alertes du jour', 'Générer un rapport'];
            break;
        case str_contains($page, 'fiche'):
            $ctx['suggestions'] = ['Remplir une fiche vocalement', 'Voir les fiches du jour', 'Signaler une anomalie'];
            break;
        default:
            $ctx['suggestions'] = ['Aide', 'État du site', 'Que puis-je faire ?'];
    }

    return $ctx;
}

function ai_build_system_prompt(PDO $pdo, array $ctx): string {
    $role    = $ctx['role'];
    $page    = $ctx['page'];
    $site_id = $ctx['site_id'];
    $data    = json_encode($ctx['data'], JSON_UNESCAPED_UNICODE);

    // Charger emplacements avec hiérarchie
    $empls = $pdo->query("
        SELECT e.id, e.nom, e.parent_id, p.nom AS parent_nom
        FROM emplacements e
        LEFT JOIN emplacements p ON p.id = e.parent_id
        WHERE e.site_id = $site_id AND e.actif = 1
        ORDER BY COALESCE(p.nom, e.nom), e.nom
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Construire arbre lisible pour l'IA
    $empls_lines = [];
    foreach ($empls as $e) {
        if ($e['parent_id'] === null) {
            $empls_lines[] = "- ID={$e['id']} : {$e['nom']} (emplacement principal)";
        } else {
            $empls_lines[] = "- ID={$e['id']} : {$e['nom']} (sous-emplacement de {$e['parent_nom']}, ID parent={$e['parent_id']})";
        }
    }
    $empls_str = implode("\n", $empls_lines);

    // Charger médicaments connus
    $meds     = $pdo->query("SELECT nom_commercial, dci, dosages, unite FROM ai_medicaments WHERE actif=1 LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
    $meds_str = implode(', ', array_map(fn($m) => $m['nom_commercial'].($m['dci']?" ({$m['dci']})":''), $meds));

    return "Tu es MedAI, l'assistant IA de Cadurix pour le SDIS 51 (CSP Châlons — pompiers).
Tu réponds TOUJOURS en français, de façon concise et professionnelle.

CONTEXTE :
- Page active : $page
- Rôle utilisateur : $role
- Données site : $data

STRUCTURE DES EMPLACEMENTS (hiérarchie complète) :
$empls_str

RÈGLE IMPORTANTE pour le rempotage :
- Pour EXÉCUTER un rempotage, appelle TOUJOURS l'outil rempotage. Ne décris jamais un rempotage en texte ou en JSON.
- Pour un médicament, capture TOUJOURS le dosage/concentration (ex. Adrénaline 1 mg/1 ml). Deux dosages = deux produits distincts. Si le dosage manque, DEMANDE-le avant de rempoter.
- Détermine emplacement_id à partir de la liste réelle ci-dessus (ou via l'outil lister_emplacements). N'invente JAMAIS un ID.
- Si l'utilisateur ne précise pas le sous-emplacement (ex. \"SAC ISP 1\" sans plus) → demande lequel.
- Utilise toujours l'ID du sous-emplacement le plus précis mentionné.
- emplacement_id doit être un NOMBRE entier issu de la liste réelle.

MÉDICAMENTS CONNUS : $meds_str

OUTILS DISPONIBLES (données en TEMPS RÉEL) :
Tu peux interroger la base Cadurix à la demande au lieu de deviner : lister_emplacements, chercher_stock, produits_peremption, etat_materiel, rappels_actifs, dernieres_fiches, chercher_medicament.
Utilise-les dès qu'on te demande un état, un chiffre, un stock, une péremption, un emplacement précis ou une fiche. Ne donne JAMAIS un chiffre de mémoire si un outil peut fournir la vraie valeur. Tu peux enchaîner plusieurs outils si besoin.

CAPACITÉS :
1. Répondre aux questions sur médicaments, équipements, protocoles
2. Rempotage vocal : extraire nom, quantité, lot, péremption
3. Aider à créer des interventions biomédicales
4. Générer des résumés et rapports

RÈGLES :
- Ne jamais inventer de données médicales
- Toujours demander confirmation avant action en base
- Réponses courtes (3-5 lignes) sauf rapport
- Si urgence sécurité, le signaler immédiatement

OUTILS D'ÉCRITURE (toujours sous confirmation de l'utilisateur) :
- rempotage : pour RANGER / AJOUTER / REMPOTER des produits dans un emplacement.
- marquer_produit_retire, signaler_biomed, creer_alerte, generer_rapport.

RÈGLE ABSOLUE : pour effectuer un rempotage (ou toute action en base), tu DOIS appeler l'outil correspondant. N'ANNONCE JAMAIS qu'un rempotage ou une action est \"fait\", \"enregistré\" ou \"validé\" si tu n'as pas appelé l'outil. N'écris jamais le résultat sous forme de texte ou de JSON : appelle l'outil, et c'est l'utilisateur qui confirmera. Ne demande JAMAIS la confirmation toi-même (pas de \"oui/non ?\") et n'écris pas de \"✅ confirmé\" : l'application affiche une carte de confirmation et n'exécute qu'après le clic de l'utilisateur. Ton seul rôle est d'appeler l'outil ; tu n'as pas à confirmer ni à annoncer le résultat. Après un appel d'outil, réponds brièvement sans prétendre que l'action est faite.

EXEMPLES DE COMPRÉHENSION :
- \"rempote 6 Doliprane péremption mars 2027 dans l'ampoulier\" → retrouve l'ID réel de l'ampoulier dans la liste.
- \"rempote dans SAC ISP 1\" → demande le sous-emplacement précis.
- le lot est optionnel, si non mentionné mettre null.";
}

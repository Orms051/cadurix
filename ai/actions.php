<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.

/**
 * Cadurix AI — Actions automatiques v4
 * Adapté à la structure réelle de rempotage_articles
 */

function ai_execute_action(PDO $pdo, array $action, int $site_id, int $user_id): ?array {
    $type = $action['action'] ?? '';

    switch ($type) {

        case 'rempotage':
            $empl_id  = (int)($action['emplacement_id'] ?? 0);
            $produits = $action['produits'] ?? [];
            if (!$empl_id || empty($produits)) return null;

            $empl = $pdo->prepare("SELECT id, nom FROM emplacements WHERE id=? AND site_id=? AND actif=1");
            $empl->execute([$empl_id, $site_id]);
            $empl = $empl->fetch(PDO::FETCH_ASSOC);
            if (!$empl) return ['action'=>'rempotage','status'=>'error','message'=>'Emplacement non trouvé (ID:'.$empl_id.')'];

            return [
                'action'         => 'rempotage',
                'status'         => 'pending_confirm',
                'emplacement'    => $empl,
                'emplacement_id' => $empl_id,
                'produits'       => $produits,
                'message'        => 'Rempotage prêt — confirmation requise',
            ];

        case 'rempotage_confirme':
            $empl_id  = (int)($action['emplacement_id'] ?? $action['emplacement']['id'] ?? 0);
            $produits = $action['produits'] ?? [];

            if (!$empl_id) return ['action'=>'rempotage_confirme','status'=>'error','message'=>'Emplacement ID manquant'];
            if (empty($produits)) return ['action'=>'rempotage_confirme','status'=>'error','message'=>'Aucun produit'];

            $empl = $pdo->prepare("SELECT id, nom FROM emplacements WHERE id=? AND site_id=? AND actif=1");
            $empl->execute([$empl_id, $site_id]);
            $empl = $empl->fetch(PDO::FETCH_ASSOC);
            if (!$empl) return ['action'=>'rempotage_confirme','status'=>'error','message'=>'Emplacement introuvable'];

            $nb = 0;
            try {
                $pdo->beginTransaction();

                // Créer rempotage
                $pdo->prepare("INSERT INTO rempotages (emplacement_id, user_id, site_id, statut) VALUES(?,?,?,'valide')")
                    ->execute([$empl_id, $user_id, $site_id]);
                $rempotage_id = (int)$pdo->lastInsertId();
                if (!$rempotage_id) throw new Exception('Impossible de créer le rempotage');

                foreach ($produits as $p) {
                    $nom    = trim($p['nom'] ?? '');
                    $lot    = trim($p['lot'] ?? '') ?: null;
                    $qte    = (float)($p['quantite'] ?? 1);
                    $peremp = trim($p['peremption'] ?? '');

                    if (!$nom) continue;

                    // Convertir date péremption
                    $date_sql = null;
                    if ($peremp) {
                        if (preg_match('#^(\d{2})/(\d{4})$#', $peremp, $m)) {
                            $date_sql = $m[2].'-'.$m[1].'-01';
                        } elseif (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $peremp, $m)) {
                            $date_sql = $m[3].'-'.$m[2].'-'.$m[1];
                        } elseif (preg_match('#^\d{4}-\d{2}-\d{2}$#', $peremp)) {
                            $date_sql = $peremp;
                        }
                    }

                    // Chercher produit existant dans produits
                    $existing = $pdo->prepare("SELECT id, quantite FROM produits WHERE emplacement_id=? AND LOWER(nom) LIKE ? AND statut='actif' LIMIT 1");
                    $existing->execute([$empl_id, '%'.strtolower($nom).'%']);
                    $existing = $existing->fetch(PDO::FETCH_ASSOC);

                    $produit_id = null;
                    if ($existing) {
                        $old_qte = (float)$existing['quantite'];
                        $new_qte = $old_qte + $qte;
                        $pdo->prepare("UPDATE produits SET quantite=?, numero_lot=COALESCE(?,numero_lot), date_peremption=COALESCE(?,date_peremption) WHERE id=?")
                            ->execute([$new_qte, $lot, $date_sql, $existing['id']]);
                        $pdo->prepare("INSERT INTO mouvements_stock (produit_id,user_id,type,quantite_avant,quantite_delta,quantite_apres,motif) VALUES(?,?,'entree',?,?,?,'Rempotage vocal IA')")
                            ->execute([$existing['id'], $user_id, $old_qte, $qte, $new_qte]);
                        $produit_id = $existing['id'];
                    } else {
                        $pdo->prepare("INSERT INTO produits (emplacement_id,nom,numero_lot,quantite,unite,date_peremption,alerte_jours,statut,cree_par) VALUES(?,?,?,?,'unité',?,30,'actif',?)")
                            ->execute([$empl_id, $nom, $lot, $qte, $date_sql, $user_id]);
                        $produit_id = (int)$pdo->lastInsertId();
                        $pdo->prepare("INSERT INTO mouvements_stock (produit_id,user_id,type,quantite_avant,quantite_delta,quantite_apres,motif) VALUES(?,?,'entree',0,?,?,'Rempotage vocal IA')")
                            ->execute([$produit_id, $user_id, $qte, $qte]);
                    }

                    // Insérer dans rempotage_articles avec les bonnes colonnes
                    $pdo->prepare("INSERT INTO rempotage_articles (rempotage_id, produit_id, action, nom, numero_lot, date_peremption, quantite, unite) VALUES(?,?,'cree',?,?,?,?,?)")
                        ->execute([$rempotage_id, $produit_id, $nom, $lot, $date_sql, $qte, 'unité']);

                    $nb++;
                }

                $pdo->commit();
                audit_log($pdo, 'REMPOTAGE_VOCAL_IA', 'rempotages', $rempotage_id, null, ['nb_produits'=>$nb]);

                return [
                    'action'  => 'rempotage_confirme',
                    'status'  => 'success',
                    'nb'      => $nb,
                    'message' => "$nb produit(s) enregistré(s) dans {$empl['nom']}.",
                    'lien'    => "/rempotages.php",
                ];

            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                return ['action'=>'rempotage_confirme','status'=>'error','message'=>'Erreur : '.$e->getMessage()];
            }

        case 'rapport':
            return ['action'=>'rapport','status'=>'redirect','lien'=>'/export_pdf.php'];

        default:
            return null;
    }
}

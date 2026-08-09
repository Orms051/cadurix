<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.

/**
 * Cadurix — Abonnements aux alertes mail
 * Résout QUI reçoit un type d'alerte (rôles abonnés + personnes précises), par site.
 * Utilisé par send_alerts.php (envoi manuel) et par le cron (envoi auto).
 */

/** Types d'alerte gérés (clé => libellé). */
function alertes_types(): array {
    return [
        'peremption'   => '⏰ Péremption',
        'stock_bas'    => '📦 Stock bas',
        'chaine_froid' => '❄️ Chaîne du froid',
        'systeme'      => '🔔 Système / compte',
    ];
}

/** Rôles ciblables (clé => libellé). Source unique = table role_permissions (hors super_admin, global). */
function alertes_roles(PDO $pdo): array {
    try {
        $rows = $pdo->query("SELECT DISTINCT role FROM role_permissions WHERE role <> 'super_admin'")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { $rows = []; }
    if (!$rows) $rows = ['administrateur','gestionnaire','saisie','lecteur','biomedical','sse'];
    $ordre = ['administrateur','gestionnaire','saisie','lecteur','biomedical','sse'];
    usort($rows, function($a, $b) use ($ordre) {
        $ia = array_search($a, $ordre); $ib = array_search($b, $ordre);
        $ia = $ia === false ? 99 : $ia; $ib = $ib === false ? 99 : $ib;
        return $ia <=> $ib ?: strcmp($a, $b);
    });
    $out = [];
    foreach ($rows as $r) $out[$r] = function_exists('roleLabel') ? roleLabel($r) : ucfirst($r);
    return $out;
}

/**
 * Destinataires (dédoublonnés) d'un type d'alerte sur un site.
 * @return array liste de ['id','prenom','nom','email']
 */
function destinataires_alerte(PDO $pdo, int $site_id, string $type): array {
    $out = []; // id => infos (dédoublonnage automatique par id)

    try {
        // 1) Rôles abonnés → utilisateurs de ce rôle sur le site
        $rs = $pdo->prepare("SELECT cible_valeur FROM alertes_abonnements
                             WHERE site_id=? AND type_alerte=? AND cible_type='role' AND actif=1");
        $rs->execute([$site_id, $type]);
        $roles = $rs->fetchAll(PDO::FETCH_COLUMN);

        if ($roles) {
            $ph = implode(',', array_fill(0, count($roles), '?'));
            $q = $pdo->prepare("SELECT DISTINCT u.id, u.prenom, u.nom, u.email
                                FROM utilisateurs u
                                JOIN user_site_permissions usp ON usp.user_id = u.id
                                WHERE usp.site_id = ? AND usp.role IN ($ph)
                                  AND u.actif = 1 AND u.email IS NOT NULL AND u.email <> ''");
            $q->execute(array_merge([$site_id], $roles));
            foreach ($q as $r) $out[(int)$r['id']] = $r;
        }

        // 2) Personnes précises abonnées
        $us = $pdo->prepare("SELECT cible_valeur FROM alertes_abonnements
                             WHERE site_id=? AND type_alerte=? AND cible_type='user' AND actif=1");
        $us->execute([$site_id, $type]);
        $uids = array_map('intval', $us->fetchAll(PDO::FETCH_COLUMN));

        if ($uids) {
            $ph = implode(',', array_fill(0, count($uids), '?'));
            $q = $pdo->prepare("SELECT u.id, u.prenom, u.nom, u.email
                                FROM utilisateurs u
                                WHERE u.id IN ($ph)
                                  AND u.actif = 1 AND u.email IS NOT NULL AND u.email <> ''");
            $q->execute($uids);
            foreach ($q as $r) $out[(int)$r['id']] = $r;
        }
    } catch (Throwable $e) { /* silencieux : pas d'envoi plutôt qu'une erreur */ }

    return array_values($out);
}

/** Matrice des rôles abonnés : [type_alerte][role] = true. */
function abo_roles_matrix(PDO $pdo, int $site_id): array {
    $m = [];
    try {
        $st = $pdo->prepare("SELECT type_alerte, cible_valeur FROM alertes_abonnements
                             WHERE site_id=? AND cible_type='role' AND actif=1");
        $st->execute([$site_id]);
        foreach ($st as $r) $m[$r['type_alerte']][$r['cible_valeur']] = true;
    } catch (Throwable $e) {}
    return $m;
}

/** Personnes précises abonnées à un type (pour l'affichage). */
function abo_users(PDO $pdo, int $site_id, string $type): array {
    try {
        $st = $pdo->prepare("SELECT u.id, u.prenom, u.nom, u.email
                             FROM alertes_abonnements a
                             JOIN utilisateurs u ON u.id = a.cible_valeur
                             WHERE a.site_id=? AND a.type_alerte=? AND a.cible_type='user' AND a.actif=1
                             ORDER BY u.nom, u.prenom");
        $st->execute([$site_id, $type]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/** Liste des utilisateurs d'un site (pour le menu déroulant d'ajout). */
function abo_users_du_site(PDO $pdo, int $site_id): array {
    try {
        $st = $pdo->prepare("SELECT DISTINCT u.id, u.prenom, u.nom, u.email
                             FROM utilisateurs u
                             JOIN user_site_permissions usp ON usp.user_id = u.id
                             WHERE usp.site_id = ? AND u.actif = 1 AND u.email IS NOT NULL AND u.email <> ''
                             ORDER BY u.nom, u.prenom");
        $st->execute([$site_id]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

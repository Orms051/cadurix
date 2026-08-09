<?php
/**
 * Cadurix v5 — Journal d'audit
 */

function audit_log(
    PDO    $pdo,
    string $action,
    string $table_cible       = '',
    ?int   $enregistrement_id = null,
    mixed  $valeur_avant      = null,
    mixed  $valeur_apres      = null
): void {
    try {
        $user_id   = $_SESSION['user_id'] ?? null;
        $ip        = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
        $ua        = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

        $avant  = $valeur_avant  ? (is_string($valeur_avant)  ? $valeur_avant  : json_encode($valeur_avant,  JSON_UNESCAPED_UNICODE)) : null;
        $apres  = $valeur_apres  ? (is_string($valeur_apres)  ? $valeur_apres  : json_encode($valeur_apres,  JSON_UNESCAPED_UNICODE)) : null;

        $stmt = $pdo->prepare("
            INSERT INTO audit_log (user_id, action, table_cible, enregistrement_id, valeur_avant, valeur_apres, ip, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$user_id, $action, $table_cible, $enregistrement_id, $avant, $apres, $ip, $ua]);
    } catch (Exception $e) {
        // Ne jamais faire planter l'app à cause d'un log
        error_log("Audit log error: " . $e->getMessage());
    }
}

function audit_connexion(PDO $pdo, int $user_id, bool $succes): void {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
    $action = $succes ? 'CONNEXION_REUSSIE' : 'CONNEXION_ECHEC';

    $stmt = $pdo->prepare("
        INSERT INTO audit_log (user_id, action, ip, user_agent)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$user_id, $action, $ip, $ua]);
}

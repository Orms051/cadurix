<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.

/**
 * Cadurix — Enregistrement de connexion
 * ------------------------------------------------------------
 * Centralise la mise à jour de la « dernière connexion ».
 * Appelée par les DEUX chemins de login :
 *   - index.php      (connexion directe, sans 2FA)
 *   - verify_2fa.php (après validation du code OTP)
 * Un seul endroit à maintenir → comportement garanti identique.
 */

if (!function_exists('enregistrer_connexion')) {
    function enregistrer_connexion(PDO $pdo, int $user_id): void {
        try {
            $pdo->prepare("UPDATE utilisateurs
                           SET derniere_connexion = NOW(),
                               derniere_ip        = ?,
                               dernier_user_agent = ?,
                               nb_connexions      = nb_connexions + 1
                           WHERE id = ?")
                ->execute([
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                    $user_id,
                ]);
        } catch (Throwable $e) {
            // Silencieux : l'enregistrement ne doit JAMAIS bloquer la connexion.
        }
    }
}

if (!function_exists('journaliser_connexion')) {
    /**
     * Journalise une tentative de connexion dans connexions_historique.
     * ⚠ user_id est NOT NULL + clé étrangère vers utilisateurs → n'appeler
     * QU'AVEC l'id d'un utilisateur EXISTANT (une tentative sur un login
     * inexistant ne peut pas être journalisée ici).
     */
    function journaliser_connexion(PDO $pdo, int $user_id, bool $succes): void {
        if ($user_id <= 0) return;
        try {
            $pdo->prepare("INSERT INTO connexions_historique (user_id, ip, user_agent, succes)
                           VALUES (?, ?, ?, ?)")
                ->execute([
                    $user_id,
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                    $succes ? 1 : 0,
                ]);
        } catch (Throwable $e) { /* silencieux */ }
    }
}

USE `medexpiry`;

-- Migration 029 — Double authentification (2FA)
-- La colonne twofa_enabled existe déjà dans utilisateurs
-- On s'assure qu'elle est bien là et on crée la table otp_codes si besoin

-- Vérifier/créer table otp_codes
CREATE TABLE IF NOT EXISTS `otp_codes` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`    INT UNSIGNED NOT NULL,
  `code`       VARCHAR(255) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `used`       TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- S'assurer que twofa_enabled existe (au cas où ALTER TABLE 026 n'a pas été joué)
ALTER TABLE `utilisateurs`
  MODIFY COLUMN `twofa_enabled` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1 = double authentification par OTP email activée';

-- Par défaut tout le monde a 2FA désactivée
UPDATE `utilisateurs` SET `twofa_enabled` = 0 WHERE `twofa_enabled` IS NULL;

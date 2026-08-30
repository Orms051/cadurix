-- 042 : préférences du tableau de bord par utilisateur
-- (widgets : ordre, visibilité, densité). SQL standard MySQL 8.4 + MariaDB 11.4.
-- À jouer une seule fois.
CREATE TABLE IF NOT EXISTS user_dashboard_prefs (
  user_id    INT UNSIGNED NOT NULL,
  layout     MEDIUMTEXT   NULL,           -- JSON : {"order":[...],"hidden":[...],"density":"cosy"}
  updated_le DATETIME     NULL,
  PRIMARY KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

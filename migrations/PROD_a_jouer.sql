-- ============================================================
--  Cadurix — Modifications de base à jouer EN PROD (o2switch)
--  ------------------------------------------------------------
--  Regroupe TOUTES les modifications de base de cette série.
--  À exécuter dans phpMyAdmin o2switch, sur la base de prod
--  (royo5535_cadurix). Prod = MariaDB 11.4 → « IF NOT EXISTS »
--  rend le script SANS DANGER même s'il est rejoué (colonnes
--  déjà présentes = ignorées).
--
--  ⚠ À jouer AVANT de mettre en ligne les fichiers PHP.
-- ============================================================

-- 030 — Type d'emplacement (prépare la PUI)
--   standard | pui | sac | vehicule  (pui = stock central du site)
ALTER TABLE `emplacements`
  ADD COLUMN IF NOT EXISTS `type` VARCHAR(20) NOT NULL DEFAULT 'standard'
    COMMENT 'standard | pui | sac | vehicule'
    AFTER `nom`;

-- 031 — Icône personnalisée par emplacement (emoji, optionnel)
ALTER TABLE `emplacements`
  ADD COLUMN IF NOT EXISTS `icone` VARCHAR(16) NULL DEFAULT NULL
    COMMENT 'Emoji d''icone choisi pour l''emplacement (optionnel)'
    AFTER `type`;

-- ============================================================
--  Fin. Après exécution : téléverser les fichiers PHP, puis
--  vérifier la page Emplacements + le rempotage.
-- ============================================================

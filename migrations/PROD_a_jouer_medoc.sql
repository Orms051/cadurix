-- ============================================================
-- PROD à jouer — Médicaments (migrations 033 + 034)
-- À exécuter dans phpMyAdmin o2switch, base `royo5535_cadurix`, onglet SQL.
-- ⚠️ Version PROD (MariaDB 11.4) : utilise IF NOT EXISTS pour n'ajouter que
--    les colonnes manquantes (certaines ont déjà pu être déployées).
--    NE PAS jouer tel quel en local MySQL 8.4 (voir 033/034 séparées pour ça).
--
-- Ajoute 3 colonnes descriptives à `produits` :
--   dci    → molécule (DCI), affichée en premier
--   dosage → ex. 1 mg/1 ml
--   forme  → ex. solution injectable
-- Purement additif, n'affecte aucune logique existante.
-- ============================================================

ALTER TABLE `produits`
  ADD COLUMN IF NOT EXISTS `dci`    VARCHAR(150) NULL DEFAULT NULL AFTER `nom`,
  ADD COLUMN IF NOT EXISTS `dosage` VARCHAR(100) NULL DEFAULT NULL AFTER `dci`,
  ADD COLUMN IF NOT EXISTS `forme`  VARCHAR(100) NULL DEFAULT NULL AFTER `dosage`;

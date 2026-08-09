-- Migration 030 — Type d'emplacement (préparation PUI)
--
-- Ajoute une colonne `type` sur `emplacements` pour distinguer la PUI
-- (pharmacie à usage intérieur / stock central du site) des emplacements
-- classiques (sacs, sacoches, véhicules).
--
-- ÉTAPE 1 du chantier PUI : cette migration ne fait qu'AJOUTER la colonne.
-- Aucune logique de rempotage/transfert n'est modifiée à ce stade.
-- Toutes les lignes existantes reçoivent 'standard' → comportement inchangé.
--
-- ⚠ Environnements : local = MySQL 8.4, prod = MariaDB 11.4.
--   On reste en SQL standard (pas de `ADD COLUMN IF NOT EXISTS`, propre à
--   MariaDB et refusé par MySQL). À exécuter UNE SEULE FOIS sur la base
--   Cadurix active (pas de `USE` codé en dur car le nom diffère selon l'env).

ALTER TABLE `emplacements`
  ADD COLUMN `type` VARCHAR(20) NOT NULL DEFAULT 'standard'
    COMMENT 'standard | pui | sac | vehicule — pui = stock central du site'
    AFTER `nom`;

-- Migration 031 — Icône personnalisée par emplacement
--
-- Permet de choisir une icône (emoji) par emplacement / compartiment, affichée
-- dans l'arbre des emplacements. Optionnel : si NULL, une icône par défaut est
-- utilisée (selon le type : PUI, sac, compartiment…).
--
-- SQL standard, compatible MySQL 8.4 (local) ET MariaDB 11.4 (prod).
-- À exécuter UNE SEULE FOIS sur la base Cadurix active.

ALTER TABLE `emplacements`
  ADD COLUMN `icone` VARCHAR(16) NULL DEFAULT NULL
    COMMENT 'Emoji d''icone choisi pour l''emplacement (optionnel)'
    AFTER `type`;

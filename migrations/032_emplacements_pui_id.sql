-- Migration 032 — Rattachement d'un emplacement à une PUI
--
-- Permet d'associer un sac / véhicule à la PUI qui le gère (multi-PUI par site).
-- L'arbre des emplacements groupe alors chaque PUI avec ses sacs rattachés.
-- NULL = non rattaché (ou l'emplacement EST une PUI). Purement organisationnel :
-- n'affecte PAS la logique de débit du rempotage (qui reste basée sur type='pui'
-- + site_id).
--
-- SQL standard, compatible MySQL 8.4 (local) ET MariaDB 11.4 (prod).
-- À exécuter UNE SEULE FOIS sur la base Cadurix active.

ALTER TABLE `emplacements`
  ADD COLUMN `pui_id` INT UNSIGNED NULL DEFAULT NULL
    COMMENT 'PUI de rattachement (emplacements.id de type pui) — NULL si non rattaché'
    AFTER `type`;

CREATE INDEX `idx_emplacements_pui` ON `emplacements` (`pui_id`);

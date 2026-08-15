-- Migration 034 — DCI (Dénomination Commune Internationale) du médicament
--
-- `nom` = nom commercial (princeps / générique). `dci` = molécule.
-- Affichage : DCI (nom commercial). Rempli notamment depuis medicaments_ref.dci.
-- SQL standard, compatible MySQL 8.4 (local) ET MariaDB 11.4 (prod).

ALTER TABLE `produits`
  ADD COLUMN `dci` VARCHAR(150) NULL DEFAULT NULL AFTER `nom`;

-- Migration 033 — Dosage & forme du médicament (fiche produit look démo)
--
-- Ajoute deux colonnes descriptives à `produits`, remplies notamment depuis
-- l'autocomplétion BDPM (medicaments_ref.dosage / .forme). Purement additif,
-- n'affecte aucune logique existante.
--
-- SQL standard, compatible MySQL 8.4 (local) ET MariaDB 11.4 (prod).
-- À exécuter UNE SEULE FOIS sur la base Cadurix active.

ALTER TABLE `produits`
  ADD COLUMN `dosage` VARCHAR(100) NULL DEFAULT NULL AFTER `nom`,
  ADD COLUMN `forme`  VARCHAR(100) NULL DEFAULT NULL AFTER `dosage`;

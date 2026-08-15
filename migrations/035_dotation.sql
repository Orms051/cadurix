-- Migration 035 — Dotation par sac (composition attendue vs réel)
--
-- Modèles de dotation RÉUTILISABLES : on définit une "dotation type" une fois
-- (ex. « SAC ISP standard »), puis on rattache autant de sacs qu'on veut.
-- Purement additif : aucune table/logique existante modifiée.
--
-- SQL standard, compatible MySQL 8.4 (local) ET MariaDB 11.4 (prod).
-- À jouer UNE SEULE FOIS. (Pour la prod, variante IF NOT EXISTS fournie à part.)

CREATE TABLE IF NOT EXISTS `dotation_modeles` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `site_id`     INT NOT NULL,
  `nom`         VARCHAR(150) NOT NULL,
  `description` VARCHAR(255) NULL DEFAULT NULL,
  `actif`       TINYINT(1) NOT NULL DEFAULT 1,
  `cree_par`    INT NULL DEFAULT NULL,
  `cree_le`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_dm_site` (`site_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `dotation_lignes` (
  `id`                INT AUTO_INCREMENT PRIMARY KEY,
  `modele_id`         INT NOT NULL,
  `nom`               VARCHAR(150) NOT NULL,
  `dci`               VARCHAR(150) NULL DEFAULT NULL,
  `dosage`            VARCHAR(100) NULL DEFAULT NULL,
  `quantite_attendue` DECIMAL(10,2) NOT NULL DEFAULT 1,
  `unite`             VARCHAR(30) NULL DEFAULT 'unité',
  `obligatoire`       TINYINT(1) NOT NULL DEFAULT 1,
  `ordre`             INT NOT NULL DEFAULT 0,
  INDEX `idx_dl_modele` (`modele_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Rattachement d'un emplacement (sac) à un modèle de dotation
ALTER TABLE `emplacements`
  ADD COLUMN `dotation_modele_id` INT NULL DEFAULT NULL;

-- 040 : tables du module Stupefiants (registre, controles de garde, validations)
-- SQL standard (MySQL 8.4 local + MariaDB 11.4 prod). A jouer une seule fois.

CREATE TABLE IF NOT EXISTS stupefiants_registre (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  produit_id INT UNSIGNED NOT NULL,
  site_id INT UNSIGNED NULL,
  type VARCHAR(20) NOT NULL,                 -- entree, administration, destruction, casse, perte
  qte_ampoules DECIMAL(10,2) NOT NULL DEFAULT 0,  -- delta (+/-)
  solde_apres DECIMAL(10,2) NULL,
  ouvert_mg DECIMAL(8,2) NULL,
  admin_mg DECIMAL(8,2) NULL,
  detruit_mg DECIMAL(8,2) NULL,
  intervention_ref VARCHAR(60) NULL,
  lot VARCHAR(100) NULL,
  date_exp DATE NULL,
  motif VARCHAR(255) NULL,
  periode CHAR(7) NULL,                       -- AAAA-MM
  user_id INT UNSIGNED NULL,
  cree_le DATETIME NOT NULL,
  INDEX idx_reg_produit (produit_id),
  INDEX idx_reg_periode (periode)
);

CREATE TABLE IF NOT EXISTS stupefiants_controles (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  produit_id INT UNSIGNED NOT NULL,
  site_id INT UNSIGNED NULL,
  jour DATE NOT NULL,
  moment VARCHAR(10) NOT NULL,               -- matin, soir
  theorique DECIMAL(10,2) NOT NULL,
  compte DECIMAL(10,2) NOT NULL,
  ecart DECIMAL(10,2) NOT NULL,
  statut VARCHAR(15) NOT NULL,               -- conforme, anomalie
  note VARCHAR(255) NULL,
  user_id INT UNSIGNED NULL,
  cree_le DATETIME NOT NULL,
  INDEX idx_ctrl_produit (produit_id),
  INDEX idx_ctrl_jour (jour)
);

CREATE TABLE IF NOT EXISTS stupefiants_validations (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  produit_id INT UNSIGNED NOT NULL,
  site_id INT UNSIGNED NULL,
  periode CHAR(7) NOT NULL,                   -- AAAA-MM
  solde_reference DECIMAL(10,2) NOT NULL,
  user_id INT UNSIGNED NULL,
  cree_le DATETIME NOT NULL,
  INDEX idx_val_produit (produit_id),
  INDEX idx_val_periode (periode)
);

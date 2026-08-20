-- 036 : distinguer medicament / materiel dans produits
-- SQL standard (MySQL 8.4 local + MariaDB 11.4 prod). A jouer une seule fois.
ALTER TABLE produits ADD COLUMN type_produit VARCHAR(20) NOT NULL DEFAULT 'medicament';

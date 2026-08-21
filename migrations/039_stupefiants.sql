-- 039 : fondations stupefiants (flag + configuration ampoule)
-- SQL standard (MySQL 8.4 local + MariaDB 11.4 prod). A jouer une seule fois.
-- La valeur 'coffre' pour emplacements.type ne necessite aucun changement (varchar).
ALTER TABLE produits ADD COLUMN stupefiant TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE produits ADD COLUMN dose_mg DECIMAL(8,2) NULL;
ALTER TABLE produits ADD COLUMN volume_ml DECIMAL(8,2) NULL;

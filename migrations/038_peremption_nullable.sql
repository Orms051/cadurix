-- 038 : la date de peremption devient facultative (materiel durable sans date)
-- SQL standard (MySQL 8.4 + MariaDB 11.4). A jouer une seule fois.
ALTER TABLE produits MODIFY COLUMN date_peremption DATE NULL;

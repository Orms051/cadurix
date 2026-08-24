-- 041 : logo de l'ORGANISATION (data URI base64) pour les impressions
-- (registre stupéfiants, déclarations casse/perte). SQL standard MySQL 8.4 + MariaDB 11.4.
ALTER TABLE organisations ADD COLUMN logo MEDIUMTEXT NULL;

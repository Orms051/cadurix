-- ============================================================
-- Correction douce des noms / DCI médicaments — CAS SÛRS UNIQUEMENT
-- À RELIRE avant de jouer. Testé en local le 2026-08-15.
--
-- N'inclut volontairement PAS :
--   - les dosages manquants (ex. Adrénaline nue) → jugement humain (quelle
--     concentration ?), à faire à la main ou via l'IA au cas par cas ;
--   - les fusions de doublons (Compresse/Compresses, variantes NaCl) → structurel.
-- ============================================================

-- 1) Coquille : « Bétardine » → « Bétadine »
UPDATE produits
   SET nom = REPLACE(nom, 'Bétardine', 'Bétadine')
 WHERE nom LIKE '%Bétardine%';

-- 2) Renseigner le champ DCI quand le nom EST déjà la molécule et que dci est vide.
--    (recopie la molécule dans dci ; le nom ne change pas)
UPDATE produits
   SET dci = nom
 WHERE (dci IS NULL OR dci = '')
   AND nom IN (
     'Acide acétylsalicylique',
     'Acide Tranexamique',
     'Adrénaline',
     'Clonazépam',
     'Kétoprofène',
     'Methylprednisolone',
     'Terbutaline'
   );

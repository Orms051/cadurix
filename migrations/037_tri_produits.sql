-- 037 : classification materiel + nettoyage des champs medicaments
-- Rejouable une fois (les UPDATE ciblent les noms d'origine ; apres passage ils ne re-matchent plus).
-- Teste en local (MySQL 8.4). A jouer en prod (MariaDB 11.4) via phpMyAdmin o2switch.

-- ================= PARTIE 1 : MATERIEL =================
UPDATE produits SET type_produit='materiel' WHERE nom IN (
  'Ballon 1L','Ballon 2L',
  'BAVU adulte','BAVU enfant','BAVU néonat',
  'Bombe de froid','Bouchon obturateur','Carnet Protocole',
  'Cathéter 16G','Cathéter 18G','Cathéter 20G','Cathéter 22G','Cathéter 24G',
  'Champ non stérile','Compresse x5','Compresses x5','Conteur à OPCT',
  'Couteau Bordeaux','Eolife','Fiche Intervention','FlowSense',
  'Garot tourniquet','Lampe stylo',
  'Masque nébulisation adulte','Masque nébulisation enfant',
  'Masque O2 adulte','Masque O2 enfant','Masque O2 pédiatrique',
  'Masque T2','Masque T3','Masque T4','Masque T5',
  'Pansement grand modèle','Pansement Hemostratic : CHITOSAM 100',
  'Pansement israélien : compressif','Pansement petit modèle','Pansement transparent',
  'Perfuseur de précision','Perfuseur Pédiatrique',
  'Prolongateur 3 voies','Robinet 3 voies','Sac DASRI',
  'Sacoche de perfusion n°1','Sacoche de perfusion n°2',
  'Seringue 1ml','Seringue 5ml','Seringue 10ml','Seringue 20ml',
  'SHA','Sparadrap','Stéthoscope',
  'Trocard 20G (rose)','Trocard 21G (vert)','Tubulures 3 voies'
);

-- ================= PARTIE 2 : CHAMPS MEDICAMENTS =================
-- (nom = marque/appellation, dci = molecule, dosage, forme)

-- Aspegic
UPDATE produits SET nom='Aspégic', dci='Acide acétylsalicylique', dosage='500mg'
  WHERE nom='Aspégic 500mg';

-- Buccolam = Midazolam
UPDATE produits SET dci='Midazolam' WHERE nom='Buccolam';
UPDATE produits SET nom='Buccolam', dci='Midazolam', dosage='10mg',  forme='Seringue préremplie' WHERE nom='Buccolam 10mg seringue préremplie';
UPDATE produits SET nom='Buccolam', dci='Midazolam', dosage='2,5mg', forme='Seringue préremplie' WHERE nom='Buccolam 2,5mg seringue préremplie';
UPDATE produits SET nom='Buccolam', dci='Midazolam', dosage='5mg',   forme='Seringue préremplie' WHERE nom='Buccolam 5mg seringue préremplie';
UPDATE produits SET nom='Buccolam', dci='Midazolam', dosage='7,5mg', forme='Seringue préremplie' WHERE nom='Buccolam 7,5mg seringue préremplie';

-- Rivotril = Clonazepam
UPDATE produits SET nom='Rivotril', dci='Clonazépam', dosage='1mg/1ml'
  WHERE nom='Clonazépam (Rivotril) 1mg/1ml';

-- Polaramine = Dexchlorpheniramine
UPDATE produits SET nom='Polaramine', dci='Dexchlorphéniramine', dosage='5mg/1ml'
  WHERE nom LIKE 'Dexchlorphéniramine (Polaramine)%';
UPDATE produits SET dci='Dexchlorphéniramine'
  WHERE nom='Dexchlorphéniramine' AND (dci IS NULL OR dci='');

-- Doliprane = Paracetamol
UPDATE produits SET nom='Doliprane', dci='Paracétamol', dosage='2,4%',  forme='Suspension buvable'
  WHERE nom LIKE 'DOLIPRANE 2,4%';
UPDATE produits SET nom='Doliprane', dci='Paracétamol', dosage='300mg', forme='Poudre pour solution buvable'
  WHERE nom LIKE 'DOLIPRANE 300 mg%';
UPDATE produits SET nom='Doliprane', dci='Paracétamol', dosage='500mg', forme='Comprimé'
  WHERE nom='Doliprane Cp 500mg';
UPDATE produits SET nom='Doliprane', dci='Paracétamol', dosage='100mg', forme='Suppositoire' WHERE nom='Doliprane Suppositoire 100mg';
UPDATE produits SET nom='Doliprane', dci='Paracétamol', dosage='150mg', forme='Suppositoire' WHERE nom='Doliprane Suppositoire 150mg';
UPDATE produits SET nom='Doliprane', dci='Paracétamol', dosage='200mg', forme='Suppositoire' WHERE nom='Doliprane Suppositoire 200mg';
UPDATE produits SET nom='Doliprane', dci='Paracétamol', dosage='300mg', forme='Suppositoire' WHERE nom='Doliprane Suppositoire 300mg';

-- Paracetamol Viatris (etiquette BDPM)
UPDATE produits SET nom='Paracétamol Viatris', dci='Paracétamol', dosage='1000mg', forme='Comprimé'
  WHERE nom LIKE 'PARACETAMOL VIATRIS%';

-- Paracetamol generiques : au moins la DCI
UPDATE produits SET dci='Paracétamol'
  WHERE nom IN ('Paracétamol Adulte','Paracétamol Enfant','Paracétamol gellule/cp (plaquette)','Paracétamol Suppo')
    AND (dci IS NULL OR dci='');
UPDATE produits SET forme='Suppositoire' WHERE nom='Paracétamol Suppo' AND (forme IS NULL OR forme='');

-- Exacyl = Acide tranexamique (normalise la casse)
UPDATE produits SET dci='Acide tranexamique' WHERE nom='Exacyl';

-- Ketoprofene
UPDATE produits SET nom='Kétoprofène', dci='Kétoprofène', dosage='100mg/4ml'
  WHERE nom='Kétoprofène 100mg/4ml';

-- Methylprednisolone
UPDATE produits SET nom='Méthylprednisolone', dci='Méthylprednisolone' WHERE nom='Methylprednisolone';
UPDATE produits SET nom='Méthylprednisolone', dci='Méthylprednisolone', dosage='120mg' WHERE nom='Méthylprednisolone 120mg';

-- Natispray / Trinitrine
UPDATE produits SET nom='Natispray', dci='Trinitrine', dosage='0,15mg/dose' WHERE nom='Natispray 0,15mg/dose';
UPDATE produits SET dci='Trinitrine' WHERE nom='Trinitrine' AND (dci IS NULL OR dci='');

-- Penthrox = Methoxyflurane
UPDATE produits SET nom='Penthrox', dci='Méthoxyflurane', dosage='99,9%'
  WHERE nom LIKE 'Penthrox 99,9%%';

-- Glucose (soluté) : DCI + dosage, on garde les noms
UPDATE produits SET dci='Glucose', dosage='30%' WHERE nom IN ('Glucosé 30%','Glucose 30% 10ml') AND (dosage IS NULL OR dosage='');
UPDATE produits SET dci='Glucose', dosage='5%'  WHERE nom IN ('Glucose 5%','Glucose 5% 10ml')   AND (dosage IS NULL OR dosage='');

-- Ipratropium (bronchodilatateur)
UPDATE produits SET dci='Ipratropium' WHERE nom IN ('Ipratropium adulte','Ipratropium enfant') AND (dci IS NULL OR dci='');

-- Sulfadiazine argentique (Flammazine)
UPDATE produits SET dci='Sulfadiazine argentique' WHERE nom='Sulfadiazine argentique (tube)' AND (dci IS NULL OR dci='');

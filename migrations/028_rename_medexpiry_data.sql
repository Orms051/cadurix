USE `medexpiry`;

-- Corriger le message de bienvenue
UPDATE `messages` SET 
  `sujet` = 'Bienvenue sur Cadurix v5 !',
  `corps` = 'Bonjour,\n\nBienvenue sur Cadurix v5.\n\nProchaines étapes :\n• Configurez vos organisations et sites dans Administration → Sites\n• Créez vos emplacements\n• Ajoutez vos médicaments ou importez via Excel\n• Assignez les accès dans Administration → Permissions\n\nMot de passe par défaut : Cadurix2025!\nPensez à le changer.\n\n— Cadurix v5'
WHERE `sujet` LIKE '%MedExpiry%';

-- Corriger l'email expéditeur dans parametres
UPDATE `parametres` SET `valeur` = 'noreply@cadurix.fr' 
WHERE `cle` = 'mail_from' AND `valeur` LIKE '%medexpiry%';

-- Corriger les protocoles IA
UPDATE `ai_protocoles` SET `contenu` = REPLACE(`contenu`, 'MedExpiry', 'Cadurix');
UPDATE `ai_protocoles` SET `contenu` = REPLACE(`contenu`, 'medexpiry', 'cadurix');

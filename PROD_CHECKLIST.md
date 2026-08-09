# Cadurix v5.4 — Checklist mise en production o2switch

## Avant de mettre en ligne

### Obligatoire
- [ ] Renommer `.htaccess.txt` → `.htaccess`
- [ ] Décommenter la règle HTTPS dans `.htaccess`
- [ ] Décommenter le header HSTS dans `.htaccess`
- [ ] Modifier `config/db.php` avec les credentials MySQL o2switch
- [ ] Générer clés VAPID sur vapidkeys.com → configurer dans `parametres`
- [ ] Configurer le cron o2switch pour `push_notify.php`
- [ ] Vérifier que `config/settings.json` est non accessible (protégé par .htaccess)
- [ ] Supprimer `generate_icons.php` de la racine (utilitaire one-shot)
- [ ] Supprimer `medexpiry.sql` de la racine (dump BDD sensible)
- [ ] Créer le dossier `uploads/` avec les bons droits (chmod 755)

### Recommandé
- [ ] Tester l'envoi d'email SMTP (Admin → Paramètres → Test email)
- [ ] Activer la 2FA sur le compte super admin
- [ ] Changer le mot de passe par défaut de tous les comptes de test
- [ ] Supprimer les comptes de test (gestionnaire@cadurix.test, etc.)
- [ ] Configurer les alertes email (Admin → Alertes)
- [ ] Vérifier le timeout de session (Admin → Paramètres → 30 min recommandé)

### Sécurité
- [ ] Tester que /config/ retourne 403
- [ ] Tester que /backups/ retourne 403
- [ ] Tester que /migrations/ retourne 403
- [ ] Tester que les .sql sont inaccessibles
- [ ] Vérifier les headers HTTP avec https://securityheaders.com

## Configuration Cron o2switch

### 1. Générer le token cron (une seule fois)
Exécuter en SQL :
```sql
INSERT INTO `parametres` (`cle`, `valeur`, `description`)
VALUES ('cron_token', SHA2(CONCAT(RAND(), NOW(), 'cadurix'), 256), 'Token sécurité cron jobs')
ON DUPLICATE KEY UPDATE `valeur` = VALUES(`valeur`);

SELECT `valeur` FROM `parametres` WHERE `cle` = 'cron_token';
```
Copier la valeur retournée → c'est votre CRON_TOKEN.

### 2. Configurer les tâches cron dans cPanel o2switch
Dans cPanel → Tâches cron, ajouter :

**Sauvegarde quotidienne (2h du matin)**
```
0 2 * * * curl -s "https://cadurix.fr/backup_auto.php?token=VOTRE_CRON_TOKEN" >> /dev/null 2>&1
```

**Rapport mensuel (1er du mois à 8h)**
```
0 8 1 * * curl -s "https://cadurix.fr/rapport_mensuel.php?token=VOTRE_CRON_TOKEN" >> /dev/null 2>&1
```

**Notifications push (toutes les 6 heures)**
```
0 */6 * * * curl -s "https://cadurix.fr/push_notify.php?token=VOTRE_CRON_TOKEN" >> /dev/null 2>&1
```

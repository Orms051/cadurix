# Cadurix

Application de gestion de matériel et de stock médical pour les **SDIS / SSSM**
(services d'incendie et de secours). Elle assure le suivi des médicaments et du
matériel répartis dans les sacs d'urgence, sacoches et véhicules, centre par centre.

## Fonctionnalités principales

- **Suivi des péremptions** — alertes configurables par produit et par lot
- **Emplacements hiérarchiques** — arborescence sacs / sacoches / véhicules, avec contrôle de scellé
- **Rempotage** — réapprovisionnement des sacs et traçabilité des mouvements de stock
- **Scanner de codes-barres** — lecture EAN et DataMatrix (GS1) via ZXing
- **Gestion multi-sites** — permissions par rôle et par centre
- **PWA** — installable sur mobile, utilisable sur le terrain
- **Exports** — PDF et Excel, rapports mensuels automatisés

## Stack technique

| Composant | Choix |
|---|---|
| Langage | PHP 8.3 |
| Base de données | MySQL 8.4 (accès via PDO, requêtes préparées) |
| Front-end | HTML / CSS / JavaScript vanilla — pas de build ni de bundler |
| Framework | Aucun (vanilla PHP) |
| Hébergement | o2switch (mutualisé) |

## Installation en local

Le projet est prévu pour tourner sous [Laragon](https://laragon.org/) (ou tout
environnement Apache + PHP 8.3 + MySQL 8.4).

1. Cloner le dépôt dans le répertoire web (par exemple `C:\laragon\www\cadurix`).

2. Créer la base de données, puis appliquer les scripts du dossier `migrations/`
   dans l'ordre numérique.

3. Créer le fichier de connexion `config/db.php` — **il n'est pas versionné**
   car il contient des identifiants :

   ```php
   <?php
   define('DB_HOST',    'localhost');
   define('DB_NAME',    'cadurix');
   define('DB_USER',    'root');
   define('DB_PASS',    '');
   define('DB_CHARSET', 'utf8mb4');
   ```

4. Ouvrir le site dans le navigateur et se connecter.

## Structure du projet

```
config/       Connexion BDD, rôles, permissions, modules, onglets admin
includes/     Bootstrap d'authentification, header, helpers partagés
admin/        Interface d'administration (utilisateurs, accès, paramètres)
api/          API interne et API publique versionnée (api/v1)
ai/           Assistant IA (identification photo, saisie vocale terrain)
scanner/      Lecture de codes-barres EAN / DataMatrix
sse/          Module SSE (consommables, équipements, contrôles)
migrations/   Scripts SQL d'évolution du schéma
libs/         Bibliothèques tierces (PHPMailer, JWT)
```

## Rôles et permissions

Les rôles sont hiérarchisés par niveau : chaque rôle hérite des droits des
niveaux inférieurs.

| Rôle | Niveau |
|---|---|
| `lecteur` | 1 |
| `saisie` / `biomedical` / `sse` | 2 |
| `gestionnaire` | 3 |
| `administrateur` | 4 |
| `super_admin` | 99 |

Les permissions fines sont stockées en base (`role_permissions`), avec
possibilité de surcharge par site (`site_role_permissions`).

## Mise en production

Voir [PROD_CHECKLIST.md](PROD_CHECKLIST.md) pour la procédure complète de
déploiement et la configuration des tâches cron.

## Contribuer

Les conventions du projet et les pièges connus sont documentés dans
[CLAUDE.md](CLAUDE.md) — à lire avant toute modification.

Points d'attention :

- Les fichiers sont en **CRLF** (fins de ligne Windows) — les conserver.
- Valider la syntaxe PHP et JavaScript avant de proposer une modification.
- Privilégier les petites étapes testables, en particulier sur les permissions.

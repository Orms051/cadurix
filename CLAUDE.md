# CLAUDE.md — Cadurix

Contexte projet pour Claude Code. À lire avant toute modification.

## 🎯 Le projet

**Cadurix v5** — application de gestion de matériel/stock médical pour les
**SDIS / SSSM** (services de secours français, pompiers). Gère les médicaments
et le matériel répartis dans des sacs d'urgence, sacoches, véhicules, par centre.

- **Dev unique** : Yoann Rolland (login `yrolland`, rôle `super_admin`)
- **Prod** : https://cadurix.fr — hébergée sur **o2switch** (mutualisé)
- **Local** : `C:\laragon\www\Cadurix\` (Laragon)
- **Déploiement** : manuel (FTP/gestionnaire de fichiers o2switch). Claude Code
  travaille en **local uniquement** ; c'est Yoann qui déploie et teste en prod.

## 🛠️ Stack technique

- **Local (Laragon)** : PHP 8.3, **MySQL 8.4**
- **Prod (o2switch)** : PHP 8.4, **MariaDB 11.4**
- ⚠️ **Écart local/prod** : le SGBD n'est pas le même. Éviter la syntaxe
  propre à MariaDB refusée par MySQL (ex. `ALTER TABLE … ADD COLUMN IF NOT
  EXISTS`) et inversement. Écrire les migrations en **SQL standard** et les
  jouer une seule fois. Les migrations sont testées en local, puis Yoann les
  exécute en prod via phpMyAdmin o2switch.
- **Vanilla PHP** — PAS de framework (pas de Laravel/Symfony), pas de Composer
  pour le cœur. Requêtes via **PDO** (requêtes préparées partout).
- Front : HTML/CSS/JS **vanilla** (pas de build, pas de bundler)
- PWA (manifest, service worker)

## ⚠️ RÈGLES CRITIQUES (pièges déjà rencontrés)

1. **Fichiers en CRLF** (`\r\n`). Conserver les fins de ligne Windows.
   Certains fichiers (ex. `includes/header.php`) ont même des `\r\r\n`.

2. **Scanner ZXing** (`scanner/scanner-zxing.js`) : **NE JAMAIS** réécrire en
   `let`/`const`. Il utilise `var` volontairement. Y toucher casse le scan.

3. **ModSecurity o2switch** bloque certains mots-clés SQL dans les paramètres
   GET → **403**. Ex : `?delete=` était bloqué → renommé en **`?suppr=`**.
   Éviter `delete`, `select`, `union`, etc. comme noms de paramètres GET.

4. **Toujours valider le PHP** avant de livrer (équilibrage accolades/parenthèses
   + pas de `\` parasite hors chaîne — un `\$var` hors chaîne = *unexpected token*).

5. **Deux systèmes de « modules » distincts** (ne pas confondre) :
   - `config/perms_modules.php` → `modules_permissions()` : liste des modules
     pour les **matrices de permissions** (medicaments, sse, equipements…)
   - `config/modules.php` → `modules_registry()` : **activation/réglages** des
     fonctionnalités par site (sse_nrbc, biomedical, ia_photo, scanner…)
   - Le pont entre les deux : `module_flag_map()` dans `perms_modules.php`.

## 🔑 Système de rôles & permissions

Défini dans **`config/permissions.php`**.

Hiérarchie (`ROLES_NIVEAUX`) :
```
lecteur=1 · saisie=2 · biomedical=2 · sse=2 · gestionnaire=3 · administrateur=4 · super_admin=99
```

- `peut_faire(ROLE_X)` → true si le rôle courant a au moins le niveau X.
- `role_sur_site()` → renvoie la clé BRUTE du rôle (`administrateur`, etc.) =
  celle stockée dans `role_permissions`.
- ⚠️ `currentRole()` **NORMALISE** `administrateur` → `admin`. Donc pour lire
  `role_permissions`, utiliser **`role_sur_site()`**, pas `currentRole()`.
- `action_permise('creer'|'modifier'|'renouveler'|'dupliquer'|'archiver'|'supprimer')`
  → droits par bouton, stockés dans `role_permissions` (module `action_X`,
  niveau ≥ 1 = autorisé). Défaut si non configuré = gestionnaire et plus.
- **Invalidation** : `perms_bump($pdo)` bumpe `parametres.perms_version` →
  chaque user recharge ses droits à sa prochaine page (pas de reconnexion).

Table **`role_permissions`** : `(role, module, niveau)` — clé unique (role, module),
donc `INSERT … ON DUPLICATE KEY UPDATE`. Surcharges par site : `site_role_permissions`.

## 🗂️ Fonctions helpers (déjà disponibles)

- Sécurité HTML : `h()` / `htmlspecialchars`
- CSRF : `csrfField()` (form), `csrf_check()` (valide POST — no-op sur GET),
  `verifyCsrf()`
- Flash : `flashSuccess()`, `flashError()`
- Rôles : `isAdmin()`, `isGestionnaire()`, `canEdit()` (saisie+),
  `canFullEdit()` (gestionnaire+), `currentUserId()`
- Modules : `module_actif('cle')`, `module_param('cle','param',defaut)`,
  `module_perm_visible('permKey')`
- Audit : `audit_log($pdo, action, table, id, avant, apres)`
- Site : `site_actif()`, `role_sur_site()`

## 🏗️ Modèle de données (principales tables)

- **`emplacements`** : arbre (parent_id), `site_id`. Colonnes scellé ajoutées :
  `scelle_numero`, `controle_le`, `controle_par`.
- **`produits`** : 1 ligne = 1 lot. `emplacement_id`, `nom`, `numero_lot`, `ean`,
  `datamatrix`, `gtin`, `quantite` (decimal), `quantite_min`, `unite`,
  `date_peremption`, `alerte_jours`, `chaine_froid`, `statut`
  enum('actif','archive','retire').
- **`mouvements_stock`** : `produit_id, user_id, type, quantite_avant,
  quantite_delta, quantite_apres, motif` — traçabilité (existe, sous-exploité).
- **`controles_sacs`** : historique des contrôles de scellé.
- **`role_permissions`**, **`site_role_permissions`** : permissions.
- **`modules`** : activation/params par site. **`parametres`** : clé/valeur.
- **`utilisateurs`**, **`user_site_permissions`**, **`sites`**, **`organisations`**.

## 🧭 Fichiers clés

- `includes/auth.php` — bootstrap : charge config, définit `currentRole()`,
  `action_permise()`, les helpers de rôle. Inclus par toutes les pages.
- `config/permissions.php` — rôles, `peut_faire`, `charger_permissions`, `perms_bump`.
- `config/perms_modules.php` — `modules_permissions()`, `modules_sections()`,
  `module_flag_map()`, `module_perm_visible()`.
- `config/modules.php` — `modules_registry()`, `module_actif()`, `module_param()`.
- `config/admin_tabs.php` — **SOURCE UNIQUE** des onglets admin (`admin_tabs_definis()`).
- `admin/index.php` — hub admin (barre d'onglets, lit `admin_tabs.php`).
- `admin/users.php` — Utilisateurs & accès. Onglet **« Accès »** (3 étages :
  modules×rôles + sélecteur de site, actions détaillées, administration).
- `products.php` — gestion médicaments (liste, création, renouvellement,
  archivage, suppression, tri colonnes). Boutons gardés par `action_permise()`.
- `locations.php` — vue emplacements (arbre + détail, accordéon par unité,
  contrôle de scellé).
- `includes/header.php` — nav latérale, `nav_peut()` (gate rôle + `module_actif`).

## 🚀 Chantiers en cours / à venir

- **PUI (stock central par centre)** — LE gros chantier. Une PUI par site
  (type d'emplacement `pui`), le rempotage devient un **transfert** qui débite
  la PUI du même `site_id` que le sac (auto, sans question). Retrait sac =
  consommé (disparaît). Réappro PUI = simple ajout.
  - ✅ **Étape 1 (faite)** : colonne `emplacements.type` (migration `030`,
    défaut `standard`) + affichage PUI dans `locations.php` (icône entrepôt,
    badge violet « PUI », sélecteur de type au formulaire). Aucune logique de
    transfert touchée. ⚠️ Migration 030 encore à jouer EN PROD.
  - ⏭️ Étape suivante : brancher le rempotage-transfert sur la PUI du site.
- **Dotation** : composition attendue par sac (réel vs attendu, flag écarts).
- **Nouveau visuel emplacements** (PUI en haut, sacs en cartes).
- Tableau de bord d'accueil, historique contrôles scellés, alertes CRON, 2FA,
  import BDPM.

## ✅ Méthode de travail

- **Répondre en français.**
- **Lire un fichier avant de le modifier.** Faire des modifs ciblées.
- **Valider le PHP** (syntaxe) et le **JS** (`node --check`) avant de conclure.
- Privilégier les **petites étapes testables** sur les gros chantiers sensibles
  (surtout les permissions — un bug peut verrouiller des accès).
- Ne pas casser l'existant qui marche : en cas de doute sur du code sensible,
  garder un filet de sécurité et proposer avant de restructurer.

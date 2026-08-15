# 🚀 Déploiement prod — Cadurix (o2switch)

Récap de **tout** ce qui doit passer en ligne. Couvre jusqu'à la **PR #27**
(refonte Emplacements/PUI + refonte Médicaments + DCI + permission ±1 + filtre en arbre).

> ⚠️ Ordre impératif : **1) SQL d'abord**, **2) fichiers ensuite**, **3) suppressions**,
> **4) vérifs**. Et **téléverser TOUS les fichiers ensemble** (certains `require`
> ont changé de chemin — un déploiement partiel casserait des pages).

---

## 1) BASE DE DONNÉES — à jouer en premier

Dans **phpMyAdmin o2switch**, base de prod `royo5535_cadurix`, onglet SQL.

### a) Emplacements/PUI — `migrations/PROD_a_jouer.sql`
3 colonnes sur `emplacements` : `type`, `icone`, `pui_id` (+ index).
> ✅ Normalement **déjà joué en prod le 2026-08-10**. Si les colonnes existent
> déjà, ne pas rejouer (ça renverrait « Duplicate column »). En cas de doute,
> vérifier dans phpMyAdmin la structure de `emplacements`.

### b) Médicaments — `migrations/PROD_a_jouer_medoc.sql`  ← **NOUVEAU, à jouer**
3 colonnes sur `produits` : `dci`, `dosage`, `forme`.
Regroupe les migrations 033 + 034. À jouer **une seule fois**.

---

## 2) FICHIERS À TÉLÉVERSER (écraser / ajouter)

Fichiers applicatifs modifiés ou nouveaux :

    admin/api_tokens.php
    admin/users.php                  ← permission « ± Ajuster qté » + « Historique »
    config/session.php
    dashboard.php
    includes/auth.php                ← nouvelles permissions d'action
    includes/header.php
    locations.php                    ← refonte emplacements + dosage/DCI sous le nom
    materiel_intervention.php
    messaging.php
    products.php                     ← refonte Médicaments (DCI, filtre arbre, ±1, scan…)
    rempotage.php
    scan.php
    scanner/zxing-0.21.3.min.js      ← NOUVEAU (librairie scanner auto-hébergée)
    service-worker.js
    terrain.php

Optionnel (simple modèle de config, sans effet) :

    config/db.example.php            ← NOUVEAU (doc)

> 🔒 **NE JAMAIS téléverser `config/db.php`** : la prod a le sien (vrais
> identifiants). On n'y touche pas.

---

## 3) FICHIERS À SUPPRIMER SUR LA PROD (sécurité + nettoyage)

Ces fichiers exposés/inutiles ont été retirés — supprime-les aussi en prod :

    reset_temp.php
    test_tools.php
    test/test_curl.php
    test/test_gsl1.php
    scanner/test_gs1.php
    scanner/scanner-zxing.js11       ← fichier parasite (sauvegarde)
    includes/session.php             ← déplacé vers config/session.php

*(Si le dossier `test/` devient vide, tu peux le supprimer.)*

> ✅ `scanner/scanner-zxing.js` (le VRAI scanner) reste, on n'y a pas touché.

---

## 4) NE PAS DÉPLOYER (fichiers de dépôt uniquement)

Aucun impact prod, ne pas téléverser :

    .gitignore
    .claude/settings.local.json
    CLAUDE.md
    README.md
    DEPLOIEMENT_PROD.md              ← ce fichier
    maquette-medicaments.html        ← maquette locale (aperçu)
    migrations/*.sql                 ← la base se met à jour via phpMyAdmin (étape 1)

---

## 5) APRÈS DÉPLOIEMENT

- [ ] **Ctrl+F5** (le service worker sert du cache — indispensable).
- [ ] **Admin → Utilisateurs → Accès** : cocher « ± Ajuster qté » et
      « 🕓 Historique » pour les rôles voulus, puis **Enregistrer**.
- [ ] Page **Médicaments** : DCI en 1er dans la fiche, filtre emplacement en
      arbre (PUI en tête, sacs décalés), boutons −1/+1, scan en rafale.
- [ ] Page **Emplacements** : arbre PUI → sacs, icônes, contrôle scellé.
- [ ] **Rempotage** : débit de la PUI du site (badge stock, mouvement tracé).
- [ ] **Scanner** : fonctionne hors-ligne (librairie auto-hébergée).

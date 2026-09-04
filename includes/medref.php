<?php
// Cadurix — Auto-apprentissage de la base de médicaments de référence.
// Ajoute un médicament à `medicaments_ref` s'il n'y est pas déjà, à partir d'un
// produit qui vient d'être créé. Silencieux : ne doit JAMAIS casser la création.

if (!function_exists('medref_apprendre')) {
function medref_apprendre(PDO $pdo, array $p): void {
    $nom = trim((string)($p['nom'] ?? ''));
    if ($nom === '') return;
    if (!empty($p['stupefiant'])) return; // stupéfiants gérés à part
    $dosage = trim((string)($p['dosage'] ?? ''));
    try {
        // Déjà présent ? (même nom + même dosage, insensible à la casse)
        $q = $pdo->prepare("SELECT id FROM medicaments_ref WHERE LOWER(nom)=LOWER(?) AND COALESCE(LOWER(dosage),'')=LOWER(?) LIMIT 1");
        $q->execute([$nom, $dosage]);
        if ($q->fetchColumn()) return;

        $pdo->prepare("INSERT INTO medicaments_ref (nom, dci, forme, dosage, unite, categorie, froid, alerte_jours, actif)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)")
            ->execute([
                $nom,
                (trim((string)($p['dci']   ?? '')) ?: null),
                (trim((string)($p['forme'] ?? '')) ?: null),
                ($dosage !== '' ? $dosage : null),
                (trim((string)($p['unite'] ?? 'unité')) ?: 'unité'),
                'Appris automatiquement',
                !empty($p['chaine_froid']) ? 1 : 0,
                (int)($p['alerte_jours'] ?? 30),
            ]);
    } catch (Throwable $e) { /* silencieux — l'apprentissage ne bloque jamais la création */ }
}}

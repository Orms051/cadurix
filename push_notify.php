<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.

/**
 * Cadurix — push_notify.php
 * Envoi des notifications push PWA
 * Appelé par cron : php push_notify.php
 * Ou via API : GET push_notify.php?token=XXX&type=alertes
 */

// Sécurité — accès CLI ou token
if (php_sapi_name() !== 'cli') {
    require_once __DIR__ . '/includes/auth.php';
    $token = $_GET['token'] ?? '';
    try {
        $stored = $pdo->query("SELECT valeur FROM parametres WHERE cle='cron_token'")->fetchColumn();
        if (!$stored || $token !== $stored) {
            http_response_code(403); echo 'Accès refusé'; exit;
        }
    } catch(Exception $e) { http_response_code(500); exit; }
} else {
    require_once __DIR__ . '/config/db.php';
}

// Vérifier si push actif + clés VAPID configurées
$params = $pdo->query("SELECT cle,valeur FROM parametres WHERE cle IN ('vapid_public_key','vapid_private_key','vapid_subject','push_notif_actif')")->fetchAll(PDO::FETCH_KEY_PAIR);

if (($params['push_notif_actif'] ?? '0') !== '1') {
    echo "Push notifications désactivées.\n"; exit;
}
if (empty($params['vapid_public_key']) || empty($params['vapid_private_key'])) {
    echo "Clés VAPID non configurées.\n"; exit;
}

// ── Construire les notifications à envoyer ────────────────
$notifications = [];

// 1. Produits périmés aujourd'hui
$perime = $pdo->query("
    SELECT p.nom, e.nom AS empl_nom, s.id AS site_id
    FROM produits p
    JOIN emplacements e ON e.id=p.emplacement_id
    JOIN sites s ON s.id=e.site_id
    WHERE p.statut='actif' AND p.date_peremption = CURDATE()
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($perime as $p) {
    $notifications[$p['site_id']][] = [
        'title' => '🔴 Produit périmé aujourd\'hui',
        'body'  => $p['nom'].' — '.$p['empl_nom'],
        'url'   => '/alerts.php',
        'tag'   => 'perime-'.$p['site_id'],
    ];
}

// 2. Produits critiques (7 jours)
$critique = $pdo->query("
    SELECT COUNT(*) AS nb, s.id AS site_id
    FROM produits p
    JOIN emplacements e ON e.id=p.emplacement_id
    JOIN sites s ON s.id=e.site_id
    WHERE p.statut='actif' AND DATEDIFF(p.date_peremption, CURDATE()) BETWEEN 1 AND 7
    GROUP BY s.id
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($critique as $c) {
    if ($c['nb'] > 0) {
        $notifications[$c['site_id']][] = [
            'title' => '🟠 '.$c['nb'].' produit(s) expirent dans 7 jours',
            'body'  => 'Vérifiez et planifiez le rempotage',
            'url'   => '/alerts.php',
            'tag'   => 'critique-'.$c['site_id'],
        ];
    }
}

// 3. Fiche de vérification non remplie depuis 24h (si c'est un jour ouvré)
$jour = date('N'); // 1=Lun, 7=Dim
if ($jour <= 5) { // Jours ouvrés seulement
    $fiches_manquantes = $pdo->query("
        SELECT s.id AS site_id, s.nom AS site_nom
        FROM sites s
        WHERE s.actif=1
        AND NOT EXISTS (
            SELECT 1 FROM fiches_remplies f
            WHERE f.site_id=s.id
            AND DATE(f.cree_le) = CURDATE()
        )
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($fiches_manquantes as $f) {
        $notifications[$f['site_id']][] = [
            'title' => '📋 Fiche de vérification manquante',
            'body'  => 'Aucune fiche remplie aujourd\'hui — '.$f['site_nom'],
            'url'   => '/fiches.php',
            'tag'   => 'fiche-'.$f['site_id'],
        ];
    }
}

if (empty($notifications)) {
    echo "Aucune notification à envoyer.\n"; exit;
}

// ── Fonction envoi push ───────────────────────────────────
function sendPush(string $endpoint, string $p256dh, string $auth, array $payload, array $vapid): bool {
    // Encoder le payload
    $payloadJson = json_encode($payload);

    // Headers VAPID simplifiés (sans librairie)
    // ⚠️ En prod, utiliser webpush-php ou minishlink/web-push
    $headers = [
        'Content-Type: application/json',
        'TTL: 86400',
    ];

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payloadJson,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $httpCode >= 200 && $httpCode < 300;
}

// ── Envoyer aux abonnés ───────────────────────────────────
$sent = 0; $errors = 0;

foreach ($notifications as $site_id => $notifs) {
    // Récupérer les abonnés actifs du site
    $subs = $pdo->prepare("SELECT * FROM push_subscriptions WHERE site_id=? AND actif=1");
    $subs->execute([$site_id]);

    foreach ($subs->fetchAll(PDO::FETCH_ASSOC) as $sub) {
        foreach ($notifs as $notif) {
            $ok = sendPush(
                $sub['endpoint'],
                $sub['p256dh'],
                $sub['auth'],
                $notif,
                $params
            );
            if ($ok) {
                $sent++;
                $pdo->prepare("UPDATE push_subscriptions SET last_used=NOW() WHERE id=?")->execute([$sub['id']]);
            } else {
                $errors++;
                // Désactiver si endpoint mort (410 = révoqué)
                $pdo->prepare("UPDATE push_subscriptions SET actif=0 WHERE id=?")->execute([$sub['id']]);
            }
        }
    }
}

echo "✅ $sent notification(s) envoyée(s), $errors erreur(s).\n";

// ── Notifications email en complément du push ────────────────
function send_alert_email(PDO $pdo, int $site_id, string $site_nom, array $stats): void {
    if ($stats['nb_perime'] === 0 && $stats['nb_critique'] === 0) return;

    // Récupérer les admins du site
    $stmt = $pdo->prepare("
        SELECT u.email, u.prenom FROM utilisateurs u
        JOIN user_site_permissions usp ON usp.user_id=u.id
        WHERE usp.site_id=? AND u.actif=1 AND u.email IS NOT NULL
          AND usp.role IN ('administrateur') AND u.email_alerte=1
    ");
    $stmt->execute([$site_id]);
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($admins)) return;

    $subject = "⚠️ Cadurix — Alertes péremptions : $site_nom";
    $lines = [];
    if ($stats['nb_perime'] > 0)   $lines[] = "• {$stats['nb_perime']} produit(s) PÉRIMÉ(S) — retrait immédiat requis";
    if ($stats['nb_critique'] > 0) $lines[] = "• {$stats['nb_critique']} produit(s) expirant dans 7 jours";
    if ($stats['nb_stock'] > 0)    $lines[] = "• {$stats['nb_stock']} produit(s) en stock bas";
    $body_lines = implode("<br>", $lines);

    foreach ($admins as $admin) {
        $html = "<!DOCTYPE html><html><body style='font-family:Arial,sans-serif;color:#1e293b;background:#f8fafc;padding:20px'>
        <div style='max-width:500px;margin:0 auto;background:white;border-radius:12px;padding:24px;box-shadow:0 2px 10px rgba(0,0,0,.1)'>
        <h2 style='color:#ef4444'>⚠️ Alertes péremptions — $site_nom</h2>
        <p>Bonjour {$admin['prenom']},</p>
        <p>Des produits nécessitent votre attention :</p>
        <p>$body_lines</p>
        <a href='https://cadurix.fr/dashboard.php' style='display:inline-block;background:#0f4c81;color:white;padding:10px 20px;border-radius:8px;text-decoration:none;margin-top:12px'>Accéder à Cadurix</a>
        <hr style='border:none;border-top:1px solid #e2e8f0;margin:16px 0'>
        <p style='font-size:11px;color:#94a3b8'>Cadurix v5 — Notification automatique</p>
        </div></body></html>";

        $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: Cadurix Alertes <noreply@cadurix.fr>\r\n";
        @mail($admin['email'], $subject, $html, $headers);
    }
}


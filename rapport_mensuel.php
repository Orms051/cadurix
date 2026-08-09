<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.

/**
 * Cadurix — Rapport mensuel automatique
 * CLI : php rapport_mensuel.php [site_id] [email]
 * Web : /rapport_mensuel.php?token=XXX&site_id=X
 */

// Accès CLI ou token cron
if (php_sapi_name() !== 'cli') {
    require_once __DIR__ . '/includes/auth.php';
    $token = $_GET['token'] ?? '';
    $stored = $pdo->query("SELECT valeur FROM parametres WHERE cle='cron_token'")->fetchColumn();
    if (!$stored || !hash_equals($stored, $token)) {
        http_response_code(403); die('Accès refusé');
    }
} else {
    require_once __DIR__ . '/config/db.php';
}

$target_site = isset($argv[1]) ? (int)$argv[1] : ((int)($_GET['site_id'] ?? 0));
$target_email = $argv[2] ?? ($_GET['email'] ?? null);
$mois = date('Y-m');
$mois_label = (new IntlDateFormatter('fr_FR', IntlDateFormatter::LONG, IntlDateFormatter::NONE, null, null, 'MMMM yyyy'))->format(new DateTime()) ?: date('m/Y');
$results = [];

// Récupérer tous les sites ou un site spécifique
if ($target_site) {
    $sites = $pdo->prepare("SELECT s.*, o.nom AS org_nom FROM sites s JOIN organisations o ON o.id=s.organisation_id WHERE s.id=? AND s.actif=1");
    $sites->execute([$target_site]);
} else {
    $sites = $pdo->query("SELECT s.*, o.nom AS org_nom FROM sites s JOIN organisations o ON o.id=s.organisation_id WHERE s.actif=1 ORDER BY o.nom, s.nom");
}
$sites = $sites->fetchAll(PDO::FETCH_ASSOC);

foreach ($sites as $site) {
    $sid = $site['id'];
    
    // Récupérer les admins du site pour l'envoi email
    $admins = $pdo->prepare("
        SELECT u.email, u.prenom, u.nom FROM utilisateurs u
        JOIN user_site_permissions usp ON usp.user_id=u.id
        WHERE usp.site_id=? AND u.actif=1 AND u.email IS NOT NULL
          AND usp.role IN ('administrateur','super_admin')
    ");
    $admins->execute([$sid]);
    $admins = $admins->fetchAll(PDO::FETCH_ASSOC);
    
    // Données du rapport
    $s = function(string $sql, array $p = []) use ($pdo, $sid): int {
        $params = empty($p) ? [$sid] : $p;
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return (int)$st->fetchColumn();
    };

    $data = [
        'produits_total'    => $s("SELECT COUNT(*) FROM produits p JOIN emplacements e ON e.id=p.emplacement_id WHERE e.site_id=? AND p.statut='actif'"),
        'produits_perimes'  => $s("SELECT COUNT(*) FROM produits p JOIN emplacements e ON e.id=p.emplacement_id WHERE e.site_id=? AND p.statut='actif' AND p.date_peremption < CURDATE()"),
        'produits_critiques'=> $s("SELECT COUNT(*) FROM produits p JOIN emplacements e ON e.id=p.emplacement_id WHERE e.site_id=? AND p.statut='actif' AND DATEDIFF(p.date_peremption,CURDATE()) BETWEEN 0 AND 7"),
        'produits_30j'      => $s("SELECT COUNT(*) FROM produits p JOIN emplacements e ON e.id=p.emplacement_id WHERE e.site_id=? AND p.statut='actif' AND DATEDIFF(p.date_peremption,CURDATE()) BETWEEN 0 AND 30"),
        'stock_bas'         => $s("SELECT COUNT(*) FROM produits p JOIN emplacements e ON e.id=p.emplacement_id WHERE e.site_id=? AND p.statut='actif' AND p.quantite_min>0 AND p.quantite<=p.quantite_min"),
        'rempotages_mois'   => $s("SELECT COUNT(*) FROM rempotages WHERE site_id=? AND MONTH(debut_le)=MONTH(NOW()) AND YEAR(debut_le)=YEAR(NOW())"),
        'interventions_mois'=> $s("SELECT COUNT(*) FROM interventions_materiel WHERE site_id=? AND MONTH(cree_le)=MONTH(NOW()) AND YEAR(cree_le)=YEAR(NOW())"),
        'fiches_mois'       => $s("SELECT COUNT(*) FROM fiches_remplies WHERE site_id=? AND MONTH(cree_le)=MONTH(NOW()) AND YEAR(cree_le)=YEAR(NOW())"),
    ];
    
    // Top 10 produits périmés ou critiques
    $st_prod = $pdo->prepare("
        SELECT p.nom, p.date_peremption, e.nom AS empl,
               DATEDIFF(p.date_peremption, CURDATE()) AS jours
        FROM produits p JOIN emplacements e ON e.id=p.emplacement_id
        WHERE e.site_id=? AND p.statut='actif' 
          AND p.date_peremption <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        ORDER BY p.date_peremption ASC LIMIT 10
    ");
    $st_prod->execute([$sid]);
    $produits_alerte = $st_prod->fetchAll(PDO::FETCH_ASSOC);
    
    // SSE alertes
    $sse_alertes = 0;
    try {
        $sse_alertes = $s("SELECT COUNT(*) FROM sse_equipements WHERE site_id=? AND actif=1 AND (date_peremption<CURDATE() OR statut IN ('hs','revision','reforme'))");
    } catch(Exception $e) {}
    
    // Générer le HTML du rapport
    $couleur = $data['produits_perimes'] > 0 ? '#ef4444' : ($data['produits_critiques'] > 0 ? '#f59e0b' : '#10b981');
    
    ob_start();
    ?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family: Arial, sans-serif; color: #1e293b; background: #f8fafc; }
  .container { max-width: 700px; margin: 0 auto; background: white; }
  .header { background: #0f4c81; color: white; padding: 30px 40px; }
  .header h1 { font-size: 22px; font-weight: 700; }
  .header p { font-size: 13px; opacity: .8; margin-top: 4px; }
  .status-bar { background: <?= $couleur ?>; color: white; padding: 16px 40px; font-weight: 700; font-size: 15px; }
  .content { padding: 30px 40px; }
  .kpi-grid { display: grid; grid-template-columns: repeat(3,1fr); gap: 12px; margin-bottom: 24px; }
  .kpi { background: #f1f5f9; border-radius: 8px; padding: 14px; text-align: center; }
  .kpi.danger  { background: #fef2f2; border: 1px solid #fecaca; }
  .kpi.warning { background: #fffbeb; border: 1px solid #fed7aa; }
  .kpi.ok      { background: #f0fdf4; border: 1px solid #bbf7d0; }
  .kpi-num { font-size: 28px; font-weight: 800; }
  .kpi-label { font-size: 11px; color: #64748b; margin-top: 4px; text-transform: uppercase; }
  .section-title { font-size: 14px; font-weight: 700; color: #0f4c81; margin: 20px 0 10px; border-bottom: 2px solid #e2e8f0; padding-bottom: 6px; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  th { background: #f1f5f9; padding: 8px 12px; text-align: left; font-size: 11px; text-transform: uppercase; color: #64748b; }
  td { padding: 8px 12px; border-bottom: 1px solid #f1f5f9; }
  .badge { display: inline-block; padding: 2px 8px; border-radius: 20px; font-size: 11px; font-weight: 600; }
  .badge-danger  { background: #fef2f2; color: #ef4444; }
  .badge-warning { background: #fffbeb; color: #f59e0b; }
  .footer { background: #f8fafc; padding: 20px 40px; font-size: 11px; color: #94a3b8; border-top: 1px solid #e2e8f0; }
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <h1>🩺 Cadurix — Rapport mensuel</h1>
    <p><?= htmlspecialchars($site['org_nom'].' — '.$site['nom']) ?> | <?= $mois_label ?></p>
  </div>
  
  <div class="status-bar">
    <?php if ($data['produits_perimes']>0): ?>
    ⚠️ <?= $data['produits_perimes'] ?> produit(s) périmé(s) — Action requise immédiatement
    <?php elseif ($data['produits_critiques']>0): ?>
    ⚠️ <?= $data['produits_critiques'] ?> produit(s) expirant dans 7 jours
    <?php else: ?>
    ✅ Tous les produits sont conformes
    <?php endif; ?>
  </div>
  
  <div class="content">
    <div class="kpi-grid">
      <div class="kpi <?= $data['produits_perimes']>0?'danger':'' ?>">
        <div class="kpi-num" style="color:<?= $data['produits_perimes']>0?'#ef4444':'#1e293b' ?>"><?= $data['produits_perimes'] ?></div>
        <div class="kpi-label">Périmés</div>
      </div>
      <div class="kpi <?= $data['produits_critiques']>0?'warning':'' ?>">
        <div class="kpi-num" style="color:<?= $data['produits_critiques']>0?'#f59e0b':'#1e293b' ?>"><?= $data['produits_critiques'] ?></div>
        <div class="kpi-label">Critiques (7j)</div>
      </div>
      <div class="kpi <?= $data['stock_bas']>0?'warning':'ok' ?>">
        <div class="kpi-num"><?= $data['stock_bas'] ?></div>
        <div class="kpi-label">Stock bas</div>
      </div>
      <div class="kpi ok">
        <div class="kpi-num" style="color:#10b981"><?= $data['produits_total'] ?></div>
        <div class="kpi-label">Produits actifs</div>
      </div>
      <div class="kpi ok">
        <div class="kpi-num" style="color:#10b981"><?= $data['rempotages_mois'] ?></div>
        <div class="kpi-label">Rempotages (mois)</div>
      </div>
      <div class="kpi ok">
        <div class="kpi-num" style="color:#10b981"><?= $data['interventions_mois'] ?></div>
        <div class="kpi-label">Interventions (mois)</div>
      </div>
    </div>
    
    <?php if ($sse_alertes > 0): ?>
    <div style="background:#ede9fe;border:1px solid #c4b5fd;border-radius:8px;padding:12px 16px;margin-bottom:16px">
      🧪 <strong style="color:#7c3aed">SSE/NRBC :</strong> <?= $sse_alertes ?> équipement(s) à traiter
    </div>
    <?php endif; ?>
    
    <?php if (!empty($produits_alerte)): ?>
    <div class="section-title">⚠️ Produits à traiter en priorité</div>
    <table>
      <thead><tr><th>Produit</th><th>Emplacement</th><th>Péremption</th><th>Statut</th></tr></thead>
      <tbody>
      <?php foreach ($produits_alerte as $p): ?>
      <tr>
        <td><?= htmlspecialchars($p['nom']) ?></td>
        <td><?= htmlspecialchars($p['empl']) ?></td>
        <td><?= date('d/m/Y', strtotime($p['date_peremption'])) ?></td>
        <td>
          <?php $j=(int)$p['jours']; ?>
          <span class="badge <?= $j<0?'badge-danger':($j<=7?'badge-danger':'badge-warning') ?>">
            <?= $j<0?'Périmé':'J-'.$j ?>
          </span>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
    
    <div class="section-title">📊 Activité du mois</div>
    <table>
      <tbody>
        <tr><td>Rempotages effectués</td><td><strong><?= $data['rempotages_mois'] ?></strong></td></tr>
        <tr><td>Interventions matériel</td><td><strong><?= $data['interventions_mois'] ?></strong></td></tr>
        <tr><td>Fiches de contrôle remplies</td><td><strong><?= $data['fiches_mois'] ?></strong></td></tr>
        <tr><td>Produits expire dans 30 jours</td><td><strong><?= $data['produits_30j'] ?></strong></td></tr>
      </tbody>
    </table>
  </div>
  
  <div class="footer">
    Rapport généré automatiquement par Cadurix v5.4 le <?= date('d/m/Y à H:i') ?><br>
    Ce rapport est confidentiel — Usage interne uniquement
  </div>
</div>
</body>
</html>
    <?php
    $html = ob_get_clean();
    
    // Envoi email
    $emails_to = [];
    if ($target_email) {
        $emails_to[] = $target_email;
    } else {
        foreach ($admins as $a) {
            if ($a['email']) $emails_to[] = $a['email'];
        }
    }

    foreach ($emails_to as $email) {
        $subject = "Cadurix — Rapport mensuel {$mois_label} — {$site['nom']}";
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: Cadurix Rapports <noreply@cadurix.fr>\r\n";
        $sent = @mail($email, $subject, $html, $headers);
        $results[] = ['email' => $email, 'site' => $site['nom'], 'ok' => $sent];
        if (php_sapi_name() === 'cli') {
            echo ($sent ? "✅" : "❌") . " Rapport envoyé à $email ({$site['nom']})\n";
        }
    }
    
    // Log
    try {
        $pdo->prepare("INSERT INTO audit_log (user_id, action, table_cible, ip) VALUES (0, 'RAPPORT_MENSUEL', 'sites', ?)")
            ->execute(['127.0.0.1']);
    } catch(Exception $e) {}
}

if (php_sapi_name() === 'cli') {
    echo "✅ Rapport mensuel terminé\n";
} else {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'results' => $results, 'mois' => $mois_label]);
}

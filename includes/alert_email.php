<?php
// Cadurix — Gabarit HTML de l'email d'alertes (partagé : page admin + cron).
// HTML "email-safe" : tableaux + styles inline (compatible Gmail, Outlook, Apple Mail…).

if (!function_exists('buildAlertEmail')) {
function buildAlertEmail(string $site, string $date, string $note, array $include, array $expired, array $critical, array $warning, array $lowstock): string {
    $E = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $total = count($expired) + count($critical) + count($warning) + count($lowstock);
    $appUrl = 'https://cadurix.fr/alerts.php';

    // ── En-tête de section (bandeau coloré) ──
    $secHead = function(string $emoji, string $titre, int $n, string $color) use ($E) {
        return '<tr><td style="padding:22px 26px 10px 26px;font-family:Arial,Helvetica,sans-serif">'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
            .'<td style="font-size:15px;font-weight:700;color:#0f172a">'.$emoji.' '.$E($titre).'</td>'
            .'<td align="right"><span style="display:inline-block;background:'.$color.'1a;color:'.$color.';font-size:12px;font-weight:700;padding:3px 11px;border-radius:99px">'.$n.'</span></td>'
            .'</tr></table></td></tr>';
    };
    // ── Ligne d'un tableau produit ──
    $th = 'style="text-align:left;padding:7px 10px;background:#f1f5f9;color:#64748b;font-weight:700;font-size:10.5px;text-transform:uppercase;letter-spacing:.03em;font-family:Arial,Helvetica,sans-serif"';
    $td = 'style="padding:9px 10px;border-bottom:1px solid #eef2f7;font-size:13px;color:#1e293b;font-family:Arial,Helvetica,sans-serif"';
    $badge = fn($txt,$bg,$fg) => '<span style="display:inline-block;padding:2px 9px;border-radius:99px;font-size:11.5px;font-weight:700;background:'.$bg.';color:'.$fg.'">'.$txt.'</span>';

    // ── Sections ──
    $sections = '';
    if (in_array('expired',$include) && !empty($expired)) {
        $sections .= $secHead('⛔','Produits périmés',count($expired),'#dc2626');
        $sections .= '<tr><td style="padding:0 26px 6px 26px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse"><tr><th '.$th.'>Produit</th><th '.$th.'>Emplacement</th><th '.$th.'>Lot</th><th '.$th.'>Date</th></tr>';
        foreach ($expired as $p) $sections .= '<tr><td '.$td.'><strong>'.$E($p['nom']).'</strong></td><td '.$td.' style="padding:9px 10px;border-bottom:1px solid #eef2f7;font-size:12.5px;color:#64748b;font-family:Arial,Helvetica,sans-serif">'.$E($p['loc']).'</td><td '.$td.'>'.$E($p['numero_lot']??'—').'</td><td '.$td.'>'.$badge(date('d/m/Y',strtotime($p['date_peremption'])),'#fee2e2','#dc2626').'</td></tr>';
        $sections .= '</table></td></tr>';
    }
    if (in_array('critical',$include) && !empty($critical)) {
        $sections .= $secHead('🔴','Critiques — moins de 7 jours',count($critical),'#dc2626');
        $sections .= '<tr><td style="padding:0 26px 6px 26px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse"><tr><th '.$th.'>Produit</th><th '.$th.'>Emplacement</th><th '.$th.'>Lot</th><th '.$th.'>Péremption</th></tr>';
        foreach ($critical as $p) { $d=(int)floor((strtotime($p['date_peremption'])-time())/86400); $sections .= '<tr><td '.$td.'><strong>'.$E($p['nom']).'</strong></td><td '.$td.' style="padding:9px 10px;border-bottom:1px solid #eef2f7;font-size:12.5px;color:#64748b;font-family:Arial,Helvetica,sans-serif">'.$E($p['loc']).'</td><td '.$td.'>'.$E($p['numero_lot']??'—').'</td><td '.$td.'>'.$badge(date('d/m/Y',strtotime($p['date_peremption'])).' · J-'.$d,'#fee2e2','#dc2626').'</td></tr>'; }
        $sections .= '</table></td></tr>';
    }
    if (in_array('warning',$include) && !empty($warning)) {
        $sections .= $secHead('🟡','Avertissements — moins de 30 jours',count($warning),'#d97706');
        $sections .= '<tr><td style="padding:0 26px 6px 26px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse"><tr><th '.$th.'>Produit</th><th '.$th.'>Emplacement</th><th '.$th.'>Péremption</th></tr>';
        foreach ($warning as $p) { $d=(int)floor((strtotime($p['date_peremption'])-time())/86400); $sections .= '<tr><td '.$td.'>'.$E($p['nom']).'</td><td '.$td.' style="padding:9px 10px;border-bottom:1px solid #eef2f7;font-size:12.5px;color:#64748b;font-family:Arial,Helvetica,sans-serif">'.$E($p['loc']).'</td><td '.$td.'>'.$badge(date('d/m/Y',strtotime($p['date_peremption'])).' · J-'.$d,'#fef3c7','#b45309').'</td></tr>'; }
        $sections .= '</table></td></tr>';
    }
    if (in_array('lowstock',$include) && !empty($lowstock)) {
        $sections .= $secHead('📦','Stocks bas',count($lowstock),'#7c3aed');
        $sections .= '<tr><td style="padding:0 26px 6px 26px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse"><tr><th '.$th.'>Produit</th><th '.$th.'>Emplacement</th><th '.$th.'>Stock</th><th '.$th.'>Mini</th></tr>';
        foreach ($lowstock as $p) $sections .= '<tr><td '.$td.'>'.$E($p['nom']).'</td><td '.$td.' style="padding:9px 10px;border-bottom:1px solid #eef2f7;font-size:12.5px;color:#64748b;font-family:Arial,Helvetica,sans-serif">'.$E($p['loc']).'</td><td '.$td.'>'.$badge($E($p['quantite']).' '.$E($p['unite']),'#ede9fe','#6d28d9').'</td><td '.$td.'>'.$E($p['quantite_min']).'</td></tr>';
        $sections .= '</table></td></tr>';
    }
    if ($sections === '') $sections = '<tr><td style="padding:26px;text-align:center;color:#16a34a;font-size:14px;font-weight:700;font-family:Arial,Helvetica,sans-serif">✅ Aucune alerte — tout est en ordre.</td></tr>';

    // ── Préheader (aperçu dans la boîte de réception) ──
    $pre = $total > 0 ? "$total produit(s) nécessitent votre attention — $site" : "Aucune alerte — $site";

    // ── KPI helper (cellule) ──
    $kpi = fn($n,$lbl,$color) => '<td width="25%" align="center" style="padding:6px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border:1px solid #e6ecf5;border-radius:10px"><tr><td align="center" style="padding:13px 6px;font-family:Arial,Helvetica,sans-serif"><div style="font-size:24px;font-weight:800;color:'.$color.';line-height:1">'.$n.'</div><div style="font-size:11px;color:#64748b;margin-top:4px">'.$lbl.'</div></td></tr></table></td>';

    $noteBlock = $note !== '' ? '<tr><td style="padding:4px 26px 0 26px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td style="background:#eff6ff;border-left:3px solid #3b82f6;border-radius:0 8px 8px 0;padding:11px 14px;font-size:13px;color:#1e3a8a;font-family:Arial,Helvetica,sans-serif">📝 '.$E($note).'</td></tr></table></td></tr>' : '';

    // ── Document ──
    return '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><title>Alertes Cadurix</title></head>
<body style="margin:0;padding:0;background:#eef2f9;-webkit-text-size-adjust:100%">
<div style="display:none;max-height:0;overflow:hidden;opacity:0">'.$E($pre).'</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef2f9"><tr><td align="center" style="padding:26px 12px">
  <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e6ecf5">
    <!-- Header -->
    <tr><td style="background:#4f46e5;background:linear-gradient(135deg,#3b82f6,#7c4dff);padding:26px 26px;font-family:Arial,Helvetica,sans-serif" bgcolor="#4f46e5">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
        <td width="46" valign="middle"><div style="width:44px;height:44px;background:rgba(255,255,255,.18);border-radius:12px;text-align:center;line-height:44px;font-size:22px">⚕️</div></td>
        <td valign="middle" style="padding-left:12px">
          <div style="color:#ffffff;font-size:19px;font-weight:800;line-height:1.15">Cadurix</div>
          <div style="color:#e6ecff;font-size:12.5px;margin-top:2px">Rapport d\'alertes · '.$E($site).'</div>
        </td>
        <td align="right" valign="middle"><div style="color:#e6ecff;font-size:12px">'.$E($date).'</div></td>
      </tr></table>
    </td></tr>
    <!-- KPI -->
    <tr><td style="padding:18px 20px 4px 20px">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
        .$kpi(count($expired),'Périmés','#6b7280')
        .$kpi(count($critical),'Critiques ≤7j','#dc2626')
        .$kpi(count($warning),'Avert. ≤30j','#d97706')
        .$kpi(count($lowstock),'Stocks bas','#7c3aed')
      .'</tr></table>
    </td></tr>'
    .$noteBlock
    .$sections
    .'<!-- CTA -->
    <tr><td align="center" style="padding:22px 26px 26px 26px">
      <table role="presentation" cellpadding="0" cellspacing="0"><tr><td align="center" bgcolor="#3b82f6" style="border-radius:10px">
        <a href="'.$appUrl.'" style="display:inline-block;padding:12px 26px;font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:10px;background:#3b82f6">Ouvrir Cadurix →</a>
      </td></tr></table>
    </td></tr>
    <!-- Footer -->
    <tr><td style="padding:16px 26px;background:#f8fafc;border-top:1px solid #eef2f7;text-align:center;font-size:11px;color:#94a3b8;font-family:Arial,Helvetica,sans-serif">
      Message automatique généré par Cadurix · '.date('d/m/Y à H:i').'<br>SDIS / SSSM — Gestion du matériel médical
    </td></tr>
  </table>
</td></tr></table>
</body></html>';
}
}

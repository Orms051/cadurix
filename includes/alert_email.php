<?php
// Cadurix — Gabarit HTML de l'email d'alertes (partagé : page admin + cron).
// HTML "email-safe" : tableaux + styles inline (Gmail, Outlook, Apple Mail…),
// + responsive mobile et support mode sombre (progressif).

if (!function_exists('buildAlertEmail')) {
function buildAlertEmail(string $site, string $date, string $note, array $include, array $expired, array $critical, array $warning, array $lowstock): string {
    $E = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $ne = count($expired); $nc = count($critical); $nw = count($warning); $nl = count($lowstock);
    $total = $ne + $nc + $nw + $nl;
    $appUrl   = 'https://cadurix.fr/alerts.php';
    $prefsUrl = 'https://cadurix.fr/alerts.php';
    $logoUrl  = 'https://cadurix.fr/assets/icons/icon-192.png';

    // ── Bandeau de priorité (couleur selon gravité) ──
    if ($ne > 0)            { $pv=['#dc2626','#fef2f2','#fecaca','🔴','Action requise', "$ne produit".($ne>1?'s':'')." périmé".($ne>1?'s':'')." à retirer sans délai"]; }
    elseif ($nc > 0)        { $pv=['#d97706','#fffbeb','#fde68a','⚠️','Attention', "$nc produit".($nc>1?'s':'')." périme".($nc>1?'nt':'')." sous 7 jours"]; }
    elseif ($nw+$nl > 0)    { $pv=['#2563eb','#eff6ff','#bfdbfe','🔔','À surveiller', ($nw+$nl)." point".(($nw+$nl)>1?'s':'')." à anticiper"]; }
    else                    { $pv=['#16a34a','#f0fdf4','#bbf7d0','✅','Tout est en ordre', "Aucune alerte sur ce site"]; }

    // ── Prochaine échéance (le produit qui périme le plus tôt, hors déjà périmés) ──
    $soon = null;
    foreach (array_merge($critical, $warning) as $p) {
        $t = strtotime($p['date_peremption'] ?? '');
        if ($t && (!$soon || $t < $soon['t'])) $soon = ['t'=>$t, 'nom'=>$p['nom'], 'loc'=>$p['loc'] ?? ''];
    }

    // ── Helpers ──
    $th = 'style="text-align:left;padding:7px 10px;background:#f1f5f9;color:#64748b;font-weight:700;font-size:10.5px;text-transform:uppercase;letter-spacing:.03em;font-family:Arial,Helvetica,sans-serif"';
    $td = 'style="padding:9px 10px;border-bottom:1px solid #eef2f7;font-size:13px;color:#1e293b;font-family:Arial,Helvetica,sans-serif"';
    $tdMut = 'style="padding:9px 10px;border-bottom:1px solid #eef2f7;font-size:12.5px;color:#64748b;font-family:Arial,Helvetica,sans-serif"';
    $badge = fn($txt,$bg,$fg) => '<span style="display:inline-block;padding:2px 9px;border-radius:99px;font-size:11.5px;font-weight:700;background:'.$bg.';color:'.$fg.'">'.$txt.'</span>';
    $secHead = function(string $emoji, string $titre, int $n, string $color) use ($E) {
        return '<tr><td class="px" style="padding:22px 26px 10px 26px;font-family:Arial,Helvetica,sans-serif">'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
            .'<td style="font-size:15px;font-weight:700;color:#0f172a">'.$emoji.' '.$E($titre).'</td>'
            .'<td align="right"><span style="display:inline-block;background:'.$color.'1a;color:'.$color.';font-size:12px;font-weight:700;padding:3px 11px;border-radius:99px">'.$n.'</span></td>'
            .'</tr></table></td></tr>';
    };

    // ── Sections ──
    $sections = '';
    if (in_array('expired',$include) && $expired) {
        $sections .= $secHead('⛔','Produits périmés',$ne,'#dc2626');
        $sections .= '<tr><td class="px" style="padding:0 26px 6px 26px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse"><tr><th '.$th.'>Produit</th><th '.$th.'>Emplacement</th><th '.$th.'>Lot</th><th '.$th.'>Date</th></tr>';
        foreach ($expired as $p) $sections .= '<tr><td '.$td.'><strong>'.$E($p['nom']).'</strong></td><td '.$tdMut.'>'.$E($p['loc']).'</td><td '.$td.'>'.$E($p['numero_lot']??'—').'</td><td '.$td.'>'.$badge(date('d/m/Y',strtotime($p['date_peremption'])),'#fee2e2','#dc2626').'</td></tr>';
        $sections .= '</table></td></tr>';
    }
    if (in_array('critical',$include) && $critical) {
        $sections .= $secHead('🔴','Critiques — moins de 7 jours',$nc,'#dc2626');
        $sections .= '<tr><td class="px" style="padding:0 26px 6px 26px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse"><tr><th '.$th.'>Produit</th><th '.$th.'>Emplacement</th><th '.$th.'>Lot</th><th '.$th.'>Péremption</th></tr>';
        foreach ($critical as $p) { $d=(int)floor((strtotime($p['date_peremption'])-time())/86400); $sections .= '<tr><td '.$td.'><strong>'.$E($p['nom']).'</strong></td><td '.$tdMut.'>'.$E($p['loc']).'</td><td '.$td.'>'.$E($p['numero_lot']??'—').'</td><td '.$td.'>'.$badge(date('d/m/Y',strtotime($p['date_peremption'])).' · J-'.$d,'#fee2e2','#dc2626').'</td></tr>'; }
        $sections .= '</table></td></tr>';
    }
    if (in_array('warning',$include) && $warning) {
        $sections .= $secHead('🟡','Avertissements — moins de 30 jours',$nw,'#d97706');
        $sections .= '<tr><td class="px" style="padding:0 26px 6px 26px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse"><tr><th '.$th.'>Produit</th><th '.$th.'>Emplacement</th><th '.$th.'>Péremption</th></tr>';
        foreach ($warning as $p) { $d=(int)floor((strtotime($p['date_peremption'])-time())/86400); $sections .= '<tr><td '.$td.'>'.$E($p['nom']).'</td><td '.$tdMut.'>'.$E($p['loc']).'</td><td '.$td.'>'.$badge(date('d/m/Y',strtotime($p['date_peremption'])).' · J-'.$d,'#fef3c7','#b45309').'</td></tr>'; }
        $sections .= '</table></td></tr>';
    }
    if (in_array('lowstock',$include) && $lowstock) {
        $sections .= $secHead('📦','Stocks bas',$nl,'#7c3aed');
        $sections .= '<tr><td class="px" style="padding:0 26px 6px 26px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse"><tr><th '.$th.'>Produit</th><th '.$th.'>Emplacement</th><th '.$th.'>Stock</th><th '.$th.'>Mini</th></tr>';
        foreach ($lowstock as $p) $sections .= '<tr><td '.$td.'>'.$E($p['nom']).'</td><td '.$tdMut.'>'.$E($p['loc']).'</td><td '.$td.'>'.$badge($E($p['quantite']).' '.$E($p['unite']),'#ede9fe','#6d28d9').'</td><td '.$td.'>'.$E($p['quantite_min']).'</td></tr>';
        $sections .= '</table></td></tr>';
    }
    if ($sections === '') $sections = '<tr><td style="padding:22px 26px;text-align:center;color:#16a34a;font-size:14px;font-weight:700;font-family:Arial,Helvetica,sans-serif">✅ Aucune alerte — tout est en ordre.</td></tr>';

    $pre = $total > 0 ? "$total produit(s) à traiter — $site" : "Aucune alerte — $site";

    $kpi = fn($n,$lbl,$color) => '<td class="kpi" width="25%" align="center" valign="top" style="padding:6px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border:1px solid #e6ecf5;border-radius:10px"><tr><td align="center" style="padding:13px 6px;font-family:Arial,Helvetica,sans-serif"><div style="font-size:24px;font-weight:800;color:'.$color.';line-height:1">'.$n.'</div><div style="font-size:11px;color:#64748b;margin-top:4px">'.$lbl.'</div></td></tr></table></td>';

    $noteBlock = $note !== '' ? '<tr><td class="px" style="padding:4px 26px 0 26px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td style="background:#eff6ff;border-left:3px solid #3b82f6;border-radius:0 8px 8px 0;padding:11px 14px;font-size:13px;color:#1e3a8a;font-family:Arial,Helvetica,sans-serif">📝 '.$E($note).'</td></tr></table></td></tr>' : '';

    $soonBlock = $soon ? '<tr><td class="px" style="padding:10px 26px 0 26px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td style="background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:11px 14px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#9a3412">⏱️ <strong>Prochaine échéance :</strong> '.$E($soon['nom']).($soon['loc']!==''?' <span style="color:#c2704a">('.$E($soon['loc']).')</span>':'').' — le <strong>'.date('d/m/Y',$soon['t']).'</strong> (J-'.max(0,(int)floor(($soon['t']-time())/86400)).')</td></tr></table></td></tr>' : '';

    // ── Document ──
    return '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light"><title>Alertes Cadurix</title>
<style>
@media only screen and (max-width:620px){
  .wrap{width:100%!important}
  .kpi{display:block!important;width:100%!important;box-sizing:border-box}
  .px{padding-left:16px!important;padding-right:16px!important}
}
</style></head>
<body class="bg" style="margin:0;padding:0;background:#eef2f9;-webkit-text-size-adjust:100%">
<div style="display:none;max-height:0;overflow:hidden;opacity:0">'.$E($pre).'</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="bg" style="background:#eef2f9"><tr><td align="center" style="padding:26px 12px">
  <table role="presentation" width="600" cellpadding="0" cellspacing="0" class="wrap card" style="width:600px;max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e6ecf5">
    <!-- Header -->
    <tr><td style="background:#4f46e5;background:linear-gradient(135deg,#3b82f6,#7c4dff);padding:24px 26px;font-family:Arial,Helvetica,sans-serif" bgcolor="#4f46e5">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
        <td width="48" valign="middle"><div style="width:44px;height:44px;background:#ffffff;border-radius:12px;text-align:center;line-height:44px;font-size:24px;color:#3b82f6;font-weight:800;font-family:Arial,Helvetica,sans-serif">✚</div></td>
        <td valign="middle" style="padding-left:12px">
          <div style="color:#ffffff;font-size:19px;font-weight:800;line-height:1.15">Cadurix</div>
          <div style="color:#e6ecff;font-size:12.5px;margin-top:2px">Rapport d\'alertes · '.$E($site).'</div>
        </td>
        <td align="right" valign="middle"><div style="color:#e6ecff;font-size:12px">'.$E($date).'</div></td>
      </tr></table>
    </td></tr>
    <!-- Bandeau priorité -->
    <tr><td class="px" style="padding:16px 26px 4px 26px">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td style="background:'.$pv[1].';border:1px solid '.$pv[2].';border-radius:12px;padding:13px 16px;font-family:Arial,Helvetica,sans-serif">
        <span style="font-size:14px;font-weight:800;color:'.$pv[0].'">'.$pv[3].' '.$E($pv[4]).'</span>
        <span style="font-size:13px;color:#475569"> — '.$E($pv[5]).'</span>
      </td></tr></table>
    </td></tr>
    <!-- KPI -->
    <tr><td class="px" style="padding:12px 20px 4px 20px">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
        .$kpi($ne,'Périmés','#6b7280').$kpi($nc,'Critiques ≤7j','#dc2626').$kpi($nw,'Avert. ≤30j','#d97706').$kpi($nl,'Stocks bas','#7c3aed')
      .'</tr></table>
    </td></tr>'
    .$soonBlock
    .$noteBlock
    .$sections
    .'<!-- CTA -->
    <tr><td align="center" style="padding:22px 26px 26px 26px">
      <table role="presentation" cellpadding="0" cellspacing="0"><tr><td align="center" bgcolor="#3b82f6" style="border-radius:10px">
        <a href="'.$appUrl.'" style="display:inline-block;padding:12px 28px;font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:10px;background:#3b82f6">Ouvrir le tableau de bord →</a>
      </td></tr></table>
    </td></tr>
    <!-- Footer -->
    <tr><td class="ftr" style="padding:18px 26px;background:#f8fafc;border-top:1px solid #eef2f7;text-align:center;font-family:Arial,Helvetica,sans-serif;font-size:11px;color:#94a3b8">
      <strong style="color:#64748b">Cadurix</strong> · '.$E($site).' — SDIS / SSSM<br>
      Message automatique du '.date('d/m/Y à H:i').'.
      <a href="'.$prefsUrl.'" style="color:#3b82f6;text-decoration:none">Gérer mes alertes</a>
    </td></tr>
  </table>
  <div style="font-family:Arial,Helvetica,sans-serif;font-size:10px;color:#b6c0d4;margin-top:12px">Cadurix — gestion du matériel médical d\'urgence</div>
</td></tr></table>
</body></html>';
}
}

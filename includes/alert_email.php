<?php
// Cadurix — Gabarit HTML de l'email d'alertes (partagé : page admin + cron).

if (!function_exists('buildAlertEmail')) {
function buildAlertEmail(string $site, string $date, string $note, array $include, array $expired, array $critical, array $warning, array $lowstock): string {
    $total = count($expired) + count($critical) + count($warning) + count($lowstock);
    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8">
<style>
body{font-family:Arial,sans-serif;background:#f8fafc;color:#1e293b;margin:0;padding:20px}
.container{max-width:600px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.1)}
.header{background:linear-gradient(135deg,#3b82f6,#8b5cf6);color:#fff;padding:24px;text-align:center}
.header h1{margin:0;font-size:22px}
.header p{margin:6px 0 0;opacity:.85;font-size:13px}
.section{padding:20px 24px;border-bottom:1px solid #e2e8f0}
.section h2{margin:0 0 12px;font-size:15px;display:flex;align-items:center;gap:8px}
table{width:100%;border-collapse:collapse;font-size:13px}
th{text-align:left;padding:6px 8px;background:#f1f5f9;color:#64748b;font-weight:600;font-size:11px;text-transform:uppercase}
td{padding:7px 8px;border-bottom:1px solid #f1f5f9}
.badge{display:inline-block;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:700}
.b-red{background:#fee2e2;color:#dc2626}
.b-amber{background:#fef3c7;color:#d97706}
.b-blue{background:#dbeafe;color:#2563eb}
.footer{padding:16px 24px;text-align:center;font-size:11px;color:#94a3b8;background:#f8fafc}
.note{background:#eff6ff;border-left:3px solid #3b82f6;padding:10px 14px;margin:0 0 16px;font-size:13px;border-radius:0 6px 6px 0}
.kpi-row{display:flex;gap:12px;margin-bottom:16px}
.kpi{flex:1;text-align:center;padding:12px;background:#f8fafc;border-radius:6px;border:1px solid #e2e8f0}
.kpi-val{font-size:22px;font-weight:800}
.kpi-lbl{font-size:11px;color:#64748b;margin-top:2px}
</style></head><body>
<div class="container">
<div class="header">
  <h1>🔔 Rapport d\'alertes Cadurix</h1>
  <p>'.$site.' · '.$date.'</p>
</div>
<div class="section">
  <div class="kpi-row">
    <div class="kpi"><div class="kpi-val" style="color:#6b7280">'.count($expired).'</div><div class="kpi-lbl">Périmés</div></div>
    <div class="kpi"><div class="kpi-val" style="color:#dc2626">'.count($critical).'</div><div class="kpi-lbl">Critiques ≤7j</div></div>
    <div class="kpi"><div class="kpi-val" style="color:#d97706">'.count($warning).'</div><div class="kpi-lbl">Avertissements</div></div>
    <div class="kpi"><div class="kpi-val" style="color:#d97706">'.count($lowstock).'</div><div class="kpi-lbl">Stocks bas</div></div>
  </div>';

    if ($note) $html .= '<div class="note">📝 '.$note.'</div>';

    if (in_array('expired', $include) && !empty($expired)) {
        $html .= '<div class="section"><h2>⛔ Produits périmés ('.count($expired).')</h2><table><thead><tr><th>Produit</th><th>Emplacement</th><th>Lot</th><th>Date</th></tr></thead><tbody>';
        foreach ($expired as $p) $html .= '<tr><td><strong>'.htmlspecialchars($p['nom']).'</strong></td><td>'.htmlspecialchars($p['loc']).'</td><td><code>'.htmlspecialchars($p['numero_lot']??'—').'</code></td><td><span class="badge b-red">'.date('d/m/Y',strtotime($p['date_peremption'])).'</span></td></tr>';
        $html .= '</tbody></table></div>';
    }
    if (in_array('critical', $include) && !empty($critical)) {
        $html .= '<div class="section"><h2>🔴 Critiques ≤7 jours ('.count($critical).')</h2><table><thead><tr><th>Produit</th><th>Emplacement</th><th>Lot</th><th>Péremption</th></tr></thead><tbody>';
        foreach ($critical as $p) { $days = (int)floor((strtotime($p['date_peremption'])-time())/86400); $html .= '<tr><td><strong>'.htmlspecialchars($p['nom']).'</strong></td><td>'.htmlspecialchars($p['loc']).'</td><td><code>'.htmlspecialchars($p['numero_lot']??'—').'</code></td><td><span class="badge b-red">'.date('d/m/Y',strtotime($p['date_peremption'])).' (J-'.$days.')</span></td></tr>'; }
        $html .= '</tbody></table></div>';
    }
    if (in_array('warning', $include) && !empty($warning)) {
        $html .= '<div class="section"><h2>🟡 Avertissements ≤30 jours ('.count($warning).')</h2><table><thead><tr><th>Produit</th><th>Emplacement</th><th>Péremption</th></tr></thead><tbody>';
        foreach ($warning as $p) { $days = (int)floor((strtotime($p['date_peremption'])-time())/86400); $html .= '<tr><td>'.htmlspecialchars($p['nom']).'</td><td>'.htmlspecialchars($p['loc']).'</td><td><span class="badge b-amber">'.date('d/m/Y',strtotime($p['date_peremption'])).' (J-'.$days.')</span></td></tr>'; }
        $html .= '</tbody></table></div>';
    }
    if (in_array('lowstock', $include) && !empty($lowstock)) {
        $html .= '<div class="section"><h2>📦 Stocks bas ('.count($lowstock).')</h2><table><thead><tr><th>Produit</th><th>Emplacement</th><th>Stock</th><th>Minimum</th></tr></thead><tbody>';
        foreach ($lowstock as $p) $html .= '<tr><td>'.htmlspecialchars($p['nom']).'</td><td>'.htmlspecialchars($p['loc']).'</td><td><span class="badge b-amber">'.$p['quantite'].' '.$p['unite'].'</span></td><td>'.$p['quantite_min'].'</td></tr>';
        $html .= '</tbody></table></div>';
    }

    $html .= '<div class="footer">Généré par Cadurix v5.2 · '.date('d/m/Y H:i').'<br>Ce message est automatique.</div></div></body></html>';
    return $html;
}
}

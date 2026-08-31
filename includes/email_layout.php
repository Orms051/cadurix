<?php
// Cadurix — Gabarit d'email partagé (coquille "email-safe" : tableaux + inline,
// thème clair forcé, responsive mobile, logo "en dur"). Utilisé par tous les mails.

if (!function_exists('cdx_email_layout')) {
/**
 * @param string      $preheader  Texte d'aperçu (boîte de réception)
 * @param string      $sousTitre  Sous-titre dans l'en-tête (contexte)
 * @param string      $content    HTML interne (paragraphes / tableaux) — déjà échappé par l'appelant
 * @param array|null  $cta        ['text'=>..., 'url'=>...] bouton optionnel
 * @param string      $footNote   Ligne de bas de page optionnelle
 * @param string      $accent     Couleur d'accent hexa du bouton/bandeau (def bleu)
 */
function cdx_email_layout(string $preheader, string $sousTitre, string $content, ?array $cta = null, string $footNote = '', string $accent = '#3b82f6'): string {
    $E = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $ctaHtml = '';
    if ($cta && !empty($cta['text']) && !empty($cta['url'])) {
        $ctaHtml = '<tr><td align="center" style="padding:20px 26px 26px 26px">'
            .'<table role="presentation" cellpadding="0" cellspacing="0"><tr><td align="center" bgcolor="'.$accent.'" style="border-radius:10px">'
            .'<a href="'.$E($cta['url']).'" style="display:inline-block;padding:12px 28px;font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:10px;background:'.$accent.'">'.$E($cta['text']).'</a>'
            .'</td></tr></table></td></tr>';
    }
    $foot = $footNote !== '' ? '<br>'.$E($footNote) : '';
    return '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light"><title>Cadurix</title>
<style>@media only screen and (max-width:620px){.wrap{width:100%!important}.px{padding-left:16px!important;padding-right:16px!important}}</style></head>
<body style="margin:0;padding:0;background:#eef2f9;-webkit-text-size-adjust:100%">
<div style="display:none;max-height:0;overflow:hidden;opacity:0">'.$E($preheader).'</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef2f9"><tr><td align="center" style="padding:26px 12px">
  <table role="presentation" width="600" cellpadding="0" cellspacing="0" class="wrap" style="width:600px;max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e6ecf5">
    <tr><td style="background:#4f46e5;background:linear-gradient(135deg,#3b82f6,#7c4dff);padding:24px 26px;font-family:Arial,Helvetica,sans-serif" bgcolor="#4f46e5">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
        <td width="48" valign="middle"><div style="width:44px;height:44px;background:#ffffff;border-radius:12px;text-align:center;line-height:44px;font-size:24px;color:#3b82f6;font-weight:800;font-family:Arial,Helvetica,sans-serif">✚</div></td>
        <td valign="middle" style="padding-left:12px"><div style="color:#ffffff;font-size:19px;font-weight:800;line-height:1.15">Cadurix</div><div style="color:#e6ecff;font-size:12.5px;margin-top:2px">'.$E($sousTitre).'</div></td>
      </tr></table>
    </td></tr>
    <tr><td class="px" style="padding:22px 26px 6px 26px;font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#1e293b;line-height:1.6">'.$content.'</td></tr>'
    .$ctaHtml
    .'<tr><td style="padding:18px 26px;background:#f8fafc;border-top:1px solid #eef2f7;text-align:center;font-family:Arial,Helvetica,sans-serif;font-size:11px;color:#94a3b8"><strong style="color:#64748b">Cadurix</strong> — SDIS / SSSM · Gestion du matériel médical'.$foot.'<br>Message automatique du '.date('d/m/Y à H:i').'.</td></tr>
  </table>
</td></tr></table>
</body></html>';
}}

if (!function_exists('cdx_email_kv')) {
// Petit tableau clé/valeur (Date / IP / …) email-safe. $rows = [['📅 Date','...'], ...]
function cdx_email_kv(array $rows): string {
    $E = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $h = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #eef2f7;border-radius:10px;border-collapse:separate;overflow:hidden;margin:6px 0 4px">';
    $n = count($rows); $i = 0;
    foreach ($rows as $r) {
        $i++; $bb = $i < $n ? 'border-bottom:1px solid #eef2f7;' : '';
        $h .= '<tr><td style="padding:10px 12px;'.$bb.'font-family:Arial,Helvetica,sans-serif;font-size:12.5px;color:#64748b;width:130px">'.$E($r[0]).'</td>'
            .'<td style="padding:10px 12px;'.$bb.'font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#1e293b"><strong>'.$E($r[1]).'</strong></td></tr>';
    }
    return $h.'</table>';
}}

if (!function_exists('cdx_email_note')) {
// Encart coloré (info/alerte). $type : info | warn | danger | ok
function cdx_email_note(string $html, string $type = 'info'): string {
    $c = ['info'=>['#eff6ff','#3b82f6','#1e3a8a'],'warn'=>['#fffbeb','#f59e0b','#92600a'],'danger'=>['#fef2f2','#ef4444','#991b1b'],'ok'=>['#f0fdf4','#16a34a','#14532d']][$type] ?? ['#eff6ff','#3b82f6','#1e3a8a'];
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:10px 0 2px"><tr><td style="background:'.$c[0].';border-left:3px solid '.$c[1].';border-radius:0 8px 8px 0;padding:11px 14px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:'.$c[2].';line-height:1.55">'.$html.'</td></tr></table>';
}}

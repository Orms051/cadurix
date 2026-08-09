<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.

if (defined('HEADER_INCLUDED')) return;
define('HEADER_INCLUDED', true);
ob_start();

$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$flash  = function_exists('getFlash')   ? getFlash()          : ['success'=>'','error'=>''];
$unread = function_exists('unreadCount') ? unreadCount($pdo)   : 0;

// Nom affiché
$display_name = trim($_SESSION['user_nom'] ?? $_SESSION['user_name'] ?? 'Utilisateur');
$display_init = strtoupper(substr($display_name, 0, 1));
$display_role = roleLabel();
$display_color= roleColor();

// ── Système de thème complet ─────────────────────────────
if (!function_exists('hexToRgb')) {
function hexToRgb(string $hex): string {
    $hex = ltrim($hex, '#');
    return implode(',', [hexdec(substr($hex,0,2)), hexdec(substr($hex,2,2)), hexdec(substr($hex,4,2))]);
}
}

// ── Thème : source unique + résolution ───────────────────
require_once __DIR__ . '/../config/themes.php';

// 1) thème par défaut du site (admin) + verrou de personnalisation
$__theme_defaut = THEME_FALLBACK;
$__theme_lock   = false;
try {
    $__tp = $pdo->query("SELECT cle, valeur FROM parametres WHERE cle IN ('theme_defaut','theme_lock')")
                ->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!empty($__tp['theme_defaut'])) $__theme_defaut = $__tp['theme_defaut'];
    $__theme_lock = (($__tp['theme_lock'] ?? '0') === '1');
} catch (Throwable $e) {}

// 2) thème perso de l'utilisateur (ignoré si verrou activé)
$__utc = $__uca = null;
if (!$__theme_lock && !empty($_SESSION['user_id'])) {
    try {
        $__st = $pdo->prepare("SELECT theme_config, couleur_accent FROM utilisateurs WHERE id=?");
        $__st->execute([$_SESSION['user_id']]);
        if ($__trow = $__st->fetch(PDO::FETCH_ASSOC)) {
            $__utc = $__trow['theme_config']   ?? null;
            $__uca = $__trow['couleur_accent'] ?? null;
        }
    } catch (Throwable $e) {}
}

// 3) résolution finale (base admin + override user + validation hex)
$theme = theme_resolve($__theme_defaut, $__utc, $__uca);

$accent_rgb  = hexToRgb($theme['accent']);
$logo1_rgb   = hexToRgb($theme['logo1']);
$bg_rgb      = hexToRgb($theme['bg']);

// ── AJAX Recherche globale ───────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search') {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) { echo json_encode([]); exit; }
    $sid = site_actif();
    $results = [];
    $like = '%' . $q . '%';

    // Médicaments
    try {
        $st = $pdo->prepare("
            SELECT p.id, p.nom, p.numero_lot, e.nom AS loc,
                   DATEDIFF(p.date_peremption, CURDATE()) AS days_left
            FROM produits p JOIN emplacements e ON e.id=p.emplacement_id
            WHERE e.site_id=? AND p.statut='actif'
              AND (p.nom LIKE ? OR p.numero_lot LIKE ? OR p.reference LIKE ?)
            LIMIT 5
        ");
        $st->execute([$sid, $like, $like, $like]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $results[] = ['type'=>'medicament','icon'=>'💊','title'=>$r['nom'],
                'sub'=>$r['loc'].($r['numero_lot']?' · Lot '.$r['numero_lot']:''),
                'url'=>'/products.php?edit='.$r['id'],
                'badge'=>$r['days_left']<0?'Périmé':($r['days_left']<=7?'Critique':''),
                'badge_color'=>$r['days_left']<0?'#6b7280':($r['days_left']<=7?'#EF4444':'')];
        }
    } catch(Exception $e) {}

    // Emplacements
    try {
        $st = $pdo->prepare("
            SELECT e.id, e.nom, ep.nom AS parent, COUNT(p.id) AS nb
            FROM emplacements e
            LEFT JOIN emplacements ep ON ep.id=e.parent_id
            LEFT JOIN produits p ON p.emplacement_id=e.id AND p.statut='actif'
            WHERE e.site_id=? AND e.actif=1 AND e.nom LIKE ?
            GROUP BY e.id LIMIT 3
        ");
        $st->execute([$sid, $like]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $results[] = ['type'=>'emplacement','icon'=>'📍','title'=>$r['nom'],
                'sub'=>($r['parent']?$r['parent'].' · ':'').$r['nb'].' produit(s)',
                'url'=>'/locations.php?id='.$r['id'],'badge'=>'','badge_color'=>''];
        }
    } catch(Exception $e) {}

    // Utilisateurs (admins seulement)
    if ($_SESSION['super_admin'] ?? false) {
        try {
            $st = $pdo->prepare("
                SELECT id, prenom, nom, login, email FROM utilisateurs
                WHERE actif=1 AND (prenom LIKE ? OR nom LIKE ? OR login LIKE ? OR email LIKE ?)
                LIMIT 3
            ");
            $st->execute([$like, $like, $like, $like]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $results[] = ['type'=>'utilisateur','icon'=>'👤',
                    'title'=>$r['prenom'].' '.$r['nom'],
                    'sub'=>$r['login'].' · '.$r['email'],
                    'url'=>'/admin/users.php?action=edit&id='.$r['id'].'&onglet=utilisateurs',
                    'badge'=>'','badge_color'=>''];
            }
        } catch(Exception $e) {}
    }

    echo json_encode($results);
    exit;
}

// ── Matrice permissions depuis BDD ───────────────────────
$nav_perms = [];
try {
    $role_actif = role_sur_site() ?? '';
    if ($_SESSION['super_admin'] ?? false) {
        // Super admin : tout à 2
        foreach (['dashboard','medicaments','emplacements','alertes','rapports','import_export',
                  'scanner','qrcodes','fiches','rempotages','terrain','sse',
                  'equipements','biomed','biomed_admin',
                  'ia','ia_config','messagerie',
                  'admin','admin_users','admin_audit','admin_alerts','admin_system'] as $m) {
            $nav_perms[$m] = 2;
        }
    } elseif ($role_actif) {
        $sid_nav = site_actif();
        // Charger permissions globales du rôle
        $rows = $pdo->prepare("SELECT module,niveau FROM role_permissions WHERE role=?");
        $rows->execute([$role_actif]);
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $nav_perms[$r['module']] = (int)$r['niveau'];
        }
        // Surcharger avec les permissions spécifiques au site si elles existent
        if ($sid_nav) {
            try {
                $rows2 = $pdo->prepare("SELECT module,niveau FROM site_role_permissions WHERE site_id=? AND role=?");
                $rows2->execute([$sid_nav, $role_actif]);
                foreach ($rows2->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $nav_perms[$r['module']] = (int)$r['niveau']; // Surcharge le global
                }
            } catch(Exception $e) {}
        }
    }
} catch (Exception $e) {}

// ── Affichage par plateforme (PC / mobile) selon le rôle (table affichage_modules) ──
$aff_map = [];
if (!empty($role_actif) && !($_SESSION['super_admin'] ?? false)) {
    try {
        $_af = $pdo->prepare("SELECT module, visible_pc, visible_mobile FROM affichage_modules WHERE role=?");
        $_af->execute([$role_actif]);
        foreach ($_af->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $aff_map[$r['module']] = ['pc' => (int)$r['visible_pc'], 'mobile' => (int)$r['visible_mobile']];
        }
    } catch (Throwable $e) {}
}
// Visible = a le droit (nav_peut) ET non masqué pour la plateforme (défaut : visible)
if (!function_exists('aff_pc')) {
    function aff_pc(string $module, int $min = 1): bool {
        global $nav_perms, $aff_map;
        if (!nav_peut($nav_perms ?? [], $module, $min)) return false;
        return (int)($aff_map[$module]['pc'] ?? 1) === 1;
    }
    function aff_mob(string $module, int $min = 1): bool {
        global $nav_perms, $aff_map;
        if (!nav_peut($nav_perms ?? [], $module, $min)) return false;
        return (int)($aff_map[$module]['mobile'] ?? 1) === 1;
    }
}

// Helper : niveau minimum requis (1=lecture, 2=écriture)
if (!function_exists('nav_peut')) {
function nav_peut(array $perms, string $module, int $min = 1): bool {
    // 1) Droit du rôle (comportement d'origine)
    if (($perms[$module] ?? 0) < $min) return false;

    // 2) Gate par le système de modules — carte centralisée (perms_modules.php)
    $map = function_exists('module_flag_map') ? module_flag_map() : [];
    if (isset($map[$module]) && function_exists('module_actif') && !module_actif($map[$module])) {
        return false;
    }
    return true;
}
}

// Site actif info
$site_actif_nom = '';
if (site_actif()) {
    try {
        $s = $pdo->prepare("SELECT s.nom, o.nom AS org FROM sites s JOIN organisations o ON o.id=s.organisation_id WHERE s.id=?");
        $s->execute([site_actif()]);
        $si = $s->fetch(PDO::FETCH_ASSOC);
        if ($si) $site_actif_nom = $si['org'] . ' — ' . $si['nom'];
    } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<meta name="csrf-token" content="<?= csrf_token() ?>">
<title>Cadurix — <?= h($pageTitle ?? 'Tableau de bord') ?></title>
<link rel="stylesheet" href="/libs/tabler/tabler-icons.min.css">
<style>
/* ── Variables ───────────────────────────────────────────── */
:root {
  --bg:        <?= $theme['bg'] ?>;
  --bg2:       <?= $theme['bg2'] ?>;
  --bg3:       <?= $theme['bg3'] ?>;
  --sidebar:   <?= $theme['sidebar'] ?>;
  --glass:     rgba(255,255,255,0.04);
  --glass2:    rgba(255,255,255,0.07);
  --border:    <?= $theme['border'] ?>;
  --border2:   <?= $theme['border2'] ?>;
  --red:       #EF4444;
  --red-glow:  rgba(239,68,68,0.35);
  --amber:     #F59E0B;
  --green:     #10B981;
  --violet:    #8B5CF6;
  --blue:      #3B82F6;
  --cyan:      #06B6D4;
  --cold:      #60a5fa;
  --text:      <?= $theme['text'] ?>;
  --text2:     <?= $theme['text2'] ?>;
  --text3:     <?= $theme['text3'] ?>;
  --sidebar-w: 256px;
  --topbar-h:  56px;
  --radius:    14px;
  --radius-sm: 9px;
  --font:      'Plus Jakarta Sans', sans-serif;
  --mono:      'JetBrains Mono', monospace;
  --shadow:    0 4px 24px rgba(0,0,0,0.4);
  --primary:      <?= $theme['accent'] ?>;
  --primary-rgb:  <?= $accent_rgb ?>;
  --primary-glow: rgba(<?= $accent_rgb ?>, 0.35);
  --primary-soft: rgba(<?= $accent_rgb ?>, 0.12);
  --primary-bg:   rgba(<?= $accent_rgb ?>, 0.06);
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; }

/* ── Accessibilité : focus clavier visible + mouvement réduit ── */
:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; border-radius: 4px; }
/* Ne pas afficher l'anneau lors d'un clic souris (uniquement au clavier) */
a:focus:not(:focus-visible), button:focus:not(:focus-visible),
input:focus:not(:focus-visible), select:focus:not(:focus-visible),
textarea:focus:not(:focus-visible) { outline: none; }
@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after {
    animation-duration: .001ms !important; animation-iteration-count: 1 !important;
    transition-duration: .001ms !important; scroll-behavior: auto !important;
  }
}
body { font-family: var(--font); background: var(--bg); color: var(--text); min-height: 100vh; display: flex; overflow-x: hidden; transition: background .3s, color .3s; }
body::before {
  content: ''; position: fixed; inset: 0; z-index: 0; pointer-events: none;
  background:
    radial-gradient(ellipse 800px 600px at -10% 20%, rgba(<?= $accent_rgb ?>,0.08) 0%, transparent 70%),
    radial-gradient(ellipse 600px 500px at 110% 80%, rgba(<?= $logo1_rgb ?>,0.05) 0%, transparent 70%);
}

/* ── Sidebar ─────────────────────────────────────────────── */
.sidebar {
  width: var(--sidebar-w); height: 100vh;
  background: var(--sidebar);
  border-right: 1px solid var(--border);
  display: flex; flex-direction: column;
  position: fixed; top: 0; left: 0; z-index: 300;
  overflow: hidden; transition: transform .28s cubic-bezier(.4,0,.2,1);
}
.sidebar-logo {
  padding: 20px 18px 16px;
  border-bottom: 1px solid var(--border);
  display: flex; align-items: center; gap: 12px; flex-shrink: 0;
}
.sidebar-logo:hover .logo-mark {
  box-shadow: 0 6px 20px var(--primary-glow);
}
.logo-mark {
  width: 38px; height: 38px;
  background: linear-gradient(135deg, var(--primary), rgba(var(--primary-rgb),.6));
  border-radius: 11px; display: flex; align-items: center; justify-content: center;
  font-size: 18px; flex-shrink: 0;
  box-shadow: 0 4px 14px var(--primary-glow);
  transition: box-shadow .3s;
}
.logo-name    { font-size: 16px; font-weight: 800; color: var(--text); letter-spacing: -.3px; }
.logo-tagline { font-size: 9.5px; color: var(--text3); letter-spacing: 2px; text-transform: uppercase; margin-top: 1px; }

/* Site actif badge dans sidebar */
.site-badge {
  margin: 8px 12px 4px;
  padding: 7px 12px;
  background: var(--primary-bg);
  border: 1px solid rgba(var(--primary-rgb),.18);
  border-radius: 9px;
  font-size: 11px;
  color: var(--primary);
  font-weight: 600;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  letter-spacing: .01em;
  display: block;
  width: auto;
  font-family: inherit;
  appearance: none;
  -webkit-appearance: none;
}
button.site-badge {
  cursor: pointer;
  white-space: normal;
  overflow: visible;
}
button.site-badge:hover {
  background: rgba(var(--primary-rgb),.18);
}

.nav {
  padding: 10px 10px; flex: 1;
  display: flex; flex-direction: column; gap: 1px;
  overflow-y: auto; overflow-x: hidden;
  min-height: 0; /* important pour que flex+overflow fonctionne */
}
.nav-label {
  font-size: 9px; font-weight: 800; letter-spacing: 2.5px; text-transform: uppercase;
  color: var(--text3); padding: 16px 12px 5px;
  display: flex; align-items: center; gap: 8px;
}
.nav-label::after {
  content: ''; flex: 1; height: 1px;
  background: linear-gradient(90deg, var(--border), transparent);
}
.nav-link {
  display: flex; align-items: center; gap: 10px;
  padding: 8px 12px; border-radius: var(--radius-sm);
  color: var(--text2); text-decoration: none; font-size: 13px; font-weight: 500;
  transition: all .18s cubic-bezier(.4,0,.2,1); position: relative;
  margin: 1px 0;
}
.nav-link:hover { background: var(--glass2); color: var(--text); transform: translateX(2px); }
.nav-link.active { background: var(--primary-soft); color: var(--primary); border: 1px solid rgba(var(--primary-rgb),.2); }
.nav-link.active::before { content:''; position:absolute; left:0; top:20%; bottom:20%; width:3px; background:var(--primary); border-radius:0 3px 3px 0; }
.nav-icon {
  font-size: 16px; width: 26px; height: 26px;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0; border-radius: 7px;
  transition: transform .18s;
}
.nav-link:hover .nav-icon { transform: scale(1.15); }
.nav-link.active .nav-icon {
  background: rgba(var(--primary-rgb), .15);
}
.nav-badge { margin-left: auto; background: var(--red); color: white; font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 20px; animation: pulse-b 2s infinite; }
@keyframes pulse-b { 0%,100%{box-shadow:0 0 0 0 rgba(239,68,68,.5)} 50%{box-shadow:0 0 0 4px rgba(239,68,68,0)} }

.sidebar-footer { padding: 12px 14px; border-top: 1px solid var(--border); flex-shrink: 0; background: var(--sidebar); }
.sidebar-user {
  display: flex; align-items: center; gap: 9px;
  padding: 10px 12px; border-radius: var(--radius-sm);
  background: var(--glass); border: 1px solid var(--border);
  transition: border-color .2s;
}
.sidebar-user:hover { border-color: var(--border2); }
.user-av { width:32px; height:32px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:800; color:white; flex-shrink:0; }
.user-name-text { font-size:12.5px; font-weight:600; color:var(--text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.role-pill { display:inline-flex; align-items:center; gap:4px; font-size:9.5px; font-weight:700; padding:2px 7px; border-radius:20px; margin-top:2px; }
.logout-icon { color:var(--text3); font-size:16px; text-decoration:none; transition:color .15s; padding:4px; border-radius:6px; flex-shrink:0; }
.logout-icon:hover { color:var(--red); background:rgba(239,68,68,.1); }

/* ── Topbar mobile ───────────────────────────────────────── */
.topbar {
  display: none;
  position: fixed; top: 0; left: 0; right: 0; height: var(--topbar-h);
  background: var(--sidebar); backdrop-filter: blur(20px);
  border-bottom: 1px solid var(--border);
  z-index: 400; align-items: center; justify-content: space-between;
  padding: 0 16px;
}
.topbar-logo { display:flex; align-items:center; gap:10px; }
.topbar-right { display:flex; align-items:center; gap:10px; }
.hamburger {
  width: 36px; height: 36px; border-radius: 9px; border: 1px solid var(--border);
  background: var(--glass); cursor: pointer; display: flex; flex-direction: column;
  align-items: center; justify-content: center; gap: 5px; padding: 0;
}
.hamburger span { display:block; width:16px; height:1.5px; background:var(--text2); border-radius:2px; transition:all .2s; }
.topbar-unread { background:var(--red); color:white; font-size:10px; font-weight:700; padding:2px 6px; border-radius:20px; }
.sidebar-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.6); z-index:299; opacity:0; transition:opacity .28s; }

/* ── Main ────────────────────────────────────────────────── */
.main { margin-left: var(--sidebar-w); flex: 1; padding: 30px 32px; position: relative; z-index: 1; min-width: 0; }

/* ── Page header ─────────────────────────────────────────── */
.page-header { margin-bottom: 26px; display:flex; align-items:flex-end; justify-content:space-between; flex-wrap:wrap; gap:12px; }
.page-title  { font-size: 26px; font-weight: 800; color: var(--text); letter-spacing: -.5px; line-height: 1.2; }
.page-sub    { color: var(--text2); font-size: 13px; margin-top: 3px; }
.page-title-group { display:flex; flex-direction:column; }

/* ── Cards ───────────────────────────────────────────────── */
.card { background: var(--bg3); border: 1px solid var(--border); border-radius: var(--radius); padding: 20px; box-shadow: var(--shadow); transition: border-color .2s; }
.card:hover { border-color: var(--border2); }
.card-title { font-size: 11px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; color: var(--text3); margin-bottom: 14px; }
.kpi-card { background:var(--bg3); border:1px solid var(--border); border-radius:var(--radius); padding:18px 20px; transition:transform .2s,box-shadow .2s; }
.kpi-card:hover { transform:translateY(-2px); box-shadow:0 12px 40px rgba(0,0,0,.4); }
.kpi-label { font-size:11px; font-weight:600; letter-spacing:1.5px; text-transform:uppercase; color:var(--text3); margin-bottom:7px; }
.kpi-value { font-size:38px; font-weight:800; letter-spacing:-2px; line-height:1; }
.kpi-foot  { font-size:11.5px; color:var(--text2); margin-top:5px; }

/* ── Grids ───────────────────────────────────────────────── */
.grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
.grid-3 { display:grid; grid-template-columns:repeat(3,1fr); gap:16px; }
.grid-4 { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; }

/* ── Badges ──────────────────────────────────────────────── */
.badge { display:inline-flex; align-items:center; gap:4px; padding:3px 9px; border-radius:20px; font-size:11px; font-weight:600; white-space:nowrap; }
.badge-ok       { background:rgba(16,185,129,.15);  color:#10B981; border:1px solid rgba(16,185,129,.2); }
.badge-warning  { background:rgba(245,158,11,.15);  color:#F59E0B; border:1px solid rgba(245,158,11,.2); }
.badge-critical { background:rgba(239,68,68,.15);   color:#f87171; border:1px solid rgba(239,68,68,.2); }
.badge-expired  { background:rgba(107,114,128,.15); color:#9CA3AF; border:1px solid rgba(107,114,128,.2); }
.badge-stock    { background:rgba(245,158,11,.15);  color:#F59E0B; border:1px solid rgba(245,158,11,.2); }
.badge-cold     { background:rgba(96,165,250,.15);  color:#60a5fa; border:1px solid rgba(96,165,250,.2); }
.badge-blue     { background:rgba(59,130,246,.15);  color:#93c5fd; border:1px solid rgba(59,130,246,.2); }
.badge-violet   { background:rgba(139,92,246,.15);  color:#a78bfa; border:1px solid rgba(139,92,246,.2); }

/* ── Boutons ─────────────────────────────────────────────── */
.btn { display:inline-flex; align-items:center; justify-content:center; gap:6px; padding:9px 18px; border-radius:var(--radius-sm); font-size:13.5px; font-weight:600; cursor:pointer; border:none; text-decoration:none; transition:all .16s; font-family:var(--font); }
.btn-primary { background:var(--primary); color:white; box-shadow:0 2px 12px var(--primary-glow); }
.btn-primary:hover { filter:brightness(1.12); transform:translateY(-1px); box-shadow:0 6px 20px var(--primary-glow); }
.btn-ghost   { background:var(--glass); border:1px solid var(--border); color:var(--text2); }
.btn-ghost:hover { background:var(--glass2); color:var(--text); }
.btn-danger  { background:rgba(239,68,68,.15); border:1px solid rgba(239,68,68,.25); color:#f87171; }
.btn-danger:hover { background:rgba(239,68,68,.25); }
.btn-sm  { padding:6px 14px; font-size:12.5px; }
.btn-xs  { padding:4px 9px;  font-size:11.5px; }

/* ── Formulaires ─────────────────────────────────────────── */
.form-group { margin-bottom: 16px; }
.form-label { display:block; font-size:11px; font-weight:600; letter-spacing:1px; text-transform:uppercase; color:var(--text3); margin-bottom:7px; }
.form-control { width:100%; padding:10px 14px; background:rgba(255,255,255,.05); border:1px solid var(--border); border-radius:var(--radius-sm); color:var(--text); font-size:13.5px; font-family:var(--font); outline:none; transition:border-color .2s,box-shadow .2s; }
.form-control:focus { border-color:rgba(59,130,246,.5); box-shadow:0 0 0 3px rgba(59,130,246,.1); }
.form-control::placeholder { color:var(--text3); }
select.form-control option { background:var(--bg2); }
.form-hint { font-size:11px; color:var(--text3); margin-top:5px; }
.form-row  { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.form-row3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:14px; }
textarea.form-control { resize:vertical; min-height:100px; }

/* ── Tables ──────────────────────────────────────────────── */
.table-wrap { overflow-x:auto; }
table { width:100%; border-collapse:collapse; font-size:13.5px; }
thead tr { border-bottom:1px solid var(--border2); }
th { padding:10px 14px; text-align:left; font-size:10.5px; font-weight:700; letter-spacing:1px; text-transform:uppercase; color:var(--text3); white-space:nowrap; }
td { padding:11px 14px; border-bottom:1px solid rgba(255,255,255,.04); vertical-align:middle; }
tbody tr:hover td { background:rgba(255,255,255,.025); }
tbody tr:last-child td { border-bottom:none; }

/* ── Flash ───────────────────────────────────────────────── */
.flash { display:flex; align-items:center; gap:10px; padding:12px 15px; border-radius:var(--radius-sm); font-size:13px; font-weight:500; margin-bottom:18px; animation:slideD .3s ease; }
@keyframes slideD { from{opacity:0;transform:translateY(-6px)} to{opacity:1;transform:translateY(0)} }
.flash-success { background:rgba(16,185,129,.12);  border:1px solid rgba(16,185,129,.25); color:#34d399; }
.flash-error   { background:rgba(239,68,68,.12);   border:1px solid rgba(239,68,68,.25);  color:#f87171; }
.flash-info    { background:rgba(59,130,246,.12);  border:1px solid rgba(59,130,246,.25); color:#60a5fa; }
.flash-locked  { background:rgba(139,92,246,.12);  border:1px solid rgba(139,92,246,.25); color:#a78bfa; }

/* ── Toggle ──────────────────────────────────────────────── */
.toggle-wrap { display:flex; align-items:center; gap:10px; }
.toggle { position:relative; width:40px; height:22px; flex-shrink:0; }
.toggle input { opacity:0; width:0; height:0; position:absolute; }
.toggle-track { position:absolute; inset:0; background:var(--glass2); border:1px solid var(--border); border-radius:11px; cursor:pointer; transition:background .2s; }
.toggle input:checked + .toggle-track { background:var(--blue); border-color:var(--blue); }
.toggle-thumb { position:absolute; top:2px; left:2px; width:16px; height:16px; background:white; border-radius:50%; transition:transform .2s; pointer-events:none; }
.toggle input:checked ~ .toggle-thumb { transform:translateX(18px); }

/* ── Misc ────────────────────────────────────────────────── */
.dot  { display:inline-block; width:8px; height:8px; border-radius:50%; flex-shrink:0; }
.sep  { height:1px; background:var(--border); margin:14px 0; }
.breadcrumb { display:flex; align-items:center; gap:6px; font-size:12px; color:var(--text3); margin-bottom:8px; flex-wrap:wrap; }
.breadcrumb a { color:var(--text2); text-decoration:none; }
.breadcrumb a:hover { color:var(--text); }
.cold-icon { color:var(--cold); }
::-webkit-scrollbar { width:4px; height:4px; }
::-webkit-scrollbar-thumb { background:var(--border2); border-radius:10px; }
.sidebar::-webkit-scrollbar { width:3px; }
.sidebar::-webkit-scrollbar-thumb { background: rgba(var(--primary-rgb),.25); border-radius:10px; }

/* ── Recherche globale ───────────────────────────────────── */
.topbar-search {
  flex: 1; max-width: 380px; position: relative;
  display: none; /* visible sur desktop via media query */
}
.tbs-inner {
  display: flex; align-items: center; gap:8px;
  background: var(--bg); border: 1px solid var(--border);
  border-radius: 8px; padding: 6px 10px; transition: border-color .15s;
}
.tbs-inner:focus-within { border-color: var(--primary); }
.tbs-icon  { font-size:14px; color:var(--text3); flex-shrink:0; }
.tbs-input {
  flex:1; background:none; border:none; outline:none;
  font-size:13px; color:var(--text); font-family:var(--font);
}
.tbs-input::placeholder { color: var(--text3); }
.tbs-clear { background:none; border:none; cursor:pointer; color:var(--text3); font-size:13px; padding:0 2px; }
.tbs-clear:hover { color:var(--text); }

.search-results {
  position: absolute; top: calc(100% + 4px); left: 0; right: 0;
  background: var(--bg2); border: 1px solid var(--border);
  border-radius: 10px; box-shadow: 0 8px 32px rgba(0,0,0,.3);
  z-index: 9999; max-height: 380px; overflow-y: auto;
}
.sr-item {
  display: flex; align-items: center; gap: 10px;
  padding: 10px 14px; cursor: pointer;
  border-bottom: 1px solid var(--border);
  text-decoration: none; color: var(--text);
  transition: background .1s;
}
.sr-item:last-child { border-bottom: none; }
.sr-item:hover { background: var(--glass); }
.sr-icon { font-size: 16px; flex-shrink:0; width:28px; text-align:center; }
.sr-title { font-size: 13px; font-weight: 600; }
.sr-sub   { font-size: 11px; color: var(--text2); margin-top: 1px; }
.sr-badge { font-size: 10px; padding: 1px 7px; border-radius: 99px; font-weight: 600; flex-shrink:0; margin-left:auto; }
.sr-empty { padding: 16px; text-align:center; font-size:13px; color:var(--text2); }
.sr-loading { padding: 16px; text-align:center; font-size:13px; color:var(--text3); }
.sr-section { padding: 5px 14px 3px; font-size: 10px; font-weight: 700; letter-spacing:.08em; text-transform:uppercase; color:var(--text3); background:var(--bg3); }

@media (min-width: 901px) {
  .topbar-search { display: block; }
}
@media (max-width: 900px) {
  .topbar { gap: 8px; }
  .topbar-search { display:block; max-width:none; flex:1; }
}

/* ── Responsive ──────────────────────────────────────────── */
@media (max-width: 900px) {
  .sidebar { transform: translateX(-100%); }
  .sidebar.mobile-open { transform: translateX(0); box-shadow: 4px 0 40px rgba(0,0,0,.6); }
  .sidebar-overlay.mobile-open { display:block; opacity:1; }
  .topbar { display: flex; }
  .main { margin-left: 0; padding: 16px; padding-top: calc(var(--topbar-h) + 16px); }
  .grid-4 { grid-template-columns: 1fr 1fr; }
  .grid-3 { grid-template-columns: 1fr 1fr; }
  .grid-2 { grid-template-columns: 1fr; }
  .form-row  { grid-template-columns: 1fr; }
  .form-row3 { grid-template-columns: 1fr 1fr; }
  .page-title { font-size: 20px; }
  .kpi-value  { font-size: 30px; }
  .hide-mobile { display: none !important; }

  /* Cards & tableaux */
  .card { border-radius: 10px; padding: 14px; }
  .table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
  .table-wrap table { min-width: 600px; }

  /* Page header mobile */
  .page-header {
    flex-direction: column;
    align-items: flex-start;
    gap: 10px;
  }
  .page-header .page-title-group { width: 100%; }
  .page-sub { font-size: 12px; }

  /* Boutons plus larges sur tactile */
  .btn { min-height: 40px; }
  .btn-xs { min-height: 34px; padding: 6px 10px; }

  /* KPI cards */
  .kpi-card { padding: 12px; }
  .kpi-label { font-size: 11px; }

  /* Flash messages */
  .flash { font-size: 13px; padding: 10px 12px; }
}

@media (max-width: 480px) {
  .grid-4, .grid-3 { grid-template-columns: 1fr 1fr; }
  .grid-2 { grid-template-columns: 1fr; }
  .form-row3 { grid-template-columns: 1fr; }
  .btn { padding: 8px 13px; font-size: 13px; }
  .page-title { font-size: 18px; }
  .main { padding: 12px; padding-top: calc(var(--topbar-h) + 12px); }

  /* Sidebar logo compact */
  .logo-tagline { display: none; }

  /* Topbar search plus compact */
  .topbar-search { max-width: none; }
}

/* Touch targets minimum 44px */
@media (hover: none) and (pointer: coarse) {
  .btn, .nav-link, .logout-icon { min-height: 44px; }
  .form-control, .form-control select { min-height: 44px; font-size: 16px; } /* Évite zoom iOS */
  input[type="text"], input[type="email"], input[type="password"],
  input[type="number"], input[type="date"], textarea, select {
    font-size: 16px !important; /* Empêche zoom automatique iOS */
  }
}
</style>
<script>
// Clé VAPID publique — sera remplie quand le site sera en prod
window.VAPID_PUBLIC_KEY = <?php
    try {
        $vk = $pdo->query("SELECT valeur FROM parametres WHERE cle='vapid_public_key'")->fetchColumn();
        echo json_encode($vk ?: '');
    } catch(Exception $e) { echo "''"; }
?>;
</script>
<script src="/assets/js/push_init.js" defer></script>
<script>
window.VAPID_PUBLIC_KEY = <?php
    try { $vk=$pdo->query("SELECT valeur FROM parametres WHERE cle='vapid_public_key'")->fetchColumn(); echo json_encode($vk?:''); }
    catch(Exception $e){ echo "''"; }
?>;
</script>
<script src="/assets/js/push_init.js" defer></script>
  <link rel="stylesheet" href="/assets/css/fonts.css">
  <link rel="stylesheet" href="/assets/css/mobile.css">
  <style>
  @media (max-width: 768px) {
    /* Topbar mobile épurée */
    .topbar-search { display: none !important; }
    .topbar { padding: 0 12px !important; gap: 8px; }
    .topbar-logo span { font-size: 14px !important; }
    /* Switcher site — masqué dans sidebar, affiché en pills dans topbar */
    .sidebar { display: none !important; }
    /* Bottom nav au-dessus de la home bar iOS */
    .bottom-nav {
      padding-bottom: max(env(safe-area-inset-bottom, 0px), 4px) !important;
      height: calc(64px + max(env(safe-area-inset-bottom, 0px), 0px)) !important;
    }
  }
  </style>
</head>
<body>

<!-- TOPBAR MOBILE -->
<div class="topbar">
  <div class="topbar-logo">
    <img src="/logo.php?size=32&icon" alt="Cadurix" style="width:32px;height:32px;border-radius:9px;">
    <span style="font-size:15px;font-weight:800;color:var(--text);">Cadurix</span>
  </div>
  <!-- Recherche globale topbar -->
  <div class="topbar-search" id="topbar-search-wrap">
    <div class="tbs-inner">
      <i class="ti ti-search tbs-icon"></i>
      <input type="text" id="global-search" class="tbs-input"
             placeholder="Rechercher médicament, emplacement…"
             autocomplete="off" oninput="globalSearch(this.value)">
      <button class="tbs-clear" id="tbs-clear" onclick="clearSearch()" style="display:none">✕</button>
    </div>
    <div id="search-results" class="search-results" style="display:none"></div>
  </div>
  <div class="topbar-right">
    <?php if ($unread > 0): ?>
    <a href="/messaging.php" style="text-decoration:none;">
      <span class="topbar-unread"><?= $unread ?> 💬</span>
    </a>
    <?php endif; ?>
    <button class="hamburger" onclick="toggleSidebar()" aria-label="Menu">
      <span></span><span></span><span></span>
    </button>
  </div>
</div>

<!-- OVERLAY MOBILE -->
<div class="sidebar-overlay" id="overlay" onclick="closeSidebar()"></div>

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
  <div class="sidebar-logo">
    <img src="/logo.php?size=40&icon" alt="Cadurix" style="width:40px;height:40px;border-radius:10px;">
    <div>
      <div class="logo-name">Cadurix</div>
      <div class="logo-tagline">Péremptions v5</div>
    </div>
  </div>

  <?php if ($site_actif_nom): ?>
  <?php
    // Icône selon type d'organisation
    $site_icon = '🏢';
    try {
        $si_type = $pdo->prepare("SELECT o.type FROM sites s JOIN organisations o ON o.id=s.organisation_id WHERE s.id=?");
        $si_type->execute([site_actif()]);
        $org_type = $si_type->fetchColumn();
        $site_icon = match($org_type ?? '') {
            'sdis','pompiers'  => '🚒',
            'hopital','chu','clinique' => '🏥',
            'samu','smur'      => '🚑',
            default            => '🏢',
        };
    } catch(Exception $e) {}
  ?>
  <?php
  // Charger tous les sites accessibles pour le switcher
  $sites_switch = sites_accessibles($pdo);
  ?>
  <?php if (count($sites_switch) > 1): ?>
  <div style="position:relative;margin:6px 10px 2px" id="site-switcher-wrap">
    <button onclick="var dd=document.getElementById('site-switcher-dropdown');dd.style.display=dd.style.display==='none'?'block':'none'"
            style="width:100%;display:flex;align-items:center;gap:7px;padding:6px 10px;background:var(--primary-bg);border:1px solid rgba(var(--primary-rgb),.2);border-radius:8px;cursor:pointer;font-family:inherit;transition:background .15s"
            title="Changer de site">
      <span style="font-size:13px;flex-shrink:0"><?= $site_icon ?></span>
      <div style="flex:1;min-width:0;text-align:left">
        <div style="font-size:11px;font-weight:700;color:var(--primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= h($sites_switch[array_search(site_actif(), array_column($sites_switch,'id'))]['nom'] ?? $site_actif_nom) ?></div>
        <div style="font-size:9px;color:var(--text3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= h($sites_switch[array_search(site_actif(), array_column($sites_switch,'id'))]['org_nom'] ?? '') ?></div>
      </div>
      <i class="ti ti-selector" style="font-size:12px;color:var(--text3);flex-shrink:0"></i>
    </button>
    <div id="site-switcher-dropdown" style="display:none;position:absolute;top:calc(100% + 4px);left:0;right:0;background:var(--bg2);border:1px solid var(--border2);border-radius:10px;box-shadow:0 8px 32px rgba(0,0,0,.5);z-index:9999;overflow:hidden">
      <div style="padding:6px 10px;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:var(--text3);border-bottom:1px solid var(--border)">Changer de site</div>
      <?php foreach ($sites_switch as $sw):
        $is_current = $sw['id'] == site_actif();
        $sw_icon = match($sw['org_type']??'') { 'sdis','pompiers'=>'🚒','hopital','chu','clinique'=>'🏥','samu','smur'=>'🚑',default=>'🏢' };
      ?>
      <a href="/switch_site.php?site_id=<?= $sw['id'] ?>&redirect=<?= urlencode($_SERVER['REQUEST_URI']) ?>"
         style="display:flex;align-items:center;gap:9px;padding:8px 10px;font-size:.8rem;text-decoration:none;color:<?= $is_current?'var(--primary)':'var(--text)' ?>;background:<?= $is_current?'var(--primary-bg)':'' ?>;transition:background .1s"
         onmouseover="this.style.background='var(--glass)'" onmouseout="this.style.background='<?= $is_current?'var(--primary-bg)':'' ?>'">
        <span style="font-size:14px;width:20px;text-align:center;flex-shrink:0"><?= $sw_icon ?></span>
        <div style="flex:1;min-width:0">
          <div style="font-weight:<?= $is_current?'700':'500' ?>;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= h($sw['nom']) ?></div>
          <div style="font-size:.68rem;color:var(--text3)"><?= h($sw['org_nom']) ?></div>
        </div>
        <?php if ($is_current): ?>
        <span style="width:6px;height:6px;border-radius:50%;background:var(--primary);flex-shrink:0"></span>
        <?php endif; ?>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php else: ?>
  <div style="margin:6px 10px 2px;padding:6px 10px;background:var(--primary-bg);border:1px solid rgba(var(--primary-rgb),.2);border-radius:8px;display:flex;align-items:center;gap:7px">
    <span style="font-size:13px"><?= $site_icon ?></span>
    <div style="min-width:0">
      <div style="font-size:11px;font-weight:700;color:var(--primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= h(explode(' — ',$site_actif_nom)[1] ?? $site_actif_nom) ?></div>
      <div style="font-size:9px;color:var(--text3)"><?= h(explode(' — ',$site_actif_nom)[0] ?? '') ?></div>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; ?>

  <!-- Recherche sidebar desktop -->
  <div style="padding:10px 14px;border-bottom:1px solid var(--border);position:relative">
    <div style="display:flex;align-items:center;gap:8px;background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:7px 10px;transition:border-color .15s" id="sb-search-wrap">
      <i class="ti ti-search" style="font-size:14px;color:var(--text3);flex-shrink:0"></i>
      <input type="text" id="sb-search" placeholder="Rechercher…"
             style="flex:1;background:none;border:none;outline:none;font-size:13px;color:var(--text);font-family:var(--font)"
             autocomplete="off" oninput="globalSearch(this.value,'sb')"
             onfocus="document.getElementById('sb-search-wrap').style.borderColor='var(--primary)'"
             onblur="setTimeout(()=>{document.getElementById('sb-search-wrap').style.borderColor='';hideSearchResults('sb')},200)">
    </div>
    <div id="search-results-sb" class="search-results" style="display:none;position:absolute;left:14px;right:14px;top:calc(100% - 4px)"></div>
  </div>

<nav class="nav">

    <!-- ── Général ── -->
    <div class="nav-label">Général</div>
    <a href="/dashboard.php"  class="nav-link <?= $currentPage==='dashboard' && !str_contains($_SERVER['PHP_SELF'],'biomed') ?'active':'' ?>" onclick="closeSidebar()">
      <span class="nav-icon">📊</span> Tableau de bord
    </a>
    <?php if (nav_peut($nav_perms,'emplacements')): ?>
    <a href="/locations.php"  class="nav-link <?= $currentPage==='locations' ?'active':'' ?>" onclick="closeSidebar()">
      <span class="nav-icon">📍</span> Emplacements
    </a>
    <?php endif; ?>
    <?php if (nav_peut($nav_perms,'medicaments')): ?>
    <a href="/products.php"   class="nav-link <?= $currentPage==='products'  ?'active':'' ?>" onclick="closeSidebar()">
      <span class="nav-icon">💊</span> Médicaments
    </a>
    <?php endif; ?>

    <!-- ── Rempotage ── -->
    <?php if (nav_peut($nav_perms,'scanner') || nav_peut($nav_perms,'fiches') || nav_peut($nav_perms,'rempotages')): ?>
    <div class="nav-label">Rempotage</div>
    <?php if (nav_peut($nav_perms,'scanner')): ?>
    <a href="/scan.php"           class="nav-link <?= $currentPage==='scan'    ?'active':'' ?>" onclick="closeSidebar()">
      <span class="nav-icon">📷</span> Scanner
    </a>
    <?php endif; ?>
    <?php if (nav_peut($nav_perms,'fiches')): ?>
    <a href="/fiches.php"         class="nav-link <?= $currentPage==='fiches'  ?'active':'' ?>" onclick="closeSidebar()">
      <span class="nav-icon">📋</span> Fiches de vérification
    </a>
    <?php endif; ?>
    <?php if (nav_peut($nav_perms,'rempotages')): ?>
    <a href="/rempotage.php"      class="nav-link <?= ($currentPage==='rempotage'||$currentPage==='rempotages') ?'active':'' ?>" onclick="closeSidebar()">
      <span class="nav-icon">🔄</span> Rempotage
    </a>
    <?php endif; ?>
    <?php if (nav_peut($nav_perms,'sse')): ?>
    <a href="/sse.php" class="nav-link <?= $currentPage==='sse' ?'active':'' ?>" onclick="closeSidebar()">
      <span class="nav-icon">☣️</span> SSE / NRBC
    </a>
    <?php endif; ?>

    <?php endif; ?>
    <!-- ── Biomédical ── -->
    <?php if (nav_peut($nav_perms,'equipements') || nav_peut($nav_perms,'biomed') || (isAdmin() && module_actif('biomedical'))): ?>
    <div class="nav-label">Biomédical</div>
    <?php if (nav_peut($nav_perms,'equipements')): ?>
    <a href="/materiel.php" class="nav-link <?= $currentPage==='materiel' && !str_contains($_SERVER['PHP_SELF'],'admin') ?'active':'' ?>" onclick="closeSidebar()">
      <span class="nav-icon">🔧</span> Équipements
      <?php try {
        $nb_hs = (int)$pdo->query("SELECT COUNT(*) FROM materiels WHERE site_id=".intval(site_actif())." AND statut='hors_service'")->fetchColumn();
        if ($nb_hs > 0): ?><span class="nav-badge" style="background:#ef4444"><?= $nb_hs ?></span><?php endif;
      } catch(Exception $e) {} ?>
    </a>
    <?php endif; ?>
    <?php if (nav_peut($nav_perms,'biomed')): ?>
    <a href="/biomed/dashboard.php" class="nav-link <?= str_contains($_SERVER['PHP_SELF'],'biomed') ?'active':'' ?>" onclick="closeSidebar()">
      <span class="nav-icon">👨‍🔬</span> Portail biomédical
    </a>
    <?php endif; ?>
    <?php endif; ?>
    <!-- ── IA — accessible via widget flottant uniquement ── -->
    <!-- ── Messagerie ── -->
    <?php if (nav_peut($nav_perms,'messagerie')): ?>
    <div class="nav-label">Messagerie</div>
    <?php if (nav_peut($nav_perms,'messagerie')): ?>
    <a href="/messaging.php"  class="nav-link <?= $currentPage==='messaging' ?'active':'' ?>" onclick="closeSidebar()">
      <span class="nav-icon">💬</span> Messages
      <?php if ($unread > 0): ?><span class="nav-badge"><?= $unread ?></span><?php endif; ?>
    </a>
    <?php endif; ?>

    <?php endif; ?>
    <!-- ── Administration ── -->
    <?php if (isAdmin() || nav_peut($nav_perms,'admin')): ?>
    <div class="nav-label">Administration</div>
    <?php if ($_SESSION['super_admin'] ?? false): ?>
    <a href="/superadmin_global.php" class="nav-link <?= $currentPage==='superadmin_global'?'active':'' ?>" onclick="closeSidebar()">
      <span class="nav-icon">🌐</span> Vue globale
    </a>
    <?php endif; ?>
    <?php if (isAdmin() && module_actif('dotations')): ?>
    <a href="/dotations.php" class="nav-link <?= $currentPage==='dotations'?'active':'' ?>" onclick="closeSidebar()">
      <span class="nav-icon">📋</span> Dotations réglementaires
    </a>
    <?php endif; ?>
    <?php if (isAdmin() && module_actif('tracabilite')): ?>
    <a href="/tracabilite.php" class="nav-link <?= $currentPage==='tracabilite'?'active':'' ?>" onclick="closeSidebar()">
      <span class="nav-icon">🔍</span> Traçabilité des lots
    </a>
    <?php endif; ?>
    <a href="/admin/" class="nav-link <?= str_contains($_SERVER['PHP_SELF'],'/admin/') && !str_contains($_SERVER['PHP_SELF'],'admin/materiel') ?'active':'' ?>" onclick="closeSidebar()">
      <span class="nav-icon">⚙️</span> Administration
      <?php
      try {
        $nb_ban_nav   = (int)$pdo->query("SELECT COUNT(*) FROM security_ips WHERE statut='banni'")->fetchColumn();
        $lockdown_nav = (int)$pdo->query("SELECT COUNT(*) FROM security_lockdown WHERE actif=1")->fetchColumn();
        if ($lockdown_nav): ?>
        <span class="nav-badge" style="background:#EF4444">🔒</span>
        <?php elseif ($nb_ban_nav > 0): ?>
        <span class="nav-badge" style="background:#F59E0B"><?= $nb_ban_nav ?></span>
        <?php endif;
      } catch(Exception $e) {}
      $mf = __DIR__.'/../config/maintenance.json';
      if (file_exists($mf)) {
        $mc = json_decode(file_get_contents($mf), true);
        if ($mc['actif'] ?? false): ?>
        <span class="nav-badge" style="background:#f59e0b">ON</span>
        <?php endif;
      } ?>
    </a>
    <?php endif; ?>

  </nav>

  <div class="sidebar-footer">
    <div class="sidebar-user">
      <?php
      $user_photo = null;
      try {
        $sp = $pdo->prepare("SELECT photo FROM utilisateurs WHERE id=?");
        $sp->execute([$_SESSION['user_id'] ?? 0]);
        $user_photo = $sp->fetchColumn();
      } catch(Exception $e) {}
      ?>
      <?php if ($user_photo && file_exists(__DIR__.'/../'.$user_photo)): ?>
      <img src="/<?= h($user_photo) ?>" style="width:32px;height:32px;border-radius:50%;object-fit:cover;flex-shrink:0;border:2px solid var(--border)">
      <?php else: ?>
      <div class="user-av" style="background:<?= h($display_color) ?>">
        <?= h($display_init) ?>
      </div>
      <?php endif; ?>
      <div style="flex:1;min-width:0;">
        <div class="user-name-text"><?= h($display_name) ?></div>
        <span class="role-pill" style="background:<?= h($display_color) ?>22;color:<?= h($display_color) ?>;border:1px solid <?= h($display_color) ?>44;">
          <?= roleIcon() ?> <?= h($display_role) ?>
        </span>
      </div>
    </div>
    <!-- Barre actions -->
    <div style="display:flex;align-items:center;gap:4px;margin-top:8px;padding-top:8px;border-top:1px solid var(--border)">
      <button id="btn-notif" title="Activer les notifications"
        style="flex:1;background:none;border:1px solid var(--border);cursor:pointer;padding:6px;color:var(--text3);border-radius:8px;transition:all .15s;display:flex;align-items:center;justify-content:center;gap:5px;font-size:.78rem;font-weight:600;font-family:var(--font)"
        onmouseover="this.style.background='rgba(239,68,68,.08)';this.style.borderColor='rgba(239,68,68,.3)';this.style.color='#f87171'"
        onmouseout="this.style.background='none';this.style.borderColor='var(--border)';this.style.color='var(--text3)'">
        🔔 Notifs
      </button>
      <a href="/profile.php" title="Mon profil"
        style="flex:1;display:flex;align-items:center;justify-content:center;gap:5px;padding:6px;background:none;border:1px solid var(--border);border-radius:8px;text-decoration:none;color:<?= $currentPage==='profile'?'var(--primary)':'var(--text3)' ?>;font-size:.78rem;font-weight:600;transition:all .15s"
        onmouseover="this.style.background='var(--primary-bg)';this.style.borderColor='rgba(var(--primary-rgb),.3)'"
        onmouseout="this.style.background='none';this.style.borderColor='var(--border)'">
        👤 Profil
      </a>
      <a href="/logout.php" title="Déconnexion"
        style="padding:6px 8px;background:none;border:1px solid var(--border);border-radius:8px;text-decoration:none;color:var(--text3);font-size:14px;transition:all .15s;display:flex;align-items:center"
        onmouseover="this.style.background='rgba(239,68,68,.08)';this.style.borderColor='rgba(239,68,68,.3)';this.style.color='#f87171'"
        onmouseout="this.style.background='none';this.style.borderColor='var(--border)';this.style.color='var(--text3)'">
        ⏻
      </a>
    </div>
  </div>
</aside>

<!-- MAIN -->
<main class="main">

<!-- BOTTOM NAV MOBILE — 4 boutons terrain + Menu -->
<nav class="bottom-nav" id="bottom-nav">

  <?php if (aff_mob('fiches')): ?>
  <a href="/fiches.php" class="bottom-nav-item <?= $currentPage==='fiches'?'active':'' ?>">
    <div class="bn-wrap"><div class="bn-icon">✅</div></div>
    <span class="bn-label">Vérification</span>
  </a>
  <?php endif; ?>

  <?php if (aff_mob('rempotages')): ?>
  <a href="/rempotage.php" class="bottom-nav-item <?= ($currentPage==='rempotage'||$currentPage==='rempotages')?'active':'' ?>">
    <div class="bn-wrap"><div class="bn-icon">🔄</div></div>
    <span class="bn-label">Rempotage</span>
  </a>
  <?php endif; ?>

  <?php if (aff_mob('messagerie')): ?>
  <a href="/messaging.php" class="bottom-nav-item <?= $currentPage==='messaging'?'active':'' ?>">
    <div class="bn-wrap">
      <div class="bn-icon">💬</div>
      <?php if ($unread > 0): ?><span class="bn-badge"><?= min($unread,9) ?></span><?php endif; ?>
    </div>
    <span class="bn-label">Messages</span>
  </a>
  <?php endif; ?>

  <?php if ((isAdmin() || nav_peut($nav_perms,'admin')) && (($aff_map['admin']['mobile'] ?? 1) == 1)): ?>
  <a href="/admin/" class="bottom-nav-item <?= str_contains($_SERVER['PHP_SELF'],'/admin/')?'active':'' ?>">
    <div class="bn-wrap"><div class="bn-icon">⚙️</div></div>
    <span class="bn-label">Admin</span>
  </a>
  <?php endif; ?>

</nav>

<?php if ($flash['success']): ?>
<div class="flash flash-success">✅ <?= h($flash['success']) ?></div>
<?php endif; ?>
<?php if ($flash['error']): ?>
<div class="flash flash-error">⚠️ <?= h($flash['error']) ?></div>
<?php endif; ?>

<script>
function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('mobile-open');
  document.getElementById('overlay').classList.toggle('mobile-open');
}
function closeSidebar() {
  document.getElementById('sidebar').classList.remove('mobile-open');
  document.getElementById('overlay').classList.remove('mobile-open');
}
// Confirmation sur data-confirm
document.addEventListener('click', function(e) {
  const el = e.target.closest('[data-confirm]');
  if (el && !confirm(el.dataset.confirm)) e.preventDefault();
});
// ── Statistiques d'usage (traceur léger) ──
(function(){
  try{
    var plat = (window.innerWidth < 900) ? 'mobile' : 'pc';
    var page = (location.pathname.split('/').pop() || 'index.php');
    if(navigator.sendBeacon){
      var fd = new FormData(); fd.append('type','page'); fd.append('cible',page); fd.append('plateforme',plat);
      navigator.sendBeacon('/stats_track.php', fd);
    }
    window.cdxTrack = function(module){
      if(!navigator.sendBeacon) return;
      var f = new FormData(); f.append('type','tuile'); f.append('cible',module); f.append('plateforme',plat);
      navigator.sendBeacon('/stats_track.php', f);
    };
  }catch(e){ window.cdxTrack = function(){}; }
})();
</script>

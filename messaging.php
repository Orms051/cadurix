<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.
require_once 'includes/auth.php';

$pageTitle = 'Messagerie';
$site_id  = site_actif();
$is_super = (bool)($_SESSION['super_admin'] ?? false);
$uid      = currentUserId();

// ── Guard : site_actif null → forcer le premier site disponible ──
if (!$site_id) {
    if ($is_super) {
        $_s = $pdo->query("SELECT id FROM sites WHERE actif=1 LIMIT 1");
        $site_id = (int)($_s->fetchColumn() ?: 0);
    } else {
        $_s = $pdo->prepare("SELECT site_id FROM user_site_permissions WHERE user_id=? LIMIT 1");
        $_s->execute([$uid]);
        $site_id = (int)($_s->fetchColumn() ?: 0);
    }
    if ($site_id) {
        $_SESSION['site_actif_id'] = $site_id;
    } else {
        flashError("Aucun site actif — contactez un administrateur.");
        header('Location: /dashboard.php'); exit;
    }
}

$box     = $_GET['box']    ?? 'inbox';
$compose = isset($_GET['compose']);
$viewId  = isset($_GET['view'])   ? (int)$_GET['view']   : 0;
$replyTo = isset($_GET['reply'])  ? (int)$_GET['reply']  : 0;
$delId   = isset($_GET['delete']) ? (int)$_GET['delete'] : 0;
$editDraft = isset($_GET['draft']) ? (int)$_GET['draft'] : 0;

// Champs v5
$f_sender   = 'expediteur_id';
$f_receiver = 'destinataire_id';
$f_subject  = 'sujet';
$f_body     = 'contenu';
$f_read     = 'lu';
$f_priority = 'priorite';
$f_date     = 'cree_le';
$f_parent   = 'parent_id';

// ── Suppression ───────────────────────────────────────────
if ($delId) {
    csrf_check();
    $pdo->prepare("DELETE FROM messages WHERE id=? AND (expediteur_id=? OR destinataire_id=?)")
        ->execute([$delId, $uid, $uid]);
    flashSuccess("Message supprimé.");
    header("Location: messaging.php?box=$box"); exit;
}

// ── Sauvegarde brouillon (AJAX) ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_draft'])) {
    // Bloc AJAX — toujours répondre en JSON, jamais de redirection
    header('Content-Type: application/json');
    try {
        csrf_check();
        $draftId = (int)($_POST['draft_id'] ?? 0);
        $recv_raw = $_POST['receiver_id'] ?? '';
        $recv    = $recv_raw === 'all' ? null : ($recv_raw === 'group' ? null : ((int)$recv_raw ?: null));
        $subject = trim($_POST['subject'] ?? 'Sans objet');
        $body    = trim($_POST['body'] ?? '');
        $prio    = in_array($_POST['priority']??'', ['normale','haute','urgente']) ? $_POST['priority'] : 'normale';
        $sendAt  = !empty($_POST['send_at']) ? $_POST['send_at'] : null;
        $type    = $recv === null ? 'broadcast' : 'prive';

        if ($draftId > 0) {
            $pdo->prepare("UPDATE messages SET destinataire_id=?,sujet=?,contenu=?,priorite=?,type=?,format_html=1,envoyer_le=?,modifie_le=NOW() WHERE id=? AND expediteur_id=? AND brouillon=1")
                ->execute([$recv, $subject, $body, $prio, $type, $sendAt, $draftId, $uid]);
            echo json_encode(['ok'=>true,'id'=>$draftId]); exit;
        } else {
            $pdo->prepare("INSERT INTO messages (expediteur_id,destinataire_id,site_id,type,sujet,contenu,priorite,format_html,brouillon,envoyer_le) VALUES(?,?,?,?,?,?,?,1,1,?)")
                ->execute([$uid, $recv, $site_id, $type, $subject, $body, $prio, $sendAt]);
            echo json_encode(['ok'=>true,'id'=>(int)$pdo->lastInsertId()]); exit;
        }
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit;
    }
}

// ── Envoi ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send'])) {
    csrf_check();
    $draftId  = (int)($_POST['draft_id'] ?? 0);
    $group    = $_POST['receiver_group'] ?? '';
    $recv_raw = $_POST['receiver_id'] ?? '';
    $recv_ids = $_POST['receiver_ids'] ?? [];
    $subject  = trim($_POST['subject'] ?? '');
    $body     = trim($_POST['body'] ?? '');
    $prio     = in_array($_POST['priority']??'', ['normale','haute','urgente']) ? $_POST['priority'] : 'normale';
    $parentId = (int)($_POST['parent_id'] ?? 0) ?: null;
    $sendAt   = !empty($_POST['send_at']) ? $_POST['send_at'] : null;

    if ($subject && $body) {
        // Résoudre les destinataires
        $receveurs = [];
        if (!empty($recv_ids)) {
            // Multi-select individuel
            foreach ($recv_ids as $rid) {
                $receveurs[] = (int)$rid;
            }
        } elseif ($group) {
            $stmt_grp = $pdo->prepare("SELECT DISTINCT u.id FROM utilisateurs u JOIN user_site_permissions usp ON usp.user_id=u.id WHERE usp.site_id=? AND usp.role=? AND u.actif=1 AND u.id!=?");
            $stmt_grp->execute([$site_id, $group, $uid]);
            $receveurs = array_column($stmt_grp->fetchAll(PDO::FETCH_ASSOC), 'id');
        } else {
            $receveurs = [null]; // broadcast
        }

        foreach ($receveurs as $recv) {
            $type = $recv === null ? 'broadcast' : 'prive';
            if ($draftId > 0 && count($receveurs) === 1) {
                $pdo->prepare("UPDATE messages SET destinataire_id=?,sujet=?,contenu=?,priorite=?,type=?,format_html=1,brouillon=0,envoyer_le=? WHERE id=? AND expediteur_id=?")
                    ->execute([$recv, $subject, $body, $prio, $type, $sendAt, $draftId, $uid]);
            } else {
                $pdo->prepare("INSERT INTO messages (expediteur_id,destinataire_id,site_id,type,sujet,contenu,priorite,format_html,parent_id,envoyer_le) VALUES(?,?,?,?,?,?,?,1,?,?)")
                    ->execute([$uid, $recv, $site_id, $type, $subject, $body, $prio, $parentId, $sendAt]);
            }
        }

        if ($draftId > 0 && count($receveurs) > 1) {
            $pdo->prepare("DELETE FROM messages WHERE id=? AND expediteur_id=? AND brouillon=1")->execute([$draftId, $uid]);
        }

        $nb = count($receveurs);
        flashSuccess($group ? "Message envoyé à $nb destinataire(s) du groupe." : ($receveurs[0]===null ? "Message diffusé à toute l'équipe." : "Message envoyé."));
        header('Location: messaging.php?box=sent'); exit;
    }
    flashError("Objet et message sont obligatoires.");
}

// ── Épingler ──────────────────────────────────────────────
if ($_GET['toggle_pin'] ?? '') {
    csrf_check();
    $pid = (int)$_GET['toggle_pin'];
    $pdo->prepare("UPDATE messages SET epingle = NOT epingle WHERE id=? AND (expediteur_id=? OR destinataire_id=?)")->execute([$pid,$uid,$uid]);
    header("Location: messaging.php?box=$box&view=$pid"); exit;
}

// ── Marquer lu ────────────────────────────────────────────
if ($viewId) {
    $pdo->prepare("UPDATE messages SET lu=1, lu_le=NOW() WHERE id=? AND (destinataire_id=? OR destinataire_id IS NULL)")
        ->execute([$viewId, $uid]);
}

// ── Message affiché ───────────────────────────────────────
$viewMsg = null; $thread = [];
if ($viewId) {
    $stmt = $pdo->prepare("SELECT m.*, CONCAT(u.prenom,' ',u.nom) AS sn, CONCAT(r.prenom,' ',r.nom) AS rn FROM messages m JOIN utilisateurs u ON u.id=m.expediteur_id LEFT JOIN utilisateurs r ON r.id=m.destinataire_id WHERE m.id=? AND (m.destinataire_id=? OR m.destinataire_id IS NULL OR m.expediteur_id=?)");
    $stmt->execute([$viewId, $uid, $uid]);
    $viewMsg = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($viewMsg) {
        $ts = $pdo->prepare("SELECT m.*, CONCAT(u.prenom,' ',u.nom) AS sn FROM messages m JOIN utilisateurs u ON u.id=m.expediteur_id WHERE m.parent_id=? ORDER BY m.cree_le");
        $ts->execute([$viewId]);
        $thread = $ts->fetchAll(PDO::FETCH_ASSOC);
    }
}

// ── Brouillon à éditer ────────────────────────────────────
$draftMsg = null;
if ($editDraft) {
    $s = $pdo->prepare("SELECT * FROM messages WHERE id=? AND expediteur_id=? AND brouillon=1");
    $s->execute([$editDraft, $uid]);
    $draftMsg = $s->fetch(PDO::FETCH_ASSOC);
}

$replyMsg = null;
if ($replyTo) {
    $s = $pdo->prepare("SELECT * FROM messages WHERE id=?");
    $s->execute([$replyTo]);
    $replyMsg = $s->fetch(PDO::FETCH_ASSOC);
}

// ── Listes messages ───────────────────────────────────────
if ($box === 'sent') {
    $stmt = $pdo->prepare("SELECT m.*, CONCAT(r.prenom,' ',r.nom) AS rn FROM messages m LEFT JOIN utilisateurs r ON r.id=m.destinataire_id WHERE m.expediteur_id=? AND m.parent_id IS NULL AND m.brouillon=0 ORDER BY m.epingle DESC, m.cree_le DESC");
    $stmt->execute([$uid]);
} elseif ($box === 'drafts') {
    $stmt = $pdo->prepare("SELECT m.*, CONCAT(r.prenom,' ',r.nom) AS rn FROM messages m LEFT JOIN utilisateurs r ON r.id=m.destinataire_id WHERE m.expediteur_id=? AND m.brouillon=1 ORDER BY m.cree_le DESC");
    $stmt->execute([$uid]);
} else {
    if ($is_super) {
        $stmt = $pdo->prepare("SELECT m.*, CONCAT(u.prenom,' ',u.nom) AS sn, u.super_admin AS sc FROM messages m JOIN utilisateurs u ON u.id=m.expediteur_id WHERE (m.destinataire_id=? OR m.destinataire_id IS NULL) AND m.expediteur_id!=? AND m.parent_id IS NULL AND m.brouillon=0 ORDER BY m.epingle DESC, m.cree_le DESC");
        $stmt->execute([$uid, $uid]);
    } else {
        $stmt = $pdo->prepare("SELECT m.*, CONCAT(u.prenom,' ',u.nom) AS sn, u.super_admin AS sc FROM messages m JOIN utilisateurs u ON u.id=m.expediteur_id WHERE (m.destinataire_id=? OR m.destinataire_id IS NULL) AND m.expediteur_id!=? AND m.parent_id IS NULL AND m.brouillon=0 AND (u.super_admin=1 OR EXISTS(SELECT 1 FROM user_site_permissions usp WHERE usp.user_id=u.id AND usp.site_id=?)) ORDER BY m.epingle DESC, m.cree_le DESC");
        $stmt->execute([$uid, $uid, $site_id]);
    }
}
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Non lus
$ucv = (int)$pdo->prepare("SELECT COUNT(*) FROM messages WHERE (destinataire_id=? OR destinataire_id IS NULL) AND expediteur_id!=? AND lu=0 AND brouillon=0")->execute([$uid,$uid]) ? (function() use($pdo,$uid){ $s=$pdo->prepare("SELECT COUNT(*) FROM messages WHERE (destinataire_id=? OR destinataire_id IS NULL) AND expediteur_id!=? AND lu=0 AND brouillon=0"); $s->execute([$uid,$uid]); return (int)$s->fetchColumn(); })() : 0;

// Brouillons count
$nb_drafts = (int)(function() use($pdo,$uid){ $s=$pdo->prepare("SELECT COUNT(*) FROM messages WHERE expediteur_id=? AND brouillon=1"); $s->execute([$uid]); return (int)$s->fetchColumn(); })();

// Utilisateurs du site pour destinataires
$uStmt = $pdo->prepare("SELECT DISTINCT u.id, CONCAT(u.prenom,' ',u.nom) AS nom, usp.role, u.prenom, u.nom AS nom_famille FROM utilisateurs u JOIN user_site_permissions usp ON usp.user_id=u.id WHERE usp.site_id=? AND u.actif=1 ORDER BY u.prenom, u.nom");
$uStmt->execute([$site_id]);
$allUsers = $uStmt->fetchAll(PDO::FETCH_ASSOC);

// Rôles disponibles pour groupes
$roles_dispo = array_unique(array_column($allUsers, 'role'));

require_once 'includes/header.php';

$av_colors = ['#3B82F6','#10B981','#F59E0B','#8B5CF6','#EF4444','#06B6D4','#EC4899','#14B8A6'];
function av_color($name, $colors) { return $colors[abs(crc32($name)) % count($colors)]; }
function av_init($name) { return strtoupper(mb_substr(trim($name) ?: '?', 0, 1)); }
?>
<style>
.msg-wrap { display:grid; grid-template-columns:280px 1fr; height:calc(100vh - 150px); gap:0; border-radius:14px; overflow:hidden; border:1px solid var(--border); box-shadow:0 4px 24px rgba(0,0,0,.12); }

/* Gauche */
.msg-left { background:var(--bg2); border-right:1px solid var(--border); display:flex; flex-direction:column; overflow:hidden; }
.msg-tabs { display:flex; flex-wrap:wrap; gap:2px; padding:10px 10px 0; flex-shrink:0; border-bottom:1px solid var(--border); }
.m-tab { flex:1; min-width:60px; padding:7px 8px; border-radius:8px 8px 0 0; font-size:11.5px; font-weight:700; text-decoration:none; color:var(--text3); transition:all .15s; text-align:center; border-bottom:2px solid transparent; margin-bottom:-1px; white-space:nowrap; }
.m-tab:hover { color:var(--text); background:var(--glass); }
.m-tab.active { color:var(--primary); border-bottom-color:var(--primary); background:var(--bg3); }
.msg-search-wrap { padding:8px 10px; border-bottom:1px solid var(--border); flex-shrink:0; }
.msg-search { width:100%; background:var(--bg); border:1px solid var(--border); border-radius:7px; padding:6px 11px; color:var(--text); font-family:inherit; font-size:12.5px; outline:none; }
.msg-search:focus { border-color:var(--primary); }
.msg-list-scroll { flex:1; overflow-y:auto; }

/* Item */
.msg-item { display:flex; align-items:flex-start; gap:9px; padding:11px 12px; border-bottom:1px solid var(--border); text-decoration:none; color:var(--text); cursor:pointer; transition:background .1s; position:relative; }
.msg-item:hover { background:var(--glass); }
.msg-item.active { background:var(--primary-bg); border-left:3px solid var(--primary); }
.msg-item.unread { background:rgba(59,130,246,.04); }
.msg-item.unread .mi-subj { font-weight:700; color:var(--text); }
.msg-item.urgent-item:not(.active) { border-left:3px solid #ef4444; }
.msg-item.pinned .mi-subj::before { content:'📌 '; }
.msg-av { width:34px; height:34px; border-radius:50%; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:800; color:white; margin-top:1px; }
.msg-item-body { flex:1; min-width:0; }
.mi-top { display:flex; align-items:center; justify-content:space-between; margin-bottom:1px; }
.mi-from { font-size:12px; font-weight:600; color:var(--text); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:130px; }
.mi-date { font-size:10px; color:var(--text3); flex-shrink:0; }
.mi-subj { font-size:12px; color:var(--text2); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; margin-bottom:1px; }
.mi-prev { font-size:11px; color:var(--text3); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.mi-badges { display:flex; gap:3px; margin-top:3px; flex-wrap:wrap; }
.unread-dot { width:6px; height:6px; border-radius:50%; background:var(--primary); flex-shrink:0; margin-top:5px; }

/* Droite */
.msg-right { background:var(--bg); display:flex; flex-direction:column; overflow:hidden; }
.msg-right-head { padding:16px 20px; border-bottom:1px solid var(--border); flex-shrink:0; background:var(--bg2); }
.msg-subject-big { font-size:16px; font-weight:800; color:var(--text); margin-bottom:8px; letter-spacing:-.2px; line-height:1.3; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.msg-meta-row { display:flex; align-items:center; gap:10px; }
.mini-av { width:34px; height:34px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:800; color:white; flex-shrink:0; }
.msg-body-area { flex:1; overflow-y:auto; padding:20px 24px; font-size:13.5px; line-height:1.8; color:var(--text); word-break:break-word; }
.msg-action-bar { padding:10px 18px; border-top:1px solid var(--border); display:flex; gap:8px; flex-shrink:0; background:var(--bg2); align-items:center; }

/* Thread */
.thread-wrap { border-top:2px solid var(--border); }
.thread-msg { padding:14px 20px; border-bottom:1px solid var(--border); display:flex; gap:10px; align-items:flex-start; }
.thread-msg-body { flex:1; }
.mini-av-sm { width:28px; height:28px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:800; color:white; flex-shrink:0; }

/* Composer */
.compose-body { flex:1; overflow-y:auto; padding:20px 24px; background:var(--bg); }
.compose-title { font-size:16px; font-weight:800; color:var(--text); margin-bottom:18px; display:flex; align-items:center; gap:10px; }
.draft-bar { background:rgba(245,158,11,.1); border:1px solid rgba(245,158,11,.25); border-radius:8px; padding:8px 14px; font-size:12px; color:#fbbf24; margin-bottom:14px; display:flex; align-items:center; justify-content:space-between; }

/* Éditeur riche */
.editor-toolbar { display:flex; gap:2px; padding:6px 10px; background:var(--bg2); border:1px solid var(--border); border-bottom:none; border-radius:8px 8px 0 0; flex-wrap:wrap; }
.editor-btn { padding:4px 8px; border-radius:5px; border:none; background:none; cursor:pointer; font-size:12px; color:var(--text2); font-family:inherit; transition:.12s; }
.editor-btn:hover { background:var(--glass); color:var(--text); }
.editor-btn.active { background:var(--primary-bg); color:var(--primary); }
.editor-sep { width:1px; background:var(--border); margin:2px 4px; }
.editor-content { min-height:180px; max-height:320px; overflow-y:auto; border:1px solid var(--border); border-radius:0 0 8px 8px; padding:12px 14px; outline:none; font-family:inherit; font-size:13.5px; line-height:1.8; color:var(--text); background:var(--bg); }
.editor-content:focus { border-color:var(--primary); }
.editor-content:empty::before { content:attr(data-placeholder); color:var(--text3); pointer-events:none; }

/* Badges */
.badge-urgent { background:rgba(239,68,68,.15); color:#f87171; border:1px solid rgba(239,68,68,.3); font-size:10px; padding:1px 7px; border-radius:10px; font-weight:700; }
.badge-global { background:rgba(139,92,246,.12); color:#a78bfa; border:1px solid rgba(139,92,246,.2); font-size:10px; padding:1px 7px; border-radius:10px; font-weight:700; }
.badge-draft  { background:rgba(245,158,11,.12); color:#fbbf24; border:1px solid rgba(245,158,11,.2); font-size:10px; padding:1px 7px; border-radius:10px; font-weight:700; }
.badge-pinned { background:rgba(16,185,129,.12); color:#34d399; border:1px solid rgba(16,185,129,.2); font-size:10px; padding:1px 7px; border-radius:10px; font-weight:700; }
.badge-haute  { background:rgba(245,158,11,.12); color:#fbbf24; border:1px solid rgba(245,158,11,.2); font-size:10px; padding:1px 7px; border-radius:10px; font-weight:700; }

/* Dropdown destinataires */
.recv-dropdown { position:relative; }
.recv-trigger {
  display:flex; align-items:center; justify-content:space-between;
  padding:9px 14px; background:var(--bg); border:1px solid var(--border);
  border-radius:8px; cursor:pointer; font-size:.85rem; color:var(--text2);
  transition:border-color .15s; user-select:none;
}
.recv-trigger:hover { border-color:var(--primary); }
.recv-dropdown.open .recv-trigger { border-color:var(--primary); border-bottom-left-radius:0; border-bottom-right-radius:0; }
.recv-menu {
  display:none; position:absolute; left:0; right:0; top:100%;
  background:var(--bg2); border:1px solid var(--primary);
  border-top:none; border-radius:0 0 8px 8px;
  max-height:250px; overflow-y:auto; z-index:200;
  box-shadow:0 8px 24px rgba(0,0,0,.15);
}
.recv-dropdown.open .recv-menu { display:block; }
.recv-menu-groups {
  display:flex; gap:5px; flex-wrap:wrap; padding:8px 10px;
  border-bottom:1px solid var(--border); background:var(--bg3);
}
.recv-menu-groups button {
  padding:3px 9px; border-radius:20px; border:1px solid var(--border);
  background:var(--glass); font-size:.72rem; cursor:pointer;
  color:var(--text2); font-family:inherit; transition:.12s;
}
.recv-menu-groups button:hover { border-color:var(--primary); color:var(--primary); }
.recv-clear-btn { color:var(--text3) !important; }
.recv-menu-sep { height:0; }
.recv-item {
  display:flex; align-items:center; gap:10px;
  padding:8px 12px; cursor:pointer; transition:background .1s;
  border-bottom:1px solid var(--border); user-select:none;
}
.recv-item:last-child { border-bottom:none; }
.recv-item:hover { background:var(--glass); }
.recv-item input[type=checkbox] { display:none; }
.recv-item.selected { background:var(--primary-bg); }
.recv-av {
  width:28px; height:28px; border-radius:50%; flex-shrink:0;
  display:flex; align-items:center; justify-content:center;
  font-size:11px; font-weight:800; color:white;
}
.recv-check-icon {
  width:20px; height:20px; border-radius:50%;
  border:2px solid var(--border); display:flex;
  align-items:center; justify-content:center;
  font-size:11px; color:transparent; flex-shrink:0; transition:.12s;
}
.recv-item.selected .recv-check-icon { background:var(--primary); border-color:var(--primary); color:white; }

/* Msg vide */
.msg-empty { flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center; color:var(--text2); background:var(--bg); gap:8px; }

@media(max-width:700px){ .msg-wrap{grid-template-columns:1fr} .msg-left{display:<?= $viewId||$compose||$editDraft?'none':'flex' ?>} }
</style>

<div class="page-header">
  <div class="page-title-group">
    <div class="page-title">💬 Messagerie interne</div>
    <div class="page-sub">Communication sécurisée entre membres de l'équipe</div>
  </div>
  <a href="messaging.php?compose=1" class="btn btn-primary btn-sm">✉️ Nouveau message</a>
</div>

<div class="msg-wrap">

  <!-- ── LISTE ── -->
  <div class="msg-left">
    <div class="msg-tabs">
      <a href="messaging.php?box=inbox" class="m-tab <?= $box==='inbox'?'active':'' ?>">
        📥 Reçus <?php if($ucv>0):?><span style="background:var(--primary);color:white;font-size:9px;padding:1px 5px;border-radius:8px;margin-left:2px"><?= $ucv ?></span><?php endif;?>
      </a>
      <a href="messaging.php?box=sent"   class="m-tab <?= $box==='sent'?'active':'' ?>">📤 Envoyés</a>
      <a href="messaging.php?box=drafts" class="m-tab <?= $box==='drafts'?'active':'' ?>">
        ✏️ Brouillons <?php if($nb_drafts>0):?><span style="background:#f59e0b;color:#1a1a1a;font-size:9px;padding:1px 5px;border-radius:8px;margin-left:2px"><?= $nb_drafts ?></span><?php endif;?>
      </a>
    </div>
    <div class="msg-search-wrap">
      <input type="text" class="msg-search" placeholder="🔍 Rechercher…" oninput="filtrerMessages(this.value)">
    </div>
    <div class="msg-list-scroll">
      <?php if (empty($messages)): ?>
      <div style="padding:40px 20px;text-align:center;color:var(--text3)">
        <div style="font-size:2rem;margin-bottom:8px"><?= $box==='drafts'?'✏️':($box==='sent'?'📤':'📥') ?></div>
        <div style="font-size:12.5px">Aucun message</div>
      </div>
      <?php endif; ?>
      <?php foreach ($messages as $m):
        $isActive  = $viewId === (int)$m['id'] || $editDraft === (int)$m['id'];
        $isUnread  = !$m['lu'] && $box==='inbox';
        $isUrgent  = ($m['priorite']??'') === 'urgente';
        $isPinned  = (bool)($m['epingle']??0);
        $isDraft   = (bool)($m['brouillon']??0);
        $from      = $box==='sent'||$box==='drafts' ? ($m['rn'] ?? '📢 Tous') : ($m['sn'] ?? '?');
        $prev      = mb_substr(strip_tags($m['contenu']), 0, 55) . '…';
        $initial   = av_init($from);
        $av_c      = $from === '📢 Tous' ? '#8B5CF6' : av_color($from, $av_colors);
        $is_bcast  = !$m['destinataire_id'] && $box!=='sent'&&$box!=='drafts';
        $link      = $isDraft ? "messaging.php?draft={$m['id']}" : "messaging.php?box={$box}&view={$m['id']}";
      ?>
      <a href="<?= $link ?>" class="msg-item <?= $isActive?'active':'' ?> <?= $isUnread?'unread':'' ?> <?= $isUrgent&&!$isActive?'urgent-item':'' ?> <?= $isPinned?'pinned':'' ?>"
         data-search="<?= strtolower(h($from.' '.$m['sujet'].' '.strip_tags($m['contenu']))) ?>">
        <?php if($isUnread&&!$isActive): ?><div class="unread-dot" style="position:absolute;left:5px;top:50%;transform:translateY(-50%)"></div><?php endif; ?>
        <div class="msg-av" style="background:<?= $av_c ?>;margin-left:<?= $isUnread&&!$isActive?'6px':'0' ?>">
          <?= $is_bcast ? '📢' : h($initial) ?>
        </div>
        <div class="msg-item-body">
          <div class="mi-top">
            <span class="mi-from"><?= $is_bcast ? 'Toute l\'équipe' : h($from) ?></span>
            <span class="mi-date"><?= date($m['cree_le']>date('Y-m-d')?'H:i':'d/m', strtotime($m['cree_le'])) ?></span>
          </div>
          <div class="mi-subj"><?= h($m['sujet']) ?></div>
          <div class="mi-prev"><?= h($prev) ?></div>
          <div class="mi-badges">
            <?php if($isUrgent):  ?><span class="badge-urgent">⚡ Urgent</span><?php endif; ?>
            <?php if($is_bcast):  ?><span class="badge-global">📢 Global</span><?php endif; ?>
            <?php if($isPinned):  ?><span class="badge-pinned">📌</span><?php endif; ?>
            <?php if($isDraft):   ?><span class="badge-draft">✏️ Brouillon</span><?php endif; ?>
            <?php if(($m['priorite']??'')==='haute'): ?><span class="badge-haute">🔺 Haute</span><?php endif; ?>
          </div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- ── ZONE DROITE ── -->
  <div class="msg-right">

    <?php if ($compose || $replyTo || $editDraft): ?>
    <!-- ── COMPOSER ── -->
    <div class="compose-body">
      <div class="compose-title">
        <?php if ($replyTo): ?>↩️ Répondre
        <?php elseif ($editDraft): ?>✏️ Modifier le brouillon
        <?php else: ?>✉️ Nouveau message<?php endif; ?>
      </div>

      <?php if($replyMsg): ?>
      <div class="flash flash-info" style="margin-bottom:14px;font-size:12.5px">Réponse à : <strong><?= h($replyMsg['sujet']) ?></strong></div>
      <?php endif; ?>

      <div class="draft-bar" id="draft-status" style="display:none">
        <span>✅ Brouillon sauvegardé</span>
        <span id="draft-time" style="opacity:.7"></span>
      </div>

      <form method="POST" id="compose-form">
        <?= csrf_field() ?>
        <input type="hidden" name="send" value="1">
        <input type="hidden" name="draft_id" id="draft_id" value="<?= $draftMsg['id']??0 ?>">
        <?php if($replyTo): ?><input type="hidden" name="parent_id" value="<?= $replyTo ?>"><?php endif; ?>

        <div class="form-row" style="margin-bottom:12px">
          <!-- Dropdown destinataires -->
          <div class="form-group" style="margin-bottom:0">
            <label class="form-label">Destinataires * <span style="font-size:.7rem;color:var(--primary);font-weight:600" id="recv-count"></span></label>
            <div class="recv-dropdown" id="recv-dropdown">
              <!-- Trigger -->
              <div class="recv-trigger" id="recv-trigger" onclick="toggleRecvDropdown()">
                <span id="recv-trigger-text">Sélectionner des destinataires…</span>
                <span style="color:var(--text3);font-size:.8rem">▼</span>
              </div>
              <!-- Menu -->
              <div class="recv-menu" id="recv-menu">
                <!-- Chips groupes -->
                <?php if(isGestionnaire()): ?>
                <div class="recv-menu-groups">
                  <button type="button" onclick="selectAll();event.stopPropagation()">📢 Tous</button>
                  <?php
                  $role_labels = ['administrateur'=>'⭐ Admins','gestionnaire'=>'🔧 Gestionnaires','saisie'=>'✏️ Saisie','lecteur'=>'👁 Lecteurs','biomedical'=>'🔬 Biomédical','sse'=>'☣️ SSE/NRBC'];
                  foreach ($roles_dispo as $r): if (!isset($role_labels[$r])) continue; ?>
                  <button type="button" onclick="selectGroup('<?= $r ?>');event.stopPropagation()"><?= $role_labels[$r] ?></button>
                  <?php endforeach; ?>
                  <button type="button" class="recv-clear-btn" onclick="clearSelection();event.stopPropagation()">✕ Vider</button>
                </div>
                <div class="recv-menu-sep"></div>
                <?php endif; ?>
                <!-- Liste personnes -->
                <?php
                $av_colors_php = ['#3B82F6','#10B981','#F59E0B','#8B5CF6','#EF4444','#06B6D4','#EC4899','#14B8A6'];
                foreach($allUsers as $u): if($u['id']==$uid) continue;
                  $u_color = $av_colors_php[abs(crc32($u['nom'])) % count($av_colors_php)];
                  $u_init  = strtoupper(mb_substr(trim($u['nom']),0,1));
                  $checked = $replyMsg && $replyMsg['expediteur_id']==$u['id'];
                ?>
                <label class="recv-item" data-role="<?= h($u['role']) ?>" onclick="event.stopPropagation()">
                  <input type="checkbox" name="receiver_ids[]" value="<?= $u['id'] ?>"
                         class="recv-cb" <?= $checked?'checked':'' ?> onchange="updateRecvCount()">
                  <div class="recv-av" style="background:<?= $u_color ?>"><?= $u_init ?></div>
                  <div style="flex:1;min-width:0">
                    <div style="font-size:.85rem;font-weight:600"><?= h($u['nom']) ?></div>
                    <div style="font-size:.72rem;color:var(--text3)"><?= roleLabel($u['role']) ?></div>
                  </div>
                  <span class="recv-check-icon">✓</span>
                </label>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
          <div class="form-group" style="margin-bottom:0">
            <label class="form-label">Priorité</label>
            <select name="priority" class="form-control">
              <option value="normale" <?= ($draftMsg['priorite']??'')==='normale'?'selected':'' ?>>Normal</option>
              <option value="haute"   <?= ($draftMsg['priorite']??'')==='haute'?'selected':'' ?>>🔺 Haute</option>
              <option value="urgente" <?= ($draftMsg['priorite']??'')==='urgente'?'selected':'' ?>>⚡ Urgent</option>
            </select>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Objet *</label>
          <input type="text" name="subject" id="msg-subject" class="form-control"
                 value="<?= $replyMsg ? 'Re: '.h($replyMsg['sujet']) : h($draftMsg['sujet']??'') ?>"
                 placeholder="Objet du message…" required>
        </div>

        <!-- Éditeur riche -->
        <div class="form-group">
          <label class="form-label">Message *</label>
          <div class="editor-toolbar">
            <button type="button" class="editor-btn" onclick="fmt('bold')" title="Gras"><b>G</b></button>
            <button type="button" class="editor-btn" onclick="fmt('italic')" title="Italique"><i>I</i></button>
            <button type="button" class="editor-btn" onclick="fmt('underline')" title="Souligné"><u>S</u></button>
            <button type="button" class="editor-btn" onclick="fmt('strikeThrough')" title="Barré"><s>R</s></button>
            <div class="editor-sep"></div>
            <select onchange="fmt('fontSize',this.value);this.value=''" class="editor-btn" style="padding:2px 4px">
              <option value="">Taille</option>
              <option value="1">Petit</option>
              <option value="3">Normal</option>
              <option value="5">Grand</option>
              <option value="7">Très grand</option>
            </select>
            <select onchange="fmt('foreColor',this.value);this.value=''" class="editor-btn" style="padding:2px 4px">
              <option value="">Couleur</option>
              <option value="#ef4444">🔴 Rouge</option>
              <option value="#f59e0b">🟡 Orange</option>
              <option value="#10b981">🟢 Vert</option>
              <option value="#3b82f6">🔵 Bleu</option>
              <option value="#8b5cf6">🟣 Violet</option>
            </select>
            <div class="editor-sep"></div>
            <button type="button" class="editor-btn" onclick="fmt('insertUnorderedList')" title="Liste">• Liste</button>
            <button type="button" class="editor-btn" onclick="fmt('insertOrderedList')" title="Liste numérotée">1. Liste</button>
            <div class="editor-sep"></div>
            <button type="button" class="editor-btn" onclick="fmt('justifyLeft')" title="Gauche">⬅</button>
            <button type="button" class="editor-btn" onclick="fmt('justifyCenter')" title="Centre">⬛</button>
            <button type="button" class="editor-btn" onclick="fmt('justifyRight')" title="Droite">➡</button>
            <div class="editor-sep"></div>
            <button type="button" class="editor-btn" onclick="clearFormat()" title="Effacer mise en forme">✕ Format</button>
          </div>
          <div class="editor-content" id="editor" contenteditable="true"
               data-placeholder="Rédigez votre message…"
               oninput="syncEditor()"><?= $draftMsg ? $draftMsg['contenu'] : '' ?></div>
          <input type="hidden" name="body" id="msg-body">
        </div>

        <!-- Envoi différé -->
        <div style="margin-bottom:16px">
          <label class="send-later-toggle">
            <input type="checkbox" id="send-later-cb" onchange="toggleSendLater(this)"> Planifier l'envoi
          </label>
          <div id="send-later-wrap" style="display:none;margin-top:8px">
            <input type="datetime-local" name="send_at" class="form-control" style="max-width:280px"
                   min="<?= date('Y-m-d\TH:i') ?>">
            <div style="font-size:11px;color:var(--text3);margin-top:4px">Le message sera envoyé à la date et l'heure choisies.</div>
          </div>
        </div>

        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <button type="submit" class="btn btn-primary">📤 Envoyer</button>
          <button type="button" class="btn btn-ghost" onclick="saveDraft()">💾 Sauvegarder brouillon</button>
          <a href="messaging.php?box=<?= $box ?>" class="btn btn-ghost">Annuler</a>
          <span style="flex:1"></span>
          <span id="autosave-label" style="font-size:11px;color:var(--text3)"></span>
        </div>
      </form>
    </div>

    <?php elseif($viewMsg): ?>
    <!-- ── VUE MESSAGE ── -->
    <div class="msg-right-head">
      <div class="msg-subject-big">
        <?php if(($viewMsg['priorite']??'')==='urgente'): ?><span class="badge-urgent">⚡ Urgent</span><?php endif; ?>
        <?php if(($viewMsg['priorite']??'')==='haute'):   ?><span class="badge-haute">🔺 Haute</span><?php endif; ?>
        <?php if($viewMsg['epingle']??0): ?><span class="badge-pinned">📌 Épinglé</span><?php endif; ?>
        <?= h($viewMsg['sujet']) ?>
      </div>
      <div class="msg-meta-row">
        <?php $sn=$viewMsg['sn']??'?'; $av_c=av_color($sn,$av_colors); ?>
        <div class="mini-av" style="background:<?= $av_c ?>"><?= av_init($sn) ?></div>
        <div>
          <div style="font-size:13px;font-weight:700;color:var(--text)"><?= h($sn) ?></div>
          <div style="font-size:11px;color:var(--text3)">
            À : <?= $viewMsg['destinataire_id'] ? h($viewMsg['rn']??'?') : '<span style="color:#8B5CF6">📢 Toute l\'équipe</span>' ?>
            &nbsp;·&nbsp; <?= date('d/m/Y à H:i', strtotime($viewMsg['cree_le'])) ?>
            <?php if($viewMsg['lu_le']??null): ?>&nbsp;·&nbsp; <span style="color:#10b981">✓ Lu le <?= date('d/m à H:i', strtotime($viewMsg['lu_le'])) ?></span><?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <div class="msg-body-area">
      <?php if($viewMsg['format_html']??0): ?>
        <?= $viewMsg['contenu'] ?>
      <?php else: ?>
        <?= nl2br(h($viewMsg['contenu'])) ?>
      <?php endif; ?>
    </div>

    <?php if(!empty($thread)): ?>
    <div class="thread-wrap">
      <div style="padding:8px 20px;font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:1.5px;color:var(--text3);background:var(--bg2);border-bottom:1px solid var(--border)"><?= count($thread) ?> réponse(s)</div>
      <?php foreach($thread as $t):
        $t_sn=$t['sn']??'?'; $t_col=av_color($t_sn,$av_colors);
      ?>
      <div class="thread-msg">
        <div class="mini-av-sm" style="background:<?= $t_col ?>"><?= av_init($t_sn) ?></div>
        <div class="thread-msg-body">
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:5px">
            <span style="font-size:12.5px;font-weight:700;color:var(--text)"><?= h($t_sn) ?></span>
            <span style="font-size:10px;color:var(--text3)"><?= date('d/m/Y à H:i',strtotime($t['cree_le'])) ?></span>
          </div>
          <div style="font-size:13px;line-height:1.75;color:var(--text)">
            <?= ($t['format_html']??0) ? $t['contenu'] : nl2br(h($t['contenu'])) ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="msg-action-bar">
      <a href="messaging.php?compose=1&reply=<?= $viewMsg['id'] ?>" class="btn btn-primary btn-sm">↩️ Répondre</a>
      <a href="messaging.php?box=<?= $box ?>&toggle_pin=<?= $viewMsg['id'] ?>&csrf_token=<?= csrf_token() ?>" class="btn btn-ghost btn-sm" title="<?= $viewMsg['epingle']?'Désépingler':'Épingler' ?>">
        <?= $viewMsg['epingle'] ? '📌 Désépingler' : '📌 Épingler' ?>
      </a>
      <a href="messaging.php?box=<?= $box ?>&delete=<?= $viewMsg['id'] ?>&csrf_token=<?= csrf_token() ?>"
         class="btn btn-danger btn-sm" data-confirm="Supprimer ce message ?">🗑️</a>
      <a href="messaging.php?box=<?= $box ?>" class="btn btn-ghost btn-sm" style="margin-left:auto">← Retour</a>
    </div>

    <?php else: ?>
    <!-- ── VIDE ── -->
    <div class="msg-empty">
      <div style="font-size:3rem;opacity:.25"><?= $box==='drafts'?'✏️':($box==='sent'?'📤':'💬') ?></div>
      <div style="font-size:14px;font-weight:700;color:var(--text)"><?= $box==='drafts'?'Aucun brouillon':($box==='sent'?'Aucun message envoyé':'Sélectionnez un message') ?></div>
      <div style="font-size:12px;color:var(--text3)"><?= $box==='inbox'?'ou écrivez-en un nouveau':'' ?></div>
      <?php if($box==='inbox'||$box==='drafts'): ?>
      <a href="messaging.php?compose=1" class="btn btn-primary btn-sm" style="margin-top:8px">✉️ Nouveau message</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>

  </div>
</div>

<script>
// ── Éditeur riche ─────────────────────────────────────────
function fmt(cmd, val) {
  document.getElementById('editor').focus();
  document.execCommand(cmd, false, val || null);
  syncEditor();
}
function clearFormat() {
  document.getElementById('editor').focus();
  document.execCommand('removeFormat', false, null);
  syncEditor();
}
function syncEditor() {
  document.getElementById('msg-body').value = document.getElementById('editor').innerHTML;
}

// ── Dropdown destinataires ───────────────────────────────
function toggleRecvDropdown() {
  document.getElementById('recv-dropdown').classList.toggle('open');
}
// Fermer si clic ailleurs
document.addEventListener('click', e => {
  const dd = document.getElementById('recv-dropdown');
  if (dd && !dd.contains(e.target)) dd.classList.remove('open');
});

function updateRecvCount() {
  const checked = document.querySelectorAll('.recv-cb:checked');
  const n = checked.length;
  // Compteur label
  const el = document.getElementById('recv-count');
  if (el) el.textContent = n > 0 ? n + ' sélectionné(s)' : '';
  // Texte trigger
  const trigger = document.getElementById('recv-trigger-text');
  if (trigger) {
    if (n === 0) {
      trigger.textContent = 'Sélectionner des destinataires…';
      trigger.style.color = 'var(--text3)';
    } else {
      const names = Array.from(checked).map(cb => {
        const item = cb.closest('.recv-item');
        return item ? item.querySelector('div > div:first-child')?.textContent.trim() : '';
      }).filter(Boolean);
      trigger.textContent = names.slice(0,3).join(', ') + (names.length > 3 ? ` +${names.length-3}` : '');
      trigger.style.color = 'var(--text)';
    }
  }
  // Style visuel lignes
  document.querySelectorAll('.recv-item').forEach(item => {
    const cb = item.querySelector('.recv-cb');
    item.classList.toggle('selected', cb && cb.checked);
  });
}

function selectAll() {
  document.querySelectorAll('.recv-cb').forEach(cb => cb.checked = true);
  updateRecvCount();
}
function selectGroup(role) {
  document.querySelectorAll('.recv-item').forEach(item => {
    const cb = item.querySelector('.recv-cb');
    if (cb) cb.checked = item.dataset.role === role;
  });
  updateRecvCount();
}
function clearSelection() {
  document.querySelectorAll('.recv-cb').forEach(cb => cb.checked = false);
  updateRecvCount();
}
document.addEventListener('DOMContentLoaded', updateRecvCount);

// ── Envoi différé ─────────────────────────────────────────
function toggleSendLater(cb) {
  document.getElementById('send-later-wrap').style.display = cb.checked ? 'block' : 'none';
}

// ── Sauvegarde brouillon ──────────────────────────────────
let autoSaveTimer;
let draftSaved = false;

function saveDraft() {
  syncEditor();
  const fd = new FormData(document.getElementById('compose-form'));
  fd.delete('send');
  fd.append('save_draft', '1');
  fetch('messaging.php', {method:'POST', body:fd})
    .then(r => {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    })
    .then(d => {
      if (d.ok) {
        document.getElementById('draft_id').value = d.id;
        const bar = document.getElementById('draft-status');
        if (bar) bar.style.display = 'flex';
        const dt = document.getElementById('draft-time');
        if (dt) dt.textContent = new Date().toLocaleTimeString('fr-FR',{hour:'2-digit',minute:'2-digit'});
        const al = document.getElementById('autosave-label');
        if (al) al.textContent = '✅ Brouillon sauvegardé à ' + new Date().toLocaleTimeString('fr-FR',{hour:'2-digit',minute:'2-digit'});
        draftSaved = true;
      }
    })
    .catch(err => {
      console.warn('[Cadurix] saveDraft silencieux:', err);
      // Ne jamais rediriger — ignorer l'erreur silencieusement
    });
}

// Autosave désactivé — sauvegarde manuelle uniquement
const editor = document.getElementById('editor');
if (editor) {
  // Sync initial si brouillon
  syncEditor();
}

// ── Recherche messages ────────────────────────────────────
function filtrerMessages(q) {
  q = q.toLowerCase().trim();
  document.querySelectorAll('.msg-item').forEach(el => {
    el.style.display = !q || el.dataset.search.includes(q) ? '' : 'none';
  });
}

// ── Confirmation suppression ──────────────────────────────
document.addEventListener('click', e => {
  const el = e.target.closest('[data-confirm]');
  if (el && !confirm(el.dataset.confirm)) e.preventDefault();
});

// ── Sync éditeur avant envoi ──────────────────────────────
const composeForm = document.getElementById('compose-form');
if (composeForm) {
  composeForm.addEventListener('submit', function() {
    syncEditor();
  });
}
</script>

<?php require_once 'includes/footer.php'; ?>

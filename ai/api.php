<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.

/**
 * Cadurix AI — Assistant
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/context.php';
require_once __DIR__ . '/actions.php';
require_once __DIR__ . '/tools.php';

header('Content-Type: application/json; charset=utf-8');

$input   = json_decode(file_get_contents('php://input'), true);
$message = trim($input['message'] ?? '');
$page    = trim($input['page'] ?? 'dashboard');

// Photo optionnelle (reconnaissance de boîte) : base64 sans préfixe data:
$img_b64   = $input['image'] ?? '';
$img_media = $input['media_type'] ?? 'image/jpeg';
if (!in_array($img_media, ['image/jpeg', 'image/png', 'image/webp'], true)) $img_media = 'image/jpeg';

$site_id = site_actif();
$user_id = currentUserId();

// ── Module Assistant IA désactivé pour ce site ───────────────
if (!module_actif('assistant_ia')) {
    http_response_code(403);
    echo json_encode(['error' => 'Module désactivé', 'message' => "L'assistant IA n'est pas activé pour ce site."]);
    exit;
}

// ── Rate limiting (configurable via ai_settings.rate_limit_heure) ──
$_rl_window = 3600; // 1 heure
$_rl_max    = (int)($pdo->query("SELECT valeur FROM ai_settings WHERE cle='rate_limit_heure'")->fetchColumn() ?: 30);

try {
    // Compter les requêtes de l'heure écoulée
    $stmt_rl = $pdo->prepare("
        SELECT COUNT(*) FROM ai_conversations
        WHERE user_id = ? AND cree_le > DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    $stmt_rl->execute([$user_id]);
    $rl_count = (int)$stmt_rl->fetchColumn();

    if ($rl_count >= $_rl_max) {
        http_response_code(429);
        echo json_encode([
            'error'   => 'Limite atteinte',
            'message' => "Vous avez atteint la limite de {$_rl_max} requêtes par heure. Réessayez dans quelques minutes.",
            'retry_after' => 3600,
        ]);
        exit;
    }
} catch (Exception $e) {
    // En cas d'erreur BDD, on laisse passer (fail open)
}
// ── Vérification IA active globalement ───────────────────────
try {
    $ia_actif = $pdo->query("SELECT valeur FROM ai_settings WHERE cle='actif'")->fetchColumn();
    if (!$ia_actif || $ia_actif !== '1') {
        http_response_code(503);
        echo json_encode(['error' => 'IA désactivée', 'message' => "L'assistant IA est actuellement désactivé par l'administrateur."]);
        exit;
    }
} catch (Exception $e) {}

// ── Vérification permission IA par rôle ──────────────────────
try {
    // Récupérer le rôle de l'utilisateur courant
    $stmt_role = $pdo->prepare("
        SELECT usp.role FROM user_site_permissions usp
        WHERE usp.user_id = ? AND usp.site_id = ?
        LIMIT 1
    ");
    $stmt_role->execute([$user_id, $site_id]);
    $user_role = $stmt_role->fetchColumn() ?: 'lecteur';

    // Super admin a toujours accès
    $is_super = (bool)($_SESSION['super_admin'] ?? false);

    if (!$is_super) {
        $stmt_perm = $pdo->prepare("SELECT chat FROM ai_permissions WHERE role = ?");
        $stmt_perm->execute([$user_role]);
        $perm = $stmt_perm->fetch(PDO::FETCH_ASSOC);
        if (!$perm || !$perm['chat']) {
            http_response_code(403);
            echo json_encode([
                'error'   => 'Accès refusé',
                'message' => "Votre rôle ({$user_role}) n'a pas accès à l'assistant IA. Contactez votre administrateur.",
            ]);
            exit;
        }
    }
} catch (Exception $e) {}

// ── Budget mensuel maximum ────────────────────────────────────
try {
    $budget_max = (float)($pdo->query("SELECT valeur FROM ai_settings WHERE cle='budget_mensuel_max'")->fetchColumn() ?: 0);
    if ($budget_max > 0) {
        $cout_mois = (float)$pdo->query("
            SELECT COALESCE(SUM(cout_eur), 0) FROM ai_conversations
            WHERE MONTH(cree_le) = MONTH(NOW()) AND YEAR(cree_le) = YEAR(NOW())
        ")->fetchColumn();
        if ($cout_mois >= $budget_max) {
            http_response_code(429);
            echo json_encode([
                'error'   => 'Budget épuisé',
                'message' => "Le budget IA mensuel ({$budget_max}€) a été atteint. L'administrateur peut le réajuster.",
                'cout_actuel' => round($cout_mois, 4),
                'budget_max'  => $budget_max,
            ]);
            exit;
        }
    }
} catch (Exception $e) {}





// ── Confirmation directe ──────────────────────────────────────
if ($page === 'confirm_action') {
    $action_data = json_decode($message, true);
    
    // LOG pour debug




    
    if (!$action_data || !isset($action_data['action'])) {
        echo json_encode(['error' => 'Action JSON invalide : ' . $message]);
        exit;
    }

    if (!$site_id) {
        echo json_encode(['error' => 'Aucun site actif']);
        exit;
    }

    try {
        $_act = $action_data['action'] ?? '';
        if (function_exists('ai_tool_is_action') && ai_tool_is_action($_act)) {
            $result = ai_action_run($pdo, $_act, $action_data['params'] ?? [], $site_id, $user_id);
        } else {
            $result = ai_execute_action($pdo, $action_data, $site_id, $user_id);
        }
        
        echo json_encode([
            'reply'         => $result ? ($result['status'] === 'success' ? '✅ ' . $result['message'] : '⚠️ ' . $result['message']) : '⚠️ Action non reconnue',
            'action_result' => $result,
            'suggestions'   => [],
            'tokens'        => 0,
                    ]);
    } catch (Throwable $e) {
        echo json_encode([
            'error' => 'Exception : ' . $e->getMessage(),
                    ]);
    }
    exit;
}

// Vérifier module IA activé
$actif = $pdo->query("SELECT valeur FROM ai_settings WHERE cle='actif'")->fetchColumn();
if (!$actif) { echo json_encode(['error' => 'Module IA désactivé']); exit; }

$api_key = $pdo->query("SELECT valeur FROM ai_settings WHERE cle='api_key'")->fetchColumn();
if (!$api_key) { echo json_encode(['error' => 'Clé API non configurée.']); exit; }

$model = $pdo->query("SELECT valeur FROM ai_settings WHERE cle='model'")->fetchColumn() ?: 'claude-haiku-4-5-20251001';

// ── Tarifs pour calcul coût exact ────────────────────────────
$_tarifs_api = [
    'claude-haiku-4-5-20251001' => ['in' => 0.80,  'out' => 4.00 ],
    'claude-haiku-3'            => ['in' => 0.25,  'out' => 1.25 ],
    'claude-sonnet-4-20250514'  => ['in' => 3.00,  'out' => 15.00],
    'claude-opus-4'             => ['in' => 15.00, 'out' => 75.00],
];
$_taux_eur_api = 0.92;
$_tarif_api    = $_tarifs_api[$model] ?? $_tarifs_api['claude-haiku-4-5-20251001'];

$history = $input['history'] ?? [];
$ctx           = ai_get_context($pdo, $page, $site_id, $user_id);
$system_prompt = ai_build_system_prompt($pdo, $ctx);

$messages = [];
foreach ($history as $h) {
    if (!empty($h['role']) && !empty($h['content'])) {
        $messages[] = ['role' => $h['role'], 'content' => $h['content']];
    }
}
if ($img_b64 !== '') {
    // Message multimodal : la photo + une consigne. Le modèle voit l'image ET a les outils.
    $img_text = $message !== ''
        ? $message
        : "Identifie ce médicament / produit d'après la photo : nom commercial, DCI (molécule), dosage, forme, numéro de lot et date de péremption s'ils sont lisibles. Ne devine JAMAIS un dosage ou un lot illisible (mets-le en inconnu). Résume ce que tu lis, puis demande-moi ce que je veux en faire (l'ajouter à un sac, renouveler un lot, etc.).";
    $messages[] = ['role' => 'user', 'content' => [
        ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $img_media, 'data' => $img_b64]],
        ['type' => 'text',  'text' => $img_text],
    ]];
} else {
    $messages[] = ['role' => 'user', 'content' => $message];
}

require_once __DIR__ . '/tools.php';

$tools      = array_merge(ai_tools_defs(), ai_actions_defs($pdo));
$IS_PROD_AI = str_contains($_SERVER['HTTP_HOST'] ?? '', 'cadurix.fr');

// Appel unitaire à l'API Anthropic
$call_api = function (array $messages) use ($model, $system_prompt, $tools, $api_key, $IS_PROD_AI) {
    $payload = [
        'model'      => $model,
        'max_tokens' => 1200,
        'system'     => $system_prompt,
        'messages'   => $messages,
        'tools'      => $tools,
    ];
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $api_key,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_TIMEOUT        => 40,
        CURLOPT_SSL_VERIFYPEER => $IS_PROD_AI,
        CURLOPT_SSL_VERIFYHOST => $IS_PROD_AI ? 2 : 0,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    curl_close($ch);
    return [$response, $httpCode, $curl_err];
};

// ── Boucle agent : lecture d'outils en direct puis réponse finale ──
$tokens_input  = 0;
$tokens_output = 0;
$reply         = '';
$loop          = 0;
$max_loops     = 5;
$ai_pending    = null;

while ($loop++ < $max_loops) {
    [$response, $httpCode, $curl_err] = $call_api($messages);

    if (!$response) { echo json_encode(['error' => 'Curl : ' . $curl_err]); exit; }

    $data = json_decode($response, true);
    if ($httpCode !== 200 || empty($data['content'])) {
        echo json_encode(['error' => $data['error']['message'] ?? 'Erreur HTTP ' . $httpCode]);
        exit;
    }

    $tokens_input  += (int)($data['usage']['input_tokens']  ?? 0);
    $tokens_output += (int)($data['usage']['output_tokens'] ?? 0);

    // Séparer texte et appels d'outils
    $text_parts = [];
    $tool_uses  = [];
    foreach ($data['content'] as $block) {
        $bt = $block['type'] ?? '';
        if ($bt === 'text')          $text_parts[] = $block['text'];
        elseif ($bt === 'tool_use')  $tool_uses[]  = $block;
    }
    $reply = trim(implode("\n", $text_parts));

    // Réponse finale si pas d'appel d'outil
    if (($data['stop_reason'] ?? '') !== 'tool_use' || empty($tool_uses)) {
        break;
    }

    // Une action d'écriture est proposée ? → on STOPPE et on demande confirmation (jamais exécutée ici)
    foreach ($tool_uses as $tu) {
        if (ai_tool_is_action($tu['name'] ?? '')) {
            $tin = is_array($tu['input'] ?? null) ? $tu['input'] : [];
            $ai_pending = ai_action_proposal($pdo, $tu['name'], $tin, $site_id);
            break;
        }
    }
    if ($ai_pending !== null) break;

    // Sinon : exécuter les outils de LECTURE et reboucler
    $messages[] = ['role' => 'assistant', 'content' => $data['content']];
    $tool_results = [];
    foreach ($tool_uses as $tu) {
        $tname  = $tu['name'] ?? '';
        $tin    = is_array($tu['input'] ?? null) ? $tu['input'] : [];
        $result = ai_tool_is_read($tname)
            ? ai_tool_run($pdo, $tname, $tin, $site_id, $user_id)
            : ['erreur' => "Outil non disponible."];
        $tool_results[] = [
            'type'        => 'tool_result',
            'tool_use_id' => $tu['id'] ?? '',
            'content'     => json_encode($result, JSON_UNESCAPED_UNICODE),
        ];
    }
    $messages[] = ['role' => 'user', 'content' => $tool_results];
}

$tokens = $tokens_input + $tokens_output;
// Calcul coût exact en €
$cout_eur = round(
    ($tokens_input  / 1_000_000 * $_tarif_api['in'] +
     $tokens_output / 1_000_000 * $_tarif_api['out'])
    * $_taux_eur_api,
6);

$action_result = null;
// Chercher JSON de rempotage dans la réponse (dans blocs code ou inline)
$json_patterns = [
    '/```json\s*({[\s\S]*?"action"\s*:\s*"rempotage"[\s\S]*?})\s*```/s',
    '/({[\s\S]*?"action"\s*:\s*"rempotage"[\s\S]*?})/s',
];
foreach ($json_patterns as $pattern) {
    if (preg_match($pattern, $reply, $m)) {
        $action_data = json_decode($m[1], true);
        if ($action_data && isset($action_data['action']) && $action_data['action'] === 'rempotage') {
            $empl_id = (int)($action_data['emplacement_id'] ?? 0);
            if ($empl_id) {
                $empl = $pdo->prepare("SELECT id, nom FROM emplacements WHERE id=? AND site_id=? AND actif=1");
                $empl->execute([$empl_id, $site_id]);
                $empl = $empl->fetch(PDO::FETCH_ASSOC);
                if ($empl) {
                    $action_result = [
                        'action'         => 'rempotage',
                        'status'         => 'pending_confirm',
                        'emplacement'    => $empl,
                        'emplacement_id' => $empl_id,
                        'produits'       => $action_data['produits'] ?? [],
                        'message'        => 'Rempotage prêt — confirmation requise',
                    ];
                    break;
                }
            }
        }
    }
}

if ($ai_pending !== null) { $action_result = $ai_pending; }

try {
    $pdo->prepare("INSERT INTO ai_conversations
        (user_id, session_id, page, message, reponse, action, action_data, tokens, tokens_input, tokens_output, cout_eur, modele)
        VALUES(?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([
            $user_id, session_id(), $page,
            ($img_b64 !== '' && $message === '') ? '[photo]' : $message, $reply,
            $action_result['action'] ?? null,
            $action_result ? json_encode($action_result) : null,
            $tokens, $tokens_input, $tokens_output, $cout_eur, $model
        ]);
} catch (Throwable) {}

echo json_encode([
    'reply'         => $reply,
    'action_result' => $action_result,
    'suggestions'   => $ctx['suggestions'],
    'tokens'        => $tokens,
]);

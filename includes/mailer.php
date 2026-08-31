<?php
// © Cadurix v5.4 — Helper d'envoi d'email réutilisable.
// Extrait de send_alerts.php (noms préfixés cdx_ pour éviter tout conflit).
// Charge la config SMTP depuis la table parametres et envoie via PHPMailer si présent, sinon mail().

require_once __DIR__ . '/email_layout.php'; // gabarit partagé cdx_email_layout / cdx_email_kv / cdx_email_note

if (!function_exists('cdx_get_param')) {
    function cdx_get_param(PDO $pdo, string $cle, string $default = ''): string {
        try {
            $s = $pdo->prepare("SELECT valeur FROM parametres WHERE cle=?");
            $s->execute([$cle]);
            $v = $s->fetchColumn();
            return ($v !== false && $v !== null && $v !== '') ? (string)$v : $default;
        } catch (Throwable $e) { return $default; }
    }
}

if (!function_exists('cdx_log_email')) {
    function cdx_log_email(PDO $pdo, string $type, string $to, ?string $subject, bool $ok, ?string $method = null, ?string $error = null, ?int $site = null): void {
        try {
            $st = $pdo->prepare("INSERT INTO email_log (type,destinataire,sujet,statut,methode,erreur,site_id) VALUES (?,?,?,?,?,?,?)");
            $st->execute([
                mb_substr($type, 0, 40),
                mb_substr($to, 0, 190),
                $subject !== null ? mb_substr($subject, 0, 255) : null,
                $ok ? 'ok' : 'echec',
                $method !== null ? mb_substr($method, 0, 40) : null,
                $error !== null ? mb_substr($error, 0, 500) : null,
                $site,
            ]);
        } catch (\Throwable $e) { /* le log ne doit jamais casser un envoi */ }
    }
}

if (!function_exists('cdx_send_mail_smtp')) {
    function cdx_send_mail_smtp(array $smtp, string $to_email, string $to_name, string $subject, string $html): bool {
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: " . ($smtp['from_name'] ?? 'Cadurix') . " <" . ($smtp['from'] ?? '') . ">\r\n";
        $headers .= "Reply-To: " . (!empty($smtp['reply_to']) ? $smtp['reply_to'] : ($smtp['from'] ?? '')) . "\r\n";
        $headers .= "X-Mailer: Cadurix/5.4\r\n";

        if (file_exists(__DIR__.'/../libs/PHPMailer/src/PHPMailer.php')) {
            require_once __DIR__.'/../libs/PHPMailer/src/PHPMailer.php';
            require_once __DIR__.'/../libs/PHPMailer/src/SMTP.php';
            require_once __DIR__.'/../libs/PHPMailer/src/Exception.php';
            try {
                $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                $mail->isSMTP();
                $mail->Host       = $smtp['host'];
                $mail->SMTPAuth   = true;
                $mail->Username   = $smtp['user'];
                $mail->Password   = $smtp['pass'];
                $mail->SMTPSecure = ($smtp['secure'] ?? 'tls') === 'ssl'
                    ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                    : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = (int)($smtp['port'] ?? 587);
                $mail->CharSet    = 'UTF-8';
                $mail->setFrom($smtp['from'], $smtp['from_name'] ?? 'Cadurix');
                if (!empty($smtp['reply_to'])) { $mail->addReplyTo($smtp['reply_to']); }
                $mail->addAddress($to_email, $to_name);
                $mail->isHTML(true);
                $mail->Subject = $subject;
                $mail->Body    = $html;
                $mail->send();
                return true;
            } catch (Exception $e) { return false; }
        }

        return @mail($to_email, $subject, $html, $headers);
    }
}

if (!function_exists('cadurix_send_mail')) {
    /**
     * Envoi haut-niveau : charge la config SMTP depuis parametres et envoie.
     * Retourne true si l'envoi a été accepté (best-effort).
     */
    function cadurix_send_mail(PDO $pdo, string $to_email, string $to_name, string $subject, string $html, string $type = 'general'): bool {
        if (!$to_email || !filter_var($to_email, FILTER_VALIDATE_EMAIL)) return false;

        // Pause globale des envois — ne bloque JAMAIS les emails critiques (2FA, réinit, sécurité)
        $critique = in_array($type, ['2fa','otp','reset','password','securite','security','critique'], true);
        if (!$critique && cdx_get_param($pdo, 'email_paused', '0') === '1') {
            cdx_log_email($pdo, $type, $to_email, $subject, false, 'pause', 'Envois en pause');
            return false;
        }

        $smtp = [
            'host'      => cdx_get_param($pdo, 'smtp_host'),
            'port'      => (int)cdx_get_param($pdo, 'smtp_port', '587'),
            'user'      => cdx_get_param($pdo, 'smtp_user'),
            'pass'      => cdx_get_param($pdo, 'smtp_pass'),
            'from'      => cdx_get_param($pdo, 'smtp_from') ?: cdx_get_param($pdo, 'mail_from', 'noreply@cadurix.fr'),
            'from_name' => cdx_get_param($pdo, 'smtp_from_name', 'Cadurix'),
            'secure'    => cdx_get_param($pdo, 'smtp_secure', 'tls'),
            'reply_to'  => cdx_get_param($pdo, 'sec_alert_email', ''),
        ];
        $ok = cdx_send_mail_smtp($smtp, $to_email, $to_name, $subject, $html);
        $method = file_exists(__DIR__.'/../libs/PHPMailer/src/PHPMailer.php') ? 'PHPMailer' : 'mail()';
        cdx_log_email($pdo, $type, $to_email, $subject, $ok, $method, $ok ? null : 'envoi refusé');
        return $ok;
    }
}

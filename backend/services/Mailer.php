<?php

class Mailer
{
    /**
     * Prefer Nexus when configured; fall back to SMTP Integrations settings.
     * @return array{ok:bool,error:?string,channel?:string}
     */
    public static function send(string $to, string $subject, string $body, ?string $attachmentPath = null, ?string $attachmentName = null): array
    {
        $to = trim($to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Invalid recipient email.'];
        }

        // Attachments still require SMTP path
        $hasAttachment = $attachmentPath && is_file($attachmentPath);
        if (!$hasAttachment && class_exists('NexusMailer') && NexusMailer::configured()) {
            $html = self::toHtml($body);
            $nexus = NexusMailer::send($to, $subject, $html, $body);
            if (!empty($nexus['ok'])) {
                return ['ok' => true, 'error' => null, 'channel' => 'nexus'];
            }
            // If Nexus is the intended primary channel, surface its error (do not pretend success).
            $preferNexus = strtolower((string) (getenv('NEXUS_PREFER') ?: ($_ENV['NEXUS_PREFER'] ?? '1')));
            if (in_array($preferNexus, ['1', 'true', 'yes', 'on'], true)) {
                $smtpHost = trim((string) Settings::get('smtp_host'));
                if ($smtpHost === '') {
                    return [
                        'ok' => false,
                        'error' => (string) ($nexus['error'] ?? 'Nexus email failed and SMTP is not configured.'),
                        'channel' => 'nexus',
                    ];
                }
            }
        }

        return self::sendSmtp($to, $subject, $body, $attachmentPath, $attachmentName);
    }

    private static function toHtml(string $body): string
    {
        if (str_contains($body, '<') && str_contains($body, '>')) {
            return $body;
        }
        return '<div style="font-family:Segoe UI,Arial,sans-serif;font-size:15px;line-height:1.5;color:#1a2e22">'
            . nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'))
            . '</div>';
    }

    /** @return array{ok:bool,error:?string,channel?:string} */
    private static function sendSmtp(string $to, string $subject, string $body, ?string $attachmentPath = null, ?string $attachmentName = null): array
    {
        $host = trim((string) Settings::get('smtp_host'));
        if ($host === '') {
            return [
                'ok' => false,
                'error' => 'Email is not configured. Set NEXUS_API_KEY (Nexus) or SMTP under Integrations.',
                'channel' => 'smtp',
            ];
        }
        $port = (int) (Settings::get('smtp_port') ?: 587);
        $user = (string) Settings::get('smtp_user');
        $pass = (string) Settings::get('smtp_pass');
        $enc = strtolower((string) (Settings::get('smtp_encryption') ?: 'tls'));
        $from = Settings::get('smtp_from') ?: $user;
        $fromName = Settings::get('smtp_from_name') ?: config('name');
        $timeout = 12;
        $remote = ($enc === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $fp = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
        if (!$fp) {
            return ['ok' => false, 'error' => 'Could not connect to SMTP: ' . ($errstr ?: $errno), 'channel' => 'smtp'];
        }
        stream_set_timeout($fp, $timeout);
        try {
            self::expect($fp, [220]);
            self::cmd($fp, 'EHLO ifms.local', [250]);
            if ($enc === 'tls') {
                self::cmd($fp, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('TLS negotiation failed.');
                }
                self::cmd($fp, 'EHLO ifms.local', [250]);
            }
            if ($user !== '') {
                self::cmd($fp, 'AUTH LOGIN', [334]);
                self::cmd($fp, base64_encode($user), [334]);
                self::cmd($fp, base64_encode($pass), [235]);
            }
            self::cmd($fp, 'MAIL FROM:<' . $from . '>', [250]);
            self::cmd($fp, 'RCPT TO:<' . $to . '>', [250, 251]);
            self::cmd($fp, 'DATA', [354]);
            $headers = [
                'Date: ' . date('r'),
                'From: ' . sprintf('"%s" <%s>', addcslashes($fromName, '"'), $from),
                'To: ' . $to,
                'Subject: ' . $subject,
                'MIME-Version: 1.0',
            ];
            if ($attachmentPath && is_file($attachmentPath)) {
                $boundary = 'fw' . bin2hex(random_bytes(8));
                $filename = $attachmentName ?: basename($attachmentPath);
                $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
                $bytes = file_get_contents($attachmentPath);
                $payload = '--' . $boundary . "\r\n"
                    . "Content-Type: text/plain; charset=UTF-8\r\n"
                    . "Content-Transfer-Encoding: 8bit\r\n\r\n"
                    . $body . "\r\n"
                    . '--' . $boundary . "\r\n"
                    . 'Content-Type: application/pdf; name="' . addcslashes($filename, '"') . "\"\r\n"
                    . "Content-Transfer-Encoding: base64\r\n"
                    . 'Content-Disposition: attachment; filename="' . addcslashes($filename, '"') . "\"\r\n\r\n"
                    . trim(chunk_split(base64_encode($bytes ?: ''))) . "\r\n"
                    . '--' . $boundary . "--\r\n";
            } else {
                $headers[] = 'Content-Type: text/plain; charset=UTF-8';
                $headers[] = 'Content-Transfer-Encoding: 8bit';
                $payload = $body;
            }
            $data = implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\n.", "\n..", $payload) . "\r\n.";
            fwrite($fp, $data . "\r\n");
            self::expect($fp, [250]);
            fwrite($fp, "QUIT\r\n");
            fclose($fp);
            return ['ok' => true, 'error' => null, 'channel' => 'smtp'];
        } catch (Throwable $e) {
            fclose($fp);
            return ['ok' => false, 'error' => $e->getMessage(), 'channel' => 'smtp'];
        }
    }

    private static function cmd($fp, string $line, array $ok): void
    {
        fwrite($fp, $line . "\r\n");
        self::expect($fp, $ok);
    }

    private static function expect($fp, array $ok): void
    {
        $response = '';
        while (($line = fgets($fp, 512)) !== false) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $ok, true)) {
            throw new RuntimeException(trim($response) ?: 'Empty SMTP response');
        }
    }
}

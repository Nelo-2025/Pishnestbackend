<?php
// Shared helpers for signup email OTP via PHP mail().

function otpBypassEnabled(): bool {
    // Temporary: skip the email code step. Flip to false to require OTP again.
    return true;
}

function otpEnsureSchema(mysqli $connect): void {
    static $ready = false;
    if ($ready) {
        return;
    }

    if (!dbHasColumn($connect, 'users', 'email_verified')) {
        // Default 1 so existing accounts keep working. New signups set 0.
        $connect->query(
            "ALTER TABLE users
             ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 1 AFTER role"
        );
    }

    $connect->query(
        "CREATE TABLE IF NOT EXISTS email_otps (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL,
            code_hash VARCHAR(255) NOT NULL,
            purpose VARCHAR(40) NOT NULL DEFAULT 'signup',
            expires_at DATETIME NOT NULL,
            used TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_email_otps_email_purpose (email, purpose),
            INDEX idx_email_otps_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $ready = true;
}

function otpGenerateCode(): string {
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function otpStoreCode(mysqli $connect, string $email, string $code, string $purpose = 'signup'): void {
    $email = strtolower(trim($email));
    $purpose = trim($purpose);
    $codeHash = password_hash($code, PASSWORD_DEFAULT);
    $expiresAt = (new DateTime('+15 minutes'))->format('Y-m-d H:i:s');

    // Invalidate previous unused codes for this email/purpose.
    $invalidate = $connect->prepare(
        "UPDATE email_otps SET used = 1
         WHERE email = ? AND purpose = ? AND used = 0"
    );
    $invalidate->bind_param("ss", $email, $purpose);
    $invalidate->execute();
    $invalidate->close();

    $insert = $connect->prepare(
        "INSERT INTO email_otps (email, code_hash, purpose, expires_at)
         VALUES (?, ?, ?, ?)"
    );
    $insert->bind_param("ssss", $email, $codeHash, $purpose, $expiresAt);
    $insert->execute();
    $insert->close();
}

function otpMailConfig(): array {
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $config = [
        'mail_from' => 'support@pishnest.co.uk',
        'mail_from_name' => 'PishNest',
        'smtp_host' => 'smtp.hostinger.com',
        'smtp_port' => 465,
        'smtp_user' => 'support@pishnest.co.uk',
        'smtp_pass' => '',
    ];

    $path = dirname(__DIR__) . '/secrets.local.php';
    if (is_file($path)) {
        $local = require $path;
        if (is_array($local)) {
            $config = array_merge($config, $local);
        }
    }

    $config['mail_from'] = trim((string) ($config['mail_from'] ?? 'support@pishnest.co.uk'));
    $config['mail_from_name'] = trim((string) ($config['mail_from_name'] ?? 'PishNest'));
    $config['smtp_host'] = trim((string) ($config['smtp_host'] ?? 'smtp.hostinger.com'));
    $config['smtp_port'] = (int) ($config['smtp_port'] ?? 465);
    $config['smtp_user'] = trim((string) ($config['smtp_user'] ?? $config['mail_from']));
    $config['smtp_pass'] = (string) ($config['smtp_pass'] ?? '');

    if ($config['mail_from'] === '') {
        $config['mail_from'] = 'support@pishnest.co.uk';
    }
    if ($config['smtp_user'] === '') {
        $config['smtp_user'] = $config['mail_from'];
    }

    return $config;
}

function otpMailBodies(string $code, string $name = ''): array {
    $displayName = trim($name) !== '' ? trim($name) : 'there';
    $plain = 'Hi ' . $displayName . ",\n\n"
        . 'Your PishNest verification code is: ' . $code . "\n\n"
        . "This code expires in 15 minutes.\n"
        . "If you did not create an account, you can ignore this email.\n\n"
        . "PishNest\n";

    $safeName = htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8');
    $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
    $html = '<!DOCTYPE html><html><body style="margin:0;padding:24px;background:#f4f6fb;font-family:Arial,Helvetica,sans-serif;color:#111;">'
        . '<div style="max-width:480px;margin:0 auto;background:#ffffff;border-radius:12px;padding:28px;">'
        . '<p style="margin:0 0 16px;">Hi ' . $safeName . ',</p>'
        . '<p style="margin:0 0 16px;">Your PishNest verification code is:</p>'
        . '<p style="margin:0 0 20px;font-size:32px;letter-spacing:0.35em;font-weight:700;text-align:center;">' . $safeCode . '</p>'
        . '<p style="margin:0 0 12px;">This code expires in 15 minutes.</p>'
        . '<p style="margin:0;">If you did not create an account, you can ignore this email.</p>'
        . '<p style="margin:24px 0 0;">PishNest</p>'
        . '</div></body></html>';

    return [$plain, $html];
}

function otpBuildMimeMessage(string $to, string $subject, string $plain, string $html, string $from, string $fromName): string {
    $boundary = 'pishnest-' . bin2hex(random_bytes(12));
    $fromHeader = $fromName !== '' ? ($fromName . ' <' . $from . '>') : $from;
    $headers = [
        'Date: ' . date('r'),
        'From: ' . $fromHeader,
        'Reply-To: ' . $from,
        'Return-Path: ' . $from,
        'To: ' . $to,
        'Subject: ' . $subject,
        'Message-ID: <' . bin2hex(random_bytes(16)) . '@pishnest.co.uk>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        'X-Mailer: PishNest',
    ];

    $body = '--' . $boundary . "\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n\r\n"
        . $plain . "\r\n"
        . '--' . $boundary . "\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n\r\n"
        . $html . "\r\n"
        . '--' . $boundary . "--\r\n";

    return implode("\r\n", $headers) . "\r\n\r\n" . $body;
}

function otpSmtpRead($fp): string {
    $response = '';
    while (($line = fgets($fp, 1024)) !== false) {
        $response .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }
    return $response;
}

function otpSmtpCmd($fp, string $command, string $expect): bool {
    fwrite($fp, $command . "\r\n");
    $response = otpSmtpRead($fp);
    return str_starts_with(trim($response), $expect);
}

function otpSmtpSend(string $to, string $subject, string $plain, string $html, array $config): bool {
    $user = $config['smtp_user'];
    $pass = $config['smtp_pass'];
    if ($user === '' || $pass === '') {
        return false;
    }

    $host = $config['smtp_host'];
    $port = (int) $config['smtp_port'];
    $from = $config['mail_from'];
    $remote = ($port === 465)
        ? ('ssl://' . $host . ':' . $port)
        : ('tcp://' . $host . ':' . $port);

    $fp = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);
    if (!$fp) {
        error_log('PishNest OTP SMTP connect failed: ' . $errstr);
        return false;
    }
    stream_set_timeout($fp, 20);

    $greet = otpSmtpRead($fp);
    if (!str_starts_with(trim($greet), '220')) {
        fclose($fp);
        return false;
    }

    $ehloHost = 'pishnest.co.uk';
    if (!otpSmtpCmd($fp, 'EHLO ' . $ehloHost, '250')) {
        fclose($fp);
        return false;
    }

    if ($port === 587) {
        if (!otpSmtpCmd($fp, 'STARTTLS', '220')) {
            fclose($fp);
            return false;
        }
        $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            $crypto = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        }
        if (!@stream_socket_enable_crypto($fp, true, $crypto)) {
            fclose($fp);
            return false;
        }
        if (!otpSmtpCmd($fp, 'EHLO ' . $ehloHost, '250')) {
            fclose($fp);
            return false;
        }
    }

    if (!otpSmtpCmd($fp, 'AUTH LOGIN', '334')) {
        fclose($fp);
        return false;
    }
    if (!otpSmtpCmd($fp, base64_encode($user), '334')) {
        fclose($fp);
        return false;
    }
    if (!otpSmtpCmd($fp, base64_encode($pass), '235')) {
        error_log('PishNest OTP SMTP auth failed for ' . $user);
        fclose($fp);
        return false;
    }
    if (!otpSmtpCmd($fp, 'MAIL FROM:<' . $from . '>', '250')) {
        fclose($fp);
        return false;
    }
    if (!otpSmtpCmd($fp, 'RCPT TO:<' . $to . '>', '250')) {
        fclose($fp);
        return false;
    }
    if (!otpSmtpCmd($fp, 'DATA', '354')) {
        fclose($fp);
        return false;
    }

    $message = otpBuildMimeMessage($to, $subject, $plain, $html, $from, (string) $config['mail_from_name']);
    fwrite($fp, $message . "\r\n.\r\n");
    $dataReply = otpSmtpRead($fp);
    $ok = str_starts_with(trim($dataReply), '250');
    fwrite($fp, "QUIT\r\n");
    fclose($fp);

    if (!$ok) {
        error_log('PishNest OTP SMTP DATA rejected: ' . trim($dataReply));
    }
    return $ok;
}

function otpPhpMailSend(string $to, string $subject, string $plain, string $html, array $config): bool {
    $from = $config['mail_from'];
    $fromName = (string) $config['mail_from_name'];
    $fromHeader = $fromName !== '' ? ($fromName . ' <' . $from . '>') : $from;
    $headers = implode("\r\n", [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $fromHeader,
        'Reply-To: ' . $from,
        'Return-Path: ' . $from,
        'Date: ' . date('r'),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@pishnest.co.uk>',
        'X-Mailer: PishNest',
    ]);

    @ini_set('sendmail_from', $from);

    $sent = mail($to, $subject, $html, $headers, '-f' . $from);
    if ($sent) {
        return true;
    }

    $sent = mail($to, $subject, $html, $headers);
    if ($sent) {
        return true;
    }

    $plainHeaders = implode("\r\n", [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . $from,
        'Reply-To: ' . $from,
    ]);
    $sent = mail($to, $subject, $plain, $plainHeaders, '-f' . $from);
    if (!$sent) {
        error_log('PishNest OTP mail() failed for ' . $to);
    }
    return $sent;
}

function otpSendMail(string $email, string $code, string $name = ''): bool {
    $to = $email;
    $subject = 'Your PishNest verification code';
    [$plain, $html] = otpMailBodies($code, $name);
    $config = otpMailConfig();

    if (otpSmtpSend($to, $subject, $plain, $html, $config)) {
        return true;
    }

    return otpPhpMailSend($to, $subject, $plain, $html, $config);
}

function otpCreateAndSend(mysqli $connect, string $email, string $name = '', string $purpose = 'signup'): array {
    otpEnsureSchema($connect);
    $code = otpGenerateCode();
    otpStoreCode($connect, $email, $code, $purpose);
    $sent = otpSendMail($email, $code, $name);

    return [
        'sent' => $sent,
        'code' => $code,
    ];
}

function otpVerifyCode(mysqli $connect, string $email, string $code, string $purpose = 'signup'): bool {
    otpEnsureSchema($connect);
    $email = strtolower(trim($email));
    $code = trim($code);
    $purpose = trim($purpose);

    if ($email === '' || !preg_match('/^\d{6}$/', $code)) {
        return false;
    }

    $stmt = $connect->prepare(
        "SELECT id, code_hash FROM email_otps
         WHERE email = ? AND purpose = ? AND used = 0 AND expires_at > NOW()
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->bind_param("ss", $email, $purpose);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || !password_verify($code, $row['code_hash'])) {
        return false;
    }

    $mark = $connect->prepare("UPDATE email_otps SET used = 1 WHERE id = ?");
    $otpId = (int) $row['id'];
    $mark->bind_param("i", $otpId);
    $mark->execute();
    $mark->close();

    return true;
}

function otpMarkUserVerified(mysqli $connect, string $email): bool {
    otpEnsureSchema($connect);
    $email = strtolower(trim($email));
    $stmt = $connect->prepare("UPDATE users SET email_verified = 1 WHERE email = ?");
    $stmt->bind_param("s", $email);
    $ok = $stmt->execute() && $stmt->affected_rows >= 0;
    $stmt->close();
    return $ok;
}

function otpIsUserVerified(mysqli $connect, array $user): bool {
    otpEnsureSchema($connect);
    if (!array_key_exists('email_verified', $user)) {
        // Fetch fresh value if the caller did not select the column.
        if (empty($user['id'])) {
            return true;
        }
        $id = (int) $user['id'];
        $stmt = $connect->prepare("SELECT email_verified FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return !$row || (int) ($row['email_verified'] ?? 1) === 1;
    }
    return (int) $user['email_verified'] === 1;
}

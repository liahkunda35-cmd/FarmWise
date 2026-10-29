<?php

/**
 * Account lifecycle emails (registration, approval, activation, reset).
 * FarmWise never emails plaintext passwords — approved accounts get a one-time activation link.
 */
class AccountMail
{
    public static function queueAndSend(array $msg): array
    {
        $id = MessageDispatcher::queue($msg);
        return MessageDispatcher::deliver(db()->find('outbound_messages', $id));
    }

    public static function registrationReceived(array $user): array
    {
        $subject = 'FarmWise registration received';
        $body = "Hello {$user['name']},\n\n"
            . "We received your FarmWise access request"
            . (!empty($user['requested_role']) ? ' for the role “' . (Auth::roles()[$user['requested_role']] ?? $user['requested_role']) . '”' : '')
            . ".\n\n"
            . "An administrator will review your request. You will receive another email when a decision is made.\n\n"
            . "You cannot sign in until your account is approved and activated.\n\n"
            . "— The FarmWise Team";
        return self::queueAndSend([
            'user_id' => $user['id'],
            'channel' => 'email',
            'recipient' => $user['email'],
            'subject' => $subject,
            'body' => $body,
        ]);
    }

    /**
     * Built-in FarmWise "account credentials" template.
     * Includes name, email, username + activation link — never a password.
     */
    public static function accountApproved(array $user, string $activationUrl): array
    {
        $roleLabel = Auth::roles()[$user['role']] ?? $user['role'];
        $username = trim((string) ($user['username'] ?? ''));
        if ($username === '') {
            $username = '(use your email to sign in)';
        }

        $subject = 'FarmWise Account Credentials';
        $body = "Hello {$user['name']},\n\n"
            . "Welcome to FarmWise!\n\n"
            . "Your account has been approved"
            . ($roleLabel !== '' ? " as {$roleLabel}" : '')
            . ". Here are your login details:\n\n"
            . "Email: {$user['email']}\n"
            . "Username: {$username}\n\n"
            . "For your security, FarmWise does not send passwords by email.\n"
            . "Set your own password with this one-time activation link (expires in 48 hours):\n"
            . "{$activationUrl}\n\n"
            . "After you set your password, sign in at FarmWise with your email or username.\n\n"
            . "Thank you for choosing FarmWise!\n\n"
            . "The FarmWise Team";

        return self::queueAndSend([
            'user_id' => $user['id'],
            'channel' => 'email',
            'recipient' => $user['email'],
            'subject' => $subject,
            'body' => $body,
        ]);
    }

    public static function accountRejected(array $user, string $reason = ''): array
    {
        $subject = 'FarmWise registration decision';
        $body = "Hello {$user['name']},\n\n"
            . "Your FarmWise registration request was not approved.\n";
        if ($reason !== '') {
            $body .= "\nReason: {$reason}\n";
        }
        $body .= "\nIf you believe this is a mistake, contact your farm administrator.\n\n— The FarmWise Team";
        return self::queueAndSend([
            'user_id' => $user['id'],
            'channel' => 'email',
            'recipient' => $user['email'],
            'subject' => $subject,
            'body' => $body,
        ]);
    }

    public static function passwordReset(array $user, string $resetUrl): array
    {
        $subject = 'Reset your FarmWise password';
        $body = "Hello {$user['name']},\n\n"
            . "We received a request to reset your FarmWise password.\n\n"
            . "Open this link to choose a new password:\n{$resetUrl}\n\n"
            . "This link expires in 1 hour. If you did not request this, you can ignore this message.\n\n"
            . "— The FarmWise Team";
        return self::queueAndSend([
            'farm_id' => $user['farm_id'] ?: null,
            'user_id' => $user['id'],
            'channel' => 'email',
            'recipient' => $user['email'],
            'subject' => $subject,
            'body' => $body,
        ]);
    }
}

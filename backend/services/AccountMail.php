<?php

/**
 * Account lifecycle emails (registration, approval, activation, reset).
 * Registration / approval never include passwords. Employee login invites
 * intentionally include the one-time temporary password the admin set.
 */
class AccountMail
{
    public static function queueAndSend(array $msg): array
    {
        $id = MessageDispatcher::queue($msg);
        return MessageDispatcher::deliver(db()->find('outbound_messages', $id));
    }

    public static function registrationReceived(array $user, ?array $farm = null): array
    {
        $farmName = trim((string) ($farm['name'] ?? ''));
        if ($farmName === '') {
            $farmName = 'FarmWise';
        }
        $approved = ($user['status'] ?? '') === 'active';
        $statusLabel = $approved ? 'Approved' : 'Pending Approval';
        $subject = 'Successfully Registered with ' . $farmName;
        $body = "Hello {$user['name']},\n\n"
            . "You have successfully registered with {$farmName} on FarmWise.\n\n"
            . "Your registration details have been received.\n\n"
            . "Your account status: {$statusLabel}.\n"
            . "Farm: {$farmName}.\n";
        if (!$approved) {
            $body .= "\nAn administrator still needs to approve this account before you can sign in. Use the username or email and the password you chose during registration. FarmWise does not email passwords.\n";
        } else {
            $body .= "\nYour account is active. Sign in at " . absolute_url('login') . " with the username or email and the password you chose. FarmWise does not email passwords.\n";
        }
        $body .= "\nThank you for joining FarmWise.\n\n— The FarmWise Team";
        return self::queueAndSend([
            'farm_id' => $farm['id'] ?? ($user['farm_id'] ?: null),
            'user_id' => $user['id'],
            'channel' => 'email',
            'recipient' => $user['email'],
            'subject' => $subject,
            'body' => $body,
        ]);
    }

    public static function accountApprovedReady(array $user, ?array $farm = null): array
    {
        $farmName = trim((string) ($farm['name'] ?? ''));
        if ($farmName === '') {
            $farmName = 'your farm';
        }
        $roleLabel = Auth::roles()[$user['role']] ?? ($user['role'] ?? '');
        $loginUrl = absolute_url('login');
        $subject = 'Your FarmWise account is approved';
        $username = trim((string) ($user['username'] ?? ''));
        $body = "Hello {$user['name']},\n\n"
            . "Your FarmWise farm-owner account has been approved and activated.\n"
            . ($farmName !== '' && $farmName !== 'your farm' ? "Farm: {$farmName}.\n" : '')
            . ($roleLabel !== '' ? "Role: {$roleLabel}.\n" : '')
            . "\nSign in with:\n"
            . "Email: {$user['email']}\n"
            . ($username !== '' ? "Username: {$username}\n" : '')
            . "Password: the password you created during registration\n\n"
            . "FarmWise never emails passwords. Use the password you already chose.\n"
            . "After you sign in, create your farm to get a Farm Code you can share with managers, workers and other staff.\n\n"
            . "Login page: {$loginUrl}\n\n"
            . "— The FarmWise Team";
        return self::queueAndSend([
            'farm_id' => $farm['id'] ?? ($user['farm_id'] ?: null),
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

    /**
     * Employee invite created by an administrator — includes the temporary password
     * the admin set (or that FarmWise generated) so the worker can sign in once.
     *
     * @param array{name?:string,email?:string,username?:string,id?:int,farm_id?:mixed} $user
     * @return array{ok:bool,error:?string,channel?:string,subject?:string,body?:string,mailto?:string}
     */
    public static function employeeLoginInvite(array $user, string $tempPassword, ?array $farm = null): array
    {
        $farmName = trim((string) ($farm['name'] ?? ''));
        if ($farmName === '') {
            $farmName = 'your farm';
        }
        $loginUrl = absolute_url('login');
        $email = trim((string) ($user['email'] ?? ''));
        $username = trim((string) ($user['username'] ?? ''));
        $name = trim((string) ($user['name'] ?? 'there'));
        $subject = 'Your FarmWise login details — ' . $farmName;
        $body = "Hello {$name},\n\n"
            . "An administrator created a FarmWise login for you"
            . ($farmName !== '' && $farmName !== 'your farm' ? " at {$farmName}" : '')
            . ".\n\n"
            . "=== YOUR LOGIN DETAILS ===\n"
            . "Login page: {$loginUrl}\n"
            . ($email !== '' && !str_ends_with($email, '@employees.farmwise.local') ? "Email: {$email}\n" : '')
            . ($username !== '' ? "Username: {$username}\n" : '')
            . "Temporary password: {$tempPassword}\n"
            . "==========================\n\n"
            . "You must change this password the first time you sign in.\n"
            . "Do not share this email with anyone else.\n\n"
            . "— The FarmWise Team";

        $mailto = '';
        if ($email !== '' && !str_ends_with($email, '@employees.farmwise.local')) {
            $mailto = 'mailto:' . rawurlencode($email)
                . '?subject=' . rawurlencode($subject)
                . '&body=' . rawurlencode($body);
        }

        if ($email === '' || str_ends_with($email, '@employees.farmwise.local')) {
            return [
                'ok' => false,
                'error' => 'No real employee email on file to send login details.',
                'subject' => $subject,
                'body' => $body,
                'mailto' => $mailto,
            ];
        }

        $result = self::queueAndSend([
            'farm_id' => $farm['id'] ?? ($user['farm_id'] ?? null),
            'user_id' => $user['id'] ?? null,
            'channel' => 'email',
            'recipient' => $email,
            'subject' => $subject,
            'body' => $body,
        ]);
        $result['subject'] = $subject;
        $result['body'] = $body;
        $result['mailto'] = $mailto;
        return $result;
    }
}

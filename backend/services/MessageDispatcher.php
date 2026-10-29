<?php

class MessageDispatcher
{
    public static function fromNotification(array $data, int $notificationId): void
    {
        $farmId = $data['farm_id'] ?? null;
        $users = [];
        if (!empty($data['user_id'])) {
            $u = db()->find('users', $data['user_id']);
            if ($u) {
                $users[] = $u;
            }
        } else {
            $sql = "SELECT * FROM users WHERE status = 'active'";
            $params = [];
            if ($farmId) {
                $sql .= ' AND (farm_id = ? OR role = ?)';
                $params[] = $farmId;
                $params[] = 'admin';
            }
            $users = db()->select($sql, $params);
        }
        $subject = '[IFMS] ' . ($data['title'] ?? 'Farm alert');
        $body = ($data['message'] ?? '') . "\n\nOpen IFMS to review this alert.";
        foreach ($users as $user) {
            if (Settings::enabled('notify_email') && !empty($user['email'])) {
                self::queue([
                    'farm_id' => $farmId,
                    'user_id' => $user['id'],
                    'notification_id' => $notificationId,
                    'channel' => 'email',
                    'recipient' => $user['email'],
                    'subject' => $subject,
                    'body' => $body,
                ]);
            }
            if (Settings::enabled('notify_sms') && !empty($user['phone'])) {
                self::queue([
                    'farm_id' => $farmId,
                    'user_id' => $user['id'],
                    'notification_id' => $notificationId,
                    'channel' => 'sms',
                    'recipient' => $user['phone'],
                    'subject' => $subject,
                    'body' => $data['title'] . ': ' . $data['message'],
                ]);
            }
        }
        self::flush();
    }

    public static function queue(array $row): int
    {
        $row['status'] = 'pending';
        return db()->insert('outbound_messages', $row);
    }

    public static function flush(int $limit = 40): void
    {
        foreach (db()->select("SELECT * FROM outbound_messages WHERE status = 'pending' ORDER BY id ASC LIMIT " . (int) $limit) as $msg) {
            self::deliver($msg);
        }
    }

    public static function deliver(array $msg): array
    {
        if (($msg['channel'] ?? '') === 'sms') {
            $result = SmsGateway::send($msg['recipient'], $msg['body']);
        } else {
            $result = Mailer::send($msg['recipient'], $msg['subject'] ?: 'IFMS notification', $msg['body']);
        }
        db()->update('outbound_messages', [
            'status' => $result['ok'] ? 'sent' : 'failed',
            'error' => $result['error'],
            'sent_at' => $result['ok'] ? date('Y-m-d H:i:s') : null,
        ], $msg['id']);
        return $result;
    }

    public static function testEmail(string $to): array
    {
        $id = self::queue([
            'farm_id' => Auth::farmId(),
            'user_id' => Auth::id(),
            'channel' => 'email',
            'recipient' => $to,
            'subject' => 'IFMS email test',
            'body' => 'This is a live test from the Intelligent Farm Management System. Email delivery is working.',
        ]);
        return self::deliver(db()->find('outbound_messages', $id));
    }

    public static function testSms(string $to): array
    {
        $id = self::queue([
            'farm_id' => Auth::farmId(),
            'user_id' => Auth::id(),
            'channel' => 'sms',
            'recipient' => $to,
            'subject' => 'IFMS SMS test',
            'body' => 'IFMS SMS test: outbound messaging is working.',
        ]);
        return self::deliver(db()->find('outbound_messages', $id));
    }
}

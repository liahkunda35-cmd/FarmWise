<?php

/**
 * Login-page quick sign-in for the five built-in FarmWise demo accounts.
 * Uses real DB users and the normal Auth::attemptLogin path — never bypasses RBAC.
 */
class DemoSignIn
{
    /** @return list<array{name:string,username:string,email:string,password:string,role:string,label:string,icon:string}> */
    public static function definitions(): array
    {
        return [
            [
                'name' => 'Administrator',
                'username' => 'admin',
                'email' => 'admin@ifms.local',
                'password' => 'Admin#2026',
                'role' => 'admin',
                'label' => 'Administrator',
                'icon' => 'shield',
            ],
            [
                'name' => 'Thandi Mokoena',
                'username' => 'owner',
                'email' => 'owner@ifms.local',
                'password' => 'Owner#2026',
                'role' => 'owner',
                'label' => 'Farm Owner',
                'icon' => 'landmark',
            ],
            [
                'name' => 'Johan van Wyk',
                'username' => 'manager',
                'email' => 'manager@ifms.local',
                'password' => 'Manager#2026',
                'role' => 'manager',
                'label' => 'Farm Manager',
                'icon' => 'clipboard',
            ],
            [
                'name' => 'Lerato Dlamini',
                'username' => 'agronomist',
                'email' => 'agro@ifms.local',
                'password' => 'Agro#2026',
                'role' => 'agronomist',
                'label' => 'Farm Supervisor',
                'icon' => 'leaf',
            ],
            [
                'name' => 'Sipho Ndlovu',
                'username' => 'worker',
                'email' => 'worker@ifms.local',
                'password' => 'Worker#2026',
                'role' => 'worker',
                'label' => 'Farm Worker',
                'icon' => 'wrench',
            ],
        ];
    }

    /** Create any missing @ifms.local demo users once; never duplicates. */
    public static function ensureAccounts(): void
    {
        // Prefer the user's own farm if already assigned; do not recreate a demo estate.
        $farm = db()->first('SELECT id, owner_id FROM farms ORDER BY id ASC');
        $farmId = $farm ? (int) $farm['id'] : null;

        foreach (self::definitions() as $def) {
            $existing = db()->first(
                'SELECT id, username, role, farm_id, status, password FROM users WHERE lower(email) = ?',
                [strtolower($def['email'])]
            );
            if ($existing) {
                $patch = [];
                if (trim((string) ($existing['username'] ?? '')) === '') {
                    $taken = db()->first(
                        'SELECT id FROM users WHERE lower(username) = ? AND id != ?',
                        [strtolower($def['username']), (int) $existing['id']]
                    );
                    if (!$taken) {
                        $patch['username'] = $def['username'];
                    }
                }
                if (($existing['status'] ?? '') !== 'active') {
                    $patch['status'] = 'active';
                }
                if ($def['role'] !== 'admin' && $farmId && empty($existing['farm_id'])) {
                    $patch['farm_id'] = $farmId;
                }
                if ($patch) {
                    $patch['updated_at'] = date('Y-m-d H:i:s');
                    db()->update('users', $patch, (int) $existing['id']);
                }
                if ($def['role'] !== 'admin' && $farmId && table_exists('user_farms')) {
                    $link = db()->first(
                        'SELECT id FROM user_farms WHERE user_id = ? AND farm_id = ?',
                        [(int) $existing['id'], $farmId]
                    );
                    if (!$link) {
                        db()->insert('user_farms', [
                            'user_id' => (int) $existing['id'],
                            'farm_id' => $farmId,
                            'is_primary' => 1,
                            'created_at' => date('Y-m-d H:i:s'),
                        ]);
                    }
                }
                if ($def['role'] === 'owner' && $farmId && (int) ($farm['owner_id'] ?? 0) !== (int) $existing['id']) {
                    // Only reclaim ownership if the current owner_id is missing or points at a deleted user.
                    $ownerOk = !empty($farm['owner_id']) && db()->find('users', (int) $farm['owner_id']);
                    if (!$ownerOk) {
                        db()->update('farms', ['owner_id' => (int) $existing['id']], $farmId);
                        $farm['owner_id'] = (int) $existing['id'];
                    }
                }
                continue;
            }

            $userNameTaken = db()->first(
                'SELECT id FROM users WHERE lower(username) = ?',
                [strtolower($def['username'])]
            );
            $username = $userNameTaken ? null : $def['username'];
            $fid = $def['role'] === 'admin' ? null : $farmId;
            $id = db()->insert('users', [
                'name' => $def['name'],
                'username' => $username,
                'email' => $def['email'],
                'password' => password_hash($def['password'], PASSWORD_DEFAULT),
                'role' => $def['role'],
                'phone' => null,
                'farm_id' => $fid,
                'status' => 'active',
                'must_change_password' => 0,
                'session_version' => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            if ($fid && table_exists('user_farms')) {
                db()->insert('user_farms', [
                    'user_id' => $id,
                    'farm_id' => $fid,
                    'is_primary' => 1,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }
            if ($def['role'] === 'owner' && $fid) {
                $ownerOk = !empty($farm['owner_id']) && db()->find('users', (int) $farm['owner_id']);
                if (!$ownerOk) {
                    db()->update('farms', ['owner_id' => $id], $fid);
                    $farm['owner_id'] = $id;
                }
            }
        }
    }

    /**
     * Public picker rows + masked fill tokens for accounts that still use the demo password.
     * @return array{accounts:list<array<string,mixed>>,fills:array<string,string>}
     */
    public static function forLoginPage(): array
    {
        self::ensureAccounts();
        $accounts = [];
        $fills = [];
        foreach (self::definitions() as $def) {
            $user = db()->first(
                'SELECT id, name, username, email, role, status, password FROM users WHERE lower(email) = ?',
                [strtolower($def['email'])]
            );
            if (!$user || ($user['status'] ?? '') !== 'active') {
                continue;
            }
            $identity = trim((string) ($user['username'] ?? '')) !== ''
                ? (string) $user['username']
                : (string) $user['email'];
            $parts = preg_split('/\s+/', trim((string) $user['name'])) ?: [];
            $initials = strtoupper(mb_substr($parts[0] ?? 'U', 0, 1) . mb_substr($parts[1] ?? ($parts[0] ?? 'U'), 0, 1));
            $accounts[] = [
                'id' => (int) $user['id'],
                'name' => (string) $user['name'],
                'email' => (string) $user['email'],
                'username' => (string) ($user['username'] ?? ''),
                'identity' => $identity,
                'role' => (string) $user['role'],
                'label' => $def['label'],
                'icon' => $def['icon'],
                'initials' => $initials,
                'can_fill_password' => password_verify($def['password'], (string) $user['password']),
            ];
            if (password_verify($def['password'], (string) $user['password'])) {
                // Obfuscate only for the page payload — password input stays type=password (masked).
                $fills[(string) $user['email']] = base64_encode($def['password']);
            }
        }
        return ['accounts' => $accounts, 'fills' => $fills];
    }
}

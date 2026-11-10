<?php

class Seed
{
    public static function run(): void
    {
        if ((int) db()->value('SELECT COUNT(*) FROM users') > 0) {
            return;
        }

        // Fresh installs start without a demo farm. Users register or create a farm themselves.
        $users = [
            ['Administrator', 'admin', 'admin@ifms.local', 'Admin#2026', 'admin', null],
            ['Thandi Mokoena', 'owner', 'owner@ifms.local', 'Owner#2026', 'owner', null],
            ['Johan van Wyk', 'manager', 'manager@ifms.local', 'Manager#2026', 'manager', null],
            ['Lerato Dlamini', 'agronomist', 'agro@ifms.local', 'Agro#2026', 'agronomist', null],
            ['Sipho Ndlovu', 'worker', 'worker@ifms.local', 'Worker#2026', 'worker', null],
            ['Ayesha Patel', 'accountant', 'accounts@ifms.local', 'Accounts#2026', 'accountant', null],
        ];
        foreach ($users as [$name, $username, $email, $pass, $role, $fid]) {
            db()->insert('users', [
                'name' => $name,
                'username' => $username,
                'email' => $email,
                'password' => password_hash($pass, PASSWORD_DEFAULT),
                'role' => $role,
                'phone' => null,
                'farm_id' => $fid,
                'status' => 'active',
                'must_change_password' => 0,
                'session_version' => 1,
            ]);
        }
    }
}

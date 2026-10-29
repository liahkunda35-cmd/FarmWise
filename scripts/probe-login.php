<?php
require dirname(__DIR__) . '/backend/bootstrap.php';
foreach (['admin@ifms.local' => 'Admin#2026', 'manager@ifms.local' => 'Manager#2026', 'owner@ifms.local' => 'Owner#2026'] as $email => $pass) {
    $u = db()->first('SELECT id, email, username, role, status, password FROM users WHERE email = ? OR username = ?', [$email, explode('@', $email)[0]]);
    if (!$u) {
        echo "$email MISSING\n";
        continue;
    }
    $ok = password_verify($pass, $u['password']);
    echo $email . ' role=' . $u['role'] . ' status=' . $u['status'] . ' verify=' . ($ok ? 'yes' : 'NO') . PHP_EOL;
}

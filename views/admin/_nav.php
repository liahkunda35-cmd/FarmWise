<?php
/**
 * Admin Console tab bar — include at top of every admin view.
 */
$adminTabs = [
    ['key' => 'users', 'label' => 'Users', 'href' => 'users'],
    ['key' => 'farms', 'label' => 'Farms', 'href' => 'module/farms', 'perm' => 'farms'],
    ['key' => 'roles', 'label' => 'Roles & permissions', 'href' => 'users?tab=roles', 'perm' => 'users'],
    ['key' => 'settings', 'label' => 'Settings', 'href' => 'settings', 'perm' => 'settings'],
    ['key' => 'integrations', 'label' => 'Integrations', 'href' => 'integrations', 'perm' => 'integrations'],
    ['key' => 'messages', 'label' => 'Messages', 'href' => 'messages', 'perm' => 'messages'],
    ['key' => 'audit_logs', 'label' => 'Audit log', 'href' => 'audit', 'perm' => 'audit_logs'],
    ['key' => 'backups', 'label' => 'Backups', 'href' => 'backup', 'perm' => 'backups'],
];
$path = trim(current_path(), '/');
$activeTab = $activeAdminTab ?? null;
if (!$activeTab) {
    if (str_starts_with($path, 'users')) $activeTab = (input('tab') === 'roles') ? 'roles' : 'users';
    elseif (str_starts_with($path, 'settings')) $activeTab = 'settings';
    elseif (str_starts_with($path, 'integrations')) $activeTab = 'integrations';
    elseif (str_starts_with($path, 'messages')) $activeTab = 'messages';
    elseif (str_starts_with($path, 'audit')) $activeTab = 'audit_logs';
    elseif (str_starts_with($path, 'backup')) $activeTab = 'backups';
    elseif (str_contains($path, 'module/farms')) $activeTab = 'farms';
}
?>
<div class="admin-console-band">
    <div class="admin-console-head">
        <span class="admin-mode-label">Admin mode</span>
        <h2>Admin Console</h2>
    </div>
    <nav class="admin-tabs" aria-label="Admin console">
        <?php foreach ($adminTabs as $tab):
            $perm = $tab['perm'] ?? $tab['key'];
            if ($tab['key'] === 'farms') {
                if (!Auth::can('farms', 'view')) continue;
            } elseif ($tab['key'] === 'roles') {
                if (!Auth::can('users', 'view') && !Auth::isAdmin()) continue;
            } elseif (!Auth::can($perm, 'view') && !Auth::isAdmin()) {
                // settings might use different key
                if ($perm === 'settings' && !Auth::isAdmin() && Auth::role() !== 'owner') continue;
                if ($perm !== 'settings') continue;
            }
            $isActive = $activeTab === $tab['key'];
        ?>
            <a class="admin-tab<?= $isActive ? ' is-active' : '' ?>" href="<?= e(url($tab['href'])) ?>" <?= $isActive ? 'aria-current="page"' : '' ?>><?= e($tab['label']) ?></a>
        <?php endforeach; ?>
    </nav>
</div>

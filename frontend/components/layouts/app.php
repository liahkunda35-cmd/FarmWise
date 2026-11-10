<?php
$user = fe_user();
$unread = fe_unread_count(fe_id(), fe_farm_id());
$farmChoices = fe_accessible_farms();
$activeFarm = fe_active_farm();
$isDashboard = rtrim(current_path(), '/') === '/dashboard';
$assetV = 'p62';
$pagePath = trim(current_path(), '/');
$needsChart = $pagePath === 'dashboard' || str_starts_with($pagePath, 'reports') || str_starts_with($pagePath, 'forecasts') || $pagePath === 'weather' || str_starts_with($pagePath, 'market') || str_starts_with($pagePath, 'agri');
$needsMap = $pagePath === 'map'
    || $pagePath === 'fields-planner'
    || $pagePath === 'crop-rotation'
    || str_starts_with($pagePath, 'module/farms')
    || str_starts_with($pagePath, 'module/fields')
    || $pagePath === 'weather';
$employeeCreds = null;
if (!empty($_SESSION['_employee_temp_creds']) && is_array($_SESSION['_employee_temp_creds'])) {
    $employeeCreds = $_SESSION['_employee_temp_creds'];
    unset($_SESSION['_employee_temp_creds']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= brand_head($title ?? 'Dashboard') ?>
    <link rel="stylesheet" href="<?= e(asset('vendor/fonts/fonts.css')) ?>?v=<?= e($assetV) ?>">
    <?php if ($needsMap): ?>
    <link rel="stylesheet" href="<?= e(asset('vendor/leaflet/leaflet.css')) ?>?v=<?= e($assetV) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/leaflet-draw/leaflet.draw.css')) ?>?v=<?= e($assetV) ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>?v=<?= e($assetV) ?>">
</head>
<body class="app-body<?= $isDashboard ? ' is-dashboard' : '' ?><?= fe_is_admin() ? ' is-admin' : '' ?>">
<button class="nav-toggle" type="button" data-nav-toggle aria-label="Open menu" aria-controls="sidebar" aria-expanded="false">☰</button>
<aside class="sidebar" id="sidebar">
    <a class="brand brand-sidebar" href="<?= e(url('dashboard')) ?>">
        <?= brand_logo() ?>
        <span class="brand-text">
            <strong>FarmWise</strong>
            <small><?= e(fe_public_config('tagline')) ?></small>
        </span>
    </a>
    <nav class="side-nav" aria-label="Main">
        <?php foreach (nav_items() as $group): ?>
            <?php
            $visible = [];
            foreach ($group['items'] as $item) {
                $key = $item['key'] ?? '';
                // farm_map has no permissions entry yet — treat like fields view (see FRONTEND_PENDING.md)
                if ($key === 'farm_map') {
                    if (!fe_can('fields', 'view') && !fe_can('farm_map', 'view')) {
                        continue;
                    }
                } elseif (!can_see_nav($item)) {
                    continue;
                }
                $visible[] = $item;
            }
            if (!$visible) {
                continue;
            }
            $groupId = $group['label'];
            $groupOpen = false;
            foreach ($visible as $item) {
                if (is_active_path($item['href'])) {
                    $groupOpen = true;
                    break;
                }
            }
            ?>
            <details class="nav-group" data-nav-group="<?= e($groupId) ?>"<?= $groupOpen ? ' open' : '' ?>>
                <summary class="nav-group-summary">
                    <span><?= e($group['label']) ?></span>
                    <i data-lucide="chevron-down" class="nav-caret" aria-hidden="true"></i>
                </summary>
                <div class="nav-sub">
                    <?php foreach ($visible as $item): ?>
                        <div class="nav-link-row">
                            <a class="<?= is_active_path($item['href']) ? 'active' : '' ?>"
                               href="<?= e(url($item['href'])) ?>"
                               data-nav-key="<?= e($item['key']) ?>">
                                <i data-lucide="<?= e($item['icon']) ?>" aria-hidden="true"></i>
                                <span><?= e($item['label']) ?></span>
                                <?php if (($item['href'] ?? '') === 'notifications' && $unread): ?>
                                    <em class="nav-badge"><?= (int) $unread ?></em>
                                <?php endif; ?>
                            </a>
                            <button type="button" class="nav-pin" data-nav-pin="<?= e($item['key']) ?>" title="Pin to favourites" aria-label="Pin <?= e($item['label']) ?> to favourites">
                                <i data-lucide="star" aria-hidden="true"></i>
                            </button>
                        </div>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endforeach; ?>
    </nav>
    <a class="side-user" href="<?= e(url('profile')) ?>">
        <span class="avatar"><?= e(user_initials($user)) ?></span>
        <span>
            <strong><?= e($user['name']) ?></strong>
            <small><?= e(fe_roles()[$user['role']] ?? $user['role']) ?></small>
        </span>
    </a>
</aside>
<div class="app-main">
    <header class="topbar">
        <div class="topbar-title">
            <?php if (!$isDashboard): ?>
                <h1><?= e($title ?? 'Dashboard') ?></h1>
            <?php endif; ?>
        </div>
        <form class="top-search" method="get" action="<?= e(url('search')) ?>" role="search">
            <i data-lucide="search" aria-hidden="true"></i>
            <label class="sr-only" for="top-search">Search farms, fields, crops, or tasks</label>
            <input id="top-search" type="search" name="q" placeholder="Search farms, fields, crops, or tasks..." value="<?= e((string) input('q')) ?>" minlength="2" autocomplete="off">
        </form>
        <div class="top-actions">
            <a class="icon-btn" href="<?= e(url('notifications')) ?>" title="Alerts" aria-label="<?= $unread ? $unread . ' unread alerts' : 'Alerts' ?>">
                <i data-lucide="bell" aria-hidden="true"></i>
                <?php if ($unread): ?><em><?= (int) $unread ?></em><?php endif; ?>
            </a>
            <div class="account-menu">
                <button type="button" class="account-chip" data-account-toggle aria-haspopup="true" aria-expanded="false" aria-label="Account menu">
                    <span class="avatar"><?= e(user_initials($user)) ?></span>
                    <span class="account-name"><?= e($user['name']) ?></span>
                    <i data-lucide="chevron-down" aria-hidden="true"></i>
                </button>
                <div class="account-drop" hidden>
                    <div class="account-card">
                        <span class="avatar"><?= e(user_initials($user)) ?></span>
                        <div>
                            <strong><?= e($user['name']) ?></strong>
                            <small><?= e(fe_roles()[$user['role']] ?? $user['role']) ?></small>
                            <em><?= e($activeFarm['name'] ?? (count($farmChoices) ? 'No farm selected' : 'No farm assigned')) ?></em>
                        </div>
                    </div>
                    <a href="<?= e(url('profile')) ?>">My Profile</a>
                    <?php if ($farmChoices && (count($farmChoices) > 1 || fe_is_admin())): ?>
                    <form class="account-farm" method="post" action="<?= e(url('switch-farm')) ?>">
                        <?= fe_csrf_field() ?>
                        <input type="hidden" name="return" value="<?= e(ltrim(current_path(), '/')) ?>">
                        <label class="sr-only" for="farm-switcher">Current farm</label>
                        <select id="farm-switcher" name="farm_id" onchange="this.form.submit()">
                            <?php if (fe_is_admin()): ?><option value="0" <?= !fe_farm_id() ? 'selected' : '' ?>>All farms</option><?php endif; ?>
                            <?php foreach ($farmChoices as $choice): ?>
                                <option value="<?= e($choice['id']) ?>" <?= (int)($activeFarm['id'] ?? 0) === (int)$choice['id'] ? 'selected' : '' ?>><?= e($choice['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                    <?php endif; ?>
                    <a href="<?= e(url('logout')) ?>">Sign out</a>
                </div>
            </div>
        </div>
    </header>
    <div class="page">
        <?php if ($employeeCreds):
            $credMailto = (string) ($employeeCreds['mailto'] ?? '');
            $credMailOk = !empty($employeeCreds['mail_ok']);
            $credMailErr = trim((string) ($employeeCreds['mail_error'] ?? ''));
            if ($credMailto === '' && !empty($employeeCreds['email']) && !empty($employeeCreds['temp_password'])) {
                $credSubject = 'Your FarmWise login details';
                $credBody = "Hello " . ($employeeCreds['name'] ?? '') . ",\n\n"
                    . "=== YOUR LOGIN DETAILS ===\n"
                    . "Login page: " . absolute_url('login') . "\n"
                    . "Email: " . ($employeeCreds['email'] ?? '') . "\n"
                    . "Username: " . ($employeeCreds['username'] ?? '') . "\n"
                    . "Temporary password: " . ($employeeCreds['temp_password'] ?? '') . "\n"
                    . "==========================\n\n"
                    . "Change this password on first sign-in.\n\n— FarmWise";
                $credMailto = 'mailto:' . rawurlencode((string) $employeeCreds['email'])
                    . '?subject=' . rawurlencode($credSubject)
                    . '&body=' . rawurlencode($credBody);
            }
        ?>
            <div class="flash flash-success creds-flash" role="status">
                <strong>Employee login details ready — send them now</strong>
                <p>
                    <?php if ($credMailOk): ?>
                        Automatic email was sent to <?= e($employeeCreds['email'] ?? '') ?>.
                    <?php else: ?>
                        Automatic Nexus email is blocked on this network<?= $credMailErr !== '' ? ': ' . e($credMailErr) : '' ?>.
                        Click <strong>Open email app &amp; send details</strong> — Outlook/Gmail opens with the full login message ready (email, username, temporary password).
                    <?php endif; ?>
                </p>
                <ul class="creds-list">
                    <li><span>Username</span> <code data-copy-text="<?= e($employeeCreds['username'] ?? '') ?>"><?= e($employeeCreds['username'] ?? '') ?></code></li>
                    <li><span>Email</span> <code data-copy-text="<?= e($employeeCreds['email'] ?? '') ?>"><?= e($employeeCreds['email'] ?? '') ?></code></li>
                    <li><span>Temporary password</span> <code data-copy-text="<?= e($employeeCreds['temp_password'] ?? '') ?>"><?= e($employeeCreds['temp_password'] ?? '') ?></code></li>
                </ul>
                <div class="creds-actions">
                    <?php if ($credMailto !== ''): ?>
                        <a class="btn btn-gold" href="<?= e($credMailto) ?>">Open email app &amp; send details</a>
                    <?php endif; ?>
                    <button type="button" class="btn btn-ghost" data-copy-text="<?= e("Email: " . ($employeeCreds['email'] ?? '') . "\nUsername: " . ($employeeCreds['username'] ?? '') . "\nTemporary password: " . ($employeeCreds['temp_password'] ?? '') . "\nLogin: " . absolute_url('login')) ?>">Copy all details</button>
                </div>
                <p class="muted">This temporary password is shown once. They must change it on first sign-in.</p>
            </div>
        <?php endif; ?>
        <?php foreach (flashes() as $type => $message): ?>
            <div class="alert <?= $type === 'success' ? 'ok' : ($type === 'error' ? 'danger' : 'info') ?>" data-flash-toast hidden><?= e(user_safe_message((string) $message, $type === 'success' ? 'Form submitted successfully!' : 'Something went wrong. Please try again later.')) ?></div>
        <?php endforeach; ?>
        <?= $content ?? '' ?>
    </div>
</div>
<?php
$assistantMode = 'auth';
require dirname(__DIR__) . '/partials/assistant.php';
?>
<script>window.IFMS_BASE = <?= json_encode(rtrim(url(''), '/')) ?>;</script>
<script src="<?= e(url('frontend/config/api.js')) ?>?v=<?= e($assetV) ?>"></script>
<script src="<?= e(asset('vendor/lucide/lucide.min.js')) ?>?v=<?= e($assetV) ?>"></script>
<?php if ($needsChart): ?>
<script src="<?= e(asset('vendor/chart.js/chart.umd.min.js')) ?>?v=<?= e($assetV) ?>"></script>
<?php endif; ?>
<?php if ($needsMap): ?>
<script src="<?= e(asset('vendor/leaflet/leaflet.js')) ?>?v=<?= e($assetV) ?>"></script>
<script src="<?= e(asset('vendor/leaflet-draw/leaflet.draw.js')) ?>?v=<?= e($assetV) ?>"></script>
<?php endif; ?>
<script src="<?= e(asset('js/workflow.js')) ?>?v=<?= e($assetV) ?>"></script>
<script src="<?= e(asset('js/ui.js')) ?>?v=<?= e($assetV) ?>"></script>
<script src="<?= e(asset('js/farm-map.js')) ?>?v=<?= e($assetV) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>?v=<?= e($assetV) ?>"></script>
<script src="<?= e(asset('js/assistant.js')) ?>?v=<?= e($assetV) ?>"></script>
</body>
</html>

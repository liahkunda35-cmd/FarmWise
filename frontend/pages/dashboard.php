<?php
$role = fe_role();
$hasFarm = (bool) fe_farm_id() || fe_is_admin();
$user = fe_user();
$farm = is_array($farm ?? null) ? $farm : [];
$weather = is_array($weather ?? null) ? $weather : [];
$currentWx = is_array($currentWx ?? null) ? $currentWx : [];
$daily = is_array($daily ?? null) ? $daily : [];
$healthDays = (int) ($healthDays ?? 7);
$wxCondition = $currentWx['weather_description']
    ?? (isset($currentWx['weather_code']) ? WeatherService::codeLabel($currentWx['weather_code']) : null)
    ?? ($weather['conditions'] ?? '');
$tempNow = $currentWx['temperature_2m'] ?? ($weather['temperature'] ?? null);
$humidity = $currentWx['relative_humidity_2m'] ?? ($weather['humidity'] ?? null);
$wind = $currentWx['wind_speed_10m'] ?? ($weather['wind_speed'] ?? null);
$wxMeta = is_array($wxMeta ?? null) ? $wxMeta : [];
$nextRain = null;
if (!empty($daily['precipitation_sum']) && !empty($daily['time'])) {
    foreach ($daily['precipitation_sum'] as $i => $mm) {
        if ((float) $mm > 0.4) {
            $nextRain = $i === 0 ? 'Today' : ($i === 1 ? 'Tomorrow' : $i . ' days');
            break;
        }
    }
}
$farmPhoto = $farmPhoto ?: asset('img/farm-overview.jpg');
$promoPhoto = asset('img/promo-seedling.jpg');
$focus = is_array($focus ?? null) ? $focus : [];
$dashCards = is_array($focus['cards'] ?? null) ? $focus['cards'] : [];
$showWeather = array_key_exists('show_weather', $focus) ? (bool) $focus['show_weather'] : fe_can('weather', 'view');
$showCrops = array_key_exists('show_crops', $focus) ? (bool) $focus['show_crops'] : (fe_can('plantings', 'view') || fe_can('crop_growth', 'view'));
$showMap = array_key_exists('show_map', $focus) ? (bool) $focus['show_map'] : fe_can('fields', 'view');
$pendingApprovals = is_array($pendingApprovals ?? null) ? $pendingApprovals : [];
?>
<?php if (!$hasFarm && !fe_is_admin()): ?>
    <section class="panel dash-empty">
        <header><h3>No farm assigned</h3></header>
        <?php if ($role === 'owner'): ?>
            <p><a class="btn btn-gold" href="<?= e(url('module/farms/create')) ?>">Create your farm profile</a></p>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php if (farm_code_feature_enabled() && !empty($farm['join_code']) && ($role === 'owner' || (fe_is_admin() && fe_farm_id()))): ?>
    <section class="panel">
        <header><h3>Farm Code</h3></header>
        <p><strong data-copy-text="<?= e($farm['join_code']) ?>"><?= e($farm['join_code']) ?></strong>
            <button type="button" class="btn btn-ghost btn-sm" data-copy>Copy</button></p>
    </section>
<?php endif; ?>

<?php if ($pendingApprovals && fe_can('users', 'edit')): ?>
<section class="panel pending-approvals">
    <header class="panel-tools">
        <h3>Pending account approvals <span class="badge badge-warn"><?= count($pendingApprovals) ?></span></h3>
        <a class="panel-link" href="<?= e(url('users')) ?>?status=pending">Manage users →</a>
    </header>
    <div class="pending-approvals-list">
        <?php foreach ($pendingApprovals as $pending):
            $reqRole = (string) ($pending['requested_role'] ?: $pending['role'] ?: '');
            $farmId = (int) ($pending['farm_id'] ?? 0);
            ?>
            <article class="pending-approval-card">
                <div class="pending-approval-meta">
                    <strong><?= e($pending['name'] ?? 'Applicant') ?></strong>
                    <span>@<?= e(($pending['username'] ?? '') !== '' ? $pending['username'] : '—') ?> · <?= e($pending['email'] ?? '') ?></span>
                    <span>
                        Role: <?= e(fe_roles()[$reqRole] ?? ($reqRole ?: '—')) ?>
                        · Registered <?= e(dater($pending['created_at'] ?? null, 'd M Y H:i')) ?>
                    </span>
                </div>
                <div class="user-actions">
                    <form method="post" action="<?= e(url('users/pending/' . (int) $pending['id'] . '/approve')) ?>">
                        <?= fe_csrf_field() ?>
                        <input type="hidden" name="redirect_to" value="dashboard">
                        <input type="hidden" name="role" value="<?= e($reqRole) ?>">
                        <?php if ($farmId > 0): ?>
                            <input type="hidden" name="farm_ids[]" value="<?= $farmId ?>">
                            <input type="hidden" name="farm_id" value="<?= $farmId ?>">
                        <?php endif; ?>
                        <button type="submit" class="btn btn-approve btn-sm">Approve</button>
                    </form>
                    <a class="btn btn-ghost btn-sm" href="<?= e(url('users/pending/' . (int) $pending['id'])) ?>">Review</a>
                    <form method="post" action="<?= e(url('users/pending/' . (int) $pending['id'] . '/reject')) ?>" data-confirm="Reject registration for <?= e($pending['name'] ?? 'this user') ?>?">
                        <?= fe_csrf_field() ?>
                        <input type="hidden" name="redirect_to" value="dashboard">
                        <button type="submit" class="btn btn-delete btn-sm">Reject</button>
                    </form>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<div class="dash-top">
    <section class="dash-banner">
        <div class="dash-banner-copy">
            <p class="dash-welcome greet-line" data-greet-name="<?= e(user_first_name($user)) ?>" data-greet-mode="welcome">
                <span class="greet-time">Welcome back</span><span class="greet-name">, <?= e(user_first_name($user)) ?></span>
            </p>
            <h2 class="dash-headline"><span><?= e($focus['line1'] ?? 'Healthy Crops,') ?></span><br><?= e($focus['line2'] ?? 'Greater Harvests') ?></h2>
            <p class="dash-banner-lede"><?= e($focus['lede'] ?? 'Track, manage and grow your farm with smart insights and real-time data.') ?></p>
            <a class="btn dash-banner-btn" href="<?= e($focus['banner_href'] ?? ($showMap ? url('map') : url('dashboard'))) ?>"><?= e($focus['banner_label'] ?? ($showMap ? 'Explore your farm →' : 'Continue →')) ?></a>
        </div>
    </section>

    <?php if ($showWeather): ?>
    <section class="dash-wx" aria-label="Current weather">
        <div class="dash-wx-now">
            <div>
                <b><?= $tempNow !== null && $tempNow !== '' ? e(round((float) $tempNow)) . '°C' : '—' ?></b>
                <span><?= e($wxCondition ?: (!empty($wxMeta['message']) ? 'Weather unavailable' : 'Set farm location')) ?></span>
                <small><i data-lucide="map-pin"></i> <?= e(($farm['location'] ?? '') ?: ($farm['name'] ?? 'Location not set')) ?></small>
            </div>
            <i data-lucide="<?= e(weather_icon($wxCondition)) ?>"></i>
        </div>
        <div class="dash-wx-meta">
            <div><i data-lucide="droplets"></i><span>Humidity</span><strong><?= $humidity !== null && $humidity !== '' ? e(round((float) $humidity)) . '%' : '—' ?></strong></div>
            <div><i data-lucide="wind"></i><span>Wind</span><strong><?= $wind !== null && $wind !== '' ? e(round((float) $wind)) . ' km/h' : '—' ?></strong></div>
            <div><i data-lucide="cloud-rain"></i><span>Next rain</span><strong><?= e($nextRain ?: '—') ?></strong></div>
        </div>
        <?php if (!empty($wxMeta['message']) && ($tempNow === null || $tempNow === '')): ?>
            <p class="dash-wx-note muted"><?= e($wxMeta['message']) ?></p>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</div>

<div class="dash-pills">
    <?php foreach ($dashCards as $card): ?>
    <a class="dash-pill" href="<?= e($card['href'] ?? url('dashboard')) ?>">
        <span class="dash-ico"><i data-lucide="<?= e($card['icon'] ?? 'activity') ?>"></i></span>
        <span>
            <em><?= e($card['label'] ?? '') ?></em>
            <b><?= e((string) ($card['value'] ?? '')) ?></b>
        </span>
        <i data-lucide="arrow-right"></i>
    </a>
    <?php endforeach; ?>
</div>

<div class="dash-lower">
    <section class="panel">
        <header>
            <h3>Recent Activity</h3>
            <a class="panel-link" href="<?= e(fe_can('audit_logs', 'view') ? url('audit') : (fe_can('tasks', 'view') ? url('module/tasks') : url('notifications'))) ?>">View all →</a>
        </header>
        <ul class="activity-list">
            <?php foreach ($activity as $row):
                $icon = match ($row['module'] ?? '') {
                    'plantings', 'crop_growth', 'harvests' => 'leaf',
                    'tasks' => 'clipboard-check',
                    'soil_records' => 'layers',
                    'crop_rotations' => 'refresh-cw',
                    'weather', 'weather_logs' => 'cloud-sun',
                    'animals', 'livestock_health' => 'paw-print',
                    'backups', 'system' => 'hard-drive',
                    default => 'activity',
                };
                $action = strtolower((string) ($row['action'] ?? ''));
                $badge = match (true) {
                    in_array($action, ['login', 'api_login'], true) => ['Success', 'ok'],
                    $action === 'create' => ['Added', 'ok'],
                    $action === 'update' => ['Updated', 'info'],
                    in_array($action, ['backup', 'restore'], true) => ['Updated', 'info'],
                    default => [ucfirst($action ?: 'Info'), 'info'],
                };
                $href = record_screen_url($row['module'] ?? '', $row['record_id'] ?? null, $row['action'] ?? null, $row['details'] ?? null);
                ?>
                <li>
                    <a class="activity-row" href="<?= e($href) ?>">
                        <span class="dash-ico sm"><i data-lucide="<?= e($icon) ?>"></i></span>
                        <div>
                            <strong><?= e($row['details'] ?: ucfirst($action) . ' ' . str_replace('_', ' ', (string) ($row['module'] ?? ''))) ?></strong>
                            <span><?= e($row['user_name'] ?: 'System') ?> · <?= e(relative_time($row['created_at'])) ?></span>
                        </div>
                        <em class="act-badge act-<?= e($badge[1]) ?>"><?= e($badge[0]) ?></em>
                    </a>
                </li>
            <?php endforeach; ?>
            <?php if (!$activity): ?><li class="muted">No recent activity to show.</li><?php endif; ?>
        </ul>
    </section>

    <?php if ($showCrops): ?>
    <section class="panel">
        <header>
            <h3>Crop Performance</h3>
            <a class="panel-link" href="<?= e($health['href'] ?? url('module/plantings')) ?>">View all →</a>
        </header>
        <div class="chart-wrap">
            <canvas id="healthChart" role="img" aria-label="Crop performance"></canvas>
        </div>
        <?php if (!empty($health['note'])): ?>
            <p class="muted"><?= e($health['note']) ?></p>
        <?php endif; ?>
        <?php if (!empty($health['insufficient']) || empty($health['series'])): ?>
            <p class="muted"><a href="<?= e(url('module/crop_growth')) ?>">Log crop growth health status to unlock a data-driven trend. →</a></p>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <div class="dash-stack">
        <section class="panel alerts-card">
            <header>
                <h3>Alerts & Notifications</h3>
                <a class="panel-link" href="<?= e(url('notifications')) ?>">View all →</a>
            </header>
            <ul class="alert-list">
                <?php foreach ($alerts as $a):
                    $tone = in_array($a['severity'] ?? '', ['danger', 'critical', 'high'], true) ? 'high' : (in_array($a['severity'] ?? '', ['warning', 'warn', 'medium'], true) ? 'medium' : 'info');
                    $isRegistration = ($a['category'] ?? '') === 'registration';
                    $icon = $isRegistration ? 'user-check' : (($a['category'] ?? '') === 'irrigation' ? 'droplets' : (($a['category'] ?? '') === 'disease' || ($a['category'] ?? '') === 'pest' ? 'bug' : 'bell'));
                    ?>
                    <li class="<?= $isRegistration ? 'alert-registration' : '' ?>">
                        <a href="<?= e(url($a['link'] ?: 'notifications')) ?>">
                            <span class="dash-ico sm tone-<?= e($tone) ?>"><i data-lucide="<?= e($icon) ?>"></i></span>
                            <div>
                                <strong><?= e($a['title']) ?></strong>
                                <?php if (!empty($a['message'])): ?><span class="alert-msg"><?= e($a['message']) ?></span><?php endif; ?>
                                <span><?= e(relative_time($a['created_at'])) ?><?= $isRegistration ? ' · Pending approval' : '' ?></span>
                            </div>
                        </a>
                    </li>
                <?php endforeach; ?>
                <?php if (!$alerts): ?><li class="muted">No alerts right now.</li><?php endif; ?>
            </ul>
        </section>

        <article class="promo-mini">
            <div>
                <h3>Good things grow with care.</h3>
                <p>Your hard work today builds a better tomorrow.</p>
                <a class="btn dash-banner-btn" href="<?= e($showMap ? url('map') : ($focus['banner_href'] ?? url('dashboard'))) ?>"><?= e($showMap ? 'View Farm Map →' : ($focus['banner_label'] ?? 'Continue →')) ?></a>
            </div>
            <img src="<?= e($promoPhoto) ?>" alt="Young crop seedling">
        </article>

        <section class="panel quick-actions">
            <header><h3>Quick Actions</h3></header>
            <div class="quick-grid">
                <?php if (fe_can('plantings', 'view')): ?>
                <a href="<?= e(url('module/plantings')) ?>"><i data-lucide="leaf"></i> Plantings</a>
                <?php endif; ?>
                <?php if (fe_can('soil_records', 'view')): ?>
                <a href="<?= e(url('module/soil_records')) ?>"><i data-lucide="layers"></i> Soil records</a>
                <?php elseif (fe_can('tasks', 'view')): ?>
                <a href="<?= e(url('module/tasks')) ?>"><i data-lucide="clipboard-list"></i> My Tasks</a>
                <?php endif; ?>
                <?php if (fe_can('weather', 'view')): ?>
                <a href="<?= e(url('weather')) ?>"><i data-lucide="cloud-sun"></i> Check Weather</a>
                <?php endif; ?>
                <?php if (fe_can('calendar', 'view')): ?>
                <a href="<?= e(url('calendar')) ?>"><i data-lucide="calendar-days"></i> View Calendar</a>
                <?php endif; ?>
                <?php if (fe_can('pest_scouting', 'view')): ?>
                <a href="<?= e(url('module/pest_scouting')) ?>"><i data-lucide="bug"></i> Scouting</a>
                <?php endif; ?>
                <?php if (fe_can('invoices', 'view') && !fe_can('plantings', 'view')): ?>
                <a href="<?= e(url('module/invoices')) ?>"><i data-lucide="file-text"></i> Invoices</a>
                <?php endif; ?>
                <?php if (fe_can('reports', 'view') && fe_can('budgets', 'view')): ?>
                <a href="<?= e(url('reports?type=budget')) ?>"><i data-lucide="scale"></i> Budget vs Actual</a>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>

<script>
window.IFMS_HEALTH = <?= json_encode($health) ?>;
</script>

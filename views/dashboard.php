<?php
$role = Auth::role();
$hasFarm = (bool) Auth::farmId() || Auth::isAdmin();
$user = Auth::user();
$farm = is_array($farm ?? null) ? $farm : [];
$weather = is_array($weather ?? null) ? $weather : [];
$currentWx = is_array($currentWx ?? null) ? $currentWx : [];
$daily = is_array($daily ?? null) ? $daily : [];
$healthDays = (int) ($healthDays ?? 7);
$wxCondition = isset($currentWx['weather_code']) ? WeatherService::codeLabel($currentWx['weather_code']) : ($weather['conditions'] ?? '');
$tempNow = $currentWx['temperature_2m'] ?? ($weather['temperature'] ?? null);
$humidity = $currentWx['relative_humidity_2m'] ?? ($weather['humidity'] ?? null);
$wind = $currentWx['wind_speed_10m'] ?? ($weather['wind_speed'] ?? null);
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
?>
<?php if (!$hasFarm && !Auth::isAdmin()): ?>
    <section class="panel dash-empty">
        <header><h3>No farm assigned</h3></header>
        <p>Your account does not yet have a farm workspace, so crops, livestock, tasks and reports have nothing to show.</p>
        <?php if (in_array($role, ['manager', 'worker', 'agronomist', 'accountant'], true)): ?>
            <form class="search-form" method="post" action="<?= e(url('join-farm')) ?>">
                <?= Csrf::field() ?>
                <input name="join_code" placeholder="Farm join code" required>
                <button class="btn btn-gold" type="submit">Join farm</button>
            </form>
        <?php elseif ($role === 'owner'): ?>
            <p><a class="btn btn-gold" href="<?= e(url('module/farms/create')) ?>">Create your farm</a></p>
        <?php endif; ?>
    </section>
<?php endif; ?>

<div class="dash-top">
    <section class="dash-banner">
        <div class="dash-banner-copy">
            <p class="dash-welcome greet-line" data-greet-name="<?= e(user_first_name($user)) ?>" data-greet-mode="welcome">
                <span class="greet-time">Welcome back</span><span class="greet-name">, <?= e(user_first_name($user)) ?></span>
            </p>
            <h2 class="dash-headline"><span>Healthy Crops,</span><br>Greater Harvests</h2>
            <p class="dash-banner-lede">Track, manage and grow your farm with smart insights and real-time data.</p>
            <a class="btn dash-banner-btn" href="<?= e(url('map')) ?>">Explore your farm →</a>
        </div>
        <div class="dash-banner-photo">
            <img src="<?= e(asset('img/farm-overview.jpg')) ?>" alt="Farm landscape">
        </div>
    </section>

    <section class="dash-wx">
        <div class="dash-wx-now">
            <div>
                <b><?= $tempNow !== null && $tempNow !== '' ? e(round((float) $tempNow)) . '°C' : '—' ?></b>
                <span><?= e($wxCondition ?: 'Set farm location') ?></span>
                <small><i data-lucide="map-pin"></i> <?= e(($farm['location'] ?? '') ?: ($farm['name'] ?? 'Location not set')) ?></small>
            </div>
            <i data-lucide="<?= e(weather_icon($wxCondition)) ?>"></i>
        </div>
        <div class="dash-wx-meta">
            <div><i data-lucide="droplets"></i><span>Humidity</span><strong><?= $humidity !== null && $humidity !== '' ? e(round((float) $humidity)) . '%' : '—' ?></strong></div>
            <div><i data-lucide="wind"></i><span>Wind</span><strong><?= $wind !== null && $wind !== '' ? e(round((float) $wind)) . ' km/h' : '—' ?></strong></div>
            <div><i data-lucide="cloud-rain"></i><span>Next rain</span><strong><?= e($nextRain ?: '—') ?></strong></div>
        </div>
    </section>
</div>

<div class="dash-pills">
    <a class="dash-pill" href="<?= e(url('module/farms')) ?>">
        <span class="dash-ico"><i data-lucide="land-plot"></i></span>
        <span>
            <em>Total Farms</em>
            <b><?= (int) $stats['farms'] ?></b>
        </span>
        <i data-lucide="arrow-right"></i>
    </a>
    <a class="dash-pill" href="<?= e(url('module/fields')) ?>">
        <span class="dash-ico"><i data-lucide="sprout"></i></span>
        <span>
            <em>Total Fields</em>
            <b><?= (int) $stats['fields'] ?></b>
        </span>
        <i data-lucide="arrow-right"></i>
    </a>
    <a class="dash-pill" href="<?= e(url('module/plantings')) ?>">
        <span class="dash-ico"><i data-lucide="leaf"></i></span>
        <span>
            <em>Active Crops</em>
            <b><?= (int) $stats['active_crops'] ?></b>
        </span>
        <i data-lucide="arrow-right"></i>
    </a>
    <a class="dash-pill" href="<?= e(url('module/tasks?status=pending')) ?>">
        <span class="dash-ico"><i data-lucide="calendar-days"></i></span>
        <span>
            <em>Upcoming Tasks</em>
            <b><?= (int) $stats['pending_tasks'] ?></b>
        </span>
        <i data-lucide="arrow-right"></i>
    </a>
</div>

<div class="dash-lower">
    <section class="panel">
        <header>
            <h3>Recent Activity</h3>
            <a class="panel-link" href="<?= e(Auth::can('audit_logs', 'view') ? url('audit') : url('module/tasks')) ?>">View all →</a>
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

    <section class="panel">
        <header>
            <h3>Crop Performance</h3>
            <a class="panel-link" href="<?= e($health['href'] ?? url('module/plantings')) ?>">View all →</a>
        </header>
        <div class="chart-wrap">
            <canvas id="healthChart" role="img" aria-label="Crop performance"></canvas>
        </div>
        <?php if (empty($health['series'][0]['data'])): ?>
            <p class="muted"><a href="<?= e(url('module/crop_growth')) ?>">Crop observations will appear here as growth records are saved. →</a></p>
        <?php endif; ?>
    </section>

    <div class="dash-stack">
        <section class="panel alerts-card">
            <header>
                <h3>Alerts & Notifications</h3>
                <a class="panel-link" href="<?= e(url('notifications')) ?>">View all →</a>
            </header>
            <ul class="alert-list">
                <?php foreach ($alerts as $a):
                    $tone = in_array($a['severity'] ?? '', ['danger', 'critical', 'high'], true) ? 'high' : (in_array($a['severity'] ?? '', ['warning', 'warn', 'medium'], true) ? 'medium' : 'info');
                    ?>
                    <li>
                        <a href="<?= e(url($a['link'] ?: 'notifications')) ?>">
                            <span class="dash-ico sm tone-<?= e($tone) ?>"><i data-lucide="<?= e(($a['category'] ?? '') === 'irrigation' ? 'droplets' : (($a['category'] ?? '') === 'disease' || ($a['category'] ?? '') === 'pest' ? 'bug' : 'bell')) ?>"></i></span>
                            <div>
                                <strong><?= e($a['title']) ?></strong>
                                <span><?= e(relative_time($a['created_at'])) ?></span>
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
                <a class="btn dash-banner-btn" href="<?= e(url('map')) ?>">View Farm Map →</a>
            </div>
            <img src="<?= e($promoPhoto) ?>" alt="Young crop seedling">
        </article>

        <section class="panel quick-actions">
            <header><h3>Quick Actions</h3></header>
            <div class="quick-grid">
                <a href="<?= e(url('module/plantings/create')) ?>"><i data-lucide="plus"></i> Add Planting</a>
                <a href="<?= e(url('module/soil_records/create')) ?>"><i data-lucide="layers"></i> Record Soil Data</a>
                <a href="<?= e(url('weather')) ?>"><i data-lucide="cloud-sun"></i> Check Weather</a>
                <a href="<?= e(url('calendar')) ?>"><i data-lucide="calendar-days"></i> View Calendar</a>
            </div>
        </section>
    </div>
</div>

<script>
window.IFMS_HEALTH = <?= json_encode($health) ?>;
</script>

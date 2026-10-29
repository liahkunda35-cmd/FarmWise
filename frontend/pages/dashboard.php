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
?>
<?php if (!$hasFarm && !fe_is_admin()): ?>
    <section class="panel dash-empty">
        <header><h3>No farm assigned</h3></header>
        <p>Your account does not yet have a farm workspace, so crops, livestock, tasks and reports have nothing to show.</p>
        <?php if (in_array($role, ['manager', 'worker', 'agronomist', 'accountant'], true)): ?>
            <form class="search-form" method="post" action="<?= e(url('join-farm')) ?>" data-validate-form novalidate>
                <?= fe_csrf_field() ?>
                <input name="join_code" placeholder="Farm join code" required maxlength="20" data-validate="required" autocomplete="off">
                <span class="field-msg" data-field-error="join_code" hidden></span>
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
            <h2 class="dash-headline"><span><?= e($focus['line1'] ?? 'Healthy Crops,') ?></span><br><?= e($focus['line2'] ?? 'Greater Harvests') ?></h2>
            <p class="dash-banner-lede"><?= e($focus['lede'] ?? 'Track, manage and grow your farm with smart insights and real-time data.') ?></p>
            <a class="btn dash-banner-btn" href="<?= e($focus['banner_href'] ?? ($showMap ? url('map') : url('dashboard'))) ?>"><?= e($focus['banner_label'] ?? ($showMap ? 'Explore your farm →' : 'Continue →')) ?></a>
        </div>
        <div class="dash-banner-photo">
            <img src="<?= e(asset('img/farm-overview.jpg')) ?>" alt="Farm landscape">
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
                <a class="btn dash-banner-btn" href="<?= e($showMap ? url('map') : ($focus['banner_href'] ?? url('dashboard'))) ?>"><?= e($showMap ? 'View Farm Map →' : ($focus['banner_label'] ?? 'Continue →')) ?></a>
            </div>
            <img src="<?= e($promoPhoto) ?>" alt="Young crop seedling">
        </article>

        <section class="panel quick-actions">
            <header><h3>Quick Actions</h3></header>
            <div class="quick-grid">
                <?php if (fe_can('plantings', 'create')): ?>
                <a href="<?= e(url('module/plantings/create')) ?>"><i data-lucide="plus"></i> Add Planting</a>
                <?php elseif (fe_can('plantings', 'view')): ?>
                <a href="<?= e(url('module/plantings')) ?>"><i data-lucide="leaf"></i> View Plantings</a>
                <?php endif; ?>
                <?php if (fe_can('soil_records', 'create')): ?>
                <a href="<?= e(url('module/soil_records/create')) ?>"><i data-lucide="layers"></i> Record Soil Data</a>
                <?php elseif (fe_can('tasks', 'view')): ?>
                <a href="<?= e(url('module/tasks')) ?>"><i data-lucide="clipboard-list"></i> My Tasks</a>
                <?php endif; ?>
                <?php if (fe_can('weather', 'view')): ?>
                <a href="<?= e(url('weather')) ?>"><i data-lucide="cloud-sun"></i> Check Weather</a>
                <?php endif; ?>
                <?php if (fe_can('calendar', 'view')): ?>
                <a href="<?= e(url('calendar')) ?>"><i data-lucide="calendar-days"></i> View Calendar</a>
                <?php endif; ?>
                <?php if (fe_can('pest_scouting', 'create')): ?>
                <a href="<?= e(url('module/pest_scouting/create')) ?>"><i data-lucide="bug"></i> Record Scouting</a>
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

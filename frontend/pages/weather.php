<?php
$current = $forecast['current'] ?? null;
$daily = $forecast['daily'] ?? null;
$meta = is_array($forecast['meta'] ?? null) ? $forecast['meta'] : [];
$hasLive = is_array($current) && isset($current['temperature_2m']);
$statusMsg = $meta['message'] ?? null;
$statusOk = !empty($meta['ok']);
$statusError = $meta['error'] ?? null;
$condition = $current['weather_description']
    ?? (isset($current['weather_code']) ? WeatherService::codeLabel($current['weather_code']) : null);
?>
<div class="weather-page" data-weather-page>
    <?php if (!$farm): ?>
        <div class="alert danger weather-status" role="status">Select or join a farm to view location-based weather.</div>
    <?php elseif ($statusMsg): ?>
        <div class="alert <?= $hasLive ? 'ok' : 'danger' ?> weather-status" role="status">
            <?= e($statusMsg) ?>
            <?php if ($statusError === 'missing_location' && fe_can('farms', 'edit')): ?>
                <a href="<?= e(url('module/farms')) ?>">Open Farms →</a>
            <?php endif; ?>
            <?php if (!empty($meta['stale']) && $hasLive): ?>
                <span class="muted"> Showing the last saved reading.</span>
            <?php endif; ?>
        </div>
    <?php elseif ($hasLive && !empty($meta['message'])): ?>
        <div class="alert ok weather-status" role="status"><?= e($meta['message']) ?></div>
    <?php elseif ($hasLive && !empty($meta['cached'])): ?>
        <div class="alert ok weather-status weather-status-quiet" role="status">Weather refreshed from cache (updates about every 30 minutes).</div>
    <?php endif; ?>

    <div class="kpi-grid weather-kpi" aria-live="polite">
        <article class="kpi">
            <span>Temperature</span>
            <b><?= $hasLive ? e($current['temperature_2m']) . '°C' : '—' ?></b>
            <?php if ($condition): ?><small class="muted"><?= e(ucfirst((string) $condition)) ?></small><?php endif; ?>
        </article>
        <article class="kpi">
            <span>Humidity</span>
            <b><?= $hasLive && isset($current['relative_humidity_2m']) ? e($current['relative_humidity_2m']) . '%' : '—' ?></b>
        </article>
        <article class="kpi">
            <span>Rainfall</span>
            <b><?= $hasLive && isset($current['precipitation']) ? e($current['precipitation']) . ' mm' : '—' ?></b>
            <small class="muted">Recent / current</small>
        </article>
        <article class="kpi">
            <span>Wind</span>
            <b><?= $hasLive && isset($current['wind_speed_10m']) ? e($current['wind_speed_10m']) . ' km/h' : '—' ?></b>
        </article>
    </div>

    <div class="split-2 weather-split">
        <section class="panel">
            <header><h3>Forecast</h3></header>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Date</th><th>Max</th><th>Min</th><th>Rain</th><th>Wind</th></tr></thead>
                    <tbody>
                    <?php if (!empty($daily['time'])): foreach ($daily['time'] as $i => $day): ?>
                        <tr>
                            <td><?= dater($day) ?></td>
                            <td><?= e($daily['temperature_2m_max'][$i] ?? '—') ?>°C</td>
                            <td><?= e($daily['temperature_2m_min'][$i] ?? '—') ?>°C</td>
                            <td><?= e($daily['precipitation_sum'][$i] ?? '—') ?> mm</td>
                            <td><?= e($daily['wind_speed_10m_max'][$i] ?? '—') ?> km/h</td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="5" class="muted">
                            <?php if (!$farm): ?>
                                No farm selected.
                            <?php elseif ($statusError === 'missing_location'): ?>
                                Set farm GPS coordinates to load the forecast.
                            <?php elseif ($statusError === 'missing_key'): ?>
                                Configure OPENWEATHER_API_KEY to load the forecast.
                            <?php else: ?>
                                Forecast unavailable right now.
                            <?php endif; ?>
                        </td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if (!empty($daily['time'])): ?>
                <div class="weather-chart-wrap">
                    <canvas id="forecastChart" height="120" aria-label="Forecast chart"></canvas>
                </div>
                <script>
                window.IFMS_WEATHER_CHART = {
                    labels: <?= json_encode($daily['time']) ?>,
                    max: <?= json_encode($daily['temperature_2m_max'] ?? []) ?>,
                    min: <?= json_encode($daily['temperature_2m_min'] ?? []) ?>,
                    rain: <?= json_encode($daily['precipitation_sum'] ?? []) ?>
                };
                </script>
            <?php endif; ?>
        </section>
        <div class="dash-stack">
            <section class="panel">
                <header><h3>Weather-based recommendations</h3></header>
                <ul class="feed">
                    <?php foreach ($tips as $tip): ?>
                        <li><strong><?= e($tip[0]) ?></strong><span><?= e($tip[1]) ?></span></li>
                    <?php endforeach; ?>
                    <?php if (!$tips): ?><li class="muted">Recommendations appear after a successful weather sync.</li><?php endif; ?>
                </ul>
            </section>
            <?php
            $irrigate = 'Skip';
            $irrigateWhy = 'No forecast loaded yet.';
            foreach ($tips as $tip) {
                if (stripos($tip[0], 'Irrigation') !== false) {
                    $irrigate = (stripos($tip[1], 'Delay') !== false || stripos($tip[1], 'Rain is expected') !== false) ? 'Skip' : 'Irrigate';
                    $irrigateWhy = $tip[1];
                    break;
                }
            }
            ?>
            <section class="panel irrigate-card">
                <header><h3>Irrigation guidance</h3></header>
                <p class="irrigate-verdict irrigate-<?= e(strtolower($irrigate)) ?>"><?= e($irrigate) ?></p>
                <p class="muted"><?= e($irrigateWhy) ?></p>
                <?php if (fe_can('irrigation_schedules', 'view')): ?>
                    <a class="btn btn-gold btn-sm" href="<?= e(url('module/irrigation_schedules')) ?>">Open irrigation schedules</a>
                <?php endif; ?>
            </section>
            <?php
            $alertLevel = null;
            if (!empty($forecast['current'])) {
                $wind = (float) ($forecast['current']['wind_speed_10m'] ?? 0);
                $rain = (float) ($forecast['current']['precipitation'] ?? 0);
                $temp = (float) ($forecast['current']['temperature_2m'] ?? 0);
                if ($wind >= 50 || $rain >= 20 || $temp >= 38 || $temp <= 0) {
                    $alertLevel = 'extreme';
                } elseif ($wind >= 35 || $rain >= 8 || $temp >= 34) {
                    $alertLevel = 'watch';
                }
            }
            ?>
            <?php if ($alertLevel): ?>
                <section class="panel alerts-card">
                    <header><h3>Extreme weather</h3></header>
                    <p><?= status_badge($alertLevel) ?> Current conditions may affect field work. Review alerts and delay spraying if winds are high.</p>
                    <a class="btn btn-ghost btn-sm" href="<?= e(url('notifications')) ?>">Open alerts</a>
                </section>
            <?php endif; ?>
        </div>
    </div>

    <section class="panel">
        <header><h3>Historical weather</h3></header>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Date</th><th>Temp</th><th>Humidity</th><th>Rain</th><th>Wind</th><th>Alert</th></tr></thead>
                <tbody>
                <?php if ($history): foreach ($history as $h): ?>
                    <tr>
                        <td><?= dater($h['log_date']) ?></td>
                        <td><?= e($h['temperature']) ?></td>
                        <td><?= e($h['humidity']) ?></td>
                        <td><?= e($h['rainfall']) ?></td>
                        <td><?= e($h['wind_speed']) ?></td>
                        <td><?= status_badge($h['alert_level']) ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="6" class="muted">No saved weather readings yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

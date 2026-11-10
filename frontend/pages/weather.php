<?php
$current = $forecast['current'] ?? null;
$daily = $forecast['daily'] ?? null;
$meta = is_array($forecast['meta'] ?? null) ? $forecast['meta'] : [];
$hasLive = is_array($current) && isset($current['temperature_2m']);
$statusMsg = $meta['message'] ?? null;
$statusError = $meta['error'] ?? null;
$condition = $current['weather_description']
    ?? (isset($current['weather_code']) ? WeatherService::codeLabel($current['weather_code']) : null);
$wxCode = $current['weather_code'] ?? null;
$farm = is_array($farm ?? null) ? $farm : null;
$locLabel = trim((string) ($meta['location_label'] ?? ''));
if ($locLabel === '' && $farm) {
    $locLabel = trim((string) (($farm['location_label'] ?? '') ?: ($farm['location'] ?? '') ?: ($farm['name'] ?? '')));
}
$tips = array_values(array_filter($tips ?? [], static function ($tip) {
    $title = strtolower((string) ($tip[0] ?? ''));
    return !str_contains($title, 'irrigat');
}));
$locateNeeded = !empty($locateNeeded) && !$hasLive;
?>
<div class="weather-page" data-weather-page<?= $locateNeeded ? ' data-weather-locate="1"' : '' ?>>
    <header class="list-hero weather-hero">
        <div class="list-hero-copy">
            <h3>Weather</h3>
            <?php if ($locLabel !== ''): ?>
                <p class="muted"><?= e($locLabel) ?></p>
            <?php elseif ($locateNeeded): ?>
                <p class="muted">Using your device location</p>
            <?php else: ?>
                <p class="muted">Local weather</p>
            <?php endif; ?>
        </div>
        <?php if (!$hasLive): ?>
            <div class="list-hero-actions">
                <button class="btn btn-gold btn-sm" type="button" data-weather-use-location>Use my location</button>
                <?php if (fe_can('farms', 'create') && !$farm): ?>
                    <a class="btn btn-ghost btn-sm" href="<?= e(url('module/farms/create')) ?>">+ Add Farm</a>
                <?php elseif ($farm && fe_can('farms', 'edit')): ?>
                    <a class="btn btn-ghost btn-sm" href="<?= e(url('module/farms/edit/' . (int) $farm['id'])) ?>">Set farm pin</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </header>

    <?php if ($locateNeeded): ?>
        <div class="alert ok weather-status" role="status" data-weather-locate-status>Getting your location to load temperature…</div>
    <?php elseif ($statusMsg && !$hasLive): ?>
        <div class="alert danger weather-status" role="status">
            <?= e($statusMsg) ?>
            <?php if ($statusError === 'missing_location' && fe_can('farms', 'edit') && $farm): ?>
                <a class="btn btn-gold btn-sm" href="<?= e(url('module/farms/edit/' . (int) $farm['id'])) ?>">Set farm location</a>
            <?php endif; ?>
        </div>
    <?php elseif ($hasLive && !empty($meta['cached'])): ?>
        <div class="alert ok weather-status weather-status-quiet" role="status">Updated recently · refreshes about every 30 minutes</div>
    <?php elseif ($hasLive && !$farm): ?>
        <div class="alert ok weather-status weather-status-quiet" role="status">Showing weather for your current location</div>
    <?php endif; ?>

    <div class="kpi-grid weather-kpi" aria-live="polite">
        <article class="kpi weather-kpi-card">
            <div class="wx-icon" aria-hidden="true"><?= weather_icon_svg($wxCode, $condition) ?></div>
            <span>Temperature</span>
            <b><?= $hasLive ? e($current['temperature_2m']) . '°C' : '—' ?></b>
            <?php if ($condition): ?><small class="muted"><?= e(ucfirst((string) $condition)) ?></small><?php endif; ?>
        </article>
        <article class="kpi weather-kpi-card">
            <div class="wx-icon" aria-hidden="true"><?= weather_icon_svg(null, 'humidity') ?></div>
            <span>Humidity</span>
            <b><?= $hasLive && isset($current['relative_humidity_2m']) ? e($current['relative_humidity_2m']) . '%' : '—' ?></b>
        </article>
        <article class="kpi weather-kpi-card">
            <div class="wx-icon" aria-hidden="true"><?= weather_icon_svg(null, 'rain') ?></div>
            <span>Rainfall</span>
            <b><?= $hasLive && isset($current['precipitation']) ? e($current['precipitation']) . ' mm' : '—' ?></b>
        </article>
        <article class="kpi weather-kpi-card">
            <div class="wx-icon" aria-hidden="true"><?= weather_icon_svg(null, 'wind') ?></div>
            <span>Wind</span>
            <b><?= $hasLive && isset($current['wind_speed_10m']) ? e($current['wind_speed_10m']) . ' km/h' : '—' ?></b>
        </article>
    </div>

    <div class="split-2 weather-split">
        <section class="panel">
            <header><h3>Forecast</h3></header>
            <div class="table-wrap">
                <table class="data-table weather-forecast-table">
                    <thead><tr><th></th><th>Date</th><th>Max</th><th>Min</th><th>Rain</th><th>Wind</th></tr></thead>
                    <tbody>
                    <?php if (!empty($daily['time'])): foreach ($daily['time'] as $i => $day):
                        $dayCode = $daily['weather_code'][$i] ?? null;
                        $dayLabel = isset($daily['weather_code'][$i]) ? WeatherService::codeLabel($daily['weather_code'][$i]) : '';
                    ?>
                        <tr>
                            <td class="wx-day-icon"><?= weather_icon_svg($dayCode, $dayLabel) ?></td>
                            <td><?= dater($day) ?></td>
                            <td><?= e($daily['temperature_2m_max'][$i] ?? '—') ?>°C</td>
                            <td><?= e($daily['temperature_2m_min'][$i] ?? '—') ?>°C</td>
                            <td><?= e($daily['precipitation_sum'][$i] ?? '—') ?> mm</td>
                            <td><?= e($daily['wind_speed_10m_max'][$i] ?? '—') ?> km/h</td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="6" class="muted">
                            <?= $locateNeeded ? 'Loading forecast for your location…' : 'Forecast unavailable right now.' ?>
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
                <header><h3>Field tips</h3></header>
                <ul class="feed">
                    <?php foreach ($tips as $tip): ?>
                        <li><strong><?= e($tip[0]) ?></strong><span><?= e($tip[1]) ?></span></li>
                    <?php endforeach; ?>
                    <?php if (!$tips): ?><li class="muted">Tips appear after weather loads.</li><?php endif; ?>
                </ul>
            </section>
        </div>
    </div>

    <section class="panel">
        <header><h3>Recent readings</h3></header>
        <div class="table-wrap">
            <table class="data-table">
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
<script>
(function () {
  var page = document.querySelector('[data-weather-page]');
  if (!page) return;
  var statusEl = document.querySelector('[data-weather-locate-status]');
  function go(lat, lon) {
    var url = new URL(window.location.href);
    url.searchParams.set('lat', Number(lat).toFixed(6));
    url.searchParams.set('lon', Number(lon).toFixed(6));
    window.location.href = url.toString();
  }
  function fail(msg) {
    if (statusEl) statusEl.textContent = msg;
  }
  function locate() {
    if (!navigator.geolocation) {
      fail('GPS is not available on this device.');
      return;
    }
    if (statusEl) statusEl.textContent = 'Getting your location to load temperature…';
    navigator.geolocation.getCurrentPosition(function (pos) {
      go(pos.coords.latitude, pos.coords.longitude);
    }, function (err) {
      fail(err && err.code === 1
        ? 'Location permission denied. Allow location, or add a farm with a map pin.'
        : 'Could not read GPS. Try again or add a farm with a map pin.');
    }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 });
  }
  var btn = document.querySelector('[data-weather-use-location]');
  if (btn) btn.addEventListener('click', locate);
  if (page.getAttribute('data-weather-locate') === '1') locate();
})();
</script>

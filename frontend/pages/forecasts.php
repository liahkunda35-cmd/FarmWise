<?php fe_component('partials/frontend'); ?>
<section class="panel">
    <header class="panel-tools">
        <div>
            <h3>Yield &amp; production forecasts</h3>
            <p class="muted">Forecasts are <strong>estimates</strong> from variety yield × field size (and optional AI notes). Actual harvest columns are recorded results — do not treat expected values as facts.</p>
            <button type="button" class="btn btn-ghost btn-sm" data-toggle-drawer="forecast-how">How this is calculated</button>
        </div>
        <?php if (fe_can('yield_forecasts', 'create')): ?>
        <form method="post" action="<?= e(url('forecasts/generate')) ?>" class="search-form">
            <?= fe_csrf_field() ?>
            <select name="planting_id" required>
                <option value="">Select planting</option>
                <?php foreach ($plantings as $p): ?>
                    <option value="<?= e($p['id']) ?>"><?= e(($p['crop_name'] ?? 'Crop').' · '.($p['field_name'] ?? 'Field').' · #'.$p['id']) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-gold btn-sm" type="submit">Generate forecast</button>
        </form>
        <?php endif; ?>
    </header>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Planting</th><th>Expected yield</th><th>Yield / ha</th><th>Price</th>
                <th>Expected revenue</th><th>Loss %</th><th>Actual harvest</th><th>Variance</th><th>Risk</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($comparisons as $c): $f = $c['forecast']; $var = (float)$c['actual'] - (float)$f['expected_yield']; ?>
                <tr>
                    <td><?php
                        $pl = fe_load_planting($f['planting_id'] ?? 0);
                        $href = fe_rel_href('plantings', $f['planting_id'] ?? 0);
                        $label = fe_planting_label($pl);
                        echo $href ? '<a class="rel-link" href="'.e($href).'">'.e($label).'</a>' : e($label);
                    ?></td>
                    <td><?= qty($f['expected_yield']) ?></td>
                    <td><?= qty($f['expected_yield_per_ha']) ?></td>
                    <td><?= money($f['expected_price']) ?></td>
                    <td><?= money($f['expected_revenue']) ?></td>
                    <td><?= e($f['loss_factor']) ?>%</td>
                    <td><?= qty($c['actual']) ?></td>
                    <td><?= qty($var) ?></td>
                    <td><?= status_badge($f['market_risk']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$comparisons): ?><tr><td colspan="9" class="muted">Generate a forecast from an active planting.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <p class="muted">Manual forecasts can also be captured under the Yield Forecasts data module if you prefer to enter values directly.</p>
    <p><a class="btn btn-ghost btn-sm" href="<?= e(url('module/yield_forecasts')) ?>">Open forecast records</a></p>
</section>
<aside class="ui-drawer" id="forecast-how" hidden>
    <div class="ui-drawer-card">
        <header class="panel-tools">
            <h3>How this is calculated</h3>
            <button type="button" class="btn btn-ghost btn-sm" data-close-drawer>Close</button>
        </header>
        <ol class="muted" style="padding-left:1.1rem">
            <li>Typical yield / ha from the crop variety × field size (ha).</li>
            <li>Loss factor reduces expected harvest quantity.</li>
            <li>Expected revenue = expected yield × market / entered price.</li>
            <li>Actual harvest is summed from harvest records on that planting.</li>
            <li>Variance = actual − expected. Market risk comes from the forecast record.</li>
        </ol>
        <p class="muted">Confidence is higher when the variety has a typical yield and the field has a size set.</p>
    </div>
</aside>
<script>
document.querySelectorAll('[data-toggle-drawer]').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var el = document.getElementById(btn.getAttribute('data-toggle-drawer'));
    if (el) el.hidden = false;
  });
});
document.querySelectorAll('[data-close-drawer]').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var drawer = btn.closest('.ui-drawer');
    if (drawer) drawer.hidden = true;
  });
});
</script>

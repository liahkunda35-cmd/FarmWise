<section class="panel">
    <header><h3>Price, demand and selling windows</h3></header>
    <div class="split-3">
        <?php foreach ($crops as $c): ?>
            <article class="kpi">
                <span><?= e($c['crop_name']) ?></span>
                <b><?= money($c['latest']['price_per_kg'] ?? 0) ?></b>
                <small>Avg <?= money($c['avg_price']) ?> · range <?= money($c['low_price']) ?>–<?= money($c['high_price']) ?><br>
                Demand: <?= e($c['latest']['demand_level'] ?? 'n/a') ?><br>
                Best selling signal: <?= ($c['latest']['demand_level'] ?? '') === 'high' ? 'Sell into current demand' : 'Hold if storage quality allows' ?></small>
            </article>
        <?php endforeach; ?>
        <?php if (!$crops): ?><p class="muted">Add market prices to unlock crop demand and revenue signals.</p><?php endif; ?>
    </div>
</section>
<section class="panel">
    <header><h3>Recent price tape</h3></header>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Date</th><th>Crop</th><th>Market</th><th>Price / kg</th><th>Demand</th></tr></thead>
            <tbody>
            <?php foreach ($prices as $p): ?>
                <tr>
                    <td><?= dater($p['price_date']) ?></td>
                    <td><?= e($p['crop_name']) ?></td>
                    <td><?= e($p['market']) ?></td>
                    <td><?= money($p['price_per_kg']) ?></td>
                    <td><?= status_badge($p['demand_level']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

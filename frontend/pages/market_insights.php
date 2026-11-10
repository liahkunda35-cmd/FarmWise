<?php
$filterCrop = $filterCrop ?? '';
$filterSource = $filterSource ?? '';
$filterProvince = $filterProvince ?? '';
$chart = is_array($chart ?? null) ? $chart : ['labels' => [], 'values' => [], 'crop' => ''];
$sources = is_array($sources ?? null) ? $sources : [];
$isAdmin = !empty($isAdmin);
?>
<section class="panel">
    <header>
        <div>
            <h3>Price, demand and selling windows</h3>
            <p class="muted">Farmer observations, WFP/HDX public food prices, and ZamStats bulletin imports. MoM change only when a prior month exists — gaps are not filled.</p>
        </div>
    </header>
    <form method="get" class="search-form report-filters" action="<?= e(url('market-insights')) ?>">
        <input type="search" name="crop" value="<?= e($filterCrop) ?>" placeholder="Crop" aria-label="Crop">
        <input type="search" name="province" value="<?= e($filterProvince) ?>" placeholder="Province" aria-label="Province">
        <select name="source" aria-label="Source">
            <option value="">All sources</option>
            <?php foreach ($sources as $s): ?>
                <?php $src = (string) ($s['source'] ?? ''); ?>
                <option value="<?= e($src) ?>" <?= $filterSource === $src ? 'selected' : '' ?>><?= e(MarketPriceSync::sourceBadge($src)) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-gold btn-sm" type="submit">Apply</button>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('agri-history')) ?>">Agri History</a>
    </form>
    <div class="split-3">
        <?php foreach ($crops as $c): ?>
            <?php
            $src = (string) ($c['latest']['source'] ?? 'farmer');
            $mom = $c['mom_pct'] ?? null;
            ?>
            <article class="kpi">
                <span><?= e($c['crop_name']) ?></span>
                <b><?= money($c['latest']['price_per_kg'] ?? 0) ?><small>/kg</small></b>
                <small>
                    Avg <?= money($c['avg_price'] ?? 0) ?> · range <?= money($c['low_price'] ?? 0) ?>–<?= money($c['high_price'] ?? 0) ?><br>
                    Demand: <?= e($c['latest']['demand_level'] ?? 'n/a') ?>
                    · <?= e(MarketPriceSync::sourceBadge($src)) ?><br>
                    <?php if ($mom !== null): ?>
                        MoM: <?= $mom > 0 ? '+' : '' ?><?= e((string) $mom) ?>%
                    <?php else: ?>
                        MoM: insufficient prior month
                    <?php endif; ?><br>
                    Best selling signal: <?= ($c['latest']['demand_level'] ?? '') === 'high' ? 'Sell into current demand' : 'Hold if storage quality allows' ?>
                </small>
            </article>
        <?php endforeach; ?>
        <?php if (!$crops): ?><p class="muted">No price rows match these filters. Add farmer prices or sync WFP/HDX data.</p><?php endif; ?>
    </div>
</section>

<?php if (!empty($chart['labels'])): ?>
<section class="panel">
    <header><h3>Price trend — <?= e($chart['crop'] ?: 'selected crop') ?></h3></header>
    <div class="report-chart-wrap"><canvas id="marketPriceChart" height="120" aria-label="Market price trend"></canvas></div>
    <script>window.IFMS_MARKET_CHART = <?= json_encode($chart, JSON_UNESCAPED_UNICODE) ?>;</script>
</section>
<?php endif; ?>

<section class="panel">
    <header><h3>Recent price tape</h3></header>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Date</th><th>Crop</th><th>Market</th><th>Province</th><th>Price</th><th>Unit</th><th>Source</th><th>Demand</th></tr></thead>
            <tbody>
            <?php foreach ($prices as $p): ?>
                <tr>
                    <td><?= dater($p['price_date']) ?></td>
                    <td><?= e($p['crop_name']) ?></td>
                    <td><?= e($p['market']) ?></td>
                    <td><?= e($p['province'] ?? '') ?></td>
                    <td>
                        <?php if (!empty($p['price_per_kg']) && (float) $p['price_per_kg'] > 0): ?>
                            <?= money($p['price_per_kg']) ?>/kg
                        <?php elseif (!empty($p['price'])): ?>
                            <?= money($p['price']) ?>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td><?= e($p['unit'] ?? 'KG') ?></td>
                    <td><span class="badge"><?= e(MarketPriceSync::sourceBadge((string) ($p['source'] ?? ''))) ?></span></td>
                    <td><?= status_badge($p['demand_level']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$prices): ?><tr><td colspan="8" class="muted">No prices yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <p class="muted" style="margin-top:.75rem">Sources: farmer observations; WFP/HDX Zambia Food Prices (CC BY-IGO; contributors may include ZamStats — rows labelled WFP/HDX, not ZamStats-only); ZamStats monthly bulletin structured imports.</p>
</section>

<?php if ($isAdmin): ?>
<section class="panel">
    <details>
        <summary><strong>Admin imports</strong> — sync HDX or enter a ZamStats bulletin price</summary>
    <div class="split-2" style="margin-top:1rem">
        <form method="post" action="<?= e(url('market-insights')) ?>" enctype="multipart/form-data" class="stack-form">
            <?= fe_csrf_field() ?>
            <input type="hidden" name="action" value="sync_hdx">
            <h4>Sync WFP/HDX CSV</h4>
            <p class="muted">Tries a live HDX download; if blocked (WAF), upload the public Zambia food prices CSV from <a href="https://data.humdata.org/dataset/wfp-food-prices-for-zambia" target="_blank" rel="noopener">HDX</a>. Rows are labelled WFP/HDX (not ZamStats-only).</p>
            <label>Months back
                <input type="number" name="months_back" value="36" min="6" max="120">
            </label>
            <label>Optional local CSV
                <input type="file" name="hdx_file" accept=".csv">
            </label>
            <button class="btn btn-gold" type="submit">Sync HDX prices</button>
        </form>
        <form method="post" action="<?= e(url('market-insights')) ?>" class="stack-form">
            <?= fe_csrf_field() ?>
            <input type="hidden" name="action" value="import_bulletin">
            <h4>ZamStats price bulletin row</h4>
            <p class="muted">Enter figures published in a ZamStats monthly bulletin (no PDF OCR).</p>
            <div class="form-grid">
                <label>Crop<input name="crop_name" required></label>
                <label>Market<input name="market" value="National retail"></label>
                <label>Date<input type="date" name="price_date" required value="<?= e(date('Y-m-d')) ?>"></label>
                <label>Price<input type="number" step="0.01" name="price" required></label>
                <label>Unit<input name="unit" value="KG"></label>
                <label>Province<input name="province"></label>
                <label>District<input name="district"></label>
                <label>Demand
                    <select name="demand_level">
                        <option value="low">Low</option>
                        <option value="medium" selected>Medium</option>
                        <option value="high">High</option>
                    </select>
                </label>
                <label class="wide">Notes<textarea name="notes" rows="2"></textarea></label>
            </div>
            <button class="btn btn-gold" type="submit">Import bulletin price</button>
        </form>
    </div>
    </details>
</section>
<?php endif; ?>

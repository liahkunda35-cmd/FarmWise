<?php
$crop = $crop ?? 'Maize (for grain)';
$province = $province ?? '';
$season = $season ?? '';
$trend = is_array($trend ?? null) ? $trend : ['labels' => [], 'production' => [], 'yield' => [], 'area' => []];
$comparison = is_array($comparison ?? null) ? $comparison : [];
$recommendations = is_array($recommendations ?? null) ? $recommendations : ['items' => [], 'disclaimer' => '', 'rainfall_note' => null, 'province' => ''];
$farmCompare = is_array($farmCompare ?? null) ? $farmCompare : [];
$datasets = is_array($datasets ?? null) ? $datasets : [];
$cropOptions = is_array($cropOptions ?? null) ? $cropOptions : [];
$provinceOptions = is_array($provinceOptions ?? null) ? $provinceOptions : [];
$seasonOptions = is_array($seasonOptions ?? null) ? $seasonOptions : [];
$isAdmin = !empty($isAdmin);
$statCount = (int) ($statCount ?? 0);
$rainfallCount = (int) ($rainfallCount ?? 0);
$livestockCount = (int) ($livestockCount ?? 0);
?>
<section class="panel">
    <header>
        <div>
            <h3>Zambia agricultural history</h3>
            <p class="muted">Official ZamStats Crop Forecast Survey aggregates only. Missing seasons appear as gaps — never invented. Household NADA microdata is not stored.</p>
        </div>
        <div class="tool-row">
            <span class="badge">Historical (ZamStats CFS)</span>
            <span class="badge"><?= (int) $statCount ?> crop rows</span>
            <?php if ($rainfallCount === 0): ?><span class="badge">No rainfall series</span><?php else: ?><span class="badge"><?= (int) $rainfallCount ?> rainfall</span><?php endif; ?>
            <?php if ($livestockCount === 0): ?><span class="badge">No livestock series</span><?php else: ?><span class="badge"><?= (int) $livestockCount ?> livestock</span><?php endif; ?>
        </div>
    </header>
    <form method="get" class="search-form report-filters" action="<?= e(url('agri-history')) ?>">
        <select name="crop" aria-label="Crop">
            <?php if (!$cropOptions): ?><option value="<?= e($crop) ?>"><?= e($crop) ?></option><?php endif; ?>
            <?php foreach ($cropOptions as $opt): ?>
                <?php $name = (string) ($opt['crop_name'] ?? ''); ?>
                <option value="<?= e($name) ?>" <?= $crop === $name ? 'selected' : '' ?>><?= e($name) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="province" aria-label="Province">
            <option value="">National trend</option>
            <?php foreach ($provinceOptions as $opt): ?>
                <?php $name = (string) ($opt['province'] ?? ''); ?>
                <option value="<?= e($name) ?>" <?= $province === $name ? 'selected' : '' ?>><?= e($name) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="season" aria-label="Season for map">
            <option value="">Latest season</option>
            <?php foreach ($seasonOptions as $opt): ?>
                <?php $name = (string) ($opt['season_label'] ?? ''); ?>
                <option value="<?= e($name) ?>" <?= $season === $name ? 'selected' : '' ?>><?= e($name) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-gold btn-sm" type="submit">Apply</button>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('market-insights')) ?>">Market Insights</a>
    </form>
</section>

<section class="panel">
    <header><h3>Production &amp; yield over imported seasons — <?= e($crop) ?><?= $province !== '' ? ' · ' . e($province) : ' · National' ?></h3></header>
    <?php if (empty($trend['labels'])): ?>
        <p class="muted">No CFS rows for this filter yet. Admin can import the published Crop Forecast Survey workbook.</p>
    <?php else: ?>
        <div class="split-2">
            <div class="report-chart-wrap"><canvas id="agriProdChart" height="140" aria-label="Production chart"></canvas></div>
            <div class="report-chart-wrap"><canvas id="agriYieldChart" height="140" aria-label="Yield chart"></canvas></div>
        </div>
        <script>window.IFMS_AGRI_TREND = <?= json_encode($trend, JSON_UNESCAPED_UNICODE) ?>;</script>
    <?php endif; ?>
</section>

<section class="panel">
    <header><h3>Provincial comparison<?= $season !== '' ? ' · ' . e($season) : '' ?></h3></header>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Province</th><th>Season</th><th>Area planted (ha)</th><th>Production (mt)</th><th>Yield (mt/ha)</th><th>Sales (mt)</th></tr></thead>
            <tbody>
            <?php foreach ($comparison as $row): ?>
                <tr>
                    <td><?= e($row['province']) ?></td>
                    <td><?= e($row['season_label']) ?></td>
                    <td><?= e($row['area_planted_ha'] !== null ? number_format((float) $row['area_planted_ha'], 0) : '—') ?></td>
                    <td><?= e($row['production_mt'] !== null ? number_format((float) $row['production_mt'], 0) : '—') ?></td>
                    <td><?= e($row['yield_mt_ha'] !== null ? number_format((float) $row['yield_mt_ha'], 3) : '—') ?></td>
                    <td><?= e($row['sales_mt'] !== null ? number_format((float) $row['sales_mt'], 0) : '—') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$comparison): ?><tr><td colspan="6" class="muted">No provincial rows for this crop/season.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($comparison): ?>
        <div class="report-chart-wrap" style="margin-top:1rem"><canvas id="agriProvChart" height="140" aria-label="Provincial production"></canvas></div>
        <script>window.IFMS_AGRI_PROV = <?= json_encode([
            'labels' => array_map(static fn ($r) => $r['province'], $comparison),
            'production' => array_map(static fn ($r) => $r['production_mt'] !== null ? (float) $r['production_mt'] : null, $comparison),
            'yield' => array_map(static fn ($r) => $r['yield_mt_ha'] !== null ? (float) $r['yield_mt_ha'] : null, $comparison),
        ], JSON_UNESCAPED_UNICODE) ?>;</script>
    <?php endif; ?>
</section>

<section class="panel">
    <header><h3>Province recommendations<?= !empty($recommendations['province']) ? ' · ' . e($recommendations['province']) : '' ?></h3></header>
    <?php if (empty($recommendations['items'])): ?>
        <p class="muted"><?= e($recommendations['disclaimer'] ?? 'No recommendation data.') ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Crop</th><th>Seasons</th><th>Avg yield</th><th>Observed range</th><th>Trend</th><th>Notes</th></tr></thead>
                <tbody>
                <?php foreach ($recommendations['items'] as $item): ?>
                    <tr>
                        <td><?= e($item['crop_name']) ?></td>
                        <td><?= (int) $item['seasons'] ?> (<?= e($item['first_season']) ?>–<?= e($item['last_season']) ?>)</td>
                        <td><?= e($item['avg_yield'] !== null ? $item['avg_yield'] . ' mt/ha' : '—') ?></td>
                        <td><?= e(($item['min_yield'] ?? '—') . ' – ' . ($item['max_yield'] ?? '—')) ?></td>
                        <td><?= e($item['trend'] ?? '') ?></td>
                        <td><?= !empty($item['limited_history']) ? 'Limited seasons in DB' : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="muted"><?= e($recommendations['disclaimer'] ?? '') ?></p>
        <?php if (!empty($recommendations['rainfall_note'])): ?>
            <p class="muted"><?= e($recommendations['rainfall_note']) ?></p>
        <?php endif; ?>
    <?php endif; ?>
</section>

<section class="panel">
    <header><h3>Farm performance vs provincial CFS</h3></header>
    <?php if (isset($farmCompare['note'])): ?>
        <p class="muted"><?= e($farmCompare['note']) ?></p>
    <?php elseif (!$farmCompare): ?>
        <p class="muted">No comparable farm harvests with hectare and mass units, or no CFS match for planted crops.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Farm crop</th><th>CFS crop</th><th>Farm yield</th><th>CFS avg</th><th>CFS range</th><th>Seasons</th></tr></thead>
                <tbody>
                <?php foreach ($farmCompare as $row): ?>
                    <tr>
                        <td><?= e($row['farm_crop']) ?></td>
                        <td><?= e($row['cfs_crop']) ?></td>
                        <td><?= e($row['farm_yield_mt_ha']) ?> mt/ha</td>
                        <td><?= e($row['cfs_avg_yield_mt_ha']) ?> mt/ha</td>
                        <td><?= e(($row['cfs_range'][0] ?? '—') . ' – ' . ($row['cfs_range'][1] ?? '—')) ?></td>
                        <td><?= (int) $row['cfs_seasons'] ?> (<?= e($row['season_span']) ?>)</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="panel">
    <header><h3>Imported datasets</h3></header>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Title</th><th>Season</th><th>Rows</th><th>Imported</th><th>License</th></tr></thead>
            <tbody>
            <?php foreach ($datasets as $d): ?>
                <tr>
                    <td><?= e($d['title']) ?></td>
                    <td><?= e($d['season_label'] ?? '') ?></td>
                    <td><?= (int) ($d['row_count'] ?? 0) ?></td>
                    <td><?= e($d['imported_at'] ?? '') ?></td>
                    <td class="muted"><?= e(mb_strimwidth((string) ($d['license_note'] ?? ''), 0, 80, '…')) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$datasets): ?><tr><td colspan="5" class="muted">No datasets imported yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <p class="muted">Citation: Zambia Statistics Agency / Ministry of Agriculture, Crop Forecast Survey (published aggregate tables). Do not redistribute NADA confidential household microdata through FarmWise.</p>
</section>

<?php if ($isAdmin): ?>
<section class="panel">
    <details>
        <summary><strong>Admin — import CFS / aggregate CSV</strong></summary>
    <form method="post" action="<?= e(url('agri-history')) ?>" enctype="multipart/form-data" class="stack-form" style="margin-top:1rem">
        <?= fe_csrf_field() ?>
        <input type="hidden" name="action" value="import_cfs">
        <div class="form-grid">
            <label class="wide">Upload published CFS Excel or aggregate CSV
                <input type="file" name="cfs_file" accept=".xlsx,.csv">
            </label>
            <label class="wide"><input type="checkbox" name="use_repo_file" value="1"> Use repository file <code>Crop-Forecast-Survey-2025_2026.xlsx</code></label>
            <label>Title<input name="title" placeholder="Optional dataset title"></label>
            <label>Season label<input name="season_label" placeholder="e.g. 2025/2026"></label>
            <label>Kind
                <select name="kind">
                    <option value="crop">Crop stats</option>
                    <option value="rainfall">Rainfall (CSV only)</option>
                    <option value="livestock">Livestock (CSV only)</option>
                </select>
            </label>
            <label class="wide">Source URL<input name="source_url" value="https://www.zamstats.gov.zm/agriculture-and-environment/"></label>
        </div>
        <button class="btn btn-gold" type="submit">Import aggregates</button>
    </form>
    </details>
</section>
<?php endif; ?>

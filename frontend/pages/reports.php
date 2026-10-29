<?php $filters = $filters ?? ['date_from'=>'','date_to'=>'','status'=>'','farm_id'=>'']; ?>
<section class="panel">
    <header>
        <h3>Reports launcher</h3>
        <p class="muted">Pick a report type, set filters, then preview, print or export.</p>
    </header>
    <div class="report-launcher">
        <?php foreach ($types as $k => $l): ?>
            <a class="report-card<?= $type === $k ? ' is-active' : '' ?>" href="<?= e(url('reports?type='.$k)) ?>">
                <strong><?= e($l) ?></strong>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<section class="panel">
    <header class="panel-tools">
        <div>
            <h3><?= e($types[$type] ?? 'Report') ?></h3>
            <p class="muted"><?= e($report['summary'] ?? '') ?></p>
            <?php if (!empty($report['period'])): ?><p class="muted"><?= e($report['period']) ?></p><?php endif; ?>
        </div>
        <div class="tool-row">
            <button type="button" class="btn btn-ghost btn-sm" onclick="window.print()">Print</button>
            <a class="btn btn-ghost btn-sm" href="?<?= e(request_query(['export' => 'csv'])) ?>">CSV</a>
            <a class="btn btn-ghost btn-sm" href="?<?= e(request_query(['export' => 'pdf'])) ?>">PDF</a>
        </div>
    </header>
    <form method="get" class="search-form report-filters">
        <input type="hidden" name="type" value="<?= e($type) ?>">
        <label class="sr-only" for="rep-from">Start date</label>
        <input id="rep-from" type="date" name="date_from" value="<?= e($filters['date_from'] ?? '') ?>">
        <label class="sr-only" for="rep-to">End date</label>
        <input id="rep-to" type="date" name="date_to" value="<?= e($filters['date_to'] ?? '') ?>">
        <?php if (fe_is_admin() && !empty($farms)): ?>
            <select name="farm_id" aria-label="Farm">
                <option value="">All accessible farms</option>
                <?php foreach ($farms as $id=>$name): ?>
                    <option value="<?= e($id) ?>" <?= (string)($filters['farm_id'] ?? '') === (string)$id ? 'selected' : '' ?>><?= e($name) ?></option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
        <input name="status" value="<?= e($filters['status'] ?? '') ?>" placeholder="Status (optional)" aria-label="Status">
        <button class="btn btn-gold btn-sm" type="submit">Apply Filters</button>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('reports?type='.$type)) ?>">Reset</a>
    </form>
    <?php if (!empty($report['chart']['labels'])): ?>
        <div class="report-chart-wrap"><canvas id="reportChart" height="120"></canvas></div>
        <script>window.IFMS_REPORT_CHART = <?= json_encode($report['chart']) ?>;</script>
    <?php endif; ?>
    <div class="table-wrap sticky-head">
        <table>
            <thead><tr><?php foreach ($report['headers'] as $h): ?><th><?= e($h) ?></th><?php endforeach; ?></tr></thead>
            <tbody>
            <?php foreach ($report['rows'] as $row): ?>
                <tr><?php foreach ($row as $cell): ?><td><?= $cell ?></td><?php endforeach; ?></tr>
            <?php endforeach; ?>
            <?php if (!$report['rows']): ?><tr><td class="muted" colspan="<?= count($report['headers']) ?>">No data for this reporting period.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

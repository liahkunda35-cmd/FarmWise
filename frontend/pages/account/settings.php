<?php include dirname(__DIR__) . '/admin/_nav.php'; ?>
<section class="panel">
    <header>
        <div>
            <h3>Currency &amp; timezone</h3>
            <p class="muted">These settings change how amounts and dates are displayed. Stored values are not converted.</p>
        </div>
    </header>
    <form class="stack-form" method="post" action="<?= e(url('settings')) ?>" data-settings-preview>
        <?= fe_csrf_field() ?>
        <div class="form-grid">
            <label>Currency
                <select name="currency" required data-currency-select>
                    <?php foreach ($currencies as $code => $meta): ?>
                        <option value="<?= e($code) ?>" data-symbol="<?= e($meta['symbol']) ?>" <?= $currency === $code ? 'selected' : '' ?>><?= e($code . ' — ' . $meta['label'] . ' (' . $meta['symbol'] . ')') ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Timezone
                <select name="timezone" required data-timezone-select>
                    <?php foreach ($timezones as $tz => $label): ?>
                        <option value="<?= e($tz) ?>" <?= $timezone === $tz ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <p class="muted">Live preview: <strong data-settings-live><?= e($symbol) ?> 1,250.00 — <?= e(date('d M Y, H:i')) ?></strong></p>
        <button class="btn btn-gold" type="submit">Save settings</button>
    </form>
</section>

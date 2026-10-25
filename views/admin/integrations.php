<?php include __DIR__ . '/_nav.php'; ?>
<?php $s = $settings; ?>
<section class="panel">
    <header>
        <div>
            <h3>Email, SMS and API</h3>
            <p class="muted">These are live outbound channels. Alerts will email and/or SMS farm users once a provider is saved and enabled.</p>
        </div>
    </header>
    <form method="post" action="<?= e(url('integrations/save')) ?>" class="stack-form">
        <?= Csrf::field() ?>
        <h3>SMTP email</h3>
        <div class="form-grid">
            <label>SMTP host<input name="smtp_host" value="<?= e($s['smtp_host'] ?? '') ?>" placeholder="smtp.gmail.com"></label>
            <label>Port<input name="smtp_port" value="<?= e($s['smtp_port'] ?? '587') ?>"></label>
            <label>Encryption
                <select name="smtp_encryption">
                    <?php foreach (['tls'=>'STARTTLS (587)','ssl'=>'SSL (465)','none'=>'None'] as $k=>$l): ?>
                        <option value="<?= e($k) ?>" <?= ($s['smtp_encryption'] ?? 'tls')===$k?'selected':'' ?>><?= e($l) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Username<input name="smtp_user" value="<?= e($s['smtp_user'] ?? '') ?>"></label>
            <label>Password<input type="password" name="smtp_pass" placeholder="<?= empty($s['smtp_pass']) ? '' : 'Unchanged if left blank' ?>"></label>
            <label>From email<input type="email" name="smtp_from" value="<?= e($s['smtp_from'] ?? '') ?>"></label>
            <label>From name<input name="smtp_from_name" value="<?= e($s['smtp_from_name'] ?? 'FarmWise') ?>"></label>
            <label class="wide"><input type="hidden" name="notify_email" value="0"><input type="checkbox" name="notify_email" value="1" <?= !empty($s['notify_email']) && $s['notify_email'] !== '0' ? 'checked' : '' ?>> Send farm alerts by email</label>
        </div>
        <h3>SMS gateway</h3>
        <div class="form-grid">
            <label>Provider
                <select name="sms_provider">
                    <?php foreach (['none'=>'Disabled','africastalking'=>"Africa's Talking",'twilio'=>'Twilio','generic'=>'Generic HTTP'] as $k=>$l): ?>
                        <option value="<?= e($k) ?>" <?= ($s['sms_provider'] ?? 'none')===$k?'selected':'' ?>><?= e($l) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Username / Account SID<input name="sms_user" value="<?= e($s['sms_user'] ?? '') ?>"></label>
            <label>API key / Auth token<input type="password" name="sms_api_key" value="<?= e($s['sms_api_key'] ?? '') ?>"></label>
            <label>Sender ID / From<input name="sms_sender" value="<?= e($s['sms_sender'] ?? '') ?>"></label>
            <label class="wide">Generic HTTP URL<input name="sms_url" value="<?= e($s['sms_url'] ?? '') ?>" placeholder="https://â€¦"></label>
            <label class="wide"><input type="hidden" name="notify_sms" value="0"><input type="checkbox" name="notify_sms" value="1" <?= !empty($s['notify_sms']) && $s['notify_sms'] !== '0' ? 'checked' : '' ?>> Send farm alerts by SMS</label>
        </div>
        <div class="form-actions">
            <button class="btn btn-gold" type="submit">Save integrations</button>
        </div>
    </form>
</section>
<section class="panel">
    <header><h3>Send a live test</h3></header>
    <div class="split-2">
        <form method="post" action="<?= e(url('integrations/test-email')) ?>">
            <?= Csrf::field() ?>
            <label>Email<input type="email" name="test_email" value="<?= e(Auth::user()['email'] ?? '') ?>" required></label>
            <button class="btn btn-gold btn-sm" type="submit">Send test email</button>
        </form>
        <form method="post" action="<?= e(url('integrations/test-sms')) ?>">
            <?= Csrf::field() ?>
            <label>Mobile (international)<input name="test_sms" value="<?= e(Auth::user()['phone'] ?? '') ?>" placeholder="+26097â€¦" required></label>
            <button class="btn btn-gold btn-sm" type="submit">Send test SMS</button>
        </form>
    </div>
    <p class="muted">JWT API: <code>POST /api/login</code> with JSON <code>{"email","password"}</code>, then <code>Authorization: Bearer &lt;token&gt;</code> for <code>/api/me</code>, <code>/api/dashboard</code>, <code>/api/weather</code>, <code>/api/notifications</code> and <code>/api/module/{name}</code>.</p>
</section>


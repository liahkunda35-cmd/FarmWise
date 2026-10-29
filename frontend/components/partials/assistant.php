<?php
/** FarmWise AI Assistant launcher + panel (landing = public, app layout = auth). */
$assistantEndpoint = url('assistant/chat');
$assistantCsrf = fe_csrf_token();
$assistantMode = ($assistantMode ?? null) === 'auth' ? 'auth' : 'public';

$assistantAuthed = fe_check();
$assistantFirstName = '';
$assistantUserId = '';
if ($assistantAuthed) {
    $assistantUserId = (string) (fe_id() ?? '');
    $full = trim((string) (fe_user()['name'] ?? ''));
    if ($full !== '') {
        $parts = preg_split('/\s+/u', $full) ?: [];
        $assistantFirstName = (string) ($parts[0] ?? '');
    }
}

// Auth mode only when signed in AND page requested auth assistant
if ($assistantMode === 'auth' && !$assistantAuthed) {
    $assistantMode = 'public';
}

$assistantLang = 'en';
if (class_exists('AssistantController')) {
    $assistantLang = AssistantController::preferredLanguage();
}
$assistantUi = GeminiService::uiStrings($assistantLang);
$assistantUiAll = [];
foreach (GeminiService::LANGUAGES as $code) {
    $assistantUiAll[$code] = GeminiService::uiStrings($code);
}

$assistantSubtitle = $assistantMode === 'auth'
    ? $assistantUi['subtitle_auth']
    : $assistantUi['subtitle_public'];

$assistantSuggestions = $assistantMode === 'auth'
    ? [
        'Help me understand my farm dashboard.',
        'How can I improve my crop management?',
        'Help me review my farm reports.',
        'Help me plan my farm activities.',
        'What livestock do I have recorded?',
        'Should I irrigate based on the weather forecast?',
    ]
    : [
        'How do I sign up?',
        'How do I reset my password?',
        'What is FarmWise?',
        'How do I sign in?',
    ];
?>
<div
    id="fw-ai-root"
    data-endpoint="<?= e($assistantEndpoint) ?>"
    data-csrf="<?= e($assistantCsrf) ?>"
    data-mode="<?= e($assistantMode) ?>"
    data-authenticated="<?= $assistantAuthed && $assistantMode === 'auth' ? '1' : '0' ?>"
    data-first-name="<?= e($assistantFirstName) ?>"
    data-user-id="<?= e($assistantUserId) ?>"
    data-ai-configured="<?= GeminiService::configured() ? '1' : '0' ?>"
    data-language="<?= e($assistantLang) ?>"
    data-ui='<?= e(json_encode($assistantUiAll, JSON_UNESCAPED_UNICODE)) ?>'
>
    <button type="button" class="fw-ai-fab" id="fw-ai-fab" aria-expanded="false" aria-controls="fw-ai-panel" aria-label="Ask FarmWise AI" title="Ask FarmWise AI">
        <span class="fw-ai-fab-icon" aria-hidden="true">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z"/><path d="M8 9h8"/><path d="M8 13h5"/></svg>
        </span>
        <span class="fw-ai-fab-label sr-only">Ask FarmWise AI</span>
    </button>
    <section class="fw-ai-panel" id="fw-ai-panel" role="dialog" aria-label="FarmWise AI Assistant" hidden>
        <header class="fw-ai-head">
            <span class="fw-ai-avatar" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z"/></svg>
            </span>
            <div class="fw-ai-head-text">
                <h2>FarmWise AI<?= $assistantMode === 'auth' ? '' : ' · Guest' ?></h2>
                <p id="fw-ai-subtitle"><?= e($assistantSubtitle) ?></p>
            </div>
            <div class="fw-ai-head-actions">
                <label class="fw-ai-lang-wrap" title="Assistant language">
                    <span class="sr-only" id="fw-ai-lang-label"><?= e($assistantUi['lang_label']) ?></span>
                    <select id="fw-ai-lang" class="fw-ai-lang" aria-labelledby="fw-ai-lang-label">
                        <?php foreach (GeminiService::LANGUAGE_LABELS as $code => $label): ?>
                            <option value="<?= e($code) ?>"<?= $code === $assistantLang ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="button" class="fw-ai-icon-btn" id="fw-ai-clear" title="<?= e($assistantUi['clear']) ?>" aria-label="<?= e($assistantUi['clear']) ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/></svg>
                </button>
                <button type="button" class="fw-ai-icon-btn" id="fw-ai-close" title="<?= e($assistantUi['close']) ?>" aria-label="<?= e($assistantUi['close']) ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </button>
            </div>
        </header>
        <div class="fw-ai-msgs" id="fw-ai-msgs" aria-live="polite"></div>
        <div class="fw-ai-suggestions" id="fw-ai-suggestions">
            <?php foreach ($assistantSuggestions as $q): ?>
                <button type="button" class="fw-ai-chip" data-q="<?= e($q) ?>"><?= e($q) ?></button>
            <?php endforeach; ?>
        </div>
        <form class="fw-ai-form" id="fw-ai-form">
            <label class="sr-only" for="fw-ai-input">Message</label>
            <textarea id="fw-ai-input" rows="1" maxlength="2000" placeholder="<?= e($assistantMode === 'auth' ? $assistantUi['placeholder_auth'] : $assistantUi['placeholder_public']) ?>" required></textarea>
            <button type="submit" class="fw-ai-send" id="fw-ai-send"><?= e($assistantUi['send']) ?></button>
        </form>
    </section>
</div>

<?php
/** FarmWise AI Assistant launcher + panel (shared by app layout and public landing). */
$assistantEndpoint = url('assistant/chat');
$assistantCsrf = Csrf::token();
?>
<div id="fw-ai-root" data-endpoint="<?= e($assistantEndpoint) ?>" data-csrf="<?= e($assistantCsrf) ?>">
    <button type="button" class="fw-ai-fab" id="fw-ai-fab" aria-expanded="false" aria-controls="fw-ai-panel" aria-label="Ask FarmWise AI" title="Ask FarmWise AI">
        <span class="fw-ai-fab-icon" aria-hidden="true">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z"/><path d="M8 9h8"/><path d="M8 13h5"/></svg>
        </span>
        <span class="fw-ai-fab-label">Ask FarmWise AI</span>
    </button>
    <section class="fw-ai-panel" id="fw-ai-panel" role="dialog" aria-label="FarmWise AI Assistant" hidden>
        <header class="fw-ai-head">
            <span class="fw-ai-avatar" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z"/></svg>
            </span>
            <div>
                <h2>FarmWise AI Assistant</h2>
                <p>Ask how FarmWise helps run the farm</p>
            </div>
            <div class="fw-ai-head-actions">
                <button type="button" class="fw-ai-icon-btn" id="fw-ai-clear" title="Clear chat" aria-label="Clear chat">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/></svg>
                </button>
                <button type="button" class="fw-ai-icon-btn" id="fw-ai-close" title="Close chat" aria-label="Close chat">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </button>
            </div>
        </header>
        <div class="fw-ai-msgs" id="fw-ai-msgs" aria-live="polite"></div>
        <div class="fw-ai-suggestions" id="fw-ai-suggestions">
            <button type="button" class="fw-ai-chip" data-q="What can FarmWise help me with?">What can FarmWise help me with?</button>
            <button type="button" class="fw-ai-chip" data-q="How do I create an account?">How do I create an account?</button>
            <button type="button" class="fw-ai-chip" data-q="How do I register a new crop?">How do I register a new crop?</button>
            <button type="button" class="fw-ai-chip" data-q="How can I record farm expenses?">How can I record farm expenses?</button>
            <button type="button" class="fw-ai-chip" data-q="How do I manage livestock?">How do I manage livestock?</button>
            <button type="button" class="fw-ai-chip" data-q="How do I generate a farm report?">How do I generate a farm report?</button>
        </div>
        <form class="fw-ai-form" id="fw-ai-form">
            <label class="sr-only" for="fw-ai-input">Message</label>
            <textarea id="fw-ai-input" rows="1" maxlength="2000" placeholder="Ask FarmWise AI…" required></textarea>
            <button type="submit" class="fw-ai-send" id="fw-ai-send">Send</button>
        </form>
    </section>
</div>

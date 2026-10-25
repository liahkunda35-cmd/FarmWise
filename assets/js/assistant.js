(function () {
  'use strict';

  var root = document.getElementById('fw-ai-root');
  if (!root) return;

  var endpoint = root.getAttribute('data-endpoint') || '';
  var csrf = root.getAttribute('data-csrf') || '';
  var fab = document.getElementById('fw-ai-fab');
  var panel = document.getElementById('fw-ai-panel');
  var msgs = document.getElementById('fw-ai-msgs');
  var form = document.getElementById('fw-ai-form');
  var input = document.getElementById('fw-ai-input');
  var sendBtn = document.getElementById('fw-ai-send');
  var closeBtn = document.getElementById('fw-ai-close');
  var clearBtn = document.getElementById('fw-ai-clear');
  var suggestions = document.getElementById('fw-ai-suggestions');

  var history = [];
  var busy = false;
  var STORAGE_KEY = 'fw_ai_history_v1';

  try {
    var saved = sessionStorage.getItem(STORAGE_KEY);
    if (saved) {
      var parsed = JSON.parse(saved);
      if (Array.isArray(parsed)) history = parsed.slice(-16);
    }
  } catch (e) { /* ignore */ }

  function persist() {
    try {
      sessionStorage.setItem(STORAGE_KEY, JSON.stringify(history.slice(-16)));
    } catch (e) { /* ignore */ }
  }

  function openPanel() {
    panel.hidden = false;
    fab.setAttribute('aria-expanded', 'true');
    fab.setAttribute('aria-label', 'Close FarmWise AI Assistant');
    if (input) input.focus();
  }

  function closePanel() {
    panel.hidden = true;
    fab.setAttribute('aria-expanded', 'false');
    fab.setAttribute('aria-label', 'Open FarmWise AI Assistant');
  }

  function togglePanel() {
    if (panel.hidden) openPanel();
    else closePanel();
  }

  function addBubble(role, text, extraClass) {
    var div = document.createElement('div');
    div.className = 'fw-ai-bubble ' + role + (extraClass ? ' ' + extraClass : '');
    div.textContent = text;
    msgs.appendChild(div);
    msgs.scrollTop = msgs.scrollHeight;
    return div;
  }

  function renderHistory() {
    msgs.innerHTML = '';
    addBubble(
      'bot',
      'Hi — I am the FarmWise AI Assistant. Ask me how to use FarmWise, or about crops, livestock, irrigation, inventory, harvests, and farm finances. For serious plant disease or animal health issues, please also consult an extension officer or veterinarian.'
    );
    history.forEach(function (turn) {
      addBubble(turn.role === 'model' ? 'bot' : 'user', turn.text);
    });
  }

  async function sendMessage(text) {
    text = String(text || '').trim();
    if (!text || busy) return;
    busy = true;
    sendBtn.disabled = true;
    if (suggestions) suggestions.hidden = true;

    addBubble('user', text);
    history.push({ role: 'user', text: text });
    persist();

    var loading = addBubble('bot', 'Thinking…', 'loading');
    input.value = '';

    try {
      var res = await fetch(endpoint, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-CSRF-TOKEN': csrf,
        },
        credentials: 'same-origin',
        body: JSON.stringify({
          message: text,
          history: history.slice(0, -1),
          include_farm_context: true,
          _csrf: csrf,
        }),
      });
      var data = {};
      try {
        data = await res.json();
      } catch (e) {
        data = {};
      }
      loading.remove();
      if (!res.ok || !data.ok) {
        var err = (data && data.error) || 'Something went wrong. Please try again.';
        addBubble('bot', err, 'err');
        history.pop();
        persist();
      } else {
        var reply = String(data.reply || '').trim() || 'I did not receive a reply.';
        addBubble('bot', reply);
        history.push({ role: 'model', text: reply });
        persist();
      }
    } catch (e) {
      loading.remove();
      addBubble('bot', 'Network error — check your connection and try again.', 'err');
      history.pop();
      persist();
    } finally {
      busy = false;
      sendBtn.disabled = false;
      if (input) input.focus();
    }
  }

  fab.addEventListener('click', togglePanel);
  if (closeBtn) closeBtn.addEventListener('click', closePanel);
  if (clearBtn) {
    clearBtn.addEventListener('click', function () {
      history = [];
      persist();
      renderHistory();
      if (suggestions) suggestions.hidden = false;
    });
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    sendMessage(input.value);
  });

  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      sendMessage(input.value);
    }
  });

  if (suggestions) {
    suggestions.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-q]');
      if (!btn) return;
      openPanel();
      sendMessage(btn.getAttribute('data-q'));
    });
  }

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !panel.hidden) closePanel();
  });

  renderHistory();
})();

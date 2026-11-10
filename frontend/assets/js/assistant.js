(function () {
  'use strict';

  var root = document.getElementById('fw-ai-root');
  if (!root) return;

  var endpoint = root.getAttribute('data-endpoint') || '';
  var csrf = root.getAttribute('data-csrf') || '';
  var mode = root.getAttribute('data-mode') === 'auth' ? 'auth' : 'public';
  var authenticated = root.getAttribute('data-authenticated') === '1';
  var firstName = (root.getAttribute('data-first-name') || '').trim();
  var userId = (root.getAttribute('data-user-id') || '').trim();
  var aiConfigured = root.getAttribute('data-ai-configured') === '1';
  var REQUEST_TIMEOUT_MS = 40000;

  var fab = document.getElementById('fw-ai-fab');
  var panel = document.getElementById('fw-ai-panel');
  var msgs = document.getElementById('fw-ai-msgs');
  var form = document.getElementById('fw-ai-form');
  var input = document.getElementById('fw-ai-input');
  var sendBtn = document.getElementById('fw-ai-send');
  var closeBtn = document.getElementById('fw-ai-close');
  var clearBtn = document.getElementById('fw-ai-clear');
  var suggestions = document.getElementById('fw-ai-suggestions');
  var langSelect = document.getElementById('fw-ai-lang');
  var subtitleEl = document.getElementById('fw-ai-subtitle');
  var langLabelEl = document.getElementById('fw-ai-lang-label');

  var uiPacks = {};
  try {
    uiPacks = JSON.parse(root.getAttribute('data-ui') || '{}') || {};
  } catch (e) {
    uiPacks = {};
  }

  var LANG_KEY =
    mode === 'auth' && userId ? 'fw_ai_lang_auth_' + userId : 'fw_ai_lang_public';

  function normalizeLang(code) {
    code = String(code || 'en').toLowerCase();
    var allowed = ['en', 'bem', 'nya', 'toi'];
    return allowed.indexOf(code) >= 0 ? code : 'en';
  }

  var language = normalizeLang(
    (langSelect && langSelect.value) || root.getAttribute('data-language') || 'en'
  );
  try {
    var storedLang = localStorage.getItem(LANG_KEY);
    if (storedLang) language = normalizeLang(storedLang);
    if (storedLang && storedLang !== language) persistLang();
  } catch (e) { /* ignore */ }
  if (langSelect) langSelect.value = language;

  function ui() {
    return uiPacks[language] || uiPacks.en || {};
  }

  // Isolate history: public vs per-user auth — never mix accounts on one device
  var STORAGE_KEY =
    mode === 'auth' && userId
      ? 'fw_ai_hist_auth_' + userId
      : 'fw_ai_hist_public';
  var GREET_KEY = STORAGE_KEY + '_greeted';
  var IDENTITY_KEY = 'fw_ai_last_identity';

  var history = [];
  var busy = false;
  var lastFailedText = '';
  var closeTimer = null;
  var abortCtrl = null;
  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function currentIdentity() {
    return mode + ':' + (userId || 'guest');
  }

  // Clear chat if a different user signs in on this browser
  try {
    var prevId = sessionStorage.getItem(IDENTITY_KEY);
    var nowId = currentIdentity();
    if (prevId && prevId !== nowId) {
      sessionStorage.removeItem(STORAGE_KEY);
      sessionStorage.removeItem(GREET_KEY);
      if (mode === 'public') {
        Object.keys(sessionStorage).forEach(function (k) {
          if (k.indexOf('fw_ai_hist_auth_') === 0) sessionStorage.removeItem(k);
        });
      }
    }
    sessionStorage.setItem(IDENTITY_KEY, nowId);
  } catch (e) { /* ignore */ }

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

  function persistLang() {
    try {
      localStorage.setItem(LANG_KEY, language);
    } catch (e) { /* ignore */ }
  }

  function dayPart() {
    var h = new Date().getHours();
    if (h < 12) return 'morning';
    if (h < 17) return 'afternoon';
    return 'evening';
  }

  function greetingText() {
    var pack = ui();
    var part = dayPart();
    if (authenticated && firstName && pack.greeting_auth) {
      return pack.greeting_auth.replace('{part}', part).replace('{name}', firstName);
    }
    if (authenticated && pack.greeting_auth_noname) {
      return pack.greeting_auth_noname.replace('{part}', part);
    }
    if (mode === 'public' && pack.greeting_public) {
      return pack.greeting_public.replace('{part}', part);
    }
    return 'Good ' + part + '! How can I help you today?';
  }

  function applyUiChrome() {
    var pack = ui();
    if (subtitleEl) {
      subtitleEl.textContent =
        mode === 'auth' ? pack.subtitle_auth || '' : pack.subtitle_public || '';
    }
    if (input) {
      input.placeholder =
        mode === 'auth'
          ? pack.placeholder_auth || 'Ask about your farm…'
          : pack.placeholder_public || 'Ask about FarmWise…';
    }
    if (sendBtn) sendBtn.textContent = pack.send || 'Send';
    if (langLabelEl) langLabelEl.textContent = pack.lang_label || 'Language';
    if (clearBtn) {
      clearBtn.title = pack.clear || 'New conversation';
      clearBtn.setAttribute('aria-label', pack.clear || 'New conversation');
    }
    if (closeBtn) {
      closeBtn.title = pack.close || 'Close chat';
      closeBtn.setAttribute('aria-label', pack.close || 'Close chat');
    }
  }

  function openPanel() {
    if (closeTimer) {
      window.clearTimeout(closeTimer);
      closeTimer = null;
    }
    panel.hidden = false;
    panel.classList.remove('is-closing');
    void panel.offsetWidth;
    panel.classList.add('is-open');
    fab.setAttribute('aria-expanded', 'true');
    fab.setAttribute('aria-label', 'Close FarmWise AI Assistant');
    if (input) input.focus();
  }

  function closePanel() {
    stopSpeech();
    fab.setAttribute('aria-expanded', 'false');
    fab.setAttribute('aria-label', 'Open FarmWise AI Assistant');
    panel.classList.remove('is-open');
    panel.classList.add('is-closing');
    if (reduceMotion) {
      panel.hidden = true;
      panel.classList.remove('is-closing');
      return;
    }
    closeTimer = window.setTimeout(function () {
      panel.hidden = true;
      panel.classList.remove('is-closing');
      closeTimer = null;
    }, 280);
  }

  function togglePanel() {
    if (panel.hidden || panel.classList.contains('is-closing')) openPanel();
    else closePanel();
  }

  var speakEndpoint = endpoint.replace(/\/chat\/?$/, '/speak');
  var SPEAK_LANGS = ['en', 'bem', 'nya'];
  var speech = { busy: false, button: null, ctx: null, source: null, abort: null };

  function canSpeak(lang) {
    return SPEAK_LANGS.indexOf(String(lang || '').toLowerCase()) >= 0;
  }

  function stopSpeech() {
    if (speech.abort) {
      try { speech.abort.abort(); } catch (e) { /* ignore */ }
      speech.abort = null;
    }
    if (speech.source) {
      var src = speech.source;
      speech.source = null;
      try { src.onended = null; src.stop(0); } catch (e) { /* ignore */ }
    }
    if (speech.button) {
      speech.button.classList.remove('is-loading', 'is-playing', 'is-error');
      speech.button.setAttribute('aria-pressed', 'false');
      speech.button.setAttribute('aria-label', 'Listen');
      setSpeakIcon(speech.button, 'listen');
      speech.button = null;
    }
    speech.busy = false;
  }

  function setSpeakIcon(btn, mode) {
    var svg = mode === 'stop'
      ? '<svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true"><rect x="7" y="7" width="10" height="10" rx="1.5" fill="currentColor"></rect></svg>'
      : '<svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true"><path d="M4 10v4h4l5 4V6L8 10H4z" fill="currentColor"></path><path d="M16.5 8.5a5 5 0 0 1 0 7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"></path></svg>';
    btn.innerHTML = svg;
  }

  function makeSpeakButton(text, lang) {
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'fw-ai-speak';
    btn.setAttribute('aria-label', 'Listen');
    btn.setAttribute('aria-pressed', 'false');
    setSpeakIcon(btn, 'listen');
    btn.addEventListener('click', function () {
      toggleSpeech(btn, text, lang);
    });
    return btn;
  }

  function refreshSpeakButton(bubble, text, lang) {
    var old = bubble.querySelector('.fw-ai-speak');
    if (old && speech.button === old) stopSpeech();
    if (old) old.remove();
    bubble.classList.remove('has-speech');
    if (!canSpeak(lang)) return;
    bubble.classList.add('has-speech');
    bubble.appendChild(makeSpeakButton(text, lang));
  }

  function unlockAudio() {
    var AC = window.AudioContext || window.webkitAudioContext;
    if (!AC) return null;
    if (!speech.ctx) speech.ctx = new AC();
    if (speech.ctx.state === 'suspended') {
      speech.ctx.resume();
    }
    return speech.ctx;
  }

  function toggleSpeech(btn, text, lang) {
    if (speech.busy) {
      if (speech.button === btn) stopSpeech();
      return;
    }
    var ctx = unlockAudio();
    if (!ctx) {
      btn.classList.add('is-error');
      btn.setAttribute('aria-label', 'Could not play this reply');
      return;
    }
    speech.busy = true;
    speech.button = btn;
    btn.classList.add('is-loading');
    btn.classList.remove('is-error', 'is-playing');
    btn.setAttribute('aria-label', 'Preparing audio');
    speech.abort = typeof AbortController !== 'undefined' ? new AbortController() : null;
    var transport = window.FarmWiseApi ? window.FarmWiseApi.send.bind(window.FarmWiseApi) : fetch;
    transport(speakEndpoint, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-TOKEN': csrf,
      },
      credentials: 'same-origin',
      signal: speech.abort ? speech.abort.signal : undefined,
      body: JSON.stringify({
        text: text,
        language: lang,
        _csrf: csrf,
      }),
    }).then(function (res) {
      return res.json().then(function (data) {
        return { ok: res.ok, data: data || {} };
      }).catch(function () {
        return { ok: false, data: {} };
      });
    }).then(function (result) {
      if (!speech.busy || speech.button !== btn) return;
      if (!result.ok || !result.data.ok || !result.data.audio) {
        btn.classList.remove('is-loading');
        btn.classList.add('is-error');
        btn.setAttribute('aria-label', (result.data && result.data.error) || 'Could not play this reply');
        speech.busy = false;
        speech.abort = null;
        return;
      }
      var binary = atob(result.data.audio);
      var bytes = new Uint8Array(binary.length);
      for (var i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
      return ctx.decodeAudioData(bytes.buffer.slice(0)).then(function (buffer) {
        if (!speech.busy || speech.button !== btn) return;
        var source = ctx.createBufferSource();
        source.buffer = buffer;
        source.connect(ctx.destination);
        speech.source = source;
        source.onended = function () {
          if (speech.source !== source) return;
          speech.source = null;
          speech.busy = false;
          btn.classList.remove('is-loading', 'is-playing');
          btn.setAttribute('aria-pressed', 'false');
          btn.setAttribute('aria-label', 'Listen');
          setSpeakIcon(btn, 'listen');
          speech.button = null;
        };
        source.start(0);
        btn.classList.remove('is-loading');
        btn.classList.add('is-playing');
        btn.setAttribute('aria-pressed', 'true');
        btn.setAttribute('aria-label', 'Stop');
        setSpeakIcon(btn, 'stop');
        speech.abort = null;
      });
    }).catch(function (e) {
      if (e && e.name === 'AbortError') return;
      if (speech.button !== btn) return;
      btn.classList.remove('is-loading', 'is-playing');
      btn.classList.add('is-error');
      btn.setAttribute('aria-label', 'Could not play this reply');
      speech.busy = false;
      speech.source = null;
      speech.abort = null;
    });
  }

  function addBubble(role, text, extraClass, lang) {
    var div = document.createElement('div');
    div.className = 'fw-ai-bubble ' + role + (extraClass ? ' ' + extraClass : '');
    var body = document.createElement('div');
    body.className = 'fw-ai-bubble-text';
    body.textContent = text;
    div.appendChild(body);
    if (role === 'bot' && extraClass !== 'err' && canSpeak(lang)) {
      div.classList.add('has-speech');
      div.appendChild(makeSpeakButton(text, lang));
    }
    msgs.appendChild(div);
    msgs.scrollTop = msgs.scrollHeight;
    return div;
  }

  function addErrorBubble(message, retryText) {
    var wrap = document.createElement('div');
    wrap.className = 'fw-ai-bubble bot err';
    var span = document.createElement('span');
    span.textContent = message;
    wrap.appendChild(span);
    if (retryText) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'fw-ai-retry';
      btn.textContent = ui().retry || 'Retry';
      btn.addEventListener('click', function () {
        wrap.remove();
        sendMessage(retryText);
      });
      wrap.appendChild(btn);
    }
    msgs.appendChild(wrap);
    msgs.scrollTop = msgs.scrollHeight;
    return wrap;
  }

  function addLoading() {
    var div = document.createElement('div');
    div.className = 'fw-ai-bubble bot loading';
    div.setAttribute('data-loading', '1');
    var dots = document.createElement('span');
    dots.className = 'fw-ai-dots';
    dots.setAttribute('aria-hidden', 'true');
    dots.innerHTML = '<i></i><i></i><i></i>';
    var label = document.createElement('span');
    label.textContent = ui().thinking || 'Thinking…';
    div.appendChild(dots);
    div.appendChild(label);
    msgs.appendChild(div);
    msgs.scrollTop = msgs.scrollHeight;
    return div;
  }

  function clearLoading() {
    if (!msgs) return;
    msgs.querySelectorAll('[data-loading="1"]').forEach(function (el) {
      el.remove();
    });
  }

  function markGreeted() {
    try {
      sessionStorage.setItem(GREET_KEY, '1');
    } catch (e) { /* ignore */ }
  }

  function wasGreeted() {
    try {
      return sessionStorage.getItem(GREET_KEY) === '1';
    } catch (e) {
      return false;
    }
  }

  function renderHistory() {
    msgs.innerHTML = '';
    applyUiChrome();
    if (!wasGreeted() || history.length === 0) {
      addBubble('bot', greetingText(), '', language);
      markGreeted();
    } else {
      addBubble('bot', greetingText() + ' ' + (ui().welcome_back || ''), '', language);
    }
    if (!aiConfigured) {
      addBubble(
        'bot',
        'Note: GEMINI_API_KEY is not configured on this server yet, so live AI replies may be unavailable until it is set.',
        'err'
      );
    }
    history.forEach(function (turn) {
      var role = turn.role === 'model' ? 'bot' : 'user';
      addBubble(role, turn.text, '', role === 'bot' ? (turn.lang || language) : '');
    });
    // Default prompt chips stay hidden so they never cover the chat.
    if (suggestions) {
      suggestions.hidden = true;
      suggestions.innerHTML = '';
    }
  }

  async function sendMessage(text) {
    text = String(text || '').trim();
    if (!text || busy) return;
    busy = true;
    sendBtn.disabled = true;
    if (suggestions) suggestions.hidden = true;
    lastFailedText = text;

    addBubble('user', text);
    history.push({ role: 'user', text: text });
    persist();

    var loading = addLoading();
    input.value = '';
    input.style.height = 'auto';

    if (abortCtrl) {
      try {
        abortCtrl.abort();
      } catch (e) { /* ignore */ }
    }
    abortCtrl = typeof AbortController !== 'undefined' ? new AbortController() : null;
    var timedOut = false;
    var timer = window.setTimeout(function () {
      timedOut = true;
      if (abortCtrl) abortCtrl.abort();
    }, REQUEST_TIMEOUT_MS);

    try {
      var transport = window.FarmWiseApi ? window.FarmWiseApi.send.bind(window.FarmWiseApi) : fetch;
      var res = await transport(endpoint, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-CSRF-TOKEN': csrf,
        },
        credentials: 'same-origin',
        signal: abortCtrl ? abortCtrl.signal : undefined,
        body: JSON.stringify({
          message: text,
          history: history.slice(0, -1),
          mode: mode,
          language: language,
          include_farm_context: mode === 'auth',
          _csrf: csrf,
        }),
      });
      var data = {};
      try {
        data = await res.json();
      } catch (e) {
        data = {};
      }
      clearLoading();
      if (loading && loading.parentNode) loading.remove();
      if (!res.ok || !data.ok) {
        var err =
          (data && data.error) ||
          (timedOut ? ui().timeout : ui().error) ||
          'Something went wrong. Please try again.';
        addErrorBubble(err, lastFailedText);
        history.pop();
        persist();
      } else {
        var reply = String(data.reply || '').trim() || 'I did not receive a reply.';
        addBubble('bot', reply, '', language);
        history.push({ role: 'model', text: reply, lang: language });
        persist();
        lastFailedText = '';
      }
    } catch (e) {
      clearLoading();
      if (loading && loading.parentNode) loading.remove();
      var msg =
        timedOut || (e && e.name === 'AbortError')
          ? ui().timeout || 'The assistant took too long. Please try again.'
          : ui().network || 'Network error — check your connection and try again.';
      addErrorBubble(msg, lastFailedText);
      history.pop();
      persist();
    } finally {
      window.clearTimeout(timer);
      abortCtrl = null;
      busy = false;
      sendBtn.disabled = false;
      if (input) input.focus();
    }
  }

  function startFresh() {
    stopSpeech();
    history = [];
    persist();
    try {
      sessionStorage.removeItem(GREET_KEY);
    } catch (e) { /* ignore */ }
    renderHistory();
    if (suggestions) {
      suggestions.hidden = true;
      suggestions.innerHTML = '';
    }
  }

  function onLanguageChange() {
    if (!langSelect) return;
    language = normalizeLang(langSelect.value);
    persistLang();
    applyUiChrome();
    // Refresh greeting chrome only — do not wipe conversation history
    var first = msgs.querySelector('.fw-ai-bubble.bot');
    if (first && history.length === 0) {
      var greet = greetingText();
      var textEl = first.querySelector('.fw-ai-bubble-text');
      if (textEl) textEl.textContent = greet;
      refreshSpeakButton(first, greet, language);
    }
  }

  fab.addEventListener('click', togglePanel);
  if (closeBtn) closeBtn.addEventListener('click', closePanel);
  if (clearBtn) clearBtn.addEventListener('click', startFresh);
  if (langSelect) langSelect.addEventListener('change', onLanguageChange);

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

  input.addEventListener('input', function () {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 120) + 'px';
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
    if (e.key === 'Escape' && !panel.hidden && !panel.classList.contains('is-closing')) closePanel();
  });

  applyUiChrome();
  renderHistory();
})();

/**
 * FarmWise shared UI — toast, confirm modal, tabs, collapsible nav,
 * favourites, dependent selects, query-string preselect.
 * Fail-safe: missing elements never throw.
 */
(function (global) {
  'use strict';

  const STORAGE_NAV = 'farmwise.nav.groups';
  const STORAGE_FAV = 'farmwise.nav.favourites';

  function $(sel, root) {
    try { return (root || document).querySelector(sel); } catch (e) { return null; }
  }
  function $all(sel, root) {
    try { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); } catch (e) { return []; }
  }

  /* ---------- Toasts ---------- */
  function ensureToastHost() {
    let host = $('#toast-host');
    if (!host) {
      host = document.createElement('div');
      host.id = 'toast-host';
      host.className = 'toast-host';
      host.setAttribute('aria-live', 'polite');
      host.setAttribute('aria-relevant', 'additions');
      document.body.appendChild(host);
    }
    return host;
  }

  var lastToastKey = '';
  var lastToastAt = 0;

  function toast(message, type) {
    if (!message) return;
    var text = String(message).trim();
    if (!text) return;
    var now = Date.now();
    var key = (type || 'info') + '|' + text;
    // Prevent duplicate popups from double-submit / flash + client validation
    if (key === lastToastKey && (now - lastToastAt) < 2500) return;
    lastToastKey = key;
    lastToastAt = now;
    const host = ensureToastHost();
    const el = document.createElement('div');
    el.className = 'toast toast-' + (type || 'info');
    el.setAttribute('role', 'status');
    el.textContent = text;
    host.appendChild(el);
    window.setTimeout(function () {
      el.classList.add('is-leaving');
      window.setTimeout(function () { el.remove(); }, 280);
    }, 4200);
  }

  function promoteFlashBanners() {
    var banners = $all('.page > .alert').concat($all('[data-flash-toast]'));
    banners.forEach(function (banner) {
      var text = banner.textContent.trim();
      if (!text) {
        banner.remove();
        return;
      }
      var type = banner.classList.contains('ok') ? 'ok'
        : (banner.classList.contains('danger') ? 'danger' : 'info');
      toast(text, type);
      banner.hidden = true;
      banner.classList.add('alert-fallback');
      banner.setAttribute('aria-hidden', 'true');
    });
  }

  /* ---------- Confirm modal ---------- */
  function ensureConfirmModal() {
    let modal = $('#ui-confirm-modal');
    if (modal) return modal;
    modal = document.createElement('div');
    modal.id = 'ui-confirm-modal';
    modal.className = 'ui-modal';
    modal.hidden = true;
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.setAttribute('aria-labelledby', 'ui-confirm-title');
    modal.innerHTML =
      '<div class="ui-modal-backdrop" data-ui-confirm-cancel></div>' +
      '<div class="ui-modal-card">' +
      '  <h3 id="ui-confirm-title">Confirm</h3>' +
      '  <p class="ui-modal-message"></p>' +
      '  <div class="ui-modal-actions">' +
      '    <button type="button" class="btn btn-ghost btn-sm" data-ui-confirm-cancel>Cancel</button>' +
      '    <button type="button" class="btn btn-gold btn-sm" data-ui-confirm-ok>Continue</button>' +
      '  </div>' +
      '</div>';
    document.body.appendChild(modal);
    return modal;
  }

  function confirm(message, options) {
    options = options || {};
    return new Promise(function (resolve) {
      const modal = ensureConfirmModal();
      const title = $('#ui-confirm-title', modal);
      const body = $('.ui-modal-message', modal);
      const okBtn = $('[data-ui-confirm-ok]', modal);
      const cancelBtns = $all('[data-ui-confirm-cancel]', modal);
      if (title) title.textContent = options.title || 'Confirm';
      if (body) body.textContent = message || 'Are you sure?';
      if (okBtn) okBtn.textContent = options.okLabel || 'Continue';
      modal.hidden = false;
      document.body.classList.add('ui-modal-open');
      const prevFocus = document.activeElement;
      if (okBtn) okBtn.focus();

      function close(result) {
        modal.hidden = true;
        document.body.classList.remove('ui-modal-open');
        cancelBtns.forEach(function (b) { b.removeEventListener('click', onCancel); });
        if (okBtn) okBtn.removeEventListener('click', onOk);
        document.removeEventListener('keydown', onKey);
        if (prevFocus && prevFocus.focus) prevFocus.focus();
        resolve(result);
      }
      function onOk() { close(true); }
      function onCancel() { close(false); }
      function onKey(e) {
        if (e.key === 'Escape') onCancel();
        if (e.key === 'Enter') onOk();
      }
      cancelBtns.forEach(function (b) { b.addEventListener('click', onCancel); });
      if (okBtn) okBtn.addEventListener('click', onOk);
      document.addEventListener('keydown', onKey);
    });
  }

  /* ---------- Tabs ---------- */
  function initTabs(root) {
    $all('[data-tabs]', root || document).forEach(function (tabs) {
      const buttons = $all('[data-tab]', tabs);
      const panels = $all('[data-tab-panel]', tabs);
      function activate(id) {
        buttons.forEach(function (btn) {
          const on = btn.getAttribute('data-tab') === id;
          btn.classList.toggle('is-active', on);
          btn.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        panels.forEach(function (panel) {
          const on = panel.getAttribute('data-tab-panel') === id;
          panel.hidden = !on;
        });
      }
      buttons.forEach(function (btn) {
        btn.setAttribute('role', 'tab');
        btn.addEventListener('click', function () {
          activate(btn.getAttribute('data-tab'));
        });
        btn.addEventListener('keydown', function (e) {
          if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
          e.preventDefault();
          const i = buttons.indexOf(btn);
          const next = e.key === 'ArrowRight' ? buttons[i + 1] || buttons[0] : buttons[i - 1] || buttons[buttons.length - 1];
          if (next) { next.focus(); activate(next.getAttribute('data-tab')); }
        });
      });
      const initial = (buttons.find(function (b) { return b.classList.contains('is-active'); }) || buttons[0]);
      if (initial) activate(initial.getAttribute('data-tab'));
    });
  }

  /* ---------- Collapsible nav + favourites ---------- */
  function readJson(key, fallback) {
    try {
      const raw = localStorage.getItem(key);
      return raw ? JSON.parse(raw) : fallback;
    } catch (e) { return fallback; }
  }
  function writeJson(key, value) {
    try { localStorage.setItem(key, JSON.stringify(value)); } catch (e) { /* ignore */ }
  }

  function initCollapsibleNav() {
    const nav = $('.side-nav');
    if (!nav) return;
    const state = readJson(STORAGE_NAV, {});
    const favs = readJson(STORAGE_FAV, []);
    const groups = $all('[data-nav-group]', nav);

    groups.forEach(function (details) {
      const id = details.getAttribute('data-nav-group');
      const hasActive = !!$('.active', details);
      if (hasActive) {
        details.open = true;
      } else if (Object.prototype.hasOwnProperty.call(state, id)) {
        details.open = !!state[id];
      } else {
        details.open = id === 'Overview';
      }
      details.addEventListener('toggle', function () {
        const next = readJson(STORAGE_NAV, {});
        next[id] = details.open;
        writeJson(STORAGE_NAV, next);
      });
    });

    // Favourites row
    let favHost = $('#nav-favourites');
    if (!favHost) {
      favHost = document.createElement('div');
      favHost.id = 'nav-favourites';
      favHost.className = 'nav-favourites';
      favHost.innerHTML = '<p class="nav-label">Pinned</p><div class="nav-fav-list" data-fav-list></div>';
      nav.insertBefore(favHost, nav.firstChild);
    }
    const favList = $('[data-fav-list]', favHost);

    function renderFavs() {
      if (!favList) return;
      favList.innerHTML = '';
      const current = readJson(STORAGE_FAV, []);
      if (!current.length) {
        favList.innerHTML = '<p class="nav-fav-empty muted">Pin modules with the star</p>';
        return;
      }
      current.forEach(function (key) {
        const link = nav.querySelector('[data-nav-key="' + key + '"]');
        if (!link) return;
        const clone = link.cloneNode(true);
        clone.classList.remove('nav-link-row');
        const pin = clone.querySelector('[data-nav-pin]');
        if (pin) pin.remove();
        favList.appendChild(clone);
      });
    }

    $all('[data-nav-pin]', nav).forEach(function (btn) {
      const key = btn.getAttribute('data-nav-pin');
      const on = favs.indexOf(key) !== -1;
      btn.classList.toggle('is-pinned', on);
      btn.setAttribute('aria-pressed', on ? 'true' : 'false');
      btn.setAttribute('aria-label', on ? 'Unpin from favourites' : 'Pin to favourites');
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        let list = readJson(STORAGE_FAV, []);
        const i = list.indexOf(key);
        if (i === -1) list.push(key);
        else list.splice(i, 1);
        writeJson(STORAGE_FAV, list);
        btn.classList.toggle('is-pinned', i === -1);
        btn.setAttribute('aria-pressed', i === -1 ? 'true' : 'false');
        renderFavs();
        if (global.lucide && lucide.createIcons) lucide.createIcons();
      });
    });
    renderFavs();
  }

  /* ---------- Dependent selects ---------- */
  function initDependentSelects(root) {
    $all('select[data-depends-on]', root || document).forEach(function (child) {
      const parentName = child.getAttribute('data-depends-on');
      const parentAttr = child.getAttribute('data-depends-attr') || 'data-parent';
      if (!parentName) return;
      const form = child.closest('form') || document;
      const parent = form.querySelector('[name="' + parentName + '"]');
      if (!parent) return;

      const allOptions = $all('option', child).map(function (opt) {
        return { el: opt, parent: opt.getAttribute(parentAttr) || '', value: opt.value };
      });

      function filter() {
        const pid = String(parent.value || '');
        let keepValue = false;
        allOptions.forEach(function (item) {
          if (!item.value) {
            item.el.hidden = false;
            item.el.disabled = false;
            return;
          }
          const match = !pid || !item.parent || item.parent === pid;
          item.el.hidden = !match;
          item.el.disabled = !match;
          if (match && item.value === child.value) keepValue = true;
        });
        if (!keepValue) child.value = '';
      }
      parent.addEventListener('change', filter);
      filter();
    });
  }

  /* ---------- Query-string preselect (+ optional lock) ---------- */
  function initQueryPreselect(root) {
    let params;
    try { params = new URLSearchParams(global.location.search); } catch (e) { return; }
    if (![...params.keys()].length) return;
    const scope = root || document;
    params.forEach(function (value, key) {
      if (value === '') return;
      const field = scope.querySelector('[name="' + key + '"]');
      if (!field) return;
      if (field.tagName === 'SELECT' || field.tagName === 'INPUT' || field.tagName === 'TEXTAREA') {
        field.value = value;
        field.dispatchEvent(new Event('change', { bubbles: true }));
        if (params.get('_lock') === '1' || field.hasAttribute('data-lock-from-query')) {
          field.setAttribute('readonly', 'readonly');
          if (field.tagName === 'SELECT') {
            field.classList.add('is-locked');
            // Keep value on submit: disable visual but use hidden clone pattern
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = field.name;
            hidden.value = value;
            field.name = field.name + '__locked_ui';
            field.disabled = true;
            field.parentNode.insertBefore(hidden, field);
          }
        }
      }
    });
  }

  /* ---------- Account menu a11y ---------- */
  function initAccountMenu() {
    const accountBtn = $('[data-account-toggle]');
    const accountDrop = $('.account-drop');
    if (!accountBtn || !accountDrop) return;
    accountBtn.addEventListener('click', function (event) {
      event.stopPropagation();
      const open = accountDrop.hasAttribute('hidden');
      accountDrop.toggleAttribute('hidden', !open);
      accountBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    accountDrop.addEventListener('click', function (event) { event.stopPropagation(); });
    document.addEventListener('click', function () {
      accountDrop.setAttribute('hidden', '');
      accountBtn.setAttribute('aria-expanded', 'false');
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !accountDrop.hasAttribute('hidden')) {
        accountDrop.setAttribute('hidden', '');
        accountBtn.setAttribute('aria-expanded', 'false');
        accountBtn.focus();
      }
    });
  }

  function initMobileNav() {
    const toggle = $('[data-nav-toggle]');
    const sidebar = $('#sidebar');
    if (toggle && sidebar) {
      toggle.addEventListener('click', function () {
        const open = sidebar.classList.toggle('open');
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
    }
  }

  function initConfirmForms() {
    $all('form[data-confirm]').forEach(function (form) {
      form.addEventListener('submit', function (e) {
        if (form.dataset.uiConfirmed === '1') return;
        e.preventDefault();
        const msg = form.getAttribute('data-confirm') || 'Are you sure?';
        confirm(msg).then(function (ok) {
          if (!ok) return;
          form.dataset.uiConfirmed = '1';
          HTMLFormElement.prototype.submit.call(form);
        });
      });
    });
  }

  function initNextSteps() {
    const host = $('[data-next-steps]');
    const chips = $('[data-next-chips]');
    if (!host || !chips || !global.IFMS_WORKFLOW || !global.IFMS_RECORD) return;
    const module = IFMS_RECORD.module;
    const record = IFMS_RECORD.record || {};
    const canCreate = IFMS_RECORD.canCreate || [];
    const steps = IFMS_WORKFLOW.stepsFor(module);
    if (!steps.length) return;
    chips.innerHTML = '';
    let shown = 0;
    steps.forEach(function (chip) {
      if (chip.when) {
        const allowed = Object.keys(chip.when).every(function (key) {
          return String(record[key] == null ? '' : record[key]) === String(chip.when[key]);
        });
        if (!allowed) return;
      }
      if (chip.module && canCreate.indexOf(chip.module) === -1 && chip.href == null) return;
      const url = IFMS_WORKFLOW.buildUrl(chip, record);
      if (!url && chip.label.indexOf('Print') === -1) return;
      const a = document.createElement(url ? 'a' : 'button');
      if (url) { a.href = url; } else { a.type = 'button'; }
      a.className = 'next-chip';
      a.textContent = chip.label;
      chips.appendChild(a);
      shown += 1;
    });
    if (shown) host.hidden = false;
  }

  function initInventoryPicker() {
    $all('[data-inventory-picker]').forEach(function (sel) {
      const targetName = sel.getAttribute('data-target-name');
      const hint = document.querySelector('[data-inventory-hint]');
      const form = sel.closest('form');
      const target = form ? form.querySelector('[name="' + targetName + '"]') : null;
      sel.addEventListener('change', function () {
        const opt = sel.selectedOptions[0];
        if (!opt || !opt.value) {
          if (hint) hint.textContent = '';
          return;
        }
        if (target) target.value = opt.value;
        const unit = opt.getAttribute('data-unit') || '';
        const stock = opt.getAttribute('data-stock') || '0';
        const low = opt.getAttribute('data-low') === '1';
        if (hint) {
          hint.textContent = 'Stock: ' + stock + (unit ? ' ' + unit : '') + (low ? ' — low stock' : '');
          hint.classList.toggle('field-error', low);
        }
        const unitField = form && form.querySelector('[name="unit"]');
        if (unitField && unit && !unitField.value) unitField.value = unit;
      });
    });
  }

  function initPrefillFromSelect() {
    $all('select[data-prefill-field]').forEach(function (sel) {
      const targetName = sel.getAttribute('data-prefill-field');
      const attr = sel.getAttribute('data-prefill-attr') || 'data-parent';
      const form = sel.closest('form');
      const target = form ? form.querySelector('[name="' + targetName + '"]') : null;
      if (!target) return;
      sel.addEventListener('change', function () {
        const opt = sel.selectedOptions[0];
        if (!opt) return;
        const v = opt.getAttribute(attr);
        if (v) target.value = v;
      });
    });
  }

  function initColumnToggle() {
    const btn = $('[data-col-toggle]');
    const menu = $('[data-col-menu]');
    if (!btn || !menu) return;
    const table = $('table.data-table');
    const key = 'farmwise.cols.' + (table ? table.getAttribute('data-module') : 'x');
    let state = {};
    try { state = JSON.parse(localStorage.getItem(key) || '{}'); } catch (e) { state = {}; }
    $all('[data-col-key]', menu).forEach(function (cb) {
      const col = cb.getAttribute('data-col-key');
      if (state[col] === false) cb.checked = false;
      function apply() {
        $all('[data-col="' + col + '"]').forEach(function (cell) {
          cell.style.display = cb.checked ? '' : 'none';
        });
        state[col] = cb.checked;
        try { localStorage.setItem(key, JSON.stringify(state)); } catch (e) { /* ignore */ }
      }
      cb.addEventListener('change', apply);
      apply();
    });
    btn.addEventListener('click', function () {
      menu.hidden = !menu.hidden;
    });
  }

  function initUnsavedWarn() {
    $all('form[data-unsaved-warn]').forEach(function (form) {
      let dirty = false;
      form.addEventListener('input', function () { dirty = true; });
      form.addEventListener('submit', function () { dirty = false; });
      window.addEventListener('beforeunload', function (e) {
        if (!dirty) return;
        e.preventDefault();
        e.returnValue = '';
      });
    });
  }

  function initCopyButtons() {
    $all('[data-copy]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        const host = btn.closest('dd, td, div') || btn.parentElement;
        const el = host && host.querySelector('[data-copy-text]');
        const text = el ? el.getAttribute('data-copy-text') || el.textContent : '';
        if (!text) return;
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(text).then(function () { toast('Copied', 'ok'); });
        } else {
          toast(text, 'info');
        }
      });
    });
  }

  function initSettingsPreview() {
    const form = $('[data-settings-preview]');
    if (!form) return;
    const live = $('[data-settings-live]', form);
    const cur = $('[data-currency-select]', form);
    const tz = $('[data-timezone-select]', form);
    function refresh() {
      if (!live || !cur) return;
      const opt = cur.selectedOptions[0];
      const sym = opt ? (opt.getAttribute('data-symbol') || opt.value) : '';
      let when = '';
      try {
        when = new Date().toLocaleString(undefined, {
          timeZone: tz ? tz.value : undefined,
          day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit'
        });
      } catch (e) {
        when = new Date().toLocaleString();
      }
      live.textContent = sym + ' 1,250.00 — ' + when;
    }
    if (cur) cur.addEventListener('change', refresh);
    if (tz) tz.addEventListener('change', refresh);
    refresh();
  }

  function initDocPreview() {
    const modal = $('#doc-preview-modal');
    if (!modal) return;
    $all('[data-doc-preview]').forEach(function (btn) {
      btn.addEventListener('click', function () { modal.hidden = false; });
    });
    $all('[data-close-preview]', modal).forEach(function (btn) {
      btn.addEventListener('click', function () { modal.hidden = true; });
    });
  }

  function initWeightChart() {
    const canvas = $('#weightChart');
    if (!canvas || !global.Chart || !global.IFMS_WEIGHT) return;
    new Chart(canvas, {
      type: 'line',
      data: {
        labels: IFMS_WEIGHT.labels || [],
        datasets: [{ label: 'Weight (kg)', data: IFMS_WEIGHT.data || [], borderColor: '#1c3b2b', tension: 0.3, fill: false }]
      },
      options: { maintainAspectRatio: false, plugins: { legend: { display: false } } }
    });
  }

  function init() {
    promoteFlashBanners();
    initTabs();
    initCollapsibleNav();
    initDependentSelects();
    initQueryPreselect();
    initAccountMenu();
    initMobileNav();
    initConfirmForms();
    initNextSteps();
    initInventoryPicker();
    initPrefillFromSelect();
    initColumnToggle();
    initUnsavedWarn();
    initCopyButtons();
    initSettingsPreview();
    initDocPreview();
    initWeightChart();
  }

  global.IFMS_UI = {
    toast: toast,
    confirm: confirm,
    initTabs: initTabs,
    initDependentSelects: initDependentSelects,
    initQueryPreselect: initQueryPreselect,
    init: init
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(typeof window !== 'undefined' ? window : globalThis);

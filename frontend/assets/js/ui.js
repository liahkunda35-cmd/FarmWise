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

  /* ---------- Searchable Farm / Field selects ---------- */
  function rebuildSearchList(wrap, select, query) {
    const list = wrap.querySelector('[data-search-list]');
    if (!list) return;
    const q = String(query || '').trim().toLowerCase();
    list.innerHTML = '';
    let shown = 0;
    $all('option', select).forEach(function (opt) {
      if (!opt.value || opt.disabled || opt.hidden) return;
      const label = (opt.textContent || '').trim();
      if (q && label.toLowerCase().indexOf(q) === -1) return;
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'searchable-option';
      btn.setAttribute('role', 'option');
      btn.setAttribute('data-value', opt.value);
      btn.textContent = label;
      if (opt.value === select.value) btn.setAttribute('aria-selected', 'true');
      btn.addEventListener('mousedown', function (e) {
        e.preventDefault();
        select.value = opt.value;
        select.dispatchEvent(new Event('change', { bubbles: true }));
        const input = wrap.querySelector('[data-search-input]');
        if (input) input.value = label;
        list.hidden = true;
      });
      list.appendChild(btn);
      shown++;
    });
    if (!shown) {
      const empty = document.createElement('div');
      empty.className = 'searchable-empty muted';
      empty.textContent = q ? 'No matches.' : 'No options available.';
      list.appendChild(empty);
    }
    list.hidden = false;
  }

  function initSearchableSelects(root) {
    $all('select[data-searchable]', root || document).forEach(function (select) {
      if (select.dataset.searchReady === '1') return;
      select.dataset.searchReady = '1';
      const label = select.closest('label') || select.parentElement;
      if (!label) return;
      const wrap = document.createElement('div');
      wrap.className = 'searchable-select';
      wrap.setAttribute('data-searchable-wrap', '1');
      select.parentNode.insertBefore(wrap, select);
      wrap.appendChild(select);
      select.classList.add('searchable-select-native');
      select.setAttribute('tabindex', '-1');
      select.setAttribute('aria-hidden', 'true');

      const input = document.createElement('input');
      input.type = 'text';
      input.className = 'searchable-select-input';
      input.setAttribute('data-search-input', '1');
      input.setAttribute('role', 'combobox');
      input.setAttribute('aria-autocomplete', 'list');
      input.setAttribute('autocomplete', 'off');
      input.setAttribute('spellcheck', 'false');
      input.placeholder = select.options[0] && !select.options[0].value
        ? (select.options[0].textContent || 'Select…')
        : 'Type to search…';
      if (select.required) input.setAttribute('aria-required', 'true');
      wrap.insertBefore(input, select);

      const list = document.createElement('div');
      list.className = 'searchable-select-list';
      list.setAttribute('data-search-list', '1');
      list.setAttribute('role', 'listbox');
      list.hidden = true;
      wrap.appendChild(list);

      function syncFromSelect() {
        const opt = select.options[select.selectedIndex];
        input.value = opt && opt.value ? (opt.textContent || '').trim() : '';
      }
      syncFromSelect();

      input.addEventListener('focus', function () {
        rebuildSearchList(wrap, select, input.value);
      });
      input.addEventListener('input', function () {
        // Typing clears selection until a match is chosen
        if (select.value) {
          select.value = '';
          select.dispatchEvent(new Event('change', { bubbles: true }));
        }
        rebuildSearchList(wrap, select, input.value);
      });
      input.addEventListener('keydown', function (e) {
        const options = $all('.searchable-option', list);
        const current = list.querySelector('.searchable-option.is-active');
        let idx = current ? options.indexOf(current) : -1;
        if (e.key === 'ArrowDown') {
          e.preventDefault();
          list.hidden = false;
          if (!options.length) rebuildSearchList(wrap, select, input.value);
          const next = $all('.searchable-option', list);
          if (!next.length) return;
          if (current) current.classList.remove('is-active');
          idx = Math.min(idx + 1, next.length - 1);
          next[idx].classList.add('is-active');
          next[idx].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'ArrowUp') {
          e.preventDefault();
          if (!options.length) return;
          if (current) current.classList.remove('is-active');
          idx = Math.max(idx - 1, 0);
          options[idx].classList.add('is-active');
          options[idx].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'Enter') {
          if (current) {
            e.preventDefault();
            current.dispatchEvent(new Event('mousedown'));
          }
        } else if (e.key === 'Escape') {
          list.hidden = true;
          syncFromSelect();
        }
      });
      input.addEventListener('blur', function () {
        setTimeout(function () {
          list.hidden = true;
          // Restore label if a value is selected; otherwise match typed label to an option
          if (select.value) {
            syncFromSelect();
            return;
          }
          const typed = input.value.trim().toLowerCase();
          if (!typed) return;
          const match = $all('option', select).find(function (o) {
            return o.value && (o.textContent || '').trim().toLowerCase() === typed;
          });
          if (match) {
            select.value = match.value;
            select.dispatchEvent(new Event('change', { bubbles: true }));
            syncFromSelect();
          } else {
            input.value = '';
          }
        }, 150);
      });
      select.addEventListener('change', syncFromSelect);
      // Expose refresh for dependent field reloads
      select._searchableSync = syncFromSelect;
      select._searchableRebuild = function () {
        rebuildSearchList(wrap, select, input.value);
        syncFromSelect();
      };
    });
    document.addEventListener('click', function (e) {
      $all('[data-searchable-wrap]').forEach(function (wrap) {
        if (!wrap.contains(e.target)) {
          const list = wrap.querySelector('[data-search-list]');
          if (list) list.hidden = true;
        }
      });
    });
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

      let optionCache = $all('option', child).map(function (opt) {
        return {
          value: opt.value,
          label: (opt.textContent || '').trim(),
          parent: opt.getAttribute(parentAttr) || opt.getAttribute('data-farm') || opt.getAttribute('data-parent') || '',
          selected: opt.selected
        };
      });

      function fieldsHint() {
        const label = child.closest('label');
        return label ? label.querySelector('[data-fields-empty-hint]') : null;
      }

      function updateEmptyHint(visibleCount) {
        const hint = fieldsHint();
        if (!hint) return;
        const pid = String(parent.value || '');
        // Only show the empty warning when a farm is chosen and the list is truly empty.
        // Never show it when the user already has a selected field value.
        if (child.value || visibleCount > 0 || !pid) {
          hint.hidden = true;
          hint.setAttribute('hidden', 'hidden');
        } else {
          hint.hidden = false;
          hint.removeAttribute('hidden');
        }
      }

      function ensureFarmSelected() {
        if (parent.value) return String(parent.value);
        const farmOpts = $all('option', parent).filter(function (o) { return !!o.value; });
        if (farmOpts.length === 1) {
          parent.value = farmOpts[0].value;
          if (typeof parent._searchableSync === 'function') parent._searchableSync();
          return String(parent.value);
        }
        // Match typed farm label (searchable input may show name while value is empty)
        const farmWrap = parent.closest('[data-searchable-wrap]');
        const farmInput = farmWrap ? farmWrap.querySelector('[data-search-input]') : null;
        const typed = farmInput ? farmInput.value.trim().toLowerCase() : '';
        if (typed) {
          const match = farmOpts.find(function (o) {
            return (o.textContent || '').trim().toLowerCase() === typed;
          });
          if (match) {
            parent.value = match.value;
            if (typeof parent._searchableSync === 'function') parent._searchableSync();
            return String(parent.value);
          }
        }
        return '';
      }

      function applyFieldLabelMatch() {
        if (child.value) return;
        const wrap = child.closest('[data-searchable-wrap]');
        const input = wrap ? wrap.querySelector('[data-search-input]') : null;
        const typed = input ? input.value.trim().toLowerCase() : '';
        if (!typed) return;
        const match = $all('option', child).find(function (o) {
          return o.value && (o.textContent || '').trim().toLowerCase() === typed;
        });
        if (match) {
          child.value = match.value;
          if (typeof child._searchableSync === 'function') child._searchableSync();
        }
      }

      function renderOptions(pid) {
        const selected = child.value;
        const placeholder = optionCache.find(function (o) { return !o.value; });
        child.innerHTML = '';
        const empty = document.createElement('option');
        empty.value = '';
        empty.textContent = placeholder ? placeholder.label : 'Select…';
        child.appendChild(empty);
        let visible = 0;
        let keep = false;
        optionCache.forEach(function (item) {
          if (!item.value) return;
          const match = !pid || !item.parent || String(item.parent) === String(pid);
          if (!match) return;
          const opt = document.createElement('option');
          opt.value = item.value;
          opt.textContent = item.label;
          if (item.parent) {
            opt.setAttribute(parentAttr, item.parent);
            opt.setAttribute('data-farm', item.parent);
            opt.setAttribute('data-parent', item.parent);
          }
          if (String(item.value) === String(selected)) {
            opt.selected = true;
            keep = true;
          }
          child.appendChild(opt);
          visible++;
        });
        if (!keep) child.value = '';
        applyFieldLabelMatch();
        updateEmptyHint(visible);
        if (typeof child._searchableRebuild === 'function') child._searchableRebuild();
        else if (typeof child._searchableSync === 'function') child._searchableSync();
        return visible;
      }

      function filter() {
        renderOptions(String(parent.value || ''));
      }

      async function refreshFieldsFromServer() {
        if (child.getAttribute('data-fields-select') !== '1') {
          filter();
          return;
        }
        const urlBase = child.getAttribute('data-fields-url');
        const farmId = ensureFarmSelected();
        if (!urlBase) {
          filter();
          return;
        }
        if (!farmId) {
          // Keep any server-rendered field options; just hide the false empty warning.
          updateEmptyHint(optionCache.filter(function (o) { return !!o.value; }).length);
          return;
        }
        try {
          const res = await fetch(urlBase + (urlBase.indexOf('?') >= 0 ? '&' : '?') + 'farm_id=' + encodeURIComponent(farmId), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
          });
          const json = await res.json();
          if (!json || !json.ok || !Array.isArray(json.fields)) {
            filter();
            return;
          }
          const selected = child.value;
          optionCache = [{ value: '', label: 'Select…', parent: '', selected: false }].concat(
            json.fields.map(function (f) {
              return {
                value: String(f.id),
                label: String(f.name || ('Field #' + f.id)),
                parent: String(f.farm_id || farmId),
                selected: String(f.id) === String(selected)
              };
            })
          );
          renderOptions(farmId);
        } catch (e) {
          filter();
        }
      }

      parent.addEventListener('change', function () {
        if (child.getAttribute('data-fields-select') === '1' && parentName === 'farm_id') {
          refreshFieldsFromServer();
        } else {
          filter();
        }
      });
      child.addEventListener('change', function () {
        updateEmptyHint($all('option', child).filter(function (o) { return !!o.value; }).length);
      });

      // Initial load
      if (child.getAttribute('data-fields-select') === '1' && parentName === 'farm_id') {
        ensureFarmSelected();
        refreshFieldsFromServer();
      } else {
        filter();
      }
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

  function markFormBusy(form) {
    const btn = form.querySelector('button[type="submit"], button:not([type])');
    if (!btn || btn.dataset.busy === '1') return;
    btn.dataset.busy = '1';
    btn.disabled = true;
    btn.classList.add('is-busy');
    const action = form.getAttribute('action') || '';
    const original = btn.textContent;
    const customBusy = (btn.getAttribute('data-busy-label') || '').trim();
    const label = customBusy
      || (action.indexOf('/delete/') !== -1
        ? 'Deleting…'
        : (action.indexOf('/approve') !== -1 ? 'Approving…' : (action.indexOf('/reject') !== -1 ? 'Rejecting…' : 'Saving…')));
    btn.textContent = label;
    const row = form.closest('tr');
    if (row && action.indexOf('/delete/') !== -1) row.classList.add('is-leaving');
    // If the server hangs (e.g. cold DB), unlock so the user can retry
    setTimeout(function () {
      if (!btn.isConnected || btn.dataset.busy !== '1') return;
      btn.dataset.busy = '0';
      btn.disabled = false;
      btn.classList.remove('is-busy');
      btn.textContent = original || 'Save';
      if (global.IFMS && IFMS.toast) {
        IFMS.toast('Save is taking too long. Check your connection and try again.', 'error');
      }
    }, 12000);
  }

  function initBusyForms() {
    document.addEventListener('submit', function (event) {
      const form = event.target;
      if (!form || !form.getAttribute || form.dataset.uiConfirmed === 'pending') return;
      if (form.hasAttribute('data-confirm') && form.dataset.uiConfirmed !== '1') return;
      markFormBusy(form);
    }, true);
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
          markFormBusy(form);
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

  function copyText(text) {
    text = String(text || '').trim();
    if (!text) return;
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function () { toast('Copied', 'ok'); }).catch(function () {
        toast(text, 'info');
      });
      return;
    }
    toast(text, 'info');
  }

  function initCopyButtons() {
    $all('[data-copy]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        const host = btn.closest('dd, td, div') || btn.parentElement;
        const el = host && host.querySelector('[data-copy-text]');
        const text = el ? el.getAttribute('data-copy-text') || el.textContent : '';
        copyText(text);
      });
    });
    // Elements that carry the text themselves (credential chips / Copy all)
    $all('[data-copy-text]').forEach(function (el) {
      if (el.matches('[data-copy]')) return;
      el.style.cursor = el.tagName === 'BUTTON' || el.tagName === 'A' ? '' : 'pointer';
      el.title = el.title || 'Click to copy';
      el.addEventListener('click', function (e) {
        if (el.tagName === 'A') return;
        e.preventDefault();
        copyText(el.getAttribute('data-copy-text') || el.textContent);
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

  function initNavigationFeedback() {
    document.addEventListener('click', function (event) {
      const link = event.target && event.target.closest ? event.target.closest('a[href]') : null;
      if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
      if (link.target && link.target !== '_self') return;
      const href = link.getAttribute('href') || '';
      if (href === '' || href.charAt(0) === '#' || href.indexOf('javascript:') === 0) return;
      document.body.classList.add('is-navigating');
    }, true);
  }

  function init() {
    initNavigationFeedback();
    promoteFlashBanners();
    initTabs();
    initCollapsibleNav();
    initSearchableSelects();
    initDependentSelects();
    initQueryPreselect();
    initAccountMenu();
    initMobileNav();
    initBusyForms();
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
    initSearchableSelects: initSearchableSelects,
    initQueryPreselect: initQueryPreselect,
    init: init
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(typeof window !== 'undefined' ? window : globalThis);

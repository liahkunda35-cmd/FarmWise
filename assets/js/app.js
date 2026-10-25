document.addEventListener('DOMContentLoaded', () => {
  if (window.lucide) lucide.createIcons();

  // Landing / auth hero slideshow — ~5s auto-fade with controls
  document.querySelectorAll('[data-hero-slideshow]').forEach((root) => {
    const slides = Array.from(root.querySelectorAll('.hero-slide'));
    if (slides.length < 2) return;

    const dots = Array.from(root.querySelectorAll('[data-hero-dot]'));
    const prevBtn = root.querySelector('[data-hero-prev]');
    const nextBtn = root.querySelector('[data-hero-next]');
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const intervalMs = Math.max(3000, parseInt(root.getAttribute('data-interval') || '5000', 10) || 5000);

    let index = slides.findIndex((s) => s.classList.contains('is-active'));
    if (index < 0) index = 0;
    let timer = null;
    let hovering = false;

    const syncUi = () => {
      slides.forEach((slide, i) => {
        slide.classList.toggle('is-active', i === index);
      });
      dots.forEach((dot, i) => {
        const on = i === index;
        dot.classList.toggle('is-active', on);
        dot.setAttribute('aria-selected', on ? 'true' : 'false');
      });
    };

    const goTo = (next) => {
      index = ((next % slides.length) + slides.length) % slides.length;
      syncUi();
    };

    const tick = () => goTo(index + 1);

    const stop = () => {
      if (!timer) return;
      window.clearInterval(timer);
      timer = null;
    };

    const start = () => {
      if (reduceMotion || hovering || document.hidden || timer) return;
      timer = window.setInterval(tick, intervalMs);
    };

    const restart = () => {
      stop();
      start();
    };

    if (prevBtn) {
      prevBtn.addEventListener('click', () => {
        goTo(index - 1);
        restart();
      });
    }
    if (nextBtn) {
      nextBtn.addEventListener('click', () => {
        goTo(index + 1);
        restart();
      });
    }
    dots.forEach((dot) => {
      dot.addEventListener('click', () => {
        const target = parseInt(dot.getAttribute('data-hero-dot') || '0', 10);
        goTo(target);
        restart();
      });
    });

    root.addEventListener('mouseenter', () => {
      hovering = true;
      stop();
    });
    root.addEventListener('mouseleave', () => {
      hovering = false;
      start();
    });

    document.addEventListener('visibilitychange', () => {
      if (document.hidden) stop();
      else start();
    });
    window.addEventListener('pagehide', stop);

    syncUi();
    start();
  });

  // Public landing mobile nav
  const pubToggle = document.querySelector('[data-pub-nav-toggle]');
  const pubNav = document.querySelector('[data-pub-nav]');
  if (pubToggle && pubNav) {
    pubToggle.addEventListener('click', () => {
      const open = pubNav.classList.toggle('is-open');
      pubToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      pubToggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
    });
    pubNav.querySelectorAll('a').forEach((link) => {
      link.addEventListener('click', () => {
        pubNav.classList.remove('is-open');
        pubToggle.setAttribute('aria-expanded', 'false');
        pubToggle.setAttribute('aria-label', 'Open menu');
      });
    });
  }

  // Footer / page links that open the AI assistant
  document.querySelectorAll('[data-open-ai]').forEach((el) => {
    el.addEventListener('click', (e) => {
      e.preventDefault();
      const fab = document.getElementById('fw-ai-fab');
      if (fab && fab.getAttribute('aria-expanded') !== 'true') {
        fab.click();
      } else if (fab) {
        fab.focus();
      }
    });
  });

  // Point Leaflet default markers at local vendor images
  if (window.L && L.Icon && L.Icon.Default) {
    const iconBase = (window.IFMS_BASE || '') + '/assets/vendor/leaflet/images/';
    L.Icon.Default.mergeOptions({
      iconUrl: iconBase + 'marker-icon.png',
      iconRetinaUrl: iconBase + 'marker-icon-2x.png',
      shadowUrl: iconBase + 'marker-shadow.png'
    });
  }

  const greetHost = document.querySelector('[data-greet-name]');
  if (greetHost) {
    const hour = new Date().getHours();
    const mode = greetHost.getAttribute('data-greet-mode');
    const greeting = mode === 'welcome'
      ? 'Welcome back'
      : (hour < 12 ? 'Good morning' : (hour < 17 ? 'Good afternoon' : 'Good evening'));
    const name = greetHost.getAttribute('data-greet-name') || '';
    const timeEl = greetHost.querySelector('.greet-time');
    const nameEl = greetHost.querySelector('.greet-name');
    const line = greetHost.querySelector('.greet-line');
    if (mode === 'welcome' && timeEl && nameEl) {
      timeEl.textContent = greeting;
      nameEl.textContent = name ? ', ' + name : '';
    } else if (timeEl && nameEl) {
      const nameText = ', ' + name;
      timeEl.textContent = '';
      nameEl.textContent = '';
      if (line) line.classList.add('is-typing');
      let i = 0;
      const typeNext = () => {
        if (i < greeting.length) {
          timeEl.textContent += greeting.charAt(i);
          i += 1;
          window.setTimeout(typeNext, 42);
          return;
        }
        const n = i - greeting.length;
        if (n < nameText.length) {
          nameEl.textContent += nameText.charAt(n);
          i += 1;
          window.setTimeout(typeNext, 42);
          return;
        }
        if (line) line.classList.remove('is-typing');
      };
      typeNext();
    }
  }

  const charts = window.IFMS_CHARTS;
  if (charts && window.Chart) {
    const gold = '#c4a35a';
    const forest = '#1c3b2b';
    const finance = document.getElementById('financeChart');
    if (finance) {
      new Chart(finance, {
        type: 'line',
        data: {
          labels: charts.months,
          datasets: [
            { label: 'Revenue', data: charts.income, borderColor: gold, backgroundColor: 'rgba(196,163,90,.18)', fill: true, tension: .35 },
            { label: 'Expenses', data: charts.expense, borderColor: forest, backgroundColor: 'rgba(28,59,43,.12)', fill: true, tension: .35 }
          ]
        },
        options: {
          plugins: { legend: { display: true } },
          scales: { y: { beginAtZero: true } },
          onClick: () => { if (charts.financeUrl) window.location.href = charts.financeUrl; }
        }
      });
    }
    const stage = document.getElementById('stageChart');
    if (stage) {
      new Chart(stage, {
        type: 'doughnut',
        data: {
          labels: (charts.stages || []).map(s => s.label || 'n/a'),
          datasets: [{ data: (charts.stages || []).map(s => s.total), backgroundColor: ['#1c3b2b', '#c4a35a', '#8a6a1f', '#5d6b62', '#d8c7a8'] }]
        },
        options: {
          plugins: { legend: { position: 'bottom' } },
          onClick: (evt, els, chart) => {
            if (!els.length || !charts.stageUrl) return;
            const label = chart.data.labels[els[0].index] || '';
            window.location.href = charts.stageUrl + (label ? ('?q=' + encodeURIComponent(label)) : '');
          }
        }
      });
    }
  }

  const health = document.getElementById('healthChart');
  if (health && window.IFMS_HEALTH && window.Chart) {
    const cfg = window.IFMS_HEALTH;
    const palette = ['#1B3B2B', '#78945A', '#D9B66F'];
    const isBar = cfg.type === 'bar';
    new Chart(health, {
      type: isBar ? 'bar' : 'line',
      data: {
        labels: (cfg.labels || []).map((d) => {
          const raw = String(d);
          if (/^\d{4}-\d{2}-\d{2}/.test(raw)) {
            const dt = new Date(raw.slice(0, 10) + 'T00:00:00');
            return dt.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
          }
          return raw;
        }),
        datasets: (cfg.series || []).map((s, i) => ({
          label: s.label,
          data: s.data,
          borderColor: palette[i % palette.length],
          backgroundColor: isBar ? palette[i % palette.length] : 'rgba(120,148,90,.12)',
          fill: !isBar,
          tension: .35,
          spanGaps: true,
          pointRadius: 3,
          pointBackgroundColor: palette[i % palette.length],
          borderWidth: 2
        }))
      },
      options: {
        maintainAspectRatio: false,
        onClick: () => { if (cfg.href) window.location.href = cfg.href; },
        plugins: { legend: { display: true, position: 'top', labels: { boxWidth: 10, font: { size: 12 } } } },
        scales: {
          y: { beginAtZero: true, title: { display: !!cfg.unit, text: cfg.unit || '' }, grid: { color: 'rgba(229,221,204,.9)' } },
          x: { grid: { display: false } }
        }
      }
    });
  }

  // Legacy map block — skipped when FarmMap module is present
  if (window.IFMS_MAP && window.L && !window.FarmMap) {
    const map = L.map('farm-map');
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; OpenStreetMap' }).addTo(map);
    const pts = [];
    (IFMS_MAP.farms || []).forEach(f => {
      if (f && f.latitude && f.longitude) {
        const m = L.marker([f.latitude, f.longitude]).addTo(map).bindPopup(`<strong>${f.name}</strong><br>${f.location || ''}<br><small>Farm location</small>`);
        pts.push(m.getLatLng());
      }
    });
    (IFMS_MAP.fields || []).forEach(f => {
      if (f.latitude && f.longitude) {
        const m = L.circleMarker([f.latitude, f.longitude], { color: '#c4a35a', fillColor: '#1c3b2b', fillOpacity: .7, radius: 8 })
          .addTo(map).bindPopup(`<strong>${f.name}</strong><br>${f.size_hectares || 0} ha · ${f.soil_type || ''}<br><small>Field location</small>`);
        pts.push(m.getLatLng());
      }
    });
    if (pts.length) {
      map.fitBounds(pts, { padding: [30, 30] });
    } else {
      map.fitWorld();
    }

    const gpsStatus = document.getElementById('map-gps-status');
    const setGpsStatus = (msg) => {
      if (gpsStatus) gpsStatus.textContent = msg;
    };
    const gpsIcon = L.divIcon({
      className: 'gps-user-marker',
      html: '<span class="gps-pulse"></span><span class="gps-dot"></span>',
      iconSize: [22, 22],
      iconAnchor: [11, 11]
    });
    let userMarker = null;
    let accuracyCircle = null;
    let centeredOnUser = false;

    const showUserLocation = (lat, lng, accuracy) => {
      const latlng = L.latLng(lat, lng);
      if (!userMarker) {
        userMarker = L.marker(latlng, { icon: gpsIcon, zIndexOffset: 1200, title: 'Your current location' })
          .addTo(map)
          .bindPopup('<strong>You are here</strong><br>Current GPS location');
        accuracyCircle = L.circle(latlng, {
          radius: Math.max(accuracy || 30, 20),
          color: '#2563eb',
          fillColor: '#3b82f6',
          fillOpacity: 0.14,
          weight: 1
        }).addTo(map);
      } else {
        userMarker.setLatLng(latlng);
        accuracyCircle.setLatLng(latlng);
        if (accuracy) accuracyCircle.setRadius(Math.max(accuracy, 20));
      }
      if (!centeredOnUser) {
        map.setView(latlng, 16);
        centeredOnUser = true;
        userMarker.openPopup();
      }
      setGpsStatus('Blue pin: your current GPS location. Other markers are farms and fields.');
    };

    const geoError = (err) => {
      const code = err && err.code;
      if (code === 1) {
        setGpsStatus('Location permission is required to show your current position. Farm and field markers still work.');
      } else if (code === 2) {
        setGpsStatus('GPS is unavailable, so your current position cannot be shown.');
      } else if (code === 3) {
        setGpsStatus('Location request timed out. Your current position could not be found.');
      } else {
        setGpsStatus('Your current position could not be determined.');
      }
    };

    if (!navigator.geolocation) {
      setGpsStatus('This browser does not support GPS location, so your current position cannot be shown.');
    } else {
      setGpsStatus('Requesting location permission to show where you are…');
      navigator.geolocation.watchPosition(
        (pos) => showUserLocation(pos.coords.latitude, pos.coords.longitude, pos.coords.accuracy),
        geoError,
        { enableHighAccuracy: true, maximumAge: 4000, timeout: 12000 }
      );
    }

    const searchForm = document.getElementById('map-search');
    if (searchForm) {
      searchForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const q = document.getElementById('map-q').value.trim();
        if (!q) return;
        const res = await fetch((window.IFMS_BASE || '') + '/geocode?q=' + encodeURIComponent(q)).catch(() => null);
        const json = res ? await res.json() : null;
        const first = json?.results?.[0];
        const status = document.getElementById('map-search-status');
        if (!first) {
          if (status) status.textContent = 'No OpenStreetMap result for that search.';
          return;
        }
        const lat = parseFloat(first.lat);
        const lon = parseFloat(first.lon);
        map.setView([lat, lon], 14);
        L.marker([lat, lon]).addTo(map).bindPopup(first.display_name || q).openPopup();
        if (status) status.textContent = first.display_name || q;
      });
    }
  }

  document.querySelectorAll('[data-toggle-password]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const input = btn.parentElement?.querySelector('input');
      if (!input) return;
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.setAttribute('aria-pressed', show ? 'true' : 'false');
      btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
  });

  const passwordInput = document.querySelector('[data-password-input]');
  if (passwordInput) {
    const confirmInput = document.querySelector('[data-password-confirm]');
    const hint = document.querySelector('[data-password-hint]');
    const matchHint = document.querySelector('[data-password-match-hint]');
    const reqBox = document.querySelector('[data-password-reqs]');
    const form = passwordInput.closest('form');
    const passwordWrap = passwordInput.closest('.password-wrap');
    const confirmWrap = confirmInput ? confirmInput.closest('.password-wrap') : null;
    let passwordTouched = false;
    let confirmTouched = false;

    const missingRules = (value) => {
      const missing = [];
      if (value.length < 8) missing.push('8+ characters');
      if (!/[A-Z]/.test(value)) missing.push('an uppercase letter');
      if (!/[a-z]/.test(value)) missing.push('a lowercase letter');
      if (!/\d/.test(value)) missing.push('a number');
      if (!/[^A-Za-z0-9]/.test(value)) missing.push('a special character');
      return missing;
    };
    const compactMessage = (missing) => {
      if (!missing.length) return '';
      if (missing.length === 1) return 'Must include ' + missing[0] + '.';
      return 'Must include ' + missing.slice(0, -1).join(', ') + ', and ' + missing[missing.length - 1] + '.';
    };
    const setMessage = (el, text) => {
      if (!el) return;
      if (!text) {
        el.hidden = true;
        el.textContent = '';
        return;
      }
      el.hidden = false;
      el.textContent = text;
    };
    const markInvalid = (wrap, on) => {
      if (!wrap) return;
      wrap.classList.toggle('is-invalid', on);
      const input = wrap.querySelector('input') || wrap;
      input.classList.toggle('is-invalid', on);
      if (input.setAttribute) input.setAttribute('aria-invalid', on ? 'true' : 'false');
    };

    const update = (fromSubmit = false) => {
      const value = passwordInput.value;
      const missing = missingRules(value);
      const showPasswordMsg = passwordTouched || fromSubmit;
      const passwordError = showPasswordMsg && value.length > 0 ? compactMessage(missing) : '';
      setMessage(hint, passwordError);
      markInvalid(passwordWrap, Boolean(passwordError));

      if (confirmInput) {
        const showMatchMsg = confirmTouched || fromSubmit;
        const mismatch = confirmInput.value !== value;
        const matchError = showMatchMsg && mismatch && (confirmInput.value.length > 0 || fromSubmit)
          ? 'Passwords do not match.'
          : '';
        setMessage(matchHint, matchError);
        markInvalid(confirmWrap, Boolean(matchError));
      }

      if (reqBox) {
        let allMet = missing.length === 0;
        const checks = {
          length: value.length >= 8,
          upper: /[A-Z]/.test(value),
          lower: /[a-z]/.test(value),
          number: /\d/.test(value),
          special: /[^A-Za-z0-9]/.test(value),
          match: confirmInput ? value.length > 0 && value === confirmInput.value : true
        };
        reqBox.querySelectorAll('[data-rule]').forEach((li) => {
          const key = li.getAttribute('data-rule');
          const ok = checks[key] || false;
          li.classList.toggle('met', ok);
          li.classList.toggle('fail', value.length > 0 && !ok);
          if (!ok) allMet = false;
        });
        form?.querySelectorAll('[type="submit"]').forEach((btn) => {
          btn.disabled = value.length > 0 && !allMet;
        });
      }
    };

    passwordInput.addEventListener('input', () => {
      passwordTouched = true;
      update();
    });
    passwordInput.addEventListener('blur', () => {
      passwordTouched = true;
      update();
    });
    if (confirmInput) {
      confirmInput.addEventListener('input', () => {
        confirmTouched = true;
        update();
      });
      confirmInput.addEventListener('blur', () => {
        confirmTouched = true;
        update();
      });
    }
    form?.addEventListener('submit', (event) => {
      passwordTouched = true;
      confirmTouched = true;
      update(true);
      const passwordBad = missingRules(passwordInput.value).length > 0 || !passwordInput.value;
      const confirmBad = confirmInput && confirmInput.value !== passwordInput.value;
      if (passwordBad || confirmBad) {
        event.preventDefault();
        (passwordBad ? passwordInput : confirmInput).focus();
      }
    });
  }

  // GPS picker moved to assets/js/farm-map.js

  const palette = ['#1c3b2b', '#c4a35a', '#8a6a1f', '#5d6b62', '#d8c7a8', '#2a5340'];
  if (window.IFMS_REPORT_CHART && window.Chart) {
    const cfg = window.IFMS_REPORT_CHART;
    new Chart(document.getElementById('reportChart'), {
      type: cfg.type || 'bar',
      data: {
        labels: cfg.labels,
        datasets: [{ label: 'Value', data: cfg.data, backgroundColor: cfg.type === 'doughnut' ? palette : '#1c3b2b' }]
      },
      options: { plugins: { legend: { display: cfg.type === 'doughnut' } }, scales: cfg.type === 'doughnut' ? {} : { y: { beginAtZero: true } } }
    });
  }
  const signupRole = document.querySelector('[data-signup-role]');
  const joinField = document.querySelector('[data-join-code-field]');
  if (signupRole && joinField) {
    const syncJoin = () => {
      const needsCode = signupRole.value === 'manager' || signupRole.value === 'worker';
      joinField.hidden = !needsCode;
      const input = joinField.querySelector('input');
      if (input) input.required = needsCode;
    };
    signupRole.addEventListener('change', syncJoin);
    syncJoin();
  }

  document.querySelectorAll('[data-sensitive-user-form]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      if (form.dataset.uiConfirmed === '1') return;
      const role = form.querySelector('[name="role"]');
      const status = form.querySelector('[name="status"]');
      const flag = form.querySelector('[data-confirm-sensitive]');
      const roleChanged = role && role.dataset.original && role.value !== role.dataset.original;
      const statusChanged = status && status.dataset.original && status.value !== status.dataset.original;
      if (roleChanged || statusChanged) {
        event.preventDefault();
        const ask = (window.IFMS_UI && IFMS_UI.confirm)
          ? IFMS_UI.confirm('You are changing this user\'s role or account status. Continue?', { title: 'Sensitive change' })
          : Promise.resolve(window.confirm('You are changing this user\'s role or account status. Continue?'));
        ask.then((ok) => {
          if (!ok) return;
          if (flag) flag.value = '1';
          form.dataset.uiConfirmed = '1';
          HTMLFormElement.prototype.submit.call(form);
        });
        return;
      }
      if (flag) flag.value = '1';
    });
  });

  document.querySelectorAll('[data-restore-form]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      if (form.dataset.uiConfirmed === '1') return;
      const typed = (form.querySelector('[name="confirm_restore"]')?.value || '').trim();
      if (typed !== 'RESTORE') {
        event.preventDefault();
        if (window.IFMS_UI && IFMS_UI.toast) {
          IFMS_UI.toast('Type RESTORE to confirm. Restoring replaces current farm data after a safety backup is created.', 'danger');
        } else {
          window.alert('Type RESTORE to confirm. Restoring replaces current farm data after a safety backup is created.');
        }
        return;
      }
      event.preventDefault();
      const ask = (window.IFMS_UI && IFMS_UI.confirm)
        ? IFMS_UI.confirm('Restore this backup? Current data will be replaced. A safety copy of the current database will be created first.', { title: 'Restore backup', okLabel: 'Restore' })
        : Promise.resolve(window.confirm('Restore this backup? Current data will be replaced. A safety copy of the current database will be created first.'));
      ask.then((ok) => {
        if (!ok) return;
        form.dataset.uiConfirmed = '1';
        HTMLFormElement.prototype.submit.call(form);
      });
    });
  });

  if (window.IFMS_WEATHER_CHART && window.Chart) {
    const w = window.IFMS_WEATHER_CHART;
    new Chart(document.getElementById('forecastChart'), {
      type: 'bar',
      data: {
        labels: (w.labels || []).map((d) => d.slice(5)),
        datasets: [
          { type: 'line', label: 'Max °C', data: w.max, borderColor: '#c4a35a', tension: .3, yAxisID: 'y' },
          { type: 'line', label: 'Min °C', data: w.min, borderColor: '#1c3b2b', tension: .3, yAxisID: 'y' },
          { type: 'bar', label: 'Rain mm', data: w.rain, backgroundColor: 'rgba(28,59,43,.35)', yAxisID: 'y1' }
        ]
      },
      options: {
        scales: {
          y: { position: 'left', title: { display: true, text: '°C' } },
          y1: { position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'mm' } }
        }
      }
    });
  }
});

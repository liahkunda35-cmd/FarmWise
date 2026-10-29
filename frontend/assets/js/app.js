document.addEventListener('DOMContentLoaded', () => {
  if (window.lucide) lucide.createIcons();

  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const isCoarsePointer = window.matchMedia('(hover: none), (pointer: coarse)').matches;

  // Landing motion: hero float, scroll reveals, counters, light parallax
  if (document.body.classList.contains('public-body')) {
    const revealEls = Array.from(document.querySelectorAll('[data-reveal], [data-reveal-stagger]'));
    document.querySelectorAll('[data-reveal-footer] [data-reveal]').forEach((el, i) => {
      el.style.setProperty('--footer-i', String(i));
    });
    document.querySelectorAll('[data-reveal-stagger]').forEach((group) => {
      Array.from(group.children).forEach((child, i) => {
        child.style.setProperty('--stagger', String(i));
      });
    });

    const showReveal = (el) => el.classList.add('is-inview');
    if (reduceMotion) {
      revealEls.forEach(showReveal);
    } else if ('IntersectionObserver' in window && revealEls.length) {
      const io = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
          if (!entry.isIntersecting) return;
          showReveal(entry.target);
          io.unobserve(entry.target);
        });
      }, { rootMargin: '0px 0px -4% 0px', threshold: 0.08 });
      revealEls.forEach((el) => io.observe(el));
      // Safety: never leave section images/text permanently hidden
      window.setTimeout(() => revealEls.forEach(showReveal), 3500);
    } else {
      revealEls.forEach(showReveal);
    }
  }

  if (document.body.classList.contains('public-body') && !reduceMotion) {
    document.querySelectorAll('.hero-glass-float').forEach((el) => {
      const onEnd = (event) => {
        if (event.animationName !== 'hero-slide-right') return;
        el.classList.add('is-floating');
        el.removeEventListener('animationend', onEnd);
      };
      el.addEventListener('animationend', onEnd);
      window.setTimeout(() => {
        if (!el.classList.contains('is-floating')) el.classList.add('is-floating');
      }, 1400);
    });

    // Hero dual-line typewriter, then fade description / CTAs / highlights
    const dualHost = document.querySelector('[data-typewriter-dual]');
    const legacyHost = document.querySelector('[data-typewriter]');
    const typeFollow = document.querySelector('[data-typewriter-follow]');
    const revealAfterType = () => {
      document.querySelectorAll('[data-hero-fade].is-waiting, .hero-actions.is-waiting, .hero-meta.is-waiting, .hero-highlights.is-waiting, .hero-lede.is-waiting').forEach((el) => {
        el.classList.remove('is-waiting');
        el.classList.add('is-ready');
      });
    };
    const typeInto = (target, text, speed, done) => {
      let i = 0;
      target.textContent = '';
      const step = () => {
        target.textContent = text.slice(0, i);
        i += 1;
        if (i <= text.length) window.setTimeout(step, speed);
        else if (typeof done === 'function') done();
      };
      step();
    };
    if (dualHost) {
      const line1 = dualHost.getAttribute('data-line1') || '';
      const line2 = dualHost.getAttribute('data-line2') || '';
      const t1 = dualHost.querySelector('[data-type-line="1"]');
      const t2 = dualHost.querySelector('[data-type-line="2"]');
      const speed = 62;
      const finish = () => {
        dualHost.classList.add('is-typed');
        window.setTimeout(revealAfterType, 280);
      };
      if (t1 && t2) {
        typeInto(t1, line1, speed, () => {
          window.setTimeout(() => typeInto(t2, line2, speed, finish), 220);
        });
      } else {
        finish();
      }
    } else if (legacyHost) {
      const headline = legacyHost.getAttribute('data-typewriter-text') || '';
      const target = legacyHost.querySelector('.typewriter-text') || legacyHost;
      typeInto(target, headline, 28, () => {
        legacyHost.classList.add('is-typed');
        if (typeFollow) {
          const lede = typeFollow.getAttribute('data-typewriter-text') || '';
          const followTarget = typeFollow.querySelector('.typewriter-text') || typeFollow;
          typeInto(followTarget, lede, 14, revealAfterType);
        } else {
          revealAfterType();
        }
      });
    } else {
      revealAfterType();
    }

    const countEls = Array.from(document.querySelectorAll('[data-count]'));
    const animateCount = (el) => {
      const target = parseFloat(el.getAttribute('data-count') || '0');
      if (!Number.isFinite(target)) return;
      const decimals = parseInt(el.getAttribute('data-count-decimals') || '0', 10) || 0;
      const prefix = el.getAttribute('data-count-prefix') || '';
      const suffix = el.getAttribute('data-count-suffix') || '';
      const format = (value) => prefix + (decimals ? value.toFixed(decimals) : String(Math.round(value))) + suffix;
      el.textContent = format(0);
      const duration = 900;
      const start = performance.now();
      const tick = (now) => {
        const t = Math.min(1, (now - start) / duration);
        const eased = 1 - Math.pow(1 - t, 3);
        el.textContent = format(target * eased);
        if (t < 1) requestAnimationFrame(tick);
      };
      requestAnimationFrame(tick);
    };
    if (countEls.length && 'IntersectionObserver' in window) {
      const countIo = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
          if (!entry.isIntersecting) return;
          entry.target.querySelectorAll('[data-count]').forEach(animateCount);
          countIo.unobserve(entry.target);
        });
      }, { threshold: 0.35 });
      const glass = document.querySelector('.glass-dash');
      if (glass) countIo.observe(glass);
      else countEls.forEach(animateCount);
    }

    if (!isCoarsePointer && window.matchMedia('(min-width: 901px)').matches) {
      const parallaxNodes = Array.from(document.querySelectorAll('[data-parallax] img'));
      let ticking = false;
      const updateParallax = () => {
        ticking = false;
        const vh = window.innerHeight || 1;
        parallaxNodes.forEach((img) => {
          const rect = img.getBoundingClientRect();
          if (rect.bottom < 0 || rect.top > vh) return;
          const progress = (rect.top + rect.height / 2 - vh / 2) / vh;
          img.style.setProperty('--parallax-y', (progress * -18).toFixed(2) + 'px');
        });
      };
      const onScroll = () => {
        if (ticking) return;
        ticking = true;
        requestAnimationFrame(updateParallax);
      };
      window.addEventListener('scroll', onScroll, { passive: true });
      updateParallax();
    }
  } else if (document.body.classList.contains('public-body') && reduceMotion) {
    const dualHost = document.querySelector('[data-typewriter-dual]');
    const typeHost = document.querySelector('[data-typewriter]');
    const typeFollow = document.querySelector('[data-typewriter-follow]');
    if (dualHost) {
      const t1 = dualHost.querySelector('[data-type-line="1"]');
      const t2 = dualHost.querySelector('[data-type-line="2"]');
      if (t1) t1.textContent = dualHost.getAttribute('data-line1') || '';
      if (t2) t2.textContent = dualHost.getAttribute('data-line2') || '';
      dualHost.classList.add('is-typed');
    }
    if (typeHost) {
      const t = typeHost.querySelector('.typewriter-text');
      if (t) t.textContent = typeHost.getAttribute('data-typewriter-text') || '';
      typeHost.classList.add('is-typed');
    }
    if (typeFollow) {
      const t = typeFollow.querySelector('.typewriter-text');
      if (t) t.textContent = typeFollow.getAttribute('data-typewriter-text') || '';
    }
    document.querySelectorAll('[data-hero-fade].is-waiting, .hero-actions.is-waiting, .hero-meta.is-waiting, .hero-highlights.is-waiting, .hero-lede.is-waiting').forEach((el) => {
      el.classList.remove('is-waiting');
      el.classList.add('is-ready');
    });
  }

  // Hero slideshow — automatic crossfade every 10s on landing; cleans up on pagehide
  document.querySelectorAll('[data-hero-slideshow]').forEach((root) => {
    const slides = Array.from(root.querySelectorAll('.hero-slide'));
    if (slides.length < 2) return;

    const autoOnly = root.getAttribute('data-auto-only') === '1';
    const dots = Array.from(root.querySelectorAll('[data-hero-dot]'));
    const prevBtn = root.querySelector('[data-hero-prev]');
    const nextBtn = root.querySelector('[data-hero-next]');
    const intervalMs = Math.max(3000, parseInt(root.getAttribute('data-interval') || '10000', 10) || 10000);

    let index = slides.findIndex((s) => s.classList.contains('is-active'));
    if (index < 0) index = 0;
    let timer = null;

    const syncUi = () => {
      slides.forEach((slide, i) => {
        const on = i === index;
        if (on && !slide.classList.contains('is-active') && !reduceMotion) {
          slide.classList.remove('is-active');
          void slide.offsetWidth;
        }
        slide.classList.toggle('is-active', on);
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
      // Keep autoplay running while scrolling; only pause when the tab is hidden
      if (document.hidden || timer) return;
      timer = window.setInterval(tick, intervalMs);
    };

    const restart = () => {
      stop();
      start();
    };

    if (!autoOnly) {
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
    }

    const onVisibility = () => {
      if (document.hidden) stop();
      else start();
    };
    document.addEventListener('visibilitychange', onVisibility);
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
        const res = window.FarmWiseApi
          ? await window.FarmWiseApi.geocode(q).catch(() => null)
          : await fetch((window.IFMS_BASE || '') + '/geocode?q=' + encodeURIComponent(q)).catch(() => null);
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
  // Farm join code is temporarily removed from registration.
  // Managers/workers join later from the dashboard.

  // Shared client-side form validation (Zambia mobile + username/email/role/login)
  // ZICTA mobile NDCs: Airtel 57/77/97, MTN 56/76/96, Zamtel 55/75/95
  const ZM_MOBILE_PREFIXES = ['55', '56', '57', '75', '76', '77', '95', '96', '97'];
  const PHONE_MSG = 'Enter a valid Zambian mobile number for Airtel, MTN or Zamtel.';
  const REQUIRED_MSG = 'Please complete all required fields.';
  const USERNAME_MSG = 'Username must be 3–30 characters: letters, numbers, underscores, periods, or hyphens only (no spaces).';
  const USERNAME_SPACE_MSG = 'Usernames cannot contain spaces.';

  const phoneHasInvalidChars = (value) => /[^\d+\s-]/.test(String(value || '').trim());

  const normalizePhoneClient = (value) => {
    const raw = String(value || '').trim();
    if (!raw) return '';
    if (phoneHasInvalidChars(raw)) return raw;
    const digits = raw.replace(/\D/g, '');
    if (/^0\d{9}$/.test(digits)) return '+260' + digits.slice(1);
    if (/^[1-9]\d{8}$/.test(digits)) return '+260' + digits;
    if (/^260\d{9}$/.test(digits)) return '+' + digits;
    return raw.startsWith('+') ? '+' + digits : digits;
  };

  const validPhoneClient = (value) => {
    if (!String(value || '').trim()) return false;
    if (phoneHasInvalidChars(value)) return false;
    const n = normalizePhoneClient(value);
    const match = /^\+260(\d{9})$/.exec(n);
    if (!match) return false;
    return ZM_MOBILE_PREFIXES.indexOf(match[1].slice(0, 2)) !== -1;
  };

  // Match RegistrationService::normalizeUsername (trim + lowercase only)
  const normalizeUsernameClient = (value) => String(value || '').trim().toLowerCase();
  const usernameErrorClient = (value) => {
    const normalized = normalizeUsernameClient(value);
    if (!normalized) return 'Username is required.';
    if (/\s/.test(normalized)) return USERNAME_SPACE_MSG;
    if (normalized.length < 3 || normalized.length > 30) return USERNAME_MSG;
    if (!/^[a-z0-9._-]+$/.test(normalized)) {
      return 'Username can only contain letters, numbers, underscores, periods, and hyphens.';
    }
    return '';
  };

  const showFormToast = (message, type) => {
    if (window.IFMS_UI && typeof IFMS_UI.toast === 'function') {
      IFMS_UI.toast(message, type || 'danger');
    }
  };

  const validEmailClient = (value) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(value || '').trim());

  const fieldErrorEl = (field) => {
    const name = field.getAttribute('name');
    if (!name) return null;
    const form = field.closest('form');
    if (!form) return null;
    return form.querySelector('[data-field-error="' + name + '"]');
  };

  const setFieldError = (field, message) => {
    field.classList.toggle('is-invalid', !!message);
    const wrap = field.closest('.password-wrap');
    if (wrap) wrap.classList.toggle('is-invalid', !!message);
    const msg = fieldErrorEl(field);
    if (msg) {
      if (message) {
        msg.textContent = message;
        msg.hidden = false;
      } else {
        msg.textContent = '';
        msg.hidden = true;
      }
    }
  };

  const validateField = (field) => {
    if (!field || field.disabled || field.type === 'hidden' || field.readOnly) return true;

    const rule = field.getAttribute('data-validate') || '';
    const type = (field.getAttribute('type') || field.tagName || '').toLowerCase();
    // Passwords must not be trimmed or rewritten.
    const isPassword = type === 'password' || rule === 'password';
    const rawValue = String(field.value || '');
    const raw = isPassword ? rawValue : rawValue.trim();
    const required = field.required || rule === 'required' || rule === 'email' || rule === 'phone'
      || rule === 'username' || rule === 'role' || rule === 'login-identity' || rule === 'password';
    let message = '';

    if (type === 'file' && required && !(field.files && field.files.length)) {
      message = 'Choose a file to upload.';
    } else if (required && raw === '' && type !== 'file') {
      if (rule === 'phone') message = PHONE_MSG;
      else if (rule === 'password' || isPassword) message = 'Password is required.';
      else if (rule === 'login-identity') message = 'Username or email is required.';
      else if (rule === 'username') message = 'Username is required.';
      else if (rule === 'role') message = 'Choose a valid account type.';
      else message = 'This field is required.';
    } else if (raw !== '') {
      if (rule === 'login-identity') {
        // Accept email or existing username without over-restricting login.
        if (raw.includes('@')) {
          if (!validEmailClient(raw)) message = 'Enter a valid username or email.';
        } else if (/\s/.test(raw)) {
          message = USERNAME_SPACE_MSG;
        }
      } else if ((rule === 'email' || rule === 'email-optional' || type === 'email') && !validEmailClient(raw)) {
        message = 'Enter a valid email address.';
      } else if ((rule === 'phone' || rule === 'phone-optional' || type === 'tel') && !validPhoneClient(raw)) {
        message = PHONE_MSG;
      } else if (rule === 'username') {
        message = usernameErrorClient(raw);
      } else if (type === 'number') {
        if (!Number.isFinite(Number(raw))) message = 'Enter a valid number.';
        else {
          const min = field.min !== '' ? Number(field.min) : NaN;
          const max = field.max !== '' ? Number(field.max) : NaN;
          if (!Number.isNaN(min) && Number(raw) < min) message = 'Value must be at least ' + min + '.';
          if (!Number.isNaN(max) && Number(raw) > max) message = 'Value cannot exceed ' + max + '.';
        }
      } else if (type === 'date' && Number.isNaN(Date.parse(raw))) {
        message = 'Enter a valid date.';
      }
      const maxLen = field.getAttribute('maxlength');
      if (!message && maxLen && raw.length > Number(maxLen)) message = 'This value is too long.';
      if (!message && rule === 'role') {
        const allowed = ['owner', 'manager', 'worker', 'agronomist', 'accountant'];
        if (allowed.indexOf(raw) === -1) message = 'Choose a valid account type.';
      }
    }

    if (!message && type === 'file' && field.files && field.files[0]) {
      const file = field.files[0];
      const maxBytes = 5 * 1024 * 1024;
      const allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
      if (file.size > maxBytes) message = 'Files must be 5 MB or smaller.';
      else if (file.type && !allowed.includes(file.type)) message = 'Allowed types: JPG, PNG, GIF, WEBP and PDF.';
    }

    setFieldError(field, message);
    return !message;
  };

  document.querySelectorAll('form[data-validate-form]').forEach((form) => {
    let submitting = false;
    const fields = () => Array.from(form.querySelectorAll('input, select, textarea')).filter((el) => {
      if (el.type === 'hidden' || el.type === 'submit' || el.type === 'button' || el.type === 'checkbox' || el.type === 'radio') return false;
      // Skip password-policy fields on activation/profile; include login password when marked.
      if ((el.name === 'password_confirm' || el.name === 'current_password') && el.getAttribute('data-validate') !== 'password') return false;
      if (el.name === 'password' && el.getAttribute('data-validate') !== 'password' && el.hasAttribute('data-password-input')) return false;
      return !!el.name;
    });

    fields().forEach((field) => {
      field.addEventListener('blur', () => validateField(field));
      field.addEventListener('input', () => {
        if (field.classList.contains('is-invalid') || field.getAttribute('aria-invalid') === 'true') validateField(field);
      });
      field.addEventListener('change', () => {
        if (field.classList.contains('is-invalid')) validateField(field);
      });
    });

    form.addEventListener('submit', (event) => {
      if (submitting) {
        event.preventDefault();
        return;
      }
      let ok = true;
      let firstBad = null;
      let firstMessage = '';
      fields().forEach((field) => {
        if (!validateField(field)) {
          ok = false;
          if (!firstBad) {
            firstBad = field;
            const err = fieldErrorEl(field);
            firstMessage = (err && err.textContent) || REQUIRED_MSG;
          }
        }
      });
      if (!ok) {
        event.preventDefault();
        if (firstBad) firstBad.focus();
        showFormToast(firstMessage || REQUIRED_MSG, 'danger');
        return;
      }
      submitting = true;
      const btn = form.querySelector('button[type="submit"], input[type="submit"]');
      if (btn) {
        btn.disabled = true;
        window.setTimeout(() => {
          btn.disabled = false;
          submitting = false;
        }, 8000);
      }
    });
  });

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

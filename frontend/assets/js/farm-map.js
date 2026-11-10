/**
 * Shared FarmWise map module — main map, form picker, mini-maps.
 * Fail-safe if Leaflet / coords / GeoJSON are missing.
 */
(function (global) {
  'use strict';

  const FALLBACK_CENTER = [-13.1, 27.8]; // Zambia
  const FALLBACK_ZOOM = 6;

  function base() {
    return (global.IFMS_BASE || '').replace(/\/$/, '');
  }

  function parseNum(v) {
    const n = parseFloat(v);
    return Number.isFinite(n) ? n : null;
  }

  function statusColor(status) {
    const s = String(status || '').toLowerCase();
    if (s === 'fallow') return '#b08c3e';
    if (s === 'reserved') return '#6a8fad';
    return '#1c3b2b'; // active / default
  }

  /** SVG location pin — avoids broken Leaflet PNG markers. */
  function locationPinIcon(color) {
    const fill = color || '#c9920f';
    const svg =
      '<svg xmlns="http://www.w3.org/2000/svg" width="32" height="42" viewBox="0 0 32 42" aria-hidden="true">' +
      '<path fill="' + fill + '" stroke="#1a1408" stroke-width="1.2" d="M16 1.5c-7.2 0-13 5.8-13 13 0 9.8 13 25.5 13 25.5S29 24.3 29 14.5c0-7.2-5.8-13-13-13z"/>' +
      '<circle cx="16" cy="14.5" r="5.2" fill="#fff8e8"/>' +
      '</svg>';
    return L.divIcon({
      className: 'fw-loc-pin',
      html: svg,
      iconSize: [32, 42],
      iconAnchor: [16, 40],
      popupAnchor: [0, -36]
    });
  }

  function makeLayers(map) {
    const street = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; OpenStreetMap'
    });
    const satellite = L.tileLayer(
      'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
      { maxZoom: 19, attribution: 'Tiles &copy; Esri' }
    );
    street.addTo(map);
    L.control.layers({ Street: street, Satellite: satellite }, null, { position: 'topright' }).addTo(map);

    let banner = null;
    function showTileError() {
      if (banner) return;
      banner = document.createElement('div');
      banner.className = 'map-tile-banner';
      banner.textContent = "Map tiles couldn't load. Check your connection.";
      (map.getContainer().parentElement || document.body).appendChild(banner);
    }
    street.on('tileerror', showTileError);
    satellite.on('tileerror', showTileError);
    return { street, satellite };
  }

  function extractGeo(notes) {
    if (!notes) return null;
    const m = String(notes).match(/\[\[GEOJSON\]\](\{[\s\S]*\})\s*$/);
    if (!m) return null;
    try { return JSON.parse(m[1]); } catch (e) { return null; }
  }

  function areaHa(latlngs) {
    if (!L.GeometryUtil || !L.GeometryUtil.geodesicArea) {
      // Approximate planar area
      if (!latlngs || latlngs.length < 3) return 0;
      let area = 0;
      for (let i = 0; i < latlngs.length; i++) {
        const j = (i + 1) % latlngs.length;
        const yi = latlngs[i].lat * Math.PI / 180;
        const xi = latlngs[i].lng * Math.PI / 180;
        const yj = latlngs[j].lat * Math.PI / 180;
        const xj = latlngs[j].lng * Math.PI / 180;
        area += (xj - xi) * (2 + Math.sin(yi) + Math.sin(yj));
      }
      area = Math.abs(area) * 6378137 * 6378137 / 2;
      return area / 10000;
    }
    return L.GeometryUtil.geodesicArea(latlngs) / 10000;
  }

  function writeBoundary(textarea, geojson, userNotes) {
    if (!textarea) return;
    const notes = (userNotes != null ? userNotes : String(textarea.dataset.userNotes || '')).trim();
    const line = '[[GEOJSON]]' + JSON.stringify(geojson);
    textarea.value = notes ? (notes + '\n' + line) : line;
  }

  /* ---------- Main farm map page ---------- */
  function initMainMap(el, data) {
    if (!global.L || !el) return null;
    const map = L.map(el).setView(FALLBACK_CENTER, FALLBACK_ZOOM);
    makeLayers(map);

    const layers = {
      farms: L.layerGroup().addTo(map),
      fields: L.layerGroup().addTo(map),
      irrigation: L.layerGroup(),
      scouting: L.layerGroup(),
      me: L.layerGroup().addTo(map)
    };

    const bounds = [];
    const fieldIndex = [];

    (data.farms || []).forEach(function (f) {
      const lat = parseNum(f.latitude); const lng = parseNum(f.longitude);
      if (lat == null || lng == null) return;
      const m = L.marker([lat, lng], { icon: locationPinIcon('#c9920f') });
      const href = base() + '/module/farms/view/' + f.id;
      m.bindPopup('<strong>' + escapeHtml(f.name || 'Farm') + '</strong><br>' +
        escapeHtml(f.location || '') +
        '<br><a href="' + href + '">Open farm</a>');
      m.addTo(layers.farms);
      bounds.push([lat, lng]);
    });

    (data.fields || []).forEach(function (f) {
      const lat = parseNum(f.latitude); const lng = parseNum(f.longitude);
      const geo = extractGeo(f.boundary_notes);
      const color = statusColor(f.status);
      const href = base() + '/module/fields/view/' + f.id;
      const popup = '<strong>' + escapeHtml(f.name || 'Field') + '</strong><br>' +
        escapeHtml((f.size_hectares || 0) + ' ha · ' + (f.status || '')) +
        '<br><a href="' + href + '">Open field</a>' +
        ' · <a href="' + base() + '/module/plantings/create?field_id=' + f.id + '">Add planting</a>' +
        ' · <a href="' + base() + '/module/pest_scouting/create?field_id=' + f.id + '">Log scouting</a>';

      let layer = null;
      if (geo) {
        try {
          layer = L.geoJSON(geo, { style: { color: color, weight: 2, fillOpacity: 0.25 } });
          layer.bindPopup(popup);
          layer.addTo(layers.fields);
          try { bounds.push(...layer.getBounds()); } catch (e) { /* ignore */ }
        } catch (e) { layer = null; }
      }
      if (!layer && lat != null && lng != null) {
        layer = L.circleMarker([lat, lng], { color: color, fillColor: color, fillOpacity: 0.7, radius: 8 });
        layer.bindPopup(popup);
        layer.addTo(layers.fields);
        bounds.push([lat, lng]);
      }
      if (layer) {
        fieldIndex.push({ id: f.id, name: f.name, status: f.status, layer: layer, lat: lat, lng: lng });
      }

      // Irrigation / scouting markers at field centre when provided
      if (lat != null && lng != null) {
        if (f._irrigation) {
          L.circleMarker([lat, lng], { color: '#355f7a', radius: 5 }).bindPopup('Irrigation @ ' + escapeHtml(f.name || '')).addTo(layers.irrigation);
        }
        if (f._scouting) {
          L.circleMarker([lat, lng], { color: '#8d2d2d', radius: 5 }).bindPopup('Scouting @ ' + escapeHtml(f.name || '')).addTo(layers.scouting);
        }
      }
    });

    const empty = document.querySelector('[data-map-empty]');
    if (bounds.length) {
      try {
        const b = L.latLngBounds(bounds);
        if (b.isValid()) map.fitBounds(b.pad(0.15));
      } catch (e) { map.setView(FALLBACK_CENTER, FALLBACK_ZOOM); }
      if (empty) empty.hidden = true;
    } else {
      map.setView(FALLBACK_CENTER, FALLBACK_ZOOM);
      if (empty) empty.hidden = false;
    }

    // Layer toggles
    document.querySelectorAll('[data-map-layer]').forEach(function (input) {
      const key = input.getAttribute('data-map-layer');
      if (!layers[key]) return;
      input.addEventListener('change', function () {
        if (input.checked) map.addLayer(layers[key]);
        else map.removeLayer(layers[key]);
      });
    });

    // Side list
    const list = document.querySelector('[data-field-list]');
    if (list) {
      list.innerHTML = '';
      fieldIndex.forEach(function (f) {
        const li = document.createElement('button');
        li.type = 'button';
        li.className = 'map-field-item';
        li.textContent = (f.name || ('Field #' + f.id)) + (f.status ? ' · ' + f.status : '');
        li.addEventListener('click', function () {
          if (f.layer.getBounds) {
            try { map.fitBounds(f.layer.getBounds().pad(0.3)); } catch (e) { /* ignore */ }
          } else if (f.lat != null) {
            map.setView([f.lat, f.lng], 16);
          }
          if (f.layer.openPopup) f.layer.openPopup();
        });
        list.appendChild(li);
      });
    }

    // My location + optional farm pin save
    let userMarker = null; let accuracyCircle = null; let draftMarker = null;
    const locateBtn = document.querySelector('[data-map-locate]');
    const manualBtn = document.querySelector('[data-map-manual]');
    const manualPanel = document.querySelector('[data-manual-panel]');
    const saveForm = document.getElementById('map-save-form');
    const status = document.getElementById('map-gps-status');
    const labelEl = document.getElementById('map-location-label');

    function showSave(lat, lng, label) {
      if (!data.canSave || !saveForm) return;
      const latInput = document.getElementById('map-save-lat');
      const lngInput = document.getElementById('map-save-lng');
      const labInput = document.getElementById('map-save-label');
      if (latInput) latInput.value = Number(lat).toFixed(7);
      if (lngInput) lngInput.value = Number(lng).toFixed(7);
      if (labInput) labInput.value = label || '';
      saveForm.hidden = false;
      if (!draftMarker) {
        draftMarker = L.marker([lat, lng], { draggable: true, icon: locationPinIcon('#c9920f') }).addTo(map);
        draftMarker.on('dragend', function () {
          const p = draftMarker.getLatLng();
          showSave(p.lat, p.lng, labInput ? labInput.value : '');
          if (status) status.textContent = 'Marker moved — confirm and save if this is the farm.';
        });
      } else {
        draftMarker.setLatLng([lat, lng]);
      }
    }

    function onPos(pos, centre) {
      const lat = pos.coords.latitude; const lng = pos.coords.longitude;
      const acc = pos.coords.accuracy || 30;
      if (!userMarker) {
        userMarker = L.circleMarker([lat, lng], { radius: 8, color: '#2563eb', fillColor: '#3b82f6', fillOpacity: 0.9 }).addTo(layers.me);
        accuracyCircle = L.circle([lat, lng], { radius: Math.max(acc, 20), color: '#2563eb', fillOpacity: 0.12, weight: 1 }).addTo(layers.me);
      } else {
        userMarker.setLatLng([lat, lng]);
        accuracyCircle.setLatLng([lat, lng]).setRadius(Math.max(acc, 20));
      }
      if (centre) {
        map.setView([lat, lng], 16);
        showSave(lat, lng, '');
        if (status) status.textContent = 'Device location found. Confirm before saving as the farm location.';
        fetch(base() + '/geocode?lat=' + encodeURIComponent(lat) + '&lon=' + encodeURIComponent(lng))
          .then(function (r) { return r.json(); })
          .then(function (json) {
            const row = json && json.results && json.results[0];
            const name = row && (row.display_name || row.farmer_label);
            if (name) {
              if (labelEl) labelEl.textContent = name;
              showSave(lat, lng, name);
              if (status) status.textContent = 'Near: ' + name + '. Save only if this is your farm.';
            }
          }).catch(function () { /* ignore */ });
      }
    }
    function geoErr(err) {
      if (!status) return;
      if (!window.isSecureContext && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
        status.textContent = 'GPS needs localhost or HTTPS. Choose location manually.';
      } else if (err && err.code === 1) {
        status.textContent = 'Location permission denied. Choose location manually.';
      } else {
        status.textContent = 'Could not read GPS. Choose location manually.';
      }
      if (manualPanel) manualPanel.hidden = false;
    }
    if (locateBtn) {
      locateBtn.addEventListener('click', function () {
        if (!navigator.geolocation) return geoErr();
        if (status) status.textContent = 'Getting current location…';
        navigator.geolocation.getCurrentPosition(function (pos) { onPos(pos, true); }, geoErr, { enableHighAccuracy: true, timeout: 12000 });
      });
    }
    if (manualBtn) {
      manualBtn.addEventListener('click', function () {
        if (manualPanel) manualPanel.hidden = false;
        if (status) status.textContent = 'Search a place or click the map to place a marker.';
        const q = document.getElementById('map-q');
        if (q) q.focus();
      });
    }
    const cancelSave = document.querySelector('[data-map-cancel-save]');
    if (cancelSave) {
      cancelSave.addEventListener('click', function () {
        if (saveForm) saveForm.hidden = true;
        if (draftMarker) { map.removeLayer(draftMarker); draftMarker = null; }
        if (status) status.textContent = 'Save cancelled.';
      });
    }
    map.on('click', function (e) {
      if (manualPanel && !manualPanel.hidden) {
        showSave(e.latlng.lat, e.latlng.lng, '');
        if (status) status.textContent = 'Pin placed. Drag to adjust, then save.';
      }
    });
    if (data.autoLocate && navigator.geolocation) {
      navigator.geolocation.getCurrentPosition(function (pos) {
        onPos(pos, !bounds.length);
      }, geoErr, { enableHighAccuracy: true, timeout: 10000 });
    } else if (!bounds.length) {
      if (status) status.textContent = 'Choose location manually to place your farm.';
      if (manualPanel) manualPanel.hidden = false;
    } else if (status) {
      status.textContent = 'Showing saved farm location.';
    }

    initSearch(map, function (lat, lon, r) {
      map.setView([lat, lon], 14);
      const name = (r && (r.farmer_label || r.display_name)) || '';
      showSave(lat, lon, name);
      if (labelEl && name) labelEl.textContent = name;
      if (status) status.textContent = 'Selected: ' + (name || (lat + ', ' + lon));
    });
    return map;
  }

  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  /* ---------- Debounced geocode search ---------- */
  let lastGeocode = 0;
  function initSearch(map, onPick) {
    const input = document.getElementById('map-q') || document.getElementById('gps-q');
    const results = document.getElementById('map-search-results') || document.getElementById('gps-search-results');
    const status = document.getElementById('map-search-status') || document.getElementById('gps-status');
    const form = document.getElementById('map-search');
    if (!input) return;

    async function runSearch() {
      const q = input.value.trim();
      if (q.length < 3) {
        if (status) status.textContent = 'Type at least 3 characters.';
        return;
      }
      const now = Date.now();
      if (now - lastGeocode < 1000) {
        if (status) status.textContent = 'Please wait a moment…';
        return;
      }
      lastGeocode = now;
      if (status) status.textContent = 'Searching…';
      if (results) { results.hidden = false; results.innerHTML = '<p class="muted">Loading…</p>'; }
      try {
        const res = window.FarmWiseApi
          ? await window.FarmWiseApi.geocode(q)
          : await fetch(base() + '/geocode?q=' + encodeURIComponent(q));
        const json = await res.json();
        const list = json && json.results ? json.results : [];
        if (!list.length) {
          if (status) status.textContent = 'No results.';
          if (results) results.innerHTML = '<p class="muted">No results.</p>';
          return;
        }
        if (status) status.textContent = list.length + ' result(s) — pick one.';
        if (results) {
          results.innerHTML = '';
          list.forEach(function (r) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'gps-result';
            btn.textContent = r.display_name || (r.lat + ', ' + r.lon);
            btn.addEventListener('click', function () {
              const lat = parseFloat(r.lat); const lon = parseFloat(r.lon);
              if (onPick) onPick(lat, lon, r);
              else {
                map.setView([lat, lon], 14);
                L.marker([lat, lon]).addTo(map).bindPopup(r.display_name || q).openPopup();
              }
              results.hidden = true;
              if (status) status.textContent = r.display_name || q;
            });
            results.appendChild(btn);
          });
        }
      } catch (e) {
        if (status) status.textContent = 'Search failed.';
      }
    }

    let t = null;
    input.addEventListener('input', function () {
      clearTimeout(t);
      t = setTimeout(runSearch, 400);
    });
    if (form) {
      form.addEventListener('submit', function (e) { e.preventDefault(); runSearch(); });
    }
    const btn = document.getElementById('gps-search');
    if (btn) btn.addEventListener('click', function (e) { e.preventDefault(); runSearch(); });
  }

  /* ---------- Form picker ---------- */
  function initPicker(el) {
    if (!global.L || !el) return null;
    const latName = el.getAttribute('data-lat-input') || 'latitude';
    const lngName = el.getAttribute('data-lng-input') || 'longitude';
    const form = el.closest('form') || document;
    const wizard = el.getAttribute('data-mode') === 'farm-wizard' || !!form.querySelector('[data-farm-location-wizard]');
    const latInput = form.querySelector('[name="' + latName + '"]') || document.getElementById('field-lat') || form.querySelector('#field-latitude');
    const lngInput = form.querySelector('[name="' + lngName + '"]') || document.getElementById('field-lng') || form.querySelector('#field-longitude');
    const precName = el.getAttribute('data-precision-input') || 'location_precision';
    const labelName = el.getAttribute('data-label-input') || 'location_label';
    const precInput = form.querySelector('[name="' + precName + '"]') || form.querySelector('#field-location_precision');
    const labelInput = form.querySelector('[name="' + labelName + '"]') || form.querySelector('#field-location_label');
    const areaName = el.getAttribute('data-area-input');
    const areaInput = areaName ? form.querySelector('[name="' + areaName + '"]') : null;
    const boundaryName = el.getAttribute('data-boundary-input');
    let boundaryInput = boundaryName ? form.querySelector('[name="' + boundaryName + '"]') : null;
    if (!boundaryInput && boundaryName) boundaryInput = document.getElementById('field-' + boundaryName);
    const statusEl = document.getElementById('gps-status') || form.querySelector('[data-loc-status]');
    const badgeEl = form.querySelector('[data-loc-badge]');
    const labelTextEl = form.querySelector('[data-loc-label-text]');
    const showLat = form.querySelector('[data-loc-show-lat]');
    const showLng = form.querySelector('[data-loc-show-lng]');
    const results = document.getElementById('gps-search-results');

    const farmLat = parseNum(el.getAttribute('data-farm-lat'));
    const farmLng = parseNum(el.getAttribute('data-farm-lng'));
    const startLat = parseNum(latInput && latInput.value) ?? farmLat ?? FALLBACK_CENTER[0];
    const startLng = parseNum(lngInput && lngInput.value) ?? farmLng ?? FALLBACK_CENTER[1];
    const zoom = (latInput && latInput.value) || farmLat != null ? 15 : FALLBACK_ZOOM;
    const map = L.map(el).setView([startLat, startLng], zoom);
    makeLayers(map);
    const snipSavesBoundary = el.getAttribute('data-snip-boundary') === '1';

    function setBadge(precision) {
      if (!badgeEl) return;
      badgeEl.className = 'loc-badge loc-badge-' + (precision === 'confirmed' ? 'ok' : (precision === 'approximate' ? 'warn' : 'muted'));
      if (precision === 'confirmed') badgeEl.textContent = 'Pin set';
      else if (precision === 'approximate') badgeEl.textContent = 'Approximate place';
      else badgeEl.textContent = 'Optional — you can save without a pin';
    }

    function syncLocationSummary(label) {
      const locInput = form.querySelector('[name="location"]') || document.getElementById('field-location');
      if (locInput && label) locInput.value = label;
    }

    function setPrecision(level) {
      if (precInput) precInput.value = level;
      setBadge(level);
    }

    function setLabel(text) {
      if (labelInput) labelInput.value = text || '';
      if (labelTextEl) labelTextEl.textContent = text || '';
    }

    let marker = null;
    let snipActive = false;
    let snipDragging = false;
    let snipStart = null;
    let snipRect = null;
    const snipBanner = form.querySelector('[data-snip-banner]');
    const snipBtn = form.querySelector('[data-loc-snip]');

    function focusHere(lat, lng, zoomLevel) {
      const z = zoomLevel || 17;
      map.invalidateSize();
      if (map.flyTo) map.flyTo([lat, lng], z, { duration: 0.6 });
      else map.setView([lat, lng], z);
      setTimeout(function () { map.invalidateSize(); }, 250);
    }

    function setPin(lat, lng, opts) {
      opts = opts || {};
      if (marker) marker.setLatLng([lat, lng]);
      else {
        marker = L.marker([lat, lng], { draggable: true }).addTo(map);
        marker.on('dragend', function () {
          const p = marker.getLatLng();
          if (latInput) latInput.value = p.lat.toFixed(7);
          if (lngInput) lngInput.value = p.lng.toFixed(7);
          if (showLat) showLat.value = latInput.value;
          if (showLng) showLng.value = lngInput.value;
          setPrecision('confirmed');
          if (statusEl) statusEl.textContent = 'Pin updated.';
        });
      }
      if (latInput) latInput.value = Number(lat).toFixed(7);
      if (lngInput) lngInput.value = Number(lng).toFixed(7);
      if (showLat) showLat.value = latInput.value;
      if (showLng) showLng.value = lngInput.value;
      if (opts.label) {
        setLabel(opts.label);
        syncLocationSummary(opts.label);
      }
      if (opts.precision) setPrecision(opts.precision);
      if (opts.focus) {
        focusHere(lat, lng, opts.zoom || 17);
      }
    }

    function reverseLabel(lat, lng, doneMsg) {
      const reverseUrl = base() + '/geocode?lat=' + encodeURIComponent(lat) + '&lon=' + encodeURIComponent(lng);
      fetch(reverseUrl).then(function (response) { return response.json(); }).then(function (json) {
        const row = json && json.results && json.results[0];
        if (!row) return;
        const addr = row.address || {};
        const town = addr.village || addr.town || addr.city || addr.suburb || '';
        const district = addr.county || addr.state_district || addr.city_district || '';
        const province = addr.state || '';
        const country = addr.country || '';
        const townInput = form.querySelector('[name="town"]');
        const districtInput = form.querySelector('[name="district"]');
        if (townInput && town) townInput.value = town;
        if (districtInput && district) districtInput.value = district;
        ['province', 'country'].forEach(function (name) {
          const select = form.querySelector('[name="' + name + '"]');
          const wanted = name === 'province' ? province : country;
          if (!select || !wanted || !select.options) return;
          Array.from(select.options).some(function (option) {
            if (!option.value) return false;
            if (wanted.toLowerCase().indexOf(option.value.toLowerCase()) !== -1) {
              select.value = option.value;
              return true;
            }
            return false;
          });
        });
        const label = row.display_name || [town, district, province, country].filter(Boolean).join(', ');
        if (label) {
          setLabel(label);
          syncLocationSummary(label);
        }
        if (statusEl) statusEl.textContent = doneMsg || label;
      }).catch(function () { /* pin already set */ });
    }

    function useDeviceLocation(fromAuto) {
      if (!navigator.geolocation) {
        if (statusEl) statusEl.textContent = 'GPS not available — use Select area or search.';
        return;
      }
      if (!window.isSecureContext && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
        if (statusEl) statusEl.textContent = 'GPS needs HTTPS or localhost — use Select area or search.';
        return;
      }
      if (statusEl) statusEl.textContent = fromAuto ? 'Finding your current GPS location…' : 'Getting your location…';
      navigator.geolocation.getCurrentPosition(function (pos) {
        const lat = pos.coords.latitude;
        const lng = pos.coords.longitude;
        setPin(lat, lng, { precision: 'confirmed', focus: true, zoom: 18 });
        if (statusEl) statusEl.textContent = 'Your current location — drag the pin if needed, then save.';
        reverseLabel(lat, lng, 'Your current location — drag the pin if needed, then save.');
      }, function (err) {
        if (statusEl) {
          statusEl.textContent = err.code === 1
            ? 'Location denied — tap Select area or search instead.'
            : 'Could not read GPS — tap Select area or search instead.';
        }
      }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 });
    }

    function setSnipMode(on) {
      snipActive = !!on;
      if (snipActive) {
        map.dragging.disable();
        el.classList.add('is-snipping');
        if (snipBanner) snipBanner.hidden = false;
        if (snipBtn) {
          snipBtn.classList.add('btn-forest');
          snipBtn.classList.remove('btn-ghost');
        }
        if (statusEl) statusEl.textContent = 'Drag on the map to snip your farm area, then release.';
      } else {
        map.dragging.enable();
        el.classList.remove('is-snipping');
        if (snipBanner) snipBanner.hidden = true;
        if (snipBtn) {
          snipBtn.classList.remove('btn-forest');
          snipBtn.classList.add('btn-ghost');
        }
        if (snipRect) { map.removeLayer(snipRect); snipRect = null; }
        snipDragging = false;
        snipStart = null;
      }
    }

    if (latInput && latInput.value && lngInput && lngInput.value) {
      const existingPrec = (precInput && precInput.value) || 'approximate';
      setPin(parseNum(latInput.value), parseNum(lngInput.value), {
        precision: existingPrec,
        approximate: existingPrec === 'approximate',
        label: labelInput ? labelInput.value : '',
        focus: true,
        zoom: 16
      });
    } else if (wizard && el.getAttribute('data-auto-locate') === '1') {
      useDeviceLocation(true);
    }

    map.on('click', function (e) {
      if (snipActive) return;
      setPin(e.latlng.lat, e.latlng.lng, { precision: 'confirmed', focus: true, zoom: 17 });
      if (statusEl) statusEl.textContent = 'Pin placed — drag to adjust, then save.';
      reverseLabel(e.latlng.lat, e.latlng.lng);
    });

    map.on('mousedown', function (e) {
      if (!snipActive) return;
      snipDragging = true;
      snipStart = e.latlng;
      if (snipRect) map.removeLayer(snipRect);
      snipRect = L.rectangle(L.latLngBounds(snipStart, snipStart), {
        color: '#c45c26', weight: 3, dashArray: '8 4', fillColor: '#c45c26', fillOpacity: 0.18
      }).addTo(map);
      L.DomEvent.stop(e);
    });
    map.on('mousemove', function (e) {
      if (!snipActive || !snipDragging || !snipStart || !snipRect) return;
      snipRect.setBounds(L.latLngBounds(snipStart, e.latlng));
      L.DomEvent.stop(e);
    });
    function finishLocationSnip(endLatLng) {
      if (!snipActive || !snipDragging || !snipStart) return;
      snipDragging = false;
      const bounds = L.latLngBounds(snipStart, endLatLng);
      const center = bounds.getCenter();
      try { map.fitBounds(bounds.pad(0.15)); } catch (err) { focusHere(center.lat, center.lng, 17); }
      setPin(center.lat, center.lng, { precision: 'confirmed' });
      if (typeof map._applySnipPolygon === 'function') {
        map._applySnipPolygon(bounds);
      }
      setSnipMode(false);
      if (statusEl) {
        statusEl.textContent = snipSavesBoundary
          ? 'Plot area selected — save when ready.'
          : 'Area selected — pin is at the centre. Drag if needed, then save.';
      }
      if (!snipSavesBoundary) reverseLabel(center.lat, center.lng);
    }

    map.on('mouseup', function (e) {
      if (!snipActive || !snipDragging) return;
      finishLocationSnip(e.latlng);
      L.DomEvent.stop(e);
    });

    if (snipBtn) {
      snipBtn.addEventListener('click', function () {
        setSnipMode(!snipActive);
      });
    }

    async function searchPlaces(queryOverride) {
      const country = (form.querySelector('[name="country"]') || {}).value || 'Zambia';
      const province = (form.querySelector('[name="province"]') || {}).value || '';
      const district = (form.querySelector('[name="district"]') || {}).value || '';
      const town = (form.querySelector('[name="town"]') || {}).value || '';
      const landmark = (form.querySelector('[name="landmark"]') || {}).value || '';
      const qInput = document.getElementById('gps-q');
      const params = new URLSearchParams();
      if (queryOverride) params.set('q', queryOverride);
      else if (qInput && qInput.value.trim()) params.set('q', qInput.value.trim());
      params.set('country', country);
      if (province) params.set('province', province);
      if (district) params.set('district', district);
      if (town) params.set('town', town);
      if (landmark) params.set('landmark', landmark);
      if (!params.get('q') && !town && !district && !landmark) {
        if (statusEl) statusEl.textContent = 'Type a place name, then Find — or just tap the map.';
        return;
      }
      if (statusEl) statusEl.textContent = 'Searching…';
      if (results) { results.hidden = false; results.innerHTML = '<p class="muted">Searching…</p>'; }
      try {
        const res = window.FarmWiseApi
          ? await window.FarmWiseApi.send('/geocode?' + params.toString())
          : await fetch(base() + '/geocode?' + params.toString());
        const json = await res.json();
        const list = (json && json.results) ? json.results : [];
        if (!list.length) {
          if (statusEl) statusEl.textContent = (json && json.message) || 'No matches — try another name or tap the map.';
          if (results) results.innerHTML = '<p class="muted">No matches.</p>';
          return;
        }
        if (statusEl) statusEl.textContent = 'Pick a result (or tap the map).';
        if (results) {
          results.innerHTML = '';
          list.forEach(function (r) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'gps-result';
            btn.textContent = r.farmer_label || r.display_name || (r.lat + ', ' + r.lon);
            btn.addEventListener('click', function () {
              const lat = parseFloat(r.lat); const lon = parseFloat(r.lon);
              const label = r.farmer_label || r.display_name || '';
              setPin(lat, lon, {
                precision: 'approximate',
                approximate: true,
                label: label,
                focus: true,
                zoom: 15
              });
              syncLocationSummary(label);
              results.hidden = true;
              if (statusEl) statusEl.textContent = 'Place set — drag the pin onto the farm if needed.';
            });
            results.appendChild(btn);
          });
        }
      } catch (e) {
        if (statusEl) statusEl.textContent = 'Search failed — tap the map instead.';
      }
    }

    if (wizard) {
      const findBtn = form.querySelector('[data-loc-find]');
      if (findBtn) findBtn.addEventListener('click', function (e) { e.preventDefault(); searchPlaces(); });
      const qInput = document.getElementById('gps-q');
      if (qInput) {
        qInput.addEventListener('keydown', function (e) {
          if (e.key === 'Enter') {
            e.preventDefault();
            searchPlaces();
          }
        });
      }
    } else {
      initSearch(map, function (lat, lon, r) {
        setPin(lat, lon, {
          precision: 'approximate',
          approximate: true,
          label: (r && (r.farmer_label || r.display_name)) || '',
          focus: true,
          zoom: 15
        });
      });
    }

    form.querySelectorAll('[data-use-my-location]').forEach(function (locateBtn) {
      locateBtn.addEventListener('click', function () { useDeviceLocation(false); });
    });

    // Boundary drawing
    let drawn = null;
    let drawControl = null;
    const areaLabel = document.querySelector('[data-boundary-area]');

    function isFarmBoundaryInput() {
      return boundaryInput && (boundaryInput.name === 'boundary_geojson' || boundaryInput.id === 'field-boundary_geojson');
    }

    function parseBoundaryValue(raw) {
      const text = String(raw || '').trim();
      if (!text) return null;
      const embedded = extractGeo(text);
      if (embedded) return embedded;
      try {
        const parsed = JSON.parse(text);
        if (parsed && (parsed.type || parsed.geometry || parsed.coordinates)) return parsed;
      } catch (e) { /* ignore */ }
      return null;
    }

    function writeBoundaryValue(layer) {
      if (!boundaryInput) return;
      const geo = layer.toGeoJSON();
      const geometry = geo.geometry || geo;
      if (isFarmBoundaryInput()) {
        boundaryInput.value = JSON.stringify(geometry);
        return;
      }
      const userNotes = boundaryInput.dataset.userNotes || feStrip(boundaryInput.value);
      boundaryInput.dataset.userNotes = userNotes;
      writeBoundary(boundaryInput, geometry, userNotes);
    }

    function applyPolygon(layer) {
      drawn = layer;
      const latlngs = layer.getLatLngs()[0] || layer.getLatLngs();
      const ha = areaHa(latlngs);
      if (areaInput) areaInput.value = ha.toFixed(2);
      if (areaLabel) areaLabel.textContent = 'Selected area ≈ ' + ha.toFixed(2) + ' ha (saved with the farm)';
      const center = layer.getBounds().getCenter();
      setPin(center.lat, center.lng, { precision: 'confirmed' });
      writeBoundaryValue(layer);
      layer.setStyle({
        color: '#1c3b2b', weight: 3, fillColor: '#2f6b4f', fillOpacity: 0.28, dashArray: null
      });
    }

    map._applySnipPolygon = function (bounds) {
      if (!snipSavesBoundary || !boundaryInput) return;
      if (drawn) {
        try { map.removeLayer(drawn); } catch (e) { /* ignore */ }
        drawn = null;
      }
      if (snipRect) {
        try { map.removeLayer(snipRect); } catch (e) { /* ignore */ }
        snipRect = null;
      }
      const layer = L.rectangle(bounds, {
        color: '#1c3b2b', weight: 3, fillColor: '#2f6b4f', fillOpacity: 0.28
      }).addTo(map);
      applyPolygon(layer);
      if (statusEl) statusEl.textContent = 'Farm area selected — the shaded box will be saved with this farm.';
    };

    function feStrip(v) {
      return String(v || '').replace(/\n?\[\[GEOJSON\]\][\s\S]*$/, '').trim();
    }
    if (boundaryInput) {
      if (!isFarmBoundaryInput()) {
        boundaryInput.dataset.userNotes = feStrip(boundaryInput.value);
      }
      const existing = parseBoundaryValue(boundaryInput.value);
      if (existing) {
        try {
          const layer = L.geoJSON(existing, {
            style: { color: '#1c3b2b', weight: 3, fillColor: '#2f6b4f', fillOpacity: 0.28 }
          });
          layer.eachLayer(function (l) {
            l.addTo(map);
            applyPolygon(l);
            try { map.fitBounds(l.getBounds().pad(0.2)); } catch (e) { /* ignore */ }
          });
        } catch (e) { /* ignore */ }
      }
    }

    const drawBtn = document.querySelector('[data-draw-boundary]');
    if (drawBtn && global.L && L.Control && L.Control.Draw) {
      drawBtn.addEventListener('click', function () {
        if (!drawControl) {
          drawControl = new L.Control.Draw({
            draw: { polygon: { allowIntersection: false }, polyline: false, rectangle: false, circle: false, marker: false, circlemarker: false },
            edit: { featureGroup: L.featureGroup().addTo(map) }
          });
          map.addControl(drawControl);
        }
        new L.Draw.Polygon(map, {}).enable();
      });
      map.on(L.Draw.Event.CREATED, function (e) {
        if (drawn) map.removeLayer(drawn);
        e.layer.addTo(map);
        applyPolygon(e.layer);
      });
    }

    // Walk-the-boundary
    const walkBtn = document.querySelector('[data-walk-boundary]');
    let walkPts = [];
    let walkLayer = null;
    if (walkBtn) {
      walkBtn.addEventListener('click', function () {
        const status = document.getElementById('gps-status');
        if (!navigator.geolocation) {
          if (status) status.textContent = 'Geolocation not supported.';
          return;
        }
        const add = window.confirm('Add a GPS point to the boundary? Tap OK at each corner, Cancel when done to close the polygon.');
        if (!add) {
          if (walkPts.length >= 3) {
            if (walkLayer) map.removeLayer(walkLayer);
            walkLayer = L.polygon(walkPts, { color: '#1c3b2b' }).addTo(map);
            applyPolygon(walkLayer);
            walkPts = [];
            if (status) status.textContent = 'Boundary closed from walking points.';
          }
          return;
        }
        navigator.geolocation.getCurrentPosition(function (pos) {
          walkPts.push([pos.coords.latitude, pos.coords.longitude]);
          if (walkLayer) map.removeLayer(walkLayer);
          walkLayer = L.polyline(walkPts, { color: '#c4a35a' }).addTo(map);
          map.setView([pos.coords.latitude, pos.coords.longitude], 17);
          if (status) status.textContent = 'Point ' + walkPts.length + ' added (±' + Math.round(pos.coords.accuracy || 0) + ' m). Tap again to add more, Cancel in dialog to finish.';
          // Re-prompt style: user clicks button again
        }, function () {
          if (status) status.textContent = 'Could not read GPS point.';
        }, { enableHighAccuracy: true, timeout: 15000 });
      });
    }

    const clearBtn = document.querySelector('[data-clear-boundary]');
    if (clearBtn) {
      clearBtn.addEventListener('click', function () {
        if (drawn) { map.removeLayer(drawn); drawn = null; }
        if (walkLayer) { map.removeLayer(walkLayer); walkLayer = null; }
        walkPts = [];
        if (areaLabel) areaLabel.textContent = '';
        if (boundaryInput) {
          boundaryInput.value = isFarmBoundaryInput() ? '' : (boundaryInput.dataset.userNotes || '');
        }
        if (statusEl) statusEl.textContent = 'Area cleared — use Select area again, then Save.';
      });
    }

    setTimeout(function () { map.invalidateSize(); }, 200);
    return map;
  }

  /* ---------- Mini map ---------- */
  function initMini(el) {
    if (!global.L || !el) return null;
    const lat = parseNum(el.getAttribute('data-lat'));
    const lng = parseNum(el.getAttribute('data-lng'));
    let geo = null;
    try { geo = JSON.parse(el.getAttribute('data-geojson') || 'null'); } catch (e) { geo = null; }
    if (lat == null && lng == null && !geo) return null;
    const map = L.map(el, { zoomControl: false, attributionControl: false }).setView(
      [lat != null ? lat : FALLBACK_CENTER[0], lng != null ? lng : FALLBACK_CENTER[1]],
      lat != null ? 15 : FALLBACK_ZOOM
    );
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map);
    if (geo) {
      try {
        const layer = L.geoJSON(geo, {
          style: { color: '#1c3b2b', weight: 3, fillColor: '#2f6b4f', fillOpacity: 0.32, dashArray: '4 3' }
        }).addTo(map);
        map.fitBounds(layer.getBounds().pad(0.2));
      } catch (e) { /* ignore */ }
    }
    if (lat != null && lng != null) {
      L.marker([lat, lng]).addTo(map).bindPopup(el.getAttribute('data-label') || 'Location');
    }
    setTimeout(function () { map.invalidateSize(); }, 200);
    return map;
  }

  /* ---------- Fields planner (boundary + field division) ---------- */
  function initFieldsPlanner(el, data) {
    if (!global.L || !el || !data || !data.farm) return null;
    const farm = data.farm;
    const startLat = parseNum(farm.latitude) ?? FALLBACK_CENTER[0];
    const startLng = parseNum(farm.longitude) ?? FALLBACK_CENTER[1];
    const zoom = parseNum(farm.latitude) != null ? 15 : FALLBACK_ZOOM;
    const map = L.map(el, { tap: true }).setView([startLat, startLng], zoom);
    makeLayers(map);

    const colors = ['#1c3b2b', '#2f6b4f', '#b08c3e', '#6a8fad', '#8d5a2d', '#4a6b3a', '#3d6b5c', '#9a6b2f'];
    const farmGroup = L.featureGroup().addTo(map);
    const fieldGroup = L.featureGroup().addTo(map);
    let farmLayer = null;
    let drawMode = null;
    let selected = null;
    let snipActive = false;
    let snipDragging = false;
    let snipStart = null;
    let snipRect = null;
    let pendingLayer = null;
    let editHandler = null;
    const deleteIds = [];
    const statusEl = document.getElementById('fields-planner-status');
    const detailEl = document.querySelector('[data-field-detail]');
    const formTitle = document.querySelector('[data-field-form-title]');
    const idInput = document.getElementById('field-edit-id');
    const farmInput = document.getElementById('field-edit-farm');
    const nameInput = document.getElementById('field-edit-name');
    const descInput = document.getElementById('field-edit-description');
    const statusInput = document.getElementById('field-edit-status');
    const areaInput = document.getElementById('field-edit-area');
    const latInput = document.getElementById('field-edit-lat');
    const lngInput = document.getElementById('field-edit-lng');
    const areaSourceEl = document.querySelector('[data-field-area-source]');
    const boundaryNote = document.querySelector('[data-field-boundary-note]');
    const saveFieldBtn = document.querySelector('[data-save-field]');
    const cancelDraftBtn = document.querySelector('[data-cancel-field-draft]');
    const editBoundaryBtn = document.querySelector('[data-edit-field-boundary]');
    const deleteBtn = document.querySelector('[data-delete-field]');
    const listBody = document.querySelector('[data-fields-list]');
    const countEl = document.querySelector('[data-field-count]');
    const snipBanner = document.querySelector('[data-fields-snip-banner]');
    const fieldItems = [];
    const canEdit = !!data.canEdit;
    const canCreate = data.canCreate !== false;
    const canDelete = !!data.canDelete;
    let draftMode = false;
    let savingField = false;
    if (farmInput) farmInput.value = farm.name || '';

    function setStatus(msg) {
      if (statusEl) statusEl.textContent = msg;
    }

    function pointInRing(latlng, ring) {
      if (!ring || ring.length < 3) return true;
      let inside = false;
      for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
        const xi = ring[i].lng, yi = ring[i].lat;
        const xj = ring[j].lng, yj = ring[j].lat;
        const intersect = ((yi > latlng.lat) !== (yj > latlng.lat))
          && (latlng.lng < (xj - xi) * (latlng.lat - yi) / ((yj - yi) || 1e-12) + xi);
        if (intersect) inside = !inside;
      }
      return inside;
    }

    function layerRing(layer) {
      if (!layer || !layer.getLatLngs) return null;
      const latlngs = layer.getLatLngs();
      return latlngs && (Array.isArray(latlngs[0]) ? latlngs[0] : latlngs);
    }

    function pointInFarm(latlng) {
      if (!farmLayer) return true;
      try {
        if (farmLayer.getBounds && !farmLayer.getBounds().contains(latlng)) return false;
      } catch (e) { /* continue */ }
      return pointInRing(latlng, layerRing(farmLayer));
    }

    function overlapsExisting(layer, ignoreItem) {
      const ring = layerRing(layer);
      if (!ring || ring.length < 3) return false;
      const center = layer.getBounds().getCenter();
      return fieldItems.some(function (item) {
        if (item === ignoreItem || !item.layer) return false;
        const other = layerRing(item.layer);
        if (!other) return false;
        if (pointInRing(center, other)) return true;
        let hits = 0;
        for (let i = 0; i < ring.length; i++) {
          if (pointInRing(ring[i], other)) hits++;
        }
        return hits >= Math.ceil(ring.length * 0.5);
      });
    }

    function nextFieldName() {
      let n = 1;
      const used = {};
      fieldItems.forEach(function (f) {
        used[String(f.name || '').trim().toLowerCase()] = true;
      });
      while (used['field #' + n] || used['field ' + n] || used['field#' + n]) n++;
      return 'Field #' + n;
    }

    function refreshCount() {
      if (countEl) {
        const n = fieldItems.length;
        countEl.textContent = n + ' field' + (n === 1 ? '' : 's');
      }
    }

    function renderFieldList() {
      if (!listBody) return;
      listBody.innerHTML = '';
      if (!fieldItems.length) {
        const tr = document.createElement('tr');
        tr.setAttribute('data-empty-row', '');
        tr.innerHTML = '<td colspan="4" class="muted">No fields yet. Use Divide Farm into Fields to create Field #1.</td>';
        listBody.appendChild(tr);
        refreshCount();
        return;
      }
      fieldItems.forEach(function (item) {
        const tr = document.createElement('tr');
        if (item.id) tr.setAttribute('data-field-row', String(item.id));
        if (item === selected) tr.classList.add('is-selected');
        const statusLabel = item.status === 'fallow' ? 'Vacant' : String(item.status || 'active');
        tr.innerHTML =
          '<td>' + escapeHtml(item.name || 'Field') + '</td>' +
          '<td>' + Number(item.size_hectares || 0).toFixed(2) + '</td>' +
          '<td>' + escapeHtml(statusLabel.charAt(0).toUpperCase() + statusLabel.slice(1)) + '</td>' +
          '<td><button type="button" class="btn btn-ghost btn-sm" data-row-focus>View</button></td>';
        tr.addEventListener('click', function () { selectField(item); });
        const focusBtn = tr.querySelector('[data-row-focus]');
        if (focusBtn) {
          focusBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            selectField(item);
            try { map.fitBounds(item.layer.getBounds().pad(0.2)); } catch (err) { /* ignore */ }
          });
        }
        listBody.appendChild(tr);
      });
      refreshCount();
    }

    function fillFormFromItem(item, opts) {
      opts = opts || {};
      const editable = !!opts.editable;
      const isDraft = !!opts.draft;
      draftMode = isDraft;
      if (formTitle) formTitle.textContent = isDraft ? 'New field (from map)' : 'Field details';
      if (detailEl) {
        detailEl.textContent = isDraft
          ? 'Review the auto-filled details, then click Save Field.'
          : (item.name || 'Field');
      }
      if (idInput) idInput.value = item.id ? String(item.id) : '';
      if (farmInput) farmInput.value = farm.name || '';
      if (nameInput) {
        nameInput.disabled = !editable;
        nameInput.value = item.name || '';
      }
      if (descInput) {
        descInput.disabled = !editable;
        descInput.value = item.description || '';
      }
      if (statusInput) {
        statusInput.disabled = !editable;
        statusInput.value = item.status || 'active';
      }
      if (areaInput) {
        areaInput.disabled = false;
        areaInput.readOnly = true;
        areaInput.value = Number(item.size_hectares || 0).toFixed(2);
      }
      if (latInput) {
        latInput.disabled = false;
        latInput.readOnly = true;
        latInput.value = item.latitude != null ? Number(item.latitude).toFixed(7) : '';
      }
      if (lngInput) {
        lngInput.disabled = false;
        lngInput.readOnly = true;
        lngInput.value = item.longitude != null ? Number(item.longitude).toFixed(7) : '';
      }
      if (areaSourceEl) {
        areaSourceEl.hidden = false;
        areaSourceEl.textContent = 'Calculated from the selected boundary';
      }
      if (boundaryNote) boundaryNote.hidden = !isDraft && !item.id;
      if (saveFieldBtn) {
        saveFieldBtn.disabled = !editable || savingField;
        saveFieldBtn.textContent = isDraft ? 'Save Field' : 'Update Field';
        saveFieldBtn.hidden = !editable;
      }
      if (cancelDraftBtn) cancelDraftBtn.hidden = !isDraft;
      if (editBoundaryBtn) editBoundaryBtn.disabled = !editable || isDraft;
      if (deleteBtn) deleteBtn.disabled = !canDelete || isDraft || !item.id;
    }

    function selectField(item) {
      // Selecting a saved field cancels an unsaved draft polygon
      if (draftMode && pendingLayer && pendingLayer !== item.layer) {
        try { map.removeLayer(pendingLayer); } catch (e) { /* ignore */ }
        pendingLayer = null;
        draftMode = false;
      }
      selected = item;
      fieldItems.forEach(function (f) {
        if (f.layer && f.layer.setStyle) {
          const color = f.color || statusColor(f.status);
          f.layer.setStyle({
            color: color,
            fillColor: color,
            weight: f === item ? 3.5 : 2,
            fillOpacity: f === item ? 0.48 : 0.28,
            dashArray: null
          });
        }
      });
      fillFormFromItem(item, { editable: canEdit, draft: false });
      if (listBody) {
        listBody.querySelectorAll('tr').forEach(function (tr) {
          tr.classList.toggle('is-selected', selected && String(tr.getAttribute('data-field-row') || '') === String(selected.id || ''));
        });
      }
      try { map.fitBounds(item.layer.getBounds().pad(0.25)); } catch (e) { /* ignore */ }
    }

    function clearSelectionUi() {
      selected = null;
      draftMode = false;
      pendingLayer = null;
      if (idInput) idInput.value = '';
      if (nameInput) { nameInput.value = ''; nameInput.disabled = true; }
      if (descInput) { descInput.value = ''; descInput.disabled = true; }
      if (statusInput) statusInput.disabled = true;
      if (areaInput) { areaInput.value = ''; areaInput.disabled = true; }
      if (latInput) { latInput.value = ''; latInput.disabled = true; }
      if (lngInput) { lngInput.value = ''; lngInput.disabled = true; }
      if (saveFieldBtn) { saveFieldBtn.disabled = true; saveFieldBtn.hidden = false; saveFieldBtn.textContent = 'Save Field'; }
      if (cancelDraftBtn) cancelDraftBtn.hidden = true;
      if (editBoundaryBtn) editBoundaryBtn.disabled = true;
      if (deleteBtn) deleteBtn.disabled = true;
      if (formTitle) formTitle.textContent = 'Field details';
      if (detailEl) detailEl.textContent = 'Select an area on the map to auto-fill this form.';
      if (areaSourceEl) areaSourceEl.hidden = true;
      if (boundaryNote) boundaryNote.hidden = true;
    }

    function styleFieldLayer(layer, index, status) {
      const color = colors[index % colors.length];
      layer.setStyle({ color: color, weight: 2, fillColor: color, fillOpacity: 0.28 });
      return color;
    }

    function validateFieldLayer(layer) {
      const latlngs = layerRing(layer) || [];
      if (latlngs.length < 3) {
        setStatus('Draw a closed area with at least 3 points.');
        return false;
      }
      let inside = 0;
      latlngs.forEach(function (pt) { if (pointInFarm(pt)) inside++; });
      if (farmLayer && inside < Math.ceil(latlngs.length * 0.6)) {
        setStatus('Fields must stay mostly inside the farm boundary.');
        return false;
      }
      if (overlapsExisting(layer, null)) {
        setStatus('That area overlaps an existing field. Select an unoccupied section.');
        return false;
      }
      return true;
    }

    function mountFieldLayer(layer, meta) {
      meta = meta || {};
      const latlngs = layerRing(layer) || [];
      const ha = meta.size_hectares != null ? Number(meta.size_hectares) : areaHa(latlngs);
      const center = layer.getBounds().getCenter();
      const color = styleFieldLayer(layer, fieldItems.length, meta.status);
      fieldGroup.addLayer(layer);
      const item = {
        id: meta.id || 0,
        name: meta.name || nextFieldName(),
        status: meta.status || 'active',
        description: meta.description || '',
        size_hectares: ha,
        latitude: center.lat,
        longitude: center.lng,
        planting_count: meta.planting_count || 0,
        color: color,
        layer: layer
      };
      layer.on('click', function () {
        if (snipActive || drawMode) return;
        selectField(item);
      });
      fieldItems.push(item);
      return item;
    }

    function loadFarmBoundary(geo) {
      if (!geo) return;
      try {
        if (farmLayer) farmGroup.removeLayer(farmLayer);
        const gj = L.geoJSON(geo, {
          style: { color: '#1c3b2b', weight: 3, fillOpacity: 0.08, dashArray: '4 3' }
        });
        gj.eachLayer(function (l) {
          farmGroup.addLayer(l);
          farmLayer = l;
        });
        try { map.fitBounds(farmGroup.getBounds().pad(0.15)); } catch (e) { /* ignore */ }
      } catch (e) {
        setStatus('Could not load saved farm boundary.');
      }
    }

    if (farm.boundary) loadFarmBoundary(farm.boundary);
    (data.fields || []).forEach(function (f) {
      if (!f.geometry) return;
      try {
        const gj = L.geoJSON(f.geometry);
        gj.eachLayer(function (l) {
          mountFieldLayer(l, f);
        });
      } catch (e) { /* ignore */ }
    });
    renderFieldList();
    if (!farm.boundary && parseNum(farm.latitude) != null) {
      L.marker([startLat, startLng]).addTo(map).bindPopup(escapeHtml(farm.name || 'Farm'));
      setStatus('No farm boundary yet — draw the farm boundary first.');
    }

    function setSnipMode(on) {
      snipActive = !!on;
      if (snipActive) {
        drawMode = null;
        map.dragging.disable();
        el.classList.add('is-snipping');
        if (snipBanner) snipBanner.hidden = false;
        setStatus('Drag on the farm map to select a field area, then release.');
      } else {
        map.dragging.enable();
        el.classList.remove('is-snipping');
        if (snipBanner) snipBanner.hidden = true;
        if (snipRect) { map.removeLayer(snipRect); snipRect = null; }
        snipDragging = false;
        snipStart = null;
      }
    }

    async function postJson(url, body) {
      const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
      const timer = controller ? setTimeout(function () { controller.abort(); }, 20000) : null;
      try {
        const res = await fetch(url, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': data.csrf || ''
          },
          credentials: 'same-origin',
          signal: controller ? controller.signal : undefined,
          body: JSON.stringify(Object.assign({ _csrf: data.csrf || '' }, body || {}))
        });
        let json = null;
        try { json = await res.json(); } catch (e) { json = null; }
        if (!res.ok || !json || !json.ok) {
          throw new Error((json && json.error) || 'Save failed.');
        }
        return json;
      } finally {
        if (timer) clearTimeout(timer);
      }
    }

    function geometryFromLayer(layer) {
      try {
        const gj = layer.toGeoJSON();
        return gj.geometry || gj;
      } catch (e) {
        return null;
      }
    }

    function toast(msg, type) {
      if (global.IFMS && typeof IFMS.toast === 'function') {
        IFMS.toast(msg, type || 'info');
      } else {
        setStatus(msg);
      }
    }

    async function persistField(item) {
      if (!data.saveFieldUrl) throw new Error('Save URL missing.');
      const geometry = geometryFromLayer(item.layer);
      if (!geometry) throw new Error('Could not read field boundary.');
      const payload = {
        id: item.id || 0,
        name: item.name,
        description: item.description || '',
        status: item.status || 'active',
        size_hectares: item.size_hectares,
        latitude: item.latitude,
        longitude: item.longitude,
        geometry: geometry
      };
      const json = await postJson(data.saveFieldUrl, payload);
      if (json.field && json.field.id) item.id = json.field.id;
      return json;
    }

    function readFormOntoItem(item) {
      item.name = (nameInput && nameInput.value.trim()) || item.name || nextFieldName();
      item.description = descInput ? descInput.value.trim() : (item.description || '');
      item.status = (statusInput && statusInput.value) || item.status || 'active';
      if (areaInput && areaInput.value !== '') {
        const ha = parseFloat(areaInput.value);
        if (Number.isFinite(ha)) item.size_hectares = ha;
      }
      return item;
    }

    function populateDraftForm(layer) {
      pendingLayer = layer;
      draftMode = true;
      selected = null;
      const latlngs = layerRing(layer) || [];
      const ha = areaHa(latlngs);
      const center = layer.getBounds().getCenter();
      fillFormFromItem({
        id: 0,
        name: nextFieldName(),
        description: '',
        status: 'active',
        size_hectares: ha,
        latitude: center.lat,
        longitude: center.lng
      }, { editable: canCreate, draft: true });
      if (nameInput) nameInput.focus();
      setStatus('Area selected — form filled automatically. Review and click Save Field.');
    }

    function cancelDraft() {
      if (pendingLayer) {
        try { map.removeLayer(pendingLayer); } catch (e) { /* ignore */ }
      }
      pendingLayer = null;
      draftMode = false;
      clearSelectionUi();
      setStatus('Selection cancelled.');
    }

    async function saveFromForm() {
      if (savingField) return;
      if (draftMode) {
        if (!pendingLayer || !canCreate) return;
        const layer = pendingLayer;
        if (!validateFieldLayer(layer)) {
          try { map.removeLayer(layer); } catch (e) { /* ignore */ }
          pendingLayer = null;
          clearSelectionUi();
          return;
        }
        const draft = readFormOntoItem({
          name: nextFieldName(),
          description: '',
          status: 'active',
          size_hectares: areaHa(layerRing(layer) || []),
          latitude: layer.getBounds().getCenter().lat,
          longitude: layer.getBounds().getCenter().lng
        });
        savingField = true;
        if (saveFieldBtn) {
          saveFieldBtn.disabled = true;
          saveFieldBtn.textContent = 'Saving…';
        }
        try {
          const item = mountFieldLayer(layer, draft);
          pendingLayer = null;
          draftMode = false;
          await persistField(item);
          selectField(item);
          renderFieldList();
          toast('Field successfully saved!', 'success');
          setStatus(item.name + ' saved and available in field dropdowns.');
        } catch (err) {
          // Remount failed — keep draft polygon for retry
          const idx = fieldItems.findIndex(function (f) { return f.layer === layer; });
          if (idx >= 0) fieldItems.splice(idx, 1);
          try { fieldGroup.removeLayer(layer); } catch (e) { /* ignore */ }
          pendingLayer = layer;
          draftMode = true;
          layer.addTo(map);
          populateDraftForm(layer);
          if (nameInput) nameInput.value = draft.name;
          if (descInput) descInput.value = draft.description || '';
          if (statusInput) statusInput.value = draft.status || 'active';
          toast(err.message || 'Could not save field.', 'error');
          setStatus(err.message || 'Could not save field.');
        } finally {
          savingField = false;
          if (saveFieldBtn && draftMode) {
            saveFieldBtn.disabled = false;
            saveFieldBtn.textContent = 'Save Field';
          }
        }
        return;
      }
      if (!selected || !canEdit) return;
      readFormOntoItem(selected);
      const latlngs = layerRing(selected.layer) || [];
      if (latlngs.length) {
        selected.size_hectares = areaHa(latlngs);
        const center = selected.layer.getBounds().getCenter();
        selected.latitude = center.lat;
        selected.longitude = center.lng;
        if (areaInput) areaInput.value = Number(selected.size_hectares).toFixed(2);
        if (latInput) latInput.value = Number(selected.latitude).toFixed(7);
        if (lngInput) lngInput.value = Number(selected.longitude).toFixed(7);
      }
      savingField = true;
      if (saveFieldBtn) {
        saveFieldBtn.disabled = true;
        saveFieldBtn.textContent = 'Saving…';
      }
      try {
        await persistField(selected);
        selectField(selected);
        renderFieldList();
        toast('Field successfully saved!', 'success');
        setStatus(selected.name + ' updated.');
      } catch (err) {
        toast(err.message || 'Could not update field.', 'error');
        setStatus(err.message || 'Could not update field.');
        if (saveFieldBtn) {
          saveFieldBtn.disabled = false;
          saveFieldBtn.textContent = 'Update Field';
        }
      } finally {
        savingField = false;
      }
    }

    function beginFieldFromLayer(layer) {
      if (pendingLayer && pendingLayer !== layer) {
        try { map.removeLayer(pendingLayer); } catch (e) { /* ignore */ }
      }
      if (!validateFieldLayer(layer)) {
        try { map.removeLayer(layer); } catch (e) { /* ignore */ }
        return;
      }
      const color = '#c45c26';
      layer.setStyle({ color: color, weight: 3, fillColor: color, fillOpacity: 0.22, dashArray: '6 4' });
      layer.addTo(map);
      populateDraftForm(layer);
    }

    function finishSnip(endLatLng) {
      if (!snipActive || !snipDragging || !snipStart) return;
      snipDragging = false;
      const bounds = L.latLngBounds(snipStart, endLatLng);
      if (snipRect) { map.removeLayer(snipRect); snipRect = null; }
      setSnipMode(false);
      if (Math.abs(bounds.getNorth() - bounds.getSouth()) < 1e-7 || Math.abs(bounds.getEast() - bounds.getWest()) < 1e-7) {
        setStatus('Selection too small — drag a larger area.');
        return;
      }
      const corners = [
        bounds.getSouthWest(),
        bounds.getSouthEast(),
        bounds.getNorthEast(),
        bounds.getNorthWest()
      ];
      const poly = L.polygon(corners);
      beginFieldFromLayer(poly);
    }

    map.on('mousedown', function (e) {
      if (!snipActive) return;
      snipDragging = true;
      snipStart = e.latlng;
      if (snipRect) map.removeLayer(snipRect);
      snipRect = L.rectangle(L.latLngBounds(snipStart, snipStart), {
        color: '#c45c26', weight: 3, dashArray: '8 4', fillColor: '#c45c26', fillOpacity: 0.18
      }).addTo(map);
      L.DomEvent.stop(e);
    });
    map.on('mousemove', function (e) {
      if (!snipActive || !snipDragging || !snipStart || !snipRect) return;
      snipRect.setBounds(L.latLngBounds(snipStart, e.latlng));
      L.DomEvent.stop(e);
    });
    map.on('mouseup', function (e) {
      if (!snipActive || !snipDragging) return;
      finishSnip(e.latlng);
      L.DomEvent.stop(e);
    });
    // Touch / pointer release outside map
    document.addEventListener('mouseup', function (e) {
      if (!snipActive || !snipDragging || !snipStart) return;
      const ll = map.mouseEventToLatLng(e);
      if (ll) finishSnip(ll);
    });

    function enablePolygonDraw(forFarm) {
      if (!L.Draw || !L.Draw.Polygon) {
        setStatus('Drawing tools are unavailable.');
        return;
      }
      setSnipMode(false);
      drawMode = forFarm ? 'farm' : 'field';
      new L.Draw.Polygon(map, { allowIntersection: false, showArea: true }).enable();
      setStatus(forFarm
        ? 'Click the map to mark farm boundary points. Double-click to finish.'
        : 'Click corners for an irregular field. Double-click to finish.');
    }

    const drawFarmBtn = document.querySelector('[data-draw-farm-boundary]');
    if (drawFarmBtn) {
      drawFarmBtn.addEventListener('click', function () { enablePolygonDraw(true); });
    }
    const divideBtn = document.querySelector('[data-divide-fields]');
    if (divideBtn) {
      divideBtn.addEventListener('click', function () {
        if (!farmLayer) {
          setStatus('Draw and complete the farm boundary first.');
          return;
        }
        if (!canCreate) {
          setStatus('You do not have permission to create fields.');
          return;
        }
        setSnipMode(!snipActive);
      });
    }
    const irregularBtn = document.querySelector('[data-draw-irregular-field]');
    if (irregularBtn) {
      irregularBtn.addEventListener('click', function () {
        if (!farmLayer) {
          setStatus('Draw and complete the farm boundary first.');
          return;
        }
        enablePolygonDraw(false);
      });
    }

    map.on(L.Draw.Event.CREATED, function (e) {
      const layer = e.layer;
      if (drawMode === 'farm') {
        if (farmLayer) farmGroup.removeLayer(farmLayer);
        farmLayer = layer;
        farmLayer.setStyle({ color: '#1c3b2b', weight: 3, fillOpacity: 0.08, dashArray: '4 3' });
        farmGroup.addLayer(farmLayer);
        setStatus('Farm boundary ready — click Save farm boundary, then Divide Farm into Fields.');
        drawMode = null;
        return;
      }
      if (drawMode === 'field') {
        beginFieldFromLayer(layer);
        drawMode = null;
      }
    });

    if (saveFieldBtn) {
      saveFieldBtn.addEventListener('click', function () { saveFromForm(); });
    }
    if (cancelDraftBtn) {
      cancelDraftBtn.addEventListener('click', function () { cancelDraft(); });
    }

    if (editBoundaryBtn) {
      editBoundaryBtn.addEventListener('click', function () {
        if (!selected || !selected.layer || !canEdit) return;
        if (!L.Edit || !L.Edit.Poly) {
          setStatus('Boundary editing tools are unavailable.');
          return;
        }
        if (editHandler && editHandler.disable) {
          try { editHandler.disable(); } catch (e) { /* ignore */ }
          editHandler = null;
        }
        try {
          selected.layer.editing.enable();
          editHandler = selected.layer.editing;
          setStatus('Drag boundary points to adjust. Click Update Field when done.');
          selected.layer.on('edit', function () {
            const latlngs = layerRing(selected.layer) || [];
            selected.size_hectares = areaHa(latlngs);
            const center = selected.layer.getBounds().getCenter();
            selected.latitude = center.lat;
            selected.longitude = center.lng;
            if (areaInput) areaInput.value = Number(selected.size_hectares).toFixed(2);
            if (latInput) latInput.value = Number(selected.latitude).toFixed(7);
            if (lngInput) lngInput.value = Number(selected.longitude).toFixed(7);
            if (areaSourceEl) {
              areaSourceEl.hidden = false;
              areaSourceEl.textContent = 'Recalculated from adjusted boundary';
            }
          });
        } catch (err) {
          setStatus('Could not enable boundary editing.');
        }
      });
    }

    if (deleteBtn) {
      deleteBtn.addEventListener('click', async function () {
        if (!selected || !canDelete) return;
        const label = selected.name || 'this field';
        if (selected.planting_count > 0) {
          const ok = confirm(
            label + ' has planting history. Deleting it is blocked to protect records.\n\nMark it as Vacant instead?'
          );
          if (!ok) return;
          selected.status = 'fallow';
          if (statusInput) statusInput.value = 'fallow';
          try {
            await persistField(selected);
            renderFieldList();
            setStatus(label + ' marked vacant. Planting history kept.');
          } catch (err) {
            setStatus(err.message || 'Could not update field.');
          }
          return;
        }
        if (!confirm('Delete ' + label + '? This cannot be undone.')) return;
        try {
          if (selected.id && data.deleteFieldUrl) {
            await postJson(data.deleteFieldUrl, { id: selected.id });
          } else if (selected.id) {
            deleteIds.push(selected.id);
          }
          fieldGroup.removeLayer(selected.layer);
          fieldItems.splice(fieldItems.indexOf(selected), 1);
          clearSelectionUi();
          renderFieldList();
          setStatus(label + ' deleted.');
        } catch (err) {
          setStatus(err.message || 'Could not delete field.');
        }
      });
    }

    function serialize() {
      const boundaryInput = document.getElementById('boundary_geojson');
      const fieldsInput = document.getElementById('fields_geojson');
      const deleteInput = document.getElementById('delete_ids');
      if (boundaryInput && farmLayer) {
        try {
          boundaryInput.value = JSON.stringify(farmLayer.toGeoJSON().geometry || farmLayer.toGeoJSON());
        } catch (e) { boundaryInput.value = ''; }
      }
      if (fieldsInput) {
        const payload = fieldItems.map(function (f) {
          let geometry = null;
          try { geometry = f.layer.toGeoJSON().geometry || f.layer.toGeoJSON(); } catch (e) { geometry = null; }
          const latlngs = layerRing(f.layer) || [];
          const ha = latlngs.length ? areaHa(latlngs) : (f.size_hectares || 0);
          const center = f.layer.getBounds ? f.layer.getBounds().getCenter() : { lat: f.latitude, lng: f.longitude };
          return {
            id: f.id || 0,
            name: f.name,
            description: f.description || '',
            status: f.status || 'active',
            size_hectares: ha,
            latitude: center.lat,
            longitude: center.lng,
            geometry: geometry
          };
        });
        fieldsInput.value = JSON.stringify(payload);
      }
      if (deleteInput) deleteInput.value = JSON.stringify(deleteIds);
    }

    const saveBtn = document.querySelector('[data-save-fields-plan]');
    const form = document.getElementById('fields-planner-form');
    if (saveBtn && form) {
      saveBtn.addEventListener('click', function () {
        serialize();
        form.submit();
      });
    }

    setTimeout(function () { map.invalidateSize(); }, 200);
    return map;
  }

  /* ---------- Crop rotation map (select field + history) ---------- */
  function initCropRotationMap(el, payload) {
    if (!global.L || !el || !payload || !payload.map) return null;
    const data = payload.map;
    const colors = payload.colors || {};
    const farm = data.farm || {};
    const startLat = parseNum(farm.latitude) ?? FALLBACK_CENTER[0];
    const startLng = parseNum(farm.longitude) ?? FALLBACK_CENTER[1];
    const map = L.map(el, { tap: true }).setView(
      [startLat, startLng],
      parseNum(farm.latitude) != null ? 15 : FALLBACK_ZOOM
    );
    makeLayers(map);

    const fieldIndex = [];
    const bounds = [];
    let selectedId = null;
    let snipActive = false;
    let snipStart = null;
    let snipRect = null;
    let snipDragging = false;

    const statusEl = document.getElementById('cr-map-status');
    const emptyEl = document.querySelector('[data-cr-empty]');
    const reportEl = document.querySelector('[data-cr-report]');
    const chooserEl = document.querySelector('[data-cr-chooser]');
    const chooserList = document.querySelector('[data-cr-chooser-list]');
    const nameEl = document.querySelector('[data-cr-field-name]');
    const metaEl = document.querySelector('[data-cr-field-meta]');
    const currentEl = document.querySelector('[data-cr-current]');
    const historyEl = document.querySelector('[data-cr-history]');
    const reportBtn = document.querySelector('[data-cr-field-report]');
    const plantBtn = document.querySelector('[data-cr-add-planting]');

    function statusColor(key) {
      return (colors[key] && colors[key].hex) || '#8a948c';
    }

    function setStatus(msg) {
      if (statusEl) statusEl.textContent = msg;
    }

    function styleField(item, selected) {
      if (!item.layer || !item.layer.setStyle) return;
      const base = statusColor(item.rotation_status);
      item.layer.setStyle({
        color: selected ? statusColor('selected') : base,
        weight: selected ? 4 : 2,
        fillColor: selected ? statusColor('selected') : base,
        fillOpacity: selected ? 0.55 : 0.32,
        opacity: selected ? 1 : 0.85
      });
      if (item.layer.bringToFront && selected) item.layer.bringToFront();
    }

    function dimOthers(exceptId) {
      fieldIndex.forEach(function (f) {
        const selected = f.id === exceptId;
        styleField(f, selected);
        if (f.layer && f.layer.setStyle && !selected) {
          f.layer.setStyle({ fillOpacity: 0.18, opacity: 0.55, weight: 1.5 });
        }
      });
    }

    function renderReport(item) {
      if (chooserEl) chooserEl.hidden = true;
      if (emptyEl) emptyEl.hidden = true;
      if (reportEl) reportEl.hidden = false;
      if (nameEl) nameEl.textContent = item.name || ('Field #' + item.id);
      if (metaEl) {
        metaEl.textContent = 'Field ID ' + item.id
          + (item.size_hectares != null && item.size_hectares !== '' ? ' · ' + item.size_hectares + ' ha' : '')
          + ' · ' + (item.field_status || 'active');
      }
      if (currentEl) currentEl.textContent = item.current_crop || 'None';
      if (historyEl) {
        historyEl.innerHTML = '';
        const rows = item.history || [];
        if (!rows.length) {
          const tr = document.createElement('tr');
          tr.innerHTML = '<td colspan="4" class="muted">No planting records for this field.</td>';
          historyEl.appendChild(tr);
        } else {
          rows.forEach(function (r) {
            const harvest = r.actual_harvest_date || r.expected_harvest || '';
            const tr = document.createElement('tr');
            tr.innerHTML = '<td>' + escapeHtml(r.crop_name || '—') + '</td>'
              + '<td>' + escapeHtml(r.planting_date || '—') + '</td>'
              + '<td>' + escapeHtml(harvest || '—') + '</td>'
              + '<td>' + escapeHtml(r.display_status || r.status || '—') + '</td>';
            historyEl.appendChild(tr);
          });
        }
      }
      if (reportBtn && payload.urls) {
        reportBtn.href = payload.urls.fieldReport.replace(/\/?$/, '/') + item.id;
      }
      if (plantBtn && payload.urls) {
        plantBtn.href = payload.urls.plantingCreate + (payload.urls.plantingCreate.indexOf('?') >= 0 ? '&' : '?') + 'field_id=' + item.id;
      }
    }

    function selectField(item) {
      selectedId = item.id;
      dimOthers(item.id);
      styleField(item, true);
      renderReport(item);
      try {
        if (item.layer.getBounds) map.fitBounds(item.layer.getBounds().pad(0.35));
        else if (item.lat != null) map.setView([item.lat, item.lng], 16);
      } catch (e) { /* ignore */ }
      setStatus('Selected: ' + (item.name || ('Field #' + item.id)));
    }

    function showChooser(items) {
      if (!chooserEl || !chooserList) {
        if (items[0]) selectField(items[0]);
        return;
      }
      if (emptyEl) emptyEl.hidden = true;
      if (reportEl) reportEl.hidden = true;
      chooserEl.hidden = false;
      chooserList.innerHTML = '';
      items.forEach(function (item) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-ghost btn-sm cr-chooser-btn';
        btn.textContent = (item.name || ('Field #' + item.id))
          + (item.current_crop ? ' · ' + item.current_crop : '');
        btn.addEventListener('click', function () { selectField(item); });
        chooserList.appendChild(btn);
      });
      setStatus('Selection covers ' + items.length + ' fields — choose one.');
    }

    function rectHitsField(boundsRect, item) {
      if (!item.layer) return false;
      try {
        if (item.layer.getBounds) {
          const b = item.layer.getBounds();
          if (boundsRect.intersects(b) || boundsRect.contains(b.getCenter())) return true;
        }
      } catch (e) { /* ignore */ }
      if (item.lat != null && item.lng != null) {
        return boundsRect.contains(L.latLng(item.lat, item.lng));
      }
      return false;
    }

    function pointHitsField(latlng, item) {
      if (!item.layer) return false;
      try {
        if (item.layer.getBounds && item.layer.getBounds().contains(latlng)) {
          // Prefer polygon contains when available
          const latlngs = item.layer.getLatLngs && item.layer.getLatLngs();
          const ring = latlngs && (Array.isArray(latlngs[0]) ? (Array.isArray(latlngs[0][0]) ? latlngs[0][0] : latlngs[0]) : latlngs);
          if (ring && ring.length >= 3) {
            let inside = false;
            for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
              const xi = ring[i].lng, yi = ring[i].lat;
              const xj = ring[j].lng, yj = ring[j].lat;
              const intersect = ((yi > latlng.lat) !== (yj > latlng.lat))
                && (latlng.lng < (xj - xi) * (latlng.lat - yi) / ((yj - yi) || 1e-12) + xi);
              if (intersect) inside = !inside;
            }
            return inside;
          }
          return true;
        }
      } catch (e) { /* ignore */ }
      if (item.lat != null && item.lng != null) {
        return map.distance(latlng, L.latLng(item.lat, item.lng)) < 40;
      }
      return false;
    }

    if (farm.boundary) {
      try {
        const bl = L.geoJSON(farm.boundary, {
          style: { color: '#1c3b2b', weight: 2, fillOpacity: 0.04, dashArray: '4 3' }
        }).addTo(map);
        try { bounds.push(bl.getBounds()); } catch (e) { /* ignore */ }
      } catch (e) { /* ignore */ }
    }

    (data.fields || []).forEach(function (f) {
      const color = statusColor(f.rotation_status);
      const lat = parseNum(f.latitude);
      const lng = parseNum(f.longitude);
      let layer = null;
      if (f.geometry) {
        try {
          const gj = L.geoJSON(f.geometry, {
            style: { color: color, weight: 2, fillColor: color, fillOpacity: 0.32 }
          });
          gj.eachLayer(function (l) {
            layer = l;
            l.addTo(map);
          });
          try { bounds.push(layer.getBounds()); } catch (e) { /* ignore */ }
        } catch (e) { layer = null; }
      }
      if (!layer && lat != null && lng != null) {
        layer = L.circleMarker([lat, lng], {
          radius: 12, color: color, fillColor: color, fillOpacity: 0.7, weight: 2
        }).addTo(map);
        bounds.push([lat, lng]);
      }
      if (!layer) return;
      const item = {
        id: f.id,
        name: f.name,
        size_hectares: f.size_hectares,
        field_status: f.field_status,
        rotation_status: f.rotation_status,
        current_crop: f.current_crop,
        history: f.history || [],
        lat: lat,
        lng: lng,
        layer: layer
      };
      layer.on('click', function (e) {
        if (snipActive) return;
        L.DomEvent.stopPropagation(e);
        selectField(item);
      });
      layer.bindTooltip(escapeHtml(f.name || ('Field #' + f.id)), { sticky: true });
      fieldIndex.push(item);
    });

    if (bounds.length) {
      try {
        const b = L.latLngBounds(bounds);
        if (b.isValid()) map.fitBounds(b.pad(0.15));
      } catch (e) { /* ignore */ }
    } else if (parseNum(farm.latitude) != null) {
      L.marker([startLat, startLng]).addTo(map).bindPopup(escapeHtml(farm.name || 'Farm'));
    }

    function finishSnip(endLatLng) {
      snipDragging = false;
      if (!snipStart || !endLatLng) return;
      const boundsRect = L.latLngBounds(snipStart, endLatLng);
      // keep rectangle briefly for the snipping feel
      const hits = fieldIndex.filter(function (f) { return rectHitsField(boundsRect, f); });
      snipActive = false;
      map.dragging.enable();
      el.classList.remove('is-snipping');
      if (snipBanner) snipBanner.hidden = true;
      if (selectBtn) {
        selectBtn.classList.add('btn-gold');
        selectBtn.classList.remove('btn-forest');
      }
      setTimeout(function () {
        if (snipRect) { map.removeLayer(snipRect); snipRect = null; }
      }, 450);
      if (!hits.length) {
        setStatus('No field found in that selection. Try again or click a field.');
        return;
      }
      if (hits.length === 1) selectField(hits[0]);
      else showChooser(hits);
    }

    function onSnipStart(e) {
      if (!snipActive) return;
      snipDragging = true;
      snipStart = e.latlng;
      if (snipRect) map.removeLayer(snipRect);
      snipRect = L.rectangle(L.latLngBounds(snipStart, snipStart), {
        color: '#c45c26', weight: 3, dashArray: '8 4', fillColor: '#c45c26', fillOpacity: 0.2
      }).addTo(map);
      L.DomEvent.stop(e);
    }
    function onSnipMove(e) {
      if (!snipActive || !snipDragging || !snipStart || !snipRect) return;
      snipRect.setBounds(L.latLngBounds(snipStart, e.latlng));
      L.DomEvent.stop(e);
    }
    function onSnipEnd(e) {
      if (!snipActive || !snipDragging) return;
      finishSnip(e.latlng);
      if (snipBanner) snipBanner.hidden = true;
      L.DomEvent.stop(e);
    }

    map.on('mousedown', onSnipStart);
    map.on('mousemove', onSnipMove);
    map.on('mouseup', onSnipEnd);
    // Touch
    map.on('touchstart', function (e) {
      if (!snipActive || !e.latlng) return;
      onSnipStart(e);
    });
    map.on('touchmove', function (e) {
      if (!snipActive || !e.latlng) return;
      onSnipMove(e);
    });
    map.on('touchend', function (e) {
      if (!snipActive) return;
      const ll = (e.latlng) || (snipRect && snipRect.getBounds().getNorthEast());
      if (ll) finishSnip(ll);
    });

    // Also allow click-without-drag: find field under point when not snipping
    map.on('click', function (e) {
      if (snipActive) return;
      const hits = fieldIndex.filter(function (f) { return pointHitsField(e.latlng, f); });
      if (hits.length === 1) selectField(hits[0]);
      else if (hits.length > 1) showChooser(hits);
    });

    const selectBtn = document.querySelector('[data-cr-select-field]');
    const snipBanner = document.querySelector('[data-cr-snip-banner]');
    if (selectBtn) {
      selectBtn.addEventListener('click', function () {
        snipActive = !snipActive;
        if (snipActive) {
          map.dragging.disable();
          el.classList.add('is-snipping');
          if (snipBanner) snipBanner.hidden = false;
          selectBtn.classList.add('btn-forest');
          selectBtn.classList.remove('btn-gold');
          setStatus('Drag around a field like a snipping tool, then release.');
        } else {
          map.dragging.enable();
          el.classList.remove('is-snipping');
          if (snipBanner) snipBanner.hidden = true;
          selectBtn.classList.add('btn-gold');
          selectBtn.classList.remove('btn-forest');
          if (snipRect) { map.removeLayer(snipRect); snipRect = null; }
          setStatus('Click a field, or press Select Field and drag around it.');
        }
      });
    }

    setTimeout(function () { map.invalidateSize(); }, 200);
    return map;
  }

  function fixLeafletIcons() {
    if (!global.L || !L.Icon || !L.Icon.Default) return;
    // Use an inline SVG location pin so PNGs never show as broken images.
    const svg =
      '<svg xmlns="http://www.w3.org/2000/svg" width="32" height="42" viewBox="0 0 32 42">' +
      '<path fill="#c9920f" stroke="#1a1408" stroke-width="1.2" d="M16 1.5c-7.2 0-13 5.8-13 13 0 9.8 13 25.5 13 25.5S29 24.3 29 14.5c0-7.2-5.8-13-13-13z"/>' +
      '<circle cx="16" cy="14.5" r="5.2" fill="#fff8e8"/>' +
      '</svg>';
    const pinUrl = 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(svg);
    delete L.Icon.Default.prototype._getIconUrl;
    L.Icon.Default.mergeOptions({
      iconUrl: pinUrl,
      iconRetinaUrl: pinUrl,
      shadowUrl: '',
      iconSize: [32, 42],
      iconAnchor: [16, 40],
      popupAnchor: [0, -36],
      tooltipAnchor: [0, -28],
      shadowSize: [0, 0],
      shadowAnchor: [0, 0],
      className: 'fw-loc-pin-img'
    });
  }

  function boot() {
    const planner = document.getElementById('fields-planner-map');
    if (!global.L) {
      if (planner) {
        const status = document.getElementById('fields-planner-status');
        if (status) status.textContent = 'Map library did not load. Refresh the page.';
        planner.innerHTML = '<p class="muted" style="padding:1rem">Map could not load. Please refresh.</p>';
      }
      return;
    }
    fixLeafletIcons();
    const main = document.getElementById('farm-map');
    if (main && global.IFMS_MAP) {
      initMainMap(main, global.IFMS_MAP);
    }
    if (planner && global.IFMS_FIELDS_PLANNER) {
      const plannerMap = initFieldsPlanner(planner, global.IFMS_FIELDS_PLANNER);
      if (plannerMap) {
        planner._farmMap = plannerMap;
        setTimeout(function () { plannerMap.invalidateSize(); }, 250);
      }
    }
    const cropMap = document.getElementById('crop-rotation-map');
    if (cropMap && global.IFMS_CROP_ROTATION) {
      initCropRotationMap(cropMap, global.IFMS_CROP_ROTATION);
    }
    document.querySelectorAll('[data-farm-map="picker"]').forEach(initPicker);
    document.querySelectorAll('[data-farm-map="mini"]').forEach(initMini);

    // When Map tab opens, invalidate size
    document.querySelectorAll('[data-tab="map"]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        setTimeout(function () {
          document.querySelectorAll('[data-farm-map="mini"]').forEach(function (el) {
            if (el._leaflet_id && global.L) {
              const m = el._leaflet_map || null;
            }
            // re-init once
            if (!el.dataset.mapReady) {
              initMini(el);
              el.dataset.mapReady = '1';
            }
          });
        }, 50);
      });
    });
  }

  global.FarmMap = {
    FALLBACK_CENTER: FALLBACK_CENTER,
    initMainMap: initMainMap,
    initPicker: initPicker,
    initMini: initMini,
    initFieldsPlanner: initFieldsPlanner,
    initCropRotationMap: initCropRotationMap,
    boot: boot
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})(typeof window !== 'undefined' ? window : globalThis);

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
      const m = L.marker([lat, lng]);
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

    // My location
    let userMarker = null; let accuracyCircle = null;
    const locateBtn = document.querySelector('[data-map-locate]');
    function onPos(pos) {
      const lat = pos.coords.latitude; const lng = pos.coords.longitude;
      const acc = pos.coords.accuracy || 30;
      if (!userMarker) {
        userMarker = L.circleMarker([lat, lng], { radius: 8, color: '#2563eb', fillColor: '#3b82f6', fillOpacity: 0.9 }).addTo(layers.me);
        accuracyCircle = L.circle([lat, lng], { radius: Math.max(acc, 20), color: '#2563eb', fillOpacity: 0.12, weight: 1 }).addTo(layers.me);
      } else {
        userMarker.setLatLng([lat, lng]);
        accuracyCircle.setLatLng([lat, lng]).setRadius(Math.max(acc, 20));
      }
    }
    function geoErr(err) {
      const status = document.getElementById('map-gps-status');
      if (!status) return;
      if (!window.isSecureContext && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
        status.textContent = 'GPS needs localhost or HTTPS.';
      } else if (err && err.code === 1) {
        status.textContent = 'Location permission denied.';
      } else {
        status.textContent = 'Could not read your location.';
      }
    }
    if (locateBtn) {
      locateBtn.addEventListener('click', function () {
        if (!navigator.geolocation) return geoErr();
        navigator.geolocation.getCurrentPosition(function (pos) {
          onPos(pos);
          map.setView([pos.coords.latitude, pos.coords.longitude], 16);
        }, geoErr, { enableHighAccuracy: true, timeout: 12000 });
      });
    }
    if (navigator.geolocation) {
      navigator.geolocation.watchPosition(onPos, function () { /* quiet */ }, { enableHighAccuracy: true, maximumAge: 5000, timeout: 12000 });
    }

    initSearch(map);
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

    const startLat = parseNum(latInput && latInput.value) ?? FALLBACK_CENTER[0];
    const startLng = parseNum(lngInput && lngInput.value) ?? FALLBACK_CENTER[1];
    const zoom = (latInput && latInput.value) ? 14 : FALLBACK_ZOOM;
    const map = L.map(el).setView([startLat, startLng], zoom);
    makeLayers(map);

    function setBadge(precision) {
      if (!badgeEl) return;
      badgeEl.className = 'loc-badge loc-badge-' + (precision === 'confirmed' ? 'ok' : (precision === 'approximate' ? 'warn' : 'muted'));
      if (precision === 'confirmed') badgeEl.textContent = 'Exact farm pin saved';
      else if (precision === 'approximate') badgeEl.textContent = 'Town / village location (approximate — not the exact farm gate)';
      else badgeEl.textContent = 'Location not set yet — you can still save the farm';
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
    let approxCircle = null;
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
          if (statusEl) statusEl.textContent = 'Pin moved — this is now marked as the exact farm place.';
          if (approxCircle) { map.removeLayer(approxCircle); approxCircle = null; }
        });
      }
      if (latInput) latInput.value = Number(lat).toFixed(7);
      if (lngInput) lngInput.value = Number(lng).toFixed(7);
      if (showLat) showLat.value = latInput.value;
      if (showLng) showLng.value = lngInput.value;
      if (opts.label) setLabel(opts.label);
      if (opts.precision) setPrecision(opts.precision);
      if (opts.approximate) {
        if (approxCircle) map.removeLayer(approxCircle);
        approxCircle = L.circle([lat, lng], {
          radius: 2500,
          color: '#b08c3e',
          fillColor: '#c4a35a',
          fillOpacity: 0.12,
          weight: 1
        }).addTo(map);
      } else if (approxCircle && opts.precision === 'confirmed') {
        map.removeLayer(approxCircle);
        approxCircle = null;
      }
    }
    if (latInput && latInput.value && lngInput && lngInput.value) {
      const existingPrec = (precInput && precInput.value) || 'approximate';
      setPin(parseNum(latInput.value), parseNum(lngInput.value), {
        precision: existingPrec,
        approximate: existingPrec === 'approximate',
        label: labelInput ? labelInput.value : ''
      });
    }
    map.on('click', function (e) {
      setPin(e.latlng.lat, e.latlng.lng, { precision: 'confirmed' });
      if (statusEl) statusEl.textContent = 'Pin placed on the farm. You can drag it to fine-tune.';
    });

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
        if (statusEl) statusEl.textContent = 'Type a town or village name first, then tap Find on map.';
        return;
      }
      if (statusEl) statusEl.textContent = 'Looking for places…';
      if (results) { results.hidden = false; results.innerHTML = '<p class="muted">Searching…</p>'; }
      try {
        const res = window.FarmWiseApi
          ? await window.FarmWiseApi.send('/geocode?' + params.toString())
          : await fetch(base() + '/geocode?' + params.toString());
        const json = await res.json();
        const list = (json && json.results) ? json.results : [];
        if (!list.length) {
          if (statusEl) statusEl.textContent = (json && json.message) || 'No matching place found. Try another town name, or tap the map.';
          if (results) results.innerHTML = '<p class="muted">No places found. Try a bigger nearby town.</p>';
          return;
        }
        if (statusEl) statusEl.textContent = 'Tap a place below, then drag the pin if needed.';
        if (results) {
          results.innerHTML = '';
          list.forEach(function (r) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'gps-result';
            btn.textContent = r.farmer_label || r.display_name || (r.lat + ', ' + r.lon);
            btn.addEventListener('click', function () {
              const lat = parseFloat(r.lat); const lon = parseFloat(r.lon);
              setPin(lat, lon, {
                precision: 'approximate',
                approximate: true,
                label: r.farmer_label || r.display_name || ''
              });
              map.setView([lat, lon], 12);
              results.hidden = true;
              if (statusEl) {
                statusEl.textContent = 'Town area selected. This is approximate weather location — drag the pin onto the farm for an exact place.';
              }
            });
            results.appendChild(btn);
          });
        }
      } catch (e) {
        if (statusEl) statusEl.textContent = 'Could not search right now. Check your internet and try again.';
      }
    }

    if (wizard) {
      const findBtn = form.querySelector('[data-loc-find]');
      if (findBtn) findBtn.addEventListener('click', function (e) { e.preventDefault(); searchPlaces(); });
    } else {
      initSearch(map, function (lat, lon, r) {
        setPin(lat, lon, {
          precision: 'approximate',
          approximate: true,
          label: (r && (r.farmer_label || r.display_name)) || ''
        });
        map.setView([lat, lon], 14);
      });
    }

    const locateBtns = form.querySelectorAll('[data-use-my-location]');
    locateBtns.forEach(function (locateBtn) {
      locateBtn.addEventListener('click', function () {
        if (!navigator.geolocation) {
          if (statusEl) statusEl.textContent = 'This device cannot share its location.';
          return;
        }
        if (!window.isSecureContext && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
          if (statusEl) statusEl.textContent = 'Phone location needs a secure page (HTTPS) or localhost.';
          return;
        }
        const ok = window.confirm('Allow FarmWise to use this device’s location? Use this only when you are at the farm.');
        if (!ok) {
          if (statusEl) statusEl.textContent = 'Location not used.';
          return;
        }
        if (statusEl) statusEl.textContent = 'Getting your phone location…';
        navigator.geolocation.getCurrentPosition(function (pos) {
          setPin(pos.coords.latitude, pos.coords.longitude, { precision: 'confirmed' });
          map.setView([pos.coords.latitude, pos.coords.longitude], 16);
          L.circle([pos.coords.latitude, pos.coords.longitude], { radius: Math.max(pos.coords.accuracy || 30, 20), color: '#2563eb', fillOpacity: 0.12 }).addTo(map);
          if (statusEl) statusEl.textContent = 'Farm pin set from your phone (±' + Math.round(pos.coords.accuracy || 0) + ' m). You can drag it to adjust.';
        }, function (err) {
          if (statusEl) statusEl.textContent = err.code === 1 ? 'Location permission was denied.' : 'Could not get location. Try Find on map instead.';
        }, { enableHighAccuracy: true, timeout: 12000 });
      });
    });

    // Boundary drawing
    let drawn = null;
    let drawControl = null;
    const areaLabel = document.querySelector('[data-boundary-area]');

    function applyPolygon(layer) {
      drawn = layer;
      const latlngs = layer.getLatLngs()[0] || layer.getLatLngs();
      const ha = areaHa(latlngs);
      if (areaInput) areaInput.value = ha.toFixed(2);
      if (areaLabel) areaLabel.textContent = 'Area ≈ ' + ha.toFixed(2) + ' ha';
      const center = layer.getBounds().getCenter();
      setPin(center.lat, center.lng);
      const geo = layer.toGeoJSON();
      if (boundaryInput) {
        const userNotes = boundaryInput.dataset.userNotes || feStrip(boundaryInput.value);
        boundaryInput.dataset.userNotes = userNotes;
        writeBoundary(boundaryInput, geo.geometry ? geo : geo, userNotes);
      }
    }

    function feStrip(v) {
      return String(v || '').replace(/\n?\[\[GEOJSON\]\][\s\S]*$/, '').trim();
    }
    if (boundaryInput) {
      boundaryInput.dataset.userNotes = feStrip(boundaryInput.value);
      const existing = extractGeo(boundaryInput.value);
      if (existing) {
        try {
          const layer = L.geoJSON(existing);
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
        if (areaInput) areaInput.value = '';
        if (areaLabel) areaLabel.textContent = '';
        if (boundaryInput) boundaryInput.value = boundaryInput.dataset.userNotes || '';
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
        const layer = L.geoJSON(geo, { style: { color: '#1c3b2b', weight: 2, fillOpacity: 0.25 } }).addTo(map);
        map.fitBounds(layer.getBounds().pad(0.2));
      } catch (e) { /* ignore */ }
    }
    if (lat != null && lng != null) {
      L.marker([lat, lng]).addTo(map).bindPopup(el.getAttribute('data-label') || 'Location');
    }
    setTimeout(function () { map.invalidateSize(); }, 200);
    return map;
  }

  function boot() {
    if (!global.L) return;
    const main = document.getElementById('farm-map');
    if (main && global.IFMS_MAP) {
      initMainMap(main, global.IFMS_MAP);
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
    boot: boot
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})(typeof window !== 'undefined' ? window : globalThis);

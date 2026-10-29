/**
 * FarmWise browser API client.
 *
 * Set window.FARMWISE_API_BASE before this file loads to point the same
 * interface at another backend. Leave it empty to use the current site
 * (window.IFMS_BASE), which supports installs in a subdirectory.
 *
 * Session cookies are sent for same-origin requests. A bearer token is added
 * only when window.FARMWISE_API_TOKEN is set by the host page. This file
 * does not contain credentials.
 */
(function (root) {
  function base() {
    const configured = root.FARMWISE_API_BASE || root.IFMS_BASE || '';
    return String(configured).replace(/\/$/, '');
  }

  function url(path) {
    const target = String(path || '');
    if (/^https?:\/\//i.test(target)) {
      return target;
    }
    const prefix = base();
    const suffix = target.startsWith('/') ? target : '/' + target;
    return prefix + suffix;
  }

  function token() {
    return root.FARMWISE_API_TOKEN || '';
  }

  /**
   * @param {string} path
   * @param {RequestInit & {csrf?: string}} [options]
   * @returns {Promise<Response>}
   */
  function send(path, options) {
    const opts = options || {};
    const headers = Object.assign({ Accept: 'application/json' }, opts.headers || {});
    const bearer = token();
    if (bearer && !headers.Authorization && !headers.authorization) {
      headers.Authorization = 'Bearer ' + bearer;
    }
    if (opts.csrf && !headers['X-CSRF-TOKEN']) {
      headers['X-CSRF-TOKEN'] = opts.csrf;
    }
    return fetch(url(path), {
      method: opts.method || 'GET',
      headers: headers,
      credentials: opts.credentials || 'same-origin',
      body: opts.body,
      signal: opts.signal,
    });
  }

  root.FarmWiseApi = {
    base: base,
    url: url,
    send: send,
    geocode: function (query) {
      return send('/geocode?q=' + encodeURIComponent(query || ''));
    },
    login: function (email, password) {
      return send('/api/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email: email, password: password }),
      });
    },
  };
})(window);

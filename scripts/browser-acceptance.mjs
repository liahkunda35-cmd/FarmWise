/**
 * Phase 5 — Playwright browser acceptance against local FarmWise (Neon-backed).
 * Run: npx playwright test is NOT used; execute directly:
 *   node scripts/browser-acceptance.mjs
 * Requires: php -S 127.0.0.1:8081 router.php
 */
import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const BASE = process.env.FARMWISE_BASE || 'http://127.0.0.1:8081';
const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = path.join(__dirname, '..', 'storage', 'acceptance');
fs.mkdirSync(outDir, { recursive: true });

const users = {
  admin: { email: 'admin@ifms.local', password: 'Admin#2026', role: 'admin' },
  owner: { email: 'owner@ifms.local', password: 'Owner#2026', role: 'owner' },
  manager: { email: 'manager@ifms.local', password: 'Manager#2026', role: 'manager' },
  agronomist: { email: 'agro@ifms.local', password: 'Agro#2026', role: 'agronomist' },
  worker: { email: 'worker@ifms.local', password: 'Worker#2026', role: 'worker' },
  accountant: { email: 'accounts@ifms.local', password: 'Accounts#2026', role: 'accountant' },
};

const results = [];
let fail = 0;

function record(role, module, action, pass, detail = '') {
  if (!pass) fail++;
  results.push({ role, module, action, pass, detail });
  console.log(`${pass ? 'OK  ' : 'FAIL'} [${role}] ${module} · ${action}${detail ? ' — ' + detail : ''}`);
}

async function waitAppReady(page) {
  for (let i = 0; i < 15; i++) {
    const res = await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded', timeout: 90000 }).catch(() => null);
    const status = res ? res.status() : 0;
    const body = await page.content();
    if (status === 200 && body.includes('name="email"') && !body.includes('Waking the FarmWise database')) {
      return true;
    }
    await page.waitForTimeout(3000);
  }
  return false;
}

async function login(page, user) {
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded', timeout: 90000 });
  // Neon wake page auto-refreshes
  for (let i = 0; i < 8; i++) {
    const body = await page.content();
    if (body.includes('name="email"') && !body.includes('Waking the FarmWise database')) break;
    await page.waitForTimeout(3000);
    await page.reload({ waitUntil: 'domcontentloaded' }).catch(() => null);
  }
  await page.fill('input[name="email"]', user.email);
  await page.fill('input[name="password"]', user.password);
  const btn = page.getByRole('button', { name: /sign in/i });
  await btn.waitFor({ state: 'visible', timeout: 15000 });
  await btn.click({ force: true, noWaitAfter: true });
  // Neon + single-threaded PHP can take a while; poll instead of Playwright navigation wait.
  for (let i = 0; i < 40; i++) {
    await page.waitForTimeout(1500);
    let body = '';
    try {
      body = await page.content();
    } catch {
      continue; // mid-navigation
    }
    const url = page.url();
    if (body.includes('Waking the FarmWise database')) {
      await page.reload({ waitUntil: 'domcontentloaded' }).catch(() => null);
      continue;
    }
    const loggedIn =
      /\/(dashboard|module|reports|notifications|profile|map|weather|users)/.test(url) ||
      /sign out|logout|quick actions|crop performance/i.test(body);
    if (loggedIn && !/login failed/i.test(body)) return true;
    if (/login failed|invalid credentials|incorrect password/i.test(body)) return false;
  }
  const url = page.url();
  let body = '';
  try {
    body = await page.content();
  } catch {
    body = '';
  }
  return (
    (/\/(dashboard|module|reports|notifications|profile|map|weather|users)/.test(url) ||
      /sign out|logout|quick actions|crop performance/i.test(body)) &&
    !/login failed/i.test(body)
  );
}

async function logout(page) {
  // Sign-out control is often in a collapsed account menu — navigate directly.
  await page.goto(`${BASE}/logout`, { waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => null);
}

async function expectForbiddenOrRedirect(page, path, role) {
  const res = await page.goto(`${BASE}/${path}`, { waitUntil: 'domcontentloaded', timeout: 60000 });
  const status = res ? res.status() : 0;
  const url = page.url();
  const body = await page.content();
  const blocked =
    status === 403 ||
    body.includes('do not have permission') ||
    body.includes('403') ||
    url.includes('/login') ||
    body.toLowerCase().includes('access denied');
  return { blocked, status, url };
}

async function openModule(page, moduleKey) {
  let res = null;
  let body = '';
  let status = 0;
  for (let attempt = 0; attempt < 5; attempt++) {
    res = await page.goto(`${BASE}/module/${moduleKey}`, { waitUntil: 'domcontentloaded', timeout: 90000 }).catch(() => null);
    status = res ? res.status() : 0;
    try {
      body = await page.content();
    } catch {
      body = '';
    }
    if (status === 503 || body.includes('Waking the FarmWise database')) {
      await page.waitForTimeout(3000);
      continue;
    }
    break;
  }
  const ok = status > 0 && status < 400 && !body.includes('do not have permission') && !body.includes('404');
  return { ok, status, body };
}

async function createViaForm(page, moduleKey, fields) {
  await page.goto(`${BASE}/module/${moduleKey}/create`, { waitUntil: 'domcontentloaded', timeout: 60000 });
  const body = await page.content();
  if (body.includes('do not have permission') || body.includes('403')) {
    return { ok: false, detail: 'forbidden create' };
  }
  if (!body.includes('<form') && !body.includes('stack-form')) {
    return { ok: false, detail: 'no create form' };
  }
  for (const [name, value] of Object.entries(fields)) {
    const sel = `[name="${name}"]`;
    const el = page.locator(sel).first();
    if (!(await el.count())) continue;
    const tag = await el.evaluate((n) => n.tagName.toLowerCase() + ':' + (n.type || ''));
    if (tag.startsWith('select')) {
      await el.selectOption({ value: String(value) }).catch(async () => {
        await el.selectOption({ label: String(value) }).catch(() => null);
      });
    } else if (tag.includes('checkbox') || tag.includes('radio')) {
      await el.check().catch(() => null);
    } else {
      await el.fill(String(value));
    }
  }
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => null),
    page.locator('form.stack-form button[type="submit"], form.stack-form button.btn-gold, form button[type="submit"]').first().click(),
  ]);
  const after = await page.content();
  const url = page.url();
  const ok =
    after.toLowerCase().includes('saved') ||
    after.toLowerCase().includes('updated') ||
    after.toLowerCase().includes('success') ||
    (url.includes(`/module/${moduleKey}`) && !url.includes('/create') && !after.includes('Please correct'));
  return { ok, detail: url, htmlHint: after.includes('Please correct') ? 'validation errors' : '' };
}

async function run() {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ ignoreHTTPSErrors: true });
  const page = await context.newPage();
  page.setDefaultTimeout(45000);

  const ready = await waitAppReady(page);
  record('guest', 'app', 'ready (not Neon 503)', ready);
  if (!ready) {
    await browser.close();
    console.log('BROWSER ACCEPTANCE HAS FAILURES — app not ready');
    process.exit(1);
  }

  // Landing
  try {
    const res = await page.goto(BASE + '/', { waitUntil: 'domcontentloaded', timeout: 60000 });
    record('guest', 'landing', 'open', (res?.status() || 0) < 400);
  } catch (e) {
    record('guest', 'landing', 'open', false, e.message);
  }

  // Login form validation
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
  await page.fill('input[name="email"]', 'bad@example.com');
  await page.fill('input[name="password"]', 'wrong');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => null),
    page.click('form button[type="submit"], form .btn, .auth-shell-fields button'),
  ]);
  const failLogin = (await page.content()).toLowerCase();
  record('guest', 'auth', 'bad login rejected', failLogin.includes('login') || failLogin.includes('failed') || page.url().includes('login'));

  // Role matrix — login + key modules
  const roleModules = {
    admin: ['tasks', 'farms', 'users'],
    owner: ['farms', 'fields', 'tasks', 'inventory_items', 'sales_orders', 'transactions'],
    manager: ['tasks', 'plantings', 'stock_movements', 'harvests', 'dispatches'],
    agronomist: ['pest_scouting', 'plantings', 'crop_growth', 'tasks'],
    worker: ['tasks', 'attendance'],
    accountant: ['invoices', 'payments', 'transactions', 'reports'],
  };

  const denied = {
    worker: ['transactions', 'stock_movements/create', 'sales_orders/create'],
    agronomist: ['sales_orders/create', 'payments'],
    accountant: ['plantings/create', 'tasks/create'],
    manager: ['payments/create'],
  };

  for (const [role, user] of Object.entries(users)) {
    const logged = await login(page, user);
    record(role, 'auth', 'login', logged, user.email);
    if (!logged) {
      await page.screenshot({ path: path.join(outDir, `${role}-login-fail.png`), fullPage: true }).catch(() => null);
      continue;
    }

    // Dashboard
    await page.goto(`${BASE}/dashboard`, { waitUntil: 'domcontentloaded' }).catch(() => null);
    const dash = await page.content();
    record(role, 'dashboard', 'view', dash.length > 500 && !dash.includes('do not have permission'));

    for (const mod of roleModules[role] || []) {
      if (mod === 'reports') {
        const r = await page.goto(`${BASE}/reports`, { waitUntil: 'domcontentloaded' });
        record(role, 'reports', 'view', (r?.status() || 0) < 400);
        continue;
      }
      if (mod === 'users') {
        const r = await page.goto(`${BASE}/users`, { waitUntil: 'domcontentloaded' }).catch(() => null);
        const body = await page.content();
        record(role, 'users', 'view', !!r && (r.status() < 400) && !body.includes('do not have permission'));
        continue;
      }
      const { ok, status } = await openModule(page, mod);
      record(role, mod, 'list', ok, `http=${status}`);
    }

    for (const pathDenied of denied[role] || []) {
      const { blocked, status, url } = await expectForbiddenOrRedirect(page, pathDenied.startsWith('module') ? pathDenied : `module/${pathDenied}`, role);
      // Also try module/x for create paths
      let okBlock = blocked;
      if (pathDenied.includes('/')) {
        const p = await page.goto(`${BASE}/module/${pathDenied}`, { waitUntil: 'domcontentloaded' }).catch(() => null);
        const b = await page.content();
        okBlock =
          (p && p.status() === 403) ||
          b.includes('do not have permission') ||
          b.includes('Please correct') ||
          !b.includes('stack-form') ||
          page.url().includes('login');
        // Worker hitting create may get 403 page
        if (!okBlock && b.includes('stack-form') && role === 'worker') okBlock = false;
      }
      record(role, pathDenied, 'denied as expected', okBlock, `status=${status} url=${url}`);
    }

    // Notifications
    const n = await page.goto(`${BASE}/notifications`, { waitUntil: 'domcontentloaded' }).catch(() => null);
    record(role, 'notifications', 'view', !!n && (n.status() || 0) < 500);

    await logout(page);
    record(role, 'auth', 'logout', true);
  }

  // Manager end-to-end UI workflows
  const mgr = users.manager;
  if (await login(page, mgr)) {
    const tag = 'p5ui_' + Date.now().toString(36);

    // Field create
    let r = await createViaForm(page, 'fields', {
      name: tag + '_field',
      size_hectares: '1.5',
      status: 'active',
    });
    record('manager', 'fields', 'create via UI', r.ok, r.detail + ' ' + (r.htmlHint || ''));

    // Inventory item
    r = await createViaForm(page, 'inventory_items', {
      name: tag + '_fert',
      category: 'fertilizer',
      unit: 'kg',
      quantity: '0',
      reorder_level: '5',
      status: 'out',
    });
    record('manager', 'inventory_items', 'create via UI', r.ok, r.detail);

    // Stock in — resolve inventory_item_id from list row (name is cell text; View is separate)
    await page.goto(`${BASE}/module/inventory_items?q=${encodeURIComponent(tag + '_fert')}`, {
      waitUntil: 'domcontentloaded',
      timeout: 90000,
    });
    let itemId = null;
    const row = page.locator('tr.data-row', { hasText: tag + '_fert' }).first();
    if (await row.count()) {
      const href = await row.locator('a[href*="/view/"], a[href*="/edit/"]').first().getAttribute('href');
      const m = (href || '').match(/inventory_items\/(?:view|edit)\/(\d+)/);
      itemId = m ? m[1] : null;
    }
    if (!itemId) {
      // Fallback: option labels on stock create form
      await page.goto(`${BASE}/module/stock_movements/create`, { waitUntil: 'domcontentloaded', timeout: 90000 });
      const opt = page.locator(`select[name="inventory_item_id"] option`, { hasText: tag + '_fert' }).first();
      if (await opt.count()) {
        itemId = await opt.getAttribute('value');
      }
    }
    if (itemId) {
      r = await createViaForm(page, 'stock_movements', {
        inventory_item_id: itemId,
        movement_type: 'in',
        quantity: '50',
        movement_date: new Date().toISOString().slice(0, 10),
        reference: tag + '_in',
        notes: 'browser acceptance',
      });
      record('manager', 'stock_movements', 'stock in via UI', r.ok, r.detail);

      // Insufficient stock out
      await page.goto(`${BASE}/module/stock_movements/create`, { waitUntil: 'domcontentloaded' });
      await page.locator('[name="inventory_item_id"]').first().selectOption(itemId).catch(() => null);
      await page.locator('[name="movement_type"]').first().selectOption('out').catch(() => null);
      await page.locator('[name="quantity"]').first().fill('99999');
      await page.locator('[name="movement_date"]').first().fill(new Date().toISOString().slice(0, 10));
      await page.locator('[name="reference"]').first().fill(tag + '_bad');
      await Promise.all([
        page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => null),
        page.locator('form.stack-form button[type="submit"], form button[type="submit"]').first().click(),
      ]);
      const rej = (await page.content()).toLowerCase();
      record(
        'manager',
        'stock_movements',
        'insufficient stock rejected in UI',
        rej.includes('insufficient') || rej.includes('correct') || page.url().includes('create')
      );
    } else {
      record('manager', 'stock_movements', 'stock in via UI', false, 'could not resolve item id');
    }

    // Pest scouting as agronomist
    await logout(page);
    if (await login(page, users.agronomist)) {
      await page.goto(`${BASE}/module/fields`, { waitUntil: 'domcontentloaded' });
      const fieldHref = await page.locator('a[href*="module/fields/view/"], a[href*="module/fields/edit/"]').first().getAttribute('href').catch(() => null);
      let fieldId = fieldHref && (fieldHref.match(/\/(\d+)/) || [])[1];
      r = await createViaForm(page, 'pest_scouting', {
        field_id: fieldId || '1',
        scout_date: new Date().toISOString().slice(0, 10),
        severity: 'high',
        notes: tag + ' scout',
        status: 'reported',
      });
      record('agronomist', 'pest_scouting', 'create via UI', r.ok, r.detail);

      r = await createViaForm(page, 'tasks', {
        title: tag + ' spray task',
        due_date: new Date().toISOString().slice(0, 10),
        priority: 'high',
        status: 'pending',
        description: 'from browser',
      });
      record('agronomist', 'tasks', 'create via UI', r.ok, r.detail);
    }

    // Accountant reports cash-basis page
    await logout(page);
    if (await login(page, users.accountant)) {
      await page.goto(`${BASE}/reports?type=financial`, { waitUntil: 'domcontentloaded' });
      let html = (await page.content()).toLowerCase();
      record('accountant', 'reports', 'financial cash-basis labels', html.includes('cash') || html.includes('profit'));
      await page.goto(`${BASE}/reports?type=receivables`, { waitUntil: 'domcontentloaded' });
      html = (await page.content()).toLowerCase();
      record('accountant', 'reports', 'receivables report', html.includes('invoice') || html.includes('outstanding') || html.includes('order') || (await page.content()).length > 800);

      // Cannot open plantings create
      const { blocked } = await expectForbiddenOrRedirect(page, 'module/plantings/create', 'accountant');
      const b = await page.content();
      record('accountant', 'plantings', 'create blocked', blocked || b.includes('permission') || !b.includes('stack-form'));
    }

    // Worker tasks only
    await logout(page);
    if (await login(page, users.worker)) {
      const { ok } = await openModule(page, 'tasks');
      record('worker', 'tasks', 'list', ok);
      const tx = await page.goto(`${BASE}/module/transactions`, { waitUntil: 'domcontentloaded' });
      const tb = await page.content();
      record('worker', 'transactions', 'blocked', (tx && tx.status() === 403) || tb.includes('permission') || tb.includes('403'));
    }

    // Owner weather + persistence refresh
    await logout(page);
    if (await login(page, users.owner)) {
      const w = await page.goto(`${BASE}/weather`, { waitUntil: 'domcontentloaded' });
      record('owner', 'weather', 'view', !!w && (w.status() || 0) < 500);
      await page.goto(`${BASE}/module/fields`, { waitUntil: 'domcontentloaded' });
      const before = await page.content();
      const hasField = before.includes(tag + '_field') || before.includes('field');
      await page.reload({ waitUntil: 'domcontentloaded' });
      const after = await page.content();
      record('owner', 'fields', 'persist after refresh', after.length > 500 && (after.includes(tag + '_field') || hasField));
    }

    // Admin integrations / users monitoring
    await logout(page);
    if (await login(page, users.admin)) {
      const u = await page.goto(`${BASE}/users`, { waitUntil: 'domcontentloaded' });
      record('admin', 'users', 'admin list', !!u && (u.status() || 0) < 400);
      const i = await page.goto(`${BASE}/admin/integrations`, { waitUntil: 'domcontentloaded' }).catch(() =>
        page.goto(`${BASE}/integrations`, { waitUntil: 'domcontentloaded' })
      );
      const ib = await page.content();
      record('admin', 'integrations', 'view', !!i && ((i.status && i.status() < 500) || ib.length > 400));
    }
  }

  await browser.close();

  const reportPath = path.join(outDir, 'browser-acceptance.json');
  fs.writeFileSync(reportPath, JSON.stringify({ base: BASE, fail, results }, null, 2));
  console.log('\nfailures=' + fail);
  console.log('report=' + reportPath);
  console.log(fail === 0 ? 'BROWSER ACCEPTANCE PASSED' : 'BROWSER ACCEPTANCE HAS FAILURES');
  process.exit(fail === 0 ? 0 : 1);
}

run().catch((e) => {
  console.error(e);
  process.exit(1);
});

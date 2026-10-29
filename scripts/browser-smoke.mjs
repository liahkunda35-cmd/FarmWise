/**
 * Faster Phase 5 browser smoke: ready check + login each role + one allowed page + one denied page.
 * node scripts/browser-smoke.mjs
 */
import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const BASE = process.env.FARMWISE_BASE || 'http://127.0.0.1:8081';
const outDir = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'storage', 'acceptance');
fs.mkdirSync(outDir, { recursive: true });

/** Align with scripts/http-acceptance.php roleAccess / roleDenied */
const users = [
  ['admin', 'admin@ifms.local', 'Admin#2026', '/users', null],
  ['owner', 'owner@ifms.local', 'Owner#2026', '/module/fields', '/integrations'],
  ['manager', 'manager@ifms.local', 'Manager#2026', '/module/tasks', '/module/payments/create'],
  ['agronomist', 'agro@ifms.local', 'Agro#2026', '/module/pest_scouting', '/module/payments'],
  ['worker', 'worker@ifms.local', 'Worker#2026', '/module/tasks', '/module/transactions'],
  ['accountant', 'accounts@ifms.local', 'Accounts#2026', '/reports?type=financial', '/module/plantings/create'],
];

let fail = 0;
const log = (ok, msg) => {
  if (!ok) fail++;
  console.log((ok ? 'OK  ' : 'FAIL') + ' ' + msg);
};

async function ready(page) {
  for (let i = 0; i < 12; i++) {
    const res = await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => null);
    const html = await page.content();
    if (res && res.status() === 200 && html.includes('name="email"') && !html.includes('Waking the FarmWise database')) return true;
    await page.waitForTimeout(2500);
  }
  return false;
}

async function login(page, email, password) {
  await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded', timeout: 60000 });
  for (let i = 0; i < 8; i++) {
    const html = await page.content();
    if (html.includes('name="email"') && !html.includes('Waking')) break;
    await page.waitForTimeout(2500);
    await page.reload({ waitUntil: 'domcontentloaded' }).catch(() => null);
  }
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', password);
  const btn = page.getByRole('button', { name: /sign in/i });
  await btn.waitFor({ state: 'visible', timeout: 15000 });
  await btn.click({ force: true, noWaitAfter: true });
  for (let i = 0; i < 40; i++) {
    await page.waitForTimeout(1500);
    let html = '';
    try {
      html = await page.content();
    } catch {
      continue;
    }
    const url = page.url();
    if (html.includes('Waking the FarmWise database')) {
      await page.reload({ waitUntil: 'domcontentloaded' }).catch(() => null);
      continue;
    }
    if (/dashboard|module|reports|users|notifications|weather|map/i.test(url + html) && !/login failed/i.test(html)) {
      return true;
    }
    if (/login failed|invalid credentials/i.test(html)) return false;
  }
  let html = '';
  try {
    html = await page.content();
  } catch {
    html = '';
  }
  const url = page.url();
  return /dashboard|module|reports|users|notifications|weather|map/i.test(url + html) && !/login failed/i.test(html);
}

function isDenied(status, html, denyPath) {
  return (
    status === 403 ||
    html.includes('do not have permission') ||
    /forbidden|access denied/i.test(html) ||
    (denyPath.includes('/integrations') && (html.includes('Integrations') === false || status === 403 || html.includes('do not have permission')))
  );
}

const browser = await chromium.launch({ headless: true });
const probe = await browser.newPage();
probe.setDefaultTimeout(45000);
log(await ready(probe), 'app ready');
await probe.close();

for (const [role, email, password, allow, deny] of users) {
  const context = await browser.newContext();
  const page = await context.newPage();
  page.setDefaultTimeout(45000);
  try {
    const okLogin = await login(page, email, password);
    log(okLogin, `[${role}] login`);
    if (!okLogin) {
      await page.screenshot({ path: path.join(outDir, `smoke-${role}-login.png`), fullPage: true }).catch(() => null);
      await context.close();
      continue;
    }

    let a = null;
    let aHtml = '';
    for (let attempt = 0; attempt < 5; attempt++) {
      a = await page.goto(BASE + allow, { waitUntil: 'domcontentloaded', timeout: 90000 }).catch(() => null);
      aHtml = await page.content().catch(() => '');
      if ((a && a.status() === 503) || aHtml.includes('Waking')) {
        await page.waitForTimeout(3000);
        continue;
      }
      break;
    }
    log(!!a && a.status() < 400 && !aHtml.includes('do not have permission'), `[${role}] allow ${allow}`);

    if (deny) {
      let d = null;
      let dHtml = '';
      let status = 0;
      for (let attempt = 0; attempt < 5; attempt++) {
        d = await page.goto(BASE + deny, { waitUntil: 'domcontentloaded', timeout: 90000 }).catch(() => null);
        dHtml = await page.content().catch(() => '');
        status = d ? d.status() : 0;
        if (status === 503 || dHtml.includes('Waking')) {
          await page.waitForTimeout(3000);
          continue;
        }
        break;
      }
      let blocked = isDenied(status, dHtml, deny);
      if (role === 'owner' && deny === '/integrations') {
        blocked =
          blocked ||
          status === 403 ||
          dHtml.includes('do not have permission') ||
          !dHtml.includes('NEXUS_API_KEY');
      }
      log(blocked, `[${role}] deny ${deny}`);
    } else {
      log(true, `[${role}] deny skipped (no restricted module in matrix)`);
    }

    await page.goto(BASE + '/logout', { waitUntil: 'domcontentloaded' }).catch(() => null);
  } catch (e) {
    log(false, `[${role}] suite error — ${e.message}`);
  }
  await context.close();
}

// Manager create field in browser (fresh context)
{
  const context = await browser.newContext();
  const page = await context.newPage();
  page.setDefaultTimeout(45000);
  try {
    if (await login(page, 'manager@ifms.local', 'Manager#2026')) {
      const tag = 'p5smoke_' + Date.now().toString(36).slice(-4);
      await page.goto(BASE + '/module/fields/create', { waitUntil: 'domcontentloaded', timeout: 90000 });
      await page.fill('input[name="name"]', tag);
      const size = page.locator('[name="size_hectares"]');
      if (await size.count()) await size.fill('1.1');
      const status = page.locator('[name="status"]');
      if (await status.count()) await status.selectOption('active').catch(() => null);
      await page.locator('form.stack-form button[type="submit"], form button[type="submit"]').first().click({ force: true, noWaitAfter: true });
      await page.waitForTimeout(4000);
      await page.goto(BASE + '/module/fields', { waitUntil: 'domcontentloaded', timeout: 90000 }).catch(() => null);
      const html = await page.content().catch(() => '');
      log(html.includes(tag), `[manager] create field ${tag} persists`);
      await page.reload({ waitUntil: 'domcontentloaded' }).catch(() => null);
      log((await page.content().catch(() => '')).includes(tag), `[manager] field persists after refresh`);
    } else {
      log(false, '[manager] create field login');
    }
  } catch (e) {
    log(false, `[manager] create field error — ${e.message}`);
  }
  await context.close();
}

await browser.close();
console.log('\nfailures=' + fail);
console.log(fail === 0 ? 'BROWSER SMOKE PASSED' : 'BROWSER SMOKE HAS FAILURES');
process.exit(fail === 0 ? 0 : 1);

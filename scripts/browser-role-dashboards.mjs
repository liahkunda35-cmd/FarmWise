/**
 * Six-role browser check. Waits for page conditions instead of treating a partial run as success.
 * node scripts/browser-role-dashboards.mjs
 */
import { chromium } from 'playwright';

const BASE = process.env.FARMWISE_BASE || 'http://127.0.0.1:8081';
const roles = [
  {
    role: 'admin',
    email: 'admin@ifms.local',
    password: 'Admin#2026',
    headline: 'Platform',
    card: 'Accounts awaiting approval',
    allow: '/users',
    allowText: 'Users',
    deny: '',
  },
  {
    role: 'owner',
    email: 'owner@ifms.local',
    password: 'Owner#2026',
    headline: 'Healthy Crops',
    card: 'Income this month',
    allow: '/module/fields',
    allowText: 'Field',
    deny: '/integrations',
  },
  {
    role: 'manager',
    email: 'manager@ifms.local',
    password: 'Manager#2026',
    headline: 'Farm operations',
    card: 'Open tasks',
    allow: '/module/tasks',
    allowText: 'Task',
    deny: '/module/payments/create',
  },
  {
    role: 'agronomist',
    email: 'agro@ifms.local',
    password: 'Agro#2026',
    headline: 'Crop health',
    card: 'High or critical scouting',
    allow: '/module/pest_scouting',
    allowText: 'Scout',
    deny: '/module/payroll',
  },
  {
    role: 'worker',
    email: 'worker@ifms.local',
    password: 'Worker#2026',
    headline: 'Your tasks',
    card: 'My open tasks',
    allow: '/module/tasks',
    allowText: 'Task',
    deny: '/module/transactions',
  },
  {
    role: 'accountant',
    email: 'accounts@ifms.local',
    password: 'Accounts#2026',
    headline: 'Cash position',
    card: 'Unpaid invoices',
    allow: '/reports?type=budget',
    allowText: 'counted once',
    deny: '/module/plantings/create',
  },
];

let fail = 0;
const log = (ok, msg) => {
  if (!ok) fail++;
  console.log((ok ? 'OK  ' : 'FAIL') + ' ' + msg);
};

async function waitUntilReady(page, predicate, timeout = 180000) {
  await page.waitForFunction(predicate, null, { timeout });
}

async function login(page, email, password) {
  await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded', timeout: 180000 });
  await waitUntilReady(page, () => {
    const body = document.body ? document.body.innerText : '';
    return !!document.querySelector('input[name="email"]') && !body.includes('Waking the FarmWise database');
  });
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', password);
  await page.getByRole('button', { name: /sign in/i }).click();
  try {
    await waitUntilReady(page, () => {
      const body = document.body ? document.body.innerText : '';
      if (body.includes('Waking the FarmWise database')) return false;
      if (/login failed/i.test(body)) return true;
      return location.pathname.includes('dashboard');
    });
  } catch {
    return false;
  }
  const body = await page.locator('body').innerText();
  return locationPath(page).includes('dashboard') && !/login failed/i.test(body);
}

function locationPath(page) {
  return new URL(page.url()).pathname;
}

async function openPage(page, path) {
  const response = await page.goto(BASE + path, { waitUntil: 'domcontentloaded', timeout: 180000 });
  await page.waitForLoadState('domcontentloaded');
  try {
    await waitUntilReady(page, () => {
      const body = document.body ? document.body.innerText : '';
      return body.length > 0 && !body.includes('Waking the FarmWise database');
    }, 180000);
  } catch {
    // The status and body captured below still decide the assertion.
  }
  const text = await page.locator('body').innerText().catch(() => '');
  return { status: response ? response.status() : 0, text };
}

const browser = await chromium.launch({ headless: true });
try {
  const only = process.env.FARMWISE_ROLE || '';
  for (const spec of roles) {
    if (only && spec.role !== only) continue;
    console.log(`-- ${spec.role}`);
    const context = await browser.newContext();
    const page = await context.newPage();
    page.setDefaultTimeout(180000);
    try {
      const signedIn = await login(page, spec.email, spec.password);
      log(signedIn, `[${spec.role}] login`);
      if (!signedIn) continue;

      const dash = await openPage(page, '/dashboard');
      log(dash.status === 200 && dash.text.includes(spec.headline), `[${spec.role}] dashboard headline`);
      log(dash.text.includes(spec.card), `[${spec.role}] dashboard card`);
      if (spec.role === 'worker') {
        log(!dash.text.includes('Income this month') && !dash.text.includes('Unpaid invoices'), `[${spec.role}] dashboard hides finance`);
      }
      if (spec.role === 'accountant') {
        log(!dash.text.includes('My open tasks') && !dash.text.includes('Active crops'), `[${spec.role}] dashboard hides field work`);
      }

      const allowed = await openPage(page, spec.allow);
      log(allowed.status === 200 && allowed.text.includes(spec.allowText), `[${spec.role}] permitted ${spec.allow}`);

      if (spec.deny) {
        const denied = await openPage(page, spec.deny);
        const blocked = denied.status === 403 || /do not have permission/i.test(denied.text);
        log(blocked, `[${spec.role}] denied ${spec.deny} status=${denied.status}`);
      }

      const missingFarm = await openPage(page, '/module/farms/view/2147483000');
      const farmBlocked = spec.role === 'admin'
        ? missingFarm.status === 404 || /not found/i.test(missingFarm.text)
        : missingFarm.status === 403 || missingFarm.status === 404 || /not found|do not have permission/i.test(missingFarm.text);
      log(farmBlocked, `[${spec.role}] unknown farm id status=${missingFarm.status}`);

      const search = await openPage(page, '/search?q=zz');
      log(search.status === 200, `[${spec.role}] search loads`);
      if (spec.role === 'worker') {
        log(!/\/module\/transactions|\/module\/payroll|\/module\/payments/i.test(search.text), `[${spec.role}] search hides finance`);
      }

      const notes = await openPage(page, '/notifications');
      log(notes.status === 200, `[${spec.role}] notifications load`);

      if (spec.role === 'worker') {
        const create = await page.request.post(BASE + '/module/tasks/store', {
          form: { title: 'tampered', assigned_to: '1', farm_id: '1', status: 'pending' },
          failOnStatusCode: false,
        });
        log(create.status() === 403, `[${spec.role}] task create rejected status=${create.status()}`);
        const apiLogin = await page.request.post(BASE + '/api/login', {
          data: { email: spec.email, password: spec.password },
          failOnStatusCode: false,
        });
        const apiBody = await apiLogin.json().catch(() => ({}));
        const token = apiBody?.data?.token || '';
        log(apiLogin.status() === 200 && token !== '', `[${spec.role}] api login`);
        if (token) {
          const apiCreate = await page.request.post(BASE + '/api/module/tasks', {
            headers: { Authorization: 'Bearer ' + token },
            data: { title: 'tampered', assigned_to: 1, farm_id: 1 },
            failOnStatusCode: false,
          });
          log(apiCreate.status() === 403, `[${spec.role}] api task create rejected status=${apiCreate.status()}`);
          const apiFinance = await page.request.get(BASE + '/api/module/transactions', {
            headers: { Authorization: 'Bearer ' + token },
            failOnStatusCode: false,
          });
          log(apiFinance.status() === 403, `[${spec.role}] api transactions rejected status=${apiFinance.status()}`);
        }
      }

      if (spec.role === 'agronomist') {
        const exported = await page.request.get(BASE + '/reports?type=financial&export=csv', { failOnStatusCode: false });
        const exportText = await exported.text();
        log(exported.status() === 403 || /do not have permission/i.test(exportText), `[${spec.role}] financial export rejected status=${exported.status()}`);
      }
    } catch (error) {
      log(false, `[${spec.role}] ${error instanceof Error ? error.message : 'browser error'}`);
    } finally {
      await context.close();
    }
  }
} finally {
  await browser.close();
}

console.log(fail === 0 ? 'ALL PASSED' : `HAS FAILURES ${fail}`);
process.exit(fail === 0 ? 0 : 1);

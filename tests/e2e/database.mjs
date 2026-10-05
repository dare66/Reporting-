// Database onboarding end to end: connect a PostgreSQL database, choose tables, load them with
// progress, and land in Auto BI with the tables understood and joined. Removes the source after.
// Needs a reachable PostgreSQL to act as "the customer's database" (by default the demo warehouse):
// SOURCE_DB_HOST=localhost SOURCE_DB_NAME=aixbi SOURCE_DB_USER=… SOURCE_DB_PASSWORD=… [SOURCE_DB_SCHEMA=analytics]
// BASE=http://localhost:4200 node database.mjs   (CHROMIUM=/path/to/chromium; SHOTS=dir to save screenshots)
import { chromium } from 'playwright';
import assert from 'node:assert/strict';

const BASE = process.env.BASE ?? 'http://localhost:4200';
const SHOTS = process.env.SHOTS;
const DB = {
  host: process.env.SOURCE_DB_HOST ?? 'localhost',
  port: process.env.SOURCE_DB_PORT ?? '5432',
  database: process.env.SOURCE_DB_NAME ?? 'aixbi',
  username: process.env.SOURCE_DB_USER,
  password: process.env.SOURCE_DB_PASSWORD,
  schema: process.env.SOURCE_DB_SCHEMA ?? 'analytics',
};
assert.ok(DB.username, 'Set SOURCE_DB_USER and SOURCE_DB_PASSWORD to the database this journey connects to.');
const IGNORED = ['fonts.googleapis.com', 'fonts.gstatic.com', 'ERR_CERT_AUTHORITY_INVALID'];
const browser = await chromium.launch({
  executablePath: process.env.CHROMIUM,
  args: ['--no-sandbox'],
});
const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
const problems = [];
page.on('pageerror', (e) => problems.push('uncaught: ' + e.message));
page.on(
  'console',
  (m) => m.type() === 'error' && !IGNORED.some((x) => m.text().includes(x)) && problems.push('console: ' + m.text()),
);
page.on(
  'response',
  (r) => r.url().includes('/api/') && r.status() >= 500 && problems.push(`HTTP ${r.status()} ${r.url()}`),
);
const step = async (name, fn) => {
  const t = Date.now();
  await fn();
  console.log(`✓ ${name} (${Date.now() - t} ms)`);
};
const shot = (name) => SHOTS && page.screenshot({ path: `${SHOTS}/${name}.png` });

await page.goto(BASE + '/');
await page.evaluate(async () => {
  const r = await fetch('/api/v1/auth/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      email: 'engineer@emgs.demo',
      password: 'Demo@2026!',
    }),
  });
  localStorage.setItem('aixbi.refresh', (await r.json()).refresh_token);
});

let sourceId = '';
try {
  await step('connecting a database leads straight to choosing its tables', async () => {
    await page.goto(BASE + '/data');
    await page.locator('button.conn', { hasText: 'PostgreSQL' }).click();
    const form = page.getByRole('dialog', { name: 'Connect a data source' });
    await form.locator('input').first().fill('Warehouse (e2e)');
    for (const [key, value] of Object.entries(DB)) {
      await form.getByLabel(new RegExp(`^${key}`, 'i')).fill(String(value));
    }
    await form.getByRole('button', { name: /Connect|Save|Create/ }).click();
    const dialog = page.getByRole('dialog', { name: /^Load Warehouse/ });
    await dialog.getByText('applications', { exact: true }).waitFor({ timeout: 30000 });
    await shot('db-1-tables');
  });

  await step('three tables load with progress, at most 10K rows each', async () => {
    const dialog = page.getByRole('dialog', { name: /^Load Warehouse/ });
    await dialog.getByRole('checkbox', { name: /selected$/ }).click();
    await dialog.locator('.row label', { hasText: /^\s*0 of \d+ selected/ }).waitFor();
    for (const t of ['applications', 'countries', 'institutions'])
      await dialog.getByLabel(`Load ${t}`, { exact: true }).check();
    await dialog.getByLabel('Rows per table').selectOption({ label: '10.0K' });
    await dialog.getByRole('button', { name: 'Load 3 tables' }).click();
    await dialog.getByText(/3 of 3 loaded/).waitFor({ timeout: 180000 });
    await dialog.getByText('10,000 rows').waitFor();
    await shot('db-2-loaded');
  });

  await step('Auto BI designs from the loaded tables, joined', async () => {
    await page.getByRole('button', { name: 'Design dashboard & report' }).click();
    await page.waitForURL(/\/data\/sources\/[^/]+\/auto-bi/);
    sourceId = page.url().split('/data/sources/')[1].split('/')[0];
    await page.getByText('Auto BI Designer').waitFor({ timeout: 60000 });
    await page.locator('.table-card', { hasText: 'Main table' }).getByText('Applications').waitFor();
    assert.ok((await page.locator('.joins details').count()) >= 2, 'applications join to countries and institutions');
    await shot('db-3-auto-bi');
  });

  assert.deepEqual(problems, []);
} finally {
  // Remove the source (and everything loaded from it) through the same safe path people use.
  const id = sourceId;
  await page.evaluate(async (id) => {
    const t = await (
      await fetch('/api/v1/auth/refresh', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          refresh_token: localStorage.getItem('aixbi.refresh'),
        }),
      })
    ).json();
    localStorage.setItem('aixbi.refresh', t.refresh_token);
    const auth = { Authorization: `Bearer ${t.access_token}` };
    const sources = (await (await fetch('/api/v1/data-sources', { headers: auth })).json()).data;
    for (const s of sources.filter((s) => s.id === id || s.name === 'Warehouse (e2e)'))
      await fetch(`/api/v1/data-sources/${s.id}`, {
        method: 'DELETE',
        headers: auth,
      });
  }, id);
  await browser.close();
}
console.log('Database onboarding journey passed.');

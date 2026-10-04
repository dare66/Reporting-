// Widget Studio and dashboard filters, end to end against a running stack.
// Works on a scratch dashboard (created and deleted here), so seeded data is untouched.
// BASE=http://localhost:4200 node studio.mjs   (CHROMIUM=/path/to/chromium; SHOTS=dir to save screenshots)
import { chromium } from 'playwright';
import assert from 'node:assert/strict';

const BASE = process.env.BASE ?? 'http://localhost:4200';
const SHOTS = process.env.SHOTS;
const IGNORED = ['fonts.googleapis.com', 'fonts.gstatic.com', 'ERR_CERT_AUTHORITY_INVALID'];

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM, args: ['--no-sandbox'] });
const page = await browser.newPage({ viewport: { width: 1600, height: 1000 } });
const problems = [];
page.on('pageerror', (e) => problems.push('uncaught: ' + e.message));
page.on('console', (m) => {
  if (m.type() === 'error' && !IGNORED.some((x) => m.text().includes(x))) problems.push('console: ' + m.text());
});
page.on('response', (r) => {
  if (r.url().includes('/api/') && r.status() >= 500) problems.push(`HTTP ${r.status()} ${r.url()}`);
});
const step = async (name, fn) => {
  const t = Date.now();
  await fn();
  console.log(`✓ ${name} (${Date.now() - t} ms)`);
};
const shot = async (name) => SHOTS && page.screenshot({ path: `${SHOTS}/${name}.png` });

// Sign in as an analyst (can build dashboards) and create the scratch dashboard through the API.
await page.goto(BASE + '/');
const { dashboardId } = await page.evaluate(async () => {
  const login = await (
    await fetch('/api/v1/auth/login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email: 'analyst@emgs.demo', password: 'Demo@2026!' }),
    })
  ).json();
  localStorage.setItem('aixbi.refresh', login.refresh_token);
  const created = await (
    await fetch('/api/v1/dashboards', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${login.access_token}` },
      body: JSON.stringify({
        title: 'E2E Widget Studio',
        widgets: [
          {
            type: 'chart',
            title: 'Applications by region',
            query: { model: 'applications', metrics: ['total_applications'], dimensions: ['region'], time: { range: 'last_12_months' } },
            viz: { type: 'column' },
          },
        ],
      }),
    })
  ).json();
  return { dashboardId: created.data.id };
});

try {
  await step('dashboard opens with its filter bar', async () => {
    await page.goto(`${BASE}/dashboards/${dashboardId}`);
    await page.waitForSelector('app-widget app-chart canvas', { timeout: 30000 });
    await page.getByRole('toolbar', { name: 'Dashboard filters' }).waitFor();
  });

  await step('a members filter narrows every widget and reads as a sentence', async () => {
    await page.getByRole('button', { name: 'Filter', exact: true }).click();
    await page.getByRole('button', { name: /^Region/ }).click();
    await page.getByRole('searchbox', { name: 'Search Region' }).fill('east');
    await page.getByRole('checkbox', { name: 'East Asia', exact: true }).check();
    assert.equal(await page.getByRole('checkbox', { name: 'Central Asia' }).count(), 0, 'search narrows the members');
    await page.getByRole('button', { name: 'Apply' }).click();
    await page.getByRole('button', { name: 'Edit filter: Region: East Asia' }).waitFor();
    await page.waitForSelector('app-widget app-chart canvas', { timeout: 30000 });
    await shot('01-dashboard-filtered');
  });

  await step('Widget Studio builds a stacked running-total column with break-by', async () => {
    await page.getByRole('button', { name: 'Edit layout' }).click();
    await page.getByRole('button', { name: 'Add widget' }).click();
    const studio = page.getByRole('dialog', { name: 'Widget Studio' });
    await studio.waitFor();
    await studio.getByLabel('Data model').selectOption('applications');
    await studio.getByRole('radio', { name: 'Column' }).click();
    await studio.getByLabel('Set Category', { exact: true }).selectOption({ label: 'Submission Date (time)' });
    await studio.getByLabel('Add a value', { exact: true }).selectOption({ label: 'Applications' });
    await studio.getByLabel('Set Break by', { exact: true }).selectOption({ label: 'Channel' });
    await studio.getByRole('radio', { name: 'Stacked' }).click();
    await studio.locator('app-chart canvas').waitFor({ timeout: 30000 });

    await studio.getByRole('button', { name: 'Options for Applications' }).click();
    await studio.getByLabel('Quick function').selectOption('running_sum');
    await studio.getByText(/rows · \d+ ms/).waitFor({ timeout: 30000 });
    await page.waitForTimeout(800);
    await shot('02-studio-running-total');
    await studio.getByRole('button', { name: 'Add to dashboard' }).click();
    await studio.waitFor({ state: 'detached' });
    assert.equal(await page.locator('.cell').count(), 2);
  });

  await step('a saved widget reopens in the studio and becomes a pivot', async () => {
    await page.getByRole('button', { name: 'Edit in Widget Studio' }).last().click();
    const studio = page.getByRole('dialog', { name: 'Widget Studio' });
    await studio.getByRole('button', { name: /Options for Applications · Running total/ }).waitFor();
    await studio.getByRole('radio', { name: 'Pivot' }).click();
    await studio.locator('app-pivot-table table').waitFor({ timeout: 30000 });
    await shot('03-studio-pivot');
    await studio.getByRole('button', { name: 'Save changes' }).click();
    await studio.waitFor({ state: 'detached' });
    await page.getByRole('button', { name: 'Save layout' }).click();
    await page.waitForSelector('app-widget app-pivot-table table', { timeout: 30000 });
    await shot('04-dashboard-with-pivot');
  });

  await step('ratios refuse running totals, with the reason shown', async () => {
    await page.getByRole('button', { name: 'Edit layout' }).click();
    await page.getByRole('button', { name: 'Add widget' }).click();
    const studio = page.getByRole('dialog', { name: 'Widget Studio' });
    await studio.getByLabel('Data model').selectOption('applications');
    await studio.getByLabel('Set Category', { exact: true }).selectOption({ label: 'Region' });
    await studio.getByLabel('Add a value', { exact: true }).selectOption({ label: 'High-risk Share' });
    await studio.getByRole('button', { name: 'Options for High-risk Share' }).click();
    const option = studio.getByLabel('Quick function').locator('option[value="percent_of_total"]');
    // isDisabled() reports the select, not the option, so read the option's own state.
    assert.equal(await option.evaluate((o) => o.disabled), true);
    assert.match(await option.innerText(), /cannot be added up/);
    await studio.getByRole('button', { name: 'Cancel' }).click();
    await studio.waitFor({ state: 'detached' });
    await shot('05-after-cancel');
    await page.getByRole('button', { name: 'Cancel' }).click();
  });

  assert.deepEqual(problems, []);
} finally {
  await page.evaluate(async (id) => {
    const refresh = await (
      await fetch('/api/v1/auth/refresh', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ refresh_token: localStorage.getItem('aixbi.refresh') }),
      })
    ).json();
    localStorage.setItem('aixbi.refresh', refresh.refresh_token);
    await fetch(`/api/v1/dashboards/${id}`, { method: 'DELETE', headers: { Authorization: `Bearer ${refresh.access_token}` } });
  }, dashboardId);
  await browser.close();
}
console.log('Widget Studio journey passed.');

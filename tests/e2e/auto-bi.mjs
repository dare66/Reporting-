// Auto BI Designer end to end: upload a three-sheet workbook through the UI, review
// what AIXBI understood, approve KPIs, publish, and check the dashboard and report render
// from the uploaded data. Removes the uploaded source afterwards.
// BASE=http://localhost:4200 node auto-bi.mjs   (CHROMIUM=/path/to/chromium; SHOTS=dir to save screenshots)
import { chromium } from 'playwright';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';

const BASE = process.env.BASE ?? 'http://localhost:4200';
const SHOTS = process.env.SHOTS;
const WORKBOOK = fileURLToPath(new URL('./fixtures/Student Applications.xlsx', import.meta.url));
const IGNORED = ['fonts.googleapis.com', 'fonts.gstatic.com', 'ERR_CERT_AUTHORITY_INVALID'];

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM, args: ['--no-sandbox'] });
const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
const problems = [];
page.on('pageerror', (e) => problems.push('uncaught: ' + e.message));
page.on('console', (m) => m.type() === 'error' && !IGNORED.some((x) => m.text().includes(x)) && problems.push('console: ' + m.text()));
page.on('response', (r) => r.url().includes('/api/') && r.status() >= 500 && problems.push(`HTTP ${r.status()} ${r.url()}`));
const step = async (name, fn) => {
  const t = Date.now();
  await fn();
  console.log(`✓ ${name} (${Date.now() - t} ms)`);
};
const shot = (name) => SHOTS && page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: true });

await page.goto(BASE + '/');
await page.evaluate(async () => {
  const r = await fetch('/api/v1/auth/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email: 'admin@emgs.demo', password: 'Demo@2026!' }),
  });
  localStorage.setItem('aixbi.refresh', (await r.json()).refresh_token);
});

let sourceId = '';
try {
  await step('a workbook upload lands in the Auto BI Designer', async () => {
    await page.goto(BASE + '/data');
    await page.locator('input[type=file]').setInputFiles(WORKBOOK);
    await page.waitForURL(/\/data\/sources\/[^/]+\/auto-bi/, { timeout: 120000 });
    sourceId = page.url().split('/data/sources/')[1].split('/')[0];
    await page.getByText('Auto BI Designer').waitFor({ timeout: 60000 });
  });

  await step('it explains what the data is, how tables join and what each column means', async () => {
    await page.getByText('student applications', { exact: true }).waitFor();
    assert.equal(await page.locator('.table-card').count(), 3);
    assert.equal(await page.locator('.joins details').count(), 2);
    const row = page.locator('table.fields tr', { hasText: 'Submitted Date' });
    assert.match(await row.innerText(), /Date/);
    await page.locator('.joins summary').first().click();
    await page.getByText(/values in .* are found in/).first().waitFor();
    await shot('auto-bi-1-understand');
  });

  await step('KPIs are proposed with formulas and can be renamed and approved', async () => {
    await page.getByRole('button', { name: 'Review KPIs →' }).click();
    await page.getByText("COUNT(Applications WHERE Status = 'Approved') ÷ COUNT(Applications)").waitFor();
    await page.getByLabel('Name for Approved rate').fill('Approval rate');
    const rejected = page.getByLabel('Approve Rejected rate');
    if (!(await rejected.isChecked())) await rejected.check();
    await shot('auto-bi-2-kpis');
  });

  await step('the design shows a reasoned blueprint and an operations variant', async () => {
    await page.getByRole('button', { name: 'Design the dashboard →' }).click();
    await page.getByText('Dashboard blueprint').waitFor();
    await page.locator('.cell', { hasText: 'Approval rate' }).first().waitFor();
    await page.getByRole('radio', { name: 'Operations' }).click();
    await page.locator('.cell[data-type=heatmap]').waitFor();
    await shot('auto-bi-3-design');
  });

  let dashboardUrl = '';
  let reportUrl = '';
  await step('publishing builds the model, dashboard and report', async () => {
    await page.getByRole('button', { name: 'Publish model, dashboard and report' }).click();
    await page.getByText('Published.').waitFor({ timeout: 120000 });
    dashboardUrl = await page.getByRole('link', { name: 'Open dashboard' }).getAttribute('href');
    reportUrl = await page.getByRole('link', { name: 'Open report' }).getAttribute('href');
    await shot('auto-bi-4-published');
  });

  await step('the generated dashboard renders every widget from the uploaded data', async () => {
    await page.goto(BASE + dashboardUrl);
    await page.waitForSelector('app-widget app-chart canvas', { timeout: 60000 });
    await page.waitForTimeout(1500);
    const widgets = await page.locator('app-widget').count();
    assert.ok(widgets >= 8, `expected at least 8 widgets, saw ${widgets}`);
    assert.equal(await page.locator('app-widget app-error').count(), 0, 'no widget failed');
    await page.getByText('Approval rate').first().waitFor();
    await shot('auto-bi-5-dashboard');
  });

  await step('the generated report reads as an executive briefing', async () => {
    await page.goto(BASE + reportUrl);
    await page.getByText('Executive summary').first().waitFor({ timeout: 60000 });
    await page.waitForTimeout(1000);
    await shot('auto-bi-6-report');
  });

  assert.deepEqual(problems, []);
} finally {
  if (sourceId) {
    await page.evaluate(async (id) => {
      const refresh = await (
        await fetch('/api/v1/auth/refresh', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ refresh_token: localStorage.getItem('aixbi.refresh') }),
        })
      ).json();
      localStorage.setItem('aixbi.refresh', refresh.refresh_token);
      await fetch(`/api/v1/data-sources/${id}`, { method: 'DELETE', headers: { Authorization: `Bearer ${refresh.access_token}` } });
    }, sourceId);
  }
  await browser.close();
}
console.log('Auto BI journey passed.');

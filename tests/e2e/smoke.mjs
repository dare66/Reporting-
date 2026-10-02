// End-to-end smoke test against a running stack.
// BASE=http://localhost:4200 node smoke.mjs   (CHROMIUM=/path/to/chromium to use a preinstalled browser)
import { chromium } from 'playwright';
import assert from 'node:assert/strict';

const BASE = process.env.BASE ?? 'http://localhost:4200';
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM, args: ['--no-sandbox'] });
const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
const errors = [];
page.on('pageerror', e => errors.push(e.message));
const step = async (name, fn) => { const t = Date.now(); await fn(); console.log(`✓ ${name} (${Date.now() - t} ms)`); };

await step('landing renders and CEO signs in', async () => {
  await page.goto(BASE + '/');
  await page.getByRole('button', { name: 'Explore intelligence' }).click();
  await page.getByRole('button', { name: 'Continue' }).click();
  await page.waitForURL('**/home');
});

await step('command centre shows ranked attention and live KPIs', async () => {
  await page.waitForSelector('app-kpi', { timeout: 30000 });
  assert.equal(await page.locator('app-kpi').count() >= 4, true);
  assert.match(await page.locator('.attention .t').innerText(), /attention/);
  assert.match(await page.locator('.summary .lead').innerText(), /performance/i);
});

await step('AI analyst explains a change with drivers and evidence', async () => {
  await page.goto(BASE + '/ai?q=' + encodeURIComponent('Why did SLA fall?'));
  await page.waitForSelector('app-drivers', { timeout: 60000 });
  await page.locator('button', { hasText: 'Evidence (' }).last().waitFor({ timeout: 60000 });
  const answer = await page.locator('.answer').last().innerText();
  assert.match(answer, /Processing SLA/);
});

await step('follow-up keeps conversation context', async () => {
  await page.locator('textarea[name=q]').fill('Show me the affected institutions');
  await page.keyboard.press('Enter');
  await page.waitForFunction(() => document.querySelectorAll('app-ai-block').length >= 3, null, { timeout: 60000 });
  assert.match(await page.locator('.answer').last().innerText(), /institution/i);
});

await step('investigate page decomposes the metric', async () => {
  await page.goto(BASE + '/investigate?metric=decisions.rejection_rate');
  await page.waitForSelector('app-drivers .leaf', { timeout: 30000 });
});

await step('dashboard renders widgets', async () => {
  await page.goto(BASE + '/dashboards');
  await page.locator('a.card').first().click();
  await page.waitForSelector('app-widget app-kpi', { timeout: 30000 });
});

await step('report generation from a template', async () => {
  await page.goto(BASE + '/reports');
  await page.getByRole('button', { name: 'Create report' }).click();
  await page.getByRole('button', { name: /Operations/ }).click();
  await page.locator('.area').first().click();
  await page.getByRole('button', { name: 'Generate report' }).click();
  await page.waitForURL(/\/reports\/[0-9a-f-]{36}/, { timeout: 90000 });
  await page.waitForSelector('app-report-section', { timeout: 30000 });
});

assert.deepEqual(errors, [], 'no uncaught page errors');
await browser.close();
console.log('E2E smoke passed');

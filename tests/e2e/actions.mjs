// Test 6 of the brief, end to end: ask the AI analyst to "create an incident for the biggest issue";
// it proposes one with evidence and nothing happens until a person allowed to approve it does.
// Approving runs it, checks the incident exists, and the incident can then be worked.
// BASE=http://localhost:4200 node actions.mjs   (CHROMIUM=/path/to/chromium; SHOTS=dir to save screenshots)
import { chromium } from 'playwright';
import assert from 'node:assert/strict';

const BASE = process.env.BASE ?? 'http://localhost:4200';
const SHOTS = process.env.SHOTS;
const IGNORED = ['fonts.googleapis.com', 'fonts.gstatic.com', 'ERR_CERT_AUTHORITY_INVALID'];
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM, args: ['--no-sandbox'] });
const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
const problems = [];
page.on('pageerror', (e) => problems.push('uncaught: ' + e.message));
page.on(
  'console',
  (m) =>
    m.type() === 'error' &&
    !IGNORED.some((x) => m.text().includes(x)) &&
    problems.push(`console (${current}): ` + m.text()),
);
page.on(
  'response',
  (r) => r.url().includes('/api/') && r.status() >= 500 && problems.push(`HTTP ${r.status()} ${r.url()}`),
);
let current = '';
const step = async (name, fn) => {
  current = name;
  const t = Date.now();
  await fn();
  console.log(`✓ ${name} (${Date.now() - t} ms)`);
};
const shot = (name) => SHOTS && page.screenshot({ path: `${SHOTS}/${name}.png` });
const signIn = (email) =>
  page.evaluate(async (email) => {
    const r = await fetch('/api/v1/auth/login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email, password: 'Demo@2026!' }),
    });
    localStorage.setItem('aixbi.refresh', (await r.json()).refresh_token);
  }, email);

await page.goto(BASE + '/');
await signIn('ceo@emgs.demo');
let reference = '';
try {
  await step('the AI analyst proposes an incident for the biggest issue, with evidence', async () => {
    await page.goto(BASE + '/ai?q=' + encodeURIComponent('Create an incident for the biggest issue'));
    const card = page.locator('app-action-card').last();
    await card.getByText('Awaiting approval').waitFor({ timeout: 90000 });
    await card.getByText('Evidence').waitFor();
    await card.getByText(/Waiting for approval/).waitFor();
    assert.equal(await card.getByText(/INC-\d{4}/).count(), 0, 'nothing is opened before approval');
    await shot('actions-1-proposed');
  });

  await step('approving runs it, verifies it and shows the incident', async () => {
    const card = page.locator('app-action-card').last();
    await card.getByRole('button', { name: /Approve and run/ }).click();
    await card.getByText('Done and checked').waitFor({ timeout: 30000 });
    reference = (await card.getByRole('link', { name: /INC-\d{4}/ }).innerText()).trim();
    assert.match(reference, /^INC-\d{4}$/);
    await shot('actions-2-done');
  });

  await step('the incident is in the Actions page and can be worked', async () => {
    await page.locator('app-action-card').last().getByRole('link', { name: reference }).click();
    await page.waitForURL(/\/actions\?incident=/);
    const row = page.locator('tr', { hasText: reference });
    await row.waitFor();
    for (const status of ['investigating', 'resolved']) {
      await page.locator('tr', { hasText: reference }).getByRole('combobox').selectOption(status);
      await page.waitForFunction(
        ([ref, status]) =>
          [...document.querySelectorAll('tr')].some(
            (tr) => tr.textContent.includes(ref) && tr.querySelector('select')?.value === status,
          ),
        [reference, status],
      );
    }
    await page.getByRole('button', { name: 'All actions' }).click();
    await page.locator('app-action-card', { hasText: 'Approved' }).first().waitFor();
    await shot('actions-3-incidents');
  });

  await step('asking again while an incident is open points to it instead of opening another', async () => {
    // Resolved above, so this proposes afresh; withdrawn at once so the next run starts clean.
    await page.goto(BASE + '/ai?q=' + encodeURIComponent('Create an incident for the biggest issue'));
    const card = page.locator('app-action-card').last();
    await card.getByText('Awaiting approval').waitFor({ timeout: 90000 });
    await page.goto(BASE + '/ai?q=' + encodeURIComponent('Create an incident for the biggest issue'));
    await page
      .getByText(/already waiting for approval/)
      .first()
      .waitFor({ timeout: 90000 });
    await page.goto(BASE + '/actions');
    await page.locator('app-action-card').first().getByRole('button', { name: 'Withdraw' }).click();
    await page.locator('app-action-card').first().getByText('Withdrawn').waitFor();
  });

  assert.deepEqual(problems, []);
  await step('someone without the permission does not see Actions', async () => {
    await page.evaluate(() => localStorage.clear());
    await signIn('viewer@emgs.demo');
    await page.goto(BASE + '/home');
    await page.locator('nav').first().waitFor();
    assert.equal(await page.getByRole('link', { name: 'Actions', exact: true }).count(), 0);
    const status = await page.evaluate(async () => {
      const t = await (
        await fetch('/api/v1/auth/refresh', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ refresh_token: localStorage.getItem('aixbi.refresh') }),
        })
      ).json();
      localStorage.setItem('aixbi.refresh', t.refresh_token);
      return (await fetch('/api/v1/actions', { headers: { Authorization: `Bearer ${t.access_token}` } })).status;
    });
    assert.equal(status, 403);
  });
} finally {
  await browser.close();
}
console.log(`Action engine journey passed (${reference}).`);

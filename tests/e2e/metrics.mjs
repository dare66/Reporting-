// Metric store end to end: a data engineer changes how a certified KPI is calculated
// (certification lapses), approves it, cannot certify their own approval, and an
// administrator certifies it. The original definition is then restored and re-certified,
// leaving the demo tenant as it was.
// BASE=http://localhost:4200 node metrics.mjs   (CHROMIUM=/path/to/chromium; SHOTS=dir to save screenshots)
import { chromium } from 'playwright';
import assert from 'node:assert/strict';

const BASE = process.env.BASE ?? 'http://localhost:4200';
const SHOTS = process.env.SHOTS;
const IGNORED = ['fonts.googleapis.com', 'fonts.gstatic.com', 'ERR_CERT_AUTHORITY_INVALID'];
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM, args: ['--no-sandbox'] });
const problems = [];

async function session(email) {
  const page = await (await browser.newContext({ viewport: { width: 1440, height: 1000 } })).newPage();
  page.on('pageerror', (e) => problems.push('uncaught: ' + e.message));
  page.on('console', (m) => {
    // Refusals the test provokes on purpose (four-eyes) surface as 422s.
    if (m.type() === 'error' && !IGNORED.some((x) => m.text().includes(x)) && !m.text().includes('422')) problems.push('console: ' + m.text());
  });
  page.on('response', (r) => r.url().includes('/api/') && r.status() >= 500 && problems.push(`HTTP ${r.status()} ${r.url()}`));
  await page.goto(BASE + '/');
  await page.evaluate(async (email) => {
    const r = await fetch('/api/v1/auth/login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email, password: 'Demo@2026!' }),
    });
    localStorage.setItem('aixbi.refresh', (await r.json()).refresh_token);
  }, email);
  return page;
}
const step = async (name, fn) => {
  const t = Date.now();
  await fn();
  console.log(`✓ ${name} (${Date.now() - t} ms)`);
};
const shot = (page, name) => SHOTS && page.screenshot({ path: `${SHOTS}/${name}.png` });

async function openMetric(page, label) {
  await page.goto(BASE + '/metrics');
  await page.getByRole('button', { name: `Open ${label}`, exact: true }).click();
  const drawer = page.getByRole('dialog');
  await drawer.getByRole('heading', { name: 'Lifecycle' }).waitFor();
  return drawer;
}

const engineer = await session('engineer@emgs.demo');
const admin = await session('admin@emgs.demo');
const ORIGINAL = 'approved_count / decided_count';

try {
  await step('the store lists governed metrics with status, owners and usage', async () => {
    await admin.goto(BASE + '/metrics');
    await admin.getByRole('radio', { name: /Certified 7/ }).click();
    await admin.locator('tbody tr').nth(7).waitFor({ state: 'detached' });
    assert.equal(await admin.locator('tbody tr').count(), 7);
    await admin.getByRole('radio', { name: /^All/ }).click();
    await admin.getByLabel('Search metrics').fill('approval');
    await admin.getByRole('button', { name: 'Open Approval Rate', exact: true }).waitFor();
    await shot(admin, 'metrics-1-store');
  });

  await step('changing a certified calculation sends it back for approval', async () => {
    const drawer = await openMetric(engineer, 'Approval Rate');
    await drawer.getByRole('button', { name: 'Edit' }).click();
    await drawer.getByLabel('Calculation').fill('approved_count / (approved_count + rejected_count)');
    await drawer.getByRole('button', { name: 'Save new version' }).click();
    await drawer.getByText('needs approval again').waitFor();
    await drawer.locator('.status[data-status=proposed]').waitFor();
  });

  await step('the approver cannot also certify', async () => {
    const drawer = engineer.getByRole('dialog');
    await drawer.getByRole('button', { name: 'Approve definition' }).click();
    await drawer.getByText('You approved this definition, so someone else must certify it.').waitFor();
    assert.equal(await drawer.getByRole('button', { name: 'Certify' }).count(), 0);
  });

  await step('a second person certifies, and the version diff shows what changed', async () => {
    const drawer = await openMetric(admin, 'Approval Rate');
    await drawer.getByRole('button', { name: 'Certify' }).click();
    await drawer.getByText('Certified.', { exact: true }).waitFor();
    await drawer.getByRole('button', { name: 'What changed' }).first().click();
    await drawer.locator('.diff th', { hasText: 'Calculation' }).waitFor();
    await shot(admin, 'metrics-2-drawer');
  });

  await step('dashboards using the metric still render with the new definition', async () => {
    await admin.goto(BASE + '/dashboards');
    await admin.getByText('Processing Operations').first().click();
    await admin.locator('app-widget', { hasText: 'Institution scorecard' }).locator('table').waitFor({ timeout: 30000 });
    assert.equal(await admin.locator('app-widget app-error').count(), 0);
  });
} finally {
  // Put the demo back: restore the original calculation, approve, certify.
  const drawer = await openMetric(engineer, 'Approval Rate');
  const versions = await engineer.evaluate(async () => {
    const r = await fetch('/api/v1/auth/refresh', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ refresh_token: localStorage.getItem('aixbi.refresh') }),
    });
    const t = await r.json();
    localStorage.setItem('aixbi.refresh', t.refresh_token);
    return (await (await fetch('/api/v1/metric-store/decisions/approval_rate', { headers: { Authorization: `Bearer ${t.access_token}` } })).json()).data
      .versions;
  });
  const original = versions.find((v) => v.definition.expression === ORIGINAL && v.version > 1);
  if (original && versions[0].definition.expression !== ORIGINAL) {
    await drawer.locator('.versions li', { hasText: `v${original.version}` }).getByRole('button', { name: 'Restore' }).click();
    await drawer.getByText(`Version ${original.version} restored as a new version.`).waitFor();
    await drawer.getByRole('button', { name: 'Approve definition' }).click();
    await drawer.getByText('Approved. A second person can now certify it.').waitFor();
    const adminDrawer = await openMetric(admin, 'Approval Rate');
    await adminDrawer.getByRole('button', { name: 'Certify' }).click();
    await adminDrawer.getByText('Certified.', { exact: true }).waitFor();
  }
  await browser.close();
}
assert.deepEqual(problems, []);
console.log('Metric store journey passed.');

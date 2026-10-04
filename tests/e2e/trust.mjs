// Data Trust Center end to end: a file is loaded and modelled, then reloaded with a broken
// schema. The Trust Center shows the breaking change and what it affects, a data engineer
// acknowledges it, and the source is removed through the impact dialog, which cleans up
// everything it created.
// BASE=http://localhost:4200 node trust.mjs   (CHROMIUM=/path/to/chromium; SHOTS=dir to save screenshots)
import { chromium } from 'playwright';
import assert from 'node:assert/strict';

const BASE = process.env.BASE ?? 'http://localhost:4200';
const SHOTS = process.env.SHOTS;
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
const shot = (name) => SHOTS && page.screenshot({ path: `${SHOTS}/${name}.png` });

await page.goto(BASE + '/');
await page.evaluate(async () => {
  const r = await fetch('/api/v1/auth/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email: 'engineer@emgs.demo', password: 'Demo@2026!' }),
  });
  localStorage.setItem('aixbi.refresh', (await r.json()).refresh_token);
});

/** Calls the API as the signed-in person (refresh tokens rotate, so each call refreshes first). */
const api = (path, init = {}) =>
  page.evaluate(
    async ([path, init]) => {
      const t = await (
        await fetch('/api/v1/auth/refresh', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ refresh_token: localStorage.getItem('aixbi.refresh') }),
        })
      ).json();
      localStorage.setItem('aixbi.refresh', t.refresh_token);
      const headers = { Authorization: `Bearer ${t.access_token}`, Accept: 'application/json' };
      let body = init.body;
      if (init.csv) {
        body = new FormData();
        body.append('file', new File([init.csv], 'Drift Orders.csv', { type: 'text/csv' }));
      } else if (body) {
        headers['Content-Type'] = 'application/json';
      }
      const r = await fetch('/api/v1' + path, { method: init.method ?? 'GET', headers, body });
      return r.status === 204 ? null : r.json();
    },
    [path, init],
  );

const rows = (n, line) => Array.from({ length: n }, (_, i) => line(i + 1)).join('\n');
const regions = ['North', 'South', 'East'];
const sources = [];

try {
  await step('a loaded and modelled file is reloaded with a broken schema', async () => {
    const first = await api('/data/upload', {
      method: 'POST',
      csv: 'order_date,region,amount,units\n' + rows(60, (i) => `2026-03-${String((i % 27) + 1).padStart(2, '0')},${regions[i % 3]},${100 + i * 2.5},${i % 6}`),
    });
    sources.push(first.data.source.id);
    await api(`/datasets/${first.data.dataset.id}/semantic-model`, { method: 'POST', body: '{}' });
    const second = await api('/data/upload', {
      method: 'POST',
      csv: 'order_date,region,amount\n' + rows(60, (i) => `2026-03-${String((i % 27) + 1).padStart(2, '0')},${regions[i % 3]},RM ${100 + i}`),
    });
    sources.push(second.data.source.id);
  });

  await step('the Trust Center shows the breaking change and what it affects', async () => {
    await page.goto(BASE + '/trust');
    await page.getByRole('heading', { name: 'Data Trust Center' }).waitFor();
    await page.getByRole('button', { name: 'Open Drift Orders' }).click();
    const drawer = page.getByRole('dialog');
    await drawer.getByText('amount changed from decimal to string.').waitFor();
    await drawer.getByText('units is no longer in the data.').waitFor();
    await drawer.locator('.event[data-severity=critical] .chip', { hasText: 'Total Amount' }).first().waitFor();
    await drawer.getByText('Schema stability').waitFor();
    await page.waitForTimeout(600); // let the drawer finish sliding in before capturing it
    await shot('trust-1-drawer');
  });

  await step('a data engineer acknowledges it', async () => {
    const drawer = page.getByRole('dialog');
    const before = await drawer.getByRole('button', { name: 'Acknowledge' }).count();
    await drawer.getByRole('button', { name: 'Acknowledge' }).first().click();
    await page.waitForFunction((n) => document.querySelectorAll('[role=dialog] .event .btn').length === n - 1, before);
    await drawer.getByRole('button', { name: 'Close' }).click();
    await shot('trust-2-list');
  });

  await step('removing the source shows its impact, then cleans up everything it created', async () => {
    await page.goto(BASE + '/data');
    const card = page.locator('.src', { has: page.locator(`button[aria-label="Remove Drift Orders (upload)"]`) }).last();
    await card.getByRole('button', { name: 'Remove Drift Orders (upload)' }).click();
    const dialog = page.getByRole('dialog', { name: /Remove Drift Orders/ });
    await dialog.getByText('(table deleted)').waitFor();
    await dialog.getByText(/Data model .* and its \d+ metrics/).waitFor();
    await shot('trust-3-remove');
    await dialog.getByRole('button', { name: 'Remove permanently' }).click();
    await dialog.waitFor({ state: 'detached' });
    const trust = await api('/trust');
    assert.equal(trust.data.filter((d) => d.label === 'Drift Orders').length, 0, 'the dataset is gone from the Trust Center');
  });

  assert.deepEqual(problems, []);
} finally {
  // Whatever is left (the first upload's now-empty connection, or everything after a failure).
  for (const id of sources) await api(`/data-sources/${id}`, { method: 'DELETE' }).catch(() => {});
  await browser.close();
}
console.log('Data Trust Center journey passed.');

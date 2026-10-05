// Projects end to end: start a project, switch to it from the top bar, add data there, and see that
// each project shows only its own work. Removes what it created.
// BASE=http://localhost:4200 node projects.mjs   (CHROMIUM=/path/to/chromium; SHOTS=dir to save screenshots)
import { chromium } from 'playwright';
import assert from 'node:assert/strict';
import { writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const BASE = process.env.BASE ?? 'http://localhost:4200';
const SHOTS = process.env.SHOTS;
const NAME = 'E2E Finance ' + Date.now().toString(36);
const CSV = join(tmpdir(), 'Budget Lines.csv');
writeFileSync(
  CSV,
  'period,cost_centre,amount\n' +
    Array.from({ length: 40 }, (_, i) => {
      const d = new Date(Date.now() - i * 86400000).toISOString().slice(0, 10);
      return `${d},CC-${i % 4},${100 * (i + 1)}`;
    }).join('\n'),
);

const IGNORED = ['fonts.googleapis.com', 'fonts.gstatic.com', 'ERR_CERT_AUTHORITY_INVALID'];
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM, args: ['--no-sandbox'] });
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
const switcher = page.getByRole('button', { name: 'Choose project' });
const choose = async (label) => {
  await switcher.click();
  await page.getByRole('menuitemradio', { name: new RegExp('^' + label) }).click();
  // The live notification stream never lets the network go idle, so wait for the switch itself.
  await page.waitForFunction(
    (l) => document.querySelector('.proj-name')?.textContent?.trim() === l,
    label === 'General' ? 'General' : label,
  );
  await page.waitForTimeout(600);
};

await page.goto(BASE + '/');
await page.evaluate(async () => {
  const r = await fetch('/api/v1/auth/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email: 'engineer@emgs.demo', password: 'Demo@2026!' }),
  });
  localStorage.setItem('aixbi.refresh', (await r.json()).refresh_token);
});

let sourceId = '';
try {
  await step('a new project is created from the Projects page', async () => {
    await page.goto(BASE + '/projects');
    await page.getByRole('button', { name: 'New project' }).click();
    await page.getByLabel('Name').fill(NAME);
    await page.getByLabel('Description').fill('Budgets and spending');
    await page.getByRole('button', { name: 'Create project' }).click();
    await page.getByText(`${NAME} is ready`).waitFor();
    const card = page.locator('article.project', { hasText: NAME });
    await card.locator('.badge', { hasText: 'Owner' }).waitFor();
    await card.getByLabel('Role of Daniel Lim').waitFor();
    await card.locator('.badge', { hasText: 'Members only' }).waitFor();
    await shot('projects-created');
  });

  await step('switching to it from the top bar empties the dashboards list', async () => {
    await page.goto(BASE + '/dashboards');
    await page.locator('a.card', { hasText: 'Executive Overview' }).waitFor();
    await choose(NAME);
    await assert.doesNotReject(() => page.waitForURL(/\/dashboards$/));
    assert.equal((await switcher.innerText()).trim(), NAME);
    assert.equal(await page.locator('a.card').count(), 0, 'the demo dashboards are not in the new project');
    await shot('projects-dashboards-empty');
  });

  await step('data added there stays there', async () => {
    await page.goto(BASE + '/data');
    await page.locator('input[type=file]').setInputFiles(CSV);
    await page.waitForURL(/\/data\/sources\/[^/]+\/auto-bi/, { timeout: 120000 });
    sourceId = page.url().split('/sources/')[1].split('/')[0];
    await page.goto(BASE + '/data');
    await page.getByText('Budget Lines (upload)').first().waitFor();
    await choose('General');
    await page.waitForURL(/\/data$/);
    await page.waitForTimeout(500);
    assert.equal(await page.getByText('Budget Lines (upload)').count(), 0, 'the upload is not in General');
    await choose('All projects');
    await page.getByText('Budget Lines (upload)').first().waitFor();
  });

  await step('the choice is remembered after a reload', async () => {
    await choose(NAME);
    await page.goto(BASE + '/dashboards'); // a full page load
    await page.waitForFunction((n) => document.querySelector('.proj-name')?.textContent?.trim() === n, NAME);
    await page.getByRole('heading', { level: 1 }).waitFor();
    await page.waitForTimeout(800);
    assert.equal(await page.locator('a.card').count(), 0, 'still inside the project after the reload');
  });

  assert.deepEqual(problems, []);
} finally {
  await page.evaluate(
    async ([name, sourceId]) => {
      const refresh = await (
        await fetch('/api/v1/auth/refresh', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ refresh_token: localStorage.getItem('aixbi.refresh') }),
        })
      ).json();
      localStorage.setItem('aixbi.refresh', refresh.refresh_token);
      const auth = { Authorization: `Bearer ${refresh.access_token}` };
      if (sourceId) await fetch(`/api/v1/data-sources/${sourceId}`, { method: 'DELETE', headers: auth });
      const projects = (await (await fetch('/api/v1/projects', { headers: auth })).json()).data;
      const project = projects.find((p) => p.name === name);
      if (project) await fetch(`/api/v1/projects/${project.id}`, { method: 'DELETE', headers: auth });
    },
    [NAME, sourceId],
  );
  await browser.close();
}
console.log('Projects journey passed.');

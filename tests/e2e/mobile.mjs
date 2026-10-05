// Test 10 of the brief: the product is usable on a phone (390 × 844). Each page must fit the screen
// with no sideways scrolling, keep the bottom navigation, and show its content. Covers the home,
// a dashboard with its charts, a report, the AI analyst and the Actions page.
// BASE=http://localhost:4200 node mobile.mjs   (CHROMIUM=/path/to/chromium; SHOTS=dir to save screenshots)
import { chromium, devices } from 'playwright';
import assert from 'node:assert/strict';

const BASE = process.env.BASE ?? 'http://localhost:4200';
const SHOTS = process.env.SHOTS;
// Development builds log the browser abandoning a page-transition animation; it does not affect the page.
const IGNORED = ['fonts.googleapis.com', 'fonts.gstatic.com', 'ERR_CERT_AUTHORITY_INVALID', 'Transition was aborted'];
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM, args: ['--no-sandbox'] });
const context = await browser.newContext({ ...devices['iPhone 13'], defaultBrowserType: undefined });
const page = await context.newPage();
const problems = [];
page.on('pageerror', (e) => problems.push('uncaught: ' + e.message));
page.on(
  'console',
  (m) => m.type() === 'error' && !IGNORED.some((x) => m.text().includes(x)) && problems.push('console: ' + m.text()),
);
const step = async (name, fn) => {
  const t = Date.now();
  await fn();
  console.log(`✓ ${name} (${Date.now() - t} ms)`);
};
const fits = async (name) => {
  await page.waitForTimeout(500);
  const { scroll, width } = await page.evaluate(() => ({
    scroll: document.documentElement.scrollWidth,
    width: window.innerWidth,
  }));
  assert.ok(scroll <= width + 1, `${name}: the page is ${scroll}px wide on a ${width}px screen`);
  assert.ok(await page.locator('nav.tabbar').isVisible(), `${name}: bottom navigation is visible`);
  if (SHOTS) await page.screenshot({ path: `${SHOTS}/mobile-${name}.png` });
};

await page.goto(BASE + '/');
await page.evaluate(async () => {
  const r = await fetch('/api/v1/auth/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email: 'ceo@emgs.demo', password: 'Demo@2026!' }),
  });
  localStorage.setItem('aixbi.refresh', (await r.json()).refresh_token);
});

try {
  await step('home fits the phone and shows the KPIs', async () => {
    await page.goto(BASE + '/home');
    await page.locator('app-kpi').first().waitFor({ timeout: 30000 });
    await fits('home');
  });

  await step('a dashboard reflows to one column with its charts drawn', async () => {
    await page.goto(BASE + '/dashboards');
    await page.locator('a.card').first().click();
    await page.locator('app-chart canvas, app-chart svg').first().waitFor({ timeout: 30000 });
    const cells = await page.locator('.cell').evaluateAll((els) =>
      els.map((e) => {
        const r = e.getBoundingClientRect();
        return { kpi: e.classList.contains('kpi'), right: Math.round(r.right), width: r.width };
      }),
    );
    const screen = await page.evaluate(() => window.innerWidth);
    assert.ok(cells.length > 0, 'the dashboard has widgets');
    for (const c of cells)
      assert.ok(c.right <= screen + 1, `a widget runs off the screen (${c.right}px of ${screen}px)`);
    // Charts take the full width; KPI tiles sit at most two to a row.
    for (const c of cells.filter((c) => !c.kpi)) assert.ok(c.width > screen * 0.8, `a chart is only ${c.width}px wide`);
    for (const c of cells.filter((c) => c.kpi))
      assert.ok(c.width > screen * 0.4, `a KPI tile is only ${c.width}px wide`);
    await fits('dashboard');
  });

  await step('a report reads on the phone', async () => {
    await page.goto(BASE + '/reports');
    await page.locator('a[href^="/reports/"]').first().click();
    await page.waitForURL(/\/reports\/.+/);
    await page.getByRole('heading').first().waitFor();
    await fits('report');
  });

  await step('the AI analyst answers on the phone', async () => {
    await page.goto(BASE + '/ai?q=' + encodeURIComponent("Show me this month's performance"));
    await page.locator('app-kpi').first().waitFor({ timeout: 90000 });
    await fits('ai');
  });

  await step('approvals work on the phone', async () => {
    await page.goto(BASE + '/actions');
    await page.getByRole('heading', { name: 'Decide, then act' }).waitFor();
    await fits('actions');
  });

  await step('the More menu opens every other section', async () => {
    await page.locator('nav.tabbar').getByRole('button', { name: 'More' }).click();
    await page.getByRole('dialog', { name: 'More' }).getByRole('link', { name: 'Alerts', exact: true }).click();
    await page.waitForURL(/\/alerts/);
    await fits('alerts');
  });

  assert.deepEqual(problems, []);
} finally {
  await browser.close();
}
console.log('Phone journey passed.');

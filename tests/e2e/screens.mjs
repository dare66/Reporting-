// Captures every screen of the running app for review.
// OUT=dir BASE=http://localhost:4200 CHROMIUM=/path node screens.mjs
import { chromium } from 'playwright';

const BASE = process.env.BASE ?? 'http://localhost:4200';
const OUT = process.env.OUT ?? 'screens';
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM, args: ['--no-sandbox'] });
const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
let n = 0;
const shot = async (name, full = false) => {
  n += 1;
  await page.screenshot({ path: `${OUT}/${String(n).padStart(2, '0')}-${name}.png`, fullPage: full });
  console.log('✓', name);
};
const settle = (ms = 2500) => page.waitForTimeout(ms);
const visit = async (path, name, wait, ms) => {
  await page.goto(BASE + path);
  if (wait) await page.waitForSelector(wait, { timeout: 60000 }).catch(() => {});
  await settle(ms);
  await shot(name);
};

// Signed out
await page.goto(BASE + '/');
await settle();
await shot('landing');
await page.getByRole('button', { name: 'Explore intelligence' }).click();
await settle(1000);
await shot('sign-in');

// Signed in as the tenant administrator (sees every area)
await page.evaluate(async () => {
  const r = await fetch('/api/v1/auth/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email: 'admin@northstar.demo', password: 'Demo@2026!' }),
  });
  localStorage.setItem('aixbi.refresh', (await r.json()).refresh_token);
});

await visit('/home', 'command-centre', 'app-kpi', 4000);
await visit('/ai?q=' + encodeURIComponent('Why did SLA fall?'), 'ai-analyst', 'app-drivers', 6000);
await visit('/insights', 'insights', null, 3000);
await visit('/investigate?metric=decisions.sla_compliance', 'investigate', 'app-drivers .leaf', 3000);
await visit('/dashboards', 'dashboards', 'a.card');
await page.locator('a.card').first().click();
await page.waitForSelector('app-widget app-kpi', { timeout: 30000 }).catch(() => {});
await settle(5000);
await shot('dashboard-executive-overview');

// Dashboard filter editor
await page.getByRole('button', { name: 'Filter', exact: true }).click();
await settle(800);
await shot('dashboard-filter-pick-field');
await page.locator('.dims .dim').first().click();
await settle(1500);
await shot('dashboard-filter-members');
await page.keyboard.press('Escape');
await page.getByRole('button', { name: 'Cancel' }).first().click().catch(() => {});

// Layout editing and Widget Studio
await page.getByRole('button', { name: 'Edit layout' }).click();
await settle(800);
await shot('dashboard-edit-layout');
await page.getByRole('button', { name: 'Add widget' }).click();
const studio = page.getByRole('dialog', { name: 'Widget Studio' });
await studio.waitFor();
await settle(800);
await shot('widget-studio-empty');
await studio.getByLabel('Data model').selectOption('applications');
await studio.getByRole('radio', { name: 'Line' }).click();
await studio.getByLabel('Set X axis', { exact: true }).selectOption({ label: 'Submission Date (time)' });
await studio.getByLabel('Add a value', { exact: true }).selectOption({ label: 'Applications' });
await studio.getByLabel('Set Break by', { exact: true }).selectOption({ label: 'Channel' });
await studio.locator('app-chart canvas').waitFor({ timeout: 30000 });
await settle(1500);
await shot('widget-studio-line-break-by');
await studio.getByRole('button', { name: 'Options for Applications' }).click();
await studio.getByLabel('Quick function').selectOption('moving_average');
await settle(2500);
await shot('widget-studio-quick-function');
await studio.getByRole('radio', { name: 'Treemap' }).click();
await studio.getByLabel('Set Category', { exact: true }).selectOption({ label: 'Region' }).catch(() => {});
await settle(2500);
await shot('widget-studio-treemap');
await studio.getByRole('radio', { name: 'Gauge' }).click();
await settle(2500);
await shot('widget-studio-gauge');
await studio.getByRole('button', { name: 'Cancel' }).click();
await studio.waitFor({ state: 'detached' });
await page.getByRole('button', { name: 'Cancel' }).click();

await visit('/reports', 'reports', null, 3000);
const report = page.locator('a[href^="/reports/"]').first();
if (await report.count()) {
  await report.click();
  await page.waitForSelector('app-report-section', { timeout: 30000 }).catch(() => {});
  await settle(4000);
  await shot('report-detail');
}
await visit('/explore', 'explore', 'app-chart canvas', 3000);
await visit('/forecast', 'forecast', 'app-chart canvas', 5000);
await visit('/alerts', 'alerts', null, 3000);
await visit('/notifications', 'notifications', null, 2500);
await visit('/data', 'data-platform', null, 3000);
await visit('/semantic', 'semantic-model', null, 3000);
await visit('/governance', 'governance', null, 3000);

// Administration
await visit('/admin', 'admin-people', 'table', 2500);
await page.getByRole('button', { name: 'Manage Priya Nair' }).click();
await page.getByRole('dialog', { name: 'Priya Nair' }).getByText('Recent activity').waitFor();
await settle(1000);
await shot('admin-person-drawer');
await page.getByRole('dialog', { name: 'Priya Nair' }).locator('.scroll').evaluate((el) => el.scrollTo(0, el.scrollHeight));
await settle(600);
await shot('admin-person-drawer-security');
await page.getByRole('button', { name: 'Close' }).click();
await page.getByRole('button', { name: 'Add person' }).click();
await settle(800);
await shot('admin-add-person');
await page.getByRole('dialog', { name: 'Add a person' }).getByRole('button', { name: 'Cancel' }).click();
await page.getByRole('button', { name: 'Roles & permissions' }).click();
await settle(1500);
await page.getByRole('button', { name: /^Analyst/ }).click();
await settle(800);
await shot('admin-roles');
for (const [tab, name] of [
  ['Security policy', 'admin-security-policy'],
  ['Organisation', 'admin-organisation'],
  ['System health', 'admin-system-health'],
]) {
  await page.getByRole('button', { name: tab, exact: true }).click();
  await settle(2500);
  await shot(name);
}
await visit('/settings', 'settings-profile-security', null, 2500);
await page.getByRole('navigation', { name: 'Settings sections' }).getByRole('button', { name: 'Notifications' }).click();
await settle(1200);
await shot('settings-notifications-preferences');

// Light theme
await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'light'));
await visit('/home', 'command-centre-light', 'app-kpi', 4000);

// Mobile
await page.setViewportSize({ width: 390, height: 844 });
await visit('/home', 'mobile-command-centre', 'app-kpi', 4000);

await browser.close();

// Administration and personal settings, end to end against a running stack.
// Adds a person, signs in as them (held until they choose a password), edits a
// person, builds and deletes a custom role, and saves personal preferences.
// The person it adds is suspended at the end so the demo tenant stays tidy.
// BASE=http://localhost:4200 node admin.mjs   (CHROMIUM=/path/to/chromium; SHOTS=dir to save screenshots)
import { chromium } from 'playwright';
import assert from 'node:assert/strict';

const BASE = process.env.BASE ?? 'http://localhost:4200';
const SHOTS = process.env.SHOTS;
const IGNORED = ['fonts.googleapis.com', 'fonts.gstatic.com', 'ERR_CERT_AUTHORITY_INVALID'];
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM, args: ['--no-sandbox'] });
const problems = [];

async function session(email, password) {
  const page = await (await browser.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
  page.on('pageerror', (e) => problems.push('uncaught: ' + e.message));
  page.on('console', (m) => {
    // A held account's background calls are refused by design (403 policy holds).
    if (m.type() === 'error' && !IGNORED.some((x) => m.text().includes(x)) && !m.text().includes('403')) {
      problems.push('console: ' + m.text());
    }
  });
  page.on('response', (r) => r.url().includes('/api/') && r.status() >= 500 && problems.push(`HTTP ${r.status()} ${r.url()}`));
  await page.goto(BASE + '/');
  await page.evaluate(
    async ([email, password]) => {
      const r = await fetch('/api/v1/auth/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email, password }),
      });
      localStorage.setItem('aixbi.refresh', (await r.json()).refresh_token);
    },
    [email, password],
  );
  return page;
}
const step = async (name, fn) => {
  const t = Date.now();
  await fn();
  console.log(`✓ ${name} (${Date.now() - t} ms)`);
};
const shot = (page, name) => SHOTS && page.screenshot({ path: `${SHOTS}/${name}.png` });

const admin = await session('admin@northstar.demo', 'Demo@2026!');
const email = `e2e.${Date.now()}@northstar.demo`;
let initialPassword = '';

await step('people can be filtered and opened', async () => {
  await admin.goto(BASE + '/admin');
  await admin.getByRole('table').waitFor();
  await admin.getByLabel('Filter by role').selectOption({ label: 'Analyst' });
  await admin.getByRole('button', { name: 'Manage Priya Nair' }).waitFor();
  await shot(admin, 'admin-people');
  await admin.getByRole('button', { name: 'Manage Priya Nair' }).click();
  const drawer = admin.getByRole('dialog', { name: 'Priya Nair' });
  await drawer.getByText('Recent activity').waitFor();
  await drawer.getByLabel('Job title').fill('Lead Business Analyst');
  await drawer.getByRole('button', { name: 'Save changes' }).click();
  await drawer.getByText('Saved changes to Priya Nair.').waitFor();
  await shot(admin, 'admin-person-drawer');
  await drawer.getByLabel('Job title').fill('Senior Business Analyst');
  await drawer.getByRole('button', { name: 'Save changes' }).click();
  await drawer.getByText('Saved changes to Priya Nair.').waitFor();
  await drawer.getByRole('button', { name: 'Close' }).click();
});

await step('a new person is added with a one-time password', async () => {
  await admin.getByLabel('Filter by role').selectOption({ label: 'All roles' });
  await admin.getByRole('button', { name: 'Add person' }).click();
  const dialog = admin.getByRole('dialog', { name: 'Add a person' });
  await dialog.getByLabel('Full name').fill('Aiman Rahman');
  await dialog.getByLabel('Work email').fill(email);
  await dialog.getByLabel('Job title').fill('Operations Analyst');
  await dialog.getByLabel('Role').selectOption('viewer');
  await dialog.getByRole('radio', { name: 'Selected countries' }).click();
  await dialog.getByLabel('Add a country code').fill('MY');
  await dialog.getByRole('button', { name: 'Add', exact: true }).click();
  await shot(admin, 'admin-add-person');
  await dialog.getByRole('button', { name: 'Add person' }).click();
  initialPassword = (await dialog.locator('app-one-time-secret code').innerText()).trim();
  assert.match(initialPassword, /^[A-Za-z0-9]{5}(-[A-Za-z0-9]{5}){3}$/);
  await shot(admin, 'admin-person-added');
  await dialog.getByRole('button', { name: 'Done' }).click();
});

await step('the new person is held until they choose their own password', async () => {
  const person = await session(email, initialPassword);
  await person.goto(BASE + '/home');
  await person.waitForURL(/\/settings\?required=password/);
  await person.getByText('Choose a new password to continue').waitFor();
  await shot(person, 'settings-held-for-password');
  await person.getByLabel('Current password').fill(initialPassword);
  await person.getByLabel('New password', { exact: true }).fill('Aiman-chooses-a-long-passphrase');
  await person.getByLabel('Confirm new password').fill('Aiman-chooses-a-long-passphrase');
  await person.getByRole('button', { name: 'Change password' }).click();
  await person.waitForURL(/\/home/);
  await person.context().close();
});

await step('a platform role is duplicated, tailored and removed', async () => {
  await admin.getByRole('button', { name: 'Roles & permissions' }).click();
  await admin.getByRole('button', { name: /^Analyst/ }).click();
  await admin.getByText('Platform role:').waitFor();
  await admin.getByRole('button', { name: 'Duplicate as custom role' }).click();
  await admin.getByLabel('Role name').fill('Regional Analyst');
  await admin.getByRole('checkbox', { name: /query\.explain/ }).uncheck();
  await admin.getByRole('button', { name: 'Create role' }).click();
  await admin.getByText('Role created.').waitFor();
  await shot(admin, 'admin-custom-role');
  await admin.getByRole('button', { name: 'Delete role' }).click();
  await admin.getByRole('button', { name: 'Confirm delete' }).click();
  await admin.getByText('Role deleted.').waitFor();
});

await step('the security policy shows and saves its rules', async () => {
  await admin.getByRole('button', { name: 'Security policy' }).click();
  await admin.getByLabel('Add an email domain').fill('northstar.demo');
  await admin.getByRole('button', { name: 'Add', exact: true }).click();
  await admin.getByRole('button', { name: 'Save policy' }).click();
  await admin.getByText('Policy saved.').waitFor();
  await shot(admin, 'admin-security-policy');
  await admin.getByRole('button', { name: 'Remove northstar.demo' }).click();
  await admin.getByRole('button', { name: 'Save policy' }).click();
  await admin.getByText('Policy saved.').waitFor();
});

await step('personal settings save notifications and preferences', async () => {
  await admin.goto(BASE + '/settings');
  await admin.getByRole('heading', { name: 'Preferences' }).waitFor();
  await admin.getByRole('checkbox', { name: 'Mentions by email' }).uncheck();
  await admin.getByText('Saved.').first().waitFor();
  await admin.getByRole('button', { name: 'ISO (year first)' }).click();
  await admin.getByText(/Today reads as \d{4}-\d{2}-\d{2}/).waitFor();
  await shot(admin, 'settings');
  await admin.getByRole('button', { name: 'Day first' }).click();
  await admin.getByRole('checkbox', { name: 'Mentions by email' }).check();
});

// Leave the demo tenant tidy: the person added above is suspended.
await admin.evaluate(async (email) => {
  const refresh = await (
    await fetch('/api/v1/auth/refresh', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ refresh_token: localStorage.getItem('aixbi.refresh') }),
    })
  ).json();
  localStorage.setItem('aixbi.refresh', refresh.refresh_token);
  const auth = { Authorization: `Bearer ${refresh.access_token}`, 'Content-Type': 'application/json' };
  const people = await (await fetch(`/api/v1/admin/users?q=${encodeURIComponent(email)}`, { headers: auth })).json();
  for (const p of people.data) await fetch(`/api/v1/admin/users/${p.id}`, { method: 'PATCH', headers: auth, body: JSON.stringify({ status: 'suspended' }) });
}, email);

await browser.close();
assert.deepEqual(problems, []);
console.log('Administration journey passed.');

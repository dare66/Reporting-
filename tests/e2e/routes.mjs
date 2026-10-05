// Route sweep against a running stack: every page, as two personas, after a full page load
// (the path users take when they refresh or follow a link). Fails on uncaught errors, console
// errors and 5xx API responses.
// BASE=http://localhost:4200 node routes.mjs   (CHROMIUM=/path/to/chromium to use a preinstalled browser)
import { chromium } from 'playwright';

const BASE = process.env.BASE ?? 'http://localhost:4200';
const PERSONAS = ['admin@emgs.demo', 'manager.asia@emgs.demo'];
const ROUTES = [
  '/home', '/insights', '/dashboards', '/reports', '/explore', '/forecast', '/alerts', '/data', '/semantic',
  '/governance', '/admin', '/notifications', '/settings', '/ai', '/investigate?metric=decisions.sla_compliance',
  '/trust', '/metrics', '/projects', '/actions',
];
// Web fonts are optional; offline or proxied environments may refuse them.
const IGNORED = ['fonts.googleapis.com', 'fonts.gstatic.com', 'ERR_CERT_AUTHORITY_INVALID'];

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM, args: ['--no-sandbox'] });
let failures = 0;
for (const email of PERSONAS) {
  const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
  const problems = [];
  page.on('pageerror', (e) => problems.push('uncaught: ' + e.message));
  page.on('console', (m) => {
    if (m.type() === 'error' && !IGNORED.some((x) => m.text().includes(x))) problems.push('console: ' + m.text());
  });
  page.on('response', (r) => {
    if (r.url().includes('/api/') && r.status() >= 500) problems.push(`HTTP ${r.status()} ${r.url()}`);
  });

  // Sign in through the API and keep only the refresh token, exactly as a returning visitor would.
  await page.goto(BASE + '/');
  await page.evaluate(async (email) => {
    const res = await fetch('/api/v1/auth/login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email, password: 'Demo@2026!' }),
    });
    localStorage.setItem('aixbi.refresh', (await res.json()).refresh_token);
  }, email);

  for (const route of ROUTES) {
    problems.length = 0;
    await page.goto(BASE + route);
    await page.waitForTimeout(3000); // live streams keep the network busy, so wait a fixed settle time
    const heading = (await page.locator('h1').first().innerText().catch(() => '(no heading)')).split('\n')[0];
    const ok = problems.length === 0 && !page.url().endsWith(':4200/');
    if (!ok) failures++;
    console.log(`${ok ? '✓' : '✗'} ${email.split('@')[0]} ${route} — ${heading}`);
    for (const p of problems) console.log('    ' + p.split('\n')[0]);
  }
  await page.close();
}
await browser.close();
console.log(failures ? `${failures} route visit(s) failed` : 'All routes clean');
process.exit(failures ? 1 : 0);

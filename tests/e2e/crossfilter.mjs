// Cross-filtering end to end: clicking a bar filters every other widget on the dashboard,
// the clicked chart highlights rather than filtering itself, and a second click clears it.
// Works on a scratch dashboard (created and deleted here).
// BASE=http://localhost:4200 node crossfilter.mjs   (CHROMIUM=/path/to/chromium; SHOTS=dir to save screenshots)
import { chromium } from "playwright";
import assert from "node:assert/strict";

const BASE = process.env.BASE ?? "http://localhost:4200";
const SHOTS = process.env.SHOTS;
const IGNORED = [
  "fonts.googleapis.com",
  "fonts.gstatic.com",
  "ERR_CERT_AUTHORITY_INVALID",
];
const browser = await chromium.launch({
  executablePath: process.env.CHROMIUM,
  args: ["--no-sandbox"],
});
const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
const problems = [];
page.on("pageerror", (e) => problems.push("uncaught: " + e.message));
page.on(
  "console",
  (m) =>
    m.type() === "error" &&
    !IGNORED.some((x) => m.text().includes(x)) &&
    problems.push("console: " + m.text()),
);
page.on(
  "response",
  (r) =>
    r.url().includes("/api/") &&
    r.status() >= 500 &&
    problems.push(`HTTP ${r.status()} ${r.url()}`),
);
const step = async (name, fn) => {
  const t = Date.now();
  await fn();
  console.log(`✓ ${name} (${Date.now() - t} ms)`);
};

await page.goto(BASE + "/");
const dashboardId = await page.evaluate(async () => {
  const login = await (
    await fetch("/api/v1/auth/login", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        email: "analyst@emgs.demo",
        password: "Demo@2026!",
      }),
    })
  ).json();
  localStorage.setItem("aixbi.refresh", login.refresh_token);
  const query = { model: "applications", time: { range: "last_12_months" } };
  const created = await (
    await fetch("/api/v1/dashboards", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Authorization: `Bearer ${login.access_token}`,
      },
      body: JSON.stringify({
        title: "E2E Cross-filter",
        widgets: [
          {
            type: "chart",
            title: "Applications by region",
            query: {
              ...query,
              metrics: ["total_applications"],
              dimensions: ["region"],
              sort: [{ key: "total_applications", dir: "desc" }],
              limit: 3,
            },
            viz: { type: "bar", orientation: "horizontal" },
            position: { x: 0, y: 0, w: 8, h: 5 },
          },
          {
            type: "kpi",
            title: "Applications",
            query: { ...query, metrics: ["total_applications"] },
            position: { x: 8, y: 0, w: 4, h: 2 },
          },
        ],
      }),
    })
  ).json();
  return created.data.id;
});

try {
  let before = "";
  await step("the dashboard opens with every widget unfiltered", async () => {
    await page.goto(`${BASE}/dashboards/${dashboardId}`);
    await page.waitForSelector("app-widget app-chart canvas", {
      timeout: 30000,
    });
    await page.locator("app-kpi").first().waitFor();
    await page.waitForTimeout(800);
    before = await page.locator("app-kpi").first().innerText();
  });

  await step(
    "clicking a bar filters the other widgets and shows as a removable filter",
    async () => {
      const canvas = page
        .locator("app-widget", { hasText: "Applications by region" })
        .locator("app-chart canvas");
      const box = await canvas.boundingBox();
      // Sweep down the left part of the plot until a bar is hit (bars are horizontal and sorted largest first).
      for (let y = 0.15; y < 0.9; y += 0.05) {
        await page.mouse.click(
          box.x + box.width * 0.35,
          box.y + box.height * y,
        );
        if (
          await page
            .getByRole("button", { name: /^Edit filter: Region/ })
            .count()
        )
          break;
      }
      await page
        .getByRole("button", { name: /^Edit filter: Region/ })
        .waitFor();
      const until = Date.now() + 30000;
      while ((await page.locator("app-kpi").first().innerText()) === before) {
        assert.ok(Date.now() < until, "the KPI follows the clicked region");
        await page.waitForTimeout(250);
      }
      // The clicked chart keeps all its bars (it highlights the pick rather than filtering itself).
      await page
        .locator("app-widget", { hasText: "Applications by region" })
        .locator("app-chart canvas")
        .waitFor();
      if (SHOTS) await page.screenshot({ path: `${SHOTS}/crossfilter.png` });
    },
  );

  await step("removing the filter restores every widget", async () => {
    await page.getByRole("button", { name: /^Remove filter: Region/ }).click();
    const until = Date.now() + 30000;
    while ((await page.locator("app-kpi").first().innerText()) !== before) {
      assert.ok(Date.now() < until, "the KPI returns to its unfiltered value");
      await page.waitForTimeout(250);
    }
    assert.equal(
      await page.getByRole("button", { name: /^Edit filter/ }).count(),
      0,
    );
  });

  assert.deepEqual(problems, []);
} finally {
  await page.evaluate(async (id) => {
    const refresh = await (
      await fetch("/api/v1/auth/refresh", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          refresh_token: localStorage.getItem("aixbi.refresh"),
        }),
      })
    ).json();
    localStorage.setItem("aixbi.refresh", refresh.refresh_token);
    await fetch(`/api/v1/dashboards/${id}`, {
      method: "DELETE",
      headers: { Authorization: `Bearer ${refresh.access_token}` },
    });
  }, dashboardId);
  await browser.close();
}
console.log("Cross-filter journey passed.");

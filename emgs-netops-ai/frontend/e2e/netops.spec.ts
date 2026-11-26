import { expect, test, type Page } from "@playwright/test";

const ADMIN = { user: "admin", pass: "Str0ng!Passw0rd" };
const SHOTS = process.env.E2E_SHOTS;

async function shot(page: Page, name: string) {
  if (SHOTS) await page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: false });
}

async function signIn(page: Page) {
  await page.goto("/");
  const setup = page.getByRole("heading", { name: "First-time setup" });
  if (await setup.isVisible().catch(() => false)) {
    await page.getByLabel("Full name").fill("E2E Admin");
    await page.getByLabel("Username").fill(ADMIN.user);
    await page.getByLabel("Password", { exact: true }).fill(ADMIN.pass);
    await page.getByLabel("Confirm password").fill(ADMIN.pass);
    await page.getByRole("button", { name: "Create administrator" }).click();
  } else {
    await page.getByLabel("Username").fill(ADMIN.user);
    await page.getByLabel("Password").fill(ADMIN.pass);
    await page.getByRole("button", { name: "Sign in" }).click();
  }
  await expect(page.getByTestId("mode-banner")).toContainText("Simulation mode");
}

test.describe("desktop", () => {
  test.skip(({ isMobile }) => isMobile, "desktop only");

  test("setup, dashboard and quick diagnostic", async ({ page }) => {
    await page.goto("/");
    await shot(page, "01-setup");
    await signIn(page);
    await expect(page.getByRole("heading", { name: "Network overview" })).toBeVisible();
    await page.getByLabel("IP address or hostname").fill("10.1.57.15");
    await page.getByTestId("run-diagnostic").click();
    await expect(page.getByText("does not prove the server is offline")).toBeVisible();
    await expect(page.getByText("Possible causes (not confirmed)")).toBeVisible();
    await shot(page, "02-dashboard");
  });

  test("policy rejects unapproved targets", async ({ page }) => {
    await signIn(page);
    await page.getByRole("link", { name: "Diagnostics" }).first().click();
    await page.getByLabel("IP address or hostname").fill("8.8.8.8");
    await page.getByTestId("run-diagnostic").click();
    await expect(page.getByRole("alert")).toContainText("not inside an approved network");
  });

  test("assistant asks for missing info, then runs", async ({ page }) => {
    await signIn(page);
    await page.getByRole("link", { name: "Assistant" }).first().click();
    await page.getByLabel("Message").fill("Check port 3306");
    await page.getByRole("button", { name: "Send" }).click();
    await expect(page.getByText("Which server or IP address should I check")).toBeVisible();
    await page.getByLabel("Message").fill("10.1.57.15");
    await page.getByRole("button", { name: "Send" }).click();
    await expect(page.getByText("1 of 1 port(s) open on 10.1.57.15.").first()).toBeVisible();
    await page.getByLabel("Message").fill("Explain why STARS is not accessible");
    await page.getByRole("button", { name: "Send" }).click();
    await expect(page.getByText("I tested SAMPLE STARS App 01")).toBeVisible();
    await shot(page, "03-assistant");
  });

  test("discovery: plan, confirm, compare, report", async ({ page }) => {
    await signIn(page);
    await page.getByRole("link", { name: "Discovery" }).first().click();
    await page.getByLabel("IP range").fill("10.1.57.xx");
    await page.getByLabel("Final-octet range for the wildcard").fill("1-100");
    await page.getByRole("button", { name: "Review plan" }).click();
    await expect(page.getByTestId("scan-plan")).toContainText("100");
    await shot(page, "04-discovery-plan");
    await page.getByRole("button", { name: "Start discovery" }).click();
    await expect(page.getByRole("dialog")).toContainText("This will check 100 addresses");
    await page.getByRole("dialog").getByRole("button", { name: "Start discovery" }).click();
    await expect(page.getByRole("heading", { name: /Inventory comparison/ })).toBeVisible({ timeout: 45_000 });
    await expect(page.getByText("Possible Unauthorised Asset").first()).toBeVisible();
    await expect(page.getByText("Registered but Not Responding").first()).toBeVisible();
    await shot(page, "05-discovery-result");
    const dl = page.waitForEvent("download");
    await page.getByRole("button", { name: "Excel" }).click();
    expect((await dl).suggestedFilename()).toMatch(/discovery\.xlsx$/);
  });

  test("inventory, reports, settings", async ({ page }) => {
    await signIn(page);
    await page.getByRole("link", { name: "Inventory" }).first().click();
    await expect(page.getByText("SAMPLE STARS DB 01")).toBeVisible();
    await expect(page.getByText("demonstration data, not real EMGS assets")).toBeVisible();
    await shot(page, "06-inventory");
    await page.getByRole("link", { name: "Reports" }).first().click();
    const dl = page.waitForEvent("download");
    await page.getByRole("button", { name: "Generate PDF" }).click();
    expect((await dl).suggestedFilename()).toMatch(/consolidated\.pdf$/);
    await page.getByRole("link", { name: "Settings" }).first().click();
    await expect(page.getByRole("heading", { name: "Approved targets" })).toBeVisible();
    await shot(page, "07-settings");
    await page.getByRole("link", { name: "Audit log" }).first().click();
    await expect(page.getByText("discovery.finish").first()).toBeVisible();
  });
});

test.describe("mobile", () => {
  test.skip(({ isMobile }) => !isMobile, "mobile only");

  test("mobile layout, quick ping and assistant", async ({ page }) => {
    await signIn(page);
    const nav = page.getByRole("navigation", { name: "Mobile" });
    await expect(nav).toBeVisible();
    await shot(page, "m1-dashboard");
    await nav.getByRole("link", { name: "Diagnostics" }).click();
    await page.getByLabel("IP address or hostname").fill("10.1.57.10");
    await page.getByTestId("run-diagnostic").click();
    await expect(page.getByText("answered all 4 ICMP echo requests")).toBeVisible();
    await shot(page, "m2-diagnostic");
    await nav.getByRole("link", { name: "Assistant" }).click();
    await page.getByLabel("Message").fill("Run tracert to 10.1.57.10");
    await page.getByRole("button", { name: "Send" }).click();
    await expect(page.getByText("The route to 10.1.57.10 completes").first()).toBeVisible();
    await shot(page, "m3-assistant");
    await page.getByRole("button", { name: "More" }).click();
    await page.getByRole("dialog", { name: "Menu" }).getByRole("link", { name: "History" }).click();
    await expect(page.getByRole("heading", { name: "Diagnostic history" })).toBeVisible();
    const hist = await page.request.get("/api/history?limit=5");
    expect((await hist.json())[0].source_interface).toBe("mobile");
  });

  test("session expiry returns to sign-in", async ({ page, context }) => {
    await signIn(page);
    await context.clearCookies();
    await page.getByRole("navigation", { name: "Mobile" }).getByRole("link", { name: "Discovery" }).click();
    await expect(page.getByRole("button", { name: "Sign in" })).toBeVisible();
  });
});

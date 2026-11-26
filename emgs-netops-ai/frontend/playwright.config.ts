import { defineConfig, devices } from "@playwright/test";

// Starts a throwaway instance (fresh database, Simulation mode) on port 8760.
const DATA = process.env.E2E_DATA_DIR ?? "/tmp/netops-e2e";

export default defineConfig({
  testDir: "./e2e",
  timeout: 60_000,
  workers: 1,
  fullyParallel: false,
  reporter: [["list"]],
  use: { baseURL: "http://127.0.0.1:8760", trace: "off", screenshot: "only-on-failure" },
  webServer: {
    command: `rm -rf ${DATA} && NETOPS_DATA_DIR=${DATA} ../.venv/bin/python ../scripts/startup/netops.py start --port 8760`,
    url: "http://127.0.0.1:8760/api/health",
    reuseExistingServer: false,
    timeout: 120_000,
  },
  projects: [
    { name: "desktop", use: { ...devices["Desktop Chrome"], viewport: { width: 1440, height: 900 } } },
    { name: "mobile", use: { ...devices["Pixel 7"] }, dependencies: ["desktop"] },
  ],
});

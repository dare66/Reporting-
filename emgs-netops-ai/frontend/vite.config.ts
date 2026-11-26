/// <reference types="vitest" />
import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import tailwindcss from "@tailwindcss/vite";

// Dev server proxies the API to the local backend. It binds to localhost only;
// mobile access goes through the backend (which serves the built app).
export default defineConfig({
  plugins: [react(), tailwindcss()],
  server: {
    host: "127.0.0.1",
    port: 5173,
    proxy: {
      "/api": "http://127.0.0.1:8750",
      "/ws": { target: "ws://127.0.0.1:8750", ws: true },
    },
  },
  build: { outDir: "dist", sourcemap: false },
  test: { globals: true, environment: "jsdom", setupFiles: ["./src/test-setup.ts"], include: ["src/**/*.test.{ts,tsx}"] },
});

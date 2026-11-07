import { defineConfig, devices } from '@playwright/test';

/**
 * Tests d'interface du lot P1 des écoles publiques (X-004). Même principe que ../playwright.config.ts : front construit
 * (vite build --outDir e2e/.dist) servi par `vite preview`, API simulée par interception réseau dans le test lui-même.
 *
 *   cd web && npx vite build --outDir e2e/.dist --emptyOutDir
 *   cd web/e2e && npx playwright test -c public/playwright.config.ts
 */
const PORT = 4318;

export default defineConfig({
  testDir: '.',
  testMatch: '*.spec.ts',
  outputDir: '../.dist/.results-public',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 60_000,
  expect: { timeout: 8_000 },
  reporter: [['list']],
  use: {
    baseURL: `http://127.0.0.1:${PORT}`,
    serviceWorkers: 'block',
    testIdAttribute: 'data-test',
    locale: 'fr-FR',
    timezoneId: 'Africa/Douala',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [{ name: 'mobile-390', use: { ...devices['Desktop Chrome'], viewport: { width: 390, height: 844 }, isMobile: false, hasTouch: true } }],
  webServer: {
    command: `npx vite preview --outDir e2e/.dist --port ${PORT} --strictPort --host 127.0.0.1`,
    cwd: '../..',
    url: `http://127.0.0.1:${PORT}`,
    reuseExistingServer: false,
    timeout: 60_000,
  },
});

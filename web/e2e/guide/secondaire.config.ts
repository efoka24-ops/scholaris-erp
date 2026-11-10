import { defineConfig, devices } from '@playwright/test';

/**
 * Captures d'écran du guide d'utilisation de l'application SECONDAIRE (G-SEC). Hors de la suite e2e normale : la configuration
 * principale (../playwright.config.ts) ne lit que ../specs. Pile réelle locale (../specs/stack.ts), données fictives.
 *
 *   cd web && npx vite build --outDir e2e/.dist
 *   cd e2e && node_modules/.bin/playwright test -c guide/secondaire.config.ts
 *
 * Les captures vont dans specs/005-application-secondaire/guide/images/.
 */
const PORT = 4337;

export default defineConfig({
  testDir: '.',
  testMatch: /secondaire\.guide\.ts$/,
  outputDir: '../test-results/guide-secondaire',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 1_800_000,
  expect: { timeout: 8_000 },
  reporter: [['list']],
  use: {
    baseURL: `http://127.0.0.1:${PORT}`,
    serviceWorkers: 'block',
    testIdAttribute: 'data-test',
    locale: 'fr-FR',
    timezoneId: 'Africa/Douala',
    actionTimeout: 45_000,
    trace: 'off',
    screenshot: 'off',
  },
  projects: [{ name: 'guide-1280', use: { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 800 } } }],
  webServer: {
    command: `npx vite preview --outDir e2e/.dist --port ${PORT} --strictPort --host 127.0.0.1`,
    cwd: '../..',
    url: `http://127.0.0.1:${PORT}`,
    reuseExistingServer: true,
    timeout: 60_000,
  },
});

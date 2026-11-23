import { defineConfig, devices } from '@playwright/test';

/**
 * Captures d'écran des guides d'utilisation du primaire (privé et public), sur la pile RÉELLE locale (../specs/stack.ts) et
 * le front construit (e2e/.dist). Ce projet ne fait PAS partie de la suite e2e normale (playwright.config.ts lit ./specs).
 *
 *   cd web && npx vite build --outDir e2e/.dist
 *   cd e2e && node_modules/.bin/playwright test -c guide/playwright.primaire.config.ts            # les deux guides
 *   cd e2e && node_modules/.bin/playwright test -c guide/playwright.primaire.config.ts primaire-prive
 *
 * Images écrites dans specs/003-application-primaire/guide/images (privé) et
 * specs/004-applications-primaire-maternelle-publiques/primaire/guide/images (public). Données fictives uniquement.
 */
const PORT = 4337;

export default defineConfig({
  testDir: '.',
  testMatch: /primaire-(prive|public)\.guide\.ts$/,
  outputDir: '../test-results/guide-primaire',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 1_800_000,
  expect: { timeout: 30_000 },
  reporter: [['list']],
  use: {
    ...devices['Desktop Chrome'],
    viewport: { width: 1280, height: 800 },
    baseURL: `http://127.0.0.1:${PORT}`,
    serviceWorkers: 'block',
    testIdAttribute: 'data-test',
    locale: 'fr-FR',
    timezoneId: 'Africa/Douala',
    actionTimeout: 45_000,
  },
  projects: [{ name: 'guide-1280' }],
  webServer: {
    command: `npx vite preview --outDir e2e/.dist --port ${PORT} --strictPort --host 127.0.0.1`,
    cwd: '../..',
    url: `http://127.0.0.1:${PORT}`,
    reuseExistingServer: true,
    timeout: 60_000,
  },
});

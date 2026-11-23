import { defineConfig, devices } from '@playwright/test';

/**
 * Tests d'interface de bout en bout : front CONSTRUIT (vite build vers e2e/.dist) servi par `vite preview`, API simulée par
 * interception réseau (mock/backend.ts, réponses calquées sur l'API réelle). Le serveur est démarré puis arrêté par Playwright.
 *
 *   cd web/e2e && npm install && npx playwright install chromium
 *   npm test                      # construit si besoin, lance vite preview sur le port 4317, exécute, arrête le serveur
 */
const PORT = 4317;

export default defineConfig({
  testDir: './specs',
  outputDir: './.results',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 45_000,
  expect: { timeout: 8_000 },
  reporter: [['list'], ['json', { outputFile: './.results/report.json' }]],
  use: {
    baseURL: `http://127.0.0.1:${PORT}`,
    // Le service worker est bloqué : sinon les requêtes qu'il émet échappent à l'interception réseau.
    serviceWorkers: 'block',
    testIdAttribute: 'data-test',
    locale: 'fr-FR',
    timezoneId: 'Africa/Douala',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [
    { name: 'mobile-390', use: { ...devices['Desktop Chrome'], viewport: { width: 390, height: 844 }, isMobile: false, hasTouch: true } },
    { name: 'desktop-1280', use: { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 800 } } },
  ],
  webServer: {
    command: `npx vite preview --outDir e2e/.dist --port ${PORT} --strictPort --host 127.0.0.1`,
    cwd: '..',
    url: `http://127.0.0.1:${PORT}`,
    reuseExistingServer: false,
    timeout: 60_000,
  },
});

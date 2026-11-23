import { defineConfig, devices } from '@playwright/test';

/**
 * Recette en LECTURE SEULE sur un serveur réel (production par défaut). Projet séparé : la configuration e2e habituelle
 * (playwright.config.ts, API simulée) n'est pas touchée. Usage et garanties : voir l'en-tête de recette-prod/recette.spec.ts.
 *
 *   cd web/e2e
 *   npx playwright test -c playwright.recette-prod.config.ts --list      # vérifie que tout compile, sans réseau
 *   RECETTE_EMAIL=... RECETTE_PASSWORD=... npx playwright test -c playwright.recette-prod.config.ts
 */
const BASE_URL = (process.env.RECETTE_BASE_URL ?? 'https://scholariserp.trugroup.cm').replace(/\/+$/, '');

export default defineConfig({
  testDir: './recette-prod',
  outputDir: './test-results/recette-prod',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  // Un test = une application parcourue en entier (jusqu'à une trentaine d'écrans sur un réseau lent).
  timeout: 15 * 60_000,
  expect: { timeout: 10_000 },
  reporter: [['list']],
  use: {
    baseURL: BASE_URL,
    // Sans service worker, toutes les requêtes passent par le garde réseau qui bloque les écritures.
    serviceWorkers: 'block',
    locale: 'fr-FR',
    timezoneId: 'Africa/Douala',
    // Ni trace ni vidéo ni capture automatique : la trace contiendrait le mot de passe saisi à la connexion.
    trace: 'off',
    video: 'off',
    screenshot: 'off',
    navigationTimeout: 45_000,
    actionTimeout: 15_000,
  },
  projects: [
    { name: 'recette-mobile-390', use: { ...devices['Desktop Chrome'], viewport: { width: 390, height: 844 }, isMobile: false, hasTouch: true } },
    { name: 'recette-desktop-1280', use: { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 800 } } },
  ],
});

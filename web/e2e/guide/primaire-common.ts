import type { Browser, Page, TestInfo } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import type { Stack } from '../specs/stack';

/** Outils communs aux scripts de capture des guides du primaire (privé et public). */
const here = dirname(fileURLToPath(import.meta.url));
export const ROOT = join(here, '..', '..', '..');
export const PRIVATE_IMAGES = join(ROOT, 'specs', '003-application-primaire', 'guide', 'images');
// GUIDE_PUBLIC_IMAGES : dossier de sortie d'un rejeu de contrôle, sans toucher aux images du guide.
export const PUBLIC_IMAGES = process.env.GUIDE_PUBLIC_IMAGES ?? join(ROOT, 'specs', '004-applications-primaire-maternelle-publiques', 'primaire', 'guide', 'images');

/** Liste des captures faites (nom, titre de page) : affichée en fin de script pour le rapport. */
export const shots: string[] = [];

/** Attend que l'écran soit stable : réseau calme, aucun indicateur de chargement visible. */
export async function settle(page: Page, ms = 600): Promise<void> {
  await page.waitForLoadState('networkidle', { timeout: 30_000 }).catch(() => undefined);
  await page
    .locator('[aria-busy="true"], .spinner, .loading')
    .first()
    .waitFor({ state: 'hidden', timeout: 20_000 })
    .catch(() => undefined);
  await page.waitForTimeout(ms);
}

/**
 * Capture de l'écran entier (hauteur limitée pour rester lisible à l'impression) dans `dir/name.png`.
 * `maxHeight` : hauteur maximale en pixels CSS de la capture (au-delà, l'image est coupée en bas).
 */
export async function shot(page: Page, dir: string, name: string, opts: { maxHeight?: number; full?: boolean } = {}): Promise<void> {
  mkdirSync(dir, { recursive: true });
  await settle(page);
  const vp = page.viewportSize() ?? { width: 1280, height: 800 };
  const height = await page.evaluate(() => document.documentElement.scrollHeight);
  const max = opts.maxHeight ?? (vp.width < 600 ? 1500 : 1700);
  const full = opts.full ?? true;
  await page.screenshot({
    path: join(dir, name + '.png'),
    fullPage: full,
    clip: full ? { x: 0, y: 0, width: vp.width, height: Math.min(height, max) } : undefined,
    animations: 'disabled',
  });
  shots.push(`${name} — ${await page.title()}`);
}

/** Contexte de navigateur au format téléphone (390 px), relié à la pile. */
export async function phonePage(browser: Browser, stack: Stack, info: TestInfo): Promise<Page> {
  const context = await browser.newContext({
    baseURL: String(info.project.use.baseURL),
    viewport: { width: 390, height: 844 },
    hasTouch: true,
    locale: 'fr-FR',
    timezoneId: 'Africa/Douala',
    serviceWorkers: 'block',
  });
  await stack.attach(context);
  return context.newPage();
}

/** Tente une action d'écran ; en cas d'échec, l'écart est consigné (pour le rapport) au lieu d'arrêter les captures. */
export const issues: string[] = [];
export async function attempt(label: string, fn: () => Promise<unknown>): Promise<boolean> {
  try {
    await fn();
    return true;
  } catch (e) {
    const msg = (e instanceof Error ? e.message : String(e)).split('\n')[0]!.slice(0, 300);
    issues.push(`${label} : ${msg}`);
    console.log(`ÉCART ${label} : ${msg}`);
    return false;
  }
}

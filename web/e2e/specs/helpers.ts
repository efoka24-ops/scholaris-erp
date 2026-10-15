import { expect, type Page, type TestInfo } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { ACCOUNTS, installApi, type MockOptions, type MockState } from '../mock/backend';

const SCREENS = join(dirname(fileURLToPath(import.meta.url)), '..', 'screenshots');

export async function signIn(page: Page, role: keyof typeof ACCOUNTS, opts: MockOptions = {}): Promise<MockState> {
  const state = await installApi(page, opts);
  await page.goto('/connexion');
  await page.getByLabel('Numéro de téléphone ou e-mail').fill(ACCOUNTS[role].identifier);
  await page.getByLabel('Mot de passe', { exact: true }).fill(ACCOUNTS[role].password);
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page).not.toHaveURL(/\/connexion/);
  return state;
}

/** Capture d'écran pleine page, nommée par écran et largeur (390 ou 1280 px). */
export async function shot(page: Page, info: TestInfo, name: string): Promise<void> {
  await page.screenshot({ path: join(SCREENS, `${name}-${info.project.name}.png`), fullPage: true });
}

/**
 * Contrôle d'accessibilité automatisé (axe-core, règles WCAG 2.0/2.1 A et AA). Les violations « critical » et « serious »
 * font échouer le test ; toutes sont jointes au rapport.
 */
export async function checkA11y(page: Page, info: TestInfo, name: string): Promise<void> {
  const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
  const summary = results.violations.map((v) => ({ id: v.id, impact: v.impact, help: v.help, nodes: v.nodes.length, targets: v.nodes.slice(0, 3).map((n) => n.target.join(' ')) }));
  await info.attach(`axe-${name}`, { body: JSON.stringify(summary, null, 2), contentType: 'application/json' });
  const blocking = summary.filter((v) => v.impact === 'critical' || v.impact === 'serious');
  expect(blocking, `Violations d'accessibilité bloquantes sur ${name} : ${JSON.stringify(blocking)}`).toEqual([]);
}

export function expectNoUnhandled(state: MockState): void {
  expect(state.unhandled, 'Appels de l\'interface non prévus par le faux backend').toEqual([]);
}

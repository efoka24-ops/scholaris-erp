import { test } from '@playwright/test';
import { actorPage, closeActors, expect, isoDate, selectContaining, startStack, uiLogin, type Stack } from './stack';

/**
 * Parcours 6 — Maternelle publique (C-110), sur la pile réelle. Semis par l'API (fixtures/stack/seed.php « publicNursery ») :
 * école maternelle publique, année et trimestres, classe « Petite section A » autorisée, 31 enfants fictifs inscrits avec un
 * parent chacun. À l'écran, la directrice encaisse la contribution exigible (7 500 FCFA, valeur de départ) et le reçu numéroté
 * est émis ; le registre du jour signale l'effectif au-delà du plafond de 30.
 */
test.describe.configure({ timeout: 600_000 });
test.use({ actionTimeout: 45_000 });

interface PublicNursery {
  establishment: string;
  logins: Record<string, [string, string]>;
  children: string[];
}

let stack: Stack;
test.beforeAll(async () => {
  test.setTimeout(120_000);
  stack = await startStack();
});
test.afterEach(async () => {
  await closeActors();
});
test.afterAll(async () => {
  await stack?.stop();
});

test('maternelle publique : contribution de 7 500 FCFA avec reçu, registre du jour et alerte d\'effectif au-delà de 30', async ({ browser }, info) => {
  const ctx = await stack.seed<PublicNursery>('publicNursery');
  const [login, password] = ctx.logins.admin!;
  const page = await actorPage(browser, stack, info);
  await uiLogin(page, login, password);

  // 1. Contribution exigible : règle paramétrée (7 500 FCFA, une tranche), encaissement contre reçu numéroté.
  await page.goto('/maternelle/contribution');
  await expect(page.getByRole('heading', { name: 'Contribution exigible', level: 1 })).toBeVisible();
  await expect(page.getByText(/7\s?500\sFCFA par enfant, en 1 tranche/)).toBeVisible();
  await selectContaining(page.getByLabel('Enfant'), 'Enfant1');
  await page.getByLabel('Nom de la personne qui verse').fill('Parent de Enfant1 Fictif-PSA');
  await page.getByRole('button', { name: 'Encaisser et émettre le reçu' }).click();
  const receipt = page.getByRole('status').filter({ hasText: /Reçu n° .+ émis pour 7\s?500\sFCFA/ });
  await expect(receipt).toBeVisible();
  const number = /Reçu n° (\S+) émis/.exec((await receipt.textContent()) ?? '')?.[1];
  expect(number, 'numéro de reçu').toBeTruthy();
  await expect(page.getByRole('region', { name: 'Souches des reçus' }).or(page.locator('section', { has: page.getByRole('heading', { name: 'Souches des reçus' }) }))).toContainText(number!);
  await expect(page.getByText(/1 enfant\(s\) sur 31 ont versé/)).toBeVisible();

  // 2. Registre du jour : effectif de la Petite section A (31) au-delà du plafond (30).
  await page.goto('/maternelle/registre');
  await expect(page.getByRole('heading', { name: 'Registre du jour', level: 1 })).toBeVisible();
  const alert = page.getByRole('alert').filter({ hasText: 'Effectif au-delà du plafond' });
  await expect(alert).toBeVisible();
  await expect(alert).toContainText('Petite section A : 31 enfants pour 30 au plus.');
  await page.getByLabel('Jour').fill(isoDate());
  await page.getByRole('button', { name: 'Afficher' }).click();
  await expect(page.getByText(/31 enfant\(s\), 0 arrivé\(s\)/)).toBeVisible();

  expect(stack.serverErrors()).toEqual([]);
  stack.expectOnlyGetPost();
});

import { test } from '@playwright/test';
import { expect, startStack, uiLogin, type Stack } from './stack';

/**
 * Fumée de la pile réelle (C-110) : serveur PHP + SQLite migrée, client mis en service par l'outil de la plateforme,
 * administrateur activé par code SMS (lu dans l'enregistreur), connexion par l'écran réel et accueil de l'administration.
 */
test.describe.configure({ timeout: 300_000 });
test.use({ actionTimeout: 45_000 });

let stack: Stack;
test.beforeAll(async () => {
  test.setTimeout(120_000);
  stack = await startStack();
});
test.afterAll(async () => {
  await stack?.stop();
});

test('pile réelle : connexion de l\'administrateur d\'un client et écran d\'administration', async ({ page }) => {
  const c = await stack.client('primary', 'primary', 'École Fumée de Recette');
  await stack.attach(page);
  await uiLogin(page, c.admin.login, c.admin.password);
  await page.goto('/administration');
  await expect(page.getByRole('heading', { name: 'Administration', level: 1 })).toBeVisible();
  await expect(page.getByRole('navigation', { name: "Sections de l'administration" }).getByRole('link', { name: 'Structures' })).toBeVisible();
  expect(stack.calls.find((c) => c.path === '/auth/login')?.status).toBe(200);
  expect(stack.serverErrors()).toEqual([]);
  stack.expectOnlyGetPost();
});

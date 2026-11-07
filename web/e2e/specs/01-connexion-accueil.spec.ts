import { expect, test } from '@playwright/test';
import { installApi } from '../mock/backend';
import { checkA11y, expectNoUnhandled, shot, signIn } from './helpers';

test.describe('Connexion et accueil', () => {
  test('mauvais mot de passe : message clair, pas de navigation', async ({ page }, info) => {
    const state = await installApi(page);
    await page.goto('/connexion');
    await shot(page, info, '01-connexion');
    await checkA11y(page, info, 'connexion');
    await page.getByLabel('Numéro de téléphone ou e-mail').fill('+237699000001');
    await page.getByLabel('Mot de passe', { exact: true }).fill('mauvais-mot-de-passe');
    await page.getByRole('button', { name: 'Se connecter' }).click();
    await expect(page.getByText(/Il vous reste 4 essais/)).toBeVisible();
    await expect(page).toHaveURL(/\/connexion/);
    expectNoUnhandled(state);
  });

  test('champs vides : erreurs de saisie sans appel au serveur', async ({ page }) => {
    const state = await installApi(page);
    await page.goto('/connexion');
    await page.getByRole('button', { name: 'Se connecter' }).click();
    await expect(page.getByText('Saisissez votre numéro de téléphone ou votre adresse e-mail.')).toBeVisible();
    expect(state.calls.filter((c) => c.startsWith('POST /auth/login'))).toEqual([]);
  });

  test('administrateur : accueil de son établissement', async ({ page }, info) => {
    const state = await signIn(page, 'admin');
    await expect(page.getByText('Collège Bilingue Démo').first()).toBeVisible();
    await shot(page, info, '02-accueil-admin');
    await checkA11y(page, info, 'accueil-admin');
    expectNoUnhandled(state);
  });

  test('une page protégée sans session renvoie vers la connexion', async ({ page }) => {
    await installApi(page);
    await page.goto('/notes');
    await expect(page).toHaveURL(/\/connexion\?redirect=/);
  });
});

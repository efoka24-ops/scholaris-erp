import { expect, test, type Page } from '@playwright/test';
import { checkA11y, expectNoUnhandled, shot, signIn } from './helpers';

async function openGrid(page: Page): Promise<void> {
  await page.goto('/notes');
  await page.locator('#g-structure').selectOption({ label: '3e A' });
  await page.locator('#g-period').selectOption({ index: 1 });
  await expect(page.getByRole('textbox', { name: 'Yann Ewane · Devoir 1' })).toBeVisible();
}

test('saisie de notes : note hors bornes refusée sur place, note valide envoyée', async ({ page }, info) => {
  const state = await signIn(page, 'teacher');
  await openGrid(page);
  await shot(page, info, '06-notes-grille');
  await checkA11y(page, info, 'notes-grille');

  const cell = page.getByRole('textbox', { name: 'Yann Ewane · Devoir 1' });
  await cell.fill('22');
  await cell.press('Tab');
  await expect(page.getByText(/22 dépasse le maximum de 20\. Corrigez avant de valider\./)).toBeVisible();
  expect(state.gradeSyncs, 'Une note hors bornes ne part pas au serveur.').toHaveLength(0);
  await shot(page, info, '07-notes-erreur');

  await cell.fill('15');
  await cell.press('Tab');
  await expect.poll(() => state.gradeSyncs.length, { timeout: 10_000 }).toBe(1);
  expect(state.gradeSyncs[0]).toMatchObject({ value: 15 });
  await expect(page.getByText(/Enregistré automatiquement/)).toBeVisible();
  expectNoUnhandled(state);
});

test('conflit 409 : les deux versions sont présentées, rien n\'est écrasé sans choix', async ({ page }, info) => {
  const state = await signIn(page, 'teacher', { gradeConflictOnce: true });
  await openGrid(page);
  const cell = page.getByRole('textbox', { name: 'Nadia Foka · Devoir 1' });
  await cell.fill('17');
  await cell.press('Tab');

  const alert = page.getByRole('alert').filter({ hasText: 'Notes modifiées entre-temps' });
  await expect(alert).toBeVisible({ timeout: 10_000 });
  await expect(alert.getByText('17')).toBeVisible();
  await expect(alert.getByText('12')).toBeVisible();
  await shot(page, info, '08-notes-conflit');
  await checkA11y(page, info, 'notes-conflit');

  await alert.getByRole('button', { name: 'Renvoyer ma version' }).click();
  await expect.poll(() => state.gradeSyncs.length, { timeout: 10_000 }).toBeGreaterThanOrEqual(2);
  await expect(page.getByText('Notes modifiées entre-temps')).toHaveCount(0, { timeout: 10_000 });
  expectNoUnhandled(state);
});

import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { checkA11y, expectNoUnhandled, shot, signIn } from './helpers';

test('administration : création d\'un établissement, erreur de saisie puis succès', async ({ page }, info) => {
  const state = await signIn(page, 'admin');
  await page.goto('/administration/etablissements');
  await expect(page.getByRole('heading', { name: 'Établissements' })).toBeVisible();
  await shot(page, info, '12-admin-etablissements');
  await checkA11y(page, info, 'admin-etablissements');

  await page.getByRole('button', { name: 'Nouvel établissement' }).click();
  await page.getByRole('button', { name: 'Créer l’établissement' }).click();
  await expect(page.getByText('Saisissez le nom de l’établissement.')).toBeVisible();
  expect(state.calls.filter((c) => c === 'POST /establishments')).toHaveLength(0);

  await page.getByLabel('Nom', { exact: true }).fill('École Primaire Recette');
  await page.getByLabel('Type').selectOption('primary');
  await shot(page, info, '13-admin-nouvel-etablissement');
  await page.getByRole('button', { name: 'Créer l’établissement' }).click();
  await expect(page.getByText('L’établissement « École Primaire Recette » est créé.')).toBeVisible();
  expect(state.establishments.map((e) => e.name)).toContain('École Primaire Recette');
  expectNoUnhandled(state);
});

test('exports CSV et Excel depuis la liste des personnes', async ({ page }, info) => {
  const state = await signIn(page, 'admin');
  await page.goto('/personnes');
  const group = page.getByRole('group', { name: /export/i }).first();
  await expect(group).toBeVisible();
  await shot(page, info, '14-personnes');
  await checkA11y(page, info, 'personnes');

  const [csv] = await Promise.all([page.waitForEvent('download'), group.getByRole('button', { name: 'Exporter en CSV' }).click()]);
  const csvText = readFileSync((await csv.path())!, 'utf8');
  expect(csvText).toContain('Identifiant;Prénom;Nom');
  const [xlsx] = await Promise.all([page.waitForEvent('download'), group.getByRole('button', { name: 'Exporter en Excel' }).click()]);
  expect(readFileSync((await xlsx.path())!).subarray(0, 2).toString()).toBe('PK');
  expect(state.exports.some((e) => e.includes('format=xlsx'))).toBe(true);
  expectNoUnhandled(state);
});

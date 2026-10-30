import { expect, test } from '@playwright/test';
import { checkA11y, expectNoUnhandled, shot, signIn } from './helpers';

test('appel saisi hors ligne puis resynchronisé au retour du réseau', async ({ page, context }, info) => {
  const state = await signIn(page, 'teacher');
  await page.goto('/appel');
  await expect(page.getByRole('heading', { name: 'Appel', level: 1 })).toBeVisible();
  await expect(page.getByRole('heading', { name: /Mathématiques · 3e A/ })).toBeVisible();
  await shot(page, info, '03-appel-creneaux');
  await checkA11y(page, info, 'appel-creneaux');

  await page.getByRole('button', { name: "Faire l'appel" }).click();
  await expect(page.getByRole('button', { name: "Valider l'appel (0/3)" })).toBeDisabled();

  // Coupure réseau : la saisie continue et l'appel est mis en file sur l'appareil.
  await context.setOffline(true);
  await page.getByRole('group', { name: 'Statut de FOKA Nadia' }).getByRole('button', { name: 'Présent' }).click();
  await page.getByRole('group', { name: 'Statut de EWANE Yann' }).getByRole('button', { name: 'Absent' }).click();
  await page.getByRole('group', { name: 'Statut de NGO Chloé' }).getByRole('button', { name: 'Présent' }).click();
  await shot(page, info, '04-appel-saisie');
  await checkA11y(page, info, 'appel-saisie');
  await page.getByRole('button', { name: "Valider l'appel (3/3)" }).click();
  await expect(page.getByText("L'appel est enregistré sur l'appareil. Il sera synchronisé au retour du réseau.")).toBeVisible();
  await expect(page.getByText("En attente d'envoi", { exact: true })).toBeVisible();
  await expect(page.getByTestId('pending-counter')).toHaveText("1 appel en attente d'envoi");
  expect(state.attendanceSyncs, 'Rien n\'est parti pendant la coupure.').toHaveLength(0);

  // Retour du réseau : synchronisation automatique, une seule fois, avec les trois lignes.
  await context.setOffline(false);
  await expect.poll(() => state.attendanceSyncs.length, { timeout: 15_000 }).toBe(1);
  const sent = state.attendanceSyncs[0] as { records: Array<{ person_id: string; status: string }>; validate?: boolean };
  expect(sent.records.map((r) => r.status).sort()).toEqual(['A', 'P', 'P']);
  await expect(page.getByTestId('pending-counter')).not.toContainText(/[1-9]/, { timeout: 10_000 });
  await shot(page, info, '05-appel-synchronise');
  expectNoUnhandled(state);
});

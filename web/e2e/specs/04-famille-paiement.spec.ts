import { expect, test } from '@playwright/test';
import { checkA11y, expectNoUnhandled, shot, signIn } from './helpers';

test('portail famille : enfant, solde et paiement mobile money confirmé', async ({ page }, info) => {
  const state = await signIn(page, 'parent', { paymentConfirmAfterPolls: 1 });
  await page.goto('/portail/famille');
  await expect(page.getByRole('heading', { name: 'Mes enfants', level: 1 })).toBeVisible();
  await expect(page.getByText('Nadia Foka').first()).toBeVisible();
  await expect(page.getByText(/300\s?000/).first()).toBeVisible();
  await shot(page, info, '09-famille');
  await checkA11y(page, info, 'famille');

  await page.getByRole('button', { name: /Tout payer/ }).click();
  await page.getByLabel('Numéro à débiter').fill('+237 6 70 00 01 23');
  await shot(page, info, '10-famille-paiement');
  await checkA11y(page, info, 'famille-paiement');
  await page.getByRole('button', { name: /^Payer / }).click();
  await expect(page.getByText('Payé')).toBeVisible({ timeout: 15_000 });
  await expect(page.getByText(/Reçu REC-2026-00001/)).toBeVisible();
  expect(state.idempotencyKeys.length, 'POST /payments porte une Idempotency-Key').toBeGreaterThan(0);
  expectNoUnhandled(state);
});

test('refus du serveur (403) pendant un paiement : l\'écran le dit', async ({ page }, info) => {
  /** Le défaut D2 est corrigé (tests/Feature/Billing/SelfServicePaymentTest) ; ce cas garde l'affichage d'un refus serveur. */
  const state = await signIn(page, 'parent', { parentPaymentForbidden: true });
  await page.goto('/portail/famille');
  await page.getByRole('button', { name: /Tout payer/ }).click();
  await page.getByLabel('Numéro à débiter').fill('+237 6 70 00 01 23');
  await page.getByRole('button', { name: /^Payer / }).click();
  await expect(page.getByText(/refus|droit|Accès/i).first()).toBeVisible({ timeout: 10_000 });
  await shot(page, info, '11-famille-paiement-refuse');
  expect(state.calls.some((c) => c.startsWith('GET /payment-references')), 'Le front cherche d\'abord la référence famille').toBe(true);
});

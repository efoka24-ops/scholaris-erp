import { test } from '@playwright/test';
import { actorPage, expect, selectContaining, startStack, uiLogin, type Stack } from './stack';

/**
 * Parcours 5 — Maternelle privée (C-110), sur la pile réelle. Semis minimal par l'API (« owner ») : client « nursery », année
 * et trimestres, administrateur qui est aussi la direction (rôles nursery_directeur et nursery_secretaire). Tout le reste se
 * fait à l'écran : section, inscription d'un enfant et de son parent, consentement photo signé, carnet trimestriel rédigé,
 * soumis, validé et publié, personne autorisée et sa carte (DOC-21), remise refusée à une personne non autorisée.
 */
test.describe.configure({ timeout: 600_000 });
test.use({ actionTimeout: 45_000 });

interface Owner {
  establishment: string;
  logins: Record<string, [string, string]>;
}

let stack: Stack;
test.beforeAll(async () => {
  test.setTimeout(120_000);
  stack = await startStack();
});
test.afterAll(async () => {
  await stack?.stop();
});

test('maternelle privée : section, enfant, consentement photo, carnet publié, carte des autorisés, remise refusée', async ({ browser }, info) => {
  const ctx = await stack.seed<Owner>('nurseryStaff');
  const page = await actorPage(browser, stack, info);
  await uiLogin(page, ...ctx.logins.director!);

  // 1. Section, créée par la directrice.
  await page.goto('/maternelle-privee/sections');
  await page.getByLabel('Nom de la section').fill('Petite section Soleil');
  await page.getByLabel('Effectif maximal (facultatif)').fill('25');
  await page.getByRole('button', { name: 'Créer la section' }).click();
  await expect(page.getByText('Petite section Soleil').first()).toBeVisible();

  // Défaut C110-NP1 (rapport) : une section sans autorisation administrative refuse toute inscription (422 sur structure_id,
  // affiché « Données invalides » sans explication) et aucun écran ne permet de déclarer cette autorisation. L'administrateur
  // la déclare donc par l'API du socle (POST /authorizations), comme le ferait l'outil d'installation.
  info.annotations.push({ type: 'défaut', description: 'C110-NP1' });
  const admin = await stack.login(...ctx.logins.admin!);
  const director = await stack.login(...ctx.logins.director!);
  const sections = await stack.api('GET', '/nursery/sections', undefined, { token: director.token, establishment: ctx.establishment });
  const section = (sections.data as Array<{ id: string; label: string }>).find((s) => s.label === 'Petite section Soleil')!;
  const year = new Date().getFullYear();
  await stack.api('POST', '/authorizations', { structure_id: section.id, reference: 'ARR-MAT-E2E-001', authority: 'MINEDUB', valid_from: `${year - 1}-01-01`, valid_to: `${year + 3}-12-31` }, { token: admin.token, establishment: ctx.establishment, expect: 201 });

  // 2. Inscription de l'enfant et de son parent par la secrétaire.
  const desk = await actorPage(browser, stack, info);
  await uiLogin(desk, ...ctx.logins.secretary!);
  await desk.goto('/maternelle-privee/inscription');
  await expect(desk.getByRole('heading', { name: 'Inscription', level: 1 })).toBeVisible();
  const birth = `${new Date().getFullYear() - 4}-03-14`;
  await desk.locator('#en-first').fill('Nadia');
  await desk.locator('#en-last').fill('Fictive-Soleil');
  await desk.getByLabel('Date de naissance').fill(birth);
  await desk.getByLabel('Sexe').selectOption('female');
  await desk.getByLabel('Section', { exact: true }).selectOption({ label: 'Petite section Soleil' });
  await desk.locator('#en-pf').fill('Paul');
  await desk.locator('#en-pl').fill('Fictif-Soleil');
  await desk.getByLabel('Téléphone').fill(stack.phone());
  await desk.getByRole('button', { name: 'Inscrire l’enfant' }).click();
  await expect(desk.getByText('Enfant inscrit.')).toBeVisible();

  const pick = async (): Promise<void> => {
    await page.getByLabel('Section', { exact: true }).selectOption({ label: 'Petite section Soleil' });
    const child = page.getByLabel('Enfant', { exact: true });
    if (await child.count()) await selectContaining(child, 'Nadia');
  };

  // 3. Consentement photo : formulaire signé enregistré.
  await page.goto('/maternelle-privee/consentement-photo');
  await pick();
  await expect(page.getByText('Aucun consentement', { exact: true })).toBeVisible();
  await page.getByLabel('Référence du formulaire').fill('CONS-2026-001');
  await page.getByLabel('Signé le').fill(new Date().toISOString().slice(0, 10));
  await page.getByLabel('Nom du parent signataire').fill('Paul Fictif-Soleil');
  await page.getByRole('button', { name: 'Enregistrer le consentement' }).click();
  await expect(page.getByText('Consentement en vigueur', { exact: true })).toBeVisible();

  // 4. Carnet trimestriel : préparé, rédigé, soumis, validé, publié aux parents.
  // L'éducatrice prépare, rédige et soumet ; la directrice valide et publie.
  const educator = await actorPage(browser, stack, info);
  await uiLogin(educator, ...ctx.logins.educator!);
  await educator.goto('/maternelle-privee/carnets');
  await educator.getByLabel('Section', { exact: true }).selectOption({ label: 'Petite section Soleil' });
  await selectContaining(educator.getByLabel('Enfant', { exact: true }), 'Nadia');
  await educator.getByRole('button', { name: 'Préparer un carnet' }).click();
  await educator.getByRole('button', { name: 'Ouvrir' }).first().click();
  await educator.getByLabel('Synthèse du trimestre').fill('Nadia participe avec plaisir aux activités et progresse en langage.');
  await educator.getByRole('button', { name: 'Enregistrer' }).click();
  await educator.getByRole('button', { name: 'Soumettre' }).click();
  await expect(educator.getByRole('button', { name: 'Soumettre' })).toHaveCount(0);
  await page.goto('/maternelle-privee/carnets');
  await page.getByLabel('Section', { exact: true }).selectOption({ label: 'Petite section Soleil' });
  await page.getByRole('button', { name: 'Valider' }).click();
  await page.getByRole('button', { name: 'Publier aux parents' }).click();
  await expect(page.getByRole('button', { name: 'Document (FR)' })).toBeVisible();

  // 5. Personne autorisée permanente et sa carte (DOC-21).
  await page.goto('/maternelle-privee/autorises');
  await pick();
  await page.getByLabel('Nom de la personne').fill('Martine Fictive-Tante');
  await page.getByLabel('Lien avec l’enfant').fill('Tante');
  await page.getByLabel('Téléphone').fill(stack.phone());
  await page.getByRole('button', { name: 'Ajouter', exact: true }).click();
  await expect(page.getByText('Martine Fictive-Tante')).toBeVisible();
  await page.getByRole('button', { name: 'Carte' }).first().click();
  await expect(page.getByText(/Document émis : n° \S+/)).toBeVisible();

  // 6. Sortie : une personne non listée se présente, la remise est refusée et les parents alertés.
  await page.goto('/maternelle-privee/sortie');
  await page.getByLabel('Section', { exact: true }).selectOption({ label: 'Petite section Soleil' });
  await page.getByRole('button', { name: 'Personne non listée' }).first().click();
  await page.getByLabel('Nom donné par la personne (pour mémoire seulement)').fill('Inconnu Fictif');
  await page.getByRole('button', { name: 'Refuser et alerter' }).click();
  await expect(page.getByText(/Refus enregistré, \d+ alerte\(s\) envoyée\(s\)\./)).toBeVisible();
  await expect(page.getByRole('button', { name: /Remettre à Inconnu/ })).toHaveCount(0);

  await stack.attachRefusals(info);
  expect(stack.serverErrors()).toEqual([]);
  stack.expectOnlyGetPost();
});

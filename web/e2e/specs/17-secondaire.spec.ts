import { test, type Page } from '@playwright/test';
import { actorPage, closeActors, expect, startStack, uiLogin, type Stack } from './stack';

/**
 * Parcours 7 — Secondaire (C-110), PARTIEL : le reste (séries, coefficients, frais, emploi du temps, appel, notes, conseil,
 * bulletin, portail parent) est en cours de développement (E2, E3, E4) et complétera ce fichier.
 * Semis par le parcours réel de la plateforme (« secondaryLeg ») : demande d'accès d'un lycée d'enseignement général (type
 * « leg »), acceptation, administrateur activé, comptes du chef d'établissement et de la scolarité, année en cours.
 * À l'écran : ouverture du lycée par l'assistant de profil, niveaux d'exemple, classe et inscription avec matricule.
 */
test.describe.configure({ timeout: 600_000 });
test.use({ actionTimeout: 45_000 });

interface Leg {
  logins: Record<string, [string, string]>;
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

async function openLeg(page: Page): Promise<void> {
  // Assistant de profil : valeurs proposées par le type « lycée d'enseignement général », confirmées puis application ouverte.
  await page.goto('/secondaire/profil');
  await expect(page.getByRole('heading', { name: 'Profil de l’établissement', level: 1 })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Confirmer le profil' })).toBeVisible();
  await page.getByRole('button', { name: 'Ouvrir l’application' }).click();
  await expect(page.getByRole('heading', { name: 'Historique du profil' })).toBeVisible();
  // Niveaux d'exemple proposés par le profil.
  await page.goto('/secondaire/parametrage');
  await page.getByRole('button', { name: "Charger les niveaux d'exemple" }).click();
  await expect(page.getByText(/Seconde|2nde/).first()).toBeVisible();
}

test("secondaire : ouverture d'un LEG par l'assistant de profil et niveaux d'exemple", async ({ browser }, info) => {
  const ctx = await stack.seed<Leg>('secondaryLeg');
  const page = await actorPage(browser, stack, info);
  await uiLogin(page, ...ctx.logins.chef!);
  await openLeg(page);
  await stack.attachRefusals(info);
  expect(stack.serverErrors()).toEqual([]);
  stack.expectOnlyGetPost();
});

test("secondaire : classe de Seconde et inscription d'un élève avec son matricule", async ({ browser }, info) => {
  // Défaut C110-PR1 (rapport) : les rôles du secondaire n'ont pas organisation.read ; GET /academic-years répond 403 et l'écran
  // Paramétrage affiche « Aucune année scolaire : créez-la d'abord » alors que l'année existe : aucune classe ne peut être créée,
  // donc aucune inscription. Échec attendu tant que le défaut existe.
  test.fail(true, "C110-PR1 : chef d'établissement sans organisation.read (année scolaire invisible)");
  const ctx = await stack.seed<Leg>('secondaryLeg');
  let page = await actorPage(browser, stack, info);
  await uiLogin(page, ...ctx.logins.chef!);
  await openLeg(page);
  await expect(page.getByText("Aucune année scolaire : créez-la d'abord dans l'administration.")).toHaveCount(0);
  await page.getByRole('navigation', { name: 'Paramétrage' }).getByRole('button', { name: 'Classes' }).click();
  await page.getByLabel(/^(Nom|Libellé)/).first().fill('2nde A');
  await page.getByRole('button', { name: /Ajouter|Créer/ }).first().click();
  await expect(page.getByText('2nde A').first()).toBeVisible();

  // Inscription par la scolarité, avec le matricule national.
  await page.context().close();
  page = await actorPage(browser, stack, info);
  await uiLogin(page, ...ctx.logins.scol!);
  await page.goto('/secondaire/inscriptions');
  await page.getByRole('navigation', { name: 'Inscriptions' }).getByRole('button', { name: 'Inscrire' }).click();
  await page.getByLabel(/^Nom/).first().fill('Fictif-Lyceen');
  await page.getByLabel(/^Prénom/).first().fill('Issa');
  await page.getByLabel(/Date de naissance/).first().fill(`${new Date().getFullYear() - 15}-02-03`);
  await page.getByLabel(/Matricule/).first().fill('LEG-E2E-0001');
  await page.getByLabel(/Classe/).first().selectOption({ label: '2nde A' });
  await page.getByRole('button', { name: /Inscrire/ }).last().click();
  await page.getByRole('navigation', { name: 'Inscriptions' }).getByRole('button', { name: 'Élèves' }).click();
  await expect(page.getByText('LEG-E2E-0001')).toBeVisible();
});

import { test, type Locator, type Page } from '@playwright/test';
import { actorPage, closeActors, expect, selectContaining, startStack, uiLogin, type Stack } from './stack';

/**
 * Parcours 7 — Secondaire (C-110), PARTIEL : le reste (séries, coefficients, frais, emploi du temps, appel, notes, conseil,
 * bulletin, portail parent) est en cours de développement (E2, E3, E4) et complétera ce fichier.
 * Semis par le parcours réel de la plateforme (« secondaryLeg ») : demande d'accès d'un lycée d'enseignement général (type
 * « leg »), acceptation, administrateur activé, comptes du chef d'établissement et de la scolarité, année en cours.
 * À l'écran : ouverture du lycée par l'assistant de profil, niveaux d'exemple, classe et inscription avec matricule.
 */
// Un seul semis par fichier : le semis crée l'administrateur de plateforme, qui ne peut l'être deux fois dans la même base.
// Les tests s'enchaînent donc sur le même lycée (mode série).
test.describe.configure({ mode: 'serial', timeout: 600_000 });
test.use({ actionTimeout: 45_000 });

interface Leg {
  logins: Record<string, [string, string]>;
}

let stack: Stack;
let ctx: Leg;
test.beforeAll(async () => {
  test.setTimeout(300_000);
  stack = await startStack();
  ctx = await stack.seed<Leg>('secondaryLeg');
});
test.afterEach(async () => {
  await closeActors();
});
test.afterAll(async () => {
  await stack?.stop();
});

/** Série C (option « C · … » exactement, pas « AC · … »). */
async function selectSeriesC(select: Locator): Promise<void> {
  const option = select.locator('option').filter({ hasText: /^C · / }).first();
  await expect(option).toBeAttached();
  await select.selectOption((await option.getAttribute('value')) ?? '');
}

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
  const page = await actorPage(browser, stack, info);
  await uiLogin(page, ...ctx.logins.chef!);
  await openLeg(page);
  await stack.attachRefusals(info);
  expect(stack.serverErrors()).toEqual([]);
  stack.expectOnlyGetPost();
});

test("secondaire : classe de Seconde et inscription d'un élève avec son matricule", async ({ browser }, info) => {
  // Défaut C110-PR1 corrigé (C-115) : le chef d'établissement et la scolarité lisent l'année scolaire (organisation.read).
  // E4C-E1 (rapport E4-C) : série C, classe « 2nde A » créées à l'écran (réussi), mais l'inscription par la scolarité n'apparaît
  // pas dans la liste des élèves. Cause probable : autorisation d'ouverture absente pour la classe d'un LEG public (C110-AU1),
  // sans écran pour la déclarer. Échec attendu tant que le défaut existe.
  test.fail(true, "E4C-E1 : inscription d'un élève du LEG non enregistrée (autorisation d'ouverture probable)");
  let page = await actorPage(browser, stack, info);
  await uiLogin(page, ...ctx.logins.chef!);
  // Lycée ouvert et niveaux chargés par le test précédent.
  await page.goto('/secondaire/parametrage');
  await expect(page.getByText("Aucune année scolaire : créez-la d'abord dans l'administration.")).toHaveCount(0);
  // Série C ouverte depuis le catalogue (une classe du 2nd cycle en exige une).
  const tabs = page.getByRole('navigation', { name: 'Paramétrage' });
  await tabs.getByRole('button', { name: 'Séries et spécialités' }).click();
  await selectSeriesC(page.getByLabel('Série ou spécialité du catalogue'));
  await page.getByRole('button', { name: 'Ouvrir', exact: true }).click();
  await expect(page.getByText('Enregistré.')).toBeVisible();
  await tabs.getByRole('button', { name: 'Classes' }).click();
  await page.getByLabel('Libellé').first().fill('2nde A');
  await selectContaining(page.getByLabel('Niveau'), 'Seconde');
  await selectSeriesC(page.getByLabel(/^Série ou spécialité/));
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

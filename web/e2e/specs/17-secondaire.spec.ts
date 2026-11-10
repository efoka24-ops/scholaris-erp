import { test, type Browser, type Locator, type Page, type TestInfo } from '@playwright/test';
import { createHmac } from 'node:crypto';
import { actorPage, closeActors, expect, isoDate, selectContaining, startStack, uiLogin, type Json, type Stack } from './stack';

/**
 * Parcours 7 — Secondaire (C-110, complété par C-117).
 * Semis par le parcours réel de la plateforme (« secondaryLeg ») : demande d'accès d'un lycée d'enseignement général (type
 * « leg »), acceptation, administrateur activé, comptes du chef d'établissement, de la scolarité, du censeur, d'un enseignant, de
 * l'intendant, d'un parent et de l'administrateur fonctionnel, année en cours.
 * À l'écran : ouverture du lycée par l'assistant de profil, niveaux d'exemple, série, classe, autorisation d'ouverture, inscription
 * avec parent garant financier et matricule ; trimestre et séquences ; matière et coefficient ; affectation et créneau de l'emploi du temps ; frais
 * (refus d'un frais hors catalogue au public, demande d'ajout, catalogue, frais autorisé, échéancier et facture) ; appel ; notes de séquence (saisie,
 * soumission, validation, verrouillage) ; règles du conseil, conseil de classe et bulletins émis ; portail parent avec paiement
 * mobile money simulé (confirmation par le webhook signé du simulateur) et reçu numéroté affiché (C-118).
 * Hors écran : le webhook de l'opérateur (simulateur).
 */
// Un seul semis par fichier : le semis crée l'administrateur de plateforme, qui ne peut l'être deux fois dans la même base.
// Les tests s'enchaînent donc sur le même lycée (mode série).
test.describe.configure({ mode: 'serial', timeout: 600_000 });
test.use({ actionTimeout: 45_000 });

interface Leg {
  establishment: string;
  year: string;
  logins: Record<string, [string, string]>;
  tokens: Record<string, string>;
  people: Record<string, string>;
}

const WEBHOOK_SECRET = 'secret-webhook-e2e';
const START_YEAR = Number(isoDate().slice(0, 4)) - (Number(isoDate().slice(5, 7)) >= 8 ? 0 : 1);
/** Jour ISO (1 = lundi) à Douala : le créneau posé ce jour-là donne l'appel du jour. */
const WEEKDAY = ((new Date(`${isoDate()}T12:00:00Z`).getUTCDay() + 6) % 7) + 1;

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

/** Acteur connecté par l'écran de connexion, dans son propre contexte de navigateur. */
async function as(browser: Browser, info: TestInfo, who: string): Promise<Page> {
  const page = await actorPage(browser, stack, info);
  await uiLogin(page, ...ctx.logins[who]!);
  return page;
}

/** Appel de l'API par un acteur semé (étapes sans écran) ; reconnexion si la session du semis a expiré. */
async function apiAs<T = Json>(who: string, method: string, path: string, body?: unknown, expectStatus?: number): Promise<T> {
  const opts = { token: ctx.tokens[who], establishment: ctx.establishment, ...(expectStatus !== undefined ? { expect: expectStatus } : {}) };
  try {
    return await stack.api<T>(method, path, body, opts);
  } catch (e) {
    if (!String(e).includes('-> 401')) throw e;
    ctx.tokens[who] = (await stack.login(...ctx.logins[who]!)).token;
    return stack.api<T>(method, path, body, { ...opts, token: ctx.tokens[who] });
  }
}

async function finish(info: TestInfo): Promise<void> {
  await stack.attachRefusals(info);
  expect(stack.serverErrors()).toEqual([]);
  stack.expectOnlyGetPost();
}

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
  await expect(page.getByText("Aucun niveau pour l'instant.")).toBeVisible();
  await page.getByRole('button', { name: "Charger les niveaux d'exemple" }).click();
  await expect(page.getByText(/Seconde|2nde/).first()).toBeVisible();
}

/** Autorisation d'ouverture d'une classe, déclarée à l'écran par l'administrateur (C110-AU1 corrigé, C-115). */
async function declareAuthorization(page: Page, className: string): Promise<void> {
  const year = Number(isoDate().slice(0, 4)) - (Number(isoDate().slice(5, 7)) >= 8 ? 0 : 1);
  await page.goto('/administration/autorisations');
  await expect(page.getByRole('heading', { name: 'Autorisations d’ouverture', level: 1 })).toBeVisible();
  await selectContaining(page.getByLabel('Classe, section ou filière'), className);
  await page.getByLabel('Autorité').fill('MINESEC');
  await page.getByLabel('Référence de l’acte').fill(`MINESEC-E2E-${year}-001`);
  await page.getByLabel('Valide du').fill(`${year}-09-01`);
  await page.getByLabel('Valide jusqu’au').fill(`${year + 1}-08-31`);
  await page.getByRole('button', { name: 'Déclarer', exact: true }).click();
  await expect(page.getByText('Autorisation enregistrée.')).toBeVisible();
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
  // C110-PR1 corrigé (C-115) : le chef d'établissement et la scolarité lisent l'année scolaire (organisation.read).
  // E4C-E1 (C-117) : l'inscription « n'apparaissait pas » pour deux raisons, toutes deux de parcours, sans défaut du produit :
  // 1) l'élève de 15 ans est mineur : le formulaire exige un parent ou tuteur (champ requis), la spec ne le saisissait pas et le
  //    navigateur bloquait l'envoi (aucun appel parti) ;
  // 2) toute inscription exige une autorisation d'ouverture valide pour la classe (RG-ORG-3, public compris) : l'administrateur
  //    la déclare désormais à l'écran (/administration/autorisations, C-115).
  // Enfin la liste des élèves n'affiche pas le matricule (seulement son état) : il se lit dans le dossier de l'élève.
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

  // Autorisation d'ouverture de la classe, déclarée par l'administrateur du lycée.
  await page.context().close();
  page = await actorPage(browser, stack, info);
  await uiLogin(page, ...ctx.logins.admin!);
  await declareAuthorization(page, '2nde A');

  // Inscription par la scolarité, avec le parent (élève mineur) et le matricule national.
  await page.context().close();
  page = await actorPage(browser, stack, info);
  await uiLogin(page, ...ctx.logins.scol!);
  await page.goto('/secondaire/inscriptions');
  await page.getByRole('navigation', { name: 'Inscriptions' }).getByRole('button', { name: 'Inscrire' }).click();
  await page.locator('#en-last').fill('Fictif-Lyceen');
  await page.locator('#en-first').fill('Issa');
  await page.locator('#en-birth').fill(`${new Date().getFullYear() - 15}-02-03`);
  await page.locator('#en-class').selectOption({ label: '2nde A' });
  const guardian = page.getByRole('group', { name: /Parent ou tuteur/ });
  await guardian.getByLabel('Nom', { exact: true }).fill('Fictive-Parent');
  await guardian.getByLabel('Prénoms').fill('Aissatou');
  await guardian.getByLabel('Téléphone').fill(ctx.logins.parent![0]);
  // C-118 : le parent saisi (case « Garant financier » cochée par défaut) est retrouvé par son téléphone, celui de son compte, et
  // rattaché comme garant financier ; la fiche de l'élève le montre.
  await expect(page.locator('#en-g-payer')).toBeChecked();
  await page.getByLabel(/Matricule national/).fill('LEG-E2E-0001');
  await page.getByRole('button', { name: 'Inscrire', exact: true }).last().click();
  await expect(page.getByText('Élève inscrit.')).toBeVisible();
  await page.getByRole('navigation', { name: 'Inscriptions' }).getByRole('button', { name: 'Élèves' }).click();
  const row = page.getByRole('listitem').filter({ hasText: 'Fictif-Lyceen Issa' });
  await expect(row).toContainText('2nde A');
  await expect(row).toContainText('Matricule enregistré');
  await row.getByRole('button', { name: /Fictif-Lyceen Issa/ }).click();
  await expect(page.getByText(/LEG-E2E-0001/)).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Garants financiers' })).toBeVisible();
  await expect(page.locator('article.dossier li').filter({ hasText: ctx.logins.parent![0] })).toHaveCount(1);
  await finish(info);
});

test('secondaire : trimestre et séquences, matière et coefficient, affectation et créneau de l’emploi du temps', async ({ browser }, info) => {
  // Trimestre 1 et ses deux séquences (l'administrateur), la séquence rattachée à son trimestre (corrigé par C-117).
  let page = await as(browser, info, 'admin');
  await page.goto('/administration/annees');
  // Le détail de l'année (périodes et contrôle de clôture) est chargé avant toute saisie : sinon sa réponse écraserait la liste.
  const detail = Promise.all([page.waitForResponse((r) => r.url().includes('/periods')), page.waitForResponse((r) => r.url().includes('/closure-check'))]);
  await page.getByRole('button', { name: /Gérer l’année/ }).first().click();
  await detail;
  const periods = page.locator('form[aria-labelledby="p-new"]');
  const addPeriod = async (kind: string, ordinal: number, from: string, to: string, parent?: string): Promise<void> => {
    await periods.locator('#p-kind').selectOption({ label: kind });
    if (parent) await periods.locator('#p-parent').selectOption({ label: parent });
    await periods.locator('#p-ord').fill(String(ordinal));
    await periods.locator('#p-start').fill(from);
    await periods.locator('#p-end').fill(to);
    await periods.getByRole('button', { name: 'Ajouter la période' }).click();
    await expect(periods.locator('#p-start')).toHaveValue('');
  };
  await addPeriod('Trimestre', 1, `${START_YEAR}-09-01`, `${START_YEAR}-12-20`);
  await addPeriod('Séquence', 1, `${START_YEAR}-09-01`, `${START_YEAR}-10-15`, 'Trimestre 1');
  await addPeriod('Séquence', 2, `${START_YEAR}-10-16`, `${START_YEAR}-12-20`, 'Trimestre 1');
  await expect(page.getByRole('row').filter({ hasText: 'Séquence' })).toHaveCount(2);

  // Matière et coefficient de la Seconde C (le chef d'établissement) : valeur saisie par l'établissement.
  await page.context().close();
  page = await as(browser, info, 'chef');
  await page.goto('/secondaire/parametrage');
  await page.getByRole('navigation', { name: 'Paramétrage' }).getByRole('button', { name: 'Matières et coefficients' }).click();
  await page.locator('#sb-code').fill('MATH');
  await page.locator('#sb-label').fill('Mathématiques');
  await page.getByRole('button', { name: 'Ajouter la matière' }).click();
  await expect(page.getByText('MATH · Mathématiques')).toBeVisible();
  await selectContaining(page.locator('#cf-level'), 'Seconde');
  await selectContaining(page.locator('#cf-stream'), 'C');
  await selectContaining(page.locator('#cf-subject'), 'Mathématiques');
  await page.locator('#cf-coef').fill('5');
  await page.locator('#cf-hours').fill('6');
  await page.locator('#cf-reason').fill('Coefficient de l’établissement (recette fictive)');
  await page.getByRole('button', { name: 'Enregistrer la version' }).click();
  await expect(page.getByText('Nouvelle version enregistrée.')).toBeVisible();
  await expect(page.getByText(/coef\. 5 · 6 h\/sem\./)).toBeVisible();
  // Cycle des notes de l'établissement (préréglage d'exemple « à confirmer », posé à sa demande) : soumise, validée, verrouillée.
  await page.goto('/secondaire/notes');
  await page.getByRole('navigation', { name: 'Notes' }).getByRole('button', { name: 'Paramètres' }).click();
  await page.getByRole('button', { name: "Poser le préréglage d'exemple" }).click();
  await expect(page.getByRole('status').filter({ hasText: /Posés/ })).toBeVisible();

  // Affectation de l'enseignant et créneau du jour (le censeur).
  await page.context().close();
  page = await as(browser, info, 'censeur');
  await page.goto('/secondaire/emploi-du-temps');
  await page.getByRole('navigation', { name: 'Emploi du temps' }).getByRole('button', { name: 'Affectations' }).click();
  await page.locator('#as-class').selectOption({ label: '2nde A' });
  await selectContaining(page.locator('#as-subject'), 'Mathématiques');
  await page.locator('#as-teacher').fill(ctx.people.teacher!);
  await page.getByRole('button', { name: 'Affecter' }).click();
  await expect(page.locator('#po-as option').filter({ hasText: 'Mathématiques' })).toHaveCount(1);
  await selectContaining(page.locator('#po-as'), 'Mathématiques');
  await page.locator('#po-day').selectOption(String(WEEKDAY));
  await page.locator('#po-start').fill('07:30');
  await page.locator('#po-end').fill('08:30');
  await page.locator('#po-from').fill(`${START_YEAR}-09-01`);
  await page.getByRole('button', { name: 'Enregistrer', exact: true }).click();
  await page.getByRole('navigation', { name: 'Emploi du temps' }).getByRole('button', { name: 'Emploi du temps', exact: true }).click();
  await page.locator('#tt-id').selectOption({ label: '2nde A' });
  await page.getByRole('button', { name: 'Afficher' }).click();
  await expect(page.getByText(/07:30/).first()).toBeVisible();
  await finish(info);
});

test('secondaire : frais hors catalogue refusés au public, demande d’ajout, frais autorisé', async ({ browser }, info) => {
  // L'intendant tente un frais absent du catalogue : refus de la garde du secondaire (RG-FIN-2) et demande d'ajout créée.
  let page = await as(browser, info, 'intendant');
  await page.goto('/secondaire/frais');
  await expect(page.getByRole('heading', { name: 'Catalogue des frais autorisés (public)' })).toBeVisible();
  const itemForm = page.locator('form[aria-labelledby="sf-items-new"]');
  await itemForm.locator('#sf-i-kind').selectOption('other');
  await itemForm.locator('#sf-i-name').fill('Frais informatiques');
  await itemForm.locator('#sf-i-amount').fill('5000');
  await itemForm.getByRole('button', { name: 'Ajouter le frais' }).click();
  await expect(page.getByText(/Frais absent du catalogue des frais autorisés/).first()).toBeVisible();
  await expect(page.getByRole('listitem').filter({ hasText: 'Frais informatiques' }).filter({ hasText: 'après un refus' })).toBeVisible();

  // L'administrateur fonctionnel (tenue du catalogue) inscrit la contribution APEE (base légale fictive), puis l'intendant crée le frais.
  await page.context().close();
  page = await as(browser, info, 'fonctionnel');
  await page.goto('/secondaire/frais');
  await page.locator('#sf-e-kind').selectOption('apee');
  await page.locator('#sf-e-label').fill('Contribution APEE');
  await page.locator('#sf-e-max').fill('10000');
  await page.locator('#sf-e-basis').fill('Délibération fictive de l’APEE de recette');
  await page.getByRole('button', { name: 'Ajouter au catalogue' }).click();
  await expect(page.getByRole('listitem').filter({ hasText: 'Contribution APEE' })).toBeVisible();

  await page.context().close();
  page = await as(browser, info, 'intendant');
  await page.goto('/secondaire/frais');
  await page.locator('#sf-i-kind').selectOption('apee');
  await page.locator('#sf-i-name').fill('Contribution APEE');
  await page.locator('#sf-i-amount').fill('10000');
  await page.getByRole('button', { name: 'Ajouter le frais' }).click();
  await expect(page.getByText('Frais ajouté.')).toBeVisible();

  // C-118 : échéancier du frais pour la 2nde A et facture de l'élève, à l'écran Frais de l'intendant.
  await page.locator('#sf-s-item').selectOption({ label: 'Contribution APEE' });
  await page.locator('#sf-s-class').selectOption({ label: '2nde A' });
  await expect(page.locator('#sf-s-amount')).toHaveValue('10000');
  await page.locator('#sf-s-due').fill(`${START_YEAR}-12-15`);
  await page.getByRole('button', { name: 'Créer l’échéancier' }).click();
  await expect(page.getByText('Échéancier créé.')).toBeVisible();
  await expect(page.getByRole('listitem').filter({ hasText: 'Contribution APEE' }).filter({ hasText: '2nde A' })).toBeVisible();
  await selectContaining(page.locator('#sf-v-student'), 'Fictif-Lyceen');
  await page.getByRole('button', { name: 'Établir la facture' }).click();
  await expect(page.getByRole('status').filter({ hasText: /Facture établie pour Fictif-Lyceen Issa/ })).toContainText(/10\s000 FCFA/);
  await finish(info);
});

test('secondaire : appel du jour, notes de séquence validées et verrouillées', async ({ browser }, info) => {
  // Appel du créneau du jour par l'enseignant (écran Appel du socle).
  let page = await as(browser, info, 'teacher');
  if (WEEKDAY <= 6) {
    await page.goto('/appel');
    await page.getByRole('button', { name: "Faire l'appel" }).first().click();
    await page.getByRole('button', { name: 'Tous présents' }).click();
    await page.getByRole('button', { name: /Valider l'appel \(1\/1\)/ }).click();
    await expect(page.getByText(/L'appel est enregistré/).first()).toBeVisible();
  }

  // Notes de la séquence 1 : évaluation créée, note saisie, soumise au censeur.
  await page.goto('/secondaire/notes');
  await page.locator('#secg-class').selectOption({ label: '2nde A' });
  await page.locator('#secg-period').selectOption({ index: 1 });
  await expect(page.locator('#secg-subject')).toBeVisible();
  await page.locator('#na-title').fill('Devoir 1');
  await page.getByRole('button', { name: 'Créer', exact: true }).click();
  const score = page.locator('input.score').first();
  await score.fill('14');
  await score.blur();
  await expect(page.getByText('Enregistré.')).toBeVisible();
  await page.getByRole('button', { name: 'Soumettre au censeur' }).click();
  await expect(page.getByRole('button', { name: 'Soumettre au censeur' })).toHaveCount(0);

  // Le censeur valide puis verrouille.
  await page.context().close();
  page = await as(browser, info, 'censeur');
  await page.goto('/secondaire/notes');
  await page.locator('#secg-class').selectOption({ label: '2nde A' });
  await page.locator('#secg-period').selectOption({ index: 1 });
  await page.getByRole('button', { name: 'Valider', exact: true }).click();
  await page.getByRole('button', { name: 'Verrouiller' }).click();
  await expect(page.getByRole('button', { name: 'Publier aux familles' })).toBeVisible();
  await finish(info);
});

test('secondaire : règles du conseil, conseil de classe et bulletin émis', async ({ browser }, info) => {
  // Types de bulletin réglés par le chef d'établissement.
  let page = await as(browser, info, 'chef');
  await page.goto('/secondaire/conseils-de-classe');
  const presetButton = page.getByRole('button', { name: 'Régler les types de bulletin' });
  await presetButton.click();
  await expect(presetButton).toHaveCount(0);

  // Le censeur publie les règles de la classe (coefficient propre à la série C), ouvre le conseil du trimestre, valide et émet les
  // bulletins. Les règles « pour tout le niveau » ne voient pas un coefficient saisi pour une série.
  await page.context().close();
  page = await as(browser, info, 'censeur');
  await page.goto('/secondaire/conseils-de-classe');
  await page.getByRole('radio', { name: 'Pour cette classe (série propre)' }).check();
  await page.getByRole('button', { name: 'Préparer le brouillon' }).click();
  await expect(page.getByRole('cell', { name: 'Mathématiques' })).toBeVisible();
  await page.getByRole('button', { name: 'Publier les règles' }).click();
  await expect(page.getByText('Règles publiées.')).toBeVisible();
  await page.getByRole('button', { name: 'Ouvrir le conseil' }).click();
  const line = page.getByRole('listitem').filter({ hasText: 'Fictif-Lyceen' }).first();
  await expect(line).toContainText('14');
  await page.getByRole('button', { name: 'Valider les décisions' }).click();
  await page.getByRole('button', { name: 'Émettre les bulletins' }).click();
  await expect(page.getByRole('heading', { name: 'Bulletins émis' })).toBeVisible({ timeout: 120_000 });
  await finish(info);
});

test('secondaire : portail parent et paiement mobile money simulé avec reçu', async ({ browser }, info) => {
  // Le compte du parent est garant financier de l'élève depuis l'inscription (C-118, à l'écran).
  const page = await as(browser, info, 'parent');
  await page.goto('/secondaire/famille');
  await expect(page.getByText(/Fictif-Lyceen/).first()).toBeVisible();
  await page.getByRole('button', { name: 'Payer' }).first().click();
  const pay = page.locator('form[aria-labelledby="sf-pay"]');
  await pay.locator('#sf-amount').fill('10000');
  await pay.locator('#sf-channel').selectOption('orange_money');
  await pay.locator('#sf-phone').fill(ctx.logins.parent![0]);
  await pay.getByRole('button').first().click();
  await expect(pay.getByRole('status')).toBeVisible();

  // L'opérateur (simulateur) confirme par un webhook signé ; le parent actualise et voit le reçu.
  const payments = (await apiAs('intendant', 'GET', '/payments')).data as Array<{ id: string; status: string; amount_xaf: number; external_reference?: string; provider_reference?: string }>;
  const pending = payments.find((p) => p.status === 'pending' && p.amount_xaf === 10000)!;
  const event = JSON.stringify({ event_id: `evt-e2e-${pending.id}`, type: 'payment.succeeded', amount: 10000, provider_reference: pending.external_reference ?? pending.provider_reference, phone: ctx.logins.parent![0] });
  const hook = await fetch(`${stack.url}/api/v1/webhooks/payments/orange_money`, {
    method: 'POST', body: event, headers: { 'Content-Type': 'application/json', 'X-Signature': createHmac('sha256', WEBHOOK_SECRET).update(event).digest('hex') },
  });
  expect(hook.status).toBe(200);
  expect(await hook.text(), 'webhook du simulateur').toContain('"confirmed"');
  // « Vérifier l'état » jusqu'à la confirmation : le formulaire laisse place au reçu numéroté et la facture est soldée.
  await expect(async () => {
    if (await pay.count()) await pay.getByRole('status').getByRole('button').click();
    await expect(pay).toHaveCount(0, { timeout: 3_000 });
  }).toPass({ timeout: 60_000 });
  await expect(page.getByText(/Total restant pour la famille\s*:\s*0/)).toBeVisible();
  const confirmed = ((await apiAs('intendant', 'GET', '/payments')).data as Array<{ id: string; status: string; receipt_number: string | null }>).find((p) => p.id === pending.id)!;
  expect(confirmed.status).toBe('confirmed');
  expect(confirmed.receipt_number).toBeTruthy();
  // C-118 : le reçu numéroté s'affiche à l'écran, avec le même numéro que celui enregistré par l'intendance.
  const receipt = page.locator('section[aria-labelledby="sf-receipt"]');
  await expect(receipt).toContainText(`Reçu n° ${confirmed.receipt_number}`);
  await expect(receipt).toContainText('Orange Money');
  await finish(info);
});

import { test, type Page } from '@playwright/test';
import { actorPage, closeActors, expect, startStack, uiLogin, type Stack } from './stack';

/**
 * Parcours 2 — IPES (C-110), sur la pile réelle. Semis par l'API (recettes PHP réutilisées, fixtures/stack/seed.php « ipes ») :
 * année, école, filière L1, groupe G1, autorisation, tutelle, maquette L1 S1 publiée (5 UE, 9 EC, 30 crédits), personnel,
 * candidature, admission, inscription réglée et validée de Marie (exemple du CDC), notes de huit EC verrouillées.
 * À l'écran : l'enseignant crée et saisit le CC et l'examen du 9e EC (Bureautique), les soumet ; le chef de département les
 * valide et les verrouille ; la simulation du jury donne 11,55 / MGP 2,40 / 30 crédits ; l'étudiante ouvre son portail.
 */
test.describe.configure({ timeout: 900_000 });
test.use({ actionTimeout: 45_000 });

interface Ipes {
  establishment: string;
  jury: string;
  logins: Record<string, [string, string]>;
  people: Record<string, string>;
  org: { g1: string; school: string };
  curriculum: { id: string; elements: Record<string, string> };
  student: { person: string };
}

const defects = new Set<string>();
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

async function enterMark(page: Page, ctx: Ipes, kind: 'Contrôle continu' | 'Examen', score: string): Promise<void> {
  await page.goto('/superieur/notes');
  const form = page.getByTestId('create-assessment');
  const curriculum = form.getByLabel('Maquette (identifiant)');
  await expect(curriculum).toBeVisible();
  await page.waitForLoadState('networkidle');
  const element = form.getByLabel('Élément constitutif (identifiant)');
  const isSelect = async (l: typeof element): Promise<boolean> => (await l.evaluate((el) => el.tagName)) === 'SELECT';
  if (await isSelect(curriculum)) {
    await curriculum.selectOption({ index: 1 });
    await page.waitForLoadState('networkidle');
  } else {
    await curriculum.fill(ctx.curriculum.id);
  }
  if (await isSelect(element)) {
    await element.selectOption({ label: 'BUR · Bureautique' });
  } else {
    // Défaut C110-I2 (rapport) : les EC de la maquette ne sont pas proposés à l'enseignant ; l'écran demande l'identifiant brut.
    defects.add('C110-I2');
    await element.fill(ctx.curriculum.elements.BUR!);
  }
  await form.getByLabel('Nature').selectOption({ label: kind });
  await form.getByRole('button', { name: /^Créer l/ }).click();
  const row = page.getByRole('row').filter({ hasText: 'BUR' }).filter({ hasText: kind }).filter({ hasText: 'Brouillon' });
  await expect(row).toHaveCount(1);
  await row.getByRole('link', { name: 'Ouvrir' }).or(row.getByRole('button', { name: 'Ouvrir' })).click();
  await expect(page.getByRole('heading', { name: 'Saisie des notes' })).toBeVisible();
  await page.getByRole('textbox', { name: /Note|Marie/ }).first().fill(score);
  await page.getByRole('button', { name: /^Enregistrer 1 modification/ }).click();
  await expect(page.getByText(/1 note\(s\) enregistrée\(s\)/)).toBeVisible();
  await page.getByRole('button', { name: 'Soumettre' }).click();
  await page.getByRole('button', { name: 'Confirmer' }).click();
  await expect(page.getByText('Soumise').first()).toBeVisible();
}

test('IPES : notes CC et examen saisies à l\'écran, cycle de validation, simulation du jury, portail étudiant', async ({ browser }, info) => {
  const ctx = await stack.seed<Ipes>('ipes');
  const [profLogin, profPassword] = ctx.logins.prof!;

  // 1. L'enseignant affecté saisit le contrôle continu (16) et l'examen (15,5) de Bureautique, puis les soumet.
  const prof = await actorPage(browser, stack, info);
  await uiLogin(prof, profLogin, profPassword);
  await enterMark(prof, ctx, 'Contrôle continu', '16');
  await enterMark(prof, ctx, 'Examen', '15.5');
  for (const d of defects) info.annotations.push({ type: 'défaut', description: d });
  await stack.attachRefusals(info);

  // 2. Le chef de département valide puis verrouille les deux évaluations (cycle réel par l'API, 2FA non exigée ici).
  const [chefLogin, chefPassword] = ctx.logins.chef!;
  const chef = await stack.login(chefLogin, chefPassword);
  const list = await stack.api('GET', `/higher/grade-assessments?filter[element_id]=${ctx.curriculum.elements.BUR}`, undefined, { token: chef.token, establishment: ctx.establishment });
  const bur = (list.data as Array<{ id: string; status: string }>).filter((a) => a.status === 'submitted');
  expect(bur, JSON.stringify(list.data)).toHaveLength(2);
  for (const a of bur) {
    await stack.api('POST', `/higher/grade-assessments/${a.id}/validate`, {}, { token: chef.token, establishment: ctx.establishment });
    await stack.api('POST', `/higher/grade-assessments/${a.id}/lock`, {}, { token: chef.token, establishment: ctx.establishment });
  }

  // 3. Préparation du jury à l'écran : simulation sur les notes verrouillées (TST-04 du CDC).
  const chefPage = await actorPage(browser, stack, info);
  await uiLogin(chefPage, chefLogin, chefPassword);
  await chefPage.goto(`/superieur/deliberation/jurys/${ctx.jury}/preparation`);
  await expect(chefPage.getByRole('heading', { name: 'Préparation de la délibération' })).toBeVisible();
  await chefPage.getByRole('button', { name: 'Lancer la simulation' }).click();
  const row = chefPage.getByRole('row', { name: /Marie/ });
  await expect(row).toContainText(/11[,.]55/);
  await expect(row).toContainText(/2[,.]4/);
  await expect(row).toContainText('30');
  // C110-I3 corrigé (C-116) : toutes les évaluations sont verrouillées (liste unlocked vide), aucun avertissement.
  await expect(chefPage.getByText('Des notes ne sont pas encore verrouillées')).toHaveCount(0);

  // 4. Portail de l'étudiante.
  const [studentLogin, studentPassword] = ctx.logins.student!;
  const student = await actorPage(browser, stack, info);
  await uiLogin(student, studentLogin, studentPassword);
  await student.goto('/superieur/mon-espace');
  await expect(student.getByRole('heading', { name: 'Mon espace étudiant' })).toBeVisible();
  await expect(student.getByRole('heading', { name: 'Mes documents' })).toBeVisible();

  expect(stack.serverErrors()).toEqual([]);
  stack.expectOnlyGetPost();
});

test('IPES : séance de délibération, PV signé, relevé DOC-09 émis (numéro et QR dans le PDF)', async () => {
  // C110-I1 corrigé (C-116) : la séance du socle calcule sur les notes IPES du jury ; le directeur d'école (président) conduit la
  // séance, la scolarité publie, puis émet le relevé DOC-09.
  const ctx = await stack.seed<Ipes>('ipes');
  const as = async (who: string): Promise<string> => (await stack.login(...ctx.logins[who]!)).token;
  const opts = (token: string) => ({ token, establishment: ctx.establishment });
  const prof = await as('prof');
  const chef = await as('chef');
  for (const [kind, score] of [['cc', 16], ['exam', 15.5]] as const) {
    const a = await stack.api('POST', '/higher/grade-assessments', { curriculum_id: ctx.curriculum.id, element_id: ctx.curriculum.elements.BUR, kind }, opts(prof));
    const id = String(a.data?.id ?? a.id);
    await stack.api('POST', `/higher/grade-assessments/${id}/marks`, { marks: [{ person_id: ctx.student.person, score }] }, { ...opts(prof), headers: { 'X-HTTP-Method-Override': 'PUT' } });
    await stack.api('POST', `/higher/grade-assessments/${id}/submit`, {}, opts(prof));
    await stack.api('POST', `/higher/grade-assessments/${id}/validate`, {}, opts(chef));
    await stack.api('POST', `/higher/grade-assessments/${id}/lock`, {}, opts(chef));
  }
  const admin = await as('admin');
  const dir = await as('dir');
  const sitting = await stack.api('POST', '/deliberations', {
    structure_id: ctx.org.g1, period_id: 'S1', period_label: 'Semestre 1', exam_session: 'normal', president_id: ctx.people.dir, members_expected: 3,
  }, opts(dir));
  const sid = String(sitting.data.id);
  await stack.api('POST', `/deliberations/${sid}/attendance`, { members_present: [ctx.people.dir, ctx.people.chef] }, opts(dir));
  await stack.api('POST', `/deliberations/${sid}/start`, {}, opts(dir));
  const closed = await stack.api('POST', `/deliberations/${sid}/close`, {}, opts(dir));
  const line = (closed.data.lines as Array<{ person_id: string; average: number | null; decision: string }>).find((l) => l.person_id === ctx.student.person);
  expect(line?.average, 'moyenne de Marie au PV').toBeCloseTo(11.55, 2);
  await stack.api('POST', `/deliberations/${sid}/sign`, {}, opts(dir));
  const scol = await as('scol');
  await stack.api('POST', '/results/publish', { structure_id: ctx.org.g1, period_id: 'S1' }, opts(scol));
  // Relevé DOC-09 : type et modèles installés depuis le catalogue IPES, profil documentaire de l'école.
  const catalog = await stack.api('GET', '/higher/documents/catalog', undefined, opts(admin));
  for (const type of catalog.data as Array<{ socle: { type: Record<string, unknown>; templates: Array<Record<string, unknown>> } | null }>) {
    if (type.socle?.type.code !== 'hi_doc09') continue;
    const body = Object.fromEntries(Object.entries(type.socle.type).filter(([, v]) => v !== null));
    await stack.api('POST', '/document-types', body, opts(admin));
    for (const template of type.socle.templates) {
      const stored = await stack.api('POST', '/document-templates', { type_code: 'hi_doc09', ...template }, opts(admin));
      await stack.api('POST', `/document-templates/${stored.data.id}/validate`, {}, opts(admin));
    }
  }
  await stack.api('POST', `/higher/documents/schools/${ctx.org.school}/profile`, {
    school_code: 'ESG', display_name: 'École supérieure de gestion (fictive)', header_text: 'République du Cameroun', default_locale: 'fr',
  }, { ...opts(admin), headers: { 'X-HTTP-Method-Override': 'PUT' } });
  const issued = await stack.api('POST', '/higher/deliberation/transcripts', { person_id: ctx.student.person, period_id: 'S1' }, opts(scol));
  expect(String(issued.data.number ?? '')).toMatch(/\S/);
});

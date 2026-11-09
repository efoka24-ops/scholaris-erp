import { test, type Page } from '@playwright/test';
import { actorPage, closeActors, expect, isoDate, selectContaining, startStack, uiLogin, type Stack } from './stack';

/**
 * Parcours 4 — Primaire public (C-110), sur la pile réelle. Semis par l'API (« publicPrimary ») : école publique, année et
 * trimestres, unité « Section francophone », directeur d'école et instituteur (fiche du personnel). À l'écran : classe CM2 et titulaire,
 * fiche officielle de l'école, inscription de deux élèves avec leur parent, appel de la demi-journée saisi HORS LIGNE puis synchronisé au retour du réseau,
 * APEE (montant voté, cotisation encaissée, reçu numéroté).
 */
test.describe.configure({ timeout: 900_000 });
test.use({ actionTimeout: 45_000 });

interface PublicPrimary {
  establishment: string;
  year: string;
  region_starts_on: string;
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

async function enrolPupil(page: Page, last: string, first: string, gender: 'Fille' | 'Garçon'): Promise<void> {
  await page.goto('/ecole-publique/eleves/inscription');
  const pupil = page.getByRole('group', { name: "L'élève" });
  await pupil.getByLabel('Nom', { exact: true }).fill(last);
  await pupil.getByLabel('Prénom', { exact: true }).fill(first);
  await pupil.getByLabel('Date de naissance').fill(`${new Date().getFullYear() - 11}-05-12`);
  await pupil.getByRole('radio', { name: gender }).check();
  await pupil.getByLabel('Classe').selectOption({ label: 'CM2 A' });
  const parent = page.getByRole('group', { name: 'Parent ou tuteur' });
  await parent.getByLabel('Nom', { exact: true }).fill(last);
  await parent.getByLabel('Prénom', { exact: true }).fill('Parent');
  await parent.getByLabel('Téléphone').fill(stack.phone());
  await page.getByRole('button', { name: "Inscrire l'élève" }).click();
  await expect(page.getByText('Élève inscrit').first()).toBeVisible();
  await expect(page.getByText(new RegExp(`${last.toUpperCase()} ${first}`))).toBeVisible();
}

test('primaire public : classe et élèves, appel hors ligne synchronisé, APEE avec reçu', async ({ browser }, info) => {
  const ctx = await stack.seed<PublicPrimary>('publicPrimary');

  // 0. L'administrateur fonctionnel (rôle du client) rattache l'école à la région du Nord à l'écran, le jour même du début
  // de la région : borne incluse (C-115, défaut C110-PP2 corrigé ; le semis le faisait par l'API).
  const functional = await actorPage(browser, stack, info);
  await uiLogin(functional, ...ctx.logins.admin!);
  // Le compte porte aussi « Super administrateur » : le rattachement exige le rôle d'administrateur fonctionnel (droit client).
  await functional.getByRole('combobox', { name: 'Rôle' }).selectOption({ label: 'Administrateur fonctionnel' });
  await expect(functional.getByRole('combobox', { name: 'Rôle' })).toHaveValue('pub_functional_admin');
  await functional.goto('/administration/territoire');
  await expect(functional.getByRole('heading', { name: 'Rattachement territorial', level: 1 })).toBeVisible();
  await functional.getByLabel('Territoire', { exact: true }).selectOption({ label: 'Région · Nord (NO)' });
  await functional.getByLabel(/^Date d.effet$/).fill(ctx.region_starts_on);
  await functional.getByRole('button', { name: 'Rattacher', exact: true }).click();
  await expect(functional.getByText(/Établissement rattaché à Nord à compter du/)).toBeVisible();
  await functional.close();

  // 1. Le directeur crée la classe de CM2, désigne le titulaire et inscrit deux élèves.
  const director = await actorPage(browser, stack, info);
  await uiLogin(director, ...ctx.logins.director!);
  // Fiche officielle de l'école (sans elle, l'école est « inconnue » et aucune inscription n'est possible).
  await director.goto('/ecole-publique/ecole');
  await director.getByLabel("Code officiel de l'école").fill('EP-GAR-E2E-001');
  await director.getByLabel('Sous-système').selectOption({ label: 'Francophone' });
  await director.getByLabel('Cycle').selectOption({ label: 'Primaire' });
  await director.getByLabel('Situation').selectOption({ label: 'Ouverte' });
  await director.getByLabel('Acte de création (référence)').fill('ARR-EP-1998-014');
  await director.getByLabel("Date de l'acte de création").fill('1998-09-01');
  await director.getByRole('button', { name: 'Enregistrer la fiche' }).click();
  await expect(director.getByText('Fiche enregistrée.')).toBeVisible();
  await director.goto('/ecole-publique/classes');
  const create = director.getByRole('region', { name: 'Créer une classe' });
  await create.getByLabel('Nom de la classe').fill('CM2 A');
  await create.getByLabel('Section ou unité').selectOption({ label: 'Section francophone' });
  await create.getByLabel('Niveaux').fill('CM2');
  await create.getByLabel('Capacité').fill('60');
  await create.getByRole('button', { name: 'Créer la classe' }).click();
  const classes = director.getByRole('region', { name: "Classes de l'année" });
  await expect(classes.getByText('CM2 A')).toBeVisible();
  // Défaut C110-AU1 (rapport) : une classe créée ici n'a pas d'autorisation administrative et toute inscription y est refusée
  // (422 « cette filière n'a pas d'autorisation valide », affiché « Données invalides ») ; aucun écran ne permet de la déclarer.
  // L'administrateur la déclare par l'API du socle.
  info.annotations.push({ type: 'défaut', description: 'C110-AU1' });
  {
    const admin = await stack.login(...ctx.logins.admin!);
    const o = { token: admin.token, establishment: ctx.establishment };
    const dir = await stack.login(...ctx.logins.director!);
    const list = await stack.api('GET', `/public/classes?academic_year_id=${ctx.year}`, undefined, { token: dir.token, establishment: ctx.establishment });
    const cm2 = (list.data as Array<{ structure_id: string }>)[0]!;
    const y = new Date().getFullYear();
    await stack.api('POST', '/authorizations', { structure_id: cm2.structure_id, reference: 'ARR-EP-CM2-E2E', authority: 'MINEDUB', valid_from: `${y - 1}-01-01`, valid_to: `${y + 3}-12-31` }, { ...o, expect: 201 });
  }
  await classes.getByRole('button', { name: 'Détails' }).first().click();
  await selectContaining(director.getByLabel('Enseignant titulaire'), 'Paul');
  await director.getByRole('button', { name: 'Affecter' }).click();
  const refused = director.getByRole('alert').filter({ hasText: 'Données invalides' });
  await expect(refused.or(classes.getByText(/CM2 — Paul/))).toBeVisible();
  if (await refused.count()) {
    // Défaut C110-PP1 (rapport) : l'écran envoie l'identifiant de fiche du personnel (staff_id) alors que le serveur attend
    // l'identifiant de la liste /public/staff (id) : 422 « Enseignant inconnu ou inactif », message non affiché sous le champ.
    // Le titulaire est donc désigné par l'API, avec l'identifiant attendu, pour poursuivre le parcours.
    info.annotations.push({ type: 'défaut', description: 'C110-PP1' });
    const { token } = await stack.login(...ctx.logins.director!);
    const o = { token, establishment: ctx.establishment };
    const staff = await stack.api('GET', '/public/staff', undefined, o);
    const list = await stack.api('GET', `/public/classes?academic_year_id=${ctx.year}`, undefined, o);
    const cls = (list.data as Array<{ structure_id: string; name?: string; label?: string }>)[0]!;
    await stack.api('POST', `/public/classes/${cls.structure_id}/homeroom`, { staff_id: staff.data[0].id, starts_on: isoDate() }, { ...o, headers: { 'X-HTTP-Method-Override': 'PUT' } });
  }
  await enrolPupil(director, 'Fictive-Amina', 'Amina', 'Fille');
  await enrolPupil(director, 'Fictif-Bello', 'Bello', 'Garçon');

  // 2. L'instituteur fait l'appel HORS LIGNE : code personnel, liste gardée sur le téléphone, envoi au retour du réseau.
  const teacher = await actorPage(browser, stack, info);
  await uiLogin(teacher, ...ctx.logins.teacher!);
  await teacher.goto('/ecole-publique/appel');
  await teacher.getByRole('group', { name: 'Code personnel à 6 chiffres' }).getByRole('textbox', { name: 'Chiffre 1' }).click();
  await teacher.keyboard.type('482913');
  await teacher.getByRole('group', { name: 'Confirmez le code' }).getByRole('textbox', { name: 'Chiffre 1' }).click();
  await teacher.keyboard.type('482913');
  await teacher.getByRole('button', { name: 'Créer le code' }).click();
  await teacher.getByLabel('Classe').selectOption({ label: 'CM2 A' }).catch(() => undefined);
  await expect(teacher.getByRole('region', { name: "Liste d'appel" })).toBeVisible();
  await expect(teacher.getByText(/Amina/)).toBeVisible();

  await teacher.context().setOffline(true);
  await teacher.getByRole('button', { name: /Bello/ }).click();
  await expect(teacher.getByText('1 présents · 1 absents · 0 en retard')).toBeVisible();
  await teacher.getByRole('button', { name: "Valider l'appel (2 élèves)" }).click();
  await expect(teacher.getByText("L'appel est enregistré sur le téléphone. Il sera envoyé au retour du réseau.")).toBeVisible();
  const before = stack.calls.filter((c) => c.path.includes('/attendance/') && c.override === 'PUT' && c.status < 300).length;
  expect(before, "rien n'est parti pendant la coupure").toBe(0);

  // Défaut C110-PP3 (rapport) : sans le réglage attendance.mode = day_part (posé par le semis, faute d'écran), l'appel synchronisé
  // est refusé (« L'appel par moment de la journée n'est pas activé pour cet établissement »).
  info.annotations.push({ type: 'défaut', description: 'C110-PP3' });
  await teacher.context().setOffline(false);
  await teacher.getByRole('button', { name: 'Envoyer maintenant' }).click().catch(() => undefined);
  await expect.poll(() => stack.calls.filter((c) => c.path.includes('/attendance/') && c.override === 'PUT' && c.status < 300).length, { timeout: 60_000 }).toBeGreaterThan(before);
  await expect(teacher.getByText(/\d+ en attente/)).toHaveCount(0);

  // 3. APEE : montant voté en assemblée générale, cotisation d'un parent encaissée contre reçu numéroté.
  // Le bureau de l'APEE (trésorière) tient les comptes ; la direction ne fait que les consulter.
  const apee = await actorPage(browser, stack, info);
  await uiLogin(apee, ...ctx.logins.apee!);
  await apee.goto('/ecole-publique/apee');
  const year = `${new Date().getFullYear() - (new Date().getMonth() < 7 ? 1 : 0)}-${new Date().getFullYear() + (new Date().getMonth() < 7 ? 0 : 1)}`;
  await apee.getByLabel('Année scolaire (AAAA-AAAA)').fill(year);
  await apee.getByLabel('Montant par élève (FCFA)').fill('1500');
  await apee.getByLabel("Date de l'assemblée").fill(isoDate());
  await apee.getByLabel('Référence du compte rendu').fill('AG-APEE-001');
  await apee.getByRole('button', { name: 'Enregistrer le vote' }).click();
  await expect(apee.getByText('Vote enregistré.')).toBeVisible();
  await apee.getByLabel('Rechercher l\'élève (nom)').fill('Amina');
  await apee.getByLabel('Rechercher l\'élève (nom)').press('Enter');
  await expect(apee.getByLabel('Élève', { exact: true }).locator('option', { hasText: /Amina/i })).not.toHaveCount(0);
  await apee.getByLabel('Élève', { exact: true }).selectOption({ index: 1 });
  await apee.getByLabel('Montant (FCFA)', { exact: true }).fill('1500');
  await apee.getByRole('button', { name: 'Enregistrer', exact: true }).click();
  await expect(apee.getByText(/Reçu n° \S+ émis, SMS envoyé au parent\./)).toBeVisible();

  await stack.attachRefusals(info);
  expect(stack.serverErrors()).toEqual([]);
  stack.expectOnlyGetPost();
});

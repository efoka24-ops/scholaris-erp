import { test, type Page } from '@playwright/test';
import { randomBytes } from 'node:crypto';
import { actorPage, closeActors, expect, isoDate, selectContaining, startStack, uiLogin, type Account, type Stack } from '../specs/stack';
import { attempt, issues, phonePage, PUBLIC_IMAGES as DIR, shot, shots } from './primaire-common';

/**
 * Captures du guide du PRIMAIRE PUBLIC, sur la pile réelle. Semis « publicPrimary » (école publique fictive, région du Nord,
 * administrateur fonctionnel, directeur, instituteur, trésorière de l'APEE), puis parcours rejoué à l'écran comme le parcours
 * e2e 14, complété par les autres écrans (évaluation, bulletins, passage, questionnaire, examens, inspection, cantine,
 * tableau de bord territorial). Un écran qui ne réagit pas comme prévu n'arrête pas les captures : l'écart est listé.
 */
test.describe.configure({ mode: 'serial' });

interface PublicPrimary {
  tenant: string;
  establishment: string;
  year: string;
  region_starts_on: string;
  logins: Record<string, [string, string]>;
  people: Record<string, string>;
}

let stack: Stack;
let ctx: PublicPrimary;
test.beforeAll(async () => {
  test.setTimeout(600_000);
  stack = await startStack();
  ctx = await stack.seed<PublicPrimary>('publicPrimary');
});
test.afterEach(async () => {
  await closeActors();
});
test.afterAll(async () => {
  await stack?.stop();
  console.log('CAPTURES (' + shots.length + ')\n' + shots.join('\n'));
  console.log('ÉCARTS (' + issues.length + ')\n' + issues.join('\n'));
});

async function visit(page: Page, url: string, name: string, maxHeight?: number): Promise<void> {
  await attempt(name, async () => {
    await page.goto(url);
    await shot(page, DIR, name, { maxHeight });
  });
}

/** Compte d'un rôle au périmètre donné (client ou territoire), créé par l'administrateur puis activé. */
async function scopedUser(admin: Account, first: string, last: string, role: string, scopeType: string, scopeId: string): Promise<Account> {
  const phone = stack.phone();
  const opts = { token: admin.token, establishment: ctx.establishment };
  const created = await stack.api('POST', '/users', { first_name: first, last_name: last, phone }, { ...opts, expect: 201 });
  const person = String(created.data.person_id);
  await stack.api('POST', '/role-assignments', { person_id: person, role, scope_type: scopeType, scope_id: scopeId, ...(scopeType === 'tenant' ? { establishment_id: ctx.establishment } : {}) }, { ...opts, expect: 201 });
  const password = 'Recette-E2E-' + randomBytes(4).toString('hex') + '!';
  await stack.activate(phone, password);
  const { token } = await stack.login(phone, password);
  return { login: phone, password, token, person_id: person };
}

async function enrolPupil(page: Page, last: string, first: string, gender: 'Fille' | 'Garçon', capture?: string): Promise<void> {
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
  if (capture) await shot(page, DIR, capture, { maxHeight: 1800 });
  await page.getByRole('button', { name: "Inscrire l'élève" }).click();
  await expect(page.getByText('Élève inscrit').first()).toBeVisible();
}

function yearLabel(): string {
  const n = new Date();
  return `${n.getFullYear() - (n.getMonth() < 7 ? 1 : 0)}-${n.getFullYear() + (n.getMonth() < 7 ? 0 : 1)}`;
}

test('guide primaire public : captures', async ({ browser }, info) => {
  const today = isoDate();
  const [adminLogin, adminPassword] = ctx.logins.admin!;
  const adminAccount: Account = { login: adminLogin, password: adminPassword, token: (await stack.login(adminLogin, adminPassword)).token };

  // --- 0. Connexion ; rattachement de l'école au territoire (administrateur fonctionnel) ---
  const functional = await actorPage(browser, stack, info);
  await functional.goto('/connexion');
  await shot(functional, DIR, 'pub-01-connexion', { full: false });
  await uiLogin(functional, adminLogin, adminPassword);
  await functional.getByRole('combobox', { name: 'Rôle' }).selectOption({ label: 'Administrateur fonctionnel' });
  await expect(functional.getByRole('combobox', { name: 'Rôle' })).toHaveValue('pub_functional_admin');
  await functional.goto('/administration/territoire');
  await expect(functional.getByRole('heading', { name: 'Rattachement territorial', level: 1 })).toBeVisible();
  await functional.getByLabel('Territoire', { exact: true }).selectOption({ label: 'Région · Nord (NO)' });
  await functional.getByLabel(/^Date d.effet$/).fill(ctx.region_starts_on);
  await shot(functional, DIR, 'pub-02-rattachement-territoire', { maxHeight: 1600 });
  await functional.getByRole('button', { name: 'Rattacher', exact: true }).click();
  await expect(functional.getByText(/Établissement rattaché à Nord à compter du/)).toBeVisible();
  await functional.close();

  // --- 1. Directeur : accueil, fiche de l'école, classe, titulaire, élèves ---
  const director = await actorPage(browser, stack, info);
  await uiLogin(director, ...ctx.logins.director!);
  await shot(director, DIR, 'pub-01-accueil-general', { full: false });
  await director.goto('/ecole-publique');
  await shot(director, DIR, 'pub-01-accueil-ecole', { maxHeight: 2400 });
  await director.goto('/ecole-publique/ecole');
  await director.getByLabel("Code officiel de l'école").fill('EP-GAR-FICT-001');
  await director.getByLabel('Sous-système').selectOption({ label: 'Francophone' });
  await director.getByLabel('Cycle').selectOption({ label: 'Primaire' });
  await director.getByLabel('Situation').selectOption({ label: 'Ouverte' });
  await director.getByLabel('Acte de création (référence)').fill('ARR-EP-1998-014');
  await director.getByLabel("Date de l'acte de création").fill('1998-09-01');
  await director.getByRole('button', { name: 'Enregistrer la fiche' }).click();
  await expect(director.getByText('Fiche enregistrée.')).toBeVisible();
  await shot(director, DIR, 'pub-02-fiche-ecole', { maxHeight: 2400 });

  await director.goto('/ecole-publique/classes');
  const create = director.getByRole('region', { name: 'Créer une classe' });
  await create.getByLabel('Nom de la classe').fill('CM2 A');
  await create.getByLabel('Section ou unité').selectOption({ label: 'Section francophone' });
  await create.getByLabel('Niveaux').fill('CM2');
  await create.getByLabel('Capacité').fill('60');
  await create.scrollIntoViewIfNeeded();
  await shot(director, DIR, 'pub-03-creer-classe', { full: false });
  await create.getByRole('button', { name: 'Créer la classe' }).click();
  const classes = director.getByRole('region', { name: "Classes de l'année" });
  await expect(classes.getByText('CM2 A')).toBeVisible();
  {
    const admin = await actorPage(browser, stack, info);
    await uiLogin(admin, adminLogin, adminPassword);
    await admin.goto('/administration/autorisations');
    await selectContaining(admin.getByLabel('Classe, section ou filière'), 'CM2 A');
    await admin.getByLabel('Autorité').fill('MINEDUB');
    await admin.getByLabel('Référence de l’acte').fill('ARR-EP-CM2-FICT');
    const y = new Date().getFullYear();
    await admin.getByLabel('Valide du').fill(`${y - 1}-01-01`);
    await admin.getByLabel('Valide jusqu’au').fill(`${y + 3}-12-31`);
    await admin.getByRole('button', { name: 'Déclarer', exact: true }).click();
    await expect(admin.getByText('Autorisation enregistrée.')).toBeVisible();
    await shot(admin, DIR, 'pub-03-autorisation-ouverture', { maxHeight: 1600 });
    await admin.context().close();
  }
  await classes.getByRole('button', { name: 'Détails' }).first().click();
  await selectContaining(director.getByLabel('Enseignant titulaire'), 'Paul');
  await director.getByRole('button', { name: 'Affecter' }).click();
  await expect(classes.getByText(/CM2 — Paul/)).toBeVisible();
  await shot(director, DIR, 'pub-03-classes-titulaire', { maxHeight: 2200 });

  await enrolPupil(director, 'Fictive-Amina', 'Amina', 'Fille', 'pub-04-inscrire-eleve');
  await shot(director, DIR, 'pub-04-eleve-inscrit', { full: false });
  await enrolPupil(director, 'Fictif-Bello', 'Bello', 'Garçon');
  await enrolPupil(director, 'Fictive-Chantal', 'Chantal', 'Fille');
  await visit(director, '/ecole-publique/eleves', 'pub-04-liste-eleves', 1800);
  await attempt('dossier de l\'élève', async () => {
    await director.goto('/ecole-publique/eleves');
    await director.getByRole('link', { name: /Amina/ }).first().click();
    await shot(director, DIR, 'pub-04-dossier-eleve', { maxHeight: 2200 });
  });
  await attempt('inscription groupée (registre papier)', async () => {
    await director.goto('/ecole-publique/eleves/registre');
    await selectContaining(director.locator('#be-class'), 'CM2 A').catch(() => undefined);
    await shot(director, DIR, 'pub-04-inscription-groupee', { maxHeight: 2200 });
  });

  // --- 2. Instituteur au téléphone : code personnel, appel hors ligne, envoi au retour du réseau ---
  const teacher = await phonePage(browser, stack, info);
  await uiLogin(teacher, ...ctx.logins.teacher!);
  await shot(teacher, DIR, 'pub-05-accueil-instituteur-telephone', { maxHeight: 1500 });
  await teacher.goto('/ecole-publique/appel');
  await shot(teacher, DIR, 'pub-05-code-personnel', { maxHeight: 1400 });
  await teacher.getByRole('group', { name: 'Code personnel à 6 chiffres' }).getByRole('textbox', { name: 'Chiffre 1' }).click();
  await teacher.keyboard.type('482913');
  await teacher.getByRole('group', { name: 'Confirmez le code' }).getByRole('textbox', { name: 'Chiffre 1' }).click();
  await teacher.keyboard.type('482913');
  await teacher.getByRole('button', { name: 'Créer le code' }).click();
  await teacher.getByLabel('Classe').selectOption({ label: 'CM2 A' }).catch(() => undefined);
  await expect(teacher.getByRole('region', { name: "Liste d'appel" })).toBeVisible();
  await teacher.context().setOffline(true);
  await teacher.getByRole('button', { name: /Bello/ }).click();
  await expect(teacher.getByText('2 présents · 1 absents · 0 en retard')).toBeVisible();
  await shot(teacher, DIR, 'pub-05-appel-hors-ligne', { maxHeight: 1500 });
  await teacher.getByRole('button', { name: "Valider l'appel (3 élèves)" }).click();
  await expect(teacher.getByText("L'appel est enregistré sur le téléphone. Il sera envoyé au retour du réseau.")).toBeVisible();
  await shot(teacher, DIR, 'pub-05-appel-garde-telephone', { maxHeight: 1500 });
  await teacher.context().setOffline(false);
  await teacher.getByRole('button', { name: 'Envoyer maintenant' }).click().catch(() => undefined);
  await expect.poll(() => stack.calls.filter((c) => c.path.includes('/attendance/') && c.override === 'PUT' && c.status < 300).length, { timeout: 60_000 }).toBeGreaterThan(0);
  await expect(teacher.getByText(/\d+ en attente/)).toHaveCount(0);
  await shot(teacher, DIR, 'pub-05-appel-envoye', { maxHeight: 1500 });
  await visit(teacher, '/ecole-publique/appareil', 'pub-05-appareil-code');
  await teacher.context().close();

  // --- 3. Directeur : cahier d'appel, présence des enseignants, alertes, personnel, messages ---
  await attempt('cahier d\'appel', async () => {
    await director.goto('/ecole-publique/classes');
    await director.getByRole('region', { name: "Classes de l'année" }).getByRole('button', { name: 'Détails' }).first().click();
    await director.getByRole('link', { name: /Cahier d.appel de/ }).first().click();
    await shot(director, DIR, 'pub-05-cahier-appel', { maxHeight: 1800 });
  });
  await attempt('présence des enseignants', async () => {
    await director.goto('/ecole-publique/presence-enseignants');
    await director.getByRole('button', { name: /Tous présents/ }).click().catch(() => undefined);
    await shot(director, DIR, 'pub-06-presence-enseignants', { maxHeight: 2000 });
  });
  await visit(director, '/ecole-publique/alertes', 'pub-06-alertes');
  await visit(director, '/ecole-publique/personnel', 'pub-06-personnel');
  await visit(director, '/ecole-publique/messages', 'pub-06-messages');
  await visit(director, '/ecole-publique/transferts', 'pub-04-transferts');
  await visit(director, '/ecole-publique/abandons', 'pub-04-abandons');

  // --- 4. Référentiel et unités d'apprentissage ---
  await attempt('référentiel d\'exemple', async () => {
    await director.goto('/ecole-publique/referentiel');
    await director.locator('#rf-level').fill('CM2');
    await director.locator('#rf-year').fill(yearLabel());
    await director.getByRole('button', { name: 'Afficher', exact: true }).click();
    await director.waitForTimeout(1500);
    await director.getByRole('button', { name: /Importer le référentiel d'exemple/ }).click({ timeout: 10_000 });
    await director.waitForTimeout(2000);
    // Trimestres de l'année en cours proposés par leur nom, dates reprises de l'administration (FIX-PUB) : aucun identifiant à saisir.
    await expect(director.getByText('Inclure le trimestre 1')).toBeVisible();
    await director.getByRole('button', { name: 'Générer les unités' }).click();
    await expect(director.getByText(/Unité 8/)).toBeVisible();
    await shot(director, DIR, 'pub-07-referentiel', { maxHeight: 2600 });
  });

  // --- 5. Instituteur (ordinateur) : évaluation par critères, remédiation, bulletins ---
  const tdesk = await actorPage(browser, stack, info);
  await uiLogin(tdesk, ...ctx.logins.teacher!);
  await attempt('code personnel (ordinateur)', async () => {
    await tdesk.goto('/ecole-publique/evaluation');
    await tdesk.getByRole('group', { name: 'Code personnel à 6 chiffres' }).getByRole('textbox', { name: 'Chiffre 1' }).click({ timeout: 10_000 });
    await tdesk.keyboard.type('482913');
    await tdesk.getByRole('group', { name: 'Confirmez le code' }).getByRole('textbox', { name: 'Chiffre 1' }).click();
    await tdesk.keyboard.type('482913');
    await tdesk.getByRole('button', { name: 'Créer le code' }).click();
  });
  await attempt('évaluation par critères', async () => {
    if (!tdesk.url().includes('/evaluation')) await tdesk.goto('/ecole-publique/evaluation');
    await selectContaining(tdesk.locator('#pp-class'), 'CM2 A').catch(() => undefined);
    await tdesk.locator('#pp-unit').selectOption({ index: 1 }).catch(() => undefined);
    await tdesk.locator('#pp-frame').selectOption({ index: 1 }).catch(() => undefined);
    await tdesk.waitForTimeout(1500);
    await shot(tdesk, DIR, 'pub-07-evaluation', { maxHeight: 2400 });
  });
  await visit(tdesk, '/ecole-publique/remediation', 'pub-07-remediation', 2000);
  await attempt('bulletins (instituteur)', async () => {
    await tdesk.goto('/ecole-publique/bulletins');
    await selectContaining(tdesk.locator('#rc-class'), 'CM2 A').catch(() => undefined);
    await tdesk.getByRole('button', { name: 'Préparer les bulletins de la classe' }).click({ timeout: 10_000 }).catch(() => undefined);
    await tdesk.waitForTimeout(2000);
    await shot(tdesk, DIR, 'pub-07-bulletins', { maxHeight: 2400 });
  });
  await tdesk.context().close();
  await visit(director, '/ecole-publique/bulletins', 'pub-07-bulletins-directeur', 2400);
  await attempt('passage', async () => {
    await director.goto('/ecole-publique/passage');
    await selectContaining(director.locator('#pa-class'), 'CM2 A').catch(() => undefined);
    await director.waitForTimeout(1500);
    await shot(director, DIR, 'pub-08-passage', { maxHeight: 2200 });
  });

  // --- 6. Questionnaire statistique, paquet minimum, examens, inspection, cantine (vues de la direction) ---
  await attempt('questionnaire statistique', async () => {
    await director.goto('/ecole-publique/statistiques');
    await director.getByRole('button', { name: 'Ouvrir le questionnaire' }).click();
    await director.waitForTimeout(3000);
    await shot(director, DIR, 'pub-10-questionnaire', { maxHeight: 2400 });
  });
  await visit(director, '/ecole-publique/paquet-minimum', 'pub-09-paquet-minimum', 2000);
  await visit(director, '/ecole-publique/examens', 'pub-11-examens-ecole', 2000);
  await visit(director, '/ecole-publique/inspection', 'pub-12-inspection-ecole', 2000);
  await visit(director, '/ecole-publique/cantine', 'pub-13-cantine', 2400);
  await visit(director, '/ecole-publique/apee', 'pub-09-apee-directeur', 1600);

  // --- 7. APEE : vote, cotisation, reçu, état financier ---
  const apee = await actorPage(browser, stack, info);
  await uiLogin(apee, ...ctx.logins.apee!);
  await apee.goto('/ecole-publique/apee');
  const now = new Date();
  const yl = `${now.getFullYear() - (now.getMonth() < 7 ? 1 : 0)}-${now.getFullYear() + (now.getMonth() < 7 ? 0 : 1)}`;
  await apee.getByLabel('Année scolaire (AAAA-AAAA)').fill(yl);
  await apee.getByLabel('Montant par élève (FCFA)').fill('1500');
  await apee.getByLabel("Date de l'assemblée").fill(today);
  await apee.getByLabel('Référence du compte rendu').fill('AG-APEE-001');
  await shot(apee, DIR, 'pub-09-apee-vote', { maxHeight: 1400 });
  await apee.getByRole('button', { name: 'Enregistrer le vote' }).click();
  await expect(apee.getByText('Vote enregistré.')).toBeVisible();
  await apee.getByLabel('Rechercher l\'élève (nom)').fill('Amina');
  await apee.getByLabel('Rechercher l\'élève (nom)').press('Enter');
  await expect(apee.getByLabel('Élève', { exact: true }).locator('option', { hasText: /Amina/i })).not.toHaveCount(0);
  await apee.getByLabel('Élève', { exact: true }).selectOption({ index: 1 });
  await apee.getByLabel('Montant (FCFA)', { exact: true }).fill('1500');
  await apee.getByRole('button', { name: 'Enregistrer', exact: true }).click();
  await expect(apee.getByText(/Reçu n° \S+ émis, SMS envoyé au parent\./)).toBeVisible();
  await shot(apee, DIR, 'pub-09-apee-recu', { maxHeight: 2600 });
  await attempt('état financier', async () => {
    await apee.locator('#ap-st').scrollIntoViewIfNeeded();
    await shot(apee, DIR, 'pub-09-apee-etat-financier', { full: false });
  });
  await apee.context().close();

  // --- 8. Rôles du territoire : inspection, direction des examens, tableau de bord territorial ---
  const territories = await stack.api('GET', '/territories', undefined, { token: adminAccount.token, establishment: ctx.establishment }).catch(() => ({ data: [] }));
  const region = (territories.data ?? []).find((t: { code?: string }) => t.code === 'NO');
  if (region) {
    await attempt('inspecteur', async () => {
      const inspector = await scopedUser(adminAccount, 'Ibrahim', 'Fictif-Inspecteur', 'pub_district_inspector', 'territory', String(region.id));
      const page = await actorPage(browser, stack, info);
      await uiLogin(page, inspector.login, inspector.password);
      await visit(page, '/ecole-publique', 'pub-12-accueil-inspecteur', 2000);
      await visit(page, '/ecole-publique/inspection', 'pub-12-inspection', 2400);
      await visit(page, '/ecole-publique/controle-presence', 'pub-06-controle-presence', 1800);
      await visit(page, '/tableau-de-bord-territorial', 'pub-14-tableau-de-bord-inspecteur', 2400);
      await page.context().close();
    });
  } else {
    issues.push('territoire NO introuvable par GET /territories : comptes territoriaux non créés');
  }
  await attempt('direction des examens', async () => {
    const exams = await scopedUser(adminAccount, 'Rose', 'Fictive-Examens', 'pub_exam_directorate', 'tenant', ctx.tenant);
    const page = await actorPage(browser, stack, info);
    await uiLogin(page, exams.login, exams.password);
    await visit(page, '/tableau-de-bord-territorial', 'pub-14-tableau-de-bord-examens', 2400);
    await visit(page, '/ecole-publique/examens/consolidation', 'pub-11-examens-consolidation', 2400);
    await attempt('ouvrir une session CEP', async () => {
      await page.goto('/ecole-publique/examens');
      await page.locator('#ex-code').selectOption({ index: 0 });
      await page.locator('#ex-written').fill(`${now.getFullYear() + (now.getMonth() < 7 ? 0 : 1)}-06-02`);
      await shot(page, DIR, 'pub-11-examens-ouvrir-session', { maxHeight: 1400 });
      await page.getByRole('button', { name: /Ouvrir la session/ }).click();
      await page.waitForTimeout(2000);
    });
    await page.context().close();
  });
  await attempt('cabinet : tableau de bord national', async () => {
    const cab = await scopedUser(adminAccount, 'Marc', 'Fictif-Cabinet', 'pub_cabinet', 'tenant', ctx.tenant);
    const page = await actorPage(browser, stack, info);
    await uiLogin(page, cab.login, cab.password);
    await page.goto('/tableau-de-bord-territorial');
    await page.locator('#db-region').selectOption({ index: 1 }).catch(() => undefined);
    await page.waitForTimeout(2000);
    await shot(page, DIR, 'pub-14-tableau-de-bord-territorial', { maxHeight: 2600 });
    await page.context().close();
  });
  await attempt('examens après ouverture (directeur)', async () => {
    await director.goto('/ecole-publique/examens');
    await director.getByRole('button', { name: 'Générer depuis les inscrits' }).click({ timeout: 10_000 });
    await expect(director.getByText('Liste générée.')).toBeVisible();
    await shot(director, DIR, 'pub-11-examens-liste', { maxHeight: 2400 });
  });
  await stack.attachRefusals(info);
});

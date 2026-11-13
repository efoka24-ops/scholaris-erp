import { test, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { actorPage, closeActors, expect, isoDate, selectContaining, startStack, uiLogin, type Account, type Stack } from '../specs/stack';
import { join } from 'node:path';
import { attempt, issues, phonePage, PRIVATE_IMAGES as DIR, settle, shot, shots } from './primaire-common';

/**
 * Captures du guide du PRIMAIRE PRIVÉ, sur la pile réelle. Semis « primarySchool » (école fictive, CM2 A, trois élèves
 * fictifs, direction, maître, parent garant), comptes complémentaires créés par l'administrateur (secrétaire, comptable,
 * caissière, promoteur, surveillant), puis parcours rejoué à l'écran comme le parcours e2e 13, une capture par écran utile.
 * Un écran qui ne réagit pas comme prévu n'arrête pas les captures : l'écart est listé en fin de script (rapport G-PRI).
 */
test.describe.configure({ mode: 'serial' });

interface PrimarySchool {
  logins: Record<string, [string, string]>;
  establishment: string;
  year: string;
  people: Record<string, string>;
}

let stack: Stack;
let ctx: PrimarySchool;
test.beforeAll(async () => {
  test.setTimeout(600_000);
  stack = await startStack();
  ctx = await stack.seed<PrimarySchool>('primarySchool');
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

test('guide primaire privé : captures', async ({ browser }, info) => {
  const y = Number(isoDate().slice(0, 4)) - (Number(isoDate().slice(5, 7)) < 8 ? 1 : 0);
  // Comptes complémentaires, créés par l'administrateur (écran Utilisateurs) : ici par l'API pour aller vite.
  const [adminLogin, adminPassword] = ctx.logins.admin!;
  const adminAccount: Account = { login: adminLogin, password: adminPassword, token: (await stack.login(adminLogin, adminPassword)).token };
  const est = ctx.establishment;
  const secretary = await stack.user(adminAccount, est, 'Salamatou', 'Fictive-Secretaire', 'primary_secretaire');
  const accountant = await stack.user(adminAccount, est, 'Cora', 'Fictive-Comptable', 'comptable');
  const cashier = await stack.user(adminAccount, est, 'Cathy', 'Fictive-Caissiere', 'caissier');
  const promoter = await stack.user(adminAccount, est, 'Jean', 'Fictif-Promoteur', 'primary_promoteur');
  const supervisor = await stack.user(adminAccount, est, 'Ali', 'Fictif-Surveillant', 'primary_surveillant');
  // Catalogue des frais et échéancier de l'année (tenus par la comptable) : inscription et scolarité en trois tranches.
  let familyReference = '';
  await attempt('catalogue des frais (API comptable)', async () => {
    const opts = { token: accountant.token, establishment: est };
    const item = await stack.api('POST', '/fee-items', { kind: 'tuition', name: 'Scolarité CM2', amount_xaf: 120000 }, { ...opts, expect: 201 });
    await stack.api('POST', '/fee-schedules', {
      fee_item_id: item.data.id, academic_year_id: ctx.year, amount_xaf: 120000,
      terms: [{ due_on: `${y}-10-15`, amount: 40000 }, { due_on: `${y + 1}-01-15`, amount: 40000 }, { due_on: `${y + 1}-04-15`, amount: 40000 }],
    }, { ...opts, expect: 201 });
    // Frais du service de cantine, avec un code (minuscules) : le forfait de service le choisit dans le catalogue (FIX-PRI 6).
    await stack.api('POST', '/fee-items', { kind: 'other', code: 'cantine', name: 'Cantine', amount_xaf: 15000 }, { ...opts, expect: 201 });
    const ref = await stack.api('POST', '/payment-references', { guarantor_id: ctx.people.parent }, { ...opts, expect: 201 });
    familyReference = String(ref.data.reference);
  });

  // --- Connexion et accueil ---
  let page = await actorPage(browser, stack, info);
  await page.goto('/connexion');
  await shot(page, DIR, 'pri-01-connexion', { full: false });
  await uiLogin(page, ...ctx.logins.director!);
  await shot(page, DIR, 'pri-01-accueil-general', { full: false });

  // --- Accueil de l'application et démarrage ---
  await page.goto('/primaire');
  await expect(page.getByRole('heading', { name: 'Mon école primaire', level: 1 })).toBeVisible();
  const start = page.getByRole('region', { name: 'Démarrage' });
  const apply = start.getByRole('button', { name: 'Appliquer les réglages recommandés' });
  const ready = start.getByText('Votre école est prête');
  await expect(ready.or(apply)).toBeVisible();
  if (await apply.isVisible()) {
    await shot(page, DIR, 'pri-02-demarrage', { maxHeight: 900 });
    await apply.click();
  }
  await expect(ready).toBeVisible();
  await shot(page, DIR, 'pri-02-accueil-application');

  // --- Paramétrage : niveaux, référentiel ---
  // Le CM2 est le niveau du CEP : la direction coche « niveau d'examen » (sinon la session d'examen est refusée, FIX-PRI 5).
  await attempt("niveaux : niveau d'examen", async () => {
    await page.goto('/primaire/niveaux');
    const exam = page.getByRole('listitem').filter({ hasText: 'CM2' }).getByRole('checkbox', { name: /niveau d’examen/i });
    if (!(await exam.isChecked())) await exam.check();
    await expect(exam).toBeChecked();
    await shot(page, DIR, 'pri-03-niveaux');
  });
  await page.goto('/primaire/referentiel');
  await page.locator('#dm-code').fill('LANG');
  await page.locator('#dm-fr').fill('Langue et communication');
  await page.getByRole('button', { name: 'Ajouter le domaine' }).click();
  await expect(page.locator('#cp-dom option').filter({ hasText: 'Langue et communication' })).toHaveCount(1);
  await selectContaining(page.locator('#rf-level'), 'CM2');
  for (const [code, label] of [['LANG-1', 'Lire un texte court'], ['LANG-2', 'Écrire une phrase simple']] as const) {
    await page.locator('#cp-code').fill(code);
    await page.locator('#cp-fr').fill(label);
    await selectContaining(page.locator('#cp-dom'), 'Langue et communication');
    await page.getByRole('button', { name: 'Ajouter la compétence' }).click();
    await expect(page.getByText(label)).toBeVisible();
  }
  await shot(page, DIR, 'pri-03-referentiel', { maxHeight: 2400 });

  // --- Administration de l'établissement (promoteur / administrateur) ---
  const admin = await actorPage(browser, stack, info);
  await uiLogin(admin, ...ctx.logins.admin!);
  await visit(admin, '/administration', 'pri-03-administration');
  await visit(admin, '/administration/annees', 'pri-03-annees-periodes');
  await visit(admin, '/administration/structures', 'pri-03-structures-classes');
  await visit(admin, '/administration/autorisations', 'pri-03-autorisations');
  await visit(admin, '/administration/utilisateurs', 'pri-03-utilisateurs');
  await visit(admin, '/administration/parametres', 'pri-03-parametres');
  await admin.context().close();

  // --- Secrétariat : nouvelle inscription avec garant financier, dossier, facture d'inscription, documents ---
  const sec = await actorPage(browser, stack, info);
  await uiLogin(sec, secretary.login, secretary.password);
  await shot(sec, DIR, 'pri-12-accueil-secretaire', { full: false });
  await attempt('assistant d\'inscription', async () => {
    await sec.goto('/inscription');
    await sec.locator('#en-last').fill('Fictif-CM2');
    await sec.locator('#en-first').fill('Daouda');
    await sec.locator('#en-birth').fill(`${y - 10}-03-14`);
    await sec.locator('#en-place').fill('Garoua');
    await sec.locator('#en-gender').selectOption({ index: 2 });
    await sec.locator('#en-nat').fill('Camerounaise');
    await shot(sec, DIR, 'pri-04-inscription-etape1');
    await sec.getByRole('button', { name: /Continuer : parents/ }).click();
    await sec.locator('#rl-last').fill('Fictif-CM2');
    await sec.locator('#rl-first').fill('Hamidou');
    await sec.locator('#rl-phone').fill(stack.phone());
    await sec.locator('#rl-kind').selectOption({ label: 'Garant financier' });
    await shot(sec, DIR, 'pri-04-inscription-etape2');
    await sec.getByRole('button', { name: 'Ajouter cette personne' }).click();
    await expect(sec.getByText(/Hamidou/).first()).toBeVisible();
    await sec.getByRole('button', { name: /Continuer : filière/ }).click();
    await sec.locator('#tr-year').selectOption({ index: 1 });
    await selectContaining(sec.locator('#tr-structure'), 'CM2 A');
    await shot(sec, DIR, 'pri-04-inscription-etape3');
    await sec.getByRole('button', { name: /Continuer : pièces/ }).click();
    await shot(sec, DIR, 'pri-04-inscription-etape4');
    await sec.getByRole('button', { name: /Continuer : frais/ }).click();
    await shot(sec, DIR, 'pri-04-inscription-etape5');
    await sec.getByRole('button', { name: /Valider l'inscription|Valider sans paiement/ }).click();
    await expect(sec.getByText(/Inscription validée|Inscription sous réserve/)).toBeVisible();
    await shot(sec, DIR, 'pri-04-inscription-validee', { full: false });
  });
  await attempt('guichet des inscriptions', async () => {
    await sec.goto('/primaire/inscriptions');
    await shot(sec, DIR, 'pri-04-guichet', { maxHeight: 1200 });
    await sec.locator('#dk-q').fill('Amina');
    await sec.getByRole('button', { name: 'Ouvrir' }).first().click();
    await shot(sec, DIR, 'pri-04-guichet-eleve');
    await sec.getByRole('button', { name: 'Facturer l’inscription' }).click();
    await expect(sec.getByText(/Facture émise/)).toBeVisible();
    await sec.getByRole('button', { name: 'Certificat de scolarité' }).click();
    await expect(sec.getByRole('link', { name: 'Vérifier' }).first()).toBeVisible();
    await shot(sec, DIR, 'pri-04-guichet-facture-documents');
  });
  await sec.context().close();
  // Fiche de l'élève et ses garants (le secrétariat ouvre aussi « Personnes et inscriptions » depuis FIX-PRI 3).
  const adm2 = await actorPage(browser, stack, info);
  await uiLogin(adm2, ...ctx.logins.admin!);
  await visit(adm2, '/personnes', 'pri-04-personnes', 1400);
  // Fiche de Daouda, inscrit « sous réserve des pièces » au guichet : états d'inscription et de pièces traduits (FIX-AFF).
  await attempt('dossier de l\'élève (garants)', async () => {
    await adm2.goto('/personnes');
    await adm2.getByRole('row', { name: /Daouda/ }).getByRole('link', { name: 'Ouvrir' }).first().click();
    await expect(adm2.getByText(/Sous réserve|Active|Actif/).first()).toBeVisible({ timeout: 20_000 });
    await shot(adm2, DIR, 'pri-04-fiche-eleve', { maxHeight: 2000 });
  });
  // GAR-FORM : un oncle (fictif) devient garant financier de Daouda à la place de Hamidou, depuis la fiche de l'élève.
  await attempt('fiche de l\'élève : changement de garant', async () => {
    const family = adm2.locator('section[aria-labelledby="fam-title"]');
    await family.locator('#rel-kind').selectOption('financial_guarantor');
    await family.locator('[data-test="mode-new"]').check();
    await family.locator('#rel-last').fill('Fictif-Oncle');
    await family.locator('#rel-first').fill('Oumarou');
    await family.locator('#rel-phone').fill(stack.phone());
    await family.locator('[data-test="add-replace"]').check();
    await settle(adm2);
    await family.screenshot({ path: join(DIR, 'pri-04-fiche-garant-ajout.png'), animations: 'disabled' });
    shots.push('pri-04-fiche-garant-ajout — section Parents et garants');
    adm2.once('dialog', (d) => void d.accept());
    await family.locator('[data-test="add-submit"]').click();
    await expect(family.getByText('Lien enregistré.')).toBeVisible({ timeout: 20_000 });
    await expect(family.getByRole('link', { name: /Oumarou/ })).toBeVisible();
    await settle(adm2);
    await family.screenshot({ path: join(DIR, 'pri-04-fiche-garant-remplace.png'), animations: 'disabled' });
    shots.push('pri-04-fiche-garant-remplace — section Parents et garants');
  });
  await adm2.context().close();

  // --- Frais et encaissements : comptable, puis caissière ---
  const acc = await actorPage(browser, stack, info);
  await uiLogin(acc, accountant.login, accountant.password);
  // Factures de l'année pour les élèves inscrits (établies par la comptable) : la liste des factures n'est pas vide.
  await attempt('factures des élèves (API comptable)', async () => {
    const opts = { token: accountant.token, establishment: est };
    const enr = await stack.api('GET', '/enrollments?filter[status]=active&per_page=50', undefined, opts);
    for (const e of (enr.data ?? []).slice(0, 3)) await stack.api('POST', '/invoices', { enrollment_id: e.id }, opts).catch(() => undefined);
  });
  await attempt('factures (comptable)', async () => {
    await acc.goto('/facturation');
    await acc.getByRole('tab', { name: 'Factures' }).or(acc.getByRole('button', { name: 'Factures' })).first().click();
    await shot(acc, DIR, 'pri-05-factures');
  });
  await acc.context().close();
  const cash = await actorPage(browser, stack, info);
  await uiLogin(cash, cashier.login, cashier.password);
  await attempt('caisse : ouverture et encaissement', async () => {
    await cash.goto('/facturation');
    await cash.getByRole('tab', { name: 'Caisse' }).or(cash.getByRole('button', { name: 'Caisse', exact: true })).first().click();
    await shot(cash, DIR, 'pri-05-caisse-fermee');
    const openBtn = cash.getByRole('button', { name: 'Ouvrir la caisse' });
    const float = cash.getByLabel('Fonds de caisse');
    if (await float.isVisible().catch(() => false)) await float.fill('20000');
    await openBtn.click();
    await expect(cash.getByText(/Caisse n°/)).toBeVisible();
    await cash.getByLabel('Référence de paiement famille').fill(familyReference);
    await cash.getByLabel('Montant en FCFA').fill('40000');
    await shot(cash, DIR, 'pri-05-caisse-encaisser');
    await cash.getByRole('button', { name: 'Encaisser et émettre le reçu' }).click();
    await expect(cash.getByText(/Encaissement enregistré/)).toBeVisible();
    await shot(cash, DIR, 'pri-05-caisse-recu');
  });
  await attempt('journal du jour et reçus', async () => {
    await cash.goto('/facturation');
    await shot(cash, DIR, 'pri-05-journal-du-jour');
    await cash.getByRole('tab', { name: 'Reçus' }).or(cash.getByRole('button', { name: 'Reçus', exact: true })).first().click();
    await shot(cash, DIR, 'pri-05-recus');
  });
  await cash.context().close();

  // --- Appel par demi-journée : direction au format téléphone, hors ligne puis envoi ---
  const callPhone = await phonePage(browser, stack, info);
  await uiLogin(callPhone, ...ctx.logins.teacher!);
  await attempt('appel hors ligne (téléphone)', async () => {
    await callPhone.goto('/primaire/appel');
    // Liste vide constatée (G-PRI) : la capture est prise quand même, l'écart est noté.
    await shot(callPhone, DIR, 'pri-06-appel-telephone', { maxHeight: 1400 });
    await expect(callPhone.getByRole('heading', { name: /Élèves \([1-9]/ })).toBeVisible({ timeout: 15_000 });
    await callPhone.context().setOffline(true);
    await callPhone.getByRole('button', { name: 'Tous présents' }).click();
    await callPhone.getByRole('group', { name: /Bello/ }).getByRole('button', { name: 'A' }).click();
    await shot(callPhone, DIR, 'pri-06-appel-telephone', { maxHeight: 1400 });
    await callPhone.getByRole('button', { name: /Valider l’appel/ }).click();
    await expect(callPhone.getByText(/gardé sur ce téléphone/)).toBeVisible();
    await shot(callPhone, DIR, 'pri-06-appel-hors-ligne', { maxHeight: 1400 });
    await callPhone.context().setOffline(false);
    await visitSync(callPhone);
  });
  await callPhone.context().close();

  // --- Maître : accueil, appel, feuille de compétences (import CSV), conduite, cahier de liaison, remédiation ---
  await page.context().close();
  page = await actorPage(browser, stack, info);
  await uiLogin(page, ...ctx.logins.teacher!);
  await page.goto('/primaire');
  await shot(page, DIR, 'pri-12-accueil-maitre');
  await attempt('appel du maître', async () => {
    await page.goto('/primaire/appel');
    await shot(page, DIR, 'pri-06-appel-maitre', { maxHeight: 1200 });
    await expect(page.getByRole('heading', { name: /Élèves \([1-9]/ })).toBeVisible({ timeout: 15_000 });
  });
  await attempt('appel général (menu Appel)', async () => {
    await page.goto('/appel');
    await shot(page, DIR, 'pri-06-appel-menu-general', { maxHeight: 1400 });
  });
  await page.goto('/primaire/competences');
  await selectContaining(page.locator('#sh-class'), 'CM2 A');
  await selectContaining(page.locator('#sh-period'), 'Trimestre 1');
  await shot(page, DIR, 'pri-07-feuille-vide');
  const panel = page.locator('section').filter({ has: page.getByRole('heading', { name: 'Importer la feuille (fichier CSV)' }) });
  const [download] = await Promise.all([page.waitForEvent('download'), panel.getByRole('button', { name: 'Télécharger le modèle' }).click()]);
  const template = readFileSync((await download.path())!, 'utf8').replace(/^\s+/, '');
  const [header, ...rows] = template.split(/\r?\n/).filter((l) => l.trim() !== '');
  const columns = header!.split(';');
  const filled = [header!, ...rows.map((r) => {
    const cells = r.split(';');
    return columns.map((_, i) => (i < 2 ? cells[i] ?? '' : 'acquired')).join(';');
  })].join('\r\n');
  await panel.locator('input[type="file"]').setInputFiles({ name: 'feuille-cm2.csv', mimeType: 'text/csv', buffer: Buffer.from(filled, 'utf8') });
  await panel.scrollIntoViewIfNeeded();
  await shot(page, DIR, 'pri-07-import-csv', { full: false });
  await panel.getByRole('button', { name: 'Importer' }).click();
  await expect.poll(() => page.getByRole('button', { name: 'Acquis', pressed: true }).count(), { timeout: 45_000 }).toBeGreaterThanOrEqual(6);
  await page.evaluate(() => window.scrollTo(0, 0));
  await shot(page, DIR, 'pri-07-feuille-remplie');
  await attempt('conduite', async () => {
    await page.goto('/primaire/conduite');
    await selectContaining(page.locator('#cd-class'), 'CM2 A');
    await selectContaining(page.locator('#cd-period'), 'Trimestre 1');
    await page.locator('ul button, li button').first().click();
    await shot(page, DIR, 'pri-07-conduite');
    await page.getByRole('button', { name: 'Enregistrer la conduite' }).click();
    await expect(page.getByText('Conduite enregistrée.')).toBeVisible();
  });
  await attempt('cahier de liaison', async () => {
    await page.goto('/primaire/cahier');
    await selectContaining(page.locator('#jr-class'), 'CM2 A');
    await page.locator('#jr-kind').selectOption({ label: 'Devoir' });
    await page.locator('#jr-date').fill(isoDate());
    await page.locator('#jr-title').fill('Lecture : texte page 24');
    await page.locator('#jr-body').fill('Lire le texte de la page 24 et répondre aux trois questions.');
    await page.getByRole('button', { name: 'Enregistrer en brouillon' }).click();
    await page.getByRole('button', { name: 'Publier aux familles' }).first().click();
    await expect(page.getByText('Publiée').first()).toBeVisible();
    await shot(page, DIR, 'pri-07-cahier-liaison');
  });
  await attempt('remédiation : séance', async () => {
    await page.goto('/primaire/remediation');
    await selectContaining(page.locator('#rm-class'), 'CM2 A');
    await page.locator('#rm-date').fill(isoDate());
    await page.locator('#rm-dur').fill('60');
    await page.locator('#rm-form').selectOption({ index: 1 });
    await page.locator('#rm-subj').fill('Lecture').catch(async () => page.locator('#rm-subj').selectOption({ index: 1 }));
    await page.locator('#rm-obj').fill('Lire une phrase sans hésiter');
    await page.locator('#rm-register input[type="checkbox"]').first().check().catch(() => page.locator('input[type="checkbox"]').first().check());
    await shot(page, DIR, 'pri-08-remediation-saisie', { maxHeight: 2000 });
    await page.getByRole('button', { name: 'Enregistrer la séance' }).click();
    await expect(page.getByText('Séance enregistrée.')).toBeVisible();
    await shot(page, DIR, 'pri-08-remediation', { maxHeight: 2000 });
  });

  // --- Direction : verrouillage, publication, conseil, bulletins ---
  await page.context().close();
  page = await actorPage(browser, stack, info);
  await uiLogin(page, ...ctx.logins.director!);
  await page.goto('/primaire/competences');
  await selectContaining(page.locator('#sh-class'), 'CM2 A');
  await selectContaining(page.locator('#sh-period'), 'Trimestre 1');
  await page.getByRole('button', { name: 'Verrouiller la feuille' }).click();
  await expect(page.getByText('Verrouillée')).toBeVisible();
  await page.getByRole('button', { name: 'Publier aux familles' }).click();
  await expect(page.getByText('Publiée aux familles')).toBeVisible();
  await shot(page, DIR, 'pri-07-feuille-publiee', { maxHeight: 1000 });
  await page.goto('/primaire/conseils');
  await selectContaining(page.locator('#co-class'), 'CM2 A');
  await selectContaining(page.locator('#co-term'), 'Trimestre 1');
  await shot(page, DIR, 'pri-08-conseil-ouvrir', { full: false });
  await page.getByRole('button', { name: 'Ouvrir le conseil' }).click();
  await expect(page.getByRole('heading', { name: /CM2 A ·/ })).toBeVisible();
  await attempt('conseil : ligne d\'Amina', async () => {
    const line = page.locator('li, article, div.line, section').filter({ hasText: 'FICTIVE-CM2 Amina' }).last();
    await line.locator('select').first().selectOption({ index: 1 });
    await line.locator('textarea').first().fill('Bon trimestre. Élève attentive et appliquée.');
    await line.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByText(/Ligne de .*Amina.* enregistrée/)).toBeVisible();
  });
  await shot(page, DIR, 'pri-08-conseil-seance', { maxHeight: 1500 });
  await page.getByRole('button', { name: 'Valider le conseil' }).click();
  await expect(page.getByText('Validé').first()).toBeVisible();
  await shot(page, DIR, 'pri-08-conseil-valide', { maxHeight: 900 });
  await page.goto('/primaire/bulletins');
  await attempt('aperçu du bulletin', async () => {
    await page.getByRole('button', { name: 'Afficher l’aperçu' }).click();
    await shot(page, DIR, 'pri-09-bulletin-apercu', { maxHeight: 2400 });
  });
  await page.getByRole('button', { name: 'Émettre les bulletins' }).click();
  await expect(page.getByText(/\d+ émis/)).toBeVisible({ timeout: 120_000 });
  await shot(page, DIR, 'pri-09-bulletins-emis', { maxHeight: 1000 });
  await page.getByRole('button', { name: /Publier aux familles \(\d+\)/ }).click();
  await expect(page.getByText(/\d+ publié\(s\)/)).toBeVisible();
  await shot(page, DIR, 'pri-09-bulletins-publies', { maxHeight: 1000 });
  await attempt('rectificatif', async () => {
    await page.getByRole('listitem').filter({ hasText: 'Amina' }).getByRole('button', { name: 'Voir' }).click()
      .catch(() => page.getByRole('button', { name: 'Voir' }).nth(1).click());
    const rect = page.locator('section, form, div').filter({ has: page.getByRole('heading', { name: 'Rectificatif' }) }).last();
    await rect.locator('textarea').first().fill('Très bon trimestre. Élève attentive et appliquée.');
    await rect.getByLabel('Motif du rectificatif').fill('Appréciation complétée après le conseil.');
    await rect.scrollIntoViewIfNeeded();
    await shot(page, DIR, 'pri-09-rectificatif', { full: false });
    await rect.getByRole('button', { name: 'Émettre le rectificatif' }).click();
    await expect(page.getByText(/Rectificatif émis/)).toBeVisible();
  });
  await attempt('bulletin émis', async () => {
    await page.goto('/primaire/bulletins');
    await page.getByRole('button', { name: 'Voir' }).first().click();
    await shot(page, DIR, 'pri-09-bulletin-emis', { maxHeight: 2400 });
  });

  // --- Examen CEP : session, candidats, numéros, fiche ---
  await attempt('examen : session', async () => {
    await page.goto('/primaire/examen');
    await page.locator('#ex-centre').fill('Centre fictif de Garoua');
    await page.locator('#ex-sub').fill('Sous-centre fictif n° 1');
    await page.locator('#ex-closes').fill(`${y + 1}-03-31`);
    await page.locator('#ex-written').fill(`${y + 1}-06-02`);
    await shot(page, DIR, 'pri-10-examen-session', { full: false });
    await page.getByRole('button', { name: 'Créer la session' }).click();
    await expect(page.getByRole('button', { name: 'Inscrire les élèves du niveau' })).toBeVisible();
    await page.getByRole('button', { name: 'Inscrire les élèves du niveau' }).click();
    await expect(page.getByText(/candidat\(s\) inscrit\(s\)/)).toBeVisible();
    await page.getByRole('button', { name: 'Attribuer les numéros' }).click();
    await expect(page.getByText(/numéro\(s\) attribué\(s\)/)).toBeVisible();
    await shot(page, DIR, 'pri-10-examen-candidats', { maxHeight: 2200 });
    await page.getByRole('button', { name: 'Fiche' }).first().click();
    await shot(page, DIR, 'pri-10-examen-fiche', { maxHeight: 2400 });
  });

  // --- Pilotage : tableau de bord, exports, services, clôture ---
  await visit(page, '/primaire/tableau-de-bord', 'pri-11-tableau-de-bord', 2200);
  await visit(page, '/primaire/exports', 'pri-11-exports');
  await visit(page, '/primaire/sortie-securisee', 'pri-10-sortie-securisee');
  await attempt('clôture', async () => {
    await page.goto('/primaire/cloture');
    await expect(page.getByRole('heading', { name: 'Contrôles' })).toBeVisible();
    await shot(page, DIR, 'pri-11-cloture', { maxHeight: 2600 });
  });
  await page.context().close();

  const acc2 = await actorPage(browser, stack, info);
  await uiLogin(acc2, accountant.login, accountant.password);
  await attempt('services : forfait cantine', async () => {
    await acc2.goto('/primaire/services');
    await acc2.locator('#sv-kind').selectOption({ index: 0 });
    await acc2.locator('#sv-label').fill('Cantine du midi');
    await acc2.locator('#sv-fee').selectOption('cantine');
    await acc2.locator('#sv-cycle').selectOption({ label: 'Trimestre' }).catch(() => undefined);
    await acc2.locator('#sv-cap').fill('40');
    await shot(acc2, DIR, 'pri-10-services', { maxHeight: 1600 });
    await acc2.getByRole('button', { name: 'Créer le forfait' }).click();
    await expect(acc2.getByText('Forfait créé.')).toBeVisible();
    await acc2.getByRole('button', { name: 'Ouvrir' }).first().click();
    await shot(acc2, DIR, 'pri-10-services-forfait', { maxHeight: 2200 });
  });
  await acc2.context().close();

  const sup = await actorPage(browser, stack, info);
  await uiLogin(sup, supervisor.login, supervisor.password);
  await visit(sup, '/primaire/sortie-securisee', 'pri-10-sortie-surveillant');
  await sup.context().close();

  const pro = await actorPage(browser, stack, info);
  await uiLogin(pro, promoter.login, promoter.password);
  await visit(pro, '/primaire', 'pri-12-accueil-promoteur');
  await visit(pro, '/primaire/tableau-de-bord', 'pri-11-tableau-de-bord-promoteur', 1400);
  await pro.context().close();

  // --- Parent : portail au format téléphone ---
  const parent = await phonePage(browser, stack, info);
  await uiLogin(parent, ...ctx.logins.parent!);
  await shot(parent, DIR, 'pri-13-parent-accueil');
  await parent.goto('/primaire/famille');
  await expect(parent.getByRole('heading', { name: 'Bulletins', exact: true })).toBeVisible();
  await shot(parent, DIR, 'pri-13-parent-bulletins');
  await attempt('parent ouvre le bulletin', async () => {
    await parent.getByRole('button', { name: /Bulletin trimestriel · Trimestre 1/ }).first().click();
    await shot(parent, DIR, 'pri-13-parent-bulletin', { maxHeight: 3000 });
  });
  await visit(parent, '/portail/famille', 'pri-13-parent-portail');
  await parent.context().close();
  await stack.attachRefusals(info);
});

/** Retour du réseau : bouton d'envoi s'il existe, sinon l'envoi automatique ; capture de la file d'envoi. */
async function visitSync(page: Page): Promise<void> {
  await page.getByRole('button', { name: /Envoyer maintenant/ }).click({ timeout: 5_000 }).catch(() => undefined);
  await page.waitForTimeout(3_000);
  await page.goto('/synchronisation');
  await shot(page, DIR, 'pri-06-file-envoi', { maxHeight: 1400 });
}

import { test, type Browser, type Locator, type Page } from '@playwright/test';
import { randomBytes } from 'node:crypto';
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, freshTotp, isoDate, selectContaining, startStack, typeCode, type Json, type Stack } from '../specs/stack';

/**
 * Manuel de formation COMMERCIALE (MANUEL-COM) : captures du parcours de vente et de mise en service d'un client sur la pile
 * réelle locale (données fictives) : vitrine publique, catalogue et prix, demande d'accès, back office du super admin
 * (demandes, acceptation, fiche client, catalogue, fiche utilisateur et mot de passe provisoire), activation du premier
 * administrateur, premiers réglages (années et périodes, structures, autorisations, utilisateurs), rapports planifiés,
 * IPES (dont le modèle « formation-pro »), maternelle privée et groupe scolaire à plusieurs établissements.
 * Hors suite e2e (config guide/commercial.config.ts). Une étape qui échoue est consignée dans images/_problemes.json.
 *
 * Le catalogue local est aligné sur le catalogue public en service (lu le 20 novembre 2026) : toutes les applications
 * « disponibles » sauf la maternelle publique, « bientôt ».
 */
test.describe.configure({ mode: 'serial' });

const here = dirname(fileURLToPath(import.meta.url));
const OUT = process.env.GUIDE_OUT ?? join(here, '..', '..', '..', 'specs', '000-commercial', 'manuel-commercial', 'images');
mkdirSync(OUT, { recursive: true });

let stack: Stack;
const problems: Array<{ step: string; error: string }> = [];
const shots: string[] = [];
const contexts: Array<{ close(): Promise<void> }> = [];
let root: { login: string; password: string; secret: string; token: string };

test.beforeAll(async () => {
  test.setTimeout(600_000);
  stack = await startStack();
  stack.tool('sql', "UPDATE platform_applications SET status = 'available' WHERE app_key IN ('nursery', 'primary', 'public_primary', 'secondary')");
  stack.tool('sql', "UPDATE platform_applications SET status = 'coming_soon' WHERE app_key = 'public_nursery'");
  const created = stack.tool<{ login: string; password: string }>('platform-admin', stack.phone(), 'Paul', 'Fictif-Plateforme');
  const { token, secret } = await stack.login(created.login, created.password);
  root = { login: created.login, password: created.password, secret: secret!, token };
});
test.afterEach(async () => {
  for (const c of contexts.splice(0)) await c.close().catch(() => undefined);
});
test.afterAll(async () => {
  writeFileSync(join(OUT, '_problemes.json'), JSON.stringify({ problems, shots, refused: stack?.calls.filter((c) => c.status >= 400).map((c) => `${c.method}${c.override ? ' (' + c.override + ')' : ''} ${c.path} -> ${c.status}`) }, null, 2));
  await stack?.stop();
});

async function open(browser: Browser, width = 1280): Promise<Page> {
  const context = await browser.newContext({ baseURL: String(test.info().project.use.baseURL), viewport: { width, height: width < 600 ? 844 : 800 }, locale: 'fr-FR', timezoneId: 'Africa/Douala', serviceWorkers: 'block', deviceScaleFactor: 1, hasTouch: width < 600 });
  contexts.push(context);
  await stack.attach(context);
  return context.newPage();
}

async function login(page: Page, id: string, pw: string): Promise<void> {
  const field = page.getByLabel(/^(E-mail ou téléphone|Numéro de téléphone ou e-mail)$/);
  for (let attempt = 0; ; attempt++) {
    await page.goto('/connexion').catch(() => undefined);
    if (await field.waitFor({ state: 'visible', timeout: 15_000 }).then(() => true, () => false)) break;
    if (attempt >= 3) throw new Error('écran de connexion indisponible');
  }
  await field.fill(id);
  await page.getByLabel('Mot de passe', { exact: true }).fill(pw);
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page).not.toHaveURL(/\/connexion/);
}

async function platformPage(browser: Browser): Promise<Page> {
  const page = await open(browser);
  await page.goto('/connexion');
  await page.getByLabel(/^(E-mail ou téléphone|Numéro de téléphone ou e-mail)$/).fill(root.login);
  await page.getByLabel('Mot de passe', { exact: true }).fill(root.password);
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page).toHaveURL(/\/double-authentification/);
  await typeCode(page, await freshTotp(root.secret));
  await page.getByRole('button', { name: 'Valider' }).click({ timeout: 3_000 }).catch(() => undefined);
  await expect(page).toHaveURL(/\/platform/);
  return page;
}

async function settle(page: Page): Promise<void> {
  await page.waitForLoadState('networkidle', { timeout: 20_000 }).catch(() => undefined);
  await page.waitForTimeout(400);
}

async function shot(page: Page, name: string, opts: { el?: Locator; maxHeight?: number; full?: boolean } = {}): Promise<void> {
  await settle(page);
  const path = join(OUT, `${name}.png`);
  try {
    if (opts.el) {
      await opts.el.scrollIntoViewIfNeeded();
      await opts.el.screenshot({ path, animations: 'disabled', caret: 'hide' });
    } else {
      const vp = page.viewportSize()!;
      const h = await page.evaluate(() => document.documentElement.scrollHeight);
      const max = opts.maxHeight ?? (vp.width < 600 ? 1600 : 1500);
      const full = opts.full ?? true;
      await page.screenshot({ path, animations: 'disabled', caret: 'hide', fullPage: full, ...(full && h > max ? { clip: { x: 0, y: 0, width: vp.width, height: max } } : {}) });
    }
    shots.push(name);
  } catch (e) {
    problems.push({ step: `capture ${name}`, error: String(e).slice(0, 300) });
  }
}

async function soft(step: string, fn: () => Promise<void>): Promise<void> {
  try {
    await fn();
  } catch (e) {
    problems.push({ step, error: String(e).split('\n').slice(0, 4).join(' ').slice(0, 500) });
  }
}

const platformApi = async <T = Json>(method: string, path: string, body?: unknown): Promise<T> => {
  try {
    return await stack.api<T>(method, path, body, { token: root.token });
  } catch (e) {
    if (!/-> (401|403)/.test(String(e))) throw e;
    root.token = (await stack.login(root.login, root.password, root.secret)).token;
    return stack.api<T>(method, path, body, { token: root.token });
  }
};

const START_YEAR = Number(isoDate().slice(0, 4)) - (Number(isoDate().slice(5, 7)) >= 8 ? 0 : 1);

// ---------------------------------------------------------------------------------------------------------------------------
// 1. Vitrine, catalogue, demande d'accès, back office, activation, premiers réglages
// ---------------------------------------------------------------------------------------------------------------------------

test('parcours de vente et de création d\'un client', async ({ browser }) => {
  const institution = 'Lycée Fictif de Démonstration';
  const contactPhone = stack.phone();

  // Vitrine et catalogue publics.
  const visitor = await open(browser);
  await soft('vitrine', async () => {
    await visitor.goto('/decouvrir');
    await shot(visitor, 'com-01-vitrine', { maxHeight: 2200 });
  });
  await soft('catalogue public', async () => {
    await visitor.goto('/applications-disponibles');
    await expect(visitor.getByRole('heading', { level: 1 })).toBeVisible();
    await shot(visitor, 'com-02-catalogue-public', { maxHeight: 2600 });
  });
  await soft('catalogue public mobile', async () => {
    const phone = await open(browser, 390);
    await phone.goto('/applications-disponibles');
    await expect(phone.getByRole('heading', { level: 1 })).toBeVisible();
    await shot(phone, 'com-02-catalogue-telephone', { maxHeight: 1500 });
  });

  // Demande d'accès (secondaire : le type d'établissement est obligatoire).
  let tracking = '';
  await visitor.goto('/connexion');
  await shot(visitor, 'com-03-connexion');
  await visitor.getByRole('link', { name: "Demander l'accès" }).click();
  await expect(visitor.getByRole('heading', { name: "Demander l'accès", level: 1 })).toBeVisible();
  await visitor.getByLabel("Nom de l'établissement *").fill(institution);
  await visitor.getByLabel("Type d'établissement *").selectOption('secondary');
  await visitor.getByLabel('Ville *').fill('Garoua');
  await visitor.getByLabel('Région *').fill('Nord');
  await soft('autorisation demande', async () => visitor.getByLabel("Numéro d'autorisation ministérielle").fill('ARR-FICTIF-0001'));
  await visitor.getByLabel('Prénom *').fill('Awa');
  await visitor.getByLabel('Nom *', { exact: true }).fill('Fictive-Proviseure');
  await visitor.getByLabel('Adresse e-mail *').fill('direction@lycee-fictif.test');
  await visitor.getByLabel('Téléphone').fill(contactPhone);
  await soft('fonction', async () => visitor.getByLabel('Fonction').fill('Proviseure'));
  await visitor.getByText('Enseignement secondaire', { exact: true }).click();
  await expect(visitor.locator('input[type=radio]:checked')).toHaveCount(1);
  await soft('type secondaire', async () => visitor.getByLabel("Type d'établissement", { exact: true }).selectOption('leg'));
  await soft('message', async () => visitor.getByLabel('Message').fill('Environ 900 élèves, 30 classes. Démarrage souhaité au 2e trimestre.'));
  await shot(visitor, 'com-04-demande-acces', { maxHeight: 2600 });
  await visitor.getByRole('button', { name: 'Envoyer la demande' }).click();
  await expect(visitor.getByRole('heading', { name: 'Demande enregistrée' })).toBeVisible();
  tracking = await visitor.getByLabel('Votre numéro de suivi').inputValue();
  await shot(visitor, 'com-05-demande-enregistree');
  await soft('suivi', async () => {
    await visitor.goto(`/demande-acces/${tracking}`);
    await settle(visitor);
    await shot(visitor, 'com-06-suivi-demande');
  });

  // Back office du super admin.
  const platform = await platformPage(browser);
  await shot(platform, 'com-07-bo-tableau-de-bord', { maxHeight: 2000 });
  await platform.goto('/platform/demandes');
  await expect(platform.getByRole('link', { name: `Voir le détail de ${institution}` })).toBeVisible();
  await shot(platform, 'com-08-bo-demandes');
  await platform.getByRole('link', { name: `Voir le détail de ${institution}` }).click();
  await expect(platform.getByRole('heading', { name: institution, level: 1 })).toBeVisible();
  await platform.getByRole('button', { name: 'Accepter', exact: true }).click();
  await expect(platform.locator('[data-test="auto-send"]')).toBeChecked();
  await platform.getByLabel(/^Canal d.envoi$/).selectOption('sms');
  await shot(platform, 'com-10-bo-acceptation', { maxHeight: 2400 });
  await platform.getByRole('button', { name: "Confirmer l'acceptation" }).click();
  await expect(platform.getByText('Demande acceptée.')).toBeVisible();
  await shot(platform, 'com-11-bo-demande-acceptee', { maxHeight: 2200 });

  // Activation par le premier administrateur.
  const admin = await open(browser);
  const password = 'Demo-Admin-' + randomBytes(3).toString('hex') + '!';
  await admin.goto('/connexion');
  await admin.getByRole('button', { name: 'Activer mon compte par SMS' }).or(admin.getByRole('link', { name: 'Activer mon compte par SMS' })).click();
  await expect(admin.getByRole('heading', { name: 'Activation du compte' })).toBeVisible();
  await admin.getByLabel(/^(E-mail ou téléphone|Numéro de téléphone ou e-mail)$/).fill(contactPhone);
  await shot(admin, 'com-12-activation-identifiant');
  await admin.getByRole('button', { name: 'J\'ai déjà reçu un code' }).click();
  await expect(admin.getByText(/Entrez le code reçu par SMS ou par e-mail/)).toBeVisible();
  const code = stack.lastCode(contactPhone);
  await typeCode(admin, code!);
  await shot(admin, 'com-13-activation-code');
  await admin.getByRole('button', { name: 'Vérifier le code' }).click();
  await admin.getByLabel('Nouveau mot de passe').fill(password);
  await admin.getByLabel('Confirmez le mot de passe').fill(password);
  await shot(admin, 'com-14-activation-mot-de-passe');
  await admin.getByRole('button', { name: 'Enregistrer le mot de passe' }).click();
  await expect(admin.getByText('Votre mot de passe est enregistré. Vous pouvez vous connecter.')).toBeVisible();
  await login(admin, contactPhone, password);
  await shot(admin, 'com-15-admin-premiere-connexion');
  const adminToken = (await stack.login(contactPhone, password)).token;
  const establishments = await stack.api('GET', '/establishments', undefined, { token: adminToken });
  const establishment = String(establishments.data[0].id);
  const asAdmin = (method: string, path: string, body?: unknown, expectStatus?: number) => stack.api(method, path, body, { token: adminToken, establishment, ...(expectStatus ? { expect: expectStatus } : {}) });

  await soft('mes applications', async () => {
    await admin.goto('/applications');
    await settle(admin);
    await shot(admin, 'com-16-admin-mes-applications', { maxHeight: 2200 });
  });
  await soft('administration', async () => {
    await admin.goto('/administration');
    await settle(admin);
    await shot(admin, 'com-17-admin-administration', { maxHeight: 2000 });
  });

  // Années et périodes : l'année est créée à l'écran, les trimestres par l'API, puis l'écran de gestion est capturé.
  let yearId = '';
  await soft('année', async () => {
    await admin.goto('/administration/annees');
    await admin.locator('#y-label').fill(`${START_YEAR}-${START_YEAR + 1}`);
    await admin.locator('#y-start').fill(`${START_YEAR}-09-01`);
    await admin.locator('#y-end').fill(`${START_YEAR + 1}-06-30`);
    await shot(admin, 'com-18-annee-saisie');
    await admin.getByRole('button', { name: 'Créer l’année' }).click();
    await expect(admin.getByRole('cell', { name: `${START_YEAR}-${START_YEAR + 1}` })).toBeVisible();
    const years = await asAdmin('GET', '/academic-years');
    yearId = String(years.data[0].id);
    const ranges: Array<[number, string, string]> = [[1, `${START_YEAR}-09-01`, `${START_YEAR}-12-18`], [2, `${START_YEAR + 1}-01-04`, `${START_YEAR + 1}-03-26`], [3, `${START_YEAR + 1}-04-06`, `${START_YEAR + 1}-06-30`]];
    for (const [ordinal, from, to] of ranges) await asAdmin('POST', '/periods', { academic_year_id: yearId, kind: 'trimester', ordinal, starts_on: from, ends_on: to }, 201);
    await admin.reload();
    await admin.getByRole('button', { name: `Gérer ${START_YEAR}-${START_YEAR + 1}` }).or(admin.getByRole('button', { name: 'Gérer' }).first()).first().click();
    await settle(admin);
    await shot(admin, 'com-19-annees-periodes', { maxHeight: 2400 });
  });

  // Structure et autorisation d'ouverture, puis personnel.
  await soft('structure et autorisation', async () => {
    const cls = await asAdmin('POST', '/structures', { kind: 'class', name: 'Seconde C', capacity: 60 }, 201);
    await asAdmin('POST', '/authorizations', { structure_id: cls.data.id, reference: 'ARR-FICTIF-0001', authority: 'MINESEC', valid_from: `${START_YEAR - 1}-01-01`, valid_to: `${START_YEAR + 3}-12-31` }, 201);
    await admin.goto('/administration/structures');
    await settle(admin);
    await shot(admin, 'com-20-structures', { maxHeight: 2000 });
    await admin.goto('/administration/autorisations');
    await settle(admin);
    await shot(admin, 'com-21-autorisations', { maxHeight: 2000 });
  });
  let teacher: { login: string } | null = null;
  await soft('personnel', async () => {
    const adminAccount = { login: contactPhone, password, token: adminToken };
    teacher = await stack.user(adminAccount, establishment, 'Fatou', 'Fictive-Enseignante', 'enseignant');
    await stack.user(adminAccount, establishment, 'Ibrahim', 'Fictif-Caissier', 'sec_caissier').catch(() => undefined);
    await admin.goto('/administration/utilisateurs');
    await settle(admin);
    await shot(admin, 'com-22-utilisateurs', { maxHeight: 2200 });
  });

  // Rapports planifiés.
  await soft('rapport planifié', async () => {
    await admin.goto('/rapports');
    await settle(admin);
    const name = admin.getByLabel('Nom', { exact: true }).or(admin.locator('form input[type=text]').first()).first();
    await name.fill('Point hebdomadaire de la direction');
    await shot(admin, 'com-23-rapport-planifie-saisie', { maxHeight: 2400 });
    await admin.locator('form button[type=submit]').last().click();
    await settle(admin);
    const run = admin.locator('[data-action="run"]').first();
    if (await run.count()) {
      await run.click();
      await settle(admin);
    }
    await shot(admin, 'com-24-rapports-planifies', { maxHeight: 2400 });
  });

  // Back office : clients, fiche client, fiche utilisateur et mot de passe provisoire, catalogue.
  await soft('clients', async () => {
    await platform.goto('/platform/clients');
    await settle(platform);
    await shot(platform, 'com-25-bo-clients');
  });
  let tenant = '';
  await soft('fiche client', async () => {
    const clients = await platformApi('GET', '/platform/clients');
    tenant = String((clients.data as Array<{ id: string; name: string }>).find((c) => c.name === institution)?.id ?? '');
    await platform.goto(`/platform/clients/${tenant}`);
    await expect(platform.getByRole('heading', { name: institution }).first()).toBeVisible();
    await shot(platform, 'com-26-bo-fiche-client', { maxHeight: 3200 });
  });
  await soft('fiche utilisateur et mot de passe provisoire', async () => {
    await platform.goto(`/platform/clients/${tenant}`);
    const link = platform.getByRole('link', { name: 'Fatou Fictive-Enseignante' });
    await link.first().click();
    await settle(platform);
    await shot(platform, 'com-27-bo-fiche-utilisateur', { maxHeight: 2200 });
    await platform.getByRole('button', { name: 'Donner un mot de passe provisoire' }).click();
    await platform.locator('[data-test="pwd-give"]').click();
    await shot(platform, 'com-28-bo-mdp-provisoire-confirmation', { maxHeight: 2400 });
    await platform.locator('[data-test="pwd-confirm"]').click();
    await expect(platform.locator('[data-test="provisional-password"]')).toBeVisible();
    await shot(platform, 'com-29-bo-mdp-provisoire-donne', { maxHeight: 2600 });
  });
  await soft('catalogue back office', async () => {
    await platform.goto('/platform/catalogue');
    await settle(platform);
    await shot(platform, 'com-30-bo-catalogue-tarifs', { maxHeight: 2400 });
  });
  await soft('paiements back office', async () => {
    await platform.goto('/platform/paiements');
    await settle(platform);
    await shot(platform, 'com-31-bo-paiements');
  });
  void teacher;
});

// ---------------------------------------------------------------------------------------------------------------------------
// 2. IPES : écrans clés et modèle « formation-pro »
// ---------------------------------------------------------------------------------------------------------------------------

interface Ipes {
  tenant: string;
  establishment: string;
  jury: string;
  logins: Record<string, [string, string]>;
}

test('IPES et centre de formation professionnelle', async ({ browser }) => {
  const ctx = await stack.seed<Ipes>('ipes');
  const as = async (who: string, width = 1280): Promise<Page> => {
    const page = await open(browser, width);
    await login(page, ...ctx.logins[who]!);
    return page;
  };
  await soft('ipes direction', async () => {
    const dir = await as('dir');
    await dir.goto('/superieur');
    await settle(dir);
    await shot(dir, 'ipes-01-accueil-directeur', { maxHeight: 2000 });
    await dir.goto('/superieur/structure');
    await settle(dir);
    await shot(dir, 'ipes-02-structure', { maxHeight: 2200 });
    await dir.goto('/superieur/autorisations');
    await settle(dir);
    await shot(dir, 'ipes-03-autorisations-minesup', { maxHeight: 2000 });
    await dir.goto('/superieur/tableaux-de-bord');
    await settle(dir);
    await shot(dir, 'ipes-09-tableaux-de-bord', { maxHeight: 2200 });
  });
  await soft('ipes scolarité', async () => {
    const scol = await as('scol');
    await scol.goto('/superieur/inscriptions');
    await settle(scol);
    await shot(scol, 'ipes-04-inscriptions', { maxHeight: 2000 });
  });
  await soft('ipes comptable', async () => {
    const compta = await as('compta');
    await compta.goto('/superieur/comptes');
    await settle(compta);
    await shot(compta, 'ipes-05-comptes-scolarite', { maxHeight: 2000 });
    const link = compta.getByRole('link', { name: /Marie/ }).first();
    if (await link.count()) {
      await link.click();
      await settle(compta);
      await shot(compta, 'ipes-05b-compte-etudiant', { maxHeight: 2200 });
    }
  });
  await soft('ipes enseignant', async () => {
    const prof = await as('prof');
    await prof.goto('/superieur/notes');
    await settle(prof);
    await shot(prof, 'ipes-06-notes-enseignant', { maxHeight: 2000 });
  });
  await soft('ipes jury', async () => {
    const chef = await as('chef');
    await chef.goto(`/superieur/deliberation/jurys/${ctx.jury}/preparation`);
    await expect(chef.getByRole('heading', { name: 'Préparation de la délibération' })).toBeVisible();
    await chef.getByRole('button', { name: 'Lancer la simulation' }).click();
    await settle(chef);
    await shot(chef, 'ipes-07-simulation-jury', { maxHeight: 2200 });
  });
  await soft('ipes étudiant', async () => {
    const student = await as('student', 390);
    await student.goto('/superieur/mon-espace');
    await expect(student.getByRole('heading', { name: 'Mon espace étudiant' })).toBeVisible();
    await shot(student, 'ipes-08-espace-etudiant-telephone', { maxHeight: 1700 });
  });

  // Centre de formation professionnelle (modèle formation-pro de higher:pilot-setup).
  await soft('formation-pro', async () => {
    const phone = stack.phone();
    const created = stack.tool<{ tenant_id: string; establishment_id: string | null }>('client', 'higher', 'higher', phone, 'Aminatou', 'Fictive-Directrice', 'Centre Fictif de Formation Professionnelle');
    const pw = 'Demo-Cfp-' + randomBytes(3).toString('hex') + '!';
    await stack.activate(phone, pw);
    const token = (await stack.login(phone, pw)).token;
    const est = created.establishment_id ?? String((await stack.api('GET', '/establishments', undefined, { token })).data[0].id);
    const y = START_YEAR;
    const year = await stack.api('POST', '/academic-years', { label: `${y}-${y + 1}`, starts_on: `${y}-10-01`, ends_on: `${y + 1}-07-31` }, { token, establishment: est, expect: 201 });
    const run = stack.tool<{ code: number; output: string }>('artisan', 'higher:pilot-setup', created.tenant_id, 'formation-pro', `--establishment=${est}`, `--year=${String(year.data.id)}`);
    if (run.code !== 0) problems.push({ step: 'pilot-setup', error: run.output.slice(0, 500) });
    writeFileSync(join(OUT, '_pilot-setup-formation-pro.txt'), run.output);
    // Le super administrateur du client n'a pas de rôle IPES : la structure se lit avec un rôle de direction de l'institut.
    const director = await stack.user({ login: phone, password: pw, token }, est, 'Moussa', 'Fictif-DirecteurCentre', 'higher_dir_academique');
    const page = await open(browser);
    await login(page, director.login, director.password);
    await page.goto('/superieur/structure');
    await settle(page);
    await shot(page, 'cfp-01-structure-formation-pro', { maxHeight: 1600 });
    await page.getByRole('tab', { name: 'Filières' }).or(page.getByRole('button', { name: 'Filières', exact: true })).first().click();
    await settle(page);
    await shot(page, 'cfp-02-filieres-formation-pro', { maxHeight: 2600 });
  });
});

// ---------------------------------------------------------------------------------------------------------------------------
// 3. Maternelle privée : sections, inscription, sortie sécurisée
// ---------------------------------------------------------------------------------------------------------------------------

test('maternelle privée', async ({ browser }) => {
  const ctx = await stack.seed<{ establishment: string; logins: Record<string, [string, string]> }>('nurseryStaff');
  const director = await open(browser);
  await login(director, ...ctx.logins.director!);
  await soft('accueil maternelle', async () => {
    await director.goto('/maternelle-privee');
    await settle(director);
    await shot(director, 'mat-01-accueil-directrice', { maxHeight: 2000 });
  });
  await soft('section', async () => {
    await director.goto('/maternelle-privee/sections');
    await director.getByLabel('Nom de la section').fill('Petite section Soleil');
    await director.getByLabel('Effectif maximal (facultatif)').fill('25');
    await director.getByRole('button', { name: 'Créer la section' }).click();
    await expect(director.getByText('Petite section Soleil').first()).toBeVisible();
    await shot(director, 'mat-02-sections');
    const admin = await stack.login(...ctx.logins.admin!);
    const dir = await stack.login(...ctx.logins.director!);
    const sections = await stack.api('GET', '/nursery/sections', undefined, { token: dir.token, establishment: ctx.establishment });
    const section = (sections.data as Array<{ id: string; label: string }>).find((s) => s.label === 'Petite section Soleil')!;
    await stack.api('POST', '/authorizations', { structure_id: section.id, reference: 'ARR-MAT-FICTIF-001', authority: 'MINEDUB', valid_from: `${START_YEAR - 1}-01-01`, valid_to: `${START_YEAR + 3}-12-31` }, { token: admin.token, establishment: ctx.establishment, expect: 201 });
  });
  await soft('inscription', async () => {
    const desk = await open(browser);
    await login(desk, ...ctx.logins.secretary!);
    await desk.goto('/maternelle-privee/inscription');
    await desk.locator('#en-first').fill('Nadia');
    await desk.locator('#en-last').fill('Fictive-Soleil');
    await desk.getByLabel('Date de naissance').fill(`${START_YEAR - 3}-03-14`);
    await desk.getByLabel('Sexe').selectOption('female');
    await desk.getByLabel('Section', { exact: true }).selectOption({ label: 'Petite section Soleil' });
    await desk.locator('#en-pf').fill('Paul');
    await desk.locator('#en-pl').fill('Fictif-Soleil');
    await desk.getByLabel('Téléphone').fill(stack.phone());
    await shot(desk, 'mat-03-inscription', { maxHeight: 2400 });
    await desk.getByRole('button', { name: 'Inscrire l’enfant' }).click();
    await expect(desk.getByText('Enfant inscrit.')).toBeVisible();
  });
  await soft('autorisés', async () => {
    await director.goto('/maternelle-privee/autorises');
    await director.getByLabel('Section', { exact: true }).selectOption({ label: 'Petite section Soleil' });
    const child = director.getByLabel('Enfant', { exact: true });
    if (await child.count()) await selectContaining(child, 'Nadia');
    await director.getByLabel('Nom de la personne').fill('Martine Fictive-Tante');
    await director.getByLabel('Lien avec l’enfant').fill('Tante');
    await director.getByLabel('Téléphone').fill(stack.phone());
    await director.getByRole('button', { name: 'Ajouter', exact: true }).click();
    await expect(director.getByText('Martine Fictive-Tante')).toBeVisible();
    await shot(director, 'mat-04-personnes-autorisees', { maxHeight: 2200 });
  });
  await soft('sortie', async () => {
    const phone = await open(browser, 390);
    await login(phone, ...ctx.logins.educator!);
    await phone.goto('/maternelle-privee/sortie');
    await settle(phone);
    const section = phone.getByLabel('Section', { exact: true });
    if (await section.count()) await section.selectOption({ label: 'Petite section Soleil' }).catch(() => undefined);
    await settle(phone);
    await shot(phone, 'mat-05-sortie-securisee-telephone', { maxHeight: 1700 });
  });
});

// ---------------------------------------------------------------------------------------------------------------------------
// 4. Groupe scolaire : un client, plusieurs établissements et applications
// ---------------------------------------------------------------------------------------------------------------------------

test('groupe scolaire à plusieurs établissements', async ({ browser }) => {
  const phone = stack.phone();
  const created = stack.tool<{ tenant_id: string }>('client', 'primary', 'primary', phone, 'Jean', 'Fictif-Promoteur', 'Groupe Scolaire Fictif — École primaire');
  await soft('établissements et applications du groupe', async () => {
    await platformApi('POST', `/platform/clients/${created.tenant_id}/establishments`, { name: 'Groupe Scolaire Fictif — Maternelle', type: 'nursery' });
    await platformApi('POST', `/platform/clients/${created.tenant_id}/establishments`, { name: 'Groupe Scolaire Fictif — Collège', type: 'secondary' });
    await platformApi('POST', `/platform/clients/${created.tenant_id}/applications`, { app_key: 'nursery' });
    await platformApi('POST', `/platform/clients/${created.tenant_id}/applications`, { app_key: 'secondary' });
  });
  const pw = 'Demo-Groupe-' + randomBytes(3).toString('hex') + '!';
  await stack.activate(phone, pw);
  const owner = await open(browser);
  await login(owner, phone, pw);
  await shot(owner, 'grp-01-mes-etablissements', { maxHeight: 2000 });
  await soft('établissements admin', async () => {
    await owner.goto('/administration/etablissements');
    await settle(owner);
    await shot(owner, 'grp-02-administration-etablissements', { maxHeight: 2000 });
  });
  await soft('fiche client groupe', async () => {
    const platform = await platformPage(browser);
    await platform.goto(`/platform/clients/${created.tenant_id}`);
    await settle(platform);
    await shot(platform, 'grp-03-bo-fiche-client-groupe', { maxHeight: 3200 });
  });
});

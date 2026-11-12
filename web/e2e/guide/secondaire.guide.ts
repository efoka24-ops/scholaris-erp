import { test, type Browser, type Locator, type Page } from '@playwright/test';
import { createHmac } from 'node:crypto';
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, isoDate, selectContaining, startStack, type Json, type Stack } from '../specs/stack';

/**
 * Guide d'utilisation du SECONDAIRE (G-SEC) : rejoue le parcours de 17-secondaire.spec.ts sur la pile réelle locale (données
 * fictives) et prend les captures d'écran du guide. Hors suite e2e (config guide/secondaire.config.ts).
 * Une étape d'illustration qui échoue n'arrête pas le guide : elle est consignée dans images/_problemes.json.
 */
test.describe.configure({ mode: 'serial' });

const here = dirname(fileURLToPath(import.meta.url));
// GUIDE_OUT : dossier de captures de contrôle (rejeu sans toucher aux images du guide).
const OUT = process.env.GUIDE_OUT ?? join(here, '..', '..', '..', 'specs', '005-application-secondaire', 'guide', 'images');
mkdirSync(OUT, { recursive: true });

interface Leg {
  establishment: string;
  year: string;
  logins: Record<string, [string, string]>;
  tokens: Record<string, string>;
  people: Record<string, string>;
}

const WEBHOOK_SECRET = 'secret-webhook-e2e';
const START_YEAR = Number(isoDate().slice(0, 4)) - (Number(isoDate().slice(5, 7)) >= 8 ? 0 : 1);
const WEEKDAY = ((new Date(`${isoDate()}T12:00:00Z`).getUTCDay() + 6) % 7) + 1;

let stack: Stack;
let ctx: Leg;
const problems: Array<{ step: string; error: string }> = [];
const shots: string[] = [];
const contexts: Array<{ close(): Promise<void> }> = [];

test.beforeAll(async () => {
  test.setTimeout(600_000);
  stack = await startStack();
  ctx = await stack.seed<Leg>('secondaryLeg');
  const admin = { login: ctx.logins.admin![0], password: ctx.logins.admin![1], token: (await stack.login(...ctx.logins.admin!)).token };
  const extra: Array<[string, string, string, string]> = [
    ['surveillant', 'Oumarou', 'Fictif-Surveillant', 'sec_surveillant_general'],
    ['caissier', 'Halima', 'Fictive-Caissiere', 'sec_caissier'],
    ['infirmier', 'Daouda', 'Fictif-Infirmier', 'sec_infirmier'],
    ['pp', 'Martine', 'Fictive-ProfPrincipale', 'sec_professeur_principal'],
    ['orientation', 'Clarisse', 'Fictive-Conseillere', 'sec_conseiller_orientation'],
    ['services', 'Ibrahim', 'Fictif-Services', 'sec_responsable_services'],
    ['promoteur', 'Jean', 'Fictif-Promoteur', 'sec_promoteur'],
    ['travaux', 'Ernest', 'Fictif-ChefTravaux', 'sec_chef_travaux'],
  ];
  for (const [key, first, last, role] of extra) {
    try {
      const acc = await stack.user(admin, ctx.establishment, first, last, role);
      ctx.logins[key] = [acc.login, acc.password];
      ctx.tokens[key] = acc.token;
    } catch (e) {
      problems.push({ step: `compte ${role}`, error: String(e).slice(0, 400) });
    }
  }
});
test.afterEach(async () => {
  for (const c of contexts.splice(0)) await c.close().catch(() => undefined);
});
test.afterAll(async () => {
  writeFileSync(join(OUT, '_problemes.json'), JSON.stringify({ problems, shots, refused: stack?.calls.filter((c) => c.status >= 400).map((c) => `${c.method}${c.override ? ' (' + c.override + ')' : ''} ${c.path} -> ${c.status}`) }, null, 2));
  await stack?.stop();
});

/** Contexte de navigateur d'un acteur (1280 px ou 390 px). */
async function open(browser: Browser, width = 1280): Promise<Page> {
  const context = await browser.newContext({ baseURL: String(test.info().project.use.baseURL), viewport: { width, height: width < 600 ? 844 : 800 }, locale: 'fr-FR', timezoneId: 'Africa/Douala', serviceWorkers: 'block', deviceScaleFactor: 1, hasTouch: width < 600 });
  contexts.push(context);
  await stack.attach(context);
  return context.newPage();
}

async function login(page: Page, who: string, shotName?: string): Promise<void> {
  const [id, pw] = ctx.logins[who]!;
  const field = page.getByLabel(/^(E-mail ou téléphone|Numéro de téléphone ou e-mail)$/);
  for (let attempt = 0; ; attempt++) {
    await page.goto('/connexion').catch(() => undefined);
    if (await field.waitFor({ state: 'visible', timeout: 15_000 }).then(() => true, () => false)) break;
    if (attempt >= 3) throw new Error('écran de connexion indisponible');
  }
  await field.fill(id);
  await page.getByLabel('Mot de passe', { exact: true }).fill(pw);
  if (shotName) await shot(page, shotName);
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page).not.toHaveURL(/\/connexion/);
}

async function as(browser: Browser, who: string, width = 1280): Promise<Page> {
  const page = await open(browser, width);
  await login(page, who);
  return page;
}

async function settle(page: Page): Promise<void> {
  await page.waitForLoadState('networkidle', { timeout: 20_000 }).catch(() => undefined);
  await page.waitForTimeout(300);
}

/** Capture : page entière (bornée en hauteur) ou un élément. Le mot de passe saisi n'apparaît jamais (champ masqué). */
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

/** Étape d'illustration : une erreur est consignée sans arrêter le guide. */
async function soft(step: string, fn: () => Promise<void>): Promise<void> {
  try {
    await fn();
  } catch (e) {
    problems.push({ step, error: String(e).split('\n').slice(0, 4).join(' ').slice(0, 500) });
  }
}

async function apiAs<T = Json>(who: string, method: string, path: string, body?: unknown): Promise<T> {
  const opts = { token: ctx.tokens[who], establishment: ctx.establishment };
  try {
    return await stack.api<T>(method, path, body, opts);
  } catch (e) {
    if (!String(e).includes('-> 401')) throw e;
    ctx.tokens[who] = (await stack.login(...ctx.logins[who]!)).token;
    return stack.api<T>(method, path, body, { ...opts, token: ctx.tokens[who] });
  }
}

async function selectSeries(select: Locator, code: string): Promise<void> {
  const option = select.locator('option').filter({ hasText: new RegExp(`^${code} · `) }).first();
  await expect(option).toBeAttached();
  await select.selectOption((await option.getAttribute('value')) ?? '');
}

/**
 * Ouvre un écran et attend un repère ; si la pile locale répond par une erreur passagère (base SQLite occupée par un autre
 * serveur php -S : 500 « Une erreur interne est survenue »), recharge l'écran (3 essais au plus).
 */
async function gotoReady(page: Page, path: string, ready: Locator): Promise<void> {
  for (let attempt = 0; ; attempt++) {
    await page.goto(path);
    if (await ready.waitFor({ state: 'visible', timeout: 20_000 }).then(() => true, () => false)) return;
    if (attempt >= 2) await expect(ready).toBeVisible();
    problems.push({ step: `rechargement ${path}`, error: 'repère absent, écran rechargé' });
  }
}

/** Clique chaque onglet (nav.tabs) d'un écran et le capture. */
async function eachTab(page: Page, prefix: string, skip: RegExp[] = []): Promise<void> {
  const buttons = page.locator('main nav.tabs button, main nav[aria-label] > button');
  const n = await buttons.count();
  for (let i = 0; i < n; i++) {
    const b = buttons.nth(i);
    const label = ((await b.textContent()) ?? '').trim();
    if (skip.some((r) => r.test(label))) continue;
    const slug = label.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 30);
    await soft(`${prefix} onglet ${label}`, async () => {
      await b.click();
      await shot(page, `${prefix}-${String(i + 1).padStart(2, '0')}-${slug}`);
    });
  }
}

// ---------------------------------------------------------------------------------------------------------------------------
// 01 Premiers pas, 02 profil et paramétrage
// ---------------------------------------------------------------------------------------------------------------------------

test('01-02 : connexion, accueil, profil, paramétrage, autorisation, calendrier', async ({ browser }) => {
  let page = await open(browser);
  await page.goto('/connexion');
  await shot(page, '01-connexion');
  await soft('activation', async () => {
    await page.goto('/activation');
    await shot(page, '01-activation');
    await page.goto('/mot-de-passe-oublie');
    await shot(page, '01-mot-de-passe-oublie');
  });
  await login(page, 'chef', '01-connexion-remplie');
  await shot(page, '01-accueil-apres-connexion');
  await page.goto('/secondaire');
  await shot(page, '01-secondaire-non-ouvert');
  await soft('bandeau', async () => shot(page, '01-bandeau', { el: page.locator('header.topbar') }));

  // Assistant de profil : modules complémentaires cochés pour illustrer tous les écrans (sauf frais du privé).
  await page.goto('/secondaire/profil');
  await expect(page.getByRole('heading', { name: 'Confirmer le profil' })).toBeVisible();
  await shot(page, '02-profil-assistant', { maxHeight: 2600 });
  for (const label of ['Orientation', 'Examens officiels', 'Ateliers', 'Stages', 'Paie simplifiée', 'Internat', 'Cantine et transport', 'Bibliothèque', 'Infirmerie']) {
    await soft(`module ${label}`, async () => {
      const box = page.locator('ul.modules li').filter({ has: page.locator('strong', { hasText: new RegExp(`^${label}$`) }) }).locator('input[type=checkbox]');
      if ((await box.count()) && (await box.isEnabled()) && !(await box.isChecked())) await box.check();
    });
  }
  await page.getByRole('button', { name: 'Ouvrir l’application' }).click();
  await expect(page.getByRole('heading', { name: 'Historique du profil' })).toBeVisible();
  await shot(page, '02-profil-ouvert', { maxHeight: 2600 });
  await page.goto('/secondaire');
  await shot(page, '01-secondaire-accueil-chef');

  // Paramétrage : niveaux d'exemple, séries, classes.
  await page.goto('/secondaire/parametrage');
  await shot(page, '02-parametrage-vide');
  await page.getByRole('button', { name: "Charger les niveaux d'exemple" }).click();
  await expect(page.getByText(/Seconde|2nde/).first()).toBeVisible();
  await shot(page, '02-parametrage-niveaux', { maxHeight: 2200 });
  const tabs = page.getByRole('navigation', { name: 'Paramétrage' });
  await tabs.getByRole('button', { name: 'Séries et spécialités' }).click();
  await selectSeries(page.getByLabel('Série ou spécialité du catalogue'), 'C');
  await page.getByRole('button', { name: 'Ouvrir', exact: true }).click();
  await expect(page.getByText('Enregistré.')).toBeVisible();
  await soft('série A4', async () => {
    await selectSeries(page.getByLabel('Série ou spécialité du catalogue'), 'A4');
    await page.getByRole('button', { name: 'Ouvrir', exact: true }).click();
    await page.waitForTimeout(800);
  });
  await shot(page, '02-parametrage-series');
  await tabs.getByRole('button', { name: 'Classes' }).click();
  await page.getByLabel('Libellé').first().fill('2nde A');
  await selectContaining(page.getByLabel('Niveau'), 'Seconde');
  await selectSeries(page.getByLabel(/^Série ou spécialité/), 'C');
  await shot(page, '02-parametrage-classe-saisie');
  await page.getByRole('button', { name: /Ajouter|Créer/ }).first().click();
  await expect(page.getByText('2nde A').first()).toBeVisible();
  await soft('classe 6e A', async () => {
    await page.getByLabel('Libellé').first().fill('6e A');
    await selectContaining(page.getByLabel('Niveau'), 'Sixième');
    await page.getByRole('button', { name: /Ajouter|Créer/ }).first().click();
    await expect(page.getByText('6e A').first()).toBeVisible();
  });
  await shot(page, '02-parametrage-classes');
  await page.getByRole('navigation', { name: 'Paramétrage' }).getByRole('button', { name: 'Matières et coefficients' }).click();
  await page.locator('#sb-code').fill('MATH');
  await page.locator('#sb-label').fill('Mathématiques');
  await page.getByRole('button', { name: 'Ajouter la matière' }).click();
  await expect(page.getByText('MATH · Mathématiques')).toBeVisible();
  await soft('matière FRAN', async () => {
    await page.locator('#sb-code').fill('FRAN');
    await page.locator('#sb-label').fill('Français');
    await page.getByRole('button', { name: 'Ajouter la matière' }).click();
    await expect(page.getByText('FRAN · Français')).toBeVisible();
  });
  await selectContaining(page.locator('#cf-level'), 'Seconde');
  await selectContaining(page.locator('#cf-stream'), 'C');
  await selectContaining(page.locator('#cf-subject'), 'Mathématiques');
  await page.locator('#cf-coef').fill('5');
  await page.locator('#cf-hours').fill('6');
  await page.locator('#cf-reason').fill('Coefficient de l’établissement (exemple fictif)');
  await shot(page, '02-parametrage-coefficient-saisie', { maxHeight: 2200 });
  await page.getByRole('button', { name: 'Enregistrer la version' }).click();
  await expect(page.getByText('Nouvelle version enregistrée.')).toBeVisible();
  await soft('coef français', async () => {
    await selectContaining(page.locator('#cf-subject'), 'Français');
    await page.locator('#cf-coef').fill('3');
    await page.locator('#cf-hours').fill('4');
    await page.locator('#cf-reason').fill('Coefficient de l’établissement (exemple fictif)');
    await page.getByRole('button', { name: 'Enregistrer la version' }).click();
    await expect(page.getByText(/coef\. 3 · 4 h\/sem\./)).toBeVisible();
  });
  await shot(page, '02-parametrage-coefficients', { maxHeight: 2200 });
  await eachTab(page, '02-parametrage-onglet', [/^Niveaux$/, /^Séries/, /^Classes$/, /^Matières/]);

  // Autorisation d'ouverture (administrateur).
  page = await as(browser, 'admin');
  await page.goto('/administration/autorisations');
  await expect(page.getByRole('heading', { name: 'Autorisations d’ouverture', level: 1 })).toBeVisible();
  for (const cls of ['2nde A', '6e A']) {
    await soft(`autorisation ${cls}`, async () => {
      await selectContaining(page.getByLabel('Classe, section ou filière'), cls);
      await page.getByLabel('Autorité').fill('MINESEC');
      await page.getByLabel('Référence de l’acte').fill(`MINESEC-FICTIF-${START_YEAR}-${cls === '2nde A' ? '001' : '002'}`);
      await page.getByLabel('Valide du').fill(`${START_YEAR}-09-01`);
      await page.getByLabel('Valide jusqu’au').fill(`${START_YEAR + 1}-08-31`);
      if (cls === '2nde A') await shot(page, '02-autorisation-saisie');
      await page.getByRole('button', { name: 'Déclarer', exact: true }).click();
      await expect(page.getByText('Autorisation enregistrée.')).toBeVisible();
    });
  }
  await shot(page, '02-autorisations');

  // Calendrier : trimestre 1 et ses séquences.
  await page.goto('/administration/annees');
  const detail = Promise.all([page.waitForResponse((r) => r.url().includes('/periods')), page.waitForResponse((r) => r.url().includes('/closure-check'))]);
  await shot(page, '02-annees');
  await page.getByRole('button', { name: /Gérer l’année/ }).first().click();
  await detail;
  const periods = page.locator('form[aria-labelledby="p-new"]');
  const addPeriod = async (kind: string, ordinal: number, from: string, to: string, parent?: string): Promise<void> => {
    await periods.locator('#p-kind').selectOption({ label: kind });
    if (parent) await periods.locator('#p-parent').selectOption({ label: parent });
    await periods.locator('#p-ord').fill(String(ordinal));
    await periods.locator('#p-start').fill(from);
    await periods.locator('#p-end').fill(to);
    if (kind === 'Séquence' && ordinal === 2) await shot(page, '02-periode-saisie', { maxHeight: 2400 });
    await periods.getByRole('button', { name: 'Ajouter la période' }).click();
    await expect(periods.locator('#p-start')).toHaveValue('');
  };
  await addPeriod('Trimestre', 1, `${START_YEAR}-09-01`, `${START_YEAR}-12-20`);
  await addPeriod('Séquence', 1, `${START_YEAR}-09-01`, `${START_YEAR}-10-15`, 'Trimestre 1');
  await addPeriod('Séquence', 2, `${START_YEAR}-10-16`, `${START_YEAR}-12-20`, 'Trimestre 1');
  await expect(page.getByRole('row').filter({ hasText: 'Séquence' })).toHaveCount(2);
  await shot(page, '02-periodes', { maxHeight: 2600 });
  await soft('utilisateurs', async () => {
    await page.goto('/administration/utilisateurs');
    await shot(page, '02-utilisateurs');
  });
});

// ---------------------------------------------------------------------------------------------------------------------------
// 03 Inscriptions
// ---------------------------------------------------------------------------------------------------------------------------

test('03 : inscriptions, garant financier, matricule, préinscription, transferts, import, carte scolaire, reprise', async ({ browser }) => {
  const page = await as(browser, 'scol');
  await page.goto('/secondaire/inscriptions');
  await shot(page, '03-inscriptions-eleves-vide');
  const nav = page.getByRole('navigation', { name: 'Inscriptions' });
  await nav.getByRole('button', { name: 'Inscrire' }).click();
  await page.locator('#en-last').fill('Fictif-Lyceen');
  await page.locator('#en-first').fill('Issa');
  await page.locator('#en-birth').fill(`${new Date().getFullYear() - 15}-02-03`);
  await page.locator('#en-class').selectOption({ label: '2nde A' });
  const guardian = page.getByRole('group', { name: /Parent ou tuteur/ });
  await guardian.getByLabel('Nom', { exact: true }).fill('Fictive-Parent');
  await guardian.getByLabel('Prénoms').fill('Aissatou');
  await guardian.getByLabel('Téléphone').fill(ctx.logins.parent![0]);
  await expect(page.locator('#en-g-payer')).toBeChecked();
  await page.getByLabel(/Matricule national/).fill('LEG-FICTIF-0001');
  await shot(page, '03-inscrire-formulaire', { maxHeight: 2600 });
  await page.getByRole('button', { name: 'Inscrire', exact: true }).last().click();
  await expect(page.getByText('Élève inscrit.')).toBeVisible();
  await shot(page, '03-inscrire-confirme');
  // Deux autres élèves fictifs pour que listes et conseil aient du contenu.
  for (const [last, first, cls, mat] of [['Fictive-Lyceenne', 'Aminatou', '2nde A', 'LEG-FICTIF-0002'], ['Fictif-Collegien', 'Moussa', '6e A', '']] as const) {
    await soft(`inscription ${first}`, async () => {
      await nav.getByRole('button', { name: 'Inscrire' }).click();
      await page.locator('#en-last').fill(last);
      await page.locator('#en-first').fill(first);
      await page.locator('#en-birth').fill(`${new Date().getFullYear() - (cls === '6e A' ? 11 : 15)}-05-12`);
      await page.locator('#en-class').selectOption({ label: cls });
      const g = page.getByRole('group', { name: /Parent ou tuteur/ });
      await g.getByLabel('Nom', { exact: true }).fill(last + '-Parent');
      await g.getByLabel('Prénoms').fill('Hawa');
      await g.getByLabel('Téléphone').fill(stack.phone());
      if (mat) await page.getByLabel(/Matricule national/).fill(mat);
      await page.getByRole('button', { name: 'Inscrire', exact: true }).last().click();
      await expect(page.getByText('Élève inscrit.')).toBeVisible();
    });
  }
  await nav.getByRole('button', { name: 'Élèves' }).click();
  await shot(page, '03-eleves-liste');
  const row = page.getByRole('listitem').filter({ hasText: 'Fictif-Lyceen Issa' });
  await row.getByRole('button', { name: /Fictif-Lyceen Issa/ }).click();
  await expect(page.getByRole('heading', { name: 'Garants financiers' })).toBeVisible();
  await shot(page, '03-eleve-dossier', { maxHeight: 2600 });
  await eachTab(page, '03-inscriptions-onglet', [/^Élèves$/, /^Inscrire$/]);

  // Carte scolaire, documents et reprise.
  await page.goto('/secondaire/carte-scolaire');
  await shot(page, '03-carte-scolaire');
  await eachTab(page, '03-carte-scolaire-onglet');
  await page.goto('/secondaire/reprise');
  await shot(page, '03-reprise');
  await eachTab(page, '03-reprise-onglet');
  await page.goto('/secondaire/documents');
  await shot(page, '15-documents');
  await soft('document certificat', async () => {
    await selectContaining(page.locator('#sd-kind'), 'Certificat de scolarité');
    await page.waitForTimeout(500);
    await selectContaining(page.locator('#sd-class'), '2nde A');
    await page.waitForTimeout(800);
    await selectContaining(page.locator('#sd-pupil'), 'Fictif-Lyceen');
    await shot(page, '15-documents-saisie');
    await page.getByRole('button', { name: 'Émettre', exact: true }).click();
    await expect(page.getByText(/Document n° .* émis\./)).toBeVisible();
    await shot(page, '15-documents-emis');
  });
});

// ---------------------------------------------------------------------------------------------------------------------------
// 05 Emploi du temps, 04 Frais
// ---------------------------------------------------------------------------------------------------------------------------

test('05 : cycle des notes préréglé, affectation, créneau, emploi du temps, cahier de textes', async ({ browser }) => {
  let page = await as(browser, 'chef');
  await page.goto('/secondaire/notes');
  await page.getByRole('navigation', { name: 'Notes' }).getByRole('button', { name: 'Paramètres' }).click();
  await shot(page, '07-notes-parametres');
  await page.getByRole('button', { name: "Poser le préréglage d'exemple" }).click();
  await expect(page.getByRole('status').filter({ hasText: /Posés/ })).toBeVisible();
  await shot(page, '07-notes-parametres-poses');

  page = await as(browser, 'censeur');
  await page.goto('/secondaire/emploi-du-temps');
  await shot(page, '05-edt-accueil');
  await page.getByRole('navigation', { name: 'Emploi du temps' }).getByRole('button', { name: 'Affectations' }).click();
  await page.locator('#as-class').selectOption({ label: '2nde A' });
  await selectContaining(page.locator('#as-subject'), 'Mathématiques');
  await selectContaining(page.locator('#as-teacher'), 'Fictif-Professeur');
  await shot(page, '05-edt-affectation-saisie');
  await page.getByRole('button', { name: 'Affecter' }).click();
  await expect(page.locator('#po-as option').filter({ hasText: 'Mathématiques' })).toHaveCount(1);
  await selectContaining(page.locator('#po-as'), 'Mathématiques');
  await page.locator('#po-day').selectOption(String(WEEKDAY));
  await page.locator('#po-start').fill('07:30');
  await page.locator('#po-end').fill('08:30');
  await page.locator('#po-from').fill(`${START_YEAR}-09-01`);
  await shot(page, '05-edt-creneau-saisie', { maxHeight: 2400 });
  await page.getByRole('button', { name: 'Enregistrer', exact: true }).click();
  await page.waitForTimeout(800);
  await page.getByRole('navigation', { name: 'Emploi du temps' }).getByRole('button', { name: 'Emploi du temps', exact: true }).click();
  await page.locator('#tt-id').selectOption({ label: '2nde A' });
  await page.getByRole('button', { name: 'Afficher' }).click();
  await expect(page.getByText(/07:30/).first()).toBeVisible();
  await shot(page, '05-edt-classe');
  await eachTab(page, '05-edt-onglet', [/^Affectations$/, /^Emploi du temps$/]);
  await page.goto('/secondaire/cahier-de-textes');
  await shot(page, '05-cahier-censeur');
  await eachTab(page, '05-cahier-censeur-onglet');

  page = await as(browser, 'teacher');
  await page.goto('/secondaire/cahier-de-textes');
  await shot(page, '05-cahier-enseignant');
  await eachTab(page, '05-cahier-enseignant-onglet');
  await page.goto('/secondaire/espace-enseignant');
  await shot(page, '14-espace-enseignant');
});

test('04 : catalogue, demande d’ajout, frais, échéancier, facture, caisse, situation', async ({ browser }) => {
  let page = await as(browser, 'intendant');
  await gotoReady(page, '/secondaire/frais', page.getByRole('heading', { name: 'Catalogue des frais autorisés (public)' }));
  await shot(page, '04-frais-accueil', { maxHeight: 2400 });
  const itemForm = page.locator('form[aria-labelledby="sf-items-new"]');
  await itemForm.locator('#sf-i-kind').selectOption('other');
  await itemForm.locator('#sf-i-name').fill('Frais informatiques');
  await itemForm.locator('#sf-i-amount').fill('5000');
  await itemForm.getByRole('button', { name: 'Ajouter le frais' }).click();
  await expect(page.getByText(/Frais absent du catalogue des frais autorisés/).first()).toBeVisible();
  await shot(page, '04-frais-refus-hors-catalogue', { maxHeight: 2400 });

  page = await as(browser, 'fonctionnel');
  await page.goto('/secondaire/frais');
  await page.locator('#sf-e-kind').selectOption('apee');
  await page.locator('#sf-e-label').fill('Contribution APEE');
  await page.locator('#sf-e-max').fill('10000');
  await page.locator('#sf-e-basis').fill('Délibération fictive de l’APEE');
  await shot(page, '04-catalogue-saisie', { maxHeight: 2400 });
  await page.getByRole('button', { name: 'Ajouter au catalogue' }).click();
  await expect(page.getByRole('listitem').filter({ hasText: 'Contribution APEE' })).toBeVisible();
  await shot(page, '04-catalogue', { maxHeight: 2400 });

  page = await as(browser, 'intendant');
  await page.goto('/secondaire/frais');
  await page.locator('#sf-i-kind').selectOption('apee');
  await page.locator('#sf-i-name').fill('Contribution APEE');
  await page.locator('#sf-i-amount').fill('10000');
  await page.getByRole('button', { name: 'Ajouter le frais' }).click();
  await expect(page.getByText('Frais ajouté.')).toBeVisible();
  await page.locator('#sf-s-item').selectOption({ label: 'Contribution APEE' });
  await page.locator('#sf-s-class').selectOption({ label: '2nde A' });
  await expect(page.locator('#sf-s-amount')).toHaveValue('10000');
  await page.locator('#sf-s-due').fill(`${START_YEAR}-12-15`);
  const sched = page.locator('#sf-s-item').locator('xpath=ancestor::section[1]');
  await shot(page, '04-echeancier-saisie', { el: sched });
  await page.getByRole('button', { name: 'Créer l’échéancier' }).click();
  await expect(page.getByText('Échéancier créé.')).toBeVisible();
  await selectContaining(page.locator('#sf-v-student'), 'Fictif-Lyceen');
  await page.getByRole('button', { name: 'Établir la facture' }).click();
  await expect(page.getByRole('status').filter({ hasText: /Facture établie pour Fictif-Lyceen Issa/ })).toBeVisible();
  await shot(page, '04-facture-etablie', { el: sched });
  await soft('facture 2', async () => {
    await selectContaining(page.locator('#sf-v-student'), 'Fictive-Lyceenne');
    await page.getByRole('button', { name: 'Établir la facture' }).click();
    await expect(page.getByRole('status').filter({ hasText: /Facture établie pour Fictive-Lyceenne/ })).toBeVisible();
  });
  await shot(page, '04-frais-complet', { maxHeight: 3200 });
  await page.goto('/secondaire/situation-financiere');
  await shot(page, '04-situation-financiere');
  await eachTab(page, '04-situation-onglet');
  await soft('facturation socle', async () => {
    await page.goto('/facturation');
    await shot(page, '04-facturation-socle');
  });

  await soft('caissier', async () => {
    page = await as(browser, 'caissier');
    await page.goto('/secondaire');
    await shot(page, '04-caissier-accueil');
    await page.goto('/secondaire/frais');
    await shot(page, '04-caissier-frais', { maxHeight: 2400 });
    await page.goto('/facturation');
    await shot(page, '04-caissier-facturation');
    await page.getByRole('button', { name: 'Caisse', exact: true }).click().catch(() => page.getByText('Caisse', { exact: true }).click());
    await shot(page, '04-caisse-fermee');
    await page.locator('#b-float').fill('25000');
    await page.getByRole('button', { name: 'Ouvrir la caisse' }).click();
    await expect(page.locator('#b-amt')).toBeVisible();
    await shot(page, '04-caisse-ouverte');
    await page.getByText('Factures', { exact: true }).click();
    await shot(page, '04-caisse-factures');
  });
  // FIX-SEC : encaissement au guichet par recherche de l'élève (sans référence famille), depuis Secondaire > Frais.
  await soft('caisse par élève', async () => {
    await page.goto('/secondaire/frais');
    const card = page.locator('section[aria-labelledby="sf-cash"]');
    await expect(card).toBeVisible();
    await page.locator('#sf-cash-student-search').fill('Lyceenne');
    await selectContaining(page.locator('#sf-cash-student'), 'Fictive-Lyceenne');
    await shot(page, '04-caisse-recherche-eleve', { el: card });
    await card.getByRole('link', { name: 'Encaisser pour cet élève' }).click();
    await expect(page).toHaveURL(/facturation/);
    await expect(page.locator('#b-amt')).toBeVisible();
    await page.locator('#b-amt').fill('5000');
    await shot(page, '04-caisse-eleve');
    await page.getByRole('button', { name: 'Encaisser et émettre le reçu' }).click();
    await expect(page.getByText(/Encaissement enregistré/).first()).toBeVisible();
    await shot(page, '04-caisse-recu');
  });
});

// ---------------------------------------------------------------------------------------------------------------------------
// 06 Appel et discipline, 07 Notes
// ---------------------------------------------------------------------------------------------------------------------------

test('06-07 : appel (390 px), discipline, notes de séquence', async ({ browser }) => {
  let page = await as(browser, 'teacher', 390);
  if (WEEKDAY <= 6) {
    await page.goto('/appel');
    await shot(page, '06-appel-mobile-liste');
    await page.getByRole('button', { name: "Faire l'appel" }).first().click();
    await shot(page, '06-appel-mobile-feuille');
    await soft('appel absent', async () => {
      const absent = page.getByRole('button', { name: /^Absent/ }).nth(1);
      if (await absent.count()) await absent.click();
      await shot(page, '06-appel-mobile-absent');
    });
    await page.getByRole('button', { name: 'Tous présents' }).click();
    await page.getByRole('button', { name: /Valider l'appel/ }).click();
    await expect(page.getByText(/L'appel est enregistré/).first()).toBeVisible();
    await shot(page, '06-appel-mobile-valide');
  }

  page = await as(browser, 'teacher');
  await page.goto('/secondaire/notes');
  await shot(page, '07-notes-enseignant-accueil');
  await page.locator('#secg-class').selectOption({ label: '2nde A' });
  await page.locator('#secg-period').selectOption({ label: 'Séquence 1' });
  await expect(page.locator('#secg-subject')).toBeVisible();
  await page.locator('#na-title').fill('Devoir 1');
  await shot(page, '07-notes-evaluation-saisie');
  await page.getByRole('button', { name: 'Créer', exact: true }).click();
  const scores = page.locator('input.score');
  await scores.first().fill('14');
  await scores.first().blur();
  await expect(page.getByText('Enregistré.')).toBeVisible();
  await soft('note 2', async () => {
    if ((await scores.count()) > 1) {
      await scores.nth(1).fill('11.5');
      await scores.nth(1).blur();
      await page.waitForTimeout(800);
    }
  });
  await shot(page, '07-notes-saisie');
  await eachTab(page, '07-notes-enseignant-onglet', [/Saisie/]);
  await page.locator('nav.tabs button').first().click().catch(() => undefined);
  await page.locator('#secg-class').selectOption({ label: '2nde A' }).catch(() => undefined);
  await page.locator('#secg-period').selectOption({ label: 'Séquence 1' }).catch(() => undefined);
  await page.getByRole('button', { name: 'Soumettre au censeur' }).click();
  await expect(page.getByRole('button', { name: 'Soumettre au censeur' })).toHaveCount(0);
  await shot(page, '07-notes-soumises');

  page = await as(browser, 'censeur');
  await page.goto('/secondaire/notes');
  await page.locator('#secg-class').selectOption({ label: '2nde A' });
  await page.locator('#secg-period').selectOption({ label: 'Séquence 1' });
  await shot(page, '07-notes-censeur-a-valider');
  await page.getByRole('button', { name: 'Valider', exact: true }).click();
  await shot(page, '07-notes-censeur-validees');
  await page.getByRole('button', { name: 'Verrouiller' }).click();
  await expect(page.getByRole('button', { name: 'Publier aux familles' })).toBeVisible();
  await shot(page, '07-notes-verrouillees');
  await eachTab(page, '07-notes-censeur-onglet', [/Saisie/]);

  await soft('surveillant', async () => {
    page = await as(browser, 'surveillant');
    await page.goto('/secondaire');
    await shot(page, '06-surveillant-accueil');
    await page.goto('/secondaire/discipline');
    await shot(page, '06-discipline');
    await selectContaining(page.locator('#secd-class'), '2nde A');
    await page.getByRole('button', { name: 'Afficher' }).click();
    await page.waitForTimeout(1000);
    await shot(page, '06-discipline-heures');
    await page.goto('/secondaire/conseils-de-discipline');
    await shot(page, '06-conseils-discipline');
  });
});

// ---------------------------------------------------------------------------------------------------------------------------
// 08 Conseil de classe et bulletins, 09 Orientation
// ---------------------------------------------------------------------------------------------------------------------------

test('08-09 : règles, conseil de classe, bulletins, orientation', async ({ browser }) => {
  let page = await as(browser, 'chef');
  await page.goto('/secondaire/conseils-de-classe');
  await shot(page, '08-conseil-chef-accueil');
  const presetButton = page.getByRole('button', { name: 'Régler les types de bulletin' });
  await presetButton.click();
  await expect(presetButton).toHaveCount(0);

  page = await as(browser, 'censeur');
  await page.goto('/secondaire/conseils-de-classe');
  await expect(page.getByRole('radio', { name: 'Pour tout le niveau' })).toBeChecked();
  await shot(page, '08-conseil-regles');
  await page.getByRole('button', { name: 'Préparer le brouillon' }).click();
  await expect(page.getByRole('heading', { name: /Classe 2nde A : coefficients propres/ })).toBeVisible();
  await shot(page, '08-conseil-brouillon', { maxHeight: 2600 });
  await page.getByRole('button', { name: 'Publier les règles' }).click();
  await expect(page.getByText('Règles publiées.')).toBeVisible();
  await page.getByRole('button', { name: 'Ouvrir le conseil' }).click();
  await expect(page.getByRole('listitem').filter({ hasText: 'Fictif-Lyceen' }).first()).toContainText('14');
  await shot(page, '08-conseil-ouvert', { maxHeight: 2600 });
  await page.getByRole('button', { name: 'Valider les décisions' }).click();
  await page.waitForTimeout(800);
  await shot(page, '08-conseil-valide', { maxHeight: 2600 });
  await page.getByRole('button', { name: 'Émettre les bulletins' }).click();
  await expect(page.getByRole('heading', { name: 'Bulletins émis' })).toBeVisible({ timeout: 180_000 });
  await shot(page, '08-bulletins-emis', { maxHeight: 2600 });
  await soft('aperçu bulletin', async () => {
    const link = page.locator('main').getByRole('link', { name: 'Aperçu' }).first();
    await link.click();
    await expect(page).toHaveURL(/bulletins/);
    // FIX-SEC : l'aperçu est calculé à la demande ; attendre l'élève avant la capture.
    await expect(page.getByText('Élève :').first()).toBeVisible({ timeout: 60_000 });
    await shot(page, '08-bulletin-apercu', { maxHeight: 3000 });
  });
  await soft('bulletins écran', async () => {
    await page.goto('/secondaire/bulletins');
    await shot(page, '08-bulletins-ecran');
  });

  await soft('orientation', async () => {
    page = await as(browser, 'orientation');
    await page.goto('/secondaire/orientation');
    await shot(page, '09-orientation');
    await eachTab(page, '09-orientation-onglet');
  });
  await soft('professeur principal', async () => {
    page = await as(browser, 'pp');
    await page.goto('/secondaire');
    await shot(page, '16-pp-accueil');
    await page.goto('/secondaire/bulletins');
    await shot(page, '08-bulletins-pp');
  });
});

// ---------------------------------------------------------------------------------------------------------------------------
// 10 à 13 : examens, ateliers et stages, personnel et paie, services, tableaux de bord
// ---------------------------------------------------------------------------------------------------------------------------

test('10-13 : examens officiels, ateliers, stages, personnel, paie, services, tableaux de bord', async ({ browser }) => {
  let page = await as(browser, 'censeur');
  await soft('examens', async () => {
    await page.goto('/secondaire/examens');
    await shot(page, '10-examens');
    await eachTab(page, '10-examens-onglet');
  });
  await soft('ateliers', async () => {
    await page.goto('/secondaire/ateliers');
    await shot(page, '11-ateliers');
    await eachTab(page, '11-ateliers-onglet');
  });
  page = await as(browser, 'chef');
  await soft('dashboards chef', async () => {
    await page.goto('/secondaire/tableaux-de-bord');
    await shot(page, '13-tableaux-de-bord');
    await eachTab(page, '13-tableaux-de-bord-onglet');
  });
  await soft('stages', async () => {
    await page.goto('/secondaire/stages');
    await shot(page, '11-stages');
  });
  await soft('personnel chef', async () => {
    await page.goto('/secondaire/personnel');
    await shot(page, '12-personnel');
  });
  await soft('personnel saisie', async () => {
    await selectContaining(page.locator('#sst-member'), 'Fictif-Professeur');
    await page.waitForTimeout(500);
    await shot(page, '12-personnel-fiche', { el: page.locator('section[aria-labelledby="sst-profiles"]') });
  });
  // FIX-SEC : l'administrateur du client (droits Personnel du socle) ouvre Personnel et Paie.
  await soft('administrateur personnel', async () => {
    page = await as(browser, 'admin');
    await page.goto('/secondaire/personnel');
    await expect(page.locator('#sst-member')).toBeVisible();
    await shot(page, '12-personnel-administrateur', { maxHeight: 2000 });
    await page.goto('/secondaire/paie');
    await shot(page, '12-paie-administrateur', { maxHeight: 2000 });
  });
  page = await as(browser, 'fonctionnel');
  for (const [path, name] of [['/secondaire/paie', '12-paie'], ['/secondaire/services', '12-services']] as const) {
    await soft(name, async () => {
      await page.goto(path);
      await shot(page, name, { maxHeight: 2400 });
    });
  }
  await soft('chef des travaux', async () => {
    page = await as(browser, 'travaux');
    await page.goto('/secondaire');
    await shot(page, '11-chef-travaux-accueil');
    await page.goto('/secondaire/ateliers');
    await shot(page, '11-ateliers-chef-travaux', { maxHeight: 2400 });
    await page.goto('/secondaire/stages');
    await shot(page, '11-stages-chef-travaux', { maxHeight: 2400 });
  });
  await soft('intendant paie', async () => {
    page = await as(browser, 'intendant');
    await page.goto('/secondaire/paie');
    await shot(page, '12-paie-intendant');
    await page.goto('/secondaire/tableaux-de-bord');
    await shot(page, '13-tableaux-de-bord-intendant');
  });
  await soft('infirmier', async () => {
    page = await as(browser, 'infirmier');
    await page.goto('/secondaire/services');
    await shot(page, '12-services-infirmier');
    await eachTab(page, '12-services-infirmier-onglet');
  });
  await soft('services', async () => {
    page = await as(browser, 'services');
    await page.goto('/secondaire/services');
    await shot(page, '12-services-responsable');
    await eachTab(page, '12-services-responsable-onglet');
  });
  await soft('promoteur', async () => {
    page = await as(browser, 'promoteur');
    await page.goto('/secondaire/tableaux-de-bord');
    await shot(page, '13-tableaux-de-bord-promoteur');
  });
});

// ---------------------------------------------------------------------------------------------------------------------------
// 14 Portails
// ---------------------------------------------------------------------------------------------------------------------------

test('14 : portail parent (390 px et 1280 px) avec paiement et reçu, portail élève', async ({ browser }) => {
  const page = await as(browser, 'parent', 390);
  await page.goto('/secondaire/famille');
  await expect(page.getByText(/Fictif-Lyceen/).first()).toBeVisible();
  await shot(page, '14-famille-mobile');
  await page.getByRole('button', { name: 'Payer' }).first().click();
  const pay = page.locator('form[aria-labelledby="sf-pay"]');
  await pay.locator('#sf-amount').fill('10000');
  await pay.locator('#sf-channel').selectOption('orange_money');
  await pay.locator('#sf-phone').fill(ctx.logins.parent![0]);
  await shot(page, '14-famille-mobile-paiement');
  await pay.getByRole('button').first().click();
  await expect(pay.getByRole('status')).toBeVisible();
  await shot(page, '14-famille-mobile-attente');

  const payments = (await apiAs('intendant', 'GET', '/payments')).data as Array<{ id: string; status: string; amount_xaf: number; external_reference?: string; provider_reference?: string }>;
  const pending = payments.find((p) => p.status === 'pending' && p.amount_xaf === 10000)!;
  const event = JSON.stringify({ event_id: `evt-guide-${pending.id}`, type: 'payment.succeeded', amount: 10000, provider_reference: pending.external_reference ?? pending.provider_reference, phone: ctx.logins.parent![0] });
  const hook = await fetch(`${stack.url}/api/v1/webhooks/payments/orange_money`, {
    method: 'POST', body: event, headers: { 'Content-Type': 'application/json', 'X-Signature': createHmac('sha256', WEBHOOK_SECRET).update(event).digest('hex') },
  });
  expect(hook.status).toBe(200);
  await expect(async () => {
    if (await pay.count()) await pay.getByRole('status').getByRole('button').click();
    await expect(pay).toHaveCount(0, { timeout: 3_000 });
  }).toPass({ timeout: 60_000 });
  await shot(page, '14-famille-mobile-recu');
  await soft('famille 1280', async () => {
    const big = await as(browser, 'parent');
    await big.goto('/secondaire/famille');
    await shot(big, '14-famille-ordinateur');
    await big.goto('/secondaire');
    await shot(big, '14-famille-accueil');
  });

  // Élève : compte rattaché à sa fiche.
  await soft('portail élève', async () => {
    const students = (await apiAs('scol', 'GET', '/secondary/enrolment/students')).data as Array<{ person_id: string; display_name?: string; last_name?: string }>;
    const issa = students.find((s) => JSON.stringify(s).includes('Fictif-Lyceen'))!;
    const admin = { login: ctx.logins.admin![0], password: ctx.logins.admin![1], token: (await stack.login(...ctx.logins.admin!)).token };
    const acc = await stack.user(admin, ctx.establishment, 'Issa', 'Fictif-Lyceen', 'sec_eleve', issa.person_id);
    ctx.logins.eleve = [acc.login, acc.password];
    const p = await as(browser, 'eleve', 390);
    await p.goto('/secondaire/mon-espace');
    await shot(p, '14-eleve-mobile');
    const d = await as(browser, 'eleve');
    await d.goto('/secondaire/mon-espace');
    await shot(d, '14-eleve-ordinateur');
  });

  // Situation financière après le paiement (intendant).
  await soft('situation après paiement', async () => {
    const p = await as(browser, 'intendant');
    await p.goto('/secondaire/situation-financiere');
    await shot(p, '04-situation-apres-paiement');
  });
});

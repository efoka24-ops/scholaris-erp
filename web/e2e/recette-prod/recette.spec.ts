/**
 * RECETTE EN PRODUCTION, LECTURE SEULE : primaire privé, primaire public, maternelle privée, maternelle publique.
 *
 * Ce que fait le script, pour chaque application et à 390 px puis 1280 px :
 *   1. se connecte (seul clic du script : le bouton de connexion) ;
 *   2. choisit l'établissement de l'application (POST /me/context : choix de session, aucune donnée écrite) ;
 *   3. ouvre chaque écran principal par son adresse (aucun clic), attend la fin du chargement et vérifie :
 *      - pas d'ErrorNotice (`.notice--error[role=alert]`), pas de page blanche, pas d'erreur JavaScript non rattrapée ;
 *      - aucune réponse 403 ni 5xx sur /api/ ; pas de renvoi vers /interdit, /connexion ou l'accueil ;
 *   4. prend une capture pleine page : web/e2e/screenshots/recette-prod/<AAAA-MM-JJ>/<projet>/<application>/<nn>-<écran>.png
 *      (dossier ignoré par git) et écrit le bilan JSON de l'application à côté.
 *
 * Garanties de lecture seule :
 *   - aucun clic hors du bouton « Se connecter » ; aucune saisie hors identifiant et mot de passe ;
 *   - garde réseau : toute requête /api/ autre que GET, HEAD ou OPTIONS (y compris POST avec X-HTTP-Method-Override) est
 *     ANNULÉE avant de partir, sauf POST /api/v1/auth/login et POST /api/v1/me/context ; chaque blocage est noté au bilan ;
 *   - service worker bloqué (configuration), pour que le garde voie toutes les requêtes.
 *
 * Variables d'environnement (jamais dans un fichier ; ni trace ni vidéo n'est enregistrée) :
 *   RECETTE_BASE_URL       défaut https://scholariserp.trugroup.cm
 *   RECETTE_EMAIL          identifiant (e-mail ou téléphone) ; RECETTE_PASSWORD : mot de passe
 *   RECETTE_<APP>_EMAIL / RECETTE_<APP>_PASSWORD   compte propre à une application (sinon RECETTE_EMAIL / RECETTE_PASSWORD)
 *   RECETTE_<APP>_ETAB     nom (ou partie du nom) ou identifiant de l'établissement à ouvrir ; sinon le premier établissement du
 *                          bon type (primary ou nursery). <APP> = PRIMAIRE_PRIVE, PRIMAIRE_PUBLIC, MATERNELLE_PRIVEE, MATERNELLE_PUBLIQUE
 *   RECETTE_APPS           liste séparée par des virgules (primaire-prive,primaire-public,maternelle-privee,maternelle-publique) ; défaut : toutes
 *   RECETTE_STRICT=1       un écran non accessible au compte (renvoi vers /interdit ou l'accueil) compte comme un échec ;
 *                          sans cette variable il est seulement signalé au bilan
 *   RECETTE_DATE           nom du dossier de captures (défaut : date du jour, AAAA-MM-JJ)
 *
 * Lancer (PowerShell) :
 *   cd web/e2e
 *   $env:RECETTE_EMAIL='...'; $env:RECETTE_PASSWORD='...'
 *   npx playwright test -c playwright.recette-prod.config.ts                         # les deux largeurs
 *   npx playwright test -c playwright.recette-prod.config.ts --project=recette-mobile-390 -g "maternelle-publique"
 *   npx playwright test -c playwright.recette-prod.config.ts --list                  # contrôle de compilation, sans réseau
 *
 * Limites : un compte qui exige la double authentification ou un nouveau mot de passe arrête le parcours (message explicite) ;
 * les écrans à identifiant (fiche élève, cahier d'appel d'une classe…) ne sont pas parcourus.
 */
import { expect, test, type Page, type Request, type TestInfo } from '@playwright/test';
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

type AppKey = 'primaire-prive' | 'primaire-public' | 'maternelle-privee' | 'maternelle-publique';
interface Screen { path: string; slug: string }
interface App { key: AppKey; env: string; type: 'primary' | 'nursery'; screens: Screen[] }
interface Issue { screen: string; kind: string; detail: string }

const screen = (path: string): Screen => ({ path, slug: path.replace(/^\//, '').replace(/[/:]+/g, '-') || 'accueil' });

/** Écrans lus dans web/src/views/{primary,public-schools,nursery-private,public-nursery}/routes.ts et web/src/router/routes.ts (sans paramètre). */
const APPS: App[] = [
  {
    key: 'primaire-prive',
    env: 'PRIMAIRE_PRIVE',
    type: 'primary',
    screens: ['/primaire', ...['tableau-de-bord', 'niveaux', 'referentiel', 'inscriptions', 'appel', 'competences', 'cahier', 'conduite', 'conseils', 'bulletins', 'remediation', 'examen', 'exports', 'cloture', 'famille'].map((p) => `/primaire/${p}`)].map(screen),
  },
  {
    key: 'primaire-public',
    env: 'PRIMAIRE_PUBLIC',
    type: 'primary',
    screens: [
      '/ecole-publique',
      ...['appel', 'classes', 'eleves', 'eleves/inscription', 'eleves/registre', 'transferts', 'abandons', 'ecole', 'ecole/photos', 'presence-enseignants', 'controle-presence', 'alertes', 'personnel', 'messages', 'appareil', 'evaluation', 'remediation', 'bulletins', 'passage', 'apee', 'paquet-minimum', 'statistiques', 'referentiel'].map((p) => `/ecole-publique/${p}`),
      '/public-primary/registry',
    ].map(screen),
  },
  {
    key: 'maternelle-privee',
    env: 'MATERNELLE_PRIVEE',
    type: 'nursery',
    screens: ['/maternelle-privee', ...['tableau-de-bord', 'sortie', 'appel', 'registre', 'observation', 'carnets', 'cahier-de-vie', 'besoins', 'autorises', 'inscription', 'frais', 'messages', 'sections', 'referentiel', 'consentement-photo', 'passage', 'services', 'famille'].map((p) => `/maternelle-privee/${p}`)].map(screen),
  },
  {
    key: 'maternelle-publique',
    env: 'MATERNELLE_PUBLIQUE',
    type: 'nursery',
    screens: ['/maternelle', ...['sorties', 'carnets', 'observations', 'carnet-trimestriel', 'cahier-de-vie', 'transition/decisions', 'transition/liaison', 'contribution', 'registre'].map((p) => `/maternelle/${p}`)].map(screen),
  },
];

/** Seules écritures permises : ouvrir la session et choisir l'établissement actif. */
const ALLOWED_WRITES = [/\/api\/v1\/auth\/login$/, /\/api\/v1\/me\/context$/];
const READ_METHODS = new Set(['GET', 'HEAD', 'OPTIONS']);
const DATE = process.env.RECETTE_DATE ?? new Date().toLocaleDateString('sv-SE', { timeZone: 'Africa/Douala' });
const OUT = join(dirname(fileURLToPath(import.meta.url)), '..', 'screenshots', 'recette-prod', DATE);
const STRICT = process.env.RECETTE_STRICT === '1';
const SELECTED = (process.env.RECETTE_APPS ?? '').split(',').map((s) => s.trim()).filter(Boolean);

function credentials(app: App): { identifier?: string; password?: string; etab?: string } {
  return {
    identifier: process.env[`RECETTE_${app.env}_EMAIL`] ?? process.env.RECETTE_EMAIL,
    password: process.env[`RECETTE_${app.env}_PASSWORD`] ?? process.env.RECETTE_PASSWORD,
    etab: process.env[`RECETTE_${app.env}_ETAB`],
  };
}

/** Garde réseau : annule toute écriture vers l'API avant qu'elle parte. */
async function installWriteGuard(page: Page, blocked: string[]): Promise<void> {
  await page.context().route(/\/api\//, async (route) => {
    const req: Request = route.request();
    const method = (req.headers()['x-http-method-override'] ?? req.method()).toUpperCase();
    const url = req.url().split('?')[0] ?? req.url();
    if (READ_METHODS.has(method) || (method === 'POST' && ALLOWED_WRITES.some((r) => r.test(url)))) {
      await route.continue();
      return;
    }
    blocked.push(`${method} ${new URL(url).pathname}`);
    await route.abort('blockedbyclient');
  });
}

async function signIn(page: Page, identifier: string, password: string): Promise<void> {
  await page.goto('/connexion');
  await page.locator('#identifier').fill(identifier);
  await page.locator('#password').fill(password);
  await page.locator('form button[type="submit"]').click();
  await expect(page, 'connexion refusée ou trop lente').not.toHaveURL(/\/connexion/, { timeout: 30_000 });
  const url = page.url();
  if (/double-authentification/.test(url)) throw new Error('Ce compte exige la double authentification : utiliser un compte de recette sans 2FA.');
  if (/changer-mot-de-passe/.test(url)) throw new Error('Ce compte doit changer son mot de passe : le faire à la main avant la recette.');
}

interface Tile { kind: string; id: string; name: string; type?: string }

/** Choisit l'établissement de l'application ; renvoie son nom ou null si le compte n'en a aucun du bon type. */
async function selectEstablishment(page: Page, app: App, wanted?: string): Promise<string | null> {
  const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
  const res = await page.request.get('/api/v1/me/contexts', { headers });
  expect(res.status(), 'GET /me/contexts').toBe(200);
  const tiles = ((await res.json()) as { data: Tile[] }).data.filter((t) => t.kind === 'establishment');
  const needle = wanted?.toLowerCase();
  const tile = needle
    ? tiles.find((t) => t.id === wanted || t.name.toLowerCase().includes(needle))
    : tiles.find((t) => t.type === app.type);
  if (!tile) return null;
  const sw = await page.request.post('/api/v1/me/context', { headers: { ...headers, 'Content-Type': 'application/json' }, data: { establishment_id: tile.id } });
  expect(sw.status(), `POST /me/context (${tile.name})`).toBeLessThan(300);
  return tile.name;
}

async function settle(page: Page): Promise<void> {
  await page.waitForLoadState('networkidle', { timeout: 20_000 }).catch(() => undefined);
  await page.locator('[aria-busy="true"]').first().waitFor({ state: 'detached', timeout: 10_000 }).catch(() => undefined);
  await page.waitForTimeout(400);
}

async function visit(page: Page, info: TestInfo, app: App, s: Screen, index: number, issues: Issue[], apiErrors: string[], pageErrors: string[]): Promise<void> {
  apiErrors.length = 0;
  pageErrors.length = 0;
  const add = (kind: string, detail: string): void => void issues.push({ screen: s.path, kind, detail });

  await page.goto(s.path, { waitUntil: 'domcontentloaded' });
  await settle(page);

  const finalPath = new URL(page.url()).pathname;
  if (finalPath !== s.path) {
    const kind = /\/connexion/.test(finalPath) ? 'session perdue' : STRICT ? 'non accessible' : 'non accessible (signalé)';
    add(kind, `renvoyé vers ${finalPath}`);
  }
  for (const text of await page.locator('.notice--error[role="alert"]').allInnerTexts()) add('ErrorNotice', text.replace(/\s+/g, ' ').trim().slice(0, 300));
  const content = await page.locator('#app').innerText().catch(() => '');
  if (content.trim().length < 20) add('page blanche', `${content.trim().length} caractères visibles`);
  for (const e of apiErrors) add('réponse API', e);
  for (const e of pageErrors) add('erreur JavaScript', e);

  const file = join(OUT, info.project.name, app.key, `${String(index).padStart(2, '0')}-${s.slug}.png`);
  mkdirSync(dirname(file), { recursive: true });
  await page.screenshot({ path: file, fullPage: true });
}

for (const app of APPS) {
  test(`recette lecture seule : ${app.key}`, async ({ page }, info) => {
    test.skip(SELECTED.length > 0 && !SELECTED.includes(app.key), 'application non retenue par RECETTE_APPS');
    const { identifier, password, etab } = credentials(app);
    test.skip(!identifier || !password, `RECETTE_EMAIL / RECETTE_PASSWORD (ou RECETTE_${app.env}_EMAIL / _PASSWORD) absents`);

    const blocked: string[] = [];
    const apiErrors: string[] = [];
    const pageErrors: string[] = [];
    const issues: Issue[] = [];
    await installWriteGuard(page, blocked);
    page.on('response', (r) => {
      const u = new URL(r.url());
      if (u.pathname.includes('/api/') && (r.status() === 403 || r.status() >= 500)) apiErrors.push(`${r.status()} ${r.request().method()} ${u.pathname}`);
    });
    page.on('pageerror', (e) => pageErrors.push(e.message.slice(0, 300)));

    await test.step('connexion', () => signIn(page, identifier as string, password as string));
    const name = await test.step('choix de l\'établissement', () => selectEstablishment(page, app, etab));
    test.skip(name === null, `aucun établissement ${etab ? `« ${etab} »` : `de type ${app.type}`} pour ce compte`);
    info.annotations.push({ type: 'établissement', description: name ?? '' });

    for (const [i, s] of app.screens.entries()) {
      await test.step(s.path, () => visit(page, info, app, s, i + 1, issues, apiErrors, pageErrors));
    }

    const report = { application: app.key, projet: info.project.name, etablissement: name, date: DATE, ecrans: app.screens.length, problemes: issues, ecrituresBloquees: blocked };
    mkdirSync(join(OUT, info.project.name), { recursive: true });
    writeFileSync(join(OUT, info.project.name, `${app.key}.json`), JSON.stringify(report, null, 2), 'utf8');
    await info.attach(`bilan-${app.key}`, { body: JSON.stringify(report, null, 2), contentType: 'application/json' });

    const failing = issues.filter((x) => STRICT || x.kind !== 'non accessible (signalé)');
    expect(failing, `Problèmes sur ${app.key} (détail : ${join(OUT, info.project.name, `${app.key}.json`)})`).toEqual([]);
  });
}

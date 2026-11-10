import { test } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { actorPage, closeActors, expect, selectContaining, startStack, uiLogin, type Stack } from './stack';

/**
 * Parcours 3 — Primaire privé (C-110), sur la pile réelle. Semis par l'API (« primarySchool ») : école privée, année et
 * trimestres, classe CM2 A autorisée, trois élèves fictifs et leurs parents, comptes directeur (primary_directeur), maître
 * (enseignant) et parent du premier élève.
 *
 * État constaté (rapport C-110) :
 * - C110-PR1 (corrigé par C-115, migration 202611080101) : les rôles primaires lisent l'organisation (GET /structures et
 *   /academic-years) ; les écrans de la direction sont de nouveau vérifiés sans échec attendu.
 * - C110-PR2 (corrigé par C-117) : import de la feuille de compétences par fichier CSV joint ; le second test (C-118) joue à
 *   l'écran référentiel, import, conseil des maîtres, bulletins publiés, consultation parent et contrôles de clôture.
 */
test.describe.configure({ mode: 'serial', timeout: 600_000 });
test.use({ actionTimeout: 45_000 });

interface PrimarySchool {
  logins: Record<string, [string, string]>;
}

let stack: Stack;
let ctx: PrimarySchool;
test.beforeAll(async () => {
  test.setTimeout(300_000);
  stack = await startStack();
  ctx = await stack.seed<PrimarySchool>('primarySchool');
});
test.afterEach(async () => {
  await closeActors();
});
test.afterAll(async () => {
  await stack?.stop();
});

test('primaire privé : la direction paramètre l\'école et ouvre les niveaux, le référentiel et la feuille de CM2', async ({ browser }, info) => {
  const page = await actorPage(browser, stack, info);
  await uiLogin(page, ...ctx.logins.director!);

  // Paramétrage recommandé (mode d'appel par demi-journée).
  await page.goto('/primaire');
  await expect(page.getByRole('heading', { name: 'Mon école primaire', level: 1 })).toBeVisible();
  const start = page.getByRole('region', { name: 'Démarrage' });
  // C-118 : la région « Démarrage » reste affichée ; on attend son état (prête ou à régler) au lieu de sa disparition (course).
  const ready = start.getByText('Votre école est prête');
  const apply = start.getByRole('button', { name: 'Appliquer les réglages recommandés' });
  await expect(ready.or(apply)).toBeVisible();
  if (await apply.isVisible()) await apply.click();
  await expect(ready).toBeVisible();

  // Les écrans de la direction lisent l'organisation de l'école (niveaux, classes, année).
  for (const [url, title] of [['/primaire/niveaux', 'Niveaux'], ['/primaire/referentiel', 'Référentiel'], ['/primaire/competences', 'Feuille de compétences']] as const) {
    await page.goto(url);
    await expect(page.getByRole('heading', { name: title, level: 1 })).toBeVisible();
    await expect(page.getByText("Vous n'avez pas le droit de consulter l'organisation."), `${url} lit l'organisation`).toHaveCount(0);
  }
  await page.goto('/primaire/competences');
  await expect(page.getByLabel('Classe')).toContainText('CM2 A');
  await stack.attachRefusals(info);
});

// C-118 : rejoué à l'écran (import CSV livré par C-117, C110-PR2). Même école que le test précédent (mode série).
test('primaire privé : référentiel, import des compétences par fichier CSV joint, conseil des maîtres, bulletins publiés, consultation parent, clôture', async ({ browser }, info) => {
  // 1. La direction complète le référentiel du CM2 pour l'année : un domaine et deux compétences (libellés fictifs).
  let page = await actorPage(browser, stack, info);
  await uiLogin(page, ...ctx.logins.director!);
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

  // 2. Le maître importe la feuille de compétences du trimestre 1 par fichier CSV joint (modèle téléchargé, rempli « Acquis »).
  await page.context().close();
  page = await actorPage(browser, stack, info);
  await uiLogin(page, ...ctx.logins.teacher!);
  await page.goto('/primaire/competences');
  await selectContaining(page.locator('#sh-class'), 'CM2 A');
  await selectContaining(page.locator('#sh-period'), 'Trimestre 1');
  const panel = page.locator('section').filter({ has: page.getByRole('heading', { name: 'Importer la feuille (fichier CSV)' }) });
  await expect(panel).toBeVisible();
  const [download] = await Promise.all([page.waitForEvent('download'), panel.getByRole('button', { name: 'Télécharger le modèle' }).click()]);
  const template = readFileSync((await download.path())!, 'utf8').replace(/^\s+/, ''); // BOM UTF-8 retiré (\s couvre U+FEFF)
  const [header, ...rows] = template.split(/\r?\n/).filter((l) => l.trim() !== '');
  const columns = header!.split(';');
  const filled = [header!, ...rows.map((r) => {
    const cells = r.split(';');
    return columns.map((_, i) => (i < 2 ? cells[i] ?? '' : 'acquired')).join(';');
  })].join('\r\n');
  expect(rows.length, 'une ligne par élève').toBe(3);
  await panel.locator('input[type="file"]').setInputFiles({ name: 'feuille-cm2.csv', mimeType: 'text/csv', buffer: Buffer.from(filled, 'utf8') });
  await panel.getByRole('button', { name: 'Importer' }).click();
  // La feuille se recharge après l'import : chaque compétence de chaque élève est « Acquis ».
  await expect(page.getByRole('region', { name: /Amina/ }).getByRole('button', { name: 'Acquis', pressed: true })).toHaveCount(2);
  await expect(page.getByRole('button', { name: 'Acquis', pressed: true })).toHaveCount(6);

  // 3. La direction verrouille puis publie la feuille, ouvre et valide le conseil des maîtres du trimestre 1.
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
  await page.goto('/primaire/conseils');
  await selectContaining(page.locator('#co-class'), 'CM2 A');
  await selectContaining(page.locator('#co-term'), 'Trimestre 1');
  await page.getByRole('button', { name: 'Ouvrir le conseil' }).click();
  await expect(page.getByRole('heading', { name: /CM2 A ·/ })).toBeVisible();
  await page.getByRole('button', { name: 'Valider le conseil' }).click();
  await expect(page.getByText('Validé').first()).toBeVisible();

  // 4. Bulletins émis puis publiés aux familles.
  await page.goto('/primaire/bulletins');
  await page.getByRole('button', { name: 'Émettre les bulletins' }).click();
  await expect(page.getByText(/3 émis/)).toBeVisible({ timeout: 120_000 });
  await page.getByRole('button', { name: /Publier aux familles \(3\)/ }).click();
  await expect(page.getByText(/3 publié\(s\)/)).toBeVisible();

  // 5. Clôture : contrôles de fin d'année affichés à la direction.
  await page.goto('/primaire/cloture');
  await expect(page.getByRole('heading', { name: 'Contrôles' })).toBeVisible();

  // 6. Le parent consulte le bulletin publié de son enfant.
  await page.context().close();
  page = await actorPage(browser, stack, info);
  await uiLogin(page, ...ctx.logins.parent!);
  await page.goto('/primaire/famille');
  await expect(page.getByRole('heading', { name: 'Bulletins', exact: true })).toBeVisible();
  // Un seul enfant : pas de liste de choix ; le bulletin du trimestre 1 est publié.
  await expect(page.getByRole('button', { name: 'Bulletin trimestriel · Trimestre 1' })).toBeVisible();
  await stack.attachRefusals(info);
  expect(stack.serverErrors()).toEqual([]);
});

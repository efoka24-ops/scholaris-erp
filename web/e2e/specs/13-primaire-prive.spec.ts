import { test } from '@playwright/test';
import { actorPage, closeActors, expect, startStack, uiLogin, type Stack } from './stack';

/**
 * Parcours 3 — Primaire privé (C-110), sur la pile réelle. Semis par l'API (« primarySchool ») : école privée, année et
 * trimestres, classe CM2 A autorisée, trois élèves fictifs et leurs parents, comptes directeur (primary_directeur), maître
 * (enseignant) et parent du premier élève.
 *
 * État constaté (rapport C-110) :
 * - C110-PR1 (corrigé par C-115, migration 202611080101) : les rôles primaires lisent l'organisation (GET /structures et
 *   /academic-years) ; les écrans de la direction sont de nouveau vérifiés sans échec attendu.
 * - C110-PR2 : aucun écran ni aucune route n'importe des notes (compétences) depuis un fichier CSV joint ; seul l'examen de fin
 *   de cycle importe des résultats (texte CSV dans un corps JSON). Étape « import CSV » non jouable.
 */
test.describe.configure({ timeout: 600_000 });
test.use({ actionTimeout: 45_000 });

interface PrimarySchool {
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

test('primaire privé : la direction paramètre l\'école et ouvre les niveaux, le référentiel et la feuille de CM2', async ({ browser }, info) => {
  const ctx = await stack.seed<PrimarySchool>('primarySchool');
  const page = await actorPage(browser, stack, info);
  await uiLogin(page, ...ctx.logins.director!);

  // Paramétrage recommandé (mode d'appel par demi-journée).
  await page.goto('/primaire');
  await expect(page.getByRole('heading', { name: 'Mon école primaire', level: 1 })).toBeVisible();
  const start = page.getByRole('region', { name: 'Démarrage' });
  if (await start.count()) {
    await start.getByRole('button', { name: 'Appliquer les réglages recommandés' }).click();
    await expect(start).toHaveCount(0);
  }

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

test.fixme('primaire privé : import des notes par fichier CSV joint, conseil des maîtres, bulletins publiés, consultation parent, clôture', async () => {
  // Reste bloqué par C110-PR2 (aucun import de notes par fichier CSV) ; C110-PR1 est corrigé (C-115).
});

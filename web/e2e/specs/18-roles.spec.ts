import { test } from '@playwright/test';
import { actorPage, expect, startStack, uiLogin, type Stack } from './stack';

/**
 * Parcours 8 — Rôles (C-110), sur la pile réelle : pour chaque application, des comptes à rôle restreint (enseignant, caissier,
 * parent) créés par l'administrateur du client ne voient pas les écrans des autres (pas d'entrée « Administration » au menu) et
 * une adresse interdite tapée à la main mène à /interdit (« Accès refusé »). Données fictives semées par l'API (« roles »).
 */
test.describe.configure({ timeout: 600_000 });
test.use({ actionTimeout: 45_000 });

interface Roles {
  logins: Record<string, [string, string]>;
}

/** [application, type, rôle de direction, rôle enseignant, rôle caissier, { acteur: adresses interdites }] */
const APPS: Array<[string, string, string, string, string, Record<string, string[]>]> = [
  ['higher', 'higher', 'higher_dir_ecole', 'enseignant', 'caissier', {
    teacher: ['/administration/utilisateurs', '/superieur/deliberation/releves'],
    cashier: ['/administration/parametres', '/superieur/notes'],
    parent: ['/administration', '/superieur/notes'],
  }],
  ['primary', 'primary', 'primary_directeur', 'enseignant', 'caissier', {
    teacher: ['/administration/utilisateurs', '/primaire/cloture'],
    cashier: ['/administration/parametres', '/primaire/competences'],
    parent: ['/administration', '/primaire/competences'],
  }],
  ['public_primary', 'primary', 'pub_school_director', 'pub_class_teacher', '', {
    teacher: ['/administration/utilisateurs', '/ecole-publique/apee'],
    parent: ['/administration', '/ecole-publique/evaluation'],
  }],
  ['nursery', 'nursery', 'nursery_directeur', 'nursery_educator', 'caissier', {
    teacher: ['/administration/utilisateurs', '/maternelle-privee/frais'],
    cashier: ['/administration/parametres', '/maternelle-privee/carnets'],
    parent: ['/administration', '/maternelle-privee/carnets'],
  }],
  ['secondary', 'secondary', 'sec_chef_etablissement', 'enseignant', 'sec_caissier', {
    teacher: ['/administration/utilisateurs', '/secondaire/frais'],
    cashier: ['/administration/parametres', '/secondaire/notes'],
    parent: ['/administration', '/secondaire/parametrage'],
  }],
];

let stack: Stack;
test.beforeAll(async () => {
  test.setTimeout(120_000);
  stack = await startStack();
});
test.afterAll(async () => {
  await stack?.stop();
});

for (const [app, type, director, teacher, cashier, forbidden] of APPS) {
  test(`rôles restreints de l'application ${app} : écrans des autres invisibles et adresse interdite vers /interdit`, async ({ browser }, info) => {
    const ctx = await stack.seed<Roles>('roles', app, type, director, teacher, ...(cashier ? [cashier] : []));
    for (const [who, urls] of Object.entries(forbidden)) {
      const [login, password] = ctx.logins[who]!;
      const page = await actorPage(browser, stack, info);
      await uiLogin(page, login, password);
      await expect(page.getByRole('link', { name: 'Administration', exact: true })).toHaveCount(0);
      for (const url of urls) {
        await page.goto(url);
        await expect(page, `${who} sur ${url}`).toHaveURL(/\/interdit$/);
        await expect(page.getByRole('heading', { name: 'Accès refusé', level: 1 })).toBeVisible();
      }
      await page.context().close();
    }
    await stack.attachRefusals(info);
    expect(stack.serverErrors()).toEqual([]);
    stack.expectOnlyGetPost();
  });
}

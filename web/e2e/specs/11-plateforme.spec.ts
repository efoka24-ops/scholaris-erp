import { test } from '@playwright/test';
import { actorPage, closeActors, expect, freshTotp, startStack, typeCode, type Stack } from './stack';

/**
 * Parcours 1 — Plateforme (C-110), sur la pile réelle : demande d'accès publique, acceptation par le super administrateur de
 * la plateforme (2FA TOTP réelle), client et administrateur créés, code d'activation reçu par SMS, première connexion de
 * l'administrateur, passage d'une application de « Bientôt » à « Disponible » au catalogue, puis impayé (passage quotidien
 * réel `platform:billing-run`), bandeau de régularisation, déclaration de paiement et vérification par la plateforme.
 */
test.describe.configure({ timeout: 600_000 });
test.use({ actionTimeout: 45_000 });

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

test('demande d\'accès → acceptation → activation → catalogue → impayé et paiement déclaré vérifié', async ({ browser }, info) => {
  const suffix = info.project.name === 'mobile-390' ? 'Mobile' : 'Bureau';
  const institution = `Institut Supérieur de Recette ${suffix}`;
  const contactPhone = stack.phone();

  // 1. Visiteur sans compte : demande d'accès publique pour l'application IPES (disponible).
  const visitor = await actorPage(browser, stack, info);
  await visitor.goto('/connexion');
  await visitor.getByRole('link', { name: "Demander l'accès" }).click();
  await expect(visitor.getByRole('heading', { name: "Demander l'accès", level: 1 })).toBeVisible();
  await visitor.getByLabel("Nom de l'établissement *").fill(institution);
  await visitor.getByLabel("Type d'établissement *").selectOption('higher');
  await visitor.getByLabel('Ville *').fill('Garoua');
  await visitor.getByLabel('Région *').fill('Nord');
  await visitor.getByLabel('Prénom *').fill('Awa');
  await visitor.getByLabel('Nom *', { exact: true }).fill('Ngo Recette');
  await visitor.getByLabel('Adresse e-mail *').fill(`direction-${suffix.toLowerCase()}@isr-recette.test`);
  await visitor.getByLabel('Téléphone').fill(contactPhone);
  await visitor.getByRole('radio', { name: 'IPES - Enseignement supérieur' }).check();
  await visitor.getByRole('button', { name: 'Envoyer la demande' }).click();
  await expect(visitor.getByRole('heading', { name: 'Demande enregistrée' })).toBeVisible();
  const tracking = await visitor.getByLabel('Votre numéro de suivi').inputValue();
  expect(tracking).toMatch(/^[a-f0-9]{48}$/);

  // 2. Super administrateur de la plateforme : compte créé par l'outil console, TOTP enrôlé (application d'authentification).
  const root = stack.tool<{ login: string; password: string }>('platform-admin', stack.phone(), 'Plato', 'Forme');
  const { secret } = await stack.login(root.login, root.password);
  const platform = await actorPage(browser, stack, info);
  await platform.goto('/connexion');
  await platform.getByLabel(/^(E-mail ou téléphone|Numéro de téléphone ou e-mail)$/).fill(root.login);
  await platform.getByLabel('Mot de passe', { exact: true }).fill(root.password);
  await platform.getByRole('button', { name: 'Se connecter' }).click();
  await expect(platform).toHaveURL(/\/double-authentification/);
  await typeCode(platform, await freshTotp(secret!));
  // Le code complet est envoyé tout seul ; « Valider » reste disponible si l'envoi automatique n'a pas eu lieu.
  await platform.getByRole('button', { name: 'Valider' }).click({ timeout: 3_000 }).catch(() => undefined);
  await expect(platform).toHaveURL(/\/platform/);

  // 3. Acceptation de la demande : client, établissement, essai et premier administrateur créés.
  await platform.goto('/platform/demandes');
  await platform.getByRole('link', { name: `Voir le détail de ${institution}` }).click();
  await expect(platform.getByRole('heading', { name: institution, level: 1 })).toBeVisible();
  await platform.getByRole('button', { name: 'Accepter', exact: true }).click();
  await expect(platform.getByLabel('Client')).toHaveValue('');
  // Envoi automatique de l'accès (coché d'office) : par SMS, le téléphone de la demande servant d'identifiant d'activation.
  await expect(platform.locator('[data-test="auto-send"]')).toBeChecked();
  await platform.getByLabel(/^Canal d.envoi$/).selectOption('sms');
  await platform.getByRole('button', { name: "Confirmer l'acceptation" }).click();
  await expect(platform.getByText('Demande acceptée.')).toBeVisible();
  await expect(platform.getByText(/Premier administrateur invité/)).toBeVisible();
  const tracked = await stack.api('GET', `/public/access-requests/${tracking}`);
  expect(tracked.data.status).toBe('accepted');

  // 4. Code d'activation reçu par SMS, choix du mot de passe, première connexion de l'administrateur du client.
  const admin = await actorPage(browser, stack, info);
  await admin.goto('/connexion');
  await admin.getByRole('button', { name: 'Activer mon compte par SMS' }).or(admin.getByRole('link', { name: 'Activer mon compte par SMS' })).click();
  await expect(admin.getByRole('heading', { name: 'Activation du compte' })).toBeVisible();
  await admin.getByLabel(/^(E-mail ou téléphone|Numéro de téléphone ou e-mail)$/).fill(contactPhone);
  // C110-P1 corrigé (C-116) : le code envoyé à l'acceptation se saisit directement, sans en redemander un.
  await admin.getByRole('button', { name: 'J\'ai déjà reçu un code' }).click();
  await expect(admin.getByText(/Entrez le code reçu par SMS ou par e-mail/)).toBeVisible();
  const code = stack.lastCode(contactPhone);
  expect(code, 'code d\'activation reçu par SMS').toMatch(/^\d{6}$/);
  await typeCode(admin, code!);
  await admin.getByRole('button', { name: 'Vérifier le code' }).click();
  const password = 'Recette-Admin-Client-2026!';
  await admin.getByLabel('Nouveau mot de passe').fill(password);
  await admin.getByLabel('Confirmez le mot de passe').fill(password);
  await admin.getByRole('button', { name: 'Enregistrer le mot de passe' }).click();
  await expect(admin.getByText('Votre mot de passe est enregistré. Vous pouvez vous connecter.')).toBeVisible();
  await admin.getByRole('link', { name: 'Aller à la connexion' }).or(admin.getByRole('button', { name: 'Aller à la connexion' })).click();
  await admin.getByLabel(/^(E-mail ou téléphone|Numéro de téléphone ou e-mail)$/).fill(contactPhone);
  await admin.getByLabel('Mot de passe', { exact: true }).fill(password);
  await admin.getByRole('button', { name: 'Se connecter' }).click();
  await expect(admin).not.toHaveURL(/\/connexion|\/activation/);
  await expect(admin.getByText(institution).first()).toBeVisible();

  // 5. Catalogue : « Enseignement secondaire » passe de « Bientôt » à « Disponible ».
  await platform.goto('/platform/catalogue');
  await expect(platform.getByRole('heading', { name: 'Catalogue des applications', level: 1 })).toBeVisible();
  await platform.getByRole('button', { name: 'Modifier Enseignement secondaire' }).click();
  await expect(platform.getByLabel('Statut')).toHaveValue('coming_soon');
  await platform.getByLabel('Statut').selectOption('available');
  await platform.getByRole('button', { name: 'Enregistrer' }).click();
  await expect(platform.getByText('Catalogue mis à jour.')).toBeVisible();
  const catalog = await stack.api('GET', '/public/applications');
  expect(catalog.data.find((a: { key: string }) => a.key === 'secondary').status).toBe('available');

  // 6. Impayé : passage quotidien réel des abonnements après la fin de l'essai et du délai de grâce.
  const trialEnd = new Date(Date.now() + 112 * 86400e3).toISOString().slice(0, 10);
  const run = stack.tool<{ code: number; output: string }>('artisan', 'platform:billing-run', trialEnd);
  expect(run.output).toMatch(/1 suspension/);
  await admin.goto('/');
  const banner = admin.getByRole('region', { name: 'Situation de l’abonnement' });
  await expect(banner).toContainText('Abonnement suspendu : lecture et export seulement.');
  await banner.getByRole('link', { name: 'Régulariser' }).click();
  await expect(admin.getByRole('heading', { name: 'Régularisation de paiement', level: 1 })).toBeVisible();
  await admin.getByRole('button', { name: 'Déclarer mon paiement' }).click();
  const form = admin.getByRole('form', { name: 'J’ai déjà payé : déclarer mon paiement' });
  await form.getByRole('radio', { name: /^Total/ }).check();
  await form.getByRole('radio', { name: /Orange Money/ }).check();
  await form.getByLabel('Numéro payeur').fill('6 99 00 00 01');
  await form.getByLabel('Motif ou référence du paiement').fill(`OM${suffix.toUpperCase()}.AB12`);
  await form.getByRole('button', { name: 'Valider ma déclaration' }).click();
  await expect(admin.getByText(/Déclaration enregistrée/)).toBeVisible();

  // 7. La plateforme constate le paiement et le confirme : l'accès du client est rétabli.
  await platform.goto('/platform/a-regulariser');
  await expect(platform.getByRole('heading', { name: 'Paiements déclarés à vérifier' })).toBeVisible();
  await platform.getByRole('group', { name: new RegExp(`Actions pour ${institution}`) }).getByRole('button', { name: 'Confirmer le paiement' }).click();
  await platform.getByLabel('Montant réellement reçu (FCFA)').fill('60000');
  await platform.getByRole('button', { name: 'Confirmer', exact: true }).click();
  await expect(platform.getByText('Paiement confirmé : l’accès du client est rétabli.')).toBeVisible();
  const after = await stack.api('GET', '/me/regularisation', undefined, { token: (await stack.login(contactPhone, password)).token });
  expect(after.data.mode, JSON.stringify(after.data)).not.toBe('read_only');

  expect(stack.serverErrors()).toEqual([]);
  stack.expectOnlyGetPost();
});

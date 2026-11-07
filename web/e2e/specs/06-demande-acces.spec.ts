import { expect, test, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { checkA11y, shot } from './helpers';

/**
 * Demande d'accès publique à la plateforme (écrans du chantier Plateforme). Réponses rejouées depuis
 * fixtures/platform.json, enregistré sur la pile PHP réelle (tests/E2E/PlatformUiFixturesRecorderTest.php).
 */
const here = dirname(fileURLToPath(import.meta.url));
const recorded = JSON.parse(readFileSync(join(here, '..', 'fixtures', 'platform.json'), 'utf8'));
const calls = recorded.calls as Record<string, { status: number; content_type: string; body: unknown }>;
const TOKEN: string = recorded.tracking_token;

interface PublicState { submitted: Array<Record<string, unknown>>; unhandled: string[] }

async function installPublicApi(page: Page): Promise<PublicState> {
  const state: PublicState = { submitted: [], unhandled: [] };
  const reply = (key: string) => ({ status: calls[key]!.status, contentType: calls[key]!.content_type, body: JSON.stringify(calls[key]!.body) });
  await page.route('**/api/v1/**', async (route) => {
    const req = route.request();
    const path = new URL(req.url()).pathname.replace(/^\/api\/v1/, '');
    const call = `${req.method()} ${path}`;
    if (call === 'GET /public/applications') return route.fulfill(reply('public.applications'));
    if (call === 'POST /public/access-requests') {
      const body = req.postDataJSON() as Record<string, unknown>;
      state.submitted.push(body);
      // Adresse refusée par le serveur (règle serveur plus stricte que le contrôle du navigateur) : 422 réel enregistré.
      return route.fulfill(String(body.contact_email).includes('refusee') ? reply('public.request.invalid') : reply('public.request.accepted'));
    }
    if (call === `GET /public/access-requests/${TOKEN}`) return route.fulfill(reply('public.track.pending'));
    if (call.startsWith('GET /public/access-requests/')) return route.fulfill(reply('public.track.unknown'));
    if (call !== 'GET /me') state.unhandled.push(call); // GET /me sans session : 401, comportement réel attendu.
    return route.fulfill({ status: 401, contentType: 'application/problem+json', body: JSON.stringify({ type: 'https://api.scholaris.cm/errors/unauthenticated', title: 'Non authentifié', status: 401, detail: 'Authentification requise.', trace_id: '01RECETTE0000000000000000' }) });
  });
  return state;
}

test('demande d\'accès sans compte : contrôles de saisie, erreur serveur 422, numéro de suivi puis suivi', async ({ page }, info) => {
  test.setTimeout(120_000);
  const state = await installPublicApi(page);
  // Parcours réel d'un visiteur : écran de connexion puis lien « Demander l'accès » (voir le défaut F1 pour l'accès direct).
  await page.goto('/connexion');
  await page.getByRole('link', { name: "Demander l'accès" }).click();
  await expect(page.getByRole('heading', { name: "Demander l'accès", level: 1 })).toBeVisible();
  await shot(page, info, '15-demande-acces');
  await checkA11y(page, info, 'demande-acces');

  // Formulaire vide : erreurs sur place, rien n'est envoyé.
  await page.getByRole('button', { name: 'Envoyer la demande' }).click();
  await expect(page.getByText("Certains champs sont à corriger", { exact: false })).toBeVisible();
  await expect(page.getByText("Indiquez le nom complet de l'établissement (3 à 160 caractères).")).toBeVisible();
  expect(state.submitted).toHaveLength(0);
  await shot(page, info, '16-demande-acces-erreurs');

  await page.getByLabel("Nom de l'établissement *").fill('Institut Supérieur de Recette');
  await page.getByLabel("Type d'établissement *").selectOption('higher');
  await page.getByLabel('Ville *').fill('Douala');
  await page.getByLabel('Région *').fill('Littoral');
  await page.getByLabel('Prénom *').fill('Awa');
  await page.getByLabel('Nom *', { exact: true }).fill('Ngo Bassa');
  await page.getByLabel('Téléphone').fill('+237 6 90 11 22 33');
  await expect(page.getByLabel('Application *')).toHaveValue('higher');

  // Le serveur refuse l'adresse : le message du champ vient de la réponse 422 réelle.
  await page.getByLabel('Adresse e-mail *').fill('adresse@refusee.test');
  await page.getByRole('button', { name: 'Envoyer la demande' }).click();
  await expect(page.getByText('Adresse e-mail invalide.')).toBeVisible();
  expect(state.submitted).toHaveLength(1);

  await page.getByLabel('Adresse e-mail *').fill('direction@isr-recette.test');
  await page.getByRole('button', { name: 'Envoyer la demande' }).click();
  await expect(page.getByRole('heading', { name: 'Demande enregistrée' })).toBeVisible();
  await expect(page.getByLabel('Votre numéro de suivi')).toHaveValue(TOKEN);
  const sent = state.submitted[1]!;
  expect(sent).toMatchObject({ institution_name: 'Institut Supérieur de Recette', target_type: 'higher', app_key: 'higher', contact_email: 'direction@isr-recette.test' });
  expect(sent, 'le champ piège n\'est jamais transmis par une personne').not.toHaveProperty('website');
  await shot(page, info, '17-demande-acces-enregistree');
  await checkA11y(page, info, 'demande-acces-enregistree');

  await page.getByRole('link', { name: /Suivre ma demande/ }).click();
  await expect(page).toHaveURL(new RegExp(`/demande-acces/${TOKEN}$`));
  await expect(page.getByText("Votre demande est en cours d'examen.", { exact: false })).toBeVisible();
  await shot(page, info, '18-demande-acces-suivi');
  await checkA11y(page, info, 'demande-acces-suivi');
  expect(state.unhandled, 'appels non prévus').toEqual([]);
});

test('suivi avec un numéro inconnu : message clair', async ({ page }) => {
  await installPublicApi(page);
  await page.goto('/connexion');
  await page.getByRole('link', { name: "Demander l'accès" }).click();
  await page.getByLabel('Numéro de suivi').fill('a'.repeat(48));
  await page.getByRole('button', { name: 'Consulter' }).click();
  await expect(page.getByText('Aucune demande ne correspond à ce numéro.', { exact: false })).toBeVisible();
});

for (const path of ['/demande-acces', '/demande-acces/' + 'a'.repeat(48), '/verification/' + 'b'.repeat(48), '/decouvrir', '/activation']) {
  test(`accès direct sans session à ${path.slice(0, 32)} : la page publique reste affichée`, async ({ page }) => {
    // Défaut F1 (test-report.md) : sans session, GET /me répond 401 et le gestionnaire global renvoie vers /connexion avant
    // la résolution de la première route (la route courante est encore « / », non publique). Attendu en échec tant que F1 existe.
    test.fail(true, 'Défaut F1 : page publique ouverte directement sans session redirigée vers /connexion');
    const state = await installPublicApi(page);
    await page.route('**/api/v1/public/verify/**', (route) => route.fulfill({ status: 404, contentType: 'application/problem+json', body: JSON.stringify({ type: 'https://api.scholaris.cm/errors/not-found', title: 'Introuvable', status: 404, detail: 'Document inconnu.', trace_id: '01RECETTE0000000000000001' }) }));
    await page.goto(path);
    await page.waitForLoadState('networkidle');
    expect(page.url(), 'appels : ' + state.unhandled.join(', ')).not.toContain('/connexion');
  });
}

import { expect, test, type Page, type Route } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

/**
 * Lot P1 des écoles publiques (T644, T645) sur téléphone (390 px) : code personnel de l'appareil, appel de 60 élèves hors
 * ligne en moins d'une minute, rejeu à la reconnexion, rôle « agrégats seuls » sans accès nominatif. L'API est simulée par
 * interception réseau (aucune donnée réelle : élèves fictifs).
 */
const TODAY = new Intl.DateTimeFormat('en-CA', { timeZone: 'Africa/Douala', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
const ROSTER = Array.from({ length: 60 }, (_, i) => ({ person_id: `P${String(i).padStart(2, '0')}`, last_name: `Élève${String(i).padStart(2, '0')}`, first_name: 'Fictif', sex: i % 2 ? 'F' : 'M' }));

const TEACHER_PERMS = [
  'public_schools.school.read', 'public_schools.pupils.read', 'public_schools.attendance.read', 'public_schools.attendance.write',
  'public_schools.teacher_presence.read', 'public_schools.messages.send', 'organisation.read', 'sync.batches.submit',
];
const AGGREGATES_PERMS = ['public_schools.aggregates.read'];

interface Seen {
  puts: Array<{ path: string; headers: Record<string, string>; body: { day_part: string; client_uuid: string; records: Array<{ person_id: string; status: string }> } }>;
  unhandled: string[];
}

async function install(page: Page, role: string, permissions: string[]): Promise<Seen> {
  const seen: Seen = { puts: [], unhandled: [] };
  const me = {
    id: 'U1', person_id: 'PE1', first_name: 'Joseph', last_name: 'Ateba', locale: 'fr', status: 'active', greeting: 'Joseph', platform_admin: false,
    two_factor: { enabled: true, required: false, verified: true }, context: { establishment_id: 'E1', role },
  };
  const tile = { kind: 'establishment', id: 'E1', name: 'École publique de démonstration', type: 'primary', accent: '#F26B1D', logo: null, roles: [{ code: role, label: role, permissions }], permissions };
  await page.route('**/api/v1/**', async (route: Route) => {
    const request = route.request();
    const url = new URL(request.url());
    const path = url.pathname.replace(/^\/api\/v1/, '');
    const key = `${request.method()} ${path}`;
    const json = (status: number, body: unknown) => route.fulfill({ status, contentType: status >= 400 ? 'application/problem+json' : 'application/json', body: JSON.stringify(body) });
    if (key === 'GET /me') return json(200, { data: me });
    if (key === 'GET /me/contexts') return json(200, { data: [tile] });
    if (key === 'POST /me/context') return json(200, { data: { context: tile, role } });
    if (key === 'GET /me/todo' || key === 'GET /me/applications') return json(200, { data: [] });
    if (key === 'GET /academic-years') return json(200, { data: [{ id: 'Y1', label: '2026-2027', starts_on: '2026-01-01', ends_on: '2027-12-31', status: 'open', closed_at: null }], meta: { next_cursor: null, per_page: 50 } });
    if (key === 'GET /public/classes') return json(200, { data: [{ structure_id: 'C1', establishment_id: 'E1', label: 'CM2 A', level_codes: ['CM2'], twinned: false, shift: 'full_day', homeroom_staff_id: null, headcount: 60 }] });
    if (key === 'GET /public/classes/C1/pupils') return json(200, { data: ROSTER });
    if (key === `GET /public/classes/C1/attendance/${TODAY}`) return json(200, { data: { structure_id: 'C1', date: TODAY, headcount: 60, parts: [{ day_part: 'morning', taken: false }, { day_part: 'afternoon', taken: false }] } });
    if (key === `PUT /public/classes/C1/attendance/${TODAY}`) {
      seen.puts.push({ path, headers: request.headers(), body: request.postDataJSON() });
      return json(200, { data: { status: 'recorded' } });
    }
    seen.unhandled.push(key);
    return json(404, { type: 'about:blank', title: 'Introuvable', status: 404, detail: `Non simulé : ${key}`, trace_id: 'E2E' });
  });
  return seen;
}

async function typeCode(page: Page, group: number, digits: string): Promise<void> {
  const inputs = page.getByRole('group', { name: /Code personnel à 6 chiffres|Confirmez le code/ }).nth(group).getByRole('textbox');
  for (let i = 0; i < digits.length; i++) await inputs.nth(i).fill(digits[i]!);
}

test('enseignant : code personnel, appel de 60 élèves hors ligne en moins d’une minute, rejeu à la reconnexion', async ({ page, context }) => {
  const seen = await install(page, 'pub_class_teacher', TEACHER_PERMS);
  await page.goto('/ecole-publique/appel');

  // Appareil neuf : création du code, rien de nominatif avant.
  await expect(page.getByRole('heading', { name: 'Créez votre code personnel' })).toBeVisible();
  await expect(page.getByText('Élève00')).toHaveCount(0);
  await typeCode(page, 0, '482915');
  await typeCode(page, 1, '482915');
  await page.getByRole('button', { name: 'Créer le code' }).click();
  await expect(page.getByLabel('Classe', { exact: true })).toBeVisible();
  await expect(page.getByRole('list').locator('li.roster__row')).toHaveCount(60);

  const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
  expect(results.violations.filter((v) => v.impact === 'critical' || v.impact === 'serious')).toEqual([]);

  // Hors ligne : trois exceptions (un absent, un retard, un absent) puis validation.
  await context.setOffline(true);
  const started = Date.now();
  const rows = page.locator('li.roster__row .roster__btn');
  await rows.nth(4).click(); // absent
  await rows.nth(21).click();
  await rows.nth(21).click(); // retard
  await rows.nth(37).click(); // absent
  await expect(page.getByTestId('call-counts')).toHaveText('58 présents · 2 absents · 1 en retard');
  await page.getByRole('button', { name: /Valider l'appel \(60 élèves\)/ }).click();
  await expect(page.getByText("L'appel est enregistré sur le téléphone")).toBeVisible();
  expect(Date.now() - started, 'Appel de 60 élèves en moins de 60 secondes').toBeLessThan(60_000);
  await expect(page.getByTestId('pending-counter')).toContainText("1 appel en attente d'envoi");
  expect(seen.puts).toHaveLength(0);

  // Retour du réseau : rejeu automatique, une seule fois, exceptions seulement, établissement en en-tête.
  await context.setOffline(false);
  await expect.poll(() => seen.puts.length, { timeout: 40_000 }).toBe(1);
  const put = seen.puts[0]!;
  expect(put.headers['x-establishment-id']).toBe('E1');
  expect(put.body.day_part).toMatch(/morning|afternoon/);
  expect(put.body.records.map((r) => `${r.person_id}:${r.status}`).sort()).toEqual(['P04:A', 'P21:R', 'P37:A']);
  expect(seen.unhandled.filter((k) => !k.startsWith('GET /me'))).toEqual([]);
});

test('code personnel : cinq codes erronés effacent l’appareil', async ({ page }) => {
  await install(page, 'pub_class_teacher', TEACHER_PERMS);
  await page.goto('/ecole-publique/appel');
  await typeCode(page, 0, '135790');
  await typeCode(page, 1, '135790');
  await page.getByRole('button', { name: 'Créer le code' }).click();
  await expect(page.getByLabel('Classe', { exact: true })).toBeVisible();

  await page.reload(); // la clé ne survit pas au rechargement : le code est redemandé
  await expect(page.getByRole('heading', { name: 'Code personnel' })).toBeVisible();
  await typeCode(page, 0, '000000');
  await expect(page.getByText(/Il vous reste 4 essai/)).toBeVisible();
  for (let i = 0; i < 4; i++) {
    await typeCode(page, 0, '000000');
  }
  await expect(page.getByText('Ce téléphone a été effacé.')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Créez votre code personnel' })).toBeVisible();
});

test('rôle « agrégats seuls » : aucun écran nominatif', async ({ page }) => {
  await install(page, 'pub_cabinet', AGGREGATES_PERMS);
  for (const path of ['/ecole-publique/appel', '/ecole-publique/eleves', '/ecole-publique/personnel', '/ecole-publique/messages']) {
    await page.goto(path);
    await expect(page).toHaveURL(/\/interdit/);
  }
});

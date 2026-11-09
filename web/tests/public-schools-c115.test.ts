import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it } from 'vitest';
import { permissionsForRoles } from '@/lib/permissions';
import ClassesView from '@/views/public-schools/ClassesView.vue';
import AdminSettingsView from '@/views/admin/AdminSettingsView.vue';
import AdminTerritoryView from '@/views/admin/AdminTerritoryView.vue';
import { routes } from '@/router/routes';
import { flushPromises, freshEnvironment, jsonResponse, mockApi, mountView, signIn, teacherMe, tile, type RecordedCall } from './helpers';

// C-115 : défauts du primaire public trouvés par les tests de bout en bout (C-110).
const page = (items: unknown[]) => ({ data: items, meta: { next_cursor: null, per_page: 50 } });
const today = new Date();
const year = { id: 'Y1', label: '2026-2027', starts_on: `${today.getFullYear() - 1}-01-01`, ends_on: `${today.getFullYear() + 1}-12-31`, status: 'open', closed_at: null };
const classRow = { structure_id: 'C1', establishment_id: 'E1', label: 'CM2 A', level_codes: ['CM2'], twinned: false, shift: 'full_day', homeroom_staff_id: null, headcount: 20, capacity_alert: false, alert_threshold: 60 };
const paul = { id: 'PERS-PAUL', staff_id: 'STAFF-REC-1', name: 'Paul Fictif', grade: null, employment: null, public_status: null };
const headcount = { structure_id: 'C1', date: '2026-10-01', total: 0, by_sex: { female: 0, male: 0, unknown: 0 }, by_age: {} };

function director(routesMap: Record<string, unknown>): RecordedCall[] {
  signIn(teacherMe, [tile({ type: 'primary', permissions: [...permissionsForRoles(['pub_school_director'])], roles: [{ code: 'pub_school_director', label: 'Directeur' }] })], 'pub_school_director');
  return mockApi(routesMap as Parameters<typeof mockApi>[0]);
}

async function openAndAssign(routesMap: Record<string, unknown>) {
  const calls = director({
    'GET /academic-years': page([year]),
    'GET /public/classes': { data: [classRow] },
    'GET /public/staff': page([paul]),
    'GET /org-units': page([]),
    'GET /public/classes/C1/headcount': { data: headcount },
    'GET /public/classes/C1/homeroom-history': { data: [] },
    ...routesMap,
  });
  const { wrapper } = await mountView(ClassesView);
  await wrapper.findAll('button').find((b) => b.text() === 'Détails')!.trigger('click');
  await flushPromises();
  await wrapper.get('#hr-staff-C1').setValue('PERS-PAUL');
  await wrapper.get('#hr-staff-C1').element.closest('form')!.dispatchEvent(new Event('submit'));
  await flushPromises();
  return { calls, wrapper };
}

beforeEach(freshEnvironment);

describe('C110-PP1 : affectation du titulaire de classe', () => {
  it('envoie l’identifiant de personne de /public/staff (id), pas la fiche (staff_id)', async () => {
    const { calls, wrapper } = await openAndAssign({ 'PUT /public/classes/C1/homeroom': { data: { homeroom_staff_id: 'PERS-PAUL', homeroom_from: '2026-10-01' } } });
    const put = calls.find((c) => c.method === 'PUT' && c.path === '/public/classes/C1/homeroom');
    expect((put?.body as { staff_id: string }).staff_id).toBe('PERS-PAUL');
    expect(wrapper.text()).toContain('CM2 — Paul Fictif');
  });

  it('affiche le motif du refus, pas seulement « Données invalides »', async () => {
    const { wrapper } = await openAndAssign({
      'PUT /public/classes/C1/homeroom': jsonResponse({ type: 'x', title: 'Données invalides', status: 422, detail: 'Données invalides.', errors: { homeroom_staff_id: ['Enseignant inconnu ou inactif dans cette école.'] }, trace_id: 'T' }, 422),
    });
    expect(wrapper.get('[role="alert"]').text()).toContain('Enseignant inconnu ou inactif dans cette école.');
  });
});

describe('C110-PP3 : mode d’appel modifiable dans les réglages de l’établissement', () => {
  it('propose attendance.mode (créneau ou moment de la journée) et l’enregistre au niveau de l’établissement', async () => {
    signIn({ ...teacherMe, context: { establishment_id: 'E1', role: 'directeur' } }, [tile({ roles: [{ code: 'directeur', label: 'Directeur' }] })], 'directeur');
    const calls = mockApi({
      'GET /settings/effective': { data: [{ key: 'attendance.mode', value: 'day_part', source: 'establishment', overridden: [] }] },
      'PUT /settings': (c) => ({ data: { scope: 'establishment', key: c.body?.key, value: c.body?.value }, effective: { key: c.body?.key, value: c.body?.value, source: 'establishment', overridden: [] } }),
    });
    const { wrapper } = await mountView(AdminSettingsView, {}, '/administration/parametres');
    const mode = wrapper.get('[id="set-attendance.mode"]');
    expect((mode.element as HTMLSelectElement).value).toBe('day_part');
    expect(mode.text()).toContain('Par moment de la journée');
    await mode.setValue('slot');
    await mode.element.closest('form')?.dispatchEvent(new Event('submit', { cancelable: true }));
    await flushPromises();
    expect(calls.find((c) => c.method === 'PUT')?.body).toEqual({ scope: 'establishment', key: 'attendance.mode', value: 'slot' });
  });
});

describe('C110-PP2 : écran de rattachement territorial pour l’administrateur fonctionnel', () => {
  const region = { id: 'R1', type: 'region', code: 'NO', label_fr: 'Nord', label_en: 'North', parent_id: null, valid_from: '2025-01-01T00:00:00+01:00', valid_to: null };

  it('route réservée à organisation.territories.manage, que détient pub_functional_admin', () => {
    const route = routes.find((r) => r.name === 'admin-territory');
    expect(route?.meta?.perms).toEqual(['organisation.territories.manage']);
    expect(permissionsForRoles(['pub_functional_admin']).has('organisation.territories.manage')).toBe(true);
    expect(permissionsForRoles(['pub_school_director']).has('organisation.territories.manage')).toBe(false);
  });

  it('rattache l’établissement actif à la région, le jour même du début du territoire, sans en-tête d’établissement', async () => {
    signIn(teacherMe, [tile({ type: 'primary', permissions: [...permissionsForRoles(['pub_functional_admin'])], roles: [{ code: 'pub_functional_admin', label: 'Admin' }] })], 'pub_functional_admin');
    const calls = mockApi({
      'GET /territories': page([region]),
      'PUT /territories/R1/establishments': (c) => ({ data: { territory_id: 'R1', valid_from: '2024-12-31T23:00:00+00:00', establishment_ids: (c.body as { establishment_ids: string[] }).establishment_ids } }),
    });
    const { wrapper } = await mountView(AdminTerritoryView);
    await wrapper.get('#t-territory').setValue('R1');
    await wrapper.get('#t-from').setValue('2025-01-01');
    await wrapper.get('#t-territory').element.closest('form')!.dispatchEvent(new Event('submit'));
    await flushPromises();
    const put = calls.find((c) => c.method === 'PUT');
    expect(put?.body).toEqual({ establishment_ids: ['E1'], valid_from: '2025-01-01T00:00:00+01:00' });
    expect(put?.headers['X-Establishment-Id']).toBeUndefined();
    expect(wrapper.text()).toContain('Établissement rattaché à Nord');
  });
});

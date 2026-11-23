import 'fake-indexeddb/auto';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import AdminAuthorizationsView from '@/views/admin/AdminAuthorizationsView.vue';
import { describeError } from '@/api/errors';
import { ApiError } from '@/api/http';
import { routes } from '@/router/routes';
import { flushPromises, freshEnvironment, mockApi, mountView, signIn, teacherMe, tile } from './helpers';

// C-115 (défauts C110-AU1 et C110-NP1) : déclarer l'autorisation d'ouverture d'une classe ou section, et voir le vrai motif d'un refus.
beforeEach(freshEnvironment);
afterEach(() => vi.restoreAllMocks());

const adminMe = { ...teacherMe, context: { establishment_id: 'E1', role: 'directeur' } };
const adminTile = tile({ type: 'primary', roles: [{ code: 'directeur', label: 'Directeur' }] });
const structures = { data: [{ id: 'L1', org_unit_id: null, kind: 'level', name: 'CM2', capacity: null }, { id: 'C1', org_unit_id: null, kind: 'class', name: 'CM2 A', capacity: 60 }] };
const reason = "Inscription impossible : cette filière n'a pas d'autorisation valide à ce jour.";

describe('écran des autorisations d’ouverture', () => {
  beforeEach(() => signIn(adminMe, [adminTile], 'directeur'));

  it('est une route de l’administration réservée à organisation.manage', () => {
    const route = routes.find((r) => r.name === 'admin-authorizations');
    expect(route?.path).toBe('/administration/autorisations');
    expect(route?.meta?.perms).toEqual(['organisation.manage']);
  });

  it('signale les classes sans autorisation valide puis déclare l’autorisation (autorité, référence, validité) par POST /authorizations', async () => {
    const calls = mockApi({
      'GET /structures': structures,
      'GET /authorizations': { data: [] },
      'POST /authorizations': (c) => ({ data: { id: 'A1', covered_levels: null, ...(c.body as object) } }),
    });
    const { wrapper } = await mountView(AdminAuthorizationsView);
    expect(wrapper.text()).toContain('Ces structures refusent toute inscription aujourd’hui : CM2 A');

    await wrapper.get('#a-structure').setValue('C1');
    await wrapper.get('#a-authority').setValue('MINEDUB');
    await wrapper.get('#a-reference').setValue('ARR-EP-CM2-001');
    await wrapper.get('#a-from').setValue('2026-01-01');
    await wrapper.get('#a-to').setValue('2030-12-31');
    await wrapper.get('form').trigger('submit');
    await flushPromises();

    const post = calls.find((c) => c.method === 'POST' && c.path === '/authorizations');
    expect(post?.body).toEqual({ structure_id: 'C1', reference: 'ARR-EP-CM2-001', authority: 'MINEDUB', valid_from: '2026-01-01', valid_to: '2030-12-31' });
    expect(wrapper.text()).toContain('Autorisation enregistrée.');
    expect(wrapper.text()).not.toContain('Ces structures refusent');
  });

  it('refuse une fin de validité antérieure au début sans rien envoyer', async () => {
    const calls = mockApi({ 'GET /structures': structures, 'GET /authorizations': { data: [] } });
    const { wrapper } = await mountView(AdminAuthorizationsView);
    await wrapper.get('#a-structure').setValue('C1');
    await wrapper.get('#a-reference').setValue('X');
    await wrapper.get('#a-from').setValue('2026-09-01');
    await wrapper.get('#a-to').setValue('2026-01-01');
    await wrapper.get('form').trigger('submit');
    expect(wrapper.text()).toContain('La fin de validité ne peut pas précéder le début.');
    expect(calls.filter((c) => c.method === 'POST')).toHaveLength(0);
  });
});

describe('motif d’un refus 422', () => {
  it('remplace le détail générique « Données invalides. » par le motif du champ', () => {
    const error = new ApiError(422, { type: 'x', title: 'Données invalides', status: 422, detail: 'Données invalides.', errors: { structure_id: [reason] } } as never);
    expect(describeError(error, 'fr').message).toBe(reason);
  });

  it('garde un détail explicite du serveur', () => {
    const error = new ApiError(422, { type: 'x', title: 'Données invalides', status: 422, detail: 'Corrigez les champs.', errors: { amount_xaf: ['Montant requis.'] } } as never);
    expect(describeError(error, 'fr').message).toBe('Corrigez les champs.');
  });
});

import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import PlatformActivityView from '@/views/platform/PlatformActivityView.vue';
import PlatformUserView from '@/views/platform/PlatformUserView.vue';
import { clearPending } from '@/views/platform/useSensitiveAction';
import { maskAddress } from '@/api/platformBackoffice';
import { usePlatformStore } from '@/stores/platform';
import type { Me } from '@/api/types';
import { flushPromises, freshEnvironment, mockApi, mountView, signIn, teacherMe } from './helpers';

// C-112 point 3 : journal d'activité transversal, export, fiche utilisateur et déconnexion forcée.
beforeEach(() => {
  freshEnvironment();
  clearPending();
});

const platformMe: Me = { ...teacherMe, id: 'UP', person_id: 'PP', first_name: 'Awa', last_name: 'Ngo', greeting: 'Awa', context: { establishment_id: null, role: null } };
function signInPlatform(): void {
  signIn(platformMe, [], null);
  const p = usePlatformStore();
  p.isAdmin = true;
  p.checkedFor = 'UP';
}
const page = (items: unknown[], next: string | null = null) => ({ data: items, links: { next: null }, meta: { next_cursor: next } });

describe('suivi des actions', () => {
  it('masque téléphones et e-mails', () => {
    expect(maskAddress('+237690001234')).toBe('6•• •• •• 34');
    expect(maskAddress('paul@domaine.cm')).toBe('p•••@domaine.cm');
    expect(maskAddress(null)).toBe('—');
  });

  it('journal transversal : filtres envoyés et export CSV', async () => {
    signInPlatform();
    const calls = mockApi({
      'GET /platform/clients': { data: [{ id: 'T1', name: 'Institut du Littoral', status: 'active', created_at: '2026-10-01', applications: [] }] },
      'GET /platform/activity': page([{ id: 'T1:3', tenant_id: 'T1', tenant_name: 'Institut du Littoral', seq: 3, occurred_at: '2026-11-06T08:00:00+01:00', actor_id: 'U1', action: 'identity.login', object_type: 'user', object_id: 'U1', details: {}, ip: null }]),
      'GET /platform/activity/export': () => new Blob(['date;client'], { type: 'text/csv' }),
    });
    const createUrl = vi.fn(() => 'blob:x');
    Object.assign(URL, { createObjectURL: createUrl, revokeObjectURL: vi.fn() });
    const { wrapper } = await mountView(PlatformActivityView, {}, '/platform/activite');
    expect(wrapper.text()).toContain('identity.login');
    expect(wrapper.text()).toContain('Institut du Littoral');
    await wrapper.get('#act-action').setValue('identity.');
    await wrapper.get('#act-from').setValue('2026-11-01');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    const last = calls.filter((c) => c.path === '/platform/activity').at(-1);
    expect(last?.query['filter[action]']).toBe('identity.');
    expect(last?.query['filter[from]']).toBe('2026-11-01');
    await wrapper.get('[data-test="activity-export"]').trigger('click');
    await flushPromises();
    expect(calls.some((c) => c.path === '/platform/activity/export' && c.query['filter[action]'] === 'identity.')).toBe(true);
  });

  it('fiche utilisateur : coordonnées masquées et déconnexion forcée', async () => {
    signInPlatform();
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    const calls = mockApi({
      'GET /platform/clients/T1/users/U1': { data: {
        id: 'U1', person_id: 'P1', tenant_id: 'T1', first_name: 'Paul', last_name: 'Mballa', phone: '6•• •• •• 34', email: 'p•••@domaine.cm', status: 'active',
        roles: ['directeur'], two_factor_enabled: true, last_login_at: null, created_at: '2026-10-01',
        sessions: [{ id: 'S1', device: 'Chrome sur Android', created_at: '2026-11-06T08:00:00+01:00', last_used_at: null, expires_at: '2026-11-07T08:00:00+01:00' }],
        logins: [], actions: [], messages: [],
      } },
      'POST /platform/clients/T1/users/U1/logout': { data: { revoked_sessions: 1 } },
    });
    const { wrapper } = await mountView(PlatformUserView, { tenant: 'T1', user: 'U1' }, '/platform/clients/T1/utilisateurs/U1');
    expect(wrapper.get('[data-test="user-phone"]').text()).toBe('6•• •• •• 34');
    expect(wrapper.text()).toContain('Chrome sur Android');
    await wrapper.get('[data-test="force-logout"]').trigger('click');
    await flushPromises();
    expect(calls.some((c) => c.method === 'POST' && c.path === '/platform/clients/T1/users/U1/logout')).toBe(true);
    expect(wrapper.text()).toContain('1 session(s) fermée(s).');
  });
});

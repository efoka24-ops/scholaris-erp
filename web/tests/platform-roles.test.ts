import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it } from 'vitest';
import PlatformAdminsView from '@/views/platform/PlatformAdminsView.vue';
import { clearPending } from '@/views/platform/useSensitiveAction';
import { clearPlatformAccess, ensurePlatformAccess, platformAccess, platformCan } from '@/views/platform/platformAccess';
import { PLATFORM_ROLE_PERMISSIONS, platformPermissionsFor } from '@/lib/permissions';
import { routes } from '@/router/routes';
import { usePlatformStore } from '@/stores/platform';
import type { Me } from '@/api/types';
import { flushPromises, freshEnvironment, mockApi, mountView, signIn, teacherMe } from './helpers';

// C-112 point 5 : rôles fins de la plateforme (support, finance, audit), menus et écrans filtrés côté front.
beforeEach(() => {
  freshEnvironment();
  clearPending();
  clearPlatformAccess();
});

const platformMe: Me = { ...teacherMe, id: 'UP', person_id: 'PP', first_name: 'Awa', last_name: 'Ngo', greeting: 'Awa', context: { establishment_id: null, role: null } };

function signInPlatform(): void {
  signIn(platformMe, [], null);
  const p = usePlatformStore();
  p.isAdmin = true;
  p.checkedFor = platformMe.id;
}

const permsOf = (name: string): string[] => routes.find((r) => r.name === name)?.meta?.perms ?? [];

describe('rôles de la plateforme', () => {
  it('le miroir ne donne ni prix ni réglages au support, ni utilisateurs à la finance, aucune action à l’auditeur', () => {
    const support = platformPermissionsFor('platform_support');
    expect(support.has('platform.users.access_code')).toBe(true);
    expect(support.has('platform.pricing.manage')).toBe(false);
    expect(support.has('platform.settings.read')).toBe(false);
    const finance = platformPermissionsFor('platform_finance');
    expect(finance.has('platform.payments.manage')).toBe(true);
    expect(finance.has('platform.users.read')).toBe(false);
    const auditor = [...platformPermissionsFor('platform_auditor')];
    expect(auditor.filter((p) => /\.(manage|decide|send|retry|broadcast|logout|access_code)$/.test(p))).toEqual([]);
    expect(PLATFORM_ROLE_PERMISSIONS.platform_admin).toHaveLength(28);
  });

  it('les écrans du support : clients et journal oui, paiements et réglages non', async () => {
    mockApi({ 'GET /platform/me': { data: { user_id: 'UP', role: 'platform_support', permissions: [] } } });
    await ensurePlatformAccess('UP');
    expect(platformAccess.role).toBe('platform_support');
    expect(platformCan(...permsOf('platform-clients'))).toBe(true);
    expect(platformCan(...permsOf('platform-audit'))).toBe(true);
    expect(platformCan(...permsOf('platform-payments'))).toBe(false);
    expect(platformCan(...permsOf('platform-settings'))).toBe(false);
    expect(platformCan(...permsOf('platform-admins'))).toBe(false);
  });

  it('l’administrateur attribue un rôle depuis l’écran Administrateurs', async () => {
    signInPlatform();
    const calls = mockApi({
      'GET /platform/me': { data: { user_id: 'UP', role: 'platform_admin', permissions: [...PLATFORM_ROLE_PERMISSIONS.platform_admin] } },
      'GET /platform/administrators': {
        data: [{ id: 'U2', person_id: 'P2', first_name: 'Paul', last_name: 'Mballa', email: 'paul@scholaris.test', phone: null, status: 'active', two_factor_enabled: true, last_login_at: null, created_at: '2026-11-01', role: 'platform_support' }],
      },
      'PATCH /platform/administrators/U2/role': { data: { id: 'U2', role: 'platform_finance' } },
    });
    await ensurePlatformAccess('UP');
    const { wrapper } = await mountView(PlatformAdminsView, {}, '/platform/administrateurs');
    const select = wrapper.get('[data-test="role-select"]');
    expect((select.element as HTMLSelectElement).value).toBe('platform_support');
    await select.setValue('platform_finance');
    await flushPromises();
    const patch = calls.find((c) => c.method === 'PATCH');
    expect(patch?.path).toBe('/platform/administrators/U2/role');
    expect(patch?.body).toEqual({ role: 'platform_finance' });
  });

  it('un auditeur voit la liste sans aucune action', async () => {
    signInPlatform();
    mockApi({
      'GET /platform/me': { data: { user_id: 'UP', role: 'platform_auditor', permissions: [...PLATFORM_ROLE_PERMISSIONS.platform_auditor] } },
      'GET /platform/administrators': {
        data: [{ id: 'U2', person_id: 'P2', first_name: 'Paul', last_name: 'Mballa', email: 'paul@scholaris.test', phone: null, status: 'active', two_factor_enabled: true, last_login_at: null, created_at: '2026-11-01', role: 'platform_support' }],
      },
    });
    await ensurePlatformAccess('UP');
    const { wrapper } = await mountView(PlatformAdminsView, {}, '/platform/administrateurs');
    expect(wrapper.text()).toContain('Paul Mballa');
    expect(wrapper.text()).toContain('Support');
    expect(wrapper.find('[data-test="role-select"]').exists()).toBe(false);
    expect(wrapper.find('[data-test="access-code-action"]').exists()).toBe(false);
  });
});

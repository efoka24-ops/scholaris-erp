import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it } from 'vitest';
import PlatformKpiPanel from '@/views/platform/PlatformKpiPanel.vue';
import { usePlatformStore } from '@/stores/platform';
import type { Me } from '@/api/types';
import { freshEnvironment, mockApi, mountView, signIn, teacherMe } from './helpers';

// C-112 point 2 : indicateurs d'usage (cartes, histogramme doublé d'un tableau, usage par application, connectés).
beforeEach(() => freshEnvironment());

const platformMe: Me = { ...teacherMe, id: 'UP', person_id: 'PP', first_name: 'Awa', last_name: 'Ngo', greeting: 'Awa', context: { establishment_id: null, role: null } };
const counts = { sms: { sent: 12, delivered: 10, failed: 2 }, email: { sent: 4, failed: 1 } };

describe('indicateurs d’usage', () => {
  it('affiche connectés, connexions, SMS et la liste nominative', async () => {
    signIn(platformMe, [], null);
    const p = usePlatformStore();
    p.isAdmin = true;
    p.checkedFor = 'UP';
    mockApi({
      'GET /platform/kpi': { data: {
        as_of: '2026-11-06T09:00:00+01:00',
        online: { window_minutes: 15, total: 1, items: [{ tenant_id: 'T1', tenant_name: 'Institut du Littoral', user_id: 'U1', first_name: 'Paul', last_name: 'Mballa', role: 'directeur', since: '2026-11-06T08:00:00+01:00', last_seen_at: '2026-11-06T08:58:00+01:00', device: 'Chrome sur Android' }] },
        logins: { today: 3, week: 10, month: 40, series: Array.from({ length: 30 }, (_, i) => ({ date: `2026-10-${String(i + 1).padStart(2, '0')}`, count: i })) },
        active_users: { d7: 5, d30: 9 },
        security: { failed_logins_24h: 2, lockouts_24h: 1, locked_accounts: 1 },
        clients_activity: { inactive_days: 14, active: 1, inactive: 1, top_active: [{ tenant_id: 'T1', name: 'Institut du Littoral', actions_7d: 42, last_login_at: null, active: true }], least_active: [] },
        usage_by_app: [{ key: 'higher', label: 'IPES', clients: 1, establishments: 2, learners: 350, actions_7d: 42 }],
        conversion: { trials: 4, converted: 1, rate_percent: 25 },
        messages: { today: counts, month: counts, sms_quota_remaining: 800, sms_mode: 'live' },
      } },
    });
    const { wrapper } = await mountView(PlatformKpiPanel, {}, '/platform');
    expect(wrapper.get('[data-test="kpi-online"]').text()).toContain('1');
    expect(wrapper.get('[data-test="kpi-sms"]').text()).toContain('quota restant : 800');
    expect(wrapper.text()).toContain('25 %');
    expect(wrapper.text()).toContain('IPES');
    expect(wrapper.findAll('.bar')).toHaveLength(30);
    expect(wrapper.findAll('details tbody tr')).toHaveLength(30);
    expect(wrapper.get('[data-test="kpi-online-list"]').text()).toContain('Chrome sur Android');
  });
});

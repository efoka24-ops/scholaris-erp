import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import PlatformMessagesView from '@/views/platform/PlatformMessagesView.vue';
import { clearPending } from '@/views/platform/useSensitiveAction';
import { usePlatformStore } from '@/stores/platform';
import type { Me } from '@/api/types';
import { flushPromises, freshEnvironment, mockApi, mountView, signIn, teacherMe } from './helpers';

// C-112 point 4 : journal des SMS (masqué, relance), statistiques internes, envoi direct avec aperçu et coût.
beforeEach(() => {
  freshEnvironment();
  clearPending();
});

const platformMe: Me = { ...teacherMe, id: 'UP', person_id: 'PP', first_name: 'Awa', last_name: 'Ngo', greeting: 'Awa', context: { establishment_id: null, role: null } };
const page = (items: unknown[]) => ({ data: items, links: { next: null }, meta: { next_cursor: null } });

describe('suivi des messages', () => {
  it('journal des SMS, relance, messagerie interne puis envoi direct', async () => {
    signIn(platformMe, [], null);
    const p = usePlatformStore();
    p.isAdmin = true;
    p.checkedFor = 'UP';
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    const calls = mockApi({
      'GET /platform/messages/deliveries': page([{ id: 'D1', tenant_id: 'T1', tenant_name: 'Institut du Littoral', channel: 'sms', recipient: '6•• •• •• 34', template_key: 'absence_alert', status: 'failed', reason: 'provider_failed', provider_reference: 'teeeotp-42', attempts: 1, created_at: '2026-11-06T08:00:00+01:00', sent_at: null, delivered_at: null }]),
      'POST /platform/messages/deliveries/T1/D1/retry': { data: { id: 'D1', status: 'queued' } },
      'GET /platform/messages/internal': { data: [{ tenant_id: 'T1', tenant_name: 'Institut du Littoral', conversations: 3, messages: 12, hidden: 1 }] },
      'GET /platform/clients': { data: [{ id: 'T1', name: 'Institut du Littoral', status: 'active', created_at: '2026-10-01', applications: [] }] },
      'GET /platform/applications': { data: [] },
      'GET /platform/clients/T1/users': { data: [{ id: 'U1', person_id: 'P1', first_name: 'Paul', last_name: 'Mballa', email: null, phone: '+237690001234', status: 'active', roles: [], two_factor_enabled: false, last_login_at: null }] },
      'POST /platform/messages/direct/preview': { data: { recipients: 1, reachable: 1, sample: [{ tenant_id: 'T1', first_name: 'Paul', address: '6•• •• •• 34' }], sms_segments: 1, estimated_cost_xaf: 25, max_recipients: 500, bulk: false, email_html: null } },
      'POST /platform/messages/direct': { data: { batch_id: 'B1', recipients: 1, sent: 1, failed: 0 } },
    });
    const { wrapper } = await mountView(PlatformMessagesView, {}, '/platform/messages');
    expect(wrapper.text()).toContain('6•• •• •• 34');
    expect(wrapper.text()).toContain('provider_failed');
    await wrapper.get('[data-test="retry-D1"]').trigger('click');
    await flushPromises();
    expect(calls.some((c) => c.method === 'POST' && c.path === '/platform/messages/deliveries/T1/D1/retry')).toBe(true);

    await wrapper.get('[data-test="tab-internal"]').trigger('click');
    await flushPromises();
    expect(wrapper.get('[data-test="internal-stats"]').text()).toContain('12');

    await wrapper.get('[data-test="tab-send"]').trigger('click');
    await flushPromises();
    await wrapper.get('#dm-tenant').setValue('T1');
    await flushPromises();
    await wrapper.get('#dm-user').setValue('U1');
    await wrapper.get('#dm-subject').setValue('Maintenance');
    await wrapper.get('#dm-body').setValue('Coupure samedi soir.');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(wrapper.get('[data-test="dm-preview-result"]').text()).toContain('25');
    await wrapper.get('[data-test="dm-send"]').trigger('click');
    await flushPromises();
    const sent = calls.find((c) => c.method === 'POST' && c.path === '/platform/messages/direct');
    expect(sent?.body).toEqual({ target_type: 'person', tenant_id: 'T1', user_id: 'U1', channel: 'sms', subject: 'Maintenance', body: 'Coupure samedi soir.' });
    expect(wrapper.text()).toContain('1 envoyé(s), 0 en échec');
  });
});

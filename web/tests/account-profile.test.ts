import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it } from 'vitest';
import ProfileView from '@/views/account/ProfileView.vue';
import { flushPromises, freshEnvironment, mockApi, mountView, signIn, teacherMe } from './helpers';

// C-112 point 6 : « Mon profil » (identité, sessions, notifications).
beforeEach(() => freshEnvironment());

const profile = (over: Record<string, unknown> = {}) => ({
  user_id: 'U1', first_name: 'Paul', last_name: 'Mballa', phone: '+237690001234', email: 'paul@client.test', locale: 'fr', two_factor_enabled: true, last_login_at: null,
  platform_member: false,
  sessions: [{ id: 'S1', device: 'Chrome', created_at: '2026-11-06T08:00:00+01:00', last_used_at: null, expires_at: '2026-11-06T16:00:00+01:00', current: true },
    { id: 'S2', device: 'Firefox', created_at: '2026-11-05T08:00:00+01:00', last_used_at: null, expires_at: '2026-11-06T16:00:00+01:00', current: false }],
  preferences: { channels: { app: true, sms: false, email: false }, daily_digest: false },
  ...over,
});

describe('mon profil', () => {
  it('modifie l’identité, coche un canal et déconnecte les autres appareils', async () => {
    signIn(teacherMe, [], null);
    let current = profile();
    const calls = mockApi({
      'GET /me/profile': () => ({ data: current }),
      'PATCH /me/profile': (c) => ({ data: (current = profile({ ...(c.body as object) })) }),
      'PATCH /me/preferences': () => ({ data: (current = profile({ preferences: { channels: { app: true, sms: false, email: true }, daily_digest: false } })) }),
      'POST /me/sessions/logout-others': () => {
        current = profile({ sessions: [current.sessions[0]] });
        return { data: { revoked_sessions: 1 } };
      },
    });
    const { wrapper } = await mountView(ProfileView, {}, '/mon-profil');
    expect((wrapper.get('#pf-first').element as HTMLInputElement).value).toBe('Paul');
    expect(wrapper.text()).toContain('Cet appareil');
    expect(wrapper.find('[data-test="pref-digest"]').exists()).toBe(false);
    await wrapper.get('#pf-last').setValue('Mballa Ndi');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(calls.find((c) => c.method === 'PATCH' && c.path === '/me/profile')?.body).toMatchObject({ last_name: 'Mballa Ndi', phone: '+237690001234' });
    await wrapper.get('[data-test="pref-email"]').setValue(true);
    await flushPromises();
    expect(calls.find((c) => c.path === '/me/preferences')?.body).toEqual({ notify_email: true });
    await wrapper.get('[data-test="logout-others"]').trigger('click');
    await flushPromises();
    expect(wrapper.text()).toContain('1 autre(s) session(s) fermée(s).');
  });
});

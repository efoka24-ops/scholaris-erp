import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it } from 'vitest';
import PlatformUserView from '@/views/platform/PlatformUserView.vue';
import { clearPending } from '@/views/platform/useSensitiveAction';
import { usePlatformStore } from '@/stores/platform';
import type { Me } from '@/api/types';
import { flushPromises, freshEnvironment, mockApi, mountView, signIn, teacherMe } from './helpers';

// MDP-PROV : mot de passe provisoire donné depuis la fiche d'un utilisateur (case, envoi, confirmation, affichage unique).
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

const card = (overrides: Record<string, unknown> = {}) => ({ data: {
  id: 'U1', person_id: 'P1', tenant_id: 'T1', first_name: 'Paul', last_name: 'Mballa', phone: '6•• •• •• 34', email: 'p•••@domaine.cm', status: 'active',
  roles: ['directeur'], two_factor_enabled: true, last_login_at: null, created_at: '2026-10-01', sessions: [], logins: [], actions: [], messages: [],
  provisional_password: { channels: ['email'], require_change_default: false },
  ...overrides,
} });

const issued = {
  data: {
    password: 'Kp7mRw3xQa9z',
    login: 'paul@domaine.cm',
    must_change_password: true,
    two_factor_enabled: true,
    delivery: { channel: 'email', status: 'sent', masked_to: 'p•••@domaine.cm' },
    share: {
      establishment_name: 'Institut du Littoral',
      login: 'paul@domaine.cm',
      login_url: 'https://app.scholaris.test/connexion',
      locale: 'fr',
      channels: ['email'],
      message: { subject: 'SCHOLARIS : votre mot de passe provisoire', text: 'Bonjour Paul,\nMot de passe : Kp7mRw3xQa9z', sms: 'x' },
    },
  },
};

describe('mot de passe provisoire', () => {
  it('case, envoi selon les coordonnées, confirmation puis affichage unique', async () => {
    signInPlatform();
    const calls = mockApi({
      'GET /platform/clients/T1/users/U1': card(),
      'POST /platform/clients/T1/users/U1/provisional-password': issued,
    });
    const { wrapper } = await mountView(PlatformUserView, { tenant: 'T1', user: 'U1' }, '/platform/clients/T1/utilisateurs/U1');
    await wrapper.get('[data-test="give-password"]').trigger('click');
    expect(wrapper.text()).toContain('Exiger un nouveau mot de passe à la connexion');
    const box = wrapper.get('[data-test="pwd-require-change"]').element as HTMLInputElement;
    expect(box.checked).toBe(false);
    expect((wrapper.get('[data-test="pwd-send-sms"]').element as HTMLInputElement).disabled).toBe(true);
    expect((wrapper.get('[data-test="pwd-send-email"]').element as HTMLInputElement).disabled).toBe(false);

    await wrapper.get('[data-test="pwd-require-change"]').setValue(true);
    await wrapper.get('[data-test="pwd-send-email"]').setValue(true);
    await wrapper.get('[data-test="pwd-give"]').trigger('submit');
    await flushPromises();
    expect(calls.some((c) => c.method === 'POST')).toBe(false);
    await wrapper.get('[data-test="pwd-confirm"]').trigger('click');
    await flushPromises();

    const post = calls.find((c) => c.method === 'POST' && c.path === '/platform/clients/T1/users/U1/provisional-password');
    expect(post?.body).toEqual({ send: 'email', require_change: true });
    expect(wrapper.get('[data-test="provisional-password"]').text()).toBe('Kp7mRw3xQa9z');
    expect(wrapper.text()).toContain('Ce mot de passe ne sera plus affiché');
    expect(wrapper.text()).toContain('Le code de double authentification reste demandé.');
    expect(wrapper.get('[data-test="pwd-delivery"]').text()).toContain('p•••@domaine.cm');
    expect((wrapper.get('[data-test="pwd-message"]').element as HTMLTextAreaElement).value).toContain('Kp7mRw3xQa9z');
    expect(wrapper.find('[data-test="copy-password"]').exists()).toBe(true);
  });

  it('case cochée par défaut selon le réglage, envoi impossible sans coordonnée', async () => {
    signInPlatform();
    mockApi({ 'GET /platform/clients/T1/users/U1': card({ provisional_password: { channels: [], require_change_default: true } }) });
    const { wrapper } = await mountView(PlatformUserView, { tenant: 'T1', user: 'U1' }, '/platform/clients/T1/utilisateurs/U1');
    await wrapper.get('[data-test="give-password"]').trigger('click');
    expect((wrapper.get('[data-test="pwd-require-change"]').element as HTMLInputElement).checked).toBe(true);
    expect((wrapper.get('[data-test="pwd-send-email"]').element as HTMLInputElement).disabled).toBe(true);
  });

  it('aucun bouton pour un compte bloqué', async () => {
    signInPlatform();
    mockApi({ 'GET /platform/clients/T1/users/U1': card({ status: 'locked' }) });
    const { wrapper } = await mountView(PlatformUserView, { tenant: 'T1', user: 'U1' }, '/platform/clients/T1/utilisateurs/U1');
    expect(wrapper.find('[data-test="give-password"]').exists()).toBe(false);
  });
});

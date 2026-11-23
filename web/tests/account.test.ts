import { beforeEach, describe, expect, it, vi } from 'vitest';
import LoginView from '@/views/LoginView.vue';
import ChangePasswordView from '@/views/account/ChangePasswordView.vue';
import PasswordConfirmView from '@/views/account/PasswordConfirmView.vue';
import TwoFactorView from '@/views/TwoFactorView.vue';
import { guard } from '@/router';
import { useAuthStore } from '@/stores/auth';
import { flushPromises, freshEnvironment, jsonResponse, mockApi, mountView, signIn, teacherMe } from './helpers';

beforeEach(freshEnvironment);

const user = { id: 'U1', person_id: 'P1', first_name: 'A', last_name: 'B', locale: 'fr', status: 'active' };
const noTwoFactor = { ...teacherMe, two_factor: { enabled: false, required: false, verified: true } };

describe('connexion simple (C-001)', () => {
  it('envoie vers le changement de mot de passe quand le mot de passe est provisoire', async () => {
    mockApi({
      'POST /auth/login': { token_type: 'Bearer', expires_at: 'x', new_device: false, two_factor_required: false, two_factor_setup_required: false, password_change_required: true, user },
      'GET /me': { data: { ...noTwoFactor, password_change_required: true } },
    });
    const { wrapper, router } = await mountView(LoginView, {}, '/connexion');
    await wrapper.get('#identifier').setValue('paul@ecole.test');
    await wrapper.get('#password').setValue('Xk7mQp4vRa9d');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('change-password'), { timeout: 8000 });
  });

  it('le routeur ne laisse rien ouvert tant que le mot de passe provisoire n\'est pas remplacé', async () => {
    signIn({ ...noTwoFactor, password_change_required: true });
    const { router } = await mountView(ChangePasswordView, {}, '/changer-mot-de-passe');
    const to = router.resolve('/');
    expect(await guard(to as never)).toEqual({ name: 'change-password' });
    expect(useAuthStore().passwordChangeRequired).toBe(true);
  });

  it('change le mot de passe puis ouvre l\'accueil', async () => {
    signIn({ ...noTwoFactor, password_change_required: true });
    const calls = mockApi({
      'POST /auth/change-password': { changed: true, password_change_required: false },
      'GET /me': { data: noTwoFactor },
    });
    const { wrapper, router } = await mountView(ChangePasswordView, {}, '/changer-mot-de-passe');
    await wrapper.get('#cp-current').setValue('Xk7mQp4vRa9d');
    await wrapper.get('#cp-new').setValue('Girafe-Bleue-Soleil-58');
    await wrapper.get('#cp-confirm').setValue('autre');
    await wrapper.get('form').trigger('submit');
    expect(calls.some((c) => c.path === '/auth/change-password')).toBe(false);
    await wrapper.get('#cp-confirm').setValue('Girafe-Bleue-Soleil-58');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(calls.find((c) => c.path === '/auth/change-password')?.body).toEqual({
      current_password: 'Xk7mQp4vRa9d',
      new_password: 'Girafe-Bleue-Soleil-58',
      new_password_confirmation: 'Girafe-Bleue-Soleil-58',
    });
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('home'), { timeout: 8000 });
  });

  it('confirme par mot de passe puis revient à l\'écran d\'origine', async () => {
    signIn(noTwoFactor);
    const calls = mockApi({ 'POST /auth/reconfirm': { confirmed: true, confirmed_at: 'x', valid_for: 600 } });
    const { wrapper, router } = await mountView(PasswordConfirmView, {}, '/confirmation?redirect=/notes');
    await wrapper.get('#pc-password').setValue('Mon-mot-de-passe-1');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(calls.find((c) => c.path === '/auth/reconfirm')?.body).toEqual({ password: 'Mon-mot-de-passe-1' });
    await vi.waitFor(() => expect(router.currentRoute.value.fullPath).toBe('/notes'), { timeout: 8000 });
  });

  it('affiche les essais restants quand le mot de passe est faux', async () => {
    signIn(noTwoFactor);
    mockApi({ 'POST /auth/reconfirm': jsonResponse({ type: 'https://api.scholaris.cm/errors/invalid-password', title: 'Mot de passe incorrect', status: 422, detail: 'Mot de passe incorrect.', attempts_left: 3, trace_id: 'T' }, 422) });
    const { wrapper } = await mountView(PasswordConfirmView, {}, '/confirmation');
    await wrapper.get('#pc-password').setValue('faux');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(wrapper.text()).toContain('Il vous reste 3 essai(s)');
  });

  it('l\'écran de double authentification renvoie vers la confirmation par mot de passe pour qui n\'a pas de 2FA', async () => {
    signIn(noTwoFactor);
    mockApi({});
    const { router } = await mountView(TwoFactorView, {}, '/double-authentification?redirect=/notes');
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('reconfirm'), { timeout: 8000 });
    expect(router.currentRoute.value.query.redirect).toBe('/notes');
  });
});

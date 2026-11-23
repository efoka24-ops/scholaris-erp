import 'fake-indexeddb/auto';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import { routes } from '@/router/routes';
import { guard } from '@/router';
import { forbiddenReason } from '@/lib/forbidden';
import { setLocale } from '@/i18n';
import ForbiddenView from '@/views/ForbiddenView.vue';
import { useContextStore } from '@/stores/context';
import { mockApi, mountView, signIn, teacherMe, tile, freshEnvironment } from './helpers';

// IPES-ADMIN : l'administrateur du client (super_admin) sans rôle IPES voyait « Accès refusé » brut sur /superieur/structure.
beforeEach(freshEnvironment);
afterEach(() => vi.restoreAllMocks());

const adminMe = { ...teacherMe, context: { establishment_id: 'E1', role: 'super_admin' } };
const superOnly = { code: 'super_admin', label: 'Super administrateur', permissions: ['higher.access', 'organisation.manage'] };
const academic = { code: 'higher_dir_academique', label: 'Directeur académique', permissions: ['higher.access', 'higher.settings.read', 'higher.enrolment.read'] };

function run(path: string): Promise<unknown> {
  const router = createRouter({ history: createMemoryHistory(), routes });
  return guard(router.resolve(path) as never);
}

describe('motif d’un écran d’application refusé', () => {
  it('sans aucun rôle de l’application : motif no_app_role', async () => {
    signIn(adminMe, [tile({ type: 'higher', roles: [superOnly] })], 'super_admin');
    expect(await run('/superieur/structure')).toEqual({ name: 'forbidden', query: { reason: 'no_app_role' } });
  });

  it('un autre rôle de la personne ouvre l’écran : motif other_role, avec le rôle et la page demandée', async () => {
    signIn(adminMe, [tile({ type: 'higher', roles: [superOnly, academic] })], 'super_admin');
    expect(await run('/superieur/structure')).toEqual({ name: 'forbidden', query: { reason: 'other_role', role: 'higher_dir_academique', from: '/superieur/structure' } });
  });

  it('garde le refus simple hors des écrans d’application et quand la personne a déjà un rôle de l’application', async () => {
    signIn(teacherMe);
    expect(await run('/administration')).toEqual({ name: 'forbidden' });
    expect(forbiddenReason(['higher'], ['higher.billing.read'], [academic], 'higher_dir_academique')).toBeNull();
    expect(forbiddenReason(undefined, ['higher.settings.read'], [superOnly], 'super_admin')).toBeNull();
  });
});

describe('écran Accès refusé', () => {
  it('explique qu’il manque un rôle dans l’application (fr et en)', async () => {
    signIn(adminMe, [tile({ type: 'higher', roles: [superOnly] })], 'super_admin');
    const { wrapper } = await mountView(ForbiddenView, {}, '/interdit?reason=no_app_role');
    expect(wrapper.get('[data-test="no-app-role"]').text()).toBe(
      "Votre compte n'a pas encore de rôle dans cette application. Demandez à l'administrateur de vous l'attribuer (Administration > Utilisateurs et rôles).",
    );
    setLocale('en');
    await wrapper.vm.$nextTick();
    expect(wrapper.get('[data-test="no-app-role"]').text()).toContain('Your account does not have a role in this application yet');
  });

  it('propose de passer au rôle qui ouvre l’écran puis y retourne', async () => {
    signIn(adminMe, [tile({ type: 'higher', roles: [superOnly, academic] })], 'super_admin');
    const calls = mockApi({ 'POST /me/context': { data: { context: tile({ type: 'higher', roles: [superOnly, academic] }), role: 'higher_dir_academique' } } });
    const { wrapper, router } = await mountView(ForbiddenView, {}, '/interdit?reason=other_role&role=higher_dir_academique&from=%2Fsuperieur%2Fstructure');
    expect(wrapper.get('[data-test="other-role"]').text()).toContain('« Directeur académique »');
    const replace = vi.spyOn(router, 'replace').mockResolvedValue(undefined);
    await wrapper.get('button').trigger('click');
    await new Promise((r) => setTimeout(r, 20));
    expect(calls.find((c) => c.method === 'POST' && c.path === '/me/context')?.body).toEqual({ establishment_id: 'E1', role: 'higher_dir_academique' });
    expect(useContextStore().activeRole).toBe('higher_dir_academique');
    expect(replace).toHaveBeenCalledWith('/superieur/structure');
  });
});

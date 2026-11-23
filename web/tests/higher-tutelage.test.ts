import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import { mount } from '@vue/test-utils';
import type { Component } from 'vue';
import { routes } from '@/router/routes';
import { higherTutelageRoutes } from '@/views/higher/tutelage/routes';
import ConventionsView from '@/views/higher/tutelage/ConventionsView.vue';
import TransmissionsView from '@/views/higher/tutelage/TransmissionsView.vue';
import DiplomaFilesView from '@/views/higher/tutelage/DiplomaFilesView.vue';
import PortalView from '@/views/higher/tutelage/PortalView.vue';
import { flushPromises, freshEnvironment } from './helpers';
import { find, page, problem, setup } from './higher-teaching-helpers';

beforeEach(() => freshEnvironment());

const PERMS = ['higher.access', 'higher.tutelage.manage', 'higher.tutelage.transmit'];
const programs = page([
  { id: 'PR1', school_id: 'S1', department_id: 'D1', code: 'LIC-GE', name: 'Licence gestion', regime: 'lmd', requires_tutelage: true },
  { id: 'PR2', school_id: 'S1', department_id: 'D1', code: 'BTS-GE', name: 'BTS gestion', regime: 'bts', requires_tutelage: false },
]);
const convention = (over: Record<string, unknown> = {}) => ({
  tutelage_id: 'T1', program_id: 'PR1', program_name: 'Licence gestion', academic_year_id: 'Y1', university_name: 'Université de Ngaoundéré', convention_reference: 'CONV-2026-01',
  convention_ends_on: '2026-11-15', approved_programme_ref: 'ARR-12', days_left: 30, expiring: true, expired: false, ...over,
});
const base = { 'GET /higher/programs': programs };

async function mountAt(component: Component, path: string) {
  const router = createRouter({ history: createMemoryHistory(), routes: [...routes, ...higherTutelageRoutes] });
  await router.push(path);
  await router.isReady();
  const wrapper = mount(component, { global: { plugins: [router] } });
  await flushPromises();
  return wrapper;
}

describe('IPES tutelle : routes', () => {
  it('déclare quatre écrans sous /superieur/tutelle, gardés et réservés aux IPES', () => {
    expect(higherTutelageRoutes).toHaveLength(4);
    for (const r of higherTutelageRoutes) {
      expect(r.path.startsWith('/superieur/tutelle')).toBe(true);
      expect(r.beforeEnter).toBeTypeOf('function');
      expect(r.meta?.establishmentTypes).toEqual(['higher']);
    }
  });
});

describe('IPES conventions de tutelle', () => {
  it('liste la convention avec son échéance et l\'alerte d\'expiration', async () => {
    setup(PERMS, { ...base, 'GET /higher/tutelage/convention': convention() });
    const wrapper = await mountAt(ConventionsView, '/superieur/tutelle');
    const row = wrapper.get('[data-test="convention-row"]');
    expect(row.text()).toContain('Université de Ngaoundéré');
    expect(row.text()).toContain('30 jour(s) restant(s)');
    expect(row.text()).toContain('bientôt expirée');
  });
});

describe('IPES transmissions', () => {
  it('liste les transmissions et affiche le refus 409 tant que le PV n\'est pas signé', async () => {
    const calls = setup(PERMS, {
      ...base,
      'GET /higher/tutelage/transmissions': page([{ id: 'TR1', program_id: 'PR1', academic_year_id: 'Y1', university_name: 'Université de Ngaoundéré', transmission_kind: 'lists', status: 'sent', items_count: 40, sent_at: '2026-10-10' }]),
      'POST /higher/tutelage/transmissions': problem(409, 'Le procès-verbal de délibération n\'est pas signé.'),
    });
    const wrapper = await mountAt(TransmissionsView, '/superieur/tutelle/transmissions');
    expect(wrapper.get('[data-test="transmission-row"]').text()).toContain('Liste des inscrits');
    await wrapper.get('#tt-kind').setValue('minutes');
    await wrapper.get('#tt-program').setValue('PR1');
    await wrapper.get('#tt-year').setValue('Y1');
    await wrapper.get('[data-test="transmit"]').trigger('submit');
    await flushPromises();
    expect(find(calls, 'POST', '/higher/tutelage/transmissions')?.body).toMatchObject({ kind: 'minutes', program_id: 'PR1' });
    expect(wrapper.get('[data-test="refused"]').text()).toContain('n\'est pas signé');
  });
});

describe('IPES dossiers de diplôme', () => {
  it('explique clairement le refus 409 RG-TUT-2', async () => {
    setup(PERMS, {
      ...base,
      'GET /people': page([{ id: 'P1', first_name: 'Marie', last_name: 'Nga' }]),
      'GET /higher/tutelage/diploma-files': page([]),
      'POST /higher/tutelage/diploma-files': problem(409, 'RG-TUT-2', { errors: { person_id: ['PV final non signé.', 'Quitus absent.'] } }),
    });
    const wrapper = await mountAt(DiplomaFilesView, '/superieur/tutelle/dossiers-diplome');
    const form = wrapper.get('[data-test="file-form"]');
    expect(form.text()).toContain('RG-TUT-2');
    await form.get('input[type="search"]').setValue('Nga');
    await form.get('.picker__row button').trigger('click');
    await flushPromises();
    await form.get('.hi-list button').trigger('click');
    await form.get('#tf-program').setValue('PR1');
    await form.get('#tf-year').setValue('Y1');
    await form.trigger('submit');
    await flushPromises();
    const notice = wrapper.get('[data-test="rg-tut-2"]');
    expect(notice.text()).toContain('PV final non signé.');
    expect(notice.text()).toContain('Quitus absent.');
  });

  it('propose la suite permise du dossier transmis', async () => {
    const calls = setup(PERMS, {
      ...base,
      'GET /higher/tutelage/diploma-files': page([{ id: 'F1', program_id: 'PR1', academic_year_id: 'Y1', person_id: 'P1', university_name: 'Université', decision_code: 'ADMIS', status: 'transmitted' }]),
      'PATCH /higher/tutelage/diploma-files/F1': { id: 'F1', status: 'in_progress' },
    });
    const wrapper = await mountAt(DiplomaFilesView, '/superieur/tutelle/dossiers-diplome');
    await wrapper.get('[data-test="file-row"] button').trigger('click');
    await flushPromises();
    expect(find(calls, 'PATCH', '/higher/tutelage/diploma-files/F1')?.body).toMatchObject({ status: 'in_progress' });
  });
});

describe('IPES accès de l\'université', () => {
  it('affiche les inscrits en lecture et le journal des consultations', async () => {
    setup([...PERMS, 'higher.tutelage.read'], {
      ...base,
      'GET /higher/tutelage/portal/enrolments': { convention: convention(), data: [{ matricule: 'IPES-25-0001', last_name: 'NGA', first_names: 'Marie', level_code: 'L1' }] },
      'GET /higher/tutelage/consultations': page([{ id: 'K1', user_id: 'U9', program_id: 'PR1', academic_year_id: 'Y1', resource_kind: 'enrolments', rows_count: 12, created_at: '2026-10-12' }]),
    });
    const wrapper = await mountAt(PortalView, '/superieur/tutelle/acces-universite');
    expect(wrapper.get('[data-test="journal-row"]').text()).toContain('enrolments');
    await wrapper.get('#tp-program').setValue('PR1');
    await wrapper.get('#tp-year').setValue('Y1');
    await wrapper.get('[data-test="portal-form"]').trigger('submit');
    await flushPromises();
    expect(wrapper.get('[data-test="portal-row"]').text()).toContain('NGA');
  });

  it('indique que l\'accès n\'est pas ouvert (404)', async () => {
    setup([...PERMS, 'higher.tutelage.read'], { ...base, 'GET /higher/tutelage/consultations': page([]), 'GET /higher/tutelage/portal/enrolments': problem(404, 'Accès de la tutelle non ouvert.') });
    const wrapper = await mountAt(PortalView, '/superieur/tutelle/acces-universite');
    await wrapper.get('#tp-program').setValue('PR1');
    await wrapper.get('#tp-year').setValue('Y1');
    await wrapper.get('[data-test="portal-form"]').trigger('submit');
    await flushPromises();
    expect(wrapper.get('[data-test="portal-closed"]').text()).toContain('pas ouvert');
  });
});

import { beforeEach, describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import type { Component } from 'vue';
import { routes } from '@/router/routes';
import type { ContextTile } from '@/api/types';
import type { Defense, Internship, MyInternship } from '@/api/higher-internships';
import { higherInternshipsRoutes } from '@/views/higher/internships/routes';
import { higherInternshipsFr } from '@/i18n/higher-internships-fr';
import { higherInternshipsEn } from '@/i18n/higher-internships-en';
import ConventionsView from '@/views/higher/internships/ConventionsView.vue';
import ConventionView from '@/views/higher/internships/ConventionView.vue';
import DefensesView from '@/views/higher/internships/DefensesView.vue';
import MyInternshipView from '@/views/higher/internships/MyInternshipView.vue';
import { flushPromises, freshEnvironment, jsonResponse, mockApi, signIn, teacherMe, tile, type RecordedCall } from './helpers';

const OFFICER = ['higher.access', 'higher.internship.manage', 'higher.defense.manage'];

function higherTile(permissions: string[] = OFFICER, role = 'higher_resp_stages'): ContextTile {
  return { ...tile({ type: 'higher', roles: [{ code: role, label: role }] }), permissions } as ContextTile;
}

const base = {
  'GET /me/applications': { data: [{ app_key: 'higher', state: 'active' }] },
  'GET /higher/me': { data: { application: { key: 'higher', state: 'active', writable: true }, establishment_id: 'E1', roles: ['higher_resp_stages'], all_schools: true, school_ids: [], school_selector: false } },
  'GET /higher/internship-companies': { data: [{ id: 'CO1', name: 'Brasseries', sector: null, city: 'Douala', address: null, phone: null, email: null, contact_name: null, active: true }], meta: { next_cursor: null } },
};

function internship(over: Partial<Internship> = {}): Internship {
  return {
    id: 'I1', enrolment_id: 'EN1', person_id: 'P1', academic_year_id: 'Y1', school_id: 'S1', department_id: 'D1', program_id: 'PR1', level_id: 'L2', group_id: null,
    company_id: 'CO1', subject: 'Contrôle de gestion', starts_on: '2026-06-01', ends_on: '2026-08-31', company_tutor_name: 'M. Tchana', company_tutor_email: null,
    company_tutor_phone: null, supervisor_person_id: 'SUP', supervisor_name: 'Dr Kamga', status: 'draft', convention_document_id: null,
    signatures: { student: null, company: null, school: null }, subject_validated_at: null, completion_received_on: null, thesis_title: null, thesis_ref: null,
    thesis_deposited_at: null, cancel_reason: null, ...over,
  };
}

const readiness = { conditions: { convention_signed: false, completion_certificate: false, thesis_deposited: false, financial_clearance: false }, missing: ['convention_signed'], ready: false };

function defense(over: Partial<Defense> = {}): Defense {
  return {
    id: 'DF1', internship_id: 'I1', person_id: 'P1', school_id: 'S1', program_id: 'PR1', level_id: 'L2', held_on: '2026-10-20', start_time: '09:00', end_time: '10:00',
    room: 'Amphi A', status: 'scheduled', grade: null, remarks: null, convocation_document_id: null, minutes_document_id: null,
    jury: [{ person_id: 'J1', jury_role: 'president', display_name: 'Pr Abena' }, { person_id: 'SUP', jury_role: 'supervisor', display_name: 'Dr Kamga' }, { person_id: 'J2', jury_role: 'member', display_name: 'M. Fouda' }],
    ...over,
  };
}

async function mountAt(component: Component, path: string, props: Record<string, unknown> = {}) {
  const router = createRouter({ history: createMemoryHistory(), routes: [...routes, ...higherInternshipsRoutes] });
  await router.push(path);
  await router.isReady();
  const wrapper = mount(component, { props, global: { plugins: [router] } });
  await flushPromises();
  return { wrapper, router };
}

const problem = (status: number, slug: string, detail: string) =>
  jsonResponse({ type: `https://api.scholaris.cm/errors/${slug}`, title: slug, status, detail, trace_id: 'T' }, status);

describe('IPES · stages et soutenances', () => {
  beforeEach(() => {
    freshEnvironment();
    signIn(teacherMe, [higherTile()]);
  });

  it('dictionnaires FR/EN alignés et routes déclarées', () => {
    expect(Object.keys(higherInternshipsEn).sort()).toEqual(Object.keys(higherInternshipsFr).sort());
    expect(higherInternshipsRoutes.map((r) => r.name)).toEqual(['higher-internships', 'higher-internship', 'higher-internship-companies', 'higher-defenses', 'higher-my-internship']);
  });

  it('liste les conventions, filtre par état et crée un brouillon', async () => {
    const calls = mockApi({ ...base, 'GET /higher/internships': { data: [internship()], meta: { next_cursor: null } }, 'POST /higher/internships': { data: internship({ id: 'I2' }) } });
    const { wrapper } = await mountAt(ConventionsView, '/superieur/stages');
    expect(wrapper.findAll('[data-test="convention-row"]')).toHaveLength(1);
    expect(wrapper.text()).toContain('Brasseries');
    await wrapper.find('#cf-st').setValue('signed');
    await flushPromises();
    expect(calls.filter((c) => c.path === '/higher/internships').pop()?.query['filter[status]']).toBe('signed');

    await wrapper.find('[data-test="new-convention"]').trigger('click');
    await wrapper.find('[data-test="convention-form"]').trigger('submit');
    expect(wrapper.find('[data-test="form-missing"]').exists()).toBe(true);
    for (const [id, value] of [['#cv-enr', 'EN1'], ['#cv-co', 'CO1'], ['#cv-sub', 'Audit'], ['#cv-from', '2026-06-01'], ['#cv-to', '2026-08-31'], ['#cv-tut', 'T'], ['#cv-sup', 'SUP']] as const) {
      await wrapper.find(id).setValue(value);
    }
    await wrapper.find('[data-test="convention-form"]').trigger('submit');
    await flushPromises();
    const post = calls.find((c: RecordedCall) => c.method === 'POST');
    expect(post?.body).toMatchObject({ enrolment_id: 'EN1', company_id: 'CO1', supervisor_person_id: 'SUP' });
  });

  it('émet la convention puis le directeur signe pour l\'école', async () => {
    let current = internship();
    const calls = mockApi({
      ...base,
      'GET /higher/internships/I1': () => jsonResponse({ data: current }),
      'GET /higher/internships/I1/readiness': { data: readiness },
      'GET /higher/internships/I1/followups': { data: [], meta: { next_cursor: null } },
      'POST /higher/internships/I1/convention': () => {
        current = internship({ status: 'pending_signatures' });
        return jsonResponse({ data: current });
      },
    });
    const { wrapper } = await mountAt(ConventionView, '/superieur/stages/conventions/I1', { id: 'I1' });
    await wrapper.find('[data-test="issue-convention"]').trigger('click');
    await flushPromises();
    expect(calls.some((c) => c.method === 'POST' && c.path === '/higher/internships/I1/convention')).toBe(true);
    expect(wrapper.find('[data-test="convention-status"]').text()).toBe('En signature');
    expect(wrapper.find('[data-test="sign-student"]').exists()).toBe(true);
    expect(wrapper.find('[data-test="sign-school"]').exists()).toBe(false);
  });

  it('programme une soutenance avec son jury ; un conflit 409 est affiché', async () => {
    const calls = mockApi({
      ...base,
      'GET /higher/defenses': { data: [defense()], meta: { next_cursor: null } },
      'POST /higher/defenses': () => problem(409, 'conflict', 'Salle Amphi A déjà occupée de 09:00 à 10:00 par une autre soutenance.'),
    });
    const { wrapper } = await mountAt(DefensesView, '/superieur/stages/soutenances');
    expect(wrapper.findAll('[data-test="defense-row"]')).toHaveLength(1);
    expect(wrapper.text()).toContain('Pr Abena');
    expect(wrapper.find('[data-test="convene"]').exists()).toBe(true);

    await wrapper.find('[data-test="new-defense"]').trigger('click');
    for (const [id, value] of [['#ds-i', 'I1'], ['#ds-d', '2026-10-20'], ['#ds-s', '09:30'], ['#ds-e', '10:30'], ['#ds-r', 'Amphi A'], ['#jm-p-0', 'J1'], ['#jm-p-1', 'SUP'], ['#jm-p-2', 'J2']] as const) {
      await wrapper.find(id).setValue(value);
    }
    await wrapper.find('[data-test="defense-form"]').trigger('submit');
    await flushPromises();
    const post = calls.find((c) => c.method === 'POST');
    expect(post?.body).toMatchObject({ internship_id: 'I1', room: 'Amphi A', jury: [{ person_id: 'J1', jury_role: 'president' }, { person_id: 'SUP', jury_role: 'supervisor' }, { person_id: 'J2', jury_role: 'member' }] });
    expect(wrapper.text()).toContain('déjà occupée');
  });

  it('le président note la soutenance convoquée', async () => {
    const calls = mockApi({
      ...base,
      'GET /higher/defenses': { data: [defense({ status: 'convened' })], meta: { next_cursor: null } },
      'POST /higher/defenses/DF1/grade': { data: defense({ status: 'graded', grade: 14.5 }) },
    });
    const { wrapper } = await mountAt(DefensesView, '/superieur/stages/soutenances');
    await wrapper.find('[data-test="grade-DF1"]').setValue('14,5');
    await wrapper.find('[data-test="save-grade"]').trigger('click');
    await flushPromises();
    expect(calls.find((c) => c.method === 'POST')?.body).toEqual({ grade: 14.5 });
  });

  it('Mon stage : conditions, dépôt du mémoire, aucun stage', async () => {
    signIn(teacherMe, [higherTile(['higher.access', 'self.read'], 'apprenant')]);
    const mine: MyInternship = {
      internship: internship({ status: 'signed', signatures: { student: '2026-05-20', company: '2026-05-21', school: '2026-05-22' } }),
      followups: [], readiness: { conditions: { ...readiness.conditions, convention_signed: true }, missing: ['completion_certificate', 'thesis_deposited', 'financial_clearance'] }, defense: null,
    };
    const calls = mockApi({ ...base, 'GET /higher/students/me/internship': { data: mine }, 'POST /higher/students/me/internship/thesis': { data: internship({ status: 'signed', thesis_deposited_at: '2026-10-16T08:00:00+01:00' }) } });
    const { wrapper } = await mountAt(MyInternshipView, '/superieur/mon-stage');
    expect(wrapper.find('[data-test="cond-convention_signed"]').text()).toContain('✓');
    await wrapper.find('#mt-t').setValue('Tableaux de bord');
    await wrapper.find('#mt-r').setValue('memoires/marie.pdf');
    await wrapper.find('[data-test="deposit"]').trigger('click');
    await flushPromises();
    expect(calls.find((c) => c.method === 'POST')?.body).toEqual({ title: 'Tableaux de bord', file_ref: 'memoires/marie.pdf' });
    expect(wrapper.find('[data-test="deposit-ok"]').exists()).toBe(true);

    mockApi({ ...base, 'GET /higher/students/me/internship': () => problem(404, 'not-found', 'Aucun stage enregistré.') });
    const empty = await mountAt(MyInternshipView, '/superieur/mon-stage');
    expect(empty.wrapper.find('[data-test="no-internship"]').exists()).toBe(true);
  });
});

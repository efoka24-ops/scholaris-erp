import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import { mount } from '@vue/test-utils';
import type { Component } from 'vue';
import { routes } from '@/router/routes';
import { higherNationalExamsRoutes } from '@/views/higher/national-exams/routes';
import SessionView from '@/views/higher/national-exams/SessionView.vue';
import SessionsView from '@/views/higher/national-exams/SessionsView.vue';
import { higherNationalExamsFr } from '@/i18n/higher-nationalexams-fr';
import { higherNationalExamsEn } from '@/i18n/higher-nationalexams-en';
import { flushPromises, freshEnvironment } from './helpers';
import { find, page, problem, setup } from './higher-teaching-helpers';

beforeEach(() => freshEnvironment());

const PERMS = ['higher.access', 'higher.national_list.manage', 'higher.national_list.export'];
const session = {
  id: 'NS1', school_id: 'S1', department_id: 'D1', program_id: 'PR1', level_id: 'L3', academic_year_id: 'Y1', exam_type: 'bts', specialty_label: 'Gestion des entreprises',
  calendar_ref: 'MINESUP-2027', registration_deadline: '2027-03-01', exam_starts_on: '2027-06-01', attendance_from: '2026-10-01', attendance_to: '2027-05-31', fee_xaf: 25000,
  required_pieces: ['Acte de naissance', 'Photo'], status: 'open', export_count: 0, last_exported_at: null, last_exported_by: null, export_fingerprint: null, created_at: '2026-10-01', updated_at: '2026-10-01',
};
const candidate = (over: Record<string, unknown> = {}) => ({
  id: 'C1', session_id: 'NS1', enrolment_id: 'E1', person_id: 'P1', matricule: 'IPES-25-0001', school_id: 'S1', program_id: 'PR1', level_id: 'L3', group_id: null,
  status: 'eligible', presence_percent: 82, attendance_ok: true, fee_receipt: null, pieces_received: [], candidate_number: null, exam_centre: null, convened_at: null,
  result_code: null, result_mark: null, result_imported_at: null, withdrawn_reason: null, created_at: '2026-10-01', updated_at: '2026-10-01', ...over,
});
const stats = { session_id: 'NS1', exam_type: 'bts', specialty_label: 'x', program_id: 'PR1', academic_year_id: 'Y1', counts: { eligible: 1, passed: 0, failed: 0, absent: 0 }, success_rate: null };
const detailRoutes = (extra: Record<string, unknown> = {}) => ({
  'GET /higher/programs': page([{ id: 'PR1', school_id: 'S1', department_id: 'D1', code: 'GE', name: 'Gestion', regime: 'bts', requires_tutelage: false }]),
  'GET /higher/national-sessions/NS1': session,
  'GET /higher/national-sessions/NS1/candidates': page([candidate()]),
  'GET /higher/national-sessions/NS1/statistics': stats,
  'GET /higher/national-sessions/NS1/events': page([]),
  ...extra,
});

async function mountAt(component: Component, path: string, props: Record<string, unknown> = {}) {
  const router = createRouter({ history: createMemoryHistory(), routes: [...routes, ...higherNationalExamsRoutes] });
  await router.push(path);
  await router.isReady();
  const wrapper = mount(component, { props, global: { plugins: [router] } });
  await flushPromises();
  return { wrapper, router };
}

describe('IPES examens nationaux : routes et textes', () => {
  it('déclare la liste et le détail avec :id, gardés et réservés aux IPES', () => {
    expect(higherNationalExamsRoutes.map((r) => r.name)).toEqual(['higher-national-exams', 'higher-national-exam']);
    expect(higherNationalExamsRoutes[1]!.path).toContain(':id');
    for (const r of higherNationalExamsRoutes) {
      expect(r.beforeEnter).toBeTypeOf('function');
      expect(r.meta?.establishmentTypes).toEqual(['higher']);
    }
  });
  it('a les mêmes clés en français et en anglais', () => {
    expect(Object.keys(higherNationalExamsEn).sort()).toEqual(Object.keys(higherNationalExamsFr).sort());
  });
});

describe('IPES session d\'examen national', () => {
  it('propose l\'ouverture d\'une session depuis la liste', async () => {
    setup(PERMS, { 'GET /higher/national-sessions': page([session]) });
    const { wrapper } = await mountAt(SessionsView, '/superieur/examens-nationaux');
    expect(wrapper.get('[data-test="session-row"]').text()).toContain('Gestion des entreprises');
    expect(wrapper.find('[data-test="open-session"]').exists()).toBe(true);
  });

  it('affiche la session, les candidats et leur état', async () => {
    setup(PERMS, detailRoutes());
    const { wrapper } = await mountAt(SessionView, '/superieur/examens-nationaux/NS1', { id: 'NS1' });
    expect(wrapper.get('[data-test="session-info"]').text()).toContain('MINESUP-2027');
    const row = wrapper.get('[data-test="candidate-row"]');
    expect(row.text()).toContain('IPES-25-0001');
    expect(row.text()).toContain('Éligible');
    expect(row.text()).toContain('82 %');
    expect(row.text()).toContain('0 pièce(s) sur 2');
  });

  it('enregistre le dossier (frais, pièces) et émet DOC-12', async () => {
    const calls = setup(PERMS, detailRoutes({
      'PATCH /higher/national-candidates/C1': candidate({ fee_receipt: 'R-1', pieces_received: ['Photo'] }),
      'POST /higher/national-candidates/C1/documents': { id: 'DOC1' },
    }));
    const { wrapper } = await mountAt(SessionView, '/superieur/examens-nationaux/NS1', { id: 'NS1' });
    await wrapper.get('[data-test="candidate-row"] button').trigger('click');
    await wrapper.get('[data-test="candidate-detail"] input[type="text"]').setValue('R-1');
    await wrapper.get('[data-test="candidate-detail"] input[type="checkbox"][value="Photo"]').setValue(true);
    await wrapper.get('[data-test="candidate-detail"] form').trigger('submit');
    await flushPromises();
    expect(find(calls, 'PATCH', '/higher/national-candidates/C1')?.body).toMatchObject({ fee_receipt: 'R-1', pieces_received: ['Photo'] });
    await wrapper.findAll('[data-test="candidate-detail"] button').find((b) => b.text() === 'Attestation de fin de formation')!.trigger('click');
    await flushPromises();
    expect(find(calls, 'POST', '/higher/national-candidates/C1/documents')?.body).toEqual({ code: 'DOC-12' });
  });

  it('affiche clairement le refus RG-EXN-1 à l\'ajout d\'un candidat', async () => {
    setup(PERMS, detailRoutes({
      'GET /people': page([{ id: 'P9', first_name: 'Paul', last_name: 'Ekani' }]),
      'POST /higher/national-sessions/NS1/candidates': problem(422, 'RG-EXN-1 : non inscrit en année de sortie.'),
      'GET /higher/national-sessions/NS1/eligibility': { person_id: 'P9', rg_exn_1: false, enrolment_id: null, reasons: ['Inscrit en L2, année de sortie : L3.'], attendance: null },
    }));
    const { wrapper } = await mountAt(SessionView, '/superieur/examens-nationaux/NS1', { id: 'NS1' });
    const form = wrapper.get('[data-test="add-candidate"]');
    await form.get('input[type="search"]').setValue('Ekani');
    await form.get('.picker__row button').trigger('click');
    await flushPromises();
    await form.get('.hi-list button').trigger('click');
    await form.get('button[type="submit"]').trigger('click');
    await form.trigger('submit');
    await flushPromises();
    const notice = wrapper.get('[data-test="not-eligible"]');
    expect(notice.text()).toContain('RG-EXN-1');
    expect(notice.text()).toContain('année de sortie');
    expect(notice.text()).toContain('Inscrit en L2');
  });

  it('exporte la liste, avertit du filigrane nominatif', async () => {
    const calls = setup(PERMS, detailRoutes({
      'POST /higher/national-sessions/NS1/candidates/export': {
        session_id: 'NS1', watermark: 'W-ISSEG-0042', fingerprint: 'abcdef0123456789',
        file: { filename: 'liste.csv', format: 'csv', content_type: 'text/csv', rows: 1, columns: ['matricule'], content_base64: 'bWF0cmljdWxl' },
      },
    }));
    const { wrapper } = await mountAt(SessionView, '/superieur/examens-nationaux/NS1', { id: 'NS1' });
    await wrapper.get('[data-test="export"] button.btn--primary').trigger('click');
    await flushPromises();
    expect(find(calls, 'POST', '/higher/national-sessions/NS1/candidates/export')?.body).toEqual({ format: 'csv' });
    expect(wrapper.get('[data-test="watermark"]').text()).toContain('W-ISSEG-0042');
    expect(wrapper.get('[data-test="exported"]').text()).toContain('1 ligne(s)');
  });

  it('renvoie vers la double authentification quand l\'export l\'exige, puis revient', async () => {
    setup(PERMS, detailRoutes({
      'POST /higher/national-sessions/NS1/candidates/export': problem(403, 'Double authentification récente exigée.', { type: 'https://api.scholaris.cm/errors/second-factor-required' }),
    }));
    const { wrapper, router } = await mountAt(SessionView, '/superieur/examens-nationaux/NS1', { id: 'NS1' });
    await wrapper.get('[data-test="export"] button.btn--primary').trigger('click');
    await flushPromises();
    expect(wrapper.find('[data-test="tfa"]').exists()).toBe(true);
    await wrapper.get('[data-test="tfa"] button').trigger('click');
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('twofactor'), { timeout: 5000 });
    expect(router.currentRoute.value.query.redirect).toBe('/superieur/examens-nationaux/NS1');
  });

  it('importe les résultats et affiche l\'aperçu des lignes rejetées', async () => {
    const calls = setup(PERMS, detailRoutes({
      'POST /higher/national-sessions/NS1/results/import': { imported: 1, rejected: [{ line: 3, key: 'XX-99', reason: 'Candidat inconnu.' }] },
    }));
    const { wrapper } = await mountAt(SessionView, '/superieur/examens-nationaux/NS1', { id: 'NS1' });
    const csv = 'matricule;result;mark\nIPES-25-0001;admis;12\nXX-99;admis;11';
    await wrapper.get('[data-test="import"] textarea').setValue(csv);
    await wrapper.get('[data-test="import"]').trigger('submit');
    await flushPromises();
    expect(find(calls, 'POST', '/higher/national-sessions/NS1/results/import')?.body).toEqual({ csv });
    const outcome = wrapper.get('[data-test="import-outcome"]');
    expect(outcome.text()).toContain('1 résultat(s) importé(s), 1 rejeté(s)');
    expect(wrapper.get('[data-test="rejected-row"]').text()).toContain('Candidat inconnu.');
  });

  it('affiche le taux de réussite à la française', async () => {
    setup(PERMS, detailRoutes({
      'GET /higher/national-sessions/NS1/statistics': { ...stats, counts: { passed: 2, failed: 1, absent: 0 }, success_rate: 66.67 },
    }));
    const { wrapper } = await mountAt(SessionView, '/superieur/examens-nationaux/NS1', { id: 'NS1' });
    expect(wrapper.get('[data-test="rate"]').text()).toContain('66,67 %');
  });
});

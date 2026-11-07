import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { mockApi, freshEnvironment } from './helpers';
import { useContextStore } from '@/stores/context';
import OfficialExamsView from '@/views/secondary/exams/OfficialExamsView.vue';

const session = {
  id: 'ses-1', exam_code: 'EXA-DEMO', exam_label: 'Examen fictif', academic_year_id: 'annee-2026', class_structure_id: 'cl-1', comparison_period_id: null,
  registration_deadline: null, exam_on: null, required_pieces: [], optional_subjects: [], export_columns: [], passing_codes: ['PASS'], status: 'open',
};
const candidate = {
  id: 'c-1', session_id: 'ses-1', person_id: 'el-2', enrollment_id: 'en-2', full_name: 'Brice Ndi', class_id: 'cl-1', class_label: '3e A', national_id: null,
  national_id_status: 'pending', photo_ref: null, photo_url: null, fee_status: 'not_seen', missing_pieces: [], pieces_received: [], selected_subjects: [],
  result_code: null, result_mark: null, status: 'blocked', status_message: 'frais non constatés',
};

describe('secondary official exams screen', () => {
  it('shows a candidate blocked for fees not seen (TST-08) without any exclusion action', async () => {
    freshEnvironment();
    setActivePinia(createPinia());
    useContextStore().tiles = [{ kind: 'establishment', id: 'school-1', name: 'Établissement fictif', type: 'secondary', accent: '#F26B1D', logo: null, roles: [{ code: 'test', label: 'Test' }], permissions: ['secondary.exams.read'] }];
    useContextStore().activeId = 'school-1';
    mockApi({
      'GET /academic-years': { data: [] },
      'GET /secondary/official-exams': { data: [session], meta: { next_cursor: null, per_page: 50 } },
      'GET /secondary/official-exams/ses-1/candidates': { data: [candidate], meta: { next_cursor: null, per_page: 50 } },
      'GET /secondary/official-exams/ses-1/summary': { data: { session_id: 'ses-1', candidate_count: 1, results_count: 0, passed_count: 0, success_rate: null, result_counts: {}, passing_codes: ['PASS'], comparison: null } },
    });
    const wrapper = mount(OfficialExamsView);
    await vi.waitFor(() => expect(wrapper.text()).toContain('Brice Ndi'));
    expect(wrapper.text()).toContain('frais non constatés');
    expect(wrapper.text()).toContain('Bloqué');
    expect(wrapper.text()).not.toContain('Créer la session');
    expect(wrapper.text().toLowerCase()).not.toContain('exclure');
    wrapper.unmount();
  });
});

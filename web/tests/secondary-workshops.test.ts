import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { mockApi, freshEnvironment } from './helpers';
import { useContextStore } from '@/stores/context';
import WorkshopsView from '@/views/secondary/workshops/WorkshopsView.vue';
import InternshipsView from '@/views/secondary/workshops/InternshipsView.vue';
import { setLocale } from '@/i18n';
import { parseLearners } from '@/views/secondary/workshops/workshops';

const workshop = { id: 'w-01', label: 'Atelier fictif', stream_id: null, room_id: null, capacity: null };
const sheet = { id: 's-01', workshop_id: 'w-01', title: 'TP fictif', objective: 'Exercice', materials: '[]', tools: '[]', safety_rules: '[]', criteria: '[]', base_score: 20, safety_cap: null, version_no: 1 };

describe('secondary workshops and internships screens', () => {
  it('explains PPE safety and lists configured workshops', async () => {
    freshEnvironment();
    setActivePinia(createPinia());
    useContextStore().tiles = [{ kind: 'establishment', id: 'school-1', name: 'Établissement fictif', type: 'secondary', accent: '#F26B1D', logo: null, roles: [{ code: 'test', label: 'Test' }], permissions: ['secondary.workshops.read', 'secondary.workshops.manage', 'secondary.workshops.session', 'secondary.stock.manage'] }];
    useContextStore().activeId = 'school-1';
    setLocale('en');
    mockApi({
      'GET /secondary/workshops': { data: [workshop] },
      'GET /secondary/workshops/workstations': { data: [] },
      'GET /secondary/workshops/sheets': { data: [sheet] },
      'GET /secondary/workshops/sessions': { data: [] },
      'GET /secondary/workshops/materials': { data: [] },
    });
    const wrapper = mount(WorkshopsView);
    await vi.waitFor(() => expect(wrapper.text()).toContain('Atelier fictif'));
    expect(wrapper.text()).toContain('Without all required PPE');
    wrapper.unmount();
  });

  it('provides internship company and placement screens in French', async () => {
    freshEnvironment();
    setActivePinia(createPinia());
    setLocale('fr');
    useContextStore().tiles = [{ kind: 'establishment', id: 'school-1', name: 'Établissement fictif', type: 'secondary', accent: '#F26B1D', logo: null, roles: [{ code: 'test', label: 'Test' }], permissions: ['secondary.internships.manage'] }];
    useContextStore().activeId = 'school-1';
    mockApi({
      'GET /secondary/internships/companies': { data: [] },
      'GET /secondary/internships': { data: [] },
    });
    const wrapper = mount(InternshipsView);
    expect(wrapper.text()).toContain('Stages en entreprise');
    expect(wrapper.text()).toContain('Entreprise');
    expect(wrapper.find('#intern-class').exists()).toBe(true);
    expect(wrapper.find('#intern-class').attributes('required')).toBeDefined();
    wrapper.unmount();
  });
});

describe('workshop session learners', () => {
  it('parses workstation and PPE per learner (RG-ATL-1 decided by the server)', () => {
    expect(parseLearners('el-1 ; ws-1 ; gloves, goggles\nel-2\n\n el-3;ws-2')).toEqual([
      { person_id: 'el-1', workstation_id: 'ws-1', ppe_items: ['gloves', 'goggles'] },
      { person_id: 'el-2' },
      { person_id: 'el-3', workstation_id: 'ws-2' },
    ]);
  });
});

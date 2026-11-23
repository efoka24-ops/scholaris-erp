import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it } from 'vitest';
import { permissionsForRoles } from '@/lib/permissions';
import BatchEnrolView from '@/views/public-schools/BatchEnrolView.vue';
import DashboardView from '@/views/public-schools/DashboardView.vue';
import { lockVault, wipeVault } from '@/views/public-schools/vault';
import { flushPromises, freshEnvironment, jsonResponse, mockApi, mountView, signIn, teacherMe, tile, type RecordedCall } from './helpers';

// C-105 : photo réelle du registre papier (fichier téléversé puis jointe au lot), liste des territoires pour un rôle national sans école,
// entrée de la direction des examens (R02) sur le tableau de bord, indicateurs de présence. Données fictives.

const page = (items: unknown[]) => ({ data: items, meta: { next_cursor: null, per_page: 50 } });

function connect(roles: string[], extra: string[], map: Record<string, unknown>, kind: 'establishment' | 'group' = 'establishment'): RecordedCall[] {
  signIn(teacherMe, [tile({ kind, type: 'primary', permissions: [...permissionsForRoles(roles), ...extra], roles: roles.map((code) => ({ code, label: code })) })], roles[0]);
  return mockApi(map as Parameters<typeof mockApi>[0]);
}

beforeEach(async () => {
  freshEnvironment();
  await wipeVault();
  lockVault();
});

describe('photo du registre papier', () => {
  it('téléverse le fichier (FormData) puis joint son identifiant au lot', async () => {
    const batch = { id: 'B1', client_uuid: 'U', register_photo_ref: null, register_photo_id: 'PH1', has_register_photo: true, register_label: 'Registre p. 3', entered_by_proxy: false, rows_count: 1, created_count: 1, results: [{ row: 0, status: 'created' }] };
    const calls = connect(['pub_school_director'], [], {
      'GET /academic-years': page([]),
      'GET /public/pupils/batches': page([]),
      'POST /public/pupils/batches/photos': jsonResponse({ data: { id: 'PH1', created_at: '2026-11-05T10:00:00+01:00' } }, 201),
      'POST /public/pupils/batches': jsonResponse({ data: batch }, 201),
    });
    const { wrapper } = await mountView(BatchEnrolView);
    await flushPromises();

    const input = wrapper.get('#be-photo');
    expect(input.attributes('type')).toBe('file');
    const file = new File([new Uint8Array([137, 80, 78, 71])], 'registre.png', { type: 'image/png' });
    Object.defineProperty(input.element, 'files', { value: [file], configurable: true });
    await input.trigger('change');
    await wrapper.get('#bl-0').setValue('SYN-Atangana');
    await wrapper.get('form').trigger('submit');
    await flushPromises();

    const upload = calls.find((c) => c.method === 'POST' && c.path === '/public/pupils/batches/photos');
    expect(upload, 'téléversement du fichier').toBeDefined();
    expect(upload?.headers['Content-Type'], 'FormData : pas de JSON').toBeUndefined();
    const create = calls.find((c) => c.method === 'POST' && c.path === '/public/pupils/batches');
    expect(create?.body).toMatchObject({ register_photo_id: 'PH1' });
    expect(create?.body).not.toHaveProperty('register_photo_ref');
  });

  it('propose de revoir la photo d’un registre déjà saisi', async () => {
    connect(['pub_school_director'], [], {
      'GET /academic-years': page([]),
      'GET /public/pupils/batches': page([
        { id: 'B1', client_uuid: 'U1', register_photo_ref: null, register_photo_id: 'PH1', has_register_photo: true, register_label: 'Registre p. 3', entered_by_proxy: false, rows_count: 2, created_count: 2, results: [] },
        { id: 'B2', client_uuid: 'U2', register_photo_ref: null, register_photo_id: null, has_register_photo: false, register_label: 'Registre p. 4', entered_by_proxy: false, rows_count: 1, created_count: 1, results: [] },
      ]),
    });
    const { wrapper } = await mountView(BatchEnrolView);
    await flushPromises();
    expect(wrapper.findAll('button').filter((b) => b.text().includes('Voir la photo du registre'))).toHaveLength(1);
  });
});

describe('tableau de bord territorial', () => {
  const nodes = [
    { id: 'REG1', type: 'region', label_fr: 'Région fictive', label_en: 'Fictional region', code: 'SYN-R', parent_id: null },
    { id: 'DEP1', type: 'departement', label_fr: 'Département fictif', label_en: 'Fictional division', code: 'SYN-D', parent_id: 'REG1' },
    { id: 'ARR1', type: 'arrondissement', label_fr: 'Arrondissement fictif', label_en: 'Fictional sub-division', code: 'SYN-A', parent_id: 'DEP1' },
    { id: 'REG2', type: 'region', label_fr: 'Autre région fictive', label_en: 'Other region', code: 'SYN-R2', parent_id: null },
  ];
  const dashboard = (id: string) => ({
    data: {
      territory: { id, type: 'region', label_fr: 'Région fictive', label_en: 'Fictional region', parent_id: null },
      reference_date: '2026-11-04', minimum_cell_size: 5, indicators: [],
      derived: [
        { code: 'pupil_attendance_rate', label_fr: 'Présence des élèves (%)', label_en: 'Pupil attendance (%)', formula: 'présents / (présents + absents) à l’appel', value: 91.5, masked: false },
        { code: 'teacher_attendance_rate', label_fr: 'Présence des enseignants (%)', label_en: 'Teacher attendance (%)', formula: 'présents / enseignants déclarés', value: null, masked: true },
        { code: 'pupils_per_classroom', label_fr: 'Élèves par salle utilisée', label_en: 'Pupils per classroom in use', formula: 'élèves / salles utilisées (recensement)', value: 44, masked: false },
      ],
    },
  });

  it('un rôle national sans école choisit région, département, arrondissement dans une liste (aucun identifiant à saisir)', async () => {
    const calls = connect(['pub_cabinet'], [], {
      'GET /public/dashboards/territories': { data: { territories: nodes } },
      'GET /public/dashboards/DEP1': dashboard('DEP1'),
      'GET /public/dashboards/DEP1/children': { data: { territories: [] } },
    }, 'group');
    const { wrapper } = await mountView(DashboardView);
    await flushPromises();

    expect(wrapper.find('input[type="text"]').exists()).toBe(false);
    expect(wrapper.find('#db-department').exists()).toBe(false);
    await wrapper.get('#db-region').setValue('REG1');
    expect(wrapper.findAll('#db-department option').map((o) => o.text())).toEqual(['Tous', 'Département fictif']);
    await wrapper.get('#db-department').setValue('DEP1');
    expect(wrapper.findAll('#db-subdivision option').map((o) => o.text())).toEqual(['Tous', 'Arrondissement fictif']);
    await wrapper.get('form').trigger('submit');
    await flushPromises();

    expect(calls.some((c) => c.path === '/public/dashboards/DEP1')).toBe(true);
    expect(wrapper.text()).toContain('91.5');
    expect(wrapper.text()).toContain('masqué');
    expect(wrapper.text()).toContain('Élèves par salle utilisée');
  });

  it('l’inspecteur de l’école garde les boutons de son territoire, sans appeler la liste nationale', async () => {
    const calls = connect(['pub_district_inspector'], [], {
      'GET /public/dashboards/entry': { data: { territories: [{ id: 'ARR1', type: 'arrondissement', label_fr: 'Arrondissement fictif', label_en: 'Fictional sub-division' }] } },
      'GET /public/dashboards/ARR1': dashboard('ARR1'),
      'GET /public/dashboards/ARR1/children': { data: { territories: [] } },
    });
    const { wrapper } = await mountView(DashboardView);
    await flushPromises();
    expect(calls.some((c) => c.path === '/public/dashboards/territories')).toBe(false);
    expect(wrapper.find('#db-region').exists()).toBe(false);
    expect(wrapper.text()).toContain('Arrondissement fictif');
  });

  it('la direction des examens (R02) trouve l’entrée vers la consolidation ; le cabinet (agrégats seuls) non', async () => {
    connect(['pub_exam_directorate'], [], { 'GET /public/dashboards/territories': { data: { territories: [] } } }, 'group');
    const first = await mountView(DashboardView);
    await flushPromises();
    const link = first.wrapper.find('a[href="/ecole-publique/examens/consolidation"]');
    expect(link.exists()).toBe(true);

    freshEnvironment();
    connect(['pub_cabinet'], [], { 'GET /public/dashboards/territories': { data: { territories: [] } } }, 'group');
    const second = await mountView(DashboardView);
    await flushPromises();
    expect(second.wrapper.find('a[href="/ecole-publique/examens/consolidation"]').exists()).toBe(false);
  });
});

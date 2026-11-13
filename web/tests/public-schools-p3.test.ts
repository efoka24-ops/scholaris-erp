import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it } from 'vitest';
import { routes } from '@/router/routes';
import { permissionsForRoles } from '@/lib/permissions';
import CanteenView from '@/views/public-schools/CanteenView.vue';
import DashboardView from '@/views/public-schools/DashboardView.vue';
import ExamsView from '@/views/public-schools/ExamsView.vue';
import { flushVisits, listVisitQueue, queueReport } from '@/views/public-schools/offlineVisits';
import { lockVault, setupPin, wipeVault } from '@/views/public-schools/vault';
import { flushPromises, freshEnvironment, jsonResponse, mockApi, mountView, signIn, teacherMe, tile, type RecordedCall } from './helpers';

// Lot P3 du primaire public : routes et droits des écrans (examens, consolidation, inspection, cantine, tableau de bord), liste
// d'examen avec candidat bloqué faute d'acte vérifié, rapport de visite hors ligne rejoué une fois, campagne de santé en effectifs,
// tableau de bord masqué. Données fictives.

const FAST = 1000;
const page = (items: unknown[]) => ({ data: items, meta: { next_cursor: null, per_page: 50 } });

function connect(roles: string[], extra: string[], map: Record<string, unknown>): RecordedCall[] {
  signIn(teacherMe, [tile({ type: 'primary', permissions: [...permissionsForRoles(roles), ...extra], roles: roles.map((code) => ({ code, label: code })) })], roles[0]);
  return mockApi(map as Parameters<typeof mockApi>[0]);
}

beforeEach(async () => {
  freshEnvironment();
  await wipeVault();
  lockVault();
});

describe('routes du lot P3', () => {
  it('déclare les écrans avec leurs permissions', () => {
    const byName = new Map(routes.map((r) => [r.name, r]));
    for (const name of ['pp-exams', 'pp-exams-delegation', 'ps-inspection', 'ps-canteen', 'territory-dashboard']) {
      expect(byName.has(name), name).toBe(true);
    }
    expect(byName.get('territory-dashboard')?.meta?.perms).toEqual(['public_schools.aggregates.read']);
    expect(byName.get('territory-dashboard')?.meta?.needsEstablishment).toBeUndefined();
  });

  it('un rôle « agrégats seuls » n’ouvre que le tableau de bord territorial', () => {
    const perms = new Set(permissionsForRoles(['pub_cabinet']));
    for (const name of ['pp-exams', 'pp-exams-delegation', 'ps-inspection', 'ps-canteen']) {
      const needed = (routes.find((r) => r.name === name)?.meta?.perms ?? []) as string[];
      expect(needed.some((p) => perms.has(p)), name).toBe(false);
    }
    expect(perms.has('public_schools.aggregates.read')).toBe(true);
  });

  it('le miroir des droits suit la migration du lot P3', () => {
    expect(permissionsForRoles(['pub_departmental_delegate'])).toContain('public_primary.exams.attest');
    expect(permissionsForRoles(['pub_class_teacher'])).toContain('public_primary.exams.read');
    expect(permissionsForRoles(['pub_school_director'])).toContain('public_schools.canteen.read');
    expect(permissionsForRoles(['pub_school_director'])).not.toContain('public_schools.canteen.write');
  });
});

describe('examens', () => {
  const session = { id: 'S1', exam_code: 'cep', year_label: '2026-2027', label: 'CEP', subsystem: 'francophone', level_codes: ['CM2'], kind: 'certificate', written_on: null, state: 'open' };
  const candidate = (id: string, state: string, verified: boolean) => ({
    id, session_id: 'S1', establishment_id: 'E1', person_id: `P${id}`, learner_number: `SYN-${id}`, last_name: 'Mbarga', first_name: id, sex: 'F', birth_date: '2015-03-01',
    birth_act_ref: verified ? 'SYN-ACTE' : null, birth_act_verified: verified, structure_id: 'C3', level_code: 'CM2', state, blocked_reason: state === 'blocked' ? 'birth_act_unverified' : null,
    added_after_freeze: false, added_reason: null, withdrawn_reason: null, candidate_number: null, centre_id: null, sub_centre_id: null, outcome: null, result_published: false,
    attestation_state: null, attestation_document_id: null,
  });

  it('l’inspecteur valide la liste : le candidat sans acte vérifié reste bloqué, lui seul (TST-07)', async () => {
    const list = { id: 'L1', establishment_id: 'E1', session_id: 'S1', state: 'submitted', current_step: 'inspection', return_reason: null, frozen_at: null, amendments_count: 0 };
    const calls = connect(['pub_district_inspector'], [], {
      'GET /academic-years': page([]),
      'GET /public-primary/exams': page([session]),
      'GET /public-primary/exams/S1': { data: { ...session, list, counts: { proposed: 2 } } },
      'GET /public-primary/exams/S1/candidates': page([candidate('A', 'proposed', true), candidate('B', 'proposed', false)]),
      'GET /public-primary/exams/S1/results': { data: { session, summary: { candidates: 0, present: 0, passed: 0, failed: 0, absent: 0, success_rate: null, pending: 0 } } },
      'POST /public-primary/exams/S1/candidates/validate': { data: { list: { ...list, state: 'inspected', current_step: 'delegation' }, step: 'inspection', validated: 1, blocked: ['B'] } },
    });
    const { wrapper } = await mountView(ExamsView);
    await flushPromises();
    expect(wrapper.text()).toContain('non vérifié');
    const validate = wrapper.findAll('button').find((b) => b.text() === 'Valider la liste');
    expect(validate).toBeDefined();
    expect(wrapper.findAll('button').some((b) => b.text() === 'Générer depuis les inscrits')).toBe(false);
    await validate?.trigger('click');
    await flushPromises();
    expect(calls.some((c) => c.method === 'POST' && c.path === '/public-primary/exams/S1/candidates/validate')).toBe(true);
    expect(wrapper.text()).toContain('1 candidat(s) validé(s), 1 bloqué(s)');
  });
});

describe('inspection hors ligne', () => {
  it('garde le rapport chiffré sans réseau puis le rejoue une seule fois avec le même identifiant client', async () => {
    await setupPin('123456', FAST);
    let online = false;
    const calls = connect(['pub_district_inspector'], [], {
      'PUT /public/inspection/visits/V1/report': () => {
        if (!online) throw new TypeError('Failed to fetch');
        return { data: { id: 'V1', state: 'done', replayed: false } };
      },
    });
    const queued = await queueReport('E1', 'V1', 'CP1', { done_on: '2026-10-08', answers: { prep_journal: 'S' }, counted_present: 30, recommendations: ['Tenir le cahier'], followups: [] });
    expect((await flushVisits()).offline).toBe(true);
    expect(await listVisitQueue()).toHaveLength(1);
    online = true;
    expect((await flushVisits()).sent).toBe(1);
    expect(await listVisitQueue()).toHaveLength(0);
    const sent = calls.filter((c) => c.method === 'PUT');
    expect(sent.at(-1)?.body?.client_uuid).toBe(queued.client_uuid);
    expect(sent.at(-1)?.headers['X-Establishment-Id']).toBe('E1');
  });

  it('un rapport déjà enregistré sous un autre identifiant reste en conflit, jamais écrasé', async () => {
    await setupPin('123456', FAST);
    connect(['pub_district_inspector'], [], {
      'PUT /public/inspection/visits/V1/report': jsonResponse({ type: 'x', title: 'Conflit', status: 409, detail: 'Visite déjà rapportée', trace_id: 'T' }, 409),
    });
    await queueReport('E1', 'V1', 'CP1', { done_on: '2026-10-08', answers: {}, recommendations: [], followups: [] });
    expect((await flushVisits()).conflicts).toBe(1);
    expect((await listVisitQueue())[0]?.status).toBe('conflict');
  });
});

describe('cantine et tableau de bord', () => {
  it('le directeur consulte la cantine sans formulaire de saisie', async () => {
    connect(['pub_school_director'], [], {
      'GET /academic-years': page([]),
      'GET /public/canteen/meals': page([]),
      'GET /public/canteen/summary': { data: { month: '2026-10', service_days: 3, served_total: 120, classes: [] } },
      'GET /public/canteen/stock': page([]),
      'GET /public/canteen/stock/balance': { data: { items: [{ item_label: 'Riz', unit_label: 'kg', received: 50, consumed: 20, balance: 30 }] } },
      'GET /public/health-campaigns': page([{ id: 'H1', campaign_kind: 'deworming', held_on: '2026-10-05', label: null, girls_count: 15, boys_count: 12, treated_count: 27, consents_count: 30, refusals_count: 3 }]),
    });
    const { wrapper } = await mountView(CanteenView);
    await flushPromises();
    expect(wrapper.text()).toContain('Riz');
    expect(wrapper.text()).toContain('15 fille(s), 12 garçon(s), 30 consentement(s)');
    expect(wrapper.find('#ca-item').exists()).toBe(false);
    expect(wrapper.find('#ca-kind').exists()).toBe(false);
  });

  it('affiche un indicateur masqué sans sa valeur', async () => {
    connect(['pub_district_inspector'], [], {
      'GET /public/dashboards/entry': { data: { territories: [{ id: 'ARR1', type: 'arrondissement', label_fr: 'Arrondissement fictif', label_en: 'Fictional sub-division' }] } },
      'GET /public/dashboards/ARR1': {
        data: {
          territory: { id: 'ARR1', type: 'arrondissement', label_fr: 'Arrondissement fictif', label_en: 'Fictional sub-division', parent_id: null },
          reference_date: '2026-10-07', minimum_cell_size: 5, indicators: [],
          derived: [
            { code: 'exam_success_rate', label_fr: 'Réussite au CEP ou au FSLC (%)', label_en: 'Success', formula: 'admis / candidats présents', value: 75, masked: false },
            { code: 'birth_act_missing_rate', label_fr: 'Élèves sans acte de naissance (%)', label_en: 'No certificate', formula: 'signalés / inscrits', value: null, masked: true },
          ],
        },
      },
      'GET /public/dashboards/ARR1/children': { data: { territories: [] } },
    });
    const { wrapper } = await mountView(DashboardView);
    await flushPromises();
    expect(wrapper.text()).toContain('Arrondissement fictif');
    expect(wrapper.text()).toContain('75');
    expect(wrapper.text()).toContain('masqué');
  });
});

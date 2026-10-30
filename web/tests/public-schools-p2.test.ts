import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it } from 'vitest';
import { routes } from '@/router/routes';
import { publicPrimary, type Grid } from '@/api/publicSchools';
import { permissionsForRoles } from '@/lib/permissions';
import ApeeView from '@/views/public-schools/ApeeView.vue';
import CensusView from '@/views/public-schools/CensusView.vue';
import { cellKey, flushChecks, listChecksQueue, nextOutcome, submitChecks } from '@/views/public-schools/offlineChecks';
import { lockVault, setupPin, wipeVault } from '@/views/public-schools/vault';
import { flushPromises, freshEnvironment, jsonResponse, mockApi, mountView, signIn, teacherMe, tile, type RecordedCall } from './helpers';

// Lot P2 du primaire public : routes et droits des écrans, saisie des critères hors ligne (coffre chiffré, rejeu idempotent),
// APEE (le directeur consulte sans écrire, aucune liste nominative hors du bureau), questionnaire statistique (contrôles en erreur).
// Données fictives.

const FAST = 1000;
const page = (items: unknown[]) => ({ data: items, meta: { next_cursor: null, per_page: 50 } });

function connect(roles: string[], extra: string[], map: Record<string, unknown>): RecordedCall[] {
  signIn(teacherMe, [tile({ type: 'primary', permissions: [...permissionsForRoles(roles), ...extra], roles: roles.map((code) => ({ code, label: code })) })], roles[0]);
  return mockApi(map as Parameters<typeof mockApi>[0]);
}

const grid: Grid = {
  unit: { id: 'U3', unit_no: 3, level_code: 'CE1', year_label: '2026-2027', starts_on: '2026-11-02', ends_on: '2026-11-27', evaluation_week_from: '2026-11-21' },
  class: { structure_id: 'C2', label: 'CE1 B' },
  occasions: 3,
  mastery_min_success: 2,
  scale: [{ code: 'A', label_fr: 'Acquis', label_en: 'Achieved' }],
  frames: [{ id: 'F1', discipline_code: 'example_francais', label_fr: 'Français', label_en: 'French', is_key_discipline: true, criteria: [{ id: 'K1', code: 'C1', label_fr: 'Pertinence', label_en: 'Relevance', kind: 'minimal' }] }],
  pupils: [{ person_id: 'PAUL', name: 'Paul E.' }],
  cells: [],
  mentions: [],
  frozen: [],
};

beforeEach(async () => {
  freshEnvironment();
  await wipeVault();
  lockVault();
});

describe('routes du lot P2', () => {
  it('déclare les écrans du lot P2 avec leurs permissions', () => {
    const byName = new Map(routes.map((r) => [r.name, r]));
    for (const name of ['pp-evaluation', 'pp-remediation', 'pp-report-cards', 'pp-promotion', 'ps-apee', 'ps-supplies', 'ps-census', 'ps-batch-enrol', 'pp-referential']) {
      expect(byName.has(name), name).toBe(true);
    }
    expect(byName.get('pp-evaluation')?.meta?.perms).toContain('public_primary.evaluation.write');
    expect(byName.get('ps-apee')?.meta?.perms).toEqual(['public_schools.apee.read']);
  });

  it('un rôle « agrégats seuls » n’a aucune permission des écrans du lot P2', () => {
    const perms = new Set(permissionsForRoles(['pub_cabinet']));
    for (const route of routes.filter((r) => typeof r.name === 'string' && /^(pp-|ps-apee|ps-census|ps-batch|ps-supplies)/.test(r.name))) {
      const needed = (route.meta?.perms ?? []) as string[];
      expect(needed.some((p) => perms.has(p)), String(route.name)).toBe(false);
    }
  });
});

describe('saisie des critères hors ligne', () => {
  it('fait tourner une case réussi, échoué, absent', () => {
    expect(nextOutcome(undefined)).toBe('success');
    expect(nextOutcome('success')).toBe('fail');
    expect(nextOutcome('fail')).toBe('absent');
    expect(nextOutcome('absent')).toBe('success');
  });

  it('garde la saisie chiffrée sans réseau puis la rejoue une seule fois avec le même identifiant client', async () => {
    await setupPin('123456', FAST);
    let online = false;
    const calls = connect(['pub_class_teacher'], ['public_primary.evaluation.write'], {
      'PUT /public-primary/units/U3/criterion-checks': () => {
        if (!online) throw new TypeError('Failed to fetch');
        return { data: { applied: 1, replayed: false, mentions: [] } };
      },
    });
    const queued = await submitChecks('E1', grid, { [cellKey('PAUL', 'K1', 1)]: 'success' });
    const offline = await flushChecks();
    expect(offline.offline).toBe(true);
    expect((await listChecksQueue()).map((q) => q.client_uuid)).toEqual([queued.client_uuid]);

    online = true;
    const report = await flushChecks();
    expect(report.sent).toBe(1);
    expect(await listChecksQueue()).toHaveLength(0);
    const sent = calls.filter((c) => c.method === 'PUT');
    expect(sent.at(-1)?.body?.client_uuid).toBe(queued.client_uuid);
    expect(sent.at(-1)?.headers['X-Establishment-Id']).toBe('E1');
    expect(sent.at(-1)?.body?.checks).toEqual([{ person_id: 'PAUL', criterion_id: 'K1', occasion_no: 1, outcome: 'success' }]);
  });

  it('un conflit (bulletin publié) reste dans la file sans être écrasé', async () => {
    await setupPin('123456', FAST);
    connect(['pub_class_teacher'], [], {
      'PUT /public-primary/units/U3/criterion-checks': jsonResponse({ type: 'x', title: 'Conflit', status: 409, detail: 'Saisie figée', trace_id: 'T' }, 409),
    });
    await submitChecks('E1', grid, { [cellKey('PAUL', 'K1', 1)]: 'fail' });
    const report = await flushChecks();
    expect(report.conflicts).toBe(1);
    expect((await listChecksQueue())[0]?.status).toBe('conflict');
  });

  it('lit la grille sous /public-primary avec l’établissement actif', async () => {
    const calls = connect(['pub_class_teacher'], [], { 'GET /public-primary/units/U3/grid': { data: grid } });
    const res = await publicPrimary.grid('U3', 'C2');
    expect(res.class.label).toBe('CE1 B');
    expect(calls[0]?.query.structure_id).toBe('C2');
    expect(calls[0]?.headers['X-Establishment-Id']).toBe('E1');
  });
});

describe('APEE', () => {
  const year = { id: 'AY1', year_label: '2026-2027', amount_per_pupil: 5000, voted_on: '2026-09-26', minutes_ref: null, expected_pupils: 420 };
  const statement = {
    year_label: '2026-2027', amount_per_pupil: 5000, expected_pupils: 420, expected: 2100000, collected: 1720000, collection_rate: 81.9,
    contributing_families: 344, exempted_families: 0, non_contributing_families: 76, income: { contribution: 1720000 }, income_total: 1720000,
    expenses: { parents_teacher: 450000, supplies: 300000, maintenance: 250000 }, expenses_total: 1000000, pending_expenses: {}, pending_total: 0, balance: 720000,
  };

  it('le directeur consulte l’état financier sans formulaire ni liste nominative', async () => {
    const calls = connect(['pub_school_director'], [], {
      'GET /public/apee/years': page([year]),
      'GET /public/apee/years/AY1/entries': page([]),
      'GET /public/apee/years/AY1/statement': { data: statement },
    });
    const { wrapper } = await mountView(ApeeView);
    await flushPromises();
    expect(wrapper.text()).toContain('720');
    expect(wrapper.text()).toContain('81.9');
    expect(wrapper.text()).toContain('volontaire');
    expect(wrapper.find('#ae-amt').exists()).toBe(false);
    expect(calls.some((c) => c.path.endsWith('/non-contributors'))).toBe(false);
  });

  it('le bureau enregistre une contribution avec un identifiant client', async () => {
    const calls = connect(['pub_apee_board'], [], {
      'GET /public/apee/years': page([year]),
      'GET /public/apee/years/AY1/entries': page([]),
      'GET /public/apee/years/AY1/statement': { data: statement },
      'GET /public/apee/years/AY1/non-contributors': page([{ person_id: 'S9', name: 'Élève SYN' }]),
      'POST /public/apee/entries': { data: { id: 'EN1', state: 'recorded', receipt_number: 'REC-1', replayed: false } },
    });
    const { wrapper } = await mountView(ApeeView);
    await flushPromises();
    expect(wrapper.text()).toContain('Élève SYN');
    await wrapper.get('#ae-cat').setValue('donation');
    await wrapper.get('#ae-amt').setValue('20000');
    await wrapper.findAll('form')[1]?.trigger('submit');
    await flushPromises();
    const post = calls.find((c) => c.method === 'POST' && c.path === '/public/apee/entries');
    expect(post?.body?.category).toBe('donation');
    expect(typeof post?.body?.client_uuid).toBe('string');
  });
});

describe('questionnaire statistique', () => {
  it('affiche les contrôles en erreur renvoyés par le serveur (TST-08)', async () => {
    const form = { id: 'Q1', year_label: '2026-2027', campaign: 'start_of_year', version: 1, state: 'draft', current_step: null, entered_by_proxy: false, correction_reason: null, return_reason: null, payload: { sections: { enrolment: { title_fr: 'Effectifs', title_en: 'Enrolment', values: { 'pupils.total': 5 } } } }, checks: null };
    connect(['pub_school_director'], [], {
      'GET /academic-years': page([]),
      'GET /public/census': page([form]),
      'POST /public/census/Q1/submit': jsonResponse({ type: 'x', title: 'Données invalides', status: 422, detail: 'Questionnaire incohérent', errors: { checks: ['enrolment.age_total_mismatch'] }, trace_id: 'T' }, 422),
    });
    const { wrapper } = await mountView(CensusView);
    await flushPromises();
    const open = wrapper.findAll('button').find((b) => b.text().includes('2026-2027'));
    await open?.trigger('click');
    await flushPromises();
    const submit = wrapper.findAll('button').find((b) => b.text() === 'Transmettre / valider');
    await submit?.trigger('click');
    await flushPromises();
    expect(wrapper.text()).toContain('enrolment.age_total_mismatch');
    expect(wrapper.text()).toContain('à corriger');
  });
});

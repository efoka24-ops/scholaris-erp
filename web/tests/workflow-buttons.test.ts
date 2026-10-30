import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import { mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import type { Component } from 'vue';
import { routes } from '@/router/routes';
import { higherGradesRoutes } from '@/views/higher/grades/routes';
import { higherRoutes } from '@/views/higher/routes';
import AssessmentGridView from '@/views/higher/grades/AssessmentGridView.vue';
import CorrectionsView from '@/views/higher/grades/CorrectionsView.vue';
import AccountView from '@/views/higher/AccountView.vue';
import AccountsView from '@/views/higher/AccountsView.vue';
import ApplicationView from '@/views/higher/ApplicationView.vue';
import { useWorkflow, useWorkflowStore, WF, WORKFLOW_CACHE_MS, type EffectiveCircuit, type EffectiveStep } from '@/lib/useWorkflow';
import { IpesDefaultsCodes } from './higher-grades.fixtures';
import { flushPromises, freshEnvironment, jsonResponse, mockApi, signIn, teacherMe, tile } from './helpers';
import { page } from './higher-teaching-helpers';

vi.setConfig({ testTimeout: 40_000 });

beforeEach(() => {
  freshEnvironment();
  vi.useRealTimers();
});

// --- aides --------------------------------------------------------------------------------------------------------------------

const step = (key: string, permission: string, over: Partial<EffectiveStep> = {}, actorKey = 'x', role: string | null = null): EffectiveStep => ({
  key, enabled: true, recent_confirmation: false, source: 'default', actor: { key: actorKey, permission, role }, ...over,
});
const circuit = (id: string, steps: EffectiveStep[], customised = true): EffectiveCircuit => {
  const [application, key] = id.split(':') as [string, string];
  return { id, application, key, customised, steps };
};

/** Le serveur sans réglage : toutes les étapes à leur valeur par défaut. */
const DEFAULTS: EffectiveCircuit[] = [
  circuit(WF.grades, [step('validate', 'assessment.validate'), step('lock', 'assessment.validate'), step('publish', 'higher.grades.publish')], false),
  circuit(WF.correction, [step('department', 'assessment.correction.approve'), step('school', 'assessment.correction.approve')], false),
  circuit(WF.scholarship, [step('approval', 'billing.manage')], false),
  circuit(WF.admission, [step('decision', 'higher.admission.decide')], false),
  circuit(WF.reversal, [step('approval', 'billing.reversal.approve')], false),
];

/** Le serveur avec un réglage : les circuits donnés remplacent les défauts. */
const withOverrides = (...over: EffectiveCircuit[]): EffectiveCircuit[] => DEFAULTS.map((c) => over.find((o) => o.id === c.id) ?? c);

const tuned = (key: string, permission: string, actorKey: string, role: string | null = null, extra: Partial<EffectiveStep> = {}): EffectiveStep =>
  step(key, permission, { source: 'establishment', ...extra }, actorKey, role);

function higherTile(permissions: string[], role = 'enseignant') {
  return { ...tile({ type: 'higher', roles: [{ code: role, label: role }] }), permissions };
}

const HIGHER_BASE = {
  'GET /me/applications': { data: [{ app_key: 'higher', state: 'active' }] },
  'GET /higher/me': { data: { application: { key: 'higher', state: 'active', writable: true }, establishment_id: 'E1', roles: [], all_schools: true, school_ids: [], school_selector: false } },
  'GET /higher/groups': { data: [] },
  'GET /higher/curricula': { data: [] },
  'GET /higher/schools': page([{ id: 'S1', code: 'ESG', name: 'École de gestion', director_person_id: null }]),
  'GET /higher/programs': page([]),
  'GET /higher/levels': page([]),
  'GET /academic-years': page([{ id: 'Y1', label: '2026-2027' }]),
};

/** Connecte une personne (permissions + rôle) et simule l'API ; `circuits` = réponse de GET /workflows/effective (null = route indisponible). */
function setup(permissions: string[], circuits: EffectiveCircuit[] | null, routesMap: Record<string, unknown> = {}, role = 'enseignant') {
  useWorkflowStore().reset(); // nouvelle session : pas de cache hérité d'une connexion précédente
  signIn({ ...teacherMe, context: { establishment_id: 'E1', role } }, [higherTile(permissions, role)], role);
  const workflowRoute = circuits === null
    ? { 'GET /workflows/effective': () => jsonResponse({ type: 'about:blank', title: 'Indisponible', status: 503, detail: 'Service indisponible.' }, 503) }
    : { 'GET /workflows/effective': { data: circuits } };
  return mockApi({ ...HIGHER_BASE, ...workflowRoute, ...routesMap } as Parameters<typeof mockApi>[0]);
}

async function mountAt(component: Component, path: string, props: Record<string, unknown> = {}) {
  const router = createRouter({ history: createMemoryHistory(), routes: [...routes, ...higherGradesRoutes, ...higherRoutes] });
  await router.push(path);
  await router.isReady();
  const wrapper = mount(component, { props, global: { plugins: [router] } });
  await flushPromises();
  return { wrapper, router };
}

// --- le composable ------------------------------------------------------------------------------------------------------------

describe('useWorkflow : repli sur les droits historiques', () => {
  function probe(): { canAct: ReturnType<typeof useWorkflow>['canAct']; stepEnabled: ReturnType<typeof useWorkflow>['stepEnabled']; tuned: ReturnType<typeof useWorkflow>['tuned'] } {
    let out!: ReturnType<typeof useWorkflow>;
    mount(defineComponent({ setup() { out = useWorkflow(); return () => h('div'); } }));
    return out;
  }

  it('sans réglage, les droits historiques décident (circuit non personnalisé ou étape jamais réglée)', async () => {
    setup(['assessment.validate'], DEFAULTS);
    const wf = probe();
    await flushPromises();
    expect(wf.canAct(WF.grades, 'lock', ['assessment.validate'])).toBe(true);
    expect(wf.canAct(WF.grades, 'publish', ['higher.grades.publish'])).toBe(false);
    expect(wf.tuned(WF.grades, 'lock')).toBeNull();

    // Circuit personnalisé, mais cette étape-ci n'a jamais été réglée : droits historiques.
    setup(['assessment.validate'], withOverrides(circuit(WF.grades, [step('validate', 'assessment.validate'), step('lock', 'assessment.validate'), tuned('publish', 'higher.exam.manage', 'examens')])));
    useWorkflowStore().reset();
    const again = probe();
    await flushPromises();
    expect(again.canAct(WF.grades, 'lock', ['assessment.validate'])).toBe(true);
  });

  it('route indisponible (503) ou absente (404) : droits historiques, sans erreur', async () => {
    setup(['higher.grades.publish'], null);
    const wf = probe();
    await flushPromises();
    expect(useWorkflowStore().state).toBe('unavailable');
    expect(wf.canAct(WF.grades, 'publish', ['higher.grades.publish'])).toBe(true);
    expect(wf.canAct(WF.grades, 'lock', ['assessment.validate'])).toBe(false);

    signIn({ ...teacherMe }, [higherTile(['higher.grades.publish'])]);
    mockApi({}); // 404 : « Route simulée absente »
    useWorkflowStore().reset();
    const none = probe();
    await flushPromises();
    expect(none.canAct(WF.grades, 'publish', ['higher.grades.publish'])).toBe(true);
  });

  it('un réglage désigne une autre fonction (droit + rôle) et une étape désactivée ferme le bouton', async () => {
    setup(['assessment.correction.approve'], withOverrides(circuit(WF.correction, [
      tuned('department', 'assessment.correction.approve', 'dir_ecole', 'higher_dir_ecole'),
      tuned('school', 'assessment.correction.approve', 'dir_ecole', 'higher_dir_ecole', { enabled: false }),
    ])), {}, 'higher_chef_dept');
    const wf = probe();
    await flushPromises();
    expect(wf.canAct(WF.correction, 'department', ['assessment.correction.approve'])).toBe(false); // le rôle exigé n'est pas le sien
    expect(wf.canAct(WF.correction, 'school', ['assessment.correction.approve'])).toBe(false); // étape désactivée
    expect(wf.stepEnabled(WF.correction, 'school')).toBe(false);
    expect(wf.stepEnabled(WF.correction, 'department')).toBe(true);
  });

  it('met en cache (une seule requête), se recharge après la durée de validité et à la demande', async () => {
    const calls = setup(['assessment.validate'], DEFAULTS);
    const count = () => calls.filter((c) => c.path === '/workflows/effective').length;
    probe();
    probe();
    await flushPromises();
    expect(count()).toBe(1);

    const now = Date.now();
    vi.spyOn(Date, 'now').mockReturnValue(now + WORKFLOW_CACHE_MS + 1);
    probe();
    await flushPromises();
    expect(count()).toBe(2);
    await useWorkflowStore().load(true);
    expect(count()).toBe(3);
    vi.restoreAllMocks();
  });

  it('le circuit de l’annulation de paiement : défaut = billing.reversal.approve, réglage = droit de la direction', async () => {
    setup(['billing.reversal.approve'], DEFAULTS);
    const base = probe();
    await flushPromises();
    expect(base.canAct(WF.reversal, 'approval', ['billing.reversal.approve'])).toBe(true);

    setup(['billing.reversal.approve'], withOverrides(circuit(WF.reversal, [step('request', 'billing.reversal.request'), tuned('approval', 'billing.reversal.approve.direction', 'direction')])));
    useWorkflowStore().reset();
    const tunedWf = probe();
    await flushPromises();
    expect(tunedWf.canAct(WF.reversal, 'approval', ['billing.reversal.approve'])).toBe(false);

    setup(['billing.reversal.approve.direction'], withOverrides(circuit(WF.reversal, [tuned('approval', 'billing.reversal.approve.direction', 'direction')])), {}, 'direction_groupe');
    useWorkflowStore().reset();
    const dir = probe();
    await flushPromises();
    expect(dir.canAct(WF.reversal, 'approval', ['billing.reversal.approve'])).toBe(true);
  });
});

// --- notes : cycle de vie -----------------------------------------------------------------------------------------------------

const assessment = (status: string) => ({
  id: 'A1', school_id: 'S1', department_id: 'D1', program_id: 'PR1', level_id: 'L1', group_id: null, academic_year_id: 'Y1', curriculum_id: 'C1', unit_id: 'U1',
  element_id: 'EC1', element_code: 'INF111', element_title: 'Algorithmique', semester_ordinal: 1, kind: 'cc', exam_session: 'normal', label: 'Devoir 1',
  held_on: null, entry_due_on: '2026-10-20', max_score: 20, max_score_label: '20', weight_percent: 40, score_step: 0.25, anonymous: false, anonymity_lifted: false,
  status, status_trail: [], version: 1, created_by: 'U1', created_at: '2026-10-01T08:00:00Z', updated_at: '2026-10-01T08:00:00Z',
});
const grid = (status: string, next: string[]) => ({
  data: {
    assessment: assessment(status), editable: false, next_states: next, absence_codes: IpesDefaultsCodes,
    lines: [{ mark_id: 'M1', person_id: 'P1', matricule: 'MAT-01', last_name: 'Ngono', first_name: 'Rachel', group_id: null, anonymous_code: null, score: 12, absence: null, version: 2 }],
    entered: 1, total: 1,
  },
});
const open = async (perms: string[], circuits: EffectiveCircuit[] | null, status: string, next: string[], role = 'enseignant') => {
  setup(perms, circuits, { 'GET /higher/grade-assessments/A1/marks': grid(status, next) }, role);
  return mountAt(AssessmentGridView, '/superieur/notes/evaluations/A1', { id: 'A1' });
};
const hasButton = (w: { find: (s: string) => { exists: () => boolean } }, name: string): boolean => w.find(`[data-test="life-${name}"]`).exists();

describe('boutons des notes (verrouillage, publication)', () => {
  it('SANS configuration : affichage identique à aujourd’hui (droits historiques)', async () => {
    const publish = await open(['higher.access', 'higher.grades.read', 'higher.grades.publish'], DEFAULTS, 'locked', ['published']);
    expect(hasButton(publish.wrapper, 'publish')).toBe(true);

    const examsOnly = await open(['higher.access', 'higher.grades.read', 'higher.exam.manage'], DEFAULTS, 'locked', ['published']);
    expect(hasButton(examsOnly.wrapper, 'publish')).toBe(false);

    const lock = await open(['higher.access', 'higher.grades.read', 'assessment.validate'], DEFAULTS, 'validated', ['locked']);
    expect(hasButton(lock.wrapper, 'lock')).toBe(true);
    const noLock = await open(['higher.access', 'higher.grades.read', 'higher.grades.publish'], DEFAULTS, 'validated', ['locked']);
    expect(hasButton(noLock.wrapper, 'lock')).toBe(false);
  });

  it('route des circuits indisponible : identique à aujourd’hui', async () => {
    const publish = await open(['higher.access', 'higher.grades.read', 'higher.grades.publish'], null, 'locked', ['published']);
    expect(hasButton(publish.wrapper, 'publish')).toBe(true);
    const examsOnly = await open(['higher.access', 'higher.grades.read', 'higher.exam.manage'], null, 'locked', ['published']);
    expect(hasButton(examsOnly.wrapper, 'publish')).toBe(false);
  });

  it('SURCHARGE : la publication revient au service des examens, le bouton suit', async () => {
    const circuits = withOverrides(circuit(WF.grades, [step('validate', 'assessment.validate'), step('lock', 'assessment.validate'), tuned('publish', 'higher.exam.manage', 'examens')]));
    const exams = await open(['higher.access', 'higher.grades.read', 'higher.exam.manage'], circuits, 'locked', ['published']);
    expect(hasButton(exams.wrapper, 'publish')).toBe(true);
    const registrar = await open(['higher.access', 'higher.grades.read', 'higher.grades.publish'], circuits, 'locked', ['published']);
    expect(hasButton(registrar.wrapper, 'publish')).toBe(false);
  });

  it('SURCHARGE : le verrouillage revient au directeur d’école (droit et rôle)', async () => {
    const circuits = withOverrides(circuit(WF.grades, [step('validate', 'assessment.validate'), tuned('lock', 'assessment.correction.approve', 'dir_ecole', 'higher_dir_ecole'), step('publish', 'higher.grades.publish')]));
    const director = await open(['higher.access', 'higher.grades.read', 'assessment.correction.approve'], circuits, 'validated', ['locked'], 'higher_dir_ecole');
    expect(hasButton(director.wrapper, 'lock')).toBe(true);
    const head = await open(['higher.access', 'higher.grades.read', 'assessment.validate'], circuits, 'validated', ['locked'], 'higher_chef_dept');
    expect(hasButton(head.wrapper, 'lock')).toBe(false);
  });

  it('SURCHARGE : validation facultative sautée, la saisie soumise se verrouille directement', async () => {
    const circuits = withOverrides(circuit(WF.grades, [tuned('validate', 'assessment.validate', 'chef_dept', null, { enabled: false }), step('lock', 'assessment.validate'), step('publish', 'higher.grades.publish')]));
    const skipped = await open(['higher.access', 'higher.grades.read', 'assessment.validate'], circuits, 'submitted', ['draft', 'locked'], 'higher_chef_dept');
    expect(hasButton(skipped.wrapper, 'validate')).toBe(false);
    expect(hasButton(skipped.wrapper, 'lock')).toBe(true);
    expect(hasButton(skipped.wrapper, 'sendBack')).toBe(true);

    // Sans réglage, une saisie soumise ne se verrouille pas directement (rien ne change).
    const normal = await open(['higher.access', 'higher.grades.read', 'assessment.validate'], DEFAULTS, 'submitted', ['validated', 'draft'], 'higher_chef_dept');
    expect(hasButton(normal.wrapper, 'validate')).toBe(true);
    expect(hasButton(normal.wrapper, 'lock')).toBe(false);
  });
});

// --- corrections --------------------------------------------------------------------------------------------------------------

describe('boutons de la seconde validation des corrections', () => {
  const correction = {
    id: 'C2', mark_id: 'M1', assessment_id: 'A1', element_code: 'INF111', assessment_label: 'Examen', person_id: 'P1', old: { score: 8, absence: null }, new: { score: 11, absence: null },
    reason: 'Erreur de report', attachment_ref: 'GED-1', query_id: null, requested_by: 'U9', status: 'pending', approval_step: 0,
    next_step: { label: 'Chef de département', role: 'higher_chef_dept', require_recent_2fa: false }, steps: [], approval_trail: [], decided_at: null, created_at: '2026-10-10T08:00:00Z',
  };
  const open2 = async (circuits: EffectiveCircuit[] | null, role: string) => {
    setup(['higher.access', 'higher.grades.read', 'assessment.correction.approve'], circuits, { 'GET /higher/grade-corrections': { data: [correction] } }, role);
    return mountAt(CorrectionsView, '/superieur/notes/corrections');
  };
  const approvals = (w: { findAll: (s: string) => unknown[] }): number => w.findAll('[data-test="approve"]').length;

  it('SANS configuration : le bouton suit le droit historique', async () => {
    expect(approvals((await open2(DEFAULTS, 'higher_chef_dept')).wrapper)).toBe(1);
    expect(approvals((await open2(null, 'higher_chef_dept')).wrapper)).toBe(1);
  });

  it('SURCHARGE : les deux validations reviennent au directeur d’école, le chef de département ne voit plus le bouton', async () => {
    const circuits = withOverrides(circuit(WF.correction, [
      tuned('department', 'assessment.correction.approve', 'dir_ecole', 'higher_dir_ecole'),
      tuned('school', 'assessment.correction.approve', 'dir_ecole', 'higher_dir_ecole'),
    ]));
    expect(approvals((await open2(circuits, 'higher_dir_ecole')).wrapper)).toBe(1);
    expect(approvals((await open2(circuits, 'higher_chef_dept')).wrapper)).toBe(0);
  });
});

// --- bourses et échéanciers ---------------------------------------------------------------------------------------------------

describe('boutons des bourses et des échéanciers', () => {
  const account = {
    id: 'AC1', academic_year_id: 'Y1', person_id: 'P1', school_id: 'S1', program_id: null, level_id: null, invoice_id: 'I1', payer_name: null,
    net_xaf: 500000, paid_xaf: 0, balance_xaf: 500000, up_to_date: true, installments: [], receipts: [],
  };
  const scholarship = {
    id: 'SC1', account_id: 'AC1', person_id: 'P1', scholarship_kind: 'merit', calc_mode: 'percent', rate_value: 10, applies_to: 'tuition', amount_xaf: 50000, reason: 'Major de promotion',
    status: 'pending', requested_by: 'U9', decided_by: null, decided_at: null, created_at: '2026-10-10T08:00:00Z',
  };
  const accountRoutes = {
    'GET /higher/billing/accounts/AC1': { data: account },
    'GET /higher/billing/accounts/AC1/scholarships': { data: [scholarship] },
    'GET /higher/billing/holds/P1': { data: { person_id: 'P1', balance_xaf: 500000, blocks: {} } },
    'GET /higher/billing/accounts': page([]),
    'GET /higher/billing/sponsors': page([]),
  };
  const directionCircuits = withOverrides(circuit(WF.scholarship, [step('request', 'billing.manage'), tuned('approval', 'higher.settings.sensitive', 'direction_generale')]));
  const approveButtons = (w: { findAll: (s: string) => unknown[] }): number => w.findAll('[data-scholarship] button.btn--quiet').length;

  it('bourse, SANS configuration : le comptable valide (identique à aujourd’hui)', async () => {
    setup(['higher.access', 'higher.billing.read', 'billing.manage'], DEFAULTS, accountRoutes, 'comptable');
    const { wrapper } = await mountAt(AccountView, '/superieur/comptes/AC1', { id: 'AC1' });
    expect(approveButtons(wrapper)).toBe(1);

    setup(['higher.access', 'higher.billing.read', 'higher.settings.sensitive'], DEFAULTS, accountRoutes, 'direction');
    const dir = await mountAt(AccountView, '/superieur/comptes/AC1', { id: 'AC1' });
    expect(approveButtons(dir.wrapper)).toBe(0);
  });

  it('bourse, SURCHARGE : la direction générale valide, le comptable ne voit plus le bouton', async () => {
    setup(['higher.access', 'higher.billing.read', 'higher.settings.sensitive'], directionCircuits, accountRoutes, 'direction');
    const dir = await mountAt(AccountView, '/superieur/comptes/AC1', { id: 'AC1' });
    expect(approveButtons(dir.wrapper)).toBe(1);

    setup(['higher.access', 'higher.billing.read', 'billing.manage'], directionCircuits, accountRoutes, 'comptable');
    const accountant = await mountAt(AccountView, '/superieur/comptes/AC1', { id: 'AC1' });
    expect(approveButtons(accountant.wrapper)).toBe(0);
  });

  it('échéancier, SANS configuration : le comptable voit la validation ; la direction seule ne voit rien', async () => {
    setup(['higher.access', 'higher.billing.read', 'billing.manage'], DEFAULTS, accountRoutes, 'comptable');
    const accountant = await mountAt(AccountsView, '/superieur/comptes');
    expect(accountant.wrapper.find('[data-test="plan-decide"]').exists()).toBe(true);
    expect(accountant.wrapper.find('[data-test="plan-request"]').exists()).toBe(true);

    setup(['higher.access', 'higher.billing.read', 'higher.settings.sensitive'], DEFAULTS, accountRoutes, 'direction');
    const dir = await mountAt(AccountsView, '/superieur/comptes');
    expect(dir.wrapper.find('[data-test="plan-decide"]').exists()).toBe(false);
  });

  it('échéancier, SURCHARGE : la direction valide sans pouvoir demander ; le comptable demande sans valider', async () => {
    setup(['higher.access', 'higher.billing.read', 'higher.settings.sensitive'], directionCircuits, accountRoutes, 'direction');
    const dir = await mountAt(AccountsView, '/superieur/comptes');
    expect(dir.wrapper.find('[data-test="plan-decide"]').exists()).toBe(true);
    expect(dir.wrapper.find('[data-test="plan-request"]').exists()).toBe(false);

    setup(['higher.access', 'higher.billing.read', 'billing.manage'], directionCircuits, accountRoutes, 'comptable');
    const accountant = await mountAt(AccountsView, '/superieur/comptes');
    expect(accountant.wrapper.find('[data-test="plan-decide"]').exists()).toBe(false);
    expect(accountant.wrapper.find('[data-test="plan-request"]').exists()).toBe(true);
  });
});

// --- décision d'admission -----------------------------------------------------------------------------------------------------

describe('bouton de la décision d’admission', () => {
  const application = {
    id: 'AP1', reference: 'CAND-1', campaign_id: 'CA1', person_id: 'P1', school_id: 'S1', program_id: 'PR1', level_id: 'L1', academic_year_id: 'Y1', channel: 'online', application_kind: 'new',
    origin_institution: null, diploma: 'bac', diploma_series: 'D', diploma_year: 2026, diploma_mention: null, status: 'complete',
    fee: { status: 'paid', invoice_id: null, payment_id: null, receipt_number: 'R-1', refundable: false }, score_hundredths: null, rank_position: null, list_kind: null,
    decision_reason: null, conditional_until: null, over_capacity: false, enrolment_id: null, submitted_at: null, completed_at: null, created_at: '2026-10-10T08:00:00Z', documents: [], decisions: [],
  };
  const routesMap = {
    'GET /higher/admissions/applications/AP1': { data: application },
    'GET /higher/admissions/campaigns/CA1': { data: { id: 'CA1', title: 'Rentrée 2026', seats: 40, prerequisites: {}, required_documents: [] } },
    'GET /higher/admissions/campaigns/CA1/statistics': { data: { campaign_id: 'CA1', seats: 40, applications: 1, by_status: {}, by_channel: {}, seats_taken: 0, seats_left: 40, over_capacity_admissions: 0, fees_collected_xaf: 0, enrolment_rate_percent: 0 } },
  };
  const decision = async (perms: string[], circuits: EffectiveCircuit[] | null, role: string) => {
    setup(['higher.access', 'higher.admission.read', ...perms], circuits, routesMap, role);
    const { wrapper } = await mountAt(ApplicationView, '/superieur/candidatures/AP1', { id: 'AP1' });
    return wrapper.find('[data-test="decision"]').exists();
  };

  it('SANS configuration : le directeur d’école décide, la scolarité seule ne décide pas (identique à aujourd’hui)', async () => {
    expect(await decision(['higher.admission.decide'], DEFAULTS, 'higher_dir_ecole')).toBe(true);
    expect(await decision(['higher.application.manage'], DEFAULTS, 'higher_scolarite')).toBe(false);
    expect(await decision(['higher.admission.decide'], null, 'higher_dir_ecole')).toBe(true);
  });

  it('SURCHARGE : la scolarité devient la décisionnaire, le directeur d’école ne voit plus le bouton', async () => {
    const circuits = withOverrides(circuit(WF.admission, [tuned('decision', 'higher.application.manage', 'scolarite')]));
    expect(await decision(['higher.application.manage'], circuits, 'higher_scolarite')).toBe(true);
    expect(await decision(['higher.admission.decide'], circuits, 'higher_dir_ecole')).toBe(false);
  });
});

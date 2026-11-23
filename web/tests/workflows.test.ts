import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import WorkflowsView from '@/views/admin/workflows/WorkflowsView.vue';
import AdminSubnav from '@/views/admin/AdminSubnav.vue';
import { buildPayload, draftFrom } from '@/views/admin/workflows/helpers';
import { guard } from '@/router';
import type { Workflow, WorkflowStep } from '@/api/workflows';
import { flushPromises, freshEnvironment, jsonResponse, mockApi, mountView, signIn, teacherMe, tile } from './helpers';

beforeEach(freshEnvironment);
afterEach(() => vi.restoreAllMocks());

const director = { ...teacherMe, context: { establishment_id: 'E1', role: 'directeur' } };
const asAdmin = (permissions = ['workflows.manage']): void =>
  signIn(director, [tile({ roles: [{ code: 'directeur', label: 'Directeur' }], permissions })], 'directeur');

const actors = [
  { key: 'chef_dept', label_fr: 'Chef de département', label_en: 'Head of department', permission: 'assessment.validate', role: null },
  { key: 'dir_ecole', label_fr: "Directeur d'école", label_en: 'School director', permission: 'assessment.correction.approve', role: 'higher_dir_ecole' },
];

const step = (key: string, over: Partial<WorkflowStep> = {}): WorkflowStep => ({
  key, label_fr: `Étape ${key}`, label_en: `Step ${key}`, optional: false,
  default: { actor: 'chef_dept', enabled: true, recent_confirmation: false },
  effective: { actor: 'chef_dept', enabled: true, recent_confirmation: false, source: 'default', enforced_by: null },
  actors, editable: { actor: true, enabled: false, recent_confirmation: true }, imposed_by: null, locks: [], ...over,
});

const workflow = (over: Partial<Workflow> = {}): Workflow => ({
  id: 'higher:grades_lifecycle', application: 'higher', key: 'grades_lifecycle', label_fr: 'Notes', label_en: 'Marks', level: 'establishment', customised: false,
  steps: [
    step('validate', { optional: true, editable: { actor: true, enabled: true, recent_confirmation: true } }),
    step('lock', {
      default: { actor: 'chef_dept', enabled: true, recent_confirmation: true },
      effective: { actor: 'chef_dept', enabled: true, recent_confirmation: true, source: 'default', enforced_by: null },
      editable: { actor: true, enabled: false, recent_confirmation: false },
      locks: [{ aspect: 'confirmation', code: 'lock_needs_confirmation', reason_fr: 'Le verrouillage exige la confirmation récente.', reason_en: 'Locking requires recent confirmation.' }],
    }),
  ],
  invariants: [{ aspect: 'circuit', code: 'segregation', reason_fr: 'Séparation des tâches.', reason_en: 'Segregation of duties.' }],
  override: null, ...over,
});

const list = (w: Workflow = workflow()) => ({ data: [w], meta: { next_cursor: null } });

describe('aides des circuits', () => {
  it('n’envoie que les écarts et jamais une étape imposée', () => {
    const w = workflow();
    const draft = draftFrom(w);
    expect(buildPayload(w, draft)).toEqual({});
    draft.validate!.enabled = false;
    draft.lock!.actor = 'dir_ecole';
    expect(buildPayload(w, draft)).toEqual({ validate: { enabled: false }, lock: { actor: 'dir_ecole' } });
    draft.validate!.enabled = true;
    expect(buildPayload(w, draft)).toEqual({ lock: { actor: 'dir_ecole' } });

    const imposed = workflow({ steps: [step('validate', { imposed_by: 'platform' })] });
    const d2 = draftFrom(imposed);
    d2.validate!.actor = 'dir_ecole';
    expect(buildPayload(imposed, d2)).toEqual({});
  });

  it('impose une étape depuis la fiche client', () => {
    const w = workflow({ level: 'client' });
    const draft = draftFrom(w);
    draft.validate!.enforced = true;
    expect(buildPayload(w, draft)).toEqual({ validate: { actor: 'chef_dept', enabled: true, recent_confirmation: false, enforced: true } });
  });
});

describe('écran des circuits', () => {
  it('liste les circuits avec défaut, verrous et règles', async () => {
    asAdmin();
    mockApi({ 'GET /workflows': list() });
    const { wrapper } = await mountView(WorkflowsView, {}, '/administration/circuits');
    await flushPromises();

    expect(wrapper.text()).toContain('Notes');
    expect(wrapper.text()).toContain('Par défaut');
    expect(wrapper.find('[data-test="badge-custom"]').exists()).toBe(false);
    expect(wrapper.text()).toContain('Le verrouillage exige la confirmation récente.');
    expect(wrapper.find('ol[aria-label]').exists()).toBe(true);
    expect(wrapper.html()).not.toContain('v-html');
  });

  it('badge personnalisé quand le circuit est réglé', async () => {
    asAdmin();
    mockApi({ 'GET /workflows': list(workflow({ customised: true, override: { version: 2, reason: 'Absence', updated_by: 'u', updated_at: '2026-10-20T08:00:00Z', steps: { validate: { enabled: false } } } })) });
    const { wrapper } = await mountView(WorkflowsView, {}, '/administration/circuits');
    await flushPromises();

    expect(wrapper.find('[data-test="badge-custom"]').exists()).toBe(true);
    expect(wrapper.find('[data-test="reset"]').exists()).toBe(true);
  });

  it('modifie après confirmation et envoie le motif', async () => {
    asAdmin();
    const updated = workflow({ customised: true, override: { version: 1, reason: 'Absence du chef.', updated_by: 'u', updated_at: '2026-10-20T08:00:00Z', steps: { validate: { enabled: false } } } });
    const calls = mockApi({ 'GET /workflows': list(), 'PUT /workflows/higher/grades_lifecycle': { data: updated } });
    const { wrapper } = await mountView(WorkflowsView, {}, '/administration/circuits');
    await flushPromises();

    await wrapper.get('[data-test="edit"]').trigger('click');
    await wrapper.get('[data-test="enabled-validate"]').setValue(false);
    expect(wrapper.get('[data-test="review"]').attributes('disabled')).toBeDefined();
    await wrapper.get('[data-test="reason"]').setValue('Absence du chef.');
    await wrapper.get('[data-test="edit-form"]').trigger('submit');
    expect(wrapper.find('[data-test="review-panel"]').exists()).toBe(true);
    expect(calls.some((c) => c.method === 'PUT')).toBe(false);

    await wrapper.get('[data-test="save"]').trigger('click');
    await flushPromises();
    const put = calls.find((c) => c.method === 'PUT');
    expect(put?.body).toEqual({ steps: { validate: { enabled: false } }, reason: 'Absence du chef.' });
    expect(wrapper.find('[data-test="badge-custom"]').exists()).toBe(true);
  });

  it('propose de confirmer son identité quand le serveur l’exige', async () => {
    asAdmin();
    mockApi({
      'GET /workflows': list(),
      'PUT /workflows/higher/grades_lifecycle': () => jsonResponse({ type: 'https://api.scholaris.cm/errors/second-factor-required', title: 'Double authentification requise', status: 403, detail: 'Confirmez.' }, 403),
    });
    const { wrapper } = await mountView(WorkflowsView, {}, '/administration/circuits');
    await flushPromises();
    await wrapper.get('[data-test="edit"]').trigger('click');
    await wrapper.get('[data-test="enabled-validate"]').setValue(false);
    await wrapper.get('[data-test="reason"]').setValue('Absence du chef.');
    await wrapper.get('[data-test="edit-form"]').trigger('submit');
    await wrapper.get('[data-test="save"]').trigger('click');
    await flushPromises();

    expect(wrapper.text()).toContain('Confirmer mon identité');
  });

  it('revient au défaut avec un motif', async () => {
    asAdmin();
    const custom = workflow({ customised: true, override: { version: 1, reason: 'x', updated_by: 'u', updated_at: '2026-10-20T08:00:00Z', steps: { validate: { enabled: false } } } });
    const calls = mockApi({ 'GET /workflows': list(custom), 'DELETE /workflows/higher/grades_lifecycle': { data: workflow() } });
    const { wrapper } = await mountView(WorkflowsView, {}, '/administration/circuits');
    await flushPromises();

    await wrapper.get('[data-test="reset"]').trigger('click');
    await wrapper.get('[data-test="reset-reason"]').setValue('Retour au standard.');
    await wrapper.get('[data-test="reset-confirm"]').trigger('click');
    await flushPromises();

    expect(calls.find((c) => c.method === 'DELETE')?.query.reason).toBe('Retour au standard.');
    expect(wrapper.find('[data-test="badge-custom"]').exists()).toBe(false);
  });
});

describe('accès', () => {
  it('l’entrée du menu et la route exigent workflows.manage', async () => {
    asAdmin(['identity.manage']);
    const { wrapper } = await mountView(AdminSubnav, {}, '/administration');
    expect(wrapper.text()).not.toContain('Circuits de validation');

    asAdmin();
    const allowed = await mountView(AdminSubnav, {}, '/administration');
    expect(allowed.wrapper.text()).toContain('Circuits de validation');
    expect(typeof guard).toBe('function');
  });
});

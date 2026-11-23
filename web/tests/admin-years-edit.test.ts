import 'fake-indexeddb/auto';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import AdminYearsView from '@/views/admin/AdminYearsView.vue';
import { flushPromises, freshEnvironment, jsonResponse, mockApi, mountView, signIn, teacherMe, tile } from './helpers';

// ANNEE-EDIT : modifier une année et une période, supprimer une période (confirmation), rien sans organisation.manage.
beforeEach(freshEnvironment);
afterEach(() => vi.restoreAllMocks());

const directorMe = { ...teacherMe, context: { establishment_id: 'E1', role: 'directeur' } };
const directorTile = tile({ type: 'higher', roles: [{ code: 'directeur', label: 'Directeur' }] });
const page = (items: unknown[]) => ({ data: items, links: { next: null }, meta: { next_cursor: null } });
const year = { id: 'Y1', label: '2026-2027', starts_on: '2026-09-07', ends_on: '2027-07-30', status: 'open', closed_at: null };
const s1 = { id: 'S1', academic_year_id: 'Y1', kind: 'semester', ordinal: 1, starts_on: '2026-09-07', ends_on: '2026-11-27', parent_id: null };
const s2 = { id: 'S2', academic_year_id: 'Y1', kind: 'semester', ordinal: 2, starts_on: '2026-11-28', ends_on: '2027-07-30', parent_id: null };
const report = { data: { academic_year_id: 'Y1', ready: true, blocking_count: 0, not_evaluable_count: 0, checks: [], closure: null } };
const problem = (status: number, detail: string, errors?: Record<string, string[]>) =>
  jsonResponse({ type: 'https://api.scholaris.cm/errors/x', title: 'Erreur', status, detail, trace_id: 'TR-Y', ...(errors ? { errors } : {}) }, status);

async function open(handlers: Parameters<typeof mockApi>[0]) {
  const calls = mockApi({ 'GET /academic-years': page([year]), 'GET /periods': page([s1, s2]), 'GET /academic-years/Y1/closure-check': report, ...handlers });
  const { wrapper } = await mountView(AdminYearsView, {}, '/administration/annees');
  await wrapper.get('tbody button').trigger('click');
  await flushPromises();
  return { wrapper, calls };
}

describe('modifier une année et ses périodes', () => {
  it('modifie l’année puis le Semestre 1, et affiche l’erreur du serveur sous le champ', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    let refuse = true;
    const { wrapper, calls } = await open({
      'PATCH /academic-years/Y1': (c) => ({ data: { ...year, ...c.body } }),
      'PATCH /periods/S1': (c) =>
        refuse
          ? problem(422, 'Données invalides.', { starts_on: ['Ces dates chevauchent « Semestre 2 » (du 28/11/2026 au 30/07/2027) : modifiez d’abord cette période.'] })
          : { data: { ...s1, ...c.body }, meta: { warnings: ['Cette période est déjà utilisée (3 évaluation(s)) : ses nouvelles dates s’appliquent aussi à ces données.'] } },
    });

    await wrapper.get('[data-test="year-edit"]').trigger('click');
    expect((wrapper.get('#ye-label').element as HTMLInputElement).value).toBe('2026-2027');
    await wrapper.get('#ye-label').setValue('2026-2027 IPES');
    await wrapper.get('form[aria-labelledby="y-edit"]').trigger('submit');
    await flushPromises();
    expect(calls.find((c) => c.method === 'PATCH' && c.path === '/academic-years/Y1')?.body).toEqual({ label: '2026-2027 IPES', starts_on: '2026-09-07', ends_on: '2027-07-30' });
    expect(wrapper.text()).toContain('Année 2026-2027 IPES enregistrée.');

    await wrapper.get('[data-test="period-edit"]').trigger('click');
    expect(wrapper.get('#p-edit').text()).toBe('Modifier Semestre 1');
    await wrapper.get('#pe-end').setValue('2027-02-14');
    await wrapper.get('form[aria-labelledby="p-edit"]').trigger('submit');
    await flushPromises();
    expect(wrapper.get('#pe-start-err').text()).toContain('chevauchent « Semestre 2 »');
    expect(wrapper.get('#pe-start').attributes('aria-invalid')).toBe('true');

    refuse = false;
    await wrapper.get('form[aria-labelledby="p-edit"]').trigger('submit');
    await flushPromises();
    const patches = calls.filter((c) => c.method === 'PATCH' && c.path === '/periods/S1');
    expect(patches.at(-1)?.body).toEqual({ kind: 'semester', ordinal: 1, starts_on: '2026-09-07', ends_on: '2027-02-14', parent_id: null });
    expect(wrapper.find('form[aria-labelledby="p-edit"]').exists()).toBe(false);
    expect(wrapper.text()).toContain('Semestre 1 enregistrée.');
    expect(wrapper.get('[data-test="period-warnings"]').text()).toContain('déjà utilisée (3 évaluation(s))');
  });

  it('supprime une période après confirmation, et n’appelle rien si l’on annule', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    const { wrapper, calls } = await open({ 'DELETE /periods/S2': { data: { id: 'S2', deleted: true } } });
    const confirm = vi.spyOn(window, 'confirm').mockReturnValueOnce(false).mockReturnValueOnce(true);

    const second = () => wrapper.findAll('[data-test="period-delete"]')[1]!;
    await second().trigger('click');
    await flushPromises();
    expect(confirm).toHaveBeenCalledWith(expect.stringContaining('Supprimer « Semestre 2 » ?'));
    expect(calls.some((c) => c.method === 'DELETE')).toBe(false);

    await second().trigger('click');
    await flushPromises();
    expect(calls.some((c) => c.method === 'DELETE' && c.path === '/periods/S2')).toBe(true);
    expect(wrapper.findAll('[data-test="period-delete"]')).toHaveLength(1);
    expect(wrapper.text()).toContain('Semestre 2 supprimée.');
  });

  it('affiche le refus du serveur quand la période est utilisée', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    const { wrapper } = await open({ 'DELETE /periods/S1': problem(409, 'La période « Semestre 1 » est déjà utilisée (2 jury(s) de délibération) : elle ne peut pas être supprimée.') });
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    await wrapper.findAll('[data-test="period-delete"]')[0]!.trigger('click');
    await flushPromises();
    expect(wrapper.get('[role="alert"]').text()).toContain('déjà utilisée (2 jury(s) de délibération)');
    expect(wrapper.findAll('[data-test="period-delete"]')).toHaveLength(2);
  });

  it('masque Modifier et Supprimer sans organisation.manage et pour une année clôturée', async () => {
    signIn(directorMe, [tile({ type: 'higher', permissions: ['organisation.read'], roles: [{ code: 'directeur', label: 'Directeur' }] })], 'directeur');
    const { wrapper } = await open({});
    expect(wrapper.text()).toContain('Semestre');
    expect(wrapper.find('[data-test="year-edit"]').exists()).toBe(false);
    expect(wrapper.find('[data-test="period-edit"]').exists()).toBe(false);
    expect(wrapper.find('[data-test="period-delete"]').exists()).toBe(false);
    wrapper.unmount();

    signIn(directorMe, [directorTile], 'directeur');
    mockApi({ 'GET /academic-years': page([{ ...year, status: 'closed', closed_at: '2027-07-31T10:00:00+01:00' }]), 'GET /periods': page([s1]), 'GET /academic-years/Y1/closure-check': report });
    const closed = (await mountView(AdminYearsView, {}, '/administration/annees')).wrapper;
    await closed.get('tbody button').trigger('click');
    await flushPromises();
    expect(closed.find('[data-test="year-edit"]').exists()).toBe(false);
    expect(closed.find('[data-test="period-edit"]').exists()).toBe(false);
    expect(closed.find('[data-test="period-delete"]').exists()).toBe(false);
  });
});

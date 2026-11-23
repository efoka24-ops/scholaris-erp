import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import { mount } from '@vue/test-utils';
import type { Component } from 'vue';
import { routes } from '@/router/routes';
import { setLocale } from '@/i18n';
import MigrationView from '@/views/higher/migration/MigrationView.vue';
import TranscriptsView from '@/views/higher/deliberation/TranscriptsView.vue';
import { flushPromises, freshEnvironment, jsonResponse, mockApi, signIn, teacherMe, tile } from './helpers';

const PERMS = ['higher.access', 'higher.migration.manage', 'higher.deliberation.read', 'higher.deliberation.prepare'];
const higherMe = { data: { application: { key: 'higher', state: 'active', writable: true }, establishment_id: 'E1', roles: [], all_schools: true, school_ids: [], school_selector: false } };
const years = { data: [{ id: 'AY1', label: '2026-2027' }], meta: {} };
const kinds = {
  data: [{ kind: 'students', label: 'Étudiants', columns: ['matricule', 'nom'], required: ['nom', 'prenom', 'filiere'] }],
};
const counts = (over: Record<string, number> = {}) => ({ total: 3, ok: 1, warning: 0, duplicate: 0, error: 2, applied: 0, ...over });
const batch = (over: Record<string, unknown> = {}) => ({
  id: 'B1', kind: 'students', kind_label: 'Étudiants', academic_year_id: 'AY1', status: 'simulated', source_format: 'csv', file_name: 'reprise.csv', file_sha256: 'x',
  counts: counts(), applicable: false, created_by: 'U1', created_at: '2026-11-05T10:00:00+01:00', applied_at: null, reverted_at: null, revert_reason: null,
  unknown_columns: ['colonne_x'],
  lines: [
    { line: 3, status: 'error', key: '26EC-0002', errors: ['Niveau L9 inconnu pour cette filière.'], warnings: [] },
    { line: 4, status: 'error', key: '26EC-0003', errors: ['mineur : à inscrire par l\'écran Inscriptions.'], warnings: [] },
  ],
  lines_truncated: false,
  ...over,
});
const problem = (status: number, slug: string, detail: string) => jsonResponse({ type: `https://api.scholaris.cm/errors/${slug}`, title: 'Refus', status, detail, trace_id: 'TR-MIG' }, status);

async function mountAt(component: Component, path: string) {
  const router = createRouter({ history: createMemoryHistory(), routes });
  await router.push(path);
  await router.isReady();
  const wrapper = mount(component, { global: { plugins: [router] } });
  await flushPromises();
  return { wrapper, router };
}

async function chooseFile(wrapper: Awaited<ReturnType<typeof mountAt>>['wrapper'], file: File): Promise<void> {
  const input = wrapper.get('#mg-file');
  Object.defineProperty(input.element, 'files', { value: [file], configurable: true });
  await input.trigger('change');
}

beforeEach(() => {
  freshEnvironment();
  setLocale('fr');
  signIn({ ...teacherMe, context: { establishment_id: 'E1', role: 'higher_scolarite' } }, [tile({ type: 'higher', permissions: PERMS })], 'higher_scolarite');
});

describe('Reprise des données IPES', () => {
  it('téléverse le fichier en multipart, affiche le rapport ligne par ligne et bloque l\'application tant qu\'il reste une erreur', async () => {
    const appended: Array<[string, unknown]> = [];
    const spy = vi.spyOn(FormData.prototype, 'append').mockImplementation(function (this: FormData, key: string, value: string | Blob) {
      appended.push([key, value]);
    });
    const calls = mockApi({
      'GET /higher/me': higherMe, 'GET /academic-years': years, 'GET /higher/migration/kinds': kinds, 'GET /higher/migration/batches': { data: [] },
      'POST /higher/migration/batches': { data: batch() },
    });
    const { wrapper } = await mountAt(MigrationView, '/superieur/reprise-des-donnees');
    expect(wrapper.get('[data-test="columns"]').text()).toContain('nom, prenom, filiere');

    await wrapper.get('[data-test="upload"]').trigger('submit');
    expect(wrapper.text()).toContain('Choisissez un fichier.');
    await wrapper.get('#mg-year').setValue('AY1');
    await chooseFile(wrapper, new File(['nom;prenom\nA;B\n'], 'reprise.csv', { type: 'text/csv' }));
    await wrapper.get('[data-test="upload"]').trigger('submit');
    await flushPromises();
    spy.mockRestore();

    const post = calls.find((c) => c.method === 'POST' && c.path === '/higher/migration/batches');
    expect(post).toBeDefined();
    expect(post?.headers['Content-Type']).toBeUndefined();
    expect(appended.map(([k]) => k)).toEqual(['kind', 'academic_year_id', 'dry_run', 'file']);
    expect(appended.find(([k]) => k === 'dry_run')?.[1]).toBe('true');
    expect((appended.find(([k]) => k === 'file')?.[1] as File).name).toBe('reprise.csv');

    expect(wrapper.get('[data-test="count-error"]').text()).toBe('2');
    expect(wrapper.get('[data-test="line-3"]').text()).toContain('Niveau L9 inconnu');
    expect(wrapper.text()).toContain('colonne_x');
    expect(wrapper.get('[data-test="blocked"]').text()).toContain('2 erreur');
    expect((wrapper.get('[data-test="apply"]').element as HTMLButtonElement).disabled).toBe(true);
    expect(wrapper.html()).not.toContain('v-html');
  });

  it('applique un lot sans erreur après confirmation, puis propose l\'annulation motivée', async () => {
    const applied = batch({ status: 'applied', counts: counts({ error: 0, ok: 3, applied: 3 }), applicable: false, lines: [], unknown_columns: [] });
    const calls = mockApi({
      'GET /higher/me': higherMe, 'GET /academic-years': years, 'GET /higher/migration/kinds': kinds,
      'GET /higher/migration/batches': { data: [batch({ counts: counts({ error: 0, ok: 3 }), applicable: true, lines: [], unknown_columns: [] })] },
      'GET /higher/migration/batches/B1': { data: batch({ counts: counts({ error: 0, ok: 3 }), applicable: true, lines: [], unknown_columns: [] }) },
      'POST /higher/migration/batches/B1/apply': { data: applied },
      'POST /higher/migration/batches/B1/revert': { data: batch({ status: 'reverted', counts: counts({ error: 0, ok: 3, applied: 3 }), revert_reason: 'Fichier erroné', lines: [] }) },
    });
    const { wrapper } = await mountAt(MigrationView, '/superieur/reprise-des-donnees');
    await wrapper.get('[data-test="open-B1"]').trigger('click');
    await flushPromises();
    expect(wrapper.find('[data-test="blocked"]').exists()).toBe(false);
    await wrapper.get('[data-test="apply"]').trigger('click');
    expect(calls.some((c) => c.path.endsWith('/apply'))).toBe(false);
    await wrapper.get('[data-test="apply-yes"]').trigger('click');
    await flushPromises();
    expect(calls.filter((c) => c.path.endsWith('/apply'))).toHaveLength(1);
    expect(wrapper.get('[data-test="done"]').text()).toContain('3 ligne(s)');

    await wrapper.get('[data-test="revert"]').trigger('click');
    expect((wrapper.get('[data-test="revert-yes"]').element as HTMLButtonElement).disabled).toBe(true);
    await wrapper.get('#mg-reason').setValue('Fichier erroné');
    await wrapper.get('#mg-reason').element.closest('form')?.dispatchEvent(new Event('submit', { cancelable: true }));
    await flushPromises();
    expect(calls.find((c) => c.path.endsWith('/revert'))?.body).toEqual({ reason: 'Fichier erroné' });
    expect(wrapper.get('[data-test="done"]').text()).toContain('Lot annulé');
  });

  it('renvoie vers la double authentification quand l\'application l\'exige', async () => {
    mockApi({
      'GET /higher/me': higherMe, 'GET /academic-years': years, 'GET /higher/migration/kinds': kinds,
      'GET /higher/migration/batches': { data: [batch({ counts: counts({ error: 0, ok: 3 }), applicable: true, lines: [] })] },
      'GET /higher/migration/batches/B1': { data: batch({ counts: counts({ error: 0, ok: 3 }), applicable: true, lines: [] }) },
      'POST /higher/migration/batches/B1/apply': problem(403, 'second-factor-required', 'Double authentification récente exigée.'),
    });
    const { wrapper } = await mountAt(MigrationView, '/superieur/reprise-des-donnees');
    await wrapper.get('[data-test="open-B1"]').trigger('click');
    await flushPromises();
    await wrapper.get('[data-test="apply"]').trigger('click');
    await wrapper.get('[data-test="apply-yes"]').trigger('click');
    await flushPromises();
    expect(wrapper.find('[data-test="tfa"]').exists()).toBe(true);
  });

  it('affiche l\'écran en anglais et refuse un compte sans la permission de reprise', async () => {
    setLocale('en');
    mockApi({ 'GET /higher/me': higherMe, 'GET /academic-years': years, 'GET /higher/migration/kinds': kinds, 'GET /higher/migration/batches': { data: [] } });
    const { wrapper } = await mountAt(MigrationView, '/superieur/reprise-des-donnees');
    expect(wrapper.text()).toContain('Data migration');
    expect(wrapper.text()).toContain('Download the file template');

    freshEnvironment();
    setLocale('fr');
    signIn({ ...teacherMe, context: { establishment_id: 'E1', role: 'higher_scolarite' } }, [tile({ type: 'higher', permissions: ['higher.access'] })], 'higher_scolarite');
    mockApi({ 'GET /higher/me': higherMe, 'GET /academic-years': years, 'GET /higher/migration/kinds': kinds, 'GET /higher/migration/batches': { data: [] } });
    const denied = await mountAt(MigrationView, '/superieur/reprise-des-donnees');
    expect(denied.wrapper.find('[data-test="upload"]').exists()).toBe(false);
  });
});

describe('Relevé annuel DOC-10 (écran des relevés)', () => {
  const emptyList = { data: [], meta: {} };

  it('refuse d\'émettre sans année académique ni étudiant et n\'appelle pas le serveur', async () => {
    const calls = mockApi({
      'GET /higher/me': higherMe, 'GET /academic-years': years, 'GET /documents': emptyList, 'GET /higher/documents': emptyList,
      'POST /higher/deliberation/annual-transcripts': { data: { id: 'D1', number: 'RAN-EC-2026-000001', code: 'DOC-10' } },
    });
    const { wrapper } = await mountAt(TranscriptsView, '/superieur/deliberation/releves');
    await wrapper.get('[data-test="annual"] #an-year').setValue('AY1');
    await wrapper.get('[data-test="annual"] form').trigger('submit');
    await flushPromises();
    expect(calls.some((c) => c.path === '/higher/deliberation/annual-transcripts')).toBe(false);
    expect(wrapper.get('[data-test="annual"]').text()).toContain('Champ obligatoire');
  });

  it('émet pour une promotion et liste les étudiants ignorés avec leur raison', async () => {
    const calls = mockApi({
      'GET /higher/me': higherMe, 'GET /academic-years': years, 'GET /documents': emptyList, 'GET /higher/documents': emptyList,
      'GET /higher/groups': { data: [{ id: 'G1', code: 'G1', name: 'Groupe 1' }], meta: {} },
      'POST /higher/deliberation/annual-transcripts/batch': {
        data: {
          academic_year_id: 'AY1', structure_id: 'G1', total: 3, issued: [{ id: 'D1', number: 'RAN-EC-2026-000001', person_id: 'P1' }],
          skipped: [{ person_id: 'P2', matricule: 'IPES-0002', reason: 'already_issued' }, { person_id: 'P3', matricule: 'IPES-0003', reason: 'Aucune décision de fin d\'année enregistrée pour cet étudiant.' }],
        },
      },
    });
    const { wrapper } = await mountAt(TranscriptsView, '/superieur/deliberation/releves');
    await wrapper.get('#an-mode').setValue('batch');
    await wrapper.get('#an-year').setValue('AY1');
    await wrapper.get('#an-group').setValue('G1');
    await wrapper.get('[data-test="annual"] form').trigger('submit');
    await flushPromises();
    expect(calls.find((c) => c.path.endsWith('/annual-transcripts/batch'))?.body).toEqual({ structure_id: 'G1', academic_year_id: 'AY1', locale: 'fr' });
    expect(wrapper.get('[data-test="an-batch"]').text()).toContain('1 relevé(s) émis, 2 ignoré(s) sur 3 étudiant(s)');
    expect(wrapper.get('[data-test="skip-P2"]').text()).toContain('Déjà servi');
    expect(wrapper.get('[data-test="skip-P3"]').text()).toContain('Aucune décision');
  });

  it('affiche le refus du serveur quand le lot ne peut pas être émis (aucune décision annuelle)', async () => {
    mockApi({
      'GET /higher/me': higherMe, 'GET /academic-years': years, 'GET /documents': emptyList, 'GET /higher/documents': emptyList,
      'GET /higher/groups': { data: [{ id: 'G1', code: 'G1', name: 'Groupe 1' }], meta: {} },
      'POST /higher/deliberation/annual-transcripts/batch': problem(409, 'conflict', 'Aucune décision de fin d\'année enregistrée.'),
    });
    const { wrapper } = await mountAt(TranscriptsView, '/superieur/deliberation/releves');
    await wrapper.get('#an-mode').setValue('batch');
    await wrapper.get('#an-year').setValue('AY1');
    await wrapper.get('#an-group').setValue('G1');
    await wrapper.get('[data-test="annual"] form').trigger('submit');
    await flushPromises();
    expect(wrapper.find('[data-test="an-batch"]').exists()).toBe(false);
    expect(wrapper.get('[data-test="annual"] .notice--error').text()).toContain('décision de fin d\'année');
  });
});

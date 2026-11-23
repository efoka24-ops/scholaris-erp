import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ImportView from '@/views/admin/import/ImportView.vue';
import AdminSubnav from '@/views/admin/AdminSubnav.vue';
import { checkFile, credentialsCsv, credentialsSheetHtml, csvCell } from '@/views/admin/import/helpers';
import { guard } from '@/router';
import type { ImportBatch, ImportLineStatus, ProvisionalCredential } from '@/api/imports';
import { flushPromises, freshEnvironment, jsonResponse, mockApi, mountView, signIn, teacherMe, tile } from './helpers';

beforeEach(freshEnvironment);
afterEach(() => vi.restoreAllMocks());

const director = { ...teacherMe, context: { establishment_id: 'E1', role: 'directeur' } };
const asDirector = (permissions = ['imports.people.manage', 'identity.manage']): void =>
  signIn(director, [tile({ roles: [{ code: 'directeur', label: 'Directeur' }], permissions })], 'directeur');

const line = (n: number, status: ImportLineStatus, over: Record<string, unknown> = {}) => ({
  line: n, status, messages: status === 'error' ? ['Rôle inconnu.'] : [], last_name: `Nom${n}`, first_name: `Prenom${n}`, email: null, phone: '+237670000000',
  locale: 'fr', role: 'enseignant', scope_type: 'establishment', scope_code: null, starts_on: null, ends_on: null, note: null, person_id: null, user_id: null, ...over,
});

const batch = (over: Partial<ImportBatch> = {}): ImportBatch => ({
  id: 'B1', status: 'previewed', source_format: 'csv', file_name: 'acteurs.csv', created_at: 'x', expires_at: 'y', committed_at: null, credentials: null, send_codes: false,
  summary: { total: 3, valid: 1, error: 1, duplicate: 1, applied: 0, partial: 0, failed: 0 },
  lines: [line(2, 'valid'), line(3, 'error'), line(4, 'duplicate')], ...over,
} as ImportBatch);

const applied = batch({
  status: 'applied', committed_at: 'z', credentials: 'provisional_password',
  summary: { total: 3, valid: 0, error: 1, duplicate: 1, applied: 1, partial: 0, failed: 0 },
  lines: [line(2, 'applied')],
});
const secrets: ProvisionalCredential[] = [{ line: 2, login: '+237670000000', last_name: 'Nom2', first_name: 'Prenom2', role: 'enseignant', provisional_password: 'Zx9-Secret-Pw' }];

function pickFile(wrapper: ReturnType<typeof Object>, name = 'acteurs.csv', size = 100): void {
  const input = (wrapper as { get: (s: string) => { element: HTMLInputElement; trigger: (e: string) => Promise<void> } }).get('[data-test="import-file"]');
  const f = new File(['x'.repeat(10)], name, { type: 'text/csv' });
  Object.defineProperty(f, 'size', { value: size });
  Object.defineProperty(input.element, 'files', { value: [f], configurable: true });
  void input.trigger('change');
}

describe('aides de l’import', () => {
  it('contrôle type et taille du fichier', () => {
    expect(checkFile({ name: 'a.csv', size: 10 })).toBeNull();
    expect(checkFile({ name: 'A.XLSX', size: 10 })).toBeNull();
    expect(checkFile({ name: 'a.pdf', size: 10 })).toBe('type');
    expect(checkFile({ name: 'a.csv', size: 0 })).toBe('empty');
    expect(checkFile({ name: 'a.csv', size: 2 * 1024 * 1024 + 1 })).toBe('size');
  });

  it('neutralise les formules et garde les numéros lisibles dans le CSV', () => {
    expect(csvCell('=1+1')).toBe("'=1+1");
    expect(csvCell('+237670000000')).toBe('+237670000000');
    expect(csvCell('a;b')).toBe('"a;b"');
    const csv = credentialsCsv([{ ...secrets[0]!, provisional_password: '-abc' }], ['identifiant', 'mot de passe']);
    expect(csv.startsWith('﻿identifiant;mot de passe\r\n')).toBe(true);
    expect(csv).toContain("'-abc");
  });

  it('échappe le texte de la fiche imprimable', () => {
    const html = credentialsSheetHtml([{ login: 'l', password: '<script>x</script>', person: 'A & B', role: 'r' }], {
      title: 't', establishment: 'e', once: 'o', steps: 's', columns: { login: 'l', password: 'p', person: 'n', role: 'r' },
    });
    expect(html).not.toContain('<script>');
    expect(html).toContain('A &amp; B');
  });
});

describe('écran Importer des personnes', () => {
  it('n’est proposé qu’avec la permission imports.people.manage', async () => {
    asDirector(['identity.manage']);
    const { wrapper } = await mountView(AdminSubnav, {}, '/administration');
    expect(wrapper.text()).not.toContain('Importer des personnes');
    freshEnvironment();
    asDirector();
    const second = await mountView(AdminSubnav, {}, '/administration');
    expect(second.wrapper.text()).toContain('Importer des personnes');
  });

  it('le routeur renvoie vers « interdit » sans la permission', async () => {
    asDirector(['identity.manage']);
    const { router } = await mountView(ImportView, {}, '/');
    expect(await guard(router.resolve('/administration/import') as never)).toEqual({ name: 'forbidden' });
  });

  it('refuse un mauvais fichier côté navigateur sans appeler le serveur', async () => {
    asDirector();
    const calls = mockApi({});
    const { wrapper } = await mountView(ImportView, {}, '/administration/import');
    pickFile(wrapper, 'liste.pdf');
    await flushPromises(2);
    expect(wrapper.text()).toContain('ni un .csv ni un .xlsx');
    await wrapper.get('form').trigger('submit');
    await flushPromises(2);
    expect(calls.some((c) => c.method === 'POST')).toBe(false);
    pickFile(wrapper, 'gros.csv', 3 * 1024 * 1024);
    await flushPromises(2);
    expect(wrapper.text()).toContain('2 Mo au plus');
  });

  it('analyse, filtre l’aperçu, valide après confirmation et remet les mots de passe une seule fois', async () => {
    asDirector();
    const calls = mockApi({
      'POST /imports/people': jsonResponse({ data: batch(), meta: { unknown_columns: ['couleur'], allowed_roles: ['enseignant'] } }, 201),
      'POST /imports/people/B1/commit': { data: { ...applied, provisional_credentials: secrets }, meta: { replay: false } },
    });
    const { wrapper } = await mountView(ImportView, {}, '/administration/import');
    pickFile(wrapper);
    await flushPromises(2);
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    const post = calls.find((c) => c.path === '/imports/people');
    expect(post?.method).toBe('POST');
    expect(post?.headers['X-Establishment-Id']).toBe('E1');

    expect(wrapper.get('[data-test="import-summary"]').text()).toContain('3 lignes : 1 valides, 1 en erreur, 1 doublons');
    expect(wrapper.text()).toContain('couleur');
    expect(wrapper.findAll('tbody tr')).toHaveLength(3);
    await wrapper.get('[data-test="import-filter-error"]').trigger('click');
    expect(wrapper.findAll('tbody tr')).toHaveLength(1);
    expect(wrapper.text()).toContain('Rôle inconnu.');
    expect(wrapper.text()).toContain('Enseignant');
    await wrapper.get('[data-test="import-filter-duplicate"]').trigger('click');
    expect(wrapper.findAll('tbody tr')).toHaveLength(1);

    await wrapper.get('[data-test="import-next"]').trigger('click');
    await flushPromises(2);
    // « Envoyer les codes » est inactif avec le mot de passe provisoire (défaut).
    expect((wrapper.get('[data-test="import-send-codes"]').element as HTMLInputElement).disabled).toBe(true);
    await wrapper.get('[data-test="import-commit"]').trigger('click');
    expect(wrapper.find('[data-test="import-confirm"]').exists()).toBe(true);
    expect(calls.some((c) => c.path.endsWith('/commit'))).toBe(false);
    await wrapper.get('[data-test="import-confirm-yes"]').trigger('click');
    await flushPromises();

    expect(calls.find((c) => c.path.endsWith('/commit'))?.body).toEqual({ credentials: 'provisional_password', send_codes: false });
    expect(wrapper.get('[data-test="import-result"]').text()).toContain('1 comptes créés');
    expect(wrapper.get('[data-test="import-password"]').text()).toBe('Zx9-Secret-Pw');
    expect(wrapper.get('[data-test="import-once"]').text()).toContain('ne seront plus affichables');
    expect((wrapper.get('[data-test="import-close"]').element as HTMLButtonElement).disabled).toBe(true);
    // Rien de secret dans le stockage du navigateur.
    expect(JSON.stringify({ ...localStorage })).not.toContain('Zx9-Secret-Pw');
    expect(JSON.stringify({ ...sessionStorage })).not.toContain('Zx9-Secret-Pw');

    await wrapper.get('[data-test="import-ack"]').setValue(true);
    await wrapper.get('[data-test="import-close"]').trigger('click');
    expect(wrapper.text()).not.toContain('Zx9-Secret-Pw');
  });

  it('envoie le code d’activation avec l’envoi des codes et n’affiche aucun mot de passe', async () => {
    asDirector();
    const calls = mockApi({
      'POST /imports/people': jsonResponse({ data: batch(), meta: {} }, 201),
      'POST /imports/people/B1/commit': { data: { ...applied, credentials: 'activation_code', send_codes: true }, meta: { replay: false } },
    });
    const { wrapper } = await mountView(ImportView, {}, '/administration/import');
    pickFile(wrapper);
    await flushPromises(2);
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    await wrapper.get('[data-test="import-next"]').trigger('click');
    await flushPromises(2);
    await wrapper.get('[data-test="import-mode-code"]').setValue(true);
    await wrapper.get('[data-test="import-send-codes"]').setValue(true);
    await wrapper.get('[data-test="import-commit"]').trigger('click');
    await wrapper.get('[data-test="import-confirm-yes"]').trigger('click');
    await flushPromises();
    expect(calls.find((c) => c.path.endsWith('/commit'))?.body).toEqual({ credentials: 'activation_code', send_codes: true });
    expect(wrapper.find('[data-test="import-credentials"]').exists()).toBe(false);
    expect(wrapper.text()).toContain('codes d’activation ont été envoyés');
  });

  it('affiche l’erreur du serveur avec ErrorNotice', async () => {
    asDirector();
    mockApi({
      'POST /imports/people': jsonResponse({ type: 'about:blank', title: 'Invalide', status: 422, detail: 'Colonnes obligatoires absentes.', trace_id: 'T1', errors: { file: ['Colonnes obligatoires absentes.'] } }, 422),
    });
    const { wrapper } = await mountView(ImportView, {}, '/administration/import');
    pickFile(wrapper);
    await flushPromises(2);
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(wrapper.find('[role="alert"]').exists()).toBe(true);
    expect(wrapper.text()).toContain('Colonnes obligatoires absentes.');
  });

  it('reprend un lot en cours après rechargement (?lot=)', async () => {
    asDirector();
    const calls = mockApi({ 'GET /imports/people/B1': { data: batch() } });
    const { wrapper } = await mountView(ImportView, {}, '/administration/import?lot=B1');
    expect(calls.some((c) => c.path === '/imports/people/B1')).toBe(true);
    expect(wrapper.get('[data-test="import-summary"]').text()).toContain('3 lignes');
  });

  it('reprend un lot déjà appliqué sans jamais réafficher de mot de passe', async () => {
    asDirector();
    mockApi({ 'GET /imports/people/B1': { data: applied } });
    const { wrapper } = await mountView(ImportView, {}, '/administration/import?lot=B1');
    expect(wrapper.find('[data-test="import-replay"]').exists()).toBe(true);
    expect(wrapper.find('[data-test="import-credentials"]').exists()).toBe(false);
  });

  it('signale un lot introuvable ou expiré et revient au dépôt du fichier', async () => {
    asDirector();
    mockApi({});
    const { wrapper } = await mountView(ImportView, {}, '/administration/import?lot=GONE');
    expect(wrapper.find('[data-test="import-lost"]').exists()).toBe(true);
    expect(wrapper.find('[data-test="import-file"]').exists()).toBe(true);
  });

  it('télécharge le modèle et le fichier des erreurs', async () => {
    asDirector();
    const saved: string[] = [];
    URL.createObjectURL = vi.fn(() => 'blob:x');
    URL.revokeObjectURL = vi.fn();
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
      saved.push(this.download);
    });
    const calls = mockApi({
      'GET /imports/people/template': new Response('a;b', { status: 200, headers: { 'Content-Type': 'text/csv' } }),
      'GET /imports/people/B1': { data: batch() },
      'GET /imports/people/B1/errors.csv': new Response('ligne;etat', { status: 200, headers: { 'Content-Type': 'text/csv' } }),
    });
    const { wrapper } = await mountView(ImportView, {}, '/administration/import?lot=B1');
    await wrapper.get('[data-test="import-errors-csv"]').trigger('click');
    await flushPromises();
    expect(calls.some((c) => c.path === '/imports/people/B1/errors.csv')).toBe(true);
    expect(saved).toContain('erreurs_import_B1.csv');
    await wrapper.get('button.btn--quiet').trigger('click');
    await flushPromises();
    await wrapper.get('[data-test="import-template-xlsx"]').trigger('click');
    await flushPromises();
    expect(calls.find((c) => c.path === '/imports/people/template')?.query.format).toBe('xlsx');
    expect(saved).toContain('modele_import_acteurs.xlsx');
  });
});

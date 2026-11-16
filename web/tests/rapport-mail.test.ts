import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it } from 'vitest';
import ReportsView from '@/views/reports/ReportsView.vue';
import { setLocale } from '@/i18n';
import { flushPromises, freshEnvironment, mockApi, mountView, signIn, teacherMe, tile } from './helpers';

// RAPPORT-MAIL : motif de chaque échec d'un rapport planifié (Historique et exécution) et avertissements à l'enregistrement.
beforeEach(() => {
  freshEnvironment();
});

const page = (items: unknown[]) => ({ data: items, links: { next: null }, meta: { next_cursor: null } });
const directorMe = { ...teacherMe, id: 'U-DIR', person_id: 'P-DIR', context: { establishment_id: 'E1', role: 'directeur' } };
const asDirector = () => signIn(directorMe, [tile({ roles: [{ code: 'directeur', label: 'Directeur' }] })], 'directeur');
const schedule = { id: 'RS1', name: 'Direction quotidienne', report_type: 'direction', frequency: 'daily', run_day: null, recipient_ids: ['P-DIR', 'P-PROF', 'P-SEC'], status: 'active', next_run_on: '2030-10-14', last_run_at: null, last_run_status: null };
const failures = [
  { person_id: 'P-PROF', name: 'Moussa Fictif', reason: 'opted_out' },
  { person_id: 'P-SEC', name: 'Hawa Fictive', reason: 'no_contact' },
  { person_id: 'P-X', name: null, reason: 'gateway_error' },
  { person_id: 'P-Y', name: 'Paul Externe', reason: 'consent_missing' },
  { person_id: 'P-Z', name: 'Zoé Modèle', reason: 'template_missing' },
  { person_id: 'P-W', name: 'Wil Inconnu', reason: 'bizarre' },
];
const api = () => mockApi({
  'GET /report-schedules': page([schedule]),
  'POST /report-schedules/RS1/run': { data: { status: 'partial', sent_count: 1, failed_count: 6, failures } },
  'GET /report-schedules/RS1/runs': { data: [
    { id: 'RN2', run_key: 'manual-2', trigger_kind: 'manual', status: 'partial', sent_count: 1, failed_count: 6, summary: 'Étudiants inscrits : 12', created_at: '2030-10-09T10:00:00+01:00', failures },
    { id: 'RN1', run_key: 'manual-1', trigger_kind: 'manual', status: 'failed', sent_count: 0, failed_count: 2, summary: null, created_at: '2030-10-08T10:00:00+01:00' },
  ] },
  'POST /report-schedules': { data: { ...schedule, id: 'RS2' }, meta: { recipient_warnings: [{ person_id: 'P-DIR', reason: 'no_email' }] } },
});

describe('rapports planifiés : motifs des échecs', () => {
  it('affiche en clair le motif de chaque destinataire dans l\'Historique et après l\'exécution (fr)', async () => {
    asDirector();
    api();
    const { wrapper } = await mountView(ReportsView);
    await wrapper.get('[data-action="run"]').trigger('click');
    await flushPromises();
    const run = wrapper.get('[data-test="run-failures"]').text();
    expect(run).toContain('Moussa Fictif : destinataire désinscrit');
    expect(run).toContain('Hawa Fictive : pas d’adresse e-mail sur la fiche');

    await wrapper.get('[data-action="runs"]').trigger('click');
    await flushPromises();
    const cells = wrapper.findAll('[data-test="run-failure-reasons"]');
    expect(cells[0]?.text()).toContain('P-X : erreur d’envoi du serveur de messagerie');
    expect(cells[0]?.text()).toContain('Paul Externe : pas de consentement aux communications');
    expect(cells[0]?.text()).toContain('Zoé Modèle : modèle de message absent');
    expect(cells[0]?.text()).toContain('Wil Inconnu : envoi bloqué (bizarre)');
    expect(cells[1]?.text()).toBe('—');
  });

  it('traduit les motifs en anglais', async () => {
    setLocale('en');
    asDirector();
    api();
    const { wrapper } = await mountView(ReportsView);
    await wrapper.get('[data-action="runs"]').trigger('click');
    await flushPromises();
    const text = wrapper.findAll('[data-test="run-failure-reasons"]')[0]?.text() ?? '';
    expect(text).toContain('Moussa Fictif: recipient unsubscribed');
    expect(text).toContain('Hawa Fictive: no email address on the record');
    expect(text).toContain('mail server sending error');
    expect(text).toContain('message template missing');
    setLocale('fr');
  });

  it('avertit dès l\'enregistrement d\'un destinataire sans adresse e-mail, sans refuser', async () => {
    asDirector();
    const calls = api();
    const { wrapper } = await mountView(ReportsView);
    await wrapper.get('form[data-test="schedule-form"] input[type="text"]').setValue('Quotidien');
    await wrapper.get('form[data-test="schedule-form"]').trigger('submit');
    await flushPromises();
    expect(calls.some((c) => c.method === 'POST' && c.path === '/report-schedules')).toBe(true);
    expect(wrapper.get('[data-test="recipient-warnings"]').text()).toContain('pas d’adresse e-mail sur la fiche : le rapport ne lui parviendra pas');
  });
});

// S-NOT05 : publication des bulletins du secondaire aux familles (SMS « bulletin disponible », message essentiel par défaut) :
// bouton sur l'écran du conseil, résumé traduit (familles prévenues, déjà prévenues, motifs des non-envois). Données fictives.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import { setLocale, translate } from '@/i18n';
import ClassCouncilView from '@/views/secondary/councils/ClassCouncilView.vue';
import { KNOWN_REASONS, publicationSummary } from '@/views/secondary/councils/publication';
import { secondaryCouncilsFr } from '@/i18n/secondary-councils-fr';
import { secondaryCouncilsEn } from '@/i18n/secondary-councils-en';
import { freshEnvironment, mockApi, mountView, signIn, teacherMe, tile } from './helpers';

const t = (key: Parameters<typeof translate>[0], params?: Record<string, string | number>) => translate(key, params);

describe('S-NOT05 résumé de publication des bulletins du secondaire', () => {
  afterEach(() => setLocale('fr'));

  it('compte les familles prévenues et traduit chaque motif, sans clé technique', () => {
    setLocale('fr');
    const text = publicationSummary(t, { published: 2, notified: 1, families: 3, already_notified: 0, not_notified: { opted_out: 1, consent_revoked: 1 } });
    expect(text).toContain('2 bulletin(s) publié(s)');
    expect(text).toContain('1 famille(s) prévenue(s) par SMS cette fois, sur 3');
    expect(text).toContain('1 désinscrite(s) des SMS');
    expect(text).toContain('1 ayant retiré leur consentement aux communications');
    expect(text).not.toMatch(/opted_out|consent_revoked/);
  });

  it('dit qu’une famille déjà prévenue ne reçoit pas de nouveau SMS, en anglais aussi', () => {
    setLocale('en');
    const text = publicationSummary(t, { published: 2, notified: 0, families: 3, already_notified: 3, not_notified: [] });
    expect(text).toContain('0 family(ies) notified by SMS this time, out of 3');
    expect(text).toContain('3 family(ies) already notified');
    expect(text).not.toContain('Not notified');
  });

  it('chaque motif connu a son libellé en fr et en en', () => {
    for (const reason of [...KNOWN_REASONS, 'other']) {
      const key = `secc.reason.${reason}` as keyof typeof secondaryCouncilsFr;
      expect(secondaryCouncilsFr[key]).toBeTruthy();
      expect(secondaryCouncilsEn[key as keyof typeof secondaryCouncilsEn]).toBeTruthy();
    }
  });
});

describe('S-NOT05 écran du conseil : publier aux familles', () => {
  beforeEach(freshEnvironment);
  afterEach(() => vi.restoreAllMocks());
  const page = (data: unknown[]): { data: unknown[]; meta: { next_cursor: null; per_page: number } } => ({ data, meta: { next_cursor: null, per_page: 50 } });

  it('publie les bulletins émis et affiche le résumé', async () => {
    signIn({ ...teacherMe, context: { establishment_id: 'E1', role: 'sec_chef_etablissement' } }, [tile({ roles: [{ code: 'sec_chef_etablissement', label: 'Chef' }], permissions: ['secondary.council.preside', 'secondary.reportcard.validate'] })], 'sec_chef_etablissement');
    const council = { id: 'cc1', structure_id: 's2a', period_id: 'T1', period_label: 'Trimestre 1', status: 'validated', summary: null, minutes_status: null, lines: [{ id: 'l1', person_id: 'p1', name: 'Fictif Issa', average: 12.5, rank_in_class: 1, decision: 'Admis', reason: null, line_extras: null }] };
    const calls = mockApi({
      'GET /academic-years': page([{ id: 'y1', label: '2026-2027', starts_on: '2026-09-01', ends_on: '2027-07-31', status: 'open', closed_at: null }]),
      'GET /secondary/councils/report-card-settings': { data: {} },
      'GET /secondary/setup/classes': page([{ id: 'c2a', structure_id: 's2a', academic_year_id: 'y1', label: '2nde A', section_code: 'fr', teaching_kind: 'general', cycle_code: 'second', level_id: 'l', level_code: '2NDE', stream_id: null, exam_code: null, capacity: null, head_teacher_person_id: null }]),
      'GET /periods': page([{ id: 'T1', academic_year_id: 'y1', kind: 'trimester', ordinal: 1, parent_id: null, starts_on: '2026-09-01', ends_on: '2026-12-20' }]),
      'GET /class-councils': page([council]),
      'GET /class-councils/cc1': { data: council },
      'GET /class-councils/cc1/report-cards': { data: [{ id: 'r1', person_id: 'p1', status: 'issued', document_id: 'd1', error_message: null }] },
      'POST /secondary/councils/cc1/report-cards/publish': { data: { published: 1, notified: 1, families: 2, already_notified: 0, not_notified: { opted_out: 1 } } },
    });
    const { wrapper } = await mountView(ClassCouncilView);
    await flushPromises();
    const button = wrapper.get('[data-test="publish-cards"]');
    expect(button.text()).toBe(secondaryCouncilsFr['secc.publishFamilies']);
    await button.trigger('click');
    await flushPromises();
    expect(calls.some((c) => c.method === 'POST' && c.path === '/secondary/councils/cc1/report-cards/publish')).toBe(true);
    const summary = wrapper.get('[data-test="publish-summary"]').text();
    expect(summary).toContain('1 famille(s) prévenue(s) par SMS cette fois, sur 2');
    expect(summary).toContain('1 désinscrite(s) des SMS');
  });
});

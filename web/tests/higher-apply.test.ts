import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it } from 'vitest';
import ApplyPublicView from '@/views/higher/apply/ApplyPublicView.vue';
import MyApplicationView from '@/views/higher/apply/MyApplicationView.vue';
import ApplyLinkView from '@/views/higher/apply/ApplyLinkView.vue';
import { higherApplyRoutes } from '@/views/higher/apply/routes';
import { higherRoutes } from '@/views/higher/routes';
import { higherApplyFr } from '@/i18n/higher-apply-fr';
import { higherApplyEn } from '@/i18n/higher-apply-en';
import { fileProblem, MAX_FILE_BYTES, type FollowUp } from '@/api/higher-apply';
import { flushPromises, freshEnvironment, mountView } from './helpers';
import { find, problem, setup } from './higher-teaching-helpers';

beforeEach(() => freshEnvironment());

const campaign = {
  id: 'C1', title: 'Rentrée 2026', school: 'EC - École de commerce', program: 'CF - Comptabilité', level: 'L1 - Licence 1', opens_on: '2026-03-01', closes_on: '2026-10-31',
  seats: 40, application_fee_xaf: 10000, selection_mode: 'file',
  required_documents: [{ code: 'diploma', label: 'Diplôme', blocking: true }, { code: 'photo', label: 'Photo d’identité', blocking: false }],
};

const followUp = (over: Partial<FollowUp> = {}): FollowUp => ({
  id: 'A1', reference: 'CAND-2026-X1', channel: 'online', status: 'submitted',
  campaign: { id: 'C1', title: 'Rentrée 2026', closes_on: '2026-10-31', selection_mode: 'file' },
  application_kind: 'new', origin_institution: null, diploma: 'bac', diploma_series: 'D', diploma_year: 2026, diploma_mention: null,
  submitted_at: '2026-10-13T08:00:00+01:00', completed_at: null,
  fee: { status: 'unpaid', amount_xaf: 10000, receipt_number: null },
  documents: [
    { id: 'D1', requirement_code: 'diploma', label: 'Diplôme', blocking: true, status: 'rejected', rejection_reason: 'Scan illisible.', has_file: true, mime: 'application/pdf', size: 1200 },
    { id: 'D2', requirement_code: 'photo', label: 'Photo d’identité', blocking: false, status: 'missing', rejection_reason: null, has_file: false, mime: null, size: null },
  ],
  missing_documents: 2, result: null, convocation: null, created_at: '2026-10-12T08:00:00+01:00', ...over,
});

describe('candidature en ligne : textes, routes et contrôles', () => {
  it('a les mêmes clés en français et en anglais', () => {
    expect(Object.keys(higherApplyEn).sort()).toEqual(Object.keys(higherApplyFr).sort());
  });

  it('déclare une page publique sans garde et des écrans connectés gardés, reliés au module higher', () => {
    const publicRoute = higherApplyRoutes.find((r) => r.path === '/candidater/:slug');
    expect(publicRoute?.meta?.public).toBe(true);
    expect(publicRoute?.beforeEnter).toBeUndefined();
    for (const r of higherApplyRoutes.filter((x) => x.path.startsWith('/superieur/'))) {
      expect(r.beforeEnter).toBeTypeOf('function');
      expect(r.meta?.establishmentTypes).toEqual(['higher']);
    }
    expect(higherRoutes.map((r) => r.path)).toEqual(expect.arrayContaining(['/candidater/:slug', '/superieur/ma-candidature', '/superieur/lien-candidature']));
  });

  it('refuse un fichier trop lourd ou d’un autre type avant envoi', () => {
    expect(fileProblem({ type: 'application/pdf', size: 1000 })).toBeNull();
    expect(fileProblem({ type: 'image/jpeg', size: MAX_FILE_BYTES })).toBeNull();
    expect(fileProblem({ type: 'application/pdf', size: MAX_FILE_BYTES + 1 })).toBe('size');
    expect(fileProblem({ type: 'application/x-msdownload', size: 10 })).toBe('type');
  });
});

describe('page publique « Candidater »', () => {
  it('liste les formations ouvertes avec dates, places, frais et pièces, sans appeler l’API avec un établissement', async () => {
    const calls = setup([], { 'GET /public/admissions/ipes-abcdefghij': { data: { institute: { name: 'IPES Test' }, campaigns: [campaign] } } });
    const { wrapper } = await mountView(ApplyPublicView, { slug: 'ipes-abcdefghij' }, '/candidater/ipes-abcdefghij');

    expect(wrapper.text()).toContain('Candidater à IPES Test');
    expect(wrapper.get('[data-test="campaign-C1"]').text()).toContain('40 places');
    expect(wrapper.text()).toContain('Diplôme');
    expect(find(calls, 'GET', '/public/admissions/ipes-abcdefghij')?.headers['X-Establishment-Id']).toBeUndefined();
  });

  it('affiche le même message pour tout lien invalide', async () => {
    setup([], { 'GET /public/admissions/zzzzzzzzzz': problem(404, 'Lien de candidature introuvable.') });
    const { wrapper } = await mountView(ApplyPublicView, { slug: 'zzzzzzzzzz' }, '/candidater/zzzzzzzzzz');

    expect(wrapper.get('[data-test="not-found"]').text()).toContain('pas valide');
    expect(wrapper.text()).not.toContain('introuvable');
  });

  it('inscrit le candidat : identité, code, mot de passe, puis lien de connexion vers son espace', async () => {
    const calls = setup([], {
      'GET /public/admissions/ipes-abcdefghij': { data: { institute: { name: 'IPES Test' }, campaigns: [campaign] } },
      'POST /public/admissions/ipes-abcdefghij/register': { data: { channel: 'sms', masked_to: '+237 6•• •• 00 01', expires_in: 300, resend_in: 60 } },
      'POST /auth/activation/verify': { ticket: 'TICKET' },
      'POST /auth/activation/password': { status: 'active' },
    });
    const { wrapper } = await mountView(ApplyPublicView, { slug: 'ipes-abcdefghij' }, '/candidater/ipes-abcdefghij');
    await wrapper.get('[data-test="campaign-C1"] button').trigger('click');

    await wrapper.get('#ap-fn').setValue('Awa');
    await wrapper.get('#ap-ln').setValue('Candidate');
    await wrapper.get('#ap-id').setValue('690000001');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    const register = find(calls, 'POST', '/public/admissions/ipes-abcdefghij/register');
    expect(register?.body).toMatchObject({ first_name: 'Awa', last_name: 'Candidate', identifier: '690000001', locale: 'fr' });
    expect(register?.body).not.toHaveProperty('website');
    expect(wrapper.text()).toContain('+237 6•• •• 00 01');

    const digits = wrapper.findAll('input').filter((i) => i.attributes('inputmode') === 'numeric');
    expect(digits.length).toBeGreaterThan(0);
  });

  it('exige les trois champs avant d’envoyer', async () => {
    const calls = setup([], { 'GET /public/admissions/ipes-abcdefghij': { data: { institute: { name: 'IPES Test' }, campaigns: [campaign] } } });
    const { wrapper } = await mountView(ApplyPublicView, { slug: 'ipes-abcdefghij' }, '/candidater/ipes-abcdefghij');
    await wrapper.get('[data-test="campaign-C1"] button').trigger('click');
    await wrapper.get('form').trigger('submit');
    await flushPromises();

    expect(wrapper.findAll('.error').length).toBe(3);
    expect(calls.some((c) => c.method === 'POST')).toBe(false);
  });
});

describe('espace du candidat', () => {
  const routes = (apps: FollowUp[], open: unknown[] = []) => ({
    'GET /higher/admissions/my-applications': { data: apps, meta: { next_cursor: null, per_page: 200 } },
    'GET /higher/admissions/open-campaigns': { data: open },
  });

  it('montre le statut, la pièce refusée avec son motif et le nombre de pièces à envoyer', async () => {
    setup(['higher.application.submit'], routes([followUp()]));
    const { wrapper } = await mountView(MyApplicationView);

    expect(wrapper.get('[data-test="status"]').text()).toContain('Déposée');
    expect(wrapper.get('[data-test="doc-diploma"]').text()).toContain('Scan illisible.');
    expect(wrapper.get('[data-test="missing"]').text()).toContain('2 pièce');
  });

  it('refuse un mauvais fichier sans appel réseau, envoie un PDF valide en multipart', async () => {
    const calls = setup(['higher.application.submit'], { ...routes([followUp()]), 'POST /higher/admissions/applications/A1/documents/D2/file': { data: {} } });
    const { wrapper } = await mountView(MyApplicationView);
    const input = wrapper.get('#file-D2');
    const set = (file: File): void => {
      Object.defineProperty(input.element, 'files', { value: [file], configurable: true });
    };

    set(new File(['MZ'], 'virus.exe', { type: 'application/x-msdownload' }));
    await input.trigger('change');
    await flushPromises();
    expect(wrapper.text()).toContain('Format non accepté');
    expect(find(calls, 'POST', '/higher/admissions/applications/A1/documents/D2/file')).toBeUndefined();

    set(new File(['%PDF-1.4'], 'photo.pdf', { type: 'application/pdf' }));
    await input.trigger('change');
    await flushPromises();
    expect(find(calls, 'POST', '/higher/admissions/applications/A1/documents/D2/file')).toBeDefined();
  });

  it('propose le paiement mobile money avec contrôle du numéro, puis envoie une clé d’idempotence', async () => {
    const calls = setup(['higher.application.submit'], { ...routes([followUp()]), 'POST /higher/admissions/applications/A1/fee-payment': { data: {} } });
    const { wrapper } = await mountView(MyApplicationView);

    await wrapper.get('[data-test="pay-form"]').trigger('submit');
    await flushPromises();
    expect(wrapper.get('[data-test="pay-form"]').text()).toContain('Numéro invalide');
    expect(find(calls, 'POST', '/higher/admissions/applications/A1/fee-payment')).toBeUndefined();

    await wrapper.get('#pay-phone').setValue('677112233');
    await wrapper.get('[data-test="pay-form"]').trigger('submit');
    await flushPromises();
    const sent = find(calls, 'POST', '/higher/admissions/applications/A1/fee-payment');
    expect(sent?.body).toMatchObject({ channel: 'orange_money', payer_phone: '677112233' });
    expect(sent?.headers['Idempotency-Key']).toBeTruthy();
  });

  it('affiche résultat et convocation', async () => {
    const decided = followUp({
      status: 'admitted', fee: { status: 'paid', amount_xaf: 10000, receipt_number: 'R-1' },
      result: { decision: 'admitted', reason: 'Dossier conforme.', conditional_until: null, waitlist_rank: null, decided_at: '2026-10-20T08:00:00+01:00' },
      convocation: { contest_date: '2026-11-05', center_name: 'Campus A', room_name: 'Salle 3', seat_number: 'T-014', document_id: null },
    });
    setup(['higher.application.submit'], routes([decided]));
    const { wrapper } = await mountView(MyApplicationView);

    expect(wrapper.get('[data-test="result"]').text()).toContain('Dossier conforme.');
    expect(wrapper.get('[data-test="convocation"]').text()).toContain('T-014');
    expect(wrapper.find('[data-test="fee"]').exists()).toBe(true);
  });

  it('commence une candidature depuis une formation ouverte, avec les champs du diplôme', async () => {
    const calls = setup(['higher.application.submit'], { ...routes([], [campaign]), 'POST /higher/admissions/applications': { data: { id: 'A9', reference: 'CAND-1' } } });
    const { wrapper } = await mountView(MyApplicationView);
    await wrapper.get('#dr-campaign').setValue('C1');
    await wrapper.get('#dr-series').setValue('C');
    await wrapper.get('#dr-year').setValue('2026');
    await wrapper.get('[data-test="draft-form"]').trigger('submit');
    await flushPromises();

    expect(find(calls, 'POST', '/higher/admissions/applications')?.body).toMatchObject({ campaign_id: 'C1', application_kind: 'new', diploma: 'bac', diploma_series: 'C', diploma_year: 2026 });
  });
});

describe('lien partageable (scolarité)', () => {
  it('crée le lien puis affiche son adresse', async () => {
    let created = false;
    const calls = setup(['higher.application.manage'], {
      'GET /higher/admissions/public-link': () => ({ data: created ? { exists: true, enabled: true, slug: 'ipes-abcdefghij', path: '/candidater/ipes-abcdefghij' } : { exists: false, enabled: false, slug: null, path: null } }),
      'PUT /higher/admissions/public-link': () => {
        created = true;
        return { data: { exists: true, enabled: true, slug: 'ipes-abcdefghij', path: '/candidater/ipes-abcdefghij' } };
      },
    });
    const { wrapper } = await mountView(ApplyLinkView);
    await wrapper.get('[data-test="create"]').trigger('click');
    await flushPromises();

    expect(find(calls, 'PUT', '/higher/admissions/public-link')?.body).toEqual({ enabled: true });
    expect((wrapper.get('[data-test="url"]').element as HTMLInputElement).value).toContain('/candidater/ipes-abcdefghij');
    expect(wrapper.get('[data-test="state"]').text()).toBe('Lien actif');
  });
});

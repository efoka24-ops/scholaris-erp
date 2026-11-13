import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { http } from '@/api/http';
import { permissionsForRoles } from '@/lib/permissions';
import ApeeView from '@/views/public-schools/ApeeView.vue';
import EvaluationView from '@/views/public-schools/EvaluationView.vue';
import ReferentialView from '@/views/public-schools/ReferentialView.vue';
import VaultGate from '@/views/public-schools/VaultGate.vue';
import { lockVault, wipeVault } from '@/views/public-schools/vault';
import { flushPromises, freshEnvironment, mockApi, mountView, signIn, teacherMe, tile, type RecordedCall } from './helpers';

// FIX-PUB : défauts du primaire public relevés pendant les captures du guide (G-PRI 9 à 13). Référentiel réglé par le directeur
// avec les trimestres choisis par leur nom, droits de la direction des examens, liste APEE cohérente avec l'état financier, code
// personnel propre à l'appareil expliqué sur ordinateur. Données fictives.

const page = (items: unknown[]) => ({ data: items, meta: { next_cursor: null, per_page: 50 } });

function connect(roles: string[], map: Record<string, unknown>): RecordedCall[] {
  signIn(teacherMe, [tile({ type: 'primary', permissions: [...permissionsForRoles(roles)], roles: roles.map((code) => ({ code, label: code })) })], roles[0]);
  return mockApi(map as Parameters<typeof mockApi>[0]);
}

const setting = (value: boolean) => ({ key: 'public_primary.referential.school_may_manage', value, default: true, status: 'to_confirm', source: 'RG-TER-6' });
const year = { id: 'Y1', label: '2026-2027', starts_on: '2000-09-01', ends_on: '2999-06-30', status: 'open', closed_at: null };
const periods = [
  { id: 'PER-T2', academic_year_id: 'Y1', kind: 'trimester', ordinal: 2, starts_on: '2027-01-04', ends_on: '2027-03-26' },
  { id: 'PER-T1', academic_year_id: 'Y1', kind: 'trimester', ordinal: 1, starts_on: '2026-09-01', ends_on: '2026-12-18' },
  { id: 'PER-T3', academic_year_id: 'Y1', kind: 'trimester', ordinal: 3, starts_on: '2027-04-06', ends_on: '2027-06-30' },
];

beforeEach(async () => {
  freshEnvironment();
  await wipeVault();
  lockVault();
});

describe('référentiel du primaire public (directeur)', () => {
  it('le directeur génère les unités en choisissant les trimestres par leur nom, sans identifiant technique', async () => {
    const calls = connect(['pub_school_director'], {
      'GET /public-primary/settings': { data: [setting(true)] },
      'GET /academic-years': page([year]),
      'GET /periods': page(periods),
      'POST /public-primary/units/generate': { data: [] },
      'POST /public-primary/frames/import-example': { data: [] },
      'GET /public-primary/frames': page([]),
      'GET /public-primary/units': { data: [] },
    });
    const { wrapper } = await mountView(ReferentialView);
    await flushPromises();

    expect(wrapper.text()).toContain("Importer le référentiel d'exemple");
    expect(wrapper.text()).not.toContain('Identifiant du trimestre');
    expect(wrapper.text()).toContain('Trimestre 1');
    expect(wrapper.text()).toContain('Trimestre 3');
    expect((wrapper.get('#ts-0').element as HTMLInputElement).value).toBe('2026-09-01');
    expect((wrapper.get('#rf-year').element as HTMLInputElement).value).toBe('2026-2027');

    // Le référentiel importé en cours d'année vaut dès la rentrée : les unités déjà passées gardent leurs disciplines.
    const importButton = wrapper.findAll('button').find((b) => b.text().includes("Importer le référentiel d'exemple"));
    await importButton?.trigger('click');
    await flushPromises();
    expect(calls.find((c) => c.path === '/public-primary/frames/import-example')?.body?.valid_from).toBe('2000-09-01');

    await wrapper.get('#tp-2').setValue(false);
    await wrapper.findAll('form').at(-1)?.trigger('submit');
    await flushPromises();
    const post = calls.find((c) => c.method === 'POST' && c.path === '/public-primary/units/generate');
    expect(post?.body?.year_label).toBe('2026-2027');
    expect(post?.body?.trimesters).toEqual([
      { period_id: 'PER-T1', starts_on: '2026-09-01', ends_on: '2026-12-18' },
      { period_id: 'PER-T2', starts_on: '2027-01-04', ends_on: '2027-03-26' },
    ]);
  });

  it('si le ministère réserve le référentiel au niveau national, le directeur le lit et reçoit une explication', async () => {
    const calls = connect(['pub_school_director'], { 'GET /public-primary/settings': { data: [setting(false)] } });
    const { wrapper } = await mountView(ReferentialView);
    await flushPromises();

    expect(wrapper.text()).not.toContain("Importer le référentiel d'exemple");
    expect(wrapper.text()).not.toContain('Générer les unités');
    expect(wrapper.text()).toContain('réglés au niveau national');
    expect(calls.some((c) => c.path === '/periods')).toBe(false);
  });

  it('l’enseignant ne règle pas le référentiel', () => {
    const teacher = new Set(permissionsForRoles(['pub_class_teacher']));
    expect(teacher.has('public_primary.referential.school_manage')).toBe(false);
    expect(teacher.has('public_primary.referential.manage')).toBe(false);
  });
});

describe('évaluation par critères sur un appareil neuf', () => {
  it('charge les classes une fois le code personnel créé (coffre fermé à l’ouverture de l’écran)', async () => {
    connect(['pub_class_teacher'], {
      'GET /academic-years': page([year]),
      'GET /public/classes': { data: [{ structure_id: 'C1', establishment_id: 'E1', label: 'CM2 A', level_codes: ['CM2'], twinned: false, shift: null, homeroom_staff_id: null }] },
    });
    const { wrapper } = await mountView(EvaluationView);
    expect(wrapper.text()).toContain('Créez votre code personnel');
    for (const box of [0, 1]) {
      const inputs = wrapper.findAll('.code-boxes')[box]!.findAll('input');
      for (let i = 0; i < 6; i++) await inputs[i]!.setValue('654321'[i]);
    }
    await flushPromises();
    await wrapper.get('form').trigger('submit');
    await vi.waitFor(async () => {
      await flushPromises(2);
      expect(wrapper.find('#pp-class').exists()).toBe(true);
      expect(wrapper.get('#pp-class').text()).toContain('CM2 A');
    }, { timeout: 15_000, interval: 100 });
  }, 20_000);
});

describe('direction des examens', () => {
  it('lit les années scolaires pour ouvrir une session (organisation.read), sans écran nominatif de l’école', () => {
    const perms = new Set(permissionsForRoles(['pub_exam_directorate']));
    expect(perms.has('organisation.read')).toBe(true);
    expect(perms.has('organisation.manage')).toBe(false);
    expect(perms.has('public_schools.pupils.read')).toBe(false);
  });
});

describe('APEE : liste des non-contributeurs', () => {
  it('le reçu n’est annoncé qu’avec la liste déjà relue : la famille qui vient de payer n’y figure plus', async () => {
    const apeeYear = { id: 'AY1', year_label: '2026-2027', amount_per_pupil: 1500, voted_on: '2026-10-10', minutes_ref: null, expected_pupils: 3 };
    let paid = false;
    const statement = () => ({
      data: {
        year_label: '2026-2027', amount_per_pupil: 1500, expected_pupils: 3, expected: 4500, collected: paid ? 1500 : 0, collection_rate: paid ? 33.3 : 0,
        contributing_families: paid ? 1 : 0, exempted_families: 0, non_contributing_families: paid ? 2 : 3, income: {}, income_total: 0, expenses: {},
        expenses_total: 0, pending_expenses: {}, pending_total: 0, balance: 0,
      },
    });
    connect(['pub_apee_board'], {
      'GET /public/apee/years': page([apeeYear]),
      'GET /public/apee/years/AY1/entries': () => page([]),
      'GET /public/apee/years/AY1/statement': statement,
      'GET /public/apee/years/AY1/non-contributors': () =>
        page([...(paid ? [] : [{ person_id: 'A1', name: 'Amina Fictive-Amina' }]), { person_id: 'B1', name: 'Bello Fictif-Bello' }]),
      'POST /public/apee/entries': () => {
        paid = true;
        return { data: { id: 'EN1', state: 'recorded', receipt_number: 'REC-FICT-1', replayed: false } };
      },
    });
    // La liste nominative arrive après l'état financier (réseau lent) : on la retient jusqu'au contrôle.
    const base = (http as unknown as { cfg: { fetchImpl: typeof fetch } }).cfg.fetchImpl;
    let release: () => void = () => undefined;
    let hold = false;
    http.configure({
      fetchImpl: (async (input: RequestInfo | URL, init?: RequestInit) => {
        if (hold && String(input).includes('/non-contributors')) await new Promise<void>((r) => (release = r));
        return base(input, init);
      }) as typeof fetch,
    });

    const { wrapper } = await mountView(ApeeView);
    await flushPromises();
    expect(wrapper.text()).toContain('Amina Fictive-Amina');

    hold = true;
    await wrapper.get('#ae-cat').setValue('donation');
    await wrapper.get('#ae-amt').setValue('1500');
    await wrapper.findAll('form')[1]?.trigger('submit');
    await flushPromises();
    const listed = wrapper.get('#ap-np').element.parentElement?.textContent ?? '';
    if (wrapper.text().includes('Reçu n° REC-FICT-1')) expect(listed).not.toContain('Amina');

    release();
    await flushPromises();
    expect(wrapper.text()).toContain('Reçu n° REC-FICT-1');
    expect(wrapper.get('#ap-np').element.parentElement?.textContent ?? '').not.toContain('Amina');
    expect(wrapper.text()).toContain('1 / 2 / 0');
  });
});

describe('code personnel sur ordinateur', () => {
  it('explique que le code protège les listes de cet appareil, téléphone ou ordinateur', async () => {
    connect(['pub_district_inspector'], {});
    const { wrapper } = await mountView(VaultGate);
    await flushPromises();

    expect(wrapper.text()).toContain('Créez votre code personnel');
    expect(wrapper.text()).toContain('téléphone ou ordinateur');
    expect(wrapper.text()).toContain('propre à cet appareil');
  });
});

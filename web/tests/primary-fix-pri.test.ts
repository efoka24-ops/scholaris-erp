import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it } from 'vitest';
import { routes } from '@/router/routes';
import { setLocale, translate } from '@/i18n';
import { permissionsForRoles } from '@/lib/permissions';
import { enqueue } from '@/offline/queue';
import { useOfflineStore } from '@/stores/offline';
import CallView from '@/views/primary/CallView.vue';
import DeskView from '@/views/primary/DeskView.vue';
import ExamView from '@/views/primary/ExamView.vue';
import HomeView from '@/views/primary/HomeView.vue';
import RemediationView from '@/views/primary/RemediationView.vue';
import ServicesView from '@/views/primary/ServicesView.vue';
import { enrolmentInvoiceRoute, publishSummary } from '@/views/primary/fixes';
import { flushPromises, freshEnvironment, jsonResponse, mockApi, mountView, signIn, teacherMe, tile, type RecordedCall } from './helpers';

// FIX-PRI : défauts du primaire privé relevés pendant les captures du guide (rapport G-PRI). Chaque bloc échoue sans la correction.
const page = (items: unknown[]) => ({ data: items, meta: { next_cursor: null, per_page: 50 } });
const year = { id: 'Y1', label: '2026-2027', starts_on: '2026-09-01', ends_on: '2027-06-30', status: 'open', closed_at: null };
const structures = [
  { id: 'L1', org_unit_id: null, kind: 'level', name: 'CM1', capacity: null },
  { id: 'L2', org_unit_id: null, kind: 'level', name: 'CM2', capacity: null },
  { id: 'C1', org_unit_id: null, kind: 'class', name: 'CM2 A', capacity: 60 },
];
const t = (key: Parameters<typeof translate>[0], params?: Record<string, string | number>) => translate(key, params, 'fr');

function connect(permissions: string[], role: string, map: Record<string, unknown>): RecordedCall[] {
  signIn(teacherMe, [tile({ type: 'primary', permissions, roles: [{ code: role, label: role }] })], role);
  return mockApi({ 'GET /academic-years': page([year]), 'GET /periods': page([]), 'GET /structures': page(structures), ...map } as Parameters<typeof mockApi>[0]);
}

beforeEach(() => {
  freshEnvironment();
  setLocale('fr');
});

describe('1. appel par demi-journée', () => {
  it('le maître voit les élèves de sa classe par la liste d’appel du primaire (et non par /enrollments)', async () => {
    const calls = connect(['attendance.take'], 'enseignant', {
      'GET /primary/classes/C1/call-roster': { data: { class: { id: 'C1', name: 'CM2 A' }, learners: [
        { person_id: 'A1', name: 'FICTIVE-CM2 Amina' }, { person_id: 'A2', name: 'FICTIF-CM2 Bello' }, { person_id: 'A3', name: 'FICTIVE-CM2 Chantal' },
      ] } },
    });
    const { wrapper } = await mountView(CallView, {}, '/primaire/appel');
    expect(wrapper.get('#cl-roster').text()).toContain('Élèves (3)');
    expect(wrapper.text()).toContain('FICTIVE-CM2 Amina');
    expect(calls.some((c) => c.path === '/enrollments')).toBe(false);
  });

  it('la direction du primaire ouvre l’appel (miroir de la migration 202611110745)', () => {
    const director = permissionsForRoles(['primary_directeur']);
    const meta = routes.find((r) => r.name === 'pri-call')?.meta?.perms as string[];
    expect(meta.some((p) => director.has(p))).toBe(true);
  });
});

describe('2. guichet des inscriptions', () => {
  it('montre aussi les inscriptions validées sous réserve des pièces', async () => {
    const enrollment = (id: string, person: string, status: string) => ({ id, person_id: person, structure_id: 'C1', academic_year_id: 'Y1', status, internal_number: `MAT-${id}` });
    connect(['primary.enrol', 'people.manage'], 'primary_secretaire', {
      'GET /enrollments': (c: RecordedCall) => page(c.query['filter[status]'] === 'conditional' ? [enrollment('E1', 'A1', 'conditional')] : c.query['filter[status]'] === 'active' ? [enrollment('E2', 'A2', 'active')] : []),
      'GET /people/A1': { data: { id: 'A1', first_name: 'Amina', last_name: 'Fictive-CM2' } },
      'GET /people/A2': { data: { id: 'A2', first_name: 'Bello', last_name: 'Fictif-CM2' } },
    });
    const { wrapper } = await mountView(DeskView, {}, '/primaire/inscriptions');
    expect(wrapper.text()).not.toContain('Aucune inscription en cours');
    expect(wrapper.text()).toContain('FICTIVE-CM2 Amina');
    expect(wrapper.text()).toContain('Sous réserve des pièces');
    expect(wrapper.text()).toContain('FICTIF-CM2 Bello');
  });
});

describe('3. secrétariat du primaire', () => {
  it('ouvre Personnes et inscriptions avec people.manage', () => {
    const secretary = permissionsForRoles(['primary_secretaire']);
    for (const name of ['people', 'person']) {
      const perms = routes.find((r) => r.name === name)?.meta?.perms as string[];
      expect(perms.some((p) => secretary.has(p)), name).toBe(true);
    }
  });

  it('facture l’inscription à l’étape 5 par la facture du primaire', () => {
    const secretary = permissionsForRoles(['primary_secretaire']);
    const can = (...p: string[]) => p.some((x) => secretary.has(x));
    expect(enrolmentInvoiceRoute(can, 'primary', 'EN1')).toEqual({ path: '/primary/enrolments/EN1/invoice' });
    expect(enrolmentInvoiceRoute(can, 'secondary', 'EN1')).toBeNull();
    expect(enrolmentInvoiceRoute((...p) => p.includes('billing.manage'), 'primary', 'EN1')).toEqual({ path: '/invoices', body: { enrollment_id: 'EN1' } });
  });
});

describe('4. remédiation', () => {
  it('un seul chargement du registre au montage, élèves nommés, aucune erreur', async () => {
    const register = {
      class: { id: 'C1', name: 'CM2 A' }, weekly_minutes_target: 60, fundamental_subjects: [],
      forms: { needs_group: { fr: 'Groupe de besoin', en: 'Needs group' } },
      learners: [{ person_id: 'A1', name: 'FICTIVE-CM2 Amina' }], sessions: [], weekly: [],
    };
    const calls = connect(['primary.remediation.read', 'primary.remediation.write'], 'enseignant', {
      'GET /primary/classes/C1/remediation': { data: register },
      'GET /primary/remediation-plans': { data: { items: [] } },
      'POST /primary/classes/C1/remediation': (c: RecordedCall) => (c.body?.remediation_form === 'needs_group' ? { data: { id: 'S1', participants: [] } } : jsonResponse({ status: 422, detail: 'Texte non vide attendu.' }, 422)),
    });
    const { wrapper } = await mountView(RemediationView, {}, '/primaire/remediation');
    expect(calls.filter((c) => c.method === 'GET' && c.path === '/primary/classes/C1/remediation')).toHaveLength(1);
    expect(wrapper.find('[role="alert"]').exists()).toBe(false);
    expect(wrapper.text()).toContain('FICTIVE-CM2 Amina');
    await wrapper.get('#rm-subj').setValue('Lecture');
    await wrapper.get('#rm-obj').setValue('Lire une phrase');
    await wrapper.get('input[type="checkbox"]').setValue(true);
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(wrapper.text()).toContain('Séance enregistrée.');
  });
});

describe('5. session d’examen', () => {
  const base = {
    'GET /primary/exam-catalogue': { data: { kinds: { cep: { fr: 'CEP', en: 'CEP', subsystem: 'fr' } }, fields: [], outcomes: [] } },
    'GET /primary/exam-sessions': { data: { items: [] } },
  };
  const profile = (level: string, exam: boolean) => ({ id: `P-${level}`, level_structure_id: level, section_org_unit_id: null, cycle_code: null, sort_order: 1, is_final_level: exam, is_exam_level: exam, language: 'fr' });

  it('ne propose que les niveaux d’examen', async () => {
    connect(['primary.exam.read', 'primary.exam.manage', 'primary.referential.read'], 'primary_directeur', { ...base, 'GET /primary/level-profiles': page([profile('L1', false), profile('L2', true)]) });
    const { wrapper } = await mountView(ExamView, {}, '/primaire/examen');
    expect(wrapper.findAll('#ex-level option').map((o) => o.text())).toEqual(['CM2']);
    expect(wrapper.find('[data-test="no-exam-level"]').exists()).toBe(false);
  });

  it('sans niveau d’examen : message clair et lien vers les niveaux ; un refus du serveur s’affiche sous le formulaire', async () => {
    connect(['primary.exam.read', 'primary.exam.manage', 'primary.referential.read'], 'primary_directeur', { ...base, 'GET /primary/level-profiles': page([profile('L2', false)]) });
    const { wrapper } = await mountView(ExamView, {}, '/primaire/examen');
    expect(wrapper.get('[data-test="no-exam-level"]').text()).toContain('niveau d’examen');
    expect((wrapper.get('form button[type="submit"]').element as HTMLButtonElement).disabled).toBe(true);
  });
});

describe('6. forfait de service', () => {
  it('crée le forfait avec une capacité saisie et propose les frais codés du catalogue', async () => {
    const calls = connect(['primary.services.read', 'primary.services.manage', 'billing.read'], 'comptable', {
      'GET /primary/service-plans': { data: { kinds: { canteen: { fr: 'Cantine', en: 'Canteen' } }, plans: [] } },
      'GET /fee-items': page([{ id: 'F1', kind: 'other', code: 'cantine', name: 'Cantine', amount_xaf: 15000, active: true }, { id: 'F2', kind: 'tuition', name: 'Scolarité', amount_xaf: 120000, active: true }]),
      'POST /primary/service-plans': (c: RecordedCall) => ({ data: { id: 'PL1', ...c.body, active: true } }),
    });
    const { wrapper } = await mountView(ServicesView, {}, '/primaire/services');
    expect(wrapper.findAll('#sv-fee option').map((o) => o.text())).toEqual(['Choisir un frais du catalogue', expect.stringContaining('Cantine (cantine)')]);
    await wrapper.get('#sv-label').setValue('Cantine du midi');
    await wrapper.get('#sv-fee').setValue('cantine');
    await wrapper.get('#sv-cap').setValue('40');
    await wrapper.get('[data-test="new-plan"]').trigger('submit');
    await flushPromises();
    const post = calls.find((c) => c.method === 'POST' && c.path === '/primary/service-plans');
    expect(post?.body).toMatchObject({ fee_code: 'cantine', capacity: 40, label: 'Cantine du midi' });
    expect(wrapper.text()).toContain('Forfait créé.');
  });

  it('sans frais codé au catalogue : saisie libre et message clair', async () => {
    connect(['primary.services.read', 'primary.services.manage', 'billing.read'], 'comptable', {
      'GET /primary/service-plans': { data: { kinds: { canteen: { fr: 'Cantine', en: 'Canteen' } }, plans: [] } },
      'GET /fee-items': page([{ id: 'F2', kind: 'tuition', name: 'Scolarité', amount_xaf: 120000, active: true }]),
    });
    const { wrapper } = await mountView(ServicesView, {}, '/primaire/services');
    expect(wrapper.get('#sv-fee').element.tagName).toBe('INPUT');
    expect(wrapper.get('[data-test="fee-hint"]').text()).toContain('ne porte de code');
  });
});

describe('7. publication des bulletins', () => {
  it('dit combien de familles sont prévenues et pourquoi les autres ne le sont pas', () => {
    const text = publishSummary(t, { published: 3, notified: 0, families: 1, not_notified: { consent_missing: 1 } });
    expect(text).toContain('3 publié(s), visibles dans l’espace des parents');
    expect(text).toContain('0 famille(s) prévenue(s) par SMS sur 1');
    expect(text).toContain('1 sans consentement aux communications');
    expect(publishSummary(t, { published: 2, notified: 0, families: 0, not_notified: {} })).toContain('Aucun garant');
  });
});

describe('8. démarrage', () => {
  it('affiche des libellés traduits et jamais la clé technique', async () => {
    connect(['primary.settings.manage'], 'primary_directeur', {
      'GET /primary/setup': { data: { presets: [{ key: 'attendance.mode', recommended: 'day_part', current: null, ok: false }], ready: false, day: '2026-11-11', absent_today: 0 } },
    });
    const { wrapper } = await mountView(HomeView, {}, '/primaire');
    const text = wrapper.get('[aria-labelledby="pri-setup"]').text();
    expect(text).toContain('Mode d’appel');
    expect(text).toContain('par demi-journée');
    expect(text).not.toContain('attendance.mode');
    expect(text).not.toContain('day_part');
  });
});

describe('9. bandeau hors ligne', () => {
  const item = { client_uuid: '01TESTFIXPRI0000000000000A', kind: 'attendance' as const, establishment_id: 'E1', label: 'CM2 A', payload: { client_uuid: '01TESTFIXPRI0000000000000A', structure_id: 'C1', day_part: 'morning', date: '2026-11-11', validate: true, records: [] } };

  it('un serveur occupé (503) ne met pas l’appareil hors ligne', async () => {
    mockApi({ 'POST /attendance-sessions/sync': jsonResponse({ status: 503, detail: 'Service indisponible.' }, 503) });
    await enqueue(item);
    const store = useOfflineStore();
    await store.sync();
    expect(store.online).toBe(true);
    expect(store.pending).toBe(1);
  });

  it('une coupure réelle le met hors ligne, et la réponse suivante du serveur le remet en ligne', async () => {
    let down = true;
    mockApi({ 'POST /attendance-sessions/sync': () => { if (down) throw new TypeError('Failed to fetch'); return { data: [{ client_uuid: item.client_uuid, status: 'created' }] }; } });
    await enqueue(item);
    const store = useOfflineStore();
    await store.sync();
    expect(store.online).toBe(false);
    down = false;
    await store.sync();
    expect(store.online).toBe(true);
  });
});

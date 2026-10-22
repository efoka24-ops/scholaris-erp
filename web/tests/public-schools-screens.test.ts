import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it } from 'vitest';
import { routes } from '@/router/routes';
import { todayIso } from '@/lib/format';
import { permissionsForRoles } from '@/lib/permissions';
import { useContextStore } from '@/stores/context';
import CallView from '@/views/public-schools/CallView.vue';
import ClassesView from '@/views/public-schools/ClassesView.vue';
import EnrolPupilView from '@/views/public-schools/EnrolPupilView.vue';
import HomeView from '@/views/public-schools/HomeView.vue';
import MessagesView from '@/views/public-schools/MessagesView.vue';
import TransfersView from '@/views/public-schools/TransfersView.vue';
import { lockVault, setupPin, wipeVault } from '@/views/public-schools/vault';
import { listQueue } from '@/views/public-schools/offlineCalls';
import { flushPromises, freshEnvironment, jsonResponse, mockApi, mountView, signIn, teacherMe, tile, type RecordedCall } from './helpers';

const find = (calls: RecordedCall[], method: string, path: string): RecordedCall | undefined => calls.find((c) => c.method === method && c.path === path);
const page = (items: unknown[]) => ({ data: items, meta: { next_cursor: null, per_page: 50 } });

const TEACHER = permissionsForRoles(['pub_class_teacher']);
const DIRECTOR = permissionsForRoles(['pub_school_director']);

function connect(role: 'pub_class_teacher' | 'pub_school_director' | 'pub_cabinet', routesMap: Record<string, unknown>): RecordedCall[] {
  signIn(teacherMe, [tile({ type: 'primary', permissions: [...permissionsForRoles([role])], roles: [{ code: role, label: role }] })], role);
  return mockApi(routesMap as Parameters<typeof mockApi>[0]);
}

const today = new Date();
const year = { id: 'Y1', label: '2026-2027', starts_on: `${today.getFullYear() - 1}-01-01`, ends_on: `${today.getFullYear() + 1}-12-31`, status: 'open', closed_at: null };
const classRow = { structure_id: 'C1', establishment_id: 'E1', label: 'CM2 A', level_codes: ['CM2'], twinned: false, shift: 'full_day', homeroom_staff_id: null, headcount: 61, capacity_alert: true, alert_threshold: 60 };
const roster = Array.from({ length: 60 }, (_, i) => ({ person_id: `P${String(i).padStart(2, '0')}`, last_name: `Nom${String(i).padStart(2, '0')}`, first_name: `Élève${i}` }));

beforeEach(async () => {
  freshEnvironment();
  await wipeVault();
  lockVault();
});

describe('permissions des routes des écoles publiques', () => {
  const psRoutes = routes.filter((r) => String(r.name ?? '').startsWith('ps-'));

  it('déclare l’espace et ses douze écrans', () => {
    expect(psRoutes.map((r) => r.name)).toEqual(
      expect.arrayContaining(['ps-home', 'ps-call', 'ps-classes', 'ps-pupils', 'ps-enrol', 'ps-pupil', 'ps-transfers', 'ps-dropouts', 'ps-school', 'ps-presence', 'ps-staff', 'ps-messages', 'ps-device']),
    );
  });

  it('aucun écran nominatif ne s’ouvre à un rôle « agrégats seuls »', () => {
    for (const role of ['pub_cabinet', 'pub_planning', 'pub_technical_partner', 'pub_exam_directorate']) {
      const perms = permissionsForRoles([role]);
      for (const r of psRoutes) {
        const needed = (r.meta?.perms ?? []) as string[];
        expect(needed.length, String(r.name)).toBeGreaterThan(0);
        expect(needed.some((p) => perms.has(p)), `${role} -> ${String(r.name)}`).toBe(false);
      }
    }
  });

  it('l’enseignant titulaire fait l’appel mais ne gère ni transferts ni abandons ni fiche de l’école', () => {
    const open = (name: string): boolean => {
      const r = psRoutes.find((x) => x.name === name);
      return ((r?.meta?.perms ?? []) as string[]).some((p) => TEACHER.has(p));
    };
    expect(open('ps-call')).toBe(true);
    expect(open('ps-classes')).toBe(true);
    expect(open('ps-messages')).toBe(true);
    expect(open('ps-transfers')).toBe(false);
    expect(open('ps-dropouts')).toBe(false);
    expect(open('ps-enrol')).toBe(false);
  });

  it('le directeur ouvre tous les écrans, sauf le contrôle de l’inspection qui n’est pas le sien', () => {
    for (const r of psRoutes.filter((x) => x.name !== 'ps-control')) expect(((r.meta?.perms ?? []) as string[]).some((p) => DIRECTOR.has(p)), String(r.name)).toBe(true);
  });
});

describe('accueil', () => {
  it('montre à l’enseignant l’appel mais pas les transferts', async () => {
    connect('pub_class_teacher', {});
    const { wrapper } = await mountView(HomeView);
    const text = wrapper.text();
    expect(text).toContain('Appel');
    expect(text).toContain('Messages aux parents');
    expect(text).not.toContain('Transferts');
    expect(text).not.toContain('Abandons');
  });

  it('montre tout au directeur', async () => {
    connect('pub_school_director', {});
    const { wrapper } = await mountView(HomeView);
    for (const label of ['Appel', 'Classes', 'Élèves', 'Transferts', 'Abandons', 'Présence des enseignants', 'Personnel', 'Messages aux parents']) {
      expect(wrapper.text()).toContain(label);
    }
  });
});

describe('appel mobile', () => {
  const base = {
    'GET /academic-years': page([year]),
    'GET /public/classes': { data: [classRow] },
    'GET /public/classes/C1/pupils': { data: roster },
    'GET /public/classes/C1/attendance': {},
  };

  async function openCall(extra: Record<string, unknown> = {}) {
    const calls = connect('pub_class_teacher', { ...base, ...extra });
    await setupPin('123456', 1000);
    const mounted = await mountView(CallView);
    await flushPromises();
    return { calls, ...mounted };
  }

  it('demande le code avant tout : rien de nominatif n’est chargé coffre fermé', async () => {
    const calls = connect('pub_class_teacher', base);
    const { wrapper } = await mountView(CallView);
    expect(wrapper.text()).toContain('Créez votre code personnel');
    expect(wrapper.find('.roster').exists()).toBe(false);
    expect(calls.some((c) => c.path.includes('/pupils'))).toBe(false);
  });

  it('charge la classe unique avec l’établissement en en-tête et montre les 60 élèves présents par défaut', async () => {
    const { calls, wrapper } = await openCall();
    const classesCall = find(calls, 'GET', '/public/classes');
    expect(classesCall?.headers['X-Establishment-Id']).toBe('E1');
    expect(classesCall?.query.academic_year_id).toBe('Y1');
    expect(wrapper.findAll('.roster__row')).toHaveLength(60);
    expect(wrapper.get('[data-test="call-counts"]').text()).toBe('60 présents · 0 absents · 0 en retard');
    expect(wrapper.text()).toContain("Valider l'appel (60 élèves)");
  });

  it('un appui par absent, hors ligne : l’appel est mis en file, rien ne part, rien n’est perdu', async () => {
    const { wrapper } = await openCall({
      ['PUT /public/classes/C1/attendance/' + todayIso()]: () => {
        throw new TypeError('Failed to fetch');
      },
    });
    const buttons = wrapper.findAll('.roster__btn');
    await buttons[3]!.trigger('click'); // absent
    await buttons[10]!.trigger('click'); // absent
    await buttons[10]!.trigger('click'); // puis en retard
    await flushPromises();
    expect(wrapper.get('[data-test="call-counts"]').text()).toBe('59 présents · 1 absents · 1 en retard');
    expect(buttons[3]!.attributes('aria-label')).toContain('Absent');
    expect(wrapper.findAll('input[type="time"]')).toHaveLength(1);

    await wrapper.findAll('button').find((b) => b.text().startsWith("Valider l'appel"))!.trigger('click');
    await flushPromises();
    expect(wrapper.text()).toContain("L'appel est enregistré sur le téléphone");
    const queue = await listQueue();
    expect(queue).toHaveLength(1);
    expect(queue[0]).toMatchObject({ absent: 1, late: 1, status: 'pending', class_id: 'C1' });
    expect(wrapper.get('[data-test="pending-counter"]').text()).toContain("1 appel en attente d'envoi");
  });

  it('en ligne : l’appel part tout de suite et la file se vide', async () => {
    const date = todayIso();
    const { calls, wrapper } = await openCall({ ['PUT /public/classes/C1/attendance/' + date]: { data: { status: 'ok' } } });
    await wrapper.findAll('.roster__btn')[0]!.trigger('click');
    await wrapper.findAll('button').find((b) => b.text().startsWith("Valider l'appel"))!.trigger('click');
    await flushPromises();
    const put = find(calls, 'PUT', '/public/classes/C1/attendance/' + date);
    expect(put?.body?.records).toEqual([{ person_id: 'P00', status: 'A' }]);
    expect(wrapper.text()).toContain('Appel enregistré et envoyé');
    expect(await listQueue()).toHaveLength(0);
  });

  it('exige un motif pour corriger une demi-journée déjà faite', async () => {
    const date = todayIso();
    const { wrapper } = await openCall({
      ['GET /public/classes/C1/attendance/' + date]: { data: { structure_id: 'C1', date, headcount: 60, parts: [{ day_part: 'morning', taken: true }, { day_part: 'afternoon', taken: true }] } },
    });
    expect(wrapper.text()).toContain('déjà fait');
    const validate = wrapper.findAll('button').find((b) => b.text().startsWith("Valider l'appel"))!;
    expect(validate.attributes('disabled')).toBeDefined();
    await wrapper.get('#ps-reason').setValue('Erreur de saisie');
    expect(validate.attributes('disabled')).toBeUndefined();
  });

  it('prévient quand le serveur ne fournit pas encore la liste des élèves', async () => {
    const { wrapper } = await openCall({ 'GET /public/classes/C1/pupils': jsonResponse({ type: 'about:blank', title: 'Introuvable', status: 404, detail: 'Non.' }, 404) });
    expect(wrapper.text()).toContain("La liste des élèves de cette classe n'est pas disponible");
  });
});

describe('inscription', () => {
  const fill = async (wrapper: Awaited<ReturnType<typeof mountView>>['wrapper']) => {
    await wrapper.get('#en-last').setValue('Fotso');
    await wrapper.get('#en-first').setValue('Léa');
    await wrapper.get('#en-birth').setValue('2018-04-12');
    await wrapper.get('input[name="en-sex"][value="F"]').setValue(true);
  };

  it('arrête sur un doublon probable, propose la fiche existante, et n’inscrit qu’avec confirmation et motif (même client_uuid)', async () => {
    let attempts = 0;
    const calls = connect('pub_school_director', {
      'GET /academic-years': page([year]),
      'GET /public/classes': { data: [classRow] },
      'POST /public/pupils': () => {
        attempts++;
        if (attempts === 1) {
          return jsonResponse({ type: 'https://api.scholaris.cm/errors/conflict', title: 'Doublon probable', status: 409, detail: 'Un élève semblable existe déjà.', possible_duplicates: ['P-OLD'] }, 409);
        }
        return jsonResponse({ data: { person_id: 'P-NEW', learner_number: 'PUB-0001', last_name: 'Fotso', first_name: 'Léa', birth_date: '2018-04-12', warnings: ['possible_duplicate', 'birth_act_missing'], replayed: false } }, 201);
      },
    });
    const { wrapper } = await mountView(EnrolPupilView);
    await fill(wrapper);
    await wrapper.get('form').trigger('submit');
    await flushPromises();

    expect(wrapper.get('[data-test="duplicates"]').text()).toContain('P-OLD');
    const submit = wrapper.get('button[type="submit"]');
    expect(submit.attributes('disabled')).toBeDefined();
    await wrapper.get('.notice input[type="checkbox"]').setValue(true);
    await wrapper.get('#en-reason').setValue('Jumeaux de familles différentes');
    expect(submit.attributes('disabled')).toBeUndefined();
    await wrapper.get('form').trigger('submit');
    await flushPromises();

    const posts = calls.filter((c) => c.method === 'POST' && c.path === '/public/pupils');
    expect(posts).toHaveLength(2);
    expect(posts[0]?.body?.confirm_different).toBeUndefined();
    expect(posts[1]?.body).toMatchObject({ confirm_different: true, override_reason: 'Jumeaux de familles différentes', last_name: 'Fotso', sex: 'F' });
    expect(posts[1]?.body?.client_uuid).toBe(posts[0]?.body?.client_uuid);
    expect(posts[0]?.headers['X-Establishment-Id']).toBe('E1');
    expect(wrapper.get('[data-test="enrol-done"]').text()).toContain('PUB-0001');
    expect(wrapper.text()).toContain('Acte de naissance manquant');
  });

  it('refuse une date de naissance future', async () => {
    connect('pub_school_director', { 'GET /academic-years': page([year]), 'GET /public/classes': { data: [] } });
    const { wrapper } = await mountView(EnrolPupilView);
    await fill(wrapper);
    await wrapper.get('#en-birth').setValue('2999-01-01');
    expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeDefined();
  });
});

describe('classes, transferts et messages', () => {
  it('signale les classes au-dessus du seuil et propose une répartition sans rien déplacer', async () => {
    const calls = connect('pub_school_director', {
      'GET /academic-years': page([year]),
      'GET /public/classes': { data: [classRow, { ...classRow, structure_id: 'C2', label: 'CM2 B', headcount: 20, capacity_alert: false }] },
      'GET /public/staff': page([]),
      'GET /org-units': page([]),
      'GET /public/classes/distribution-proposal': { data: { classes: [{ structure_id: 'C1', label: 'CM2 A', pupil_ids: [], headcount: 40, female: 20, male: 20 }], moves: [{ person_id: 'P1', from_structure_id: 'C1', to_structure_id: 'C2' }] } },
    });
    const { wrapper } = await mountView(ClassesView);
    expect(wrapper.text()).toContain('Au-dessus de 60');
    const boxes = wrapper.findAll('input[type="checkbox"]');
    await boxes[0]!.setValue(true);
    await boxes[1]!.setValue(true);
    await wrapper.findAll('button').find((b) => b.text().startsWith('Proposer'))!.trigger('click');
    await flushPromises();
    expect(find(calls, 'GET', '/public/classes/distribution-proposal')?.query.class_ids).toBe('C1,C2');
    expect(wrapper.text()).toContain('1 élève(s) changeraient de classe');
    expect(calls.some((c) => c.method !== 'GET')).toBe(false);
  });

  it('transfert reçu : accepter avec date et classe, refuser avec motif obligatoire', async () => {
    const transfer = { id: 'T1', person_id: 'P9', pupil_name: 'Awa Njoya', learner_number: 'X', from_establishment_id: 'E2', to_establishment_id: 'E1', state: 'requested', requested_at: '2026-10-01', decided_at: null, arrival_on: null, refusal_reason: null, exeat_document_id: null, to_structure_id: null };
    const calls = connect('pub_school_director', {
      'GET /public/transfers': { data: [transfer] },
      'GET /academic-years': page([year]),
      'GET /public/classes': { data: [classRow] },
      'POST /public/transfers/T1/accept': { data: { ...transfer, state: 'accepted', arrival_on: '2026-10-05' } },
    });
    const { wrapper } = await mountView(TransfersView);
    expect(wrapper.text()).toContain('Awa Njoya');
    await wrapper.findAll('button').find((b) => b.text() === 'Traiter')!.trigger('click');
    const refuse = wrapper.findAll('button').find((b) => b.text() === 'Refuser')!;
    expect(refuse.attributes('disabled')).toBeDefined();
    await wrapper.get('#tr-date-T1').setValue('2026-10-05');
    await wrapper.get('#tr-class-T1').setValue('C1');
    await wrapper.get('form.decision').trigger('submit');
    await flushPromises();
    expect(find(calls, 'POST', '/public/transfers/T1/accept')?.body).toEqual({ arrival_on: '2026-10-05', class_id: 'C1' });
    expect(wrapper.text()).toContain('Transfert accepté');
  });

  it('message à une classe : le même envoi rejoué ne part pas deux fois (client_uuid conservé jusqu’au succès)', async () => {
    let n = 0;
    const calls = connect('pub_class_teacher', {
      'GET /academic-years': page([year]),
      'GET /public/classes': { data: [classRow] },
      'GET /public/messages': page([]),
      'POST /public/messages': () => {
        n++;
        return n === 1
          ? jsonResponse({ type: 'about:blank', title: 'Indisponible', status: 503, detail: 'Réessayez.' }, 503)
          : jsonResponse({ data: { id: 'M1', audience: 'class', target: 'C1', template_key: 'pub.class_notice', variables: {}, classes_count: 1, recipients_count: 31, sent_by: 'U1', created_at: '2026-10-21T08:00:00Z' } }, 201);
      },
    });
    const { wrapper } = await mountView(MessagesView);
    // L'enseignant ne peut écrire qu'à sa classe.
    expect(wrapper.find('#ms-audience option[value="school"]').exists()).toBe(false);
    await wrapper.get('#ms-class').setValue('C1');
    await wrapper.get('#ms-details').setValue('Réunion de classe vendredi');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    const posts = calls.filter((c) => c.method === 'POST' && c.path === '/public/messages');
    expect(posts).toHaveLength(2);
    expect(posts[1]?.body?.client_uuid).toBe(posts[0]?.body?.client_uuid);
    expect(posts[1]?.body).toMatchObject({ audience: 'class', target: 'C1', template_key: 'pub.class_notice', variables: { details: 'Réunion de classe vendredi' } });
    expect(wrapper.text()).toContain('Message envoyé à 31 parents');
  });
});

describe('contexte', () => {
  it('les permissions viennent du rôle actif', () => {
    connect('pub_class_teacher', {});
    expect(useContextStore().can('public_schools.attendance.write')).toBe(true);
    expect(useContextStore().can('public_schools.pupils.write')).toBe(false);
  });
});

describe('barrière du code personnel', () => {
  async function type(wrapper: Awaited<ReturnType<typeof mountView>>['wrapper'], digits: string, box = 0): Promise<void> {
    const inputs = wrapper.findAll('.code-boxes')[box]!.findAll('input');
    for (let i = 0; i < digits.length; i++) await inputs[i]!.setValue(digits[i]);
    await flushPromises();
  }

  it('affiche le nombre d’essais restants puis efface l’appareil au cinquième échec', async () => {
    connect('pub_class_teacher', { 'GET /academic-years': page([year]), 'GET /public/classes': { data: [] } });
    await setupPin('123456', 1000);
    lockVault();
    const { wrapper } = await mountView(CallView);
    expect(wrapper.text()).toContain('Code personnel');
    await type(wrapper, '000000');
    expect(wrapper.text()).toContain('Il vous reste 4 essai(s)');
    for (let i = 0; i < 3; i++) await type(wrapper, '000000');
    expect(wrapper.text()).toContain('Il vous reste 1 essai(s)');
    await type(wrapper, '000000');
    expect(wrapper.text()).toContain('Ce téléphone a été effacé');
    expect(wrapper.text()).toContain('Créez votre code personnel');
  });

  it('crée le code sur un appareil neuf puis ouvre le contenu', async () => {
    connect('pub_class_teacher', { 'GET /academic-years': page([year]), 'GET /public/classes': { data: [] } });
    const { wrapper } = await mountView(CallView);
    await type(wrapper, '654321', 0);
    await type(wrapper, '654321', 1);
    await wrapper.get('form').trigger('submit');
    await flushPromises(12);
    expect(wrapper.text()).not.toContain('Créez votre code personnel');
    expect(wrapper.find('#ps-class').exists()).toBe(true);
  }, 20_000);
});

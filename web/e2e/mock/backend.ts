import type { Page, Route, Request } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

/**
 * Faux backend de l'API /api/v1, installé par interception réseau (page.route). Les corps viennent de
 * fixtures/recorded.json, enregistré depuis la pile PHP réelle (tests/E2E/UiFixturesRecorderTest.php) : mêmes champs,
 * mêmes enveloppes { data, meta }, mêmes erreurs RFC 9457. L'état (connexion, établissements créés, notes, appels reçus)
 * vit en mémoire le temps d'un test. Toute requête non prévue répond 404 Problem Details et est consignée dans `unhandled`.
 */
type Role = 'admin' | 'teacher' | 'parent';
interface Recorded { request: string; status: number; content_type: string; body: unknown }

const here = dirname(fileURLToPath(import.meta.url));
const recorded: Record<string, Recorded> = JSON.parse(readFileSync(join(here, '..', 'fixtures', 'recorded.json'), 'utf8')).calls;

export const ACCOUNTS: Record<Role, { identifier: string; password: string }> = {
  admin: { identifier: '+237699000001', password: 'Recette-Admin-2026!' },
  teacher: { identifier: '+237699000002', password: 'Recette-Prof-2026!' },
  parent: { identifier: '+237699000003', password: 'Recette-Parent-2026!' },
};

export interface MockOptions {
  /** Réplique le comportement réel constaté (défaut D2) : le parent reçoit 403 sur les routes de paiement. */
  parentPaymentForbidden?: boolean;
  /** La synchronisation des notes renvoie un conflit 409 pour la prochaine note envoyée. */
  gradeConflictOnce?: boolean;
  /** Le paiement mobile money est confirmé après N lectures de GET /payments/{id}. */
  paymentConfirmAfterPolls?: number;
}

export interface MockState {
  role: Role | null;
  unhandled: string[];
  calls: string[];
  attendanceSyncs: Array<Record<string, unknown>>;
  gradeSyncs: Array<Record<string, unknown>>;
  establishments: Array<Record<string, unknown>>;
  payments: Map<string, { polls: number; body: Record<string, unknown> }>;
  idempotencyKeys: string[];
  exports: string[];
}

const clone = <T>(v: T): T => JSON.parse(JSON.stringify(v)) as T;
const body = (key: string): any => clone(recorded[key]!.body);
let seq = 0;
const ulid = (): string => ('01MOCK' + String(Date.now()) + String(++seq).padStart(6, '0')).slice(0, 26).toUpperCase().padEnd(26, '0');

function problem(status: number, title: string, detail: string, extra: Record<string, unknown> = {}): { status: number; json: unknown; type: string } {
  const slug = { 400: 'bad-request', 401: 'unauthenticated', 403: 'forbidden', 404: 'not-found', 409: 'conflict', 422: 'validation' }[status] ?? 'error';
  return { status, type: 'application/problem+json', json: { type: `https://api.scholaris.cm/errors/${slug}`, title, status, detail, trace_id: ulid(), ...extra } };
}

function todayIn(tz = 'Africa/Douala'): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
}

/** Semaine d'emploi du temps calculée pour la date demandée, avec le créneau enregistré placé le jour même. */
function timetableFor(week: string): unknown {
  const rec = body('teacher.timetable');
  const entry = rec.data.days.flatMap((d: any) => d.entries)[0];
  const start = new Date(week + 'T12:00:00Z');
  const monday = new Date(start);
  monday.setUTCDate(start.getUTCDate() - ((start.getUTCDay() + 6) % 7));
  const days = Array.from({ length: 7 }, (_, i) => {
    const d = new Date(monday);
    d.setUTCDate(monday.getUTCDate() + i);
    const iso = d.toISOString().slice(0, 10);
    return { date: iso, weekday: i + 1, entries: iso === week ? [entry] : [] };
  });
  rec.data.week_start = days[0]!.date;
  rec.data.week_end = days[6]!.date;
  rec.data.days = days;
  return rec;
}

export async function installApi(page: Page, opts: MockOptions = {}): Promise<MockState> {
  const state: MockState = {
    role: null, unhandled: [], calls: [], attendanceSyncs: [], gradeSyncs: [],
    establishments: clone(body('admin.establishments.all').data), payments: new Map(), idempotencyKeys: [], exports: [],
  };
  let conflictPending = opts.gradeConflictOnce ?? false;
  const grid = body('teacher.gradesheet.after');

  await page.route('**/api/v1/**', async (route: Route, request: Request) => {
    const url = new URL(request.url());
    const path = url.pathname.replace(/^\/api\/v1/, '');
    const method = request.method();
    const key = `${method} ${path}`;
    state.calls.push(key + (url.search ? url.search : ''));
    const idem = request.headers()['idempotency-key'];
    if (idem) state.idempotencyKeys.push(idem);
    const send = async (r: { status: number; json?: unknown; type?: string; raw?: Buffer | string; headers?: Record<string, string> }): Promise<void> => {
      await route.fulfill({
        status: r.status,
        headers: { 'Content-Type': r.type ?? 'application/json; charset=utf-8', 'X-Content-Type-Options': 'nosniff', 'Cache-Control': 'no-store', ...(r.headers ?? {}) },
        body: r.raw !== undefined ? r.raw : JSON.stringify(r.json ?? {}),
      });
    };
    const json = (status: number, data: unknown) => send({ status, json: data });
    const payload = (): any => {
      try {
        return request.postDataJSON();
      } catch {
        return {};
      }
    };
    const who = state.role;

    // Public.
    if (key === 'POST /auth/login') {
      const b = payload();
      const role = (Object.keys(ACCOUNTS) as Role[]).find((r) => ACCOUNTS[r].identifier === String(b.identifier ?? '').trim() && ACCOUNTS[r].password === b.password);
      if (!role) return send({ ...problem(401, 'Identifiants incorrects', body('login.bad').detail, { attempts_left: 4 }) });
      state.role = role;
      const me = body(`${role}.me`).data;
      return json(200, {
        token_type: 'Bearer', expires_at: new Date(Date.now() + 8 * 3600e3).toISOString(), new_device: false,
        two_factor_required: false, two_factor_setup_required: false,
        user: { id: me.id, person_id: me.person_id, first_name: me.first_name, last_name: me.last_name, locale: me.locale, status: me.status },
      });
    }
    if (key === 'GET /health') return json(200, { status: 'ok' });
    if (!who) return send(problem(401, 'Authentification requise', 'Connectez-vous pour continuer.'));

    // Session et contexte.
    if (key === 'GET /me') return json(200, body(`${who}.me`));
    // Lanceur d'applications (chantier Plateforme) : réponse réelle enregistrée par rôle.
    if (key === 'GET /me/applications') return json(200, body(`${who}.applications`));
    if (key === 'POST /auth/logout') {
      state.role = null;
      return send({ status: 204, raw: '' });
    }
    if (key === 'GET /me/contexts') {
      const tiles = body(`${who}.contexts`);
      return json(200, tiles);
    }
    if (key === 'POST /me/context') return json(200, body(`${who}.context`));
    if (key === 'GET /me/todo') return json(200, body(`${who}.todo`));
    if (key === 'GET /vocabulary') return json(200, body('admin.vocabulary'));

    // Administration.
    if (path === '/establishments') {
      if (who !== 'admin') return send(problem(403, 'Accès refusé', 'Vous n\'avez pas le droit de consulter l\'organisation.'));
      if (method === 'GET') return json(200, { data: state.establishments, links: { next: null }, meta: { next_cursor: null } });
      if (method === 'POST') {
        const b = payload();
        const errors: Record<string, string[]> = {};
        if (!b.name || String(b.name).trim() === '') errors.name = ['Le nom est obligatoire.'];
        if (!['nursery', 'primary', 'secondary', 'higher'].includes(b.type)) errors.type = ['Type attendu : nursery, primary, secondary ou higher.'];
        if (Object.keys(errors).length) return send(problem(422, 'Données invalides', 'Données invalides.', { errors }));
        const created = { ...body('admin.establishment.created').data, id: ulid(), name: String(b.name).trim(), type: b.type };
        state.establishments.push(created);
        return json(201, { data: created });
      }
    }
    if (method === 'GET' && /^\/establishments\/[^/]+$/.test(path)) {
      const found = state.establishments.find((e) => e.id === path.split('/')[2]);
      return found ? json(200, { data: found }) : send(problem(404, 'Introuvable', 'Établissement introuvable.'));
    }

    // Lectures de l'établissement.
    const reads: Record<string, string> = {
      '/structures': `${who === 'teacher' ? 'teacher' : 'admin'}.structures`,
      '/periods': `${who === 'teacher' ? 'teacher' : 'admin'}.periods`,
      '/people': `${who === 'teacher' ? 'teacher' : 'admin'}.people`,
      '/enrollments': 'teacher.enrollments',
      '/academic-years': 'admin.years',
    };
    if (method === 'GET' && reads[path]) {
      if (who === 'parent' && path !== '/structures') return send(problem(403, 'Accès refusé', 'Accès refusé.'));
      return json(200, body(reads[path]!));
    }
    if (key === 'GET /timetable') return json(200, timetableFor(url.searchParams.get('week') ?? todayIn()));

    // Appel hors ligne.
    if (key === 'POST /attendance-sessions/sync') {
      const sessions: any[] = payload().sessions ?? [];
      state.attendanceSyncs.push(...sessions);
      const template = body('teacher.attendance.sync').data[0];
      return json(200, {
        data: sessions.map((s) => ({ client_uuid: s.client_uuid, status: 'synced', replayed: false, session: { ...template.session, id: ulid(), slot_id: s.slot_id, session_date: s.date, client_uuid: s.client_uuid } })),
      });
    }

    // Notes.
    if (key === 'GET /grade-sheets') return json(200, grid);
    if (key === 'POST /grades/sync') {
      const entries: any[] = payload().entries ?? [];
      state.gradeSyncs.push(...entries);
      if (conflictPending && entries.length > 0) {
        conflictPending = false;
        const c = body('teacher.grades.sync.conflict');
        const e = entries[0];
        c.results[0].client_uuid = e.client_uuid;
        c.results[0].client = { ...c.results[0].client, score: e.value ?? null, person_id: e.person_id, assessment_id: e.assessment_id, base_version: e.base_version, client_uuid: e.client_uuid };
        c.results[0].server = { ...c.results[0].server, person_id: e.person_id, assessment_id: e.assessment_id };
        return send({ status: 409, type: 'application/problem+json', json: c });
      }
      const ok = body('teacher.grades.sync.ok').data.results[0];
      return json(200, {
        data: {
          results: entries.map((e) => {
            const cell = grid.data.rows.find((r: any) => r.person_id === e.person_id)?.cells[e.assessment_id];
            if (cell) Object.assign(cell, { state: e.value === null ? 'missing' : 'value', value: e.value, version: (cell.version ?? 0) + 1 });
            return { ...ok, client_uuid: e.client_uuid, grade: { ...ok.grade, person_id: e.person_id, assessment_id: e.assessment_id, score: e.value, version: cell?.version ?? 1, client_uuid: e.client_uuid } };
          }),
        },
      });
    }
    if (key === 'GET /class-councils') return json(200, { data: [], links: { next: null }, meta: { next_cursor: null } });

    // Portail famille et paiement.
    if (key === 'GET /portal/family') return json(200, body('parent.portal'));
    if (path === '/payment-references' || key === 'POST /payments') {
      // Refus serveur simulé (le défaut D2 est corrigé : le parent a désormais billing.self.pay sur SES références).
      if (who === 'parent' && opts.parentPaymentForbidden) return send(problem(403, 'Accès refusé', 'Vous n\'avez pas le droit d\'effectuer cette opération de facturation.'));
      if (method === 'GET') return json(200, { data: [{ reference: 'FAM-0001', guarantor_id: body('parent.portal').data.guarantor_id }], links: { next: null }, meta: { next_cursor: null } });
      if (method === 'POST' && path === '/payment-references') return json(201, { data: { reference: 'FAM-0001' } });
      const pending = { ...body('cashier.payment.pending').data, id: ulid(), amount_xaf: payload().amount_xaf, status: 'pending' };
      state.payments.set(pending.id, { polls: 0, body: pending });
      return json(202, { data: pending });
    }
    if (method === 'GET' && /^\/payments\/[^/]+$/.test(path)) {
      const p = state.payments.get(path.split('/')[2]!);
      if (!p) return send(problem(404, 'Introuvable', 'Paiement introuvable.'));
      p.polls += 1;
      if (p.polls >= (opts.paymentConfirmAfterPolls ?? 1)) p.body = { ...p.body, status: 'confirmed', receipt_number: 'REC-2026-00001' };
      return json(200, { data: p.body });
    }

    // Exports CSV / xlsx.
    if (method === 'GET' && path.endsWith('/export')) {
      state.exports.push(key + url.search);
      const xlsx = url.searchParams.get('format') === 'xlsx';
      const rec = recorded[xlsx ? 'admin.people.xlsx' : 'admin.people.csv']!;
      return send({
        status: 200,
        type: xlsx ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv; charset=utf-8',
        raw: xlsx ? Buffer.from(String(rec.body), 'base64') : String(rec.body),
        headers: { 'Content-Disposition': `attachment; filename="export.${xlsx ? 'xlsx' : 'csv'}"` },
      });
    }

    state.unhandled.push(key + url.search);
    return send(problem(404, 'Introuvable', `Route non simulée : ${key}`));
  });

  return state;
}

// Test de charge k6 de l'API SCHOLARIS. NON EXÉCUTÉ dans le dépôt (k6 absent du poste de recette) : à lancer par un humain
// contre une RECETTE remplie de données fictives, jamais contre la production.
//
//   k6 run \
//     -e BASE_URL=https://recette.exemple.cm \
//     -e ESTABLISHMENT_ID=<id> \
//     -e LOGIN_IDENTIFIER=<compte sans 2FA, ex. parent fictif> -e LOGIN_PASSWORD=<mot de passe> \
//     -e ADMIN_TOKEN=<jeton> -e CASHIER_TOKEN=<jeton> -e TEACHER_TOKEN=<jeton> \
//     -e FAMILY_REFERENCE=FAM-0001 -e LEARNER_IDS=<id1,id2,...> -e SLOT_IDS=<id1,id2,...> -e ASSESSMENT_ID=<id> \
//     scripts/loadtest/k6-api.js
//
// Les jetons ADMIN/CASHIER/TEACHER sont ceux de comptes de recette ayant DÉJÀ franchi la double authentification
// (la 2FA TOTP ne s'automatise pas proprement dans k6). Un scénario dont les variables manquent est ignoré (log).
// La limitation de débit de /auth/login (par IP et par identifiant) doit être relevée sur la recette, sinon le scénario
// « connexion » mesurera surtout des 429.
//
// Seuils (objectif du CDC) : p95 < 300 ms sur les lectures, taux d'erreur < 1 % ; écritures suivies sans seuil bloquant
// (p95 < 800 ms indicatif), connexion Argon2id indicative (p95 < 1 500 ms). k6 sort en code 99 si un seuil échoue.
import http from 'k6/http';
import { check, sleep } from 'k6';
import { Trend, Rate } from 'k6/metrics';

const BASE = (__ENV.BASE_URL || '').replace(/\/$/, '') + '/api/v1';
const EST = __ENV.ESTABLISHMENT_ID || '';
const list = (name) => (__ENV[name] || '').split(',').map((v) => v.trim()).filter(Boolean);
const LEARNERS = list('LEARNER_IDS');
const SLOTS = list('SLOT_IDS');
const VUS = Number(__ENV.VUS || 25);
const DURATION = __ENV.DURATION || '3m';

const readLatency = new Trend('scholaris_read_ms', true);
const writeLatency = new Trend('scholaris_write_ms', true);
const businessErrors = new Rate('scholaris_errors');

const scenario = (exec, vus) => ({ executor: 'constant-vus', exec, vus, duration: DURATION });

export const options = {
  scenarios: {
    connexion: scenario('login', Math.max(1, Math.round(VUS * 0.08))),
    personnes: scenario('people', Math.max(1, Math.round(VUS * 0.4))),
    encaissement: scenario('cash', Math.max(1, Math.round(VUS * 0.16))),
    appel: scenario('attendance', Math.max(1, Math.round(VUS * 0.16))),
    notes: scenario('grades', Math.max(1, Math.round(VUS * 0.16))),
    documents: { executor: 'per-vu-iterations', exec: 'documents', vus: 1, iterations: Number(__ENV.DOC_BATCHES || 3), maxDuration: DURATION },
  },
  thresholds: {
    http_req_failed: ['rate<0.01'],
    scholaris_errors: ['rate<0.01'],
    scholaris_read_ms: ['p(95)<300'],
    'http_req_duration{kind:read}': ['p(95)<300'],
    'http_req_duration{kind:write}': ['p(95)<800'],
    'http_req_duration{name:login}': ['p(95)<1500'],
  },
};

const headers = (token, extra = {}) => Object.assign({ Authorization: `Bearer ${token}`, Accept: 'application/json', 'Content-Type': 'application/json', 'X-Establishment-Id': EST }, extra);
const uuid = () => 'k6-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 12);
const pick = (arr) => arr[Math.floor(Math.random() * arr.length)];
const need = (names) => {
  const missing = names.filter((n) => !__ENV[n]);
  if (missing.length) {
    console.warn(`scénario ignoré : variables manquantes ${missing.join(', ')}`);
    sleep(5);
    return false;
  }
  return true;
};
const record = (res, kind, okStatuses) => {
  (kind === 'read' ? readLatency : writeLatency).add(res.timings.duration);
  const ok = okStatuses.includes(res.status);
  businessErrors.add(!ok);
  check(res, { [`${res.request.method} statut attendu`]: () => ok });
  return ok;
};

export function setup() {
  if (!__ENV.BASE_URL) {
    throw new Error('BASE_URL est obligatoire.');
  }
  const health = http.get(`${BASE}/health`);
  if (health.status !== 200) {
    throw new Error(`/health renvoie ${health.status}`);
  }
}

// Connexion (Argon2id) : compte de recette sans 2FA obligatoire.
export function login() {
  if (!need(['LOGIN_IDENTIFIER', 'LOGIN_PASSWORD'])) return;
  const res = http.post(`${BASE}/auth/login`, JSON.stringify({ identifier: __ENV.LOGIN_IDENTIFIER, password: __ENV.LOGIN_PASSWORD }), { headers: { 'Content-Type': 'application/json' }, tags: { name: 'login', kind: 'auth' } });
  record(res, 'write', [200]);
  sleep(2);
}

// Liste paginée de personnes : première page puis deux pages suivantes par curseur ; recherche.
export function people() {
  if (!need(['ADMIN_TOKEN', 'ESTABLISHMENT_ID'])) return;
  const h = { headers: headers(__ENV.ADMIN_TOKEN), tags: { name: 'people', kind: 'read' } };
  let res = http.get(`${BASE}/people?per_page=50`, h);
  record(res, 'read', [200]);
  for (let i = 0; i < 2 && res.status === 200; i++) {
    const cursor = res.json('meta.next_cursor');
    if (!cursor) break;
    res = http.get(`${BASE}/people?per_page=50&cursor=${encodeURIComponent(cursor)}`, h);
    record(res, 'read', [200]);
  }
  record(http.get(`${BASE}/people?per_page=20&filter[q]=Nom`, { headers: headers(__ENV.ADMIN_TOKEN), tags: { name: 'people-search', kind: 'read' } }), 'read', [200]);
  sleep(1);
}

// Encaissement en espèces avec Idempotency-Key : la même clé rejouée doit renvoyer le même paiement (200), pas un doublon.
export function cash() {
  if (!need(['CASHIER_TOKEN', 'ESTABLISHMENT_ID', 'FAMILY_REFERENCE'])) return;
  const key = uuid();
  const body = JSON.stringify({ amount_xaf: 500, family_reference: __ENV.FAMILY_REFERENCE });
  const first = http.post(`${BASE}/cash/receipts`, body, { headers: headers(__ENV.CASHIER_TOKEN, { 'Idempotency-Key': key }), tags: { name: 'cash', kind: 'write' } });
  if (record(first, 'write', [201])) {
    const replay = http.post(`${BASE}/cash/receipts`, body, { headers: headers(__ENV.CASHIER_TOKEN, { 'Idempotency-Key': key }), tags: { name: 'cash-replay', kind: 'write' } });
    record(replay, 'write', [200, 201]);
    check(replay, { 'rejeu idempotent : même paiement': (r) => r.json('data.id') === first.json('data.id') });
  }
  sleep(1);
}

// Appel synchronisé (format hors ligne de l'application enseignant).
export function attendance() {
  if (!need(['TEACHER_TOKEN', 'ESTABLISHMENT_ID', 'SLOT_IDS']) || !LEARNERS.length) return;
  const date = new Date().toISOString().slice(0, 10);
  const records = LEARNERS.slice(0, 30).map((p) => ({ person_id: p, status: Math.random() < 0.1 ? 'A' : 'P', client_uuid: uuid() }));
  const res = http.post(`${BASE}/attendance-sessions/sync`, JSON.stringify({ sessions: [{ client_uuid: uuid(), slot_id: pick(SLOTS), date, validate: false, records }] }), { headers: headers(__ENV.TEACHER_TOKEN), tags: { name: 'attendance-sync', kind: 'write' } });
  record(res, 'write', [200]);
  sleep(2);
}

// Saisie de notes par lot (une classe).
export function grades() {
  if (!need(['ADMIN_TOKEN', 'ESTABLISHMENT_ID', 'ASSESSMENT_ID']) || !LEARNERS.length) return;
  const entries = LEARNERS.slice(0, 50).map((p) => ({ person_id: p, value: Math.round(Math.random() * 40) / 2 }));
  const res = http.post(`${BASE}/grades/batch`, JSON.stringify({ assessment_id: __ENV.ASSESSMENT_ID, entries }), { headers: headers(__ENV.ADMIN_TOKEN), tags: { name: 'grades-batch', kind: 'write' } });
  record(res, 'write', [200, 201]);
  sleep(2);
}

// Génération d'un lot de documents (création 202 puis traitement par tranches jusqu'à « done »).
export function documents() {
  if (!need(['ADMIN_TOKEN', 'ESTABLISHMENT_ID']) || !LEARNERS.length) return;
  const items = LEARNERS.slice(0, Number(__ENV.DOC_BATCH_SIZE || 200)).map((p) => ({ subject_person_id: p, data: { academic_year: '2026-2027', class_name: 'Recette' } }));
  const started = Date.now();
  const created = http.post(`${BASE}/document-batches`, JSON.stringify({ type: 'attestation_simple', items }), { headers: headers(__ENV.ADMIN_TOKEN), tags: { name: 'doc-batch', kind: 'write' } });
  if (!record(created, 'write', [201, 202])) return;
  const id = created.json('data.id');
  let status = created.json('data.status');
  for (let i = 0; i < 100 && status !== 'done'; i++) {
    const res = http.post(`${BASE}/document-batches/${id}/process`, '{}', { headers: headers(__ENV.ADMIN_TOKEN), tags: { name: 'doc-batch-process', kind: 'batch' }, timeout: '120s' });
    if (!record(res, 'write', [200])) return;
    status = res.json('data.status');
  }
  check(status, { 'lot terminé': (s) => s === 'done' });
  console.log(`lot de ${items.length} documents : ${((Date.now() - started) / 1000).toFixed(1)} s`);
}

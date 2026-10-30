// API des écoles publiques (préfixe /api/v1/public) : fiche et infrastructure, classes, élèves, transferts, abandons, appel,
// présence des enseignants, personnel et messages. Contrats : specs/001-socle-plateforme/contracts/openapi.d/public-schools-*.yaml.
//
// Le client HTTP n'envoie jamais X-Establishment-Id sous /public (préfixe réservé aux routes sans compte) alors que ces routes-ci
// l'exigent : il est donc posé ici, explicitement, à chaque appel (établissement actif ou celui de l'élément rejoué).
import { ref } from 'vue';
import { http, type QueryValue, type RequestOptions } from '@/api/http';
import { useContextStore } from '@/stores/context';

const ROOT = '/public';

function options(establishmentId?: string | null): RequestOptions {
  const id = establishmentId ?? useContextStore().establishmentId;
  return id ? { headers: { 'X-Establishment-Id': id } } : {};
}

/**
 * Finalité d'une lecture nominative par la supervision territoriale (inspection, délégation) : le serveur l'exige et l'inscrit au
 * journal des consultations nominatives. Dans l'école (direction, enseignant), elle n'est jamais envoyée.
 */
export type Purpose = 'transfer' | 'exam' | 'control';
export const PURPOSES: readonly Purpose[] = ['transfer', 'exam', 'control'];
export const supervisionPurpose = ref<Purpose>('control');

/** Vrai pour un rôle de supervision : il lit du nominatif sans exercer dans l'école (ni écriture de la fiche, ni appel). */
export function isSupervision(): boolean {
  const ctx = useContextStore();
  if (ctx.can('public_schools.school.write', 'public_schools.attendance.write')) return false;
  return ctx.can('public_schools.pupils.named.read', 'public_schools.teacher_presence.validate');
}

function withPurpose(query?: Record<string, QueryValue>): Record<string, QueryValue> | undefined {
  if (!isSupervision() || query?.purpose !== undefined) return query;
  return { ...(query ?? {}), purpose: supervisionPurpose.value };
}

const get = async <T>(path: string, query?: Record<string, QueryValue>, est?: string | null): Promise<T> =>
  (await http.get<{ data: T }>(`${ROOT}${path}`, withPurpose(query), options(est))).data;
const post = async <T>(path: string, body?: unknown, est?: string | null): Promise<T> =>
  (await http.post<{ data: T }>(`${ROOT}${path}`, body ?? {}, options(est))).data;
const put = async <T>(path: string, body?: unknown, est?: string | null): Promise<T> =>
  (await http.put<{ data: T }>(`${ROOT}${path}`, body ?? {}, options(est))).data;
const patch = async <T>(path: string, body?: unknown, est?: string | null): Promise<T> =>
  (await http.patch<{ data: T }>(`${ROOT}${path}`, body ?? {}, options(est))).data;

/** Une page d'une liste paginée par curseur. */
export interface Paged<T> {
  items: T[];
  next: string | null;
}

async function page<T>(path: string, query: Record<string, QueryValue> = {}, cursor?: string | null, est?: string | null): Promise<Paged<T>> {
  const res: { data: T[]; meta?: { next_cursor?: string | null } } = await http.get(
    `${ROOT}${path}`,
    withPurpose({ ...query, ...(cursor ? { cursor } : {}) }),
    options(est),
  );
  return { items: res.data ?? [], next: res.meta?.next_cursor ?? null };
}

/** Liste paginée par curseur (50 par page) : toutes les pages, jusqu'à 20. */
async function all<T>(path: string, query: Record<string, QueryValue> = {}, est?: string | null): Promise<T[]> {
  const rows: T[] = [];
  let cursor: string | null = null;
  for (let i = 0; i < 20; i++) {
    const res: Paged<T> = await page<T>(path, query, cursor, est);
    rows.push(...res.items);
    cursor = res.next;
    if (!cursor) break;
  }
  return rows;
}

// ---------------------------------------------------------------------------------------------------------------- types

export type Sex = 'F' | 'M';
export type DayPart = 'morning' | 'afternoon';
export type CallStatus = 'P' | 'A' | 'R';
export type Presence = 'present' | 'absent' | 'permission' | 'mission';

export interface SchoolFiche {
  establishment_id: string;
  official_code: string;
  creation_act_ref: string | null;
  creation_act_date: string | null;
  official: boolean;
  subsystem: 'francophone' | 'anglophone' | 'bilingual';
  cycle: 'nursery' | 'primary' | 'group';
  school_group_ref: string | null;
  status: 'open' | 'closed' | 'suspended';
  latitude: number | null;
  longitude: number | null;
  phone: string | null;
  email: string | null;
}
export type SchoolFicheInput = Partial<Omit<SchoolFiche, 'establishment_id' | 'official'>>;

export interface Classroom {
  label: string;
  material: string;
  room_state: string;
  in_use: boolean;
}
export interface DigitalEquipment {
  label: string;
  quantity: number;
}
export interface InfrastructureVersion {
  id: string;
  year_label: string;
  version: number;
  state: 'draft' | 'closed';
  desks_count: number;
  water_points: number;
  latrines_girls: number;
  latrines_boys: number;
  has_electricity: boolean;
  has_fence: boolean;
  has_canteen: boolean;
  has_library: boolean;
  network_observed: 'unknown' | 'none' | 'limited' | 'available';
  digital_equipment: DigitalEquipment[];
  correction_reason: string | null;
  classrooms?: Classroom[];
}
export type InfrastructureInput = Partial<Omit<InfrastructureVersion, 'id' | 'version' | 'state' | 'correction_reason'>> & {
  year_label: string;
};

export interface ClassRow {
  structure_id: string;
  establishment_id: string;
  label: string;
  level_codes: string[];
  twinned: boolean;
  shift: string | null;
  homeroom_staff_id: string | null;
  headcount?: number;
  capacity_alert?: boolean;
  alert_threshold?: number;
}
export interface ClassInput {
  name: string;
  academic_year_id: string;
  org_unit_id: string;
  level_codes: string[];
  shift: 'full_day' | 'morning' | 'afternoon';
  capacity?: number;
  homeroom_staff_id?: string | null;
  homeroom_from?: string | null;
}
export interface ClassHeadcount {
  date: string;
  total: number;
  by_sex: { female: number; male: number; unknown: number };
  by_age: Record<string, number>;
  age_unknown: number;
  threshold: number;
  alert: boolean;
}
export interface HomeroomEntry {
  staff_id: string;
  starts_on: string;
  ends_on: string | null;
}
export interface DistributionProposal {
  classes: Array<{ structure_id: string; label: string; pupil_ids: string[]; headcount: number; female: number; male: number }>;
  moves: Array<{ person_id: string; from_structure_id: string | null; to_structure_id: string }>;
}
/** Un élève de la liste d'appel. Route de liste nominative de la classe : voir « Ce qui reste » du rapport X-004. */
export interface RosterPupil {
  person_id: string;
  last_name: string;
  first_name: string;
  sex?: Sex | null;
  learner_number?: string | null;
}

export interface Guardian {
  kind: 'parent' | 'guardian' | 'financial_guarantor' | 'authorized_pickup' | 'emergency';
  person_id?: string;
  identity?: Record<string, unknown>;
}
export interface PupilRegistration {
  last_name: string;
  first_name: string;
  birth_date: string;
  sex: Sex;
  birth_act_ref?: string | null;
  client_uuid?: string;
  class_id?: string | null;
  guardians?: Guardian[];
  confirm_different?: boolean;
  override_reason?: string | null;
}
export interface PupilRegistered {
  person_id: string;
  learner_number: string;
  last_name: string;
  first_name: string;
  birth_date: string;
  warnings: string[];
  replayed: boolean;
}
export interface PupilFile {
  person_id: string;
  learner_number: string | null;
  last_name: string;
  first_name: string;
  birth_date: string;
  sex: Sex;
  structure_id: string | null;
  enrollment_status: string;
  read_only: boolean;
  birth_act_ref: string | null;
  birth_act_verified: boolean;
  registered_on: string;
  registration_establishment_id: string;
  special_situations?: Record<string, unknown>;
  [key: string]: unknown;
}
export interface PupilProfileInput {
  birth_act_ref?: string | null;
  birth_act_verified?: boolean;
  home_language?: string | null;
  is_displaced?: boolean;
  is_refugee?: boolean;
  is_orphan?: boolean;
  accommodation_type?: string | null;
}
export interface WithoutBirthActRow {
  person_id: string;
  last_name: string;
  first_name: string;
  learner_number: string | null;
  registered_on: string | null;
}
export interface BirthActTransmission {
  quarter: string;
  pupil_count: number;
  generated_on: string;
  transmitted_on: string | null;
}

export type TransferState = 'requested' | 'accepted' | 'completed' | 'refused' | string;
export interface Transfer {
  id: string;
  person_id: string;
  pupil_name: string | null;
  learner_number: string | null;
  from_establishment_id: string;
  to_establishment_id: string;
  state: TransferState;
  requested_at: string;
  decided_at: string | null;
  arrival_on: string | null;
  refusal_reason: string | null;
  exeat_document_id: string | null;
  to_structure_id: string | null;
}
export interface Dropout {
  id: string;
  person_id: string;
  kind: 'declared' | 'presumed';
  detected_on: string;
  family_contacted_at: string | null;
  outcome: string | null;
  resolved_on: string | null;
  declared_by: string | null;
  note: string | null;
}

export interface AttendanceDay {
  structure_id: string;
  date: string;
  headcount: number;
  parts: Array<{
    day_part: DayPart;
    taken: boolean;
    headcount?: number;
    absent_count?: number;
    late_count?: number;
    recorded_at?: string;
    absent_person_ids?: string[];
  }>;
}
export interface CallRecord {
  person_id: string;
  status: 'A' | 'R';
  arrived_at?: string | null;
  note?: string | null;
}
export interface CallBody {
  day_part: DayPart;
  client_uuid: string;
  records: CallRecord[];
  reason?: string;
}
export interface AttendanceAlert {
  id: string;
  kind: string;
  subject_id: string;
  period_key: string;
  level: string;
  recipients: number;
  raised_on: string;
}

export interface TeacherPresence {
  id: string;
  staff_id: string;
  date: string;
  presence: Presence;
  arrived_at: string | null;
  left_at: string | null;
  reason: string | null;
  replacement_staff_id: string | null;
  check_result: string | null;
}
export interface PresenceInput {
  presence: Presence;
  arrived_at?: string | null;
  left_at?: string | null;
  reason?: string | null;
  replacement_person_id?: string | null;
  client_uuid?: string;
}
export interface UncoveredClass {
  id: string;
  structure_id: string;
  date: string;
}

export interface StaffMember {
  id: string;
  name: string;
  staff_id: string;
  grade: string | null;
  employment: string | null;
  public_status: string | null;
  civil_service_number?: string | null;
  contract_number?: string | null;
  professional_diploma?: string | null;
  service_start_on?: string | null;
}
/** École d'accueil possible d'un transfert ou d'une mutation (GET /public/schools/receiving). */
export interface ReceivingSchool {
  establishment_id: string;
  name: string;
  official_code: string;
  district_id: string | null;
  district: string | null;
}

/** Cahier d'appel mensuel (DOC-06) : une colonne par demi-journée dont l'appel a été fait. */
export interface AttendanceRegister {
  month: string;
  class: { structure_id: string; label: string };
  days: Array<{ date: string; morning: boolean; afternoon: boolean }>;
  pupils: Array<{ person_id: string; name: string; marks: Record<string, Partial<Record<DayPart, 'A' | 'P'>>>; absent_half_days: number }>;
}

export type CheckResult = 'confirmed' | 'discrepancy';
export type PublicStatus = 'civil_servant' | 'contract' | 'parents_teacher' | 'volunteer';
export interface StaffProfile {
  id: string;
  staff_id: string;
  grade: string | null;
  employment: string | null;
  public_status: PublicStatus | null;
  civil_service_number?: string | null;
  contract_number?: string | null;
  professional_diploma?: string | null;
  service_start_on?: string | null;
}
export interface StaffProfileInput {
  public_status: PublicStatus;
  civil_service_number?: string | null;
  contract_number?: string | null;
  professional_diploma?: string | null;
  service_start_on?: string | null;
}
export interface Training {
  id: string;
  title: string;
  starts_on: string;
  ends_on: string | null;
  organiser: string | null;
}
export interface TrainingInput {
  title: string;
  starts_on: string;
  ends_on?: string | null;
  organiser?: string | null;
}
export interface StaffMove {
  id: string;
  staff_id: string;
  from_establishment_id: string;
  to_establishment_id: string;
  effective_on: string;
  reason: string | null;
  ended_assignments?: unknown;
}
export interface StaffMoveInput {
  to_establishment_id: string;
  effective_on: string;
  reason?: string | null;
}

/** Photo géolocalisée d'une visite d'inspection ; `url` est un lien signé de durée limitée (lecture authentifiée). */
export interface SchoolPhoto {
  id: string;
  establishment_id: string;
  latitude: string;
  longitude: string;
  taken_at: string;
  visit_id: string | null;
  taken_by: string;
  url: string;
}
export interface SchoolPhotoInput {
  content_base64: string;
  latitude: number;
  longitude: number;
  taken_at: string;
  visit_id?: string | null;
}

export interface StaffNeeds {
  date: string;
  teachers: number;
  pupils: number;
  classes: number;
  pupils_per_teacher: number | null;
  pupils_per_teacher_target: number | null;
  teachers_missing: number | null;
  classes_without_homeroom: Array<{ structure_id: string; label: string }>;
}

export type Audience = 'class' | 'level' | 'school';
export interface MessageBatch {
  id: string;
  audience: Audience;
  target: string | null;
  template_key: string;
  variables: Record<string, string>;
  classes_count: number;
  recipients_count: number;
  sent_by: string;
  created_at: string;
  replayed?: boolean;
}
export interface MessageInput {
  audience: Audience;
  target?: string | null;
  template_key: string;
  variables?: Record<string, string>;
  client_uuid?: string;
}

// -------------------------------------------------------------------------------------------------------------- appels

export const publicSchools = {
  // Fiche et infrastructure
  school: (): Promise<SchoolFiche> => get('/schools/current'),
  saveSchool: (body: SchoolFicheInput): Promise<SchoolFiche> => put('/schools/current', body),
  infrastructure: (yearLabel?: string): Promise<InfrastructureVersion[]> => get('/schools/current/infrastructure', yearLabel ? { year_label: yearLabel } : {}),
  infrastructureVersion: (id: string): Promise<InfrastructureVersion> => get(`/schools/current/infrastructure/${encodeURIComponent(id)}`),
  openInfrastructure: (body: InfrastructureInput): Promise<InfrastructureVersion> => post('/schools/current/infrastructure', body),
  updateInfrastructure: (id: string, body: Partial<InfrastructureInput>): Promise<InfrastructureVersion> =>
    patch(`/schools/current/infrastructure/${encodeURIComponent(id)}`, body),
  closeInfrastructure: (id: string): Promise<InfrastructureVersion> => post(`/schools/current/infrastructure/${encodeURIComponent(id)}/close`),
  correctInfrastructure: (id: string, reason: string, data: Partial<InfrastructureInput>): Promise<InfrastructureVersion> =>
    post(`/schools/current/infrastructure/${encodeURIComponent(id)}/corrections`, { reason, data }),

  // Classes
  classes: (academicYearId: string, establishmentId?: string | null): Promise<ClassRow[]> => get('/classes', { academic_year_id: academicYearId }, establishmentId),
  createClass: (body: ClassInput): Promise<Record<string, unknown>> => post('/classes', body),
  updateClass: (id: string, body: { name?: string; capacity?: number; level_codes?: string[]; shift?: string }): Promise<ClassRow> =>
    patch(`/classes/${encodeURIComponent(id)}`, body),
  headcount: (id: string, date?: string): Promise<ClassHeadcount> => get(`/classes/${encodeURIComponent(id)}/headcount`, date ? { date } : {}),
  assignHomeroom: (id: string, staffId: string | null, startsOn: string): Promise<{ homeroom_staff_id: string | null; homeroom_from: string }> =>
    put(`/classes/${encodeURIComponent(id)}/homeroom`, { staff_id: staffId, starts_on: startsOn }),
  homeroomHistory: (id: string): Promise<HomeroomEntry[]> => get(`/classes/${encodeURIComponent(id)}/homeroom-history`),
  distributionProposal: (classIds: string[]): Promise<DistributionProposal> => get('/classes/distribution-proposal', { class_ids: classIds.join(',') }),
  /** Liste nominative d'une classe (appel hors ligne), toutes les pages : le serveur en rend 50 à la fois. */
  roster: (id: string, establishmentId?: string | null): Promise<RosterPupil[]> => all(`/classes/${encodeURIComponent(id)}/pupils`, {}, establishmentId),

  // Élèves
  /** Recherche par mots dans le nom, le prénom et le numéro d'apprenant ; 422 sous la longueur minimale fixée par le serveur. */
  searchPupils: (search: string, cursor?: string | null): Promise<Paged<RosterPupil>> => page('/pupils', { search }, cursor),
  registerPupil: (body: PupilRegistration): Promise<PupilRegistered> => post('/pupils', body),
  pupil: (id: string): Promise<PupilFile> => get(`/pupils/${encodeURIComponent(id)}`),
  updatePupil: (id: string, body: PupilProfileInput): Promise<PupilFile> => patch(`/pupils/${encodeURIComponent(id)}`, body),
  withoutBirthAct: (): Promise<WithoutBirthActRow[]> => get('/pupils/without-birth-act'),
  birthActTransmissions: (): Promise<BirthActTransmission[]> => get('/pupils/without-birth-act/transmissions'),
  transmitBirthActList: (quarter?: string): Promise<BirthActTransmission> => post('/pupils/without-birth-act/transmissions', quarter ? { quarter } : {}),

  // Transferts et abandons
  /** Écoles ouvertes du territoire pouvant accueillir un élève ou un enseignant (hors maternelle et hors l'école elle-même). */
  receivingSchools: (search: string, territoryId?: string | null, cursor?: string | null): Promise<Paged<ReceivingSchool>> =>
    page('/schools/receiving', { search, territory_id: territoryId ?? undefined }, cursor),
  transfers: (direction?: 'incoming' | 'outgoing'): Promise<Transfer[]> => get('/transfers', direction ? { direction } : {}),
  requestTransfer: (personId: string, toEstablishmentId: string): Promise<Transfer> => post('/transfers', { person_id: personId, to_establishment_id: toEstablishmentId }),
  acceptTransfer: (id: string, arrivalOn: string, classId: string | null): Promise<Transfer> =>
    post(`/transfers/${encodeURIComponent(id)}/accept`, { arrival_on: arrivalOn, class_id: classId }),
  completeTransfer: (id: string, classId: string): Promise<Transfer> => post(`/transfers/${encodeURIComponent(id)}/complete`, { class_id: classId }),
  refuseTransfer: (id: string, reason: string): Promise<Transfer> => post(`/transfers/${encodeURIComponent(id)}/refuse`, { reason }),
  issueExeat: (id: string): Promise<{ transfer_id: string; exeat_document_id: string }> => post(`/transfers/${encodeURIComponent(id)}/exeat`),
  transferDossier: (id: string): Promise<Record<string, unknown>> => get(`/transfers/${encodeURIComponent(id)}/dossier`),
  dropouts: (state: 'open' | 'all' = 'open'): Promise<Dropout[]> => get('/dropouts', { state }),
  declareDropout: (personId: string, note?: string): Promise<Dropout> => post('/dropouts', { person_id: personId, ...(note ? { note } : {}) }),
  contactDropout: (id: string): Promise<Dropout> => post(`/dropouts/${encodeURIComponent(id)}/contact`),
  resolveDropout: (id: string, outcome: string): Promise<Dropout> => post(`/dropouts/${encodeURIComponent(id)}/resolve`, { outcome }),

  // Appel et présence des enseignants
  attendanceDay: (classId: string, date: string, establishmentId?: string | null): Promise<AttendanceDay> =>
    get(`/classes/${encodeURIComponent(classId)}/attendance/${date}`, undefined, establishmentId),
  recordAttendance: (classId: string, date: string, body: CallBody, establishmentId?: string | null): Promise<Record<string, unknown>> =>
    put(`/classes/${encodeURIComponent(classId)}/attendance/${date}`, body, establishmentId),
  attendanceRegister: (classId: string, month: string): Promise<AttendanceRegister> =>
    get(`/classes/${encodeURIComponent(classId)}/attendance-register`, { month }),
  alerts: (kind?: string): Promise<AttendanceAlert[]> => all('/attendance-alerts', kind ? { kind } : {}),
  teacherPresence: (date: string): Promise<TeacherPresence[]> => all('/teacher-presence', { date }),
  controlSample: (date: string): Promise<TeacherPresence[]> => get('/teacher-presence/control-sample', { date, purpose: 'control' }),
  checkPresence: (personId: string, date: string, result: CheckResult): Promise<TeacherPresence> =>
    put(`/staff/${encodeURIComponent(personId)}/presence/${date}/check`, { result, purpose: 'control' }),
  declarePresence: (personId: string, date: string, body: PresenceInput): Promise<TeacherPresence> =>
    put(`/staff/${encodeURIComponent(personId)}/presence/${date}`, body),
  uncoveredClasses: (date: string): Promise<UncoveredClass[]> => all('/uncovered-classes', { date }),
  presenceSummary: (month: string): Promise<Record<string, unknown>> => get('/teacher-presence/summary', { month }),

  // Personnel
  staff: (): Promise<StaffMember[]> => all('/staff'),
  staffNeeds: (): Promise<StaffNeeds> => get('/staff/needs'),
  staffProfile: (id: string): Promise<StaffProfile> => get(`/staff/${encodeURIComponent(id)}/profile`),
  saveStaffProfile: (id: string, body: StaffProfileInput): Promise<StaffProfile> => put(`/staff/${encodeURIComponent(id)}/profile`, body),
  trainings: (id: string): Promise<Training[]> => all(`/staff/${encodeURIComponent(id)}/trainings`),
  addTraining: (id: string, body: TrainingInput): Promise<Training> => post(`/staff/${encodeURIComponent(id)}/trainings`, body),
  staffMoves: (id: string): Promise<StaffMove[]> => get(`/staff/${encodeURIComponent(id)}/moves`),
  moveStaff: (id: string, body: StaffMoveInput): Promise<StaffMove> => post(`/staff/${encodeURIComponent(id)}/moves`, body),

  // Photos géolocalisées de l'école (inspection)
  photos: (): Promise<SchoolPhoto[]> => get('/schools/photos'),
  addPhoto: (body: SchoolPhotoInput): Promise<SchoolPhoto> => post('/schools/photos', body),
  /** Contenu d'une photo par son lien signé ; lecture authentifiée, donc pas d'élément <img> direct sur l'adresse. */
  photoContent: (signedUrl: string): Promise<Blob> => {
    const url = new URL(signedUrl, window.location.origin);
    return http.get<Blob>(url.pathname.replace(/^\/api\/v1/, ''), Object.fromEntries(url.searchParams.entries()), { ...options(), expect: 'blob' });
  },

  // Messages aux parents
  sendMessage: (body: MessageInput): Promise<MessageBatch> => post('/messages', body),
  messages: (): Promise<MessageBatch[]> => all('/messages'),
};

// ------------------------------------------------------------------------------------------------------ appareil (Sync)

/** Appareil de l'établissement et code personnel (T608) : routes du socle Sync, hors préfixe /public. */
export const syncDevices = {
  register: async (label: string): Promise<{ id: string; label: string }> => (await http.post<{ data: { id: string; label: string } }>('/sync/devices', { label })).data,
  setPin: async (deviceId: string, code: string): Promise<{ device_id: string; pin_set: boolean }> =>
    (await http.put<{ data: { device_id: string; pin_set: boolean } }>(`/sync/devices/${encodeURIComponent(deviceId)}/pin`, { code })).data,
  unlock: async (deviceId: string, code: string): Promise<{ device_id: string; unlocked: boolean; wipe: boolean }> =>
    (await http.post<{ data: { device_id: string; unlocked: boolean; wipe: boolean } }>(`/sync/devices/${encodeURIComponent(deviceId)}/unlock`, { code })).data,
};

// ------------------------------------------------------------------------------------------------ lot P2 (évaluation, APEE, statistiques)
// Primaire publique : préfixe /api/v1/public-primary (contrat openapi.d/public-primary.yaml). Mêmes règles d'en-tête et de finalité.

const PP = '/public-primary';
const ppGet = async <T>(path: string, query?: Record<string, QueryValue>): Promise<T> =>
  (await http.get<{ data: T }>(`${PP}${path}`, withPurpose(query), options())).data;
const ppPost = async <T>(path: string, body?: unknown): Promise<T> => (await http.post<{ data: T }>(`${PP}${path}`, body ?? {}, options())).data;
const ppPut = async <T>(path: string, body?: unknown, est?: string | null): Promise<T> =>
  (await http.put<{ data: T }>(`${PP}${path}`, body ?? {}, options(est))).data;
const ppPatch = async <T>(path: string, body?: unknown): Promise<T> => (await http.patch<{ data: T }>(`${PP}${path}`, body ?? {}, options())).data;
async function ppAll<T>(path: string, query: Record<string, QueryValue> = {}): Promise<T[]> {
  const rows: T[] = [];
  let cursor: string | null = null;
  for (let i = 0; i < 20; i++) {
    const res: { data: T[]; meta?: { next_cursor?: string | null } } = await http.get(`${PP}${path}`, withPurpose({ ...query, ...(cursor ? { cursor } : {}) }), options());
    rows.push(...(res.data ?? []));
    cursor = res.meta?.next_cursor ?? null;
    if (!cursor) break;
  }
  return rows;
}
const enc = encodeURIComponent;

export type Outcome = 'success' | 'fail' | 'absent';
export interface ScaleLevel {
  code: string;
  label_fr: string;
  label_en: string;
}
export interface Criterion {
  id: string;
  code: string;
  label_fr: string;
  label_en: string;
  kind: 'minimal' | 'perfection';
}
export interface Frame {
  id: string;
  subsystem: string;
  level_code: string;
  discipline_code: string;
  label_fr: string;
  label_en: string;
  version: number;
  valid_from: string;
  is_key_discipline: boolean;
  criteria: Criterion[];
}
export interface LearningUnit {
  id: string;
  year_label: string;
  level_code: string;
  unit_no: number;
  period_id: string;
  starts_on: string;
  ends_on: string;
  evaluation_week_from: string;
  is_final_integration: boolean;
}
export interface UnitMention {
  person_id: string;
  frame_id: string;
  discipline_code: string | null;
  mention: string;
  perfection_mastered: boolean;
  to_remediate: string[];
}
export interface Cell {
  person_id: string;
  criterion_id: string;
  occasion_no: number;
  outcome: Outcome;
}
export interface GridFrame {
  id: string;
  discipline_code: string;
  label_fr: string;
  label_en: string;
  is_key_discipline: boolean;
  criteria: Criterion[];
}
export interface Grid {
  unit: { id: string; unit_no: number; level_code: string; year_label: string; starts_on: string; ends_on: string; evaluation_week_from: string };
  class: { structure_id: string; label: string };
  occasions: number;
  mastery_min_success: number;
  scale: ScaleLevel[];
  frames: GridFrame[];
  pupils: { person_id: string; name: string }[];
  cells: Cell[];
  mentions: UnitMention[];
  frozen: string[];
}
export interface ChecksBody {
  structure_id: string;
  client_uuid: string;
  checks: Cell[];
}
export interface ChecksResult {
  applied: number;
  replayed: boolean;
  mentions: UnitMention[];
}
export interface RemediationGroup {
  frame_id: string;
  discipline_code: string;
  discipline_fr: string;
  discipline_en: string;
  criterion_id: string;
  criterion_code: string;
  criterion_fr: string;
  criterion_en: string;
  pupils: { remediation_id: string; person_id: string; name: string; state: string }[];
}
export interface RemediationSession {
  id: string;
  structure_id: string;
  frame_id: string;
  unit_id: string | null;
  held_on: string;
  duration_minutes: number;
  form: string;
  criterion_ids: string[];
  person_ids: string[];
  notes: string | null;
}
export interface SessionInput {
  structure_id: string;
  frame_id: string;
  unit_id?: string;
  held_on: string;
  duration_minutes: number;
  form: string;
  criterion_ids: string[];
  person_ids: string[];
  notes?: string;
}
export interface WeeklySummary {
  week_from: string;
  week_to: string;
  minimum_minutes: number;
  disciplines: { frame_id: string; discipline_fr: string; discipline_en: string; minutes: number; minimum: number; met: boolean }[];
}
export interface SheetItem {
  id: string;
  state: string;
  form: string | null;
  outcome_note: string | null;
  discipline_fr: string | null;
  discipline_en: string | null;
  unit_no: number | null;
  criterion_code: string | null;
  criterion_fr: string | null;
  criterion_en: string | null;
}
export interface RemediationSheet {
  person_id: string;
  name: string;
  minutes_attended: number;
  open: number;
  remediations: SheetItem[];
  sessions: RemediationSession[];
}
export interface ReportCardLine {
  frame_id: string;
  discipline_code: string;
  label_fr: string;
  label_en: string;
  mention: string;
  perfection?: boolean;
  units?: { unit_no: number; mention: string }[];
}
export interface ReportCard {
  id: string;
  person_id: string;
  name?: string;
  structure_id: string;
  kind: 'unit' | 'trimester' | 'year';
  scope_ref: string;
  year_label: string;
  state: 'draft' | 'published' | 'rectified';
  appraisal: string | null;
  document_id: string | null;
  published_at: string | null;
  rectified_reason: string | null;
  content: { period_label_fr?: string; period_label_en?: string; disciplines?: ReportCardLine[]; remediation?: string[] };
}
export interface PromotionRow {
  id: string;
  person_id: string;
  name?: string;
  structure_id: string;
  year_label: string;
  level_code: string;
  proposal: string;
  key_na_count: number;
  decision: string | null;
  state: 'proposed' | 'final' | 'pending_opinion';
  reason: string | null;
  policy_version: string;
  inspector_opinion: string | null;
  appeal_until: string | null;
  appeal_state: string | null;
  council_id: string | null;
}
export interface PromotionPolicyView {
  version: string;
  na_for_remediation: number;
  na_for_council: number;
  lock_level: string;
}
export interface SettingRow {
  key: string;
  value: unknown;
  default: unknown;
  status: 'official' | 'cdc_default' | 'to_confirm';
  source: string;
}
export interface IssuedRef {
  id: string;
  number: string;
}
export interface CouncilInput {
  structure_id: string;
  held_on: string;
  attendees: string[];
  decisions: { person_id: string; decision: string; reason?: string }[];
}

export const publicPrimary = {
  settings: (): Promise<SettingRow[]> => ppGet('/settings'),
  frames: (levelCode?: string): Promise<Frame[]> => ppAll('/frames', levelCode ? { level_code: levelCode } : {}),
  importExample: (subsystem: string, levelCode: string): Promise<Frame[]> => ppPost('/frames/import-example', { subsystem, level_code: levelCode }),
  units: (yearLabel: string, levelCode: string): Promise<LearningUnit[]> => ppGet('/units', { year_label: yearLabel, level_code: levelCode }),
  generateUnits: (body: { year_label: string; level_code: string; trimesters: { period_id: string; starts_on: string; ends_on: string }[] }): Promise<LearningUnit[]> =>
    ppPost('/units/generate', body),
  grid: (unitId: string, structureId: string): Promise<Grid> => ppGet(`/units/${enc(unitId)}/grid`, { structure_id: structureId }),
  saveChecks: (unitId: string, body: ChecksBody, est?: string | null): Promise<ChecksResult> => ppPut(`/units/${enc(unitId)}/criterion-checks`, body, est),
  remediation: (unitId: string, structureId: string): Promise<RemediationGroup[]> => ppGet(`/units/${enc(unitId)}/remediation`, { structure_id: structureId }),
  sessions: (structureId: string): Promise<RemediationSession[]> => ppAll('/remediation-sessions', { structure_id: structureId }),
  recordSession: (body: SessionInput): Promise<RemediationSession> => ppPost('/remediation-sessions', body),
  weeklySummary: (structureId: string, date?: string): Promise<WeeklySummary> => ppGet('/remediation-sessions/summary', { structure_id: structureId, ...(date ? { date } : {}) }),
  updateRemediation: (id: string, body: { state?: string; form?: string | null; outcome_note?: string }): Promise<unknown> => ppPatch(`/remediations/${enc(id)}`, body),
  sheet: (structureId: string, personId: string): Promise<RemediationSheet> => ppGet(`/classes/${enc(structureId)}/pupils/${enc(personId)}/remediation-sheet`),
  generateCards: (body: { kind: string; scope_ref: string; structure_id: string; year_label?: string }): Promise<ReportCard[]> => ppPost('/report-cards/generate', body),
  cards: (structureId: string, kind?: string, scopeRef?: string): Promise<ReportCard[]> =>
    ppAll('/report-cards', { structure_id: structureId, ...(kind ? { kind } : {}), ...(scopeRef ? { scope_ref: scopeRef } : {}) }),
  appraise: (id: string, appraisal: string): Promise<ReportCard> => ppPatch(`/report-cards/${enc(id)}`, { appraisal }),
  publish: (id: string): Promise<ReportCard & { document?: IssuedRef }> => ppPost(`/report-cards/${enc(id)}/publish`, {}),
  rectify: (id: string, body: { frame_id: string; mention: string; reason: string }): Promise<ReportCard> => ppPost(`/report-cards/${enc(id)}/rectify`, body),
  phrases: (locale: string): Promise<{ id: string; locale: string; phrase: string }[]> => ppAll('/appraisal-phrases', { locale }),
  addPhrase: (locale: string, phrase: string): Promise<{ id: string }> => ppPost('/appraisal-phrases', { locale, phrase }),
  policy: (): Promise<PromotionPolicyView> => ppGet('/promotion/policy'),
  propose: (year: string, structureId: string): Promise<PromotionRow[]> => ppPost(`/promotion/${enc(year)}/proposals`, { structure_id: structureId }),
  promotion: (year: string, structureId: string): Promise<PromotionRow[]> => ppAll(`/promotion/${enc(year)}`, { structure_id: structureId }),
  council: (year: string, body: CouncilInput): Promise<{ council_id: string; decisions: PromotionRow[] }> => ppPost(`/promotion/${enc(year)}/council`, body),
  opinion: (id: string, opinion: 'favourable' | 'unfavourable', comment: string): Promise<PromotionRow> => ppPost(`/promotion/decisions/${enc(id)}/opinion`, { opinion, comment }),
  appeal: (id: string, body: { action: 'lodge' | 'uphold' | 'revise'; reason: string; decision?: string }): Promise<PromotionRow> =>
    ppPost(`/promotion/decisions/${enc(id)}/appeal`, body),
  minutes: (councilId: string): Promise<{ document: IssuedRef }> => ppPost(`/promotion/councils/${enc(councilId)}/minutes`, {}),
};

// APEE (comptes de l'association, séparés de la scolarité), paquet minimum, questionnaire statistique, inscription groupée, PDF P1.

export interface ApeeYear {
  id: string;
  year_label: string;
  amount_per_pupil: number;
  voted_on: string;
  minutes_ref: string | null;
  expected_pupils: number;
}
export interface ApeeEntry {
  id: string;
  apee_year_id: string;
  direction: 'income' | 'expense';
  category: string;
  amount: number;
  method: string | null;
  operator_ref: string | null;
  family_ref: string | null;
  label: string | null;
  proof_ref: string | null;
  state: 'recorded' | 'pending';
  receipt_document_id: string | null;
  receipt_number: string | null;
  entered_on: string;
}
export interface ApeeEntryInput {
  apee_year_id: string;
  direction: 'income' | 'expense';
  category: string;
  amount: number;
  method?: string;
  operator_ref?: string;
  family_ref?: string;
  label?: string;
  proof_ref?: string;
  client_uuid: string;
}
export interface ApeeStatement {
  year_label: string;
  amount_per_pupil: number;
  expected_pupils: number;
  expected: number;
  collected: number;
  collection_rate: number | null;
  contributing_families: number;
  exempted_families: number;
  non_contributing_families: number;
  income: Record<string, number>;
  income_total: number;
  expenses: Record<string, number>;
  expenses_total: number;
  pending_expenses: Record<string, number>;
  pending_total: number;
  balance: number;
}
export interface SupplyLine {
  item_label: string;
  quantity: number;
  structure_id?: string | null;
}
export interface SupplyReceipt {
  id: string;
  source: 'minimum_package' | 'donation';
  slip_ref: string | null;
  received_on: string;
  deliverer_name: string | null;
  document_id: string | null;
  lines?: SupplyLine[];
}
export interface CensusSection {
  title_fr: string;
  title_en: string;
  values: Record<string, number | null>;
}
export interface CensusForm {
  id: string;
  year_label: string;
  campaign: 'start_of_year' | 'end_of_year';
  version: number;
  state: 'draft' | 'returned' | 'in_review' | 'frozen' | 'consolidated';
  current_step: string | null;
  entered_by_proxy: boolean;
  correction_reason: string | null;
  return_reason: string | null;
  payload: { sections?: Record<string, CensusSection> };
  checks: { anomalies?: string[]; justifications?: Record<string, string> } | null;
}
export interface BatchResult {
  row: number;
  status: 'created' | 'replayed' | 'duplicate' | 'rejected';
  person_id?: string;
  learner_number?: string | null;
  message?: string;
  errors?: Record<string, string[]>;
}
export interface RegistrationBatch {
  id: string;
  client_uuid: string;
  register_photo_ref: string | null;
  register_label: string | null;
  entered_by_proxy: boolean;
  rows_count: number;
  created_count: number;
  results: BatchResult[];
  replayed?: boolean;
}

export const publicSchoolsP2 = {
  apeeYears: (): Promise<ApeeYear[]> => all('/apee/years'),
  voteApee: (body: { year_label: string; amount_per_pupil: number; voted_on: string; minutes_ref?: string; expected_pupils?: number }): Promise<ApeeYear> =>
    post('/apee/years', body),
  apeeEntries: (yearId: string): Promise<ApeeEntry[]> => all(`/apee/years/${enc(yearId)}/entries`),
  recordApee: (body: ApeeEntryInput): Promise<ApeeEntry & { replayed: boolean }> => post('/apee/entries', body),
  attachProof: (entryId: string, proofRef: string): Promise<ApeeEntry> => post(`/apee/entries/${enc(entryId)}/proof`, { proof_ref: proofRef }),
  apeeStatement: (yearId: string): Promise<ApeeStatement> => get(`/apee/years/${enc(yearId)}/statement`),
  issueStatement: (yearId: string): Promise<{ document: IssuedRef }> => post(`/apee/years/${enc(yearId)}/statement/document`, {}),
  nonContributors: (yearId: string): Promise<{ person_id: string; name: string }[]> => all(`/apee/years/${enc(yearId)}/non-contributors`),
  exempt: (yearId: string, familyRef: string, reasonCode: string): Promise<unknown> => post(`/apee/years/${enc(yearId)}/exemptions`, { family_ref: familyRef, reason_code: reasonCode }),
  families: (search: string): Promise<{ person_id: string; name: string }[]> => get('/apee/families', { search }),
  supplies: (): Promise<SupplyReceipt[]> => all('/supplies'),
  receiveSupply: (body: { source: string; slip_ref?: string; received_on: string; deliverer_name?: string; lines: SupplyLine[] }): Promise<SupplyReceipt> => post('/supplies', body),
  supplySlip: (id: string): Promise<{ document: IssuedRef }> => post(`/supplies/${enc(id)}/slip`, {}),
  census: (): Promise<CensusForm[]> => all('/census'),
  censusPending: (): Promise<CensusForm[]> => get('/census/pending'),
  openCensus: (yearLabel: string, campaign: string): Promise<CensusForm> => post('/census', { year_label: yearLabel, campaign }),
  editCensus: (id: string, values: Record<string, number | null>): Promise<CensusForm> => patch(`/census/${enc(id)}`, { values }),
  submitCensus: (id: string, justifications: Record<string, string> = {}): Promise<CensusForm> => post(`/census/${enc(id)}/submit`, { justifications }),
  returnCensus: (id: string, reason: string): Promise<CensusForm> => post(`/census/${enc(id)}/return`, { reason }),
  correctCensus: (id: string, reason: string): Promise<CensusForm> => post(`/census/${enc(id)}/corrections`, { reason }),
  censusDocument: (id: string): Promise<{ document: IssuedRef }> => post(`/census/${enc(id)}/document`, {}),
  censusExport: (id: string): Promise<Blob> => http.get<Blob>(`${ROOT}/census/${enc(id)}/export`, { format: 'csv' }, { ...options(), expect: 'blob' }),
  registerBatch: (body: { client_uuid: string; register_photo_ref?: string; register_label?: string; class_id?: string; rows: Record<string, unknown>[] }): Promise<RegistrationBatch> =>
    post('/pupils/batches', body),
  registrationBatches: (): Promise<RegistrationBatch[]> => all('/pupils/batches'),
  registerDocument: (classId: string, month: string, locale: string): Promise<IssuedRef> => post(`/classes/${enc(classId)}/attendance-register/document`, { month, locale }),
  presenceDocument: (month: string, locale: string): Promise<IssuedRef> => post('/teacher-presence/summary/document', { month, locale }),
};

/** PDF d'un document émis (module Documents du socle, profil léger pour les parents et les réseaux lents). */
export function documentPdf(documentId: string, light = true): Promise<Blob> {
  return http.get<Blob>(`/documents/${enc(documentId)}/preview`, light ? { profile: 'light' } : {}, { ...options(), expect: 'blob' });
}

/** Ouvre un PDF émis dans un nouvel onglet (lecture authentifiée : pas de lien direct). */
export async function openDocument(documentId: string): Promise<void> {
  const url = URL.createObjectURL(await documentPdf(documentId));
  window.open(url, '_blank', 'noopener');
  setTimeout(() => URL.revokeObjectURL(url), 60_000);
}

// ------------------------------------------------------------------------------------------------ lot P3 (examens, inspection, cantine, tableau de bord)
// Contrats : openapi.d/public-primary-exams.yaml, public-schools-inspection.yaml, public-schools-canteen.yaml, public-schools-dashboards.yaml.

export type ExamState = 'proposed' | 'validated' | 'blocked' | 'withdrawn';
export type ExamOutcome = 'passed' | 'failed' | 'absent';
export interface ExamSession {
  id: string;
  exam_code: string;
  year_label: string;
  label: string;
  subsystem: string;
  level_codes: string[];
  kind: 'certificate' | 'competition';
  written_on: string | null;
  state: string;
}
export interface ExamList {
  id: string;
  establishment_id: string;
  session_id: string;
  state: 'draft' | 'submitted' | 'inspected' | 'validated' | 'returned';
  current_step: 'inspection' | 'delegation' | null;
  return_reason: string | null;
  frozen_at: string | null;
  amendments_count: number;
}
export interface ExamSessionDetail extends ExamSession {
  list: ExamList | null;
  counts: Partial<Record<ExamState, number>>;
}
export interface ExamCandidate {
  id: string;
  session_id: string;
  establishment_id: string;
  person_id: string;
  learner_number: string | null;
  last_name: string;
  first_name: string;
  sex: string | null;
  birth_date: string | null;
  birth_act_ref: string | null;
  birth_act_verified: boolean;
  structure_id: string | null;
  level_code: string | null;
  state: ExamState;
  blocked_reason: string | null;
  added_after_freeze: boolean;
  added_reason: string | null;
  withdrawn_reason: string | null;
  candidate_number: string | null;
  centre_id: string | null;
  sub_centre_id: string | null;
  outcome: ExamOutcome | null;
  result_published: boolean;
  attestation_state: 'prepared' | 'signed' | null;
  attestation_document_id: string | null;
}
export interface ExamSummary {
  candidates: number;
  present: number;
  passed: number;
  failed: number;
  absent: number;
  success_rate: number | null;
  pending: number;
}
export interface ExamValidation {
  list: ExamList | null;
  step: string;
  validated: number;
  blocked: string[];
}
export interface TerritoryList {
  id: string;
  establishment_id: string;
  official_code: string;
  list: ExamList | null;
  counts: Partial<Record<ExamState, number>>;
}
export interface ExamCentre {
  id: string;
  session_id: string;
  territory_id: string;
  parent_centre_id: string | null;
  code: string;
  label: string;
  host_establishment_id: string | null;
  capacity: number | null;
  kind: 'centre' | 'sub_centre';
}
export interface ExamOfficial {
  id: string;
  centre_id: string;
  full_name: string;
  staff_ref: string | null;
  function_code: string;
  half_days: number;
  copies_marked: number;
}
export interface VacationStatementView {
  territory_id: string;
  rates: Record<string, unknown>;
  rates_status: string;
  source: string;
  lines: (ExamOfficial & { half_day_rate: number; copy_rate: number; amount: number })[];
  directors: { establishment_id: string; candidates: number; amount: number }[];
  total_officials: number;
  total_directors: number;
  total: number;
}
export interface ImportReport {
  imported: number;
  rejected: { line: number; key: string; reason: string }[];
  summary: ExamSummary;
}
export interface TransferDossier {
  id: string;
  candidate_id: string;
  target_label: string | null;
  created_at: string;
  dossier: Record<string, unknown>;
  dossier_json: string;
  signature: string;
  algorithm: string;
}

export const publicExams = {
  sessions: (yearLabel?: string): Promise<ExamSession[]> => ppAll('/exams', yearLabel ? { year_label: yearLabel } : {}),
  openSession: (body: { exam_code: string; year_label: string; written_on?: string }): Promise<ExamSession> => ppPost('/exams', body),
  session: (id: string): Promise<ExamSessionDetail> => ppGet(`/exams/${enc(id)}`),
  candidates: (id: string): Promise<ExamCandidate[]> => ppAll(`/exams/${enc(id)}/candidates`),
  generate: (id: string, academicYearId: string): Promise<ExamCandidate[]> => ppPost(`/exams/${enc(id)}/candidates/generate`, { academic_year_id: academicYearId }),
  add: (id: string, body: { person_id: string; structure_id?: string; reason?: string }): Promise<ExamCandidate> => ppPost(`/exams/${enc(id)}/candidates`, body),
  submit: (id: string): Promise<ExamList> => ppPost(`/exams/${enc(id)}/submit`, {}),
  validate: (id: string): Promise<ExamValidation> => ppPost(`/exams/${enc(id)}/candidates/validate`, {}),
  sendBack: (id: string, reason: string): Promise<ExamList> => ppPost(`/exams/${enc(id)}/return`, { reason }),
  listDocument: (id: string, locale: string): Promise<{ document: IssuedRef }> => ppPost(`/exams/${enc(id)}/document`, { locale }),
  birthActAlerts: (id: string): Promise<{ alerted: number; messages: number }> => ppPost(`/exams/${enc(id)}/birth-act-alerts`, {}),
  results: (id: string): Promise<{ session: ExamSession; summary: ExamSummary }> => ppGet(`/exams/${enc(id)}/results`),
  resultsDocument: (id: string, locale: string): Promise<{ document: IssuedRef }> => ppPost(`/exams/${enc(id)}/results/document`, { locale }),
  importResults: (id: string, territoryId: string, csv: string): Promise<ImportReport> => ppPost(`/exams/${enc(id)}/results/import`, { territory_id: territoryId, csv }),
  publish: (id: string, territoryId: string): Promise<{ published: number; messages: number }> => ppPost(`/exams/${enc(id)}/results/publish`, { territory_id: territoryId }),
  attestations: (id: string, locale: string): Promise<{ prepared: { candidate_id: string; document: IssuedRef }[] }> => ppPost(`/exams/${enc(id)}/attestations`, { locale }),
  territoryLists: (id: string, territoryId: string): Promise<TerritoryList[]> => ppAll(`/exams/${enc(id)}/territories/${enc(territoryId)}/lists`),
  territoryResults: (id: string, territoryId: string): Promise<{ schools: { establishment_id: string; official_code: string; summary: ExamSummary }[]; summary: ExamSummary }> =>
    ppGet(`/exams/${enc(id)}/territories/${enc(territoryId)}/results`),
  vacations: (id: string, territoryId: string): Promise<VacationStatementView> => ppGet(`/exams/${enc(id)}/territories/${enc(territoryId)}/vacations`),
  centres: (id: string): Promise<ExamCentre[]> => ppAll(`/exams/${enc(id)}/centres`),
  createCentre: (id: string, body: { territory_id: string; code: string; label: string; parent_centre_id?: string; host_establishment_id?: string }): Promise<ExamCentre> =>
    ppPost(`/exams/${enc(id)}/centres`, body),
  assign: (id: string, centreId: string, establishmentIds: string[]): Promise<{ assigned: number; skipped: number }> =>
    ppPost(`/exams/${enc(id)}/centres/${enc(centreId)}/assign`, { establishment_ids: establishmentIds }),
  officials: (id: string): Promise<ExamOfficial[]> => ppAll(`/exams/${enc(id)}/officials`),
  addOfficial: (id: string, body: { centre_id: string; full_name: string; function_code: string; half_days: number; copies_marked: number; staff_ref?: string }): Promise<ExamOfficial> =>
    ppPost(`/exams/${enc(id)}/officials`, body),
  withdraw: (candidateId: string, reason: string): Promise<ExamCandidate> => ppPost(`/exam-candidates/${enc(candidateId)}/withdraw`, { reason }),
  validateCandidate: (candidateId: string): Promise<ExamCandidate> => ppPost(`/exam-candidates/${enc(candidateId)}/validate`, {}),
  sheet: (candidateId: string, locale: string): Promise<{ document: IssuedRef }> => ppPost(`/exam-candidates/${enc(candidateId)}/sheet`, { locale }),
  sign: (candidateId: string): Promise<ExamCandidate> => ppPost(`/exam-candidates/${enc(candidateId)}/attestation/sign`, {}),
  transfer: (candidateId: string, targetLabel?: string): Promise<TransferDossier> =>
    ppPost(`/exam-candidates/${enc(candidateId)}/secondary-transfer`, targetLabel ? { target_label: targetLabel } : {}),
  exportList: (id: string): Promise<Blob> => http.get<Blob>(`/public-primary/exams/${enc(id)}/export`, { format: 'csv' }, { ...options(), expect: 'blob' }),
};

export interface GridItem {
  code: string;
  section: string;
  label_fr: string;
  label_en: string;
}
export interface VisitGrid {
  id: string;
  code: string;
  version: number;
  label_fr: string;
  label_en: string;
  items: GridItem[];
  active: boolean;
}
export interface ScaleCode {
  code: string;
  label_fr: string;
  label_en: string;
}
export interface Recommendation {
  id: string;
  visit_id: string;
  label: string;
  state: 'open' | 'in_progress' | 'done' | 'not_done';
  checked_visit_id: string | null;
  note: string | null;
}
export interface Visit {
  id: string;
  establishment_id: string;
  inspector_id: string;
  inspector_name: string;
  planned_on: string;
  state: 'planned' | 'done';
  done_on: string | null;
  structure_id: string | null;
  class_name: string;
  teacher_person_id: string | null;
  teacher_name: string;
  grid_id: string | null;
  answers: Record<string, string>;
  counted_present: number | null;
  registry_count: number | null;
  headcount_gap: number | null;
  headcount_gap_rate: number | null;
  observations: string | null;
  report_document_id: string | null;
  recommendations: Recommendation[];
  replayed?: boolean;
}
export interface VisitReport {
  client_uuid: string;
  done_on: string;
  grid_id?: string;
  answers: Record<string, string>;
  counted_present?: number;
  observations?: string;
  recommendations: string[];
  followups: { id: string; state: 'in_progress' | 'done' | 'not_done'; note?: string }[];
}
export interface FeeReport {
  id: string;
  reported_on: string;
  reporter_kind: string;
  fee_label: string;
  amount: number | null;
  description: string;
  state: 'open' | 'investigating' | 'confirmed' | 'dismissed';
  resolution: string | null;
  handled_at: string | null;
}

export const publicInspection = {
  grids: (): Promise<VisitGrid[]> => all('/inspection/grids'),
  importExample: (): Promise<VisitGrid> => post('/inspection/grids/import-example', {}),
  scale: (): Promise<{ scale: ScaleCode[]; status: string }> => get('/inspection/scale'),
  planning: (): Promise<Visit[]> => all('/inspection/planning'),
  visits: (est?: string | null): Promise<Visit[]> => all('/inspection/visits', {}, est),
  plan: (body: { planned_on: string; structure_id?: string; teacher_person_id?: string; grid_id?: string }): Promise<Visit> => post('/inspection/visits', body),
  visit: (id: string): Promise<Visit> => get(`/inspection/visits/${enc(id)}`),
  report: (id: string, body: VisitReport, est?: string | null): Promise<Visit> => put(`/inspection/visits/${enc(id)}/report`, body, est),
  document: (id: string, locale: string): Promise<{ document: IssuedRef }> => post(`/inspection/visits/${enc(id)}/document`, { locale }),
  recommendations: (state?: string): Promise<Recommendation[]> => all('/inspection/recommendations', state ? { state } : {}),
  fees: (): Promise<FeeReport[]> => all('/inspection/fee-reports'),
  reportFee: (body: { reported_on: string; reporter_kind: string; fee_label: string; amount?: number; description: string }): Promise<FeeReport> =>
    post('/inspection/fee-reports', body),
  handleFee: (id: string, state: string, resolution: string): Promise<FeeReport> => post(`/inspection/fee-reports/${enc(id)}/handle`, { state, resolution }),
};

export interface Meal {
  id: string;
  structure_id: string;
  class_name: string;
  day_on: string;
  served_count: number;
}
export interface StockMove {
  id: string;
  item_label: string;
  unit_label: string;
  direction: 'in' | 'out';
  quantity: number;
  moved_on: string;
  source_label: string | null;
  note: string | null;
}
export interface StockBalance {
  item_label: string;
  unit_label: string;
  received: number;
  consumed: number;
  balance: number;
}
export interface HealthCampaign {
  id: string;
  campaign_kind: string;
  held_on: string;
  label: string | null;
  girls_count: number;
  boys_count: number;
  treated_count: number;
  consents_count: number;
  refusals_count: number;
}
export interface MealSummary {
  month: string;
  service_days: number;
  served_total: number;
  classes: { structure_id: string; class_name: string; days: number; served: number }[];
}

export const publicCanteen = {
  meals: (date: string): Promise<Meal[]> => all('/canteen/meals', { date }),
  saveMeals: (date: string, lines: { structure_id: string; served_count: number }[]): Promise<{ meals: Meal[]; warnings: string[] }> =>
    put(`/canteen/meals/${enc(date)}`, { lines }),
  summary: (month: string): Promise<MealSummary> => get('/canteen/summary', { month }),
  moves: (): Promise<StockMove[]> => all('/canteen/stock'),
  addMove: (body: { item_label: string; unit_label: string; direction: 'in' | 'out'; quantity: number; moved_on: string; source_label?: string }): Promise<StockMove> =>
    post('/canteen/stock', body),
  balance: async (): Promise<StockBalance[]> => (await get<{ items: StockBalance[] }>('/canteen/stock/balance')).items,
  campaigns: (): Promise<HealthCampaign[]> => all('/health-campaigns'),
  addCampaign: (body: { campaign_kind: string; held_on: string; label?: string; girls_count: number; boys_count: number; consents_count: number; refusals_count: number }): Promise<HealthCampaign> =>
    post('/health-campaigns', body),
};

export interface TerritoryNodeView {
  id: string;
  type: string;
  label_fr: string;
  label_en: string;
  code?: string;
}
export interface DerivedIndicator {
  code: string;
  label_fr: string;
  label_en: string;
  formula: string;
  value: number | null;
  masked: boolean;
}
export interface TerritoryDashboard {
  territory: TerritoryNodeView & { parent_id: string | null };
  reference_date: string | null;
  minimum_cell_size: number;
  derived: DerivedIndicator[];
  indicators: { key: string; establishment_count: number; computed_at: string; cells: { key: string; value: number | null; suppressed: boolean }[] }[];
}

export const publicDashboards = {
  entry: async (): Promise<TerritoryNodeView[]> => (await get<{ territories: TerritoryNodeView[] }>('/dashboards/entry')).territories,
  show: (territoryId: string, date?: string): Promise<TerritoryDashboard> => get(`/dashboards/${enc(territoryId)}`, date ? { date } : {}),
  children: async (territoryId: string): Promise<TerritoryNodeView[]> => (await get<{ territories: TerritoryNodeView[] }>(`/dashboards/${enc(territoryId)}/children`)).territories,
  document: (territoryId: string, locale: string): Promise<{ document: IssuedRef }> => post(`/dashboards/${enc(territoryId)}/document`, { locale }),
};

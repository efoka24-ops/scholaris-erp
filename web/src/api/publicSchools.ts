// API des écoles publiques (préfixe /api/v1/public) : fiche et infrastructure, classes, élèves, transferts, abandons, appel,
// présence des enseignants, personnel et messages. Contrats : specs/001-socle-plateforme/contracts/openapi.d/public-schools-*.yaml.
//
// Le client HTTP n'envoie jamais X-Establishment-Id sous /public (préfixe réservé aux routes sans compte) alors que ces routes-ci
// l'exigent : il est donc posé ici, explicitement, à chaque appel (établissement actif ou celui de l'élément rejoué).
import { http, type QueryValue, type RequestOptions } from '@/api/http';
import { useContextStore } from '@/stores/context';

const ROOT = '/public';

function options(establishmentId?: string | null): RequestOptions {
  const id = establishmentId ?? useContextStore().establishmentId;
  return id ? { headers: { 'X-Establishment-Id': id } } : {};
}

const get = async <T>(path: string, query?: Record<string, QueryValue>, est?: string | null): Promise<T> =>
  (await http.get<{ data: T }>(`${ROOT}${path}`, query, options(est))).data;
const post = async <T>(path: string, body?: unknown, est?: string | null): Promise<T> =>
  (await http.post<{ data: T }>(`${ROOT}${path}`, body ?? {}, options(est))).data;
const put = async <T>(path: string, body?: unknown, est?: string | null): Promise<T> =>
  (await http.put<{ data: T }>(`${ROOT}${path}`, body ?? {}, options(est))).data;
const patch = async <T>(path: string, body?: unknown, est?: string | null): Promise<T> =>
  (await http.patch<{ data: T }>(`${ROOT}${path}`, body ?? {}, options(est))).data;

/** Liste paginée par curseur (50 par page) : toutes les pages, jusqu'à 20. */
async function all<T>(path: string, query: Record<string, QueryValue> = {}): Promise<T[]> {
  const rows: T[] = [];
  let cursor: string | null = null;
  for (let i = 0; i < 20; i++) {
    const res: { data: T[]; meta?: { next_cursor?: string | null } } = await http.get(`${ROOT}${path}`, { ...query, ...(cursor ? { cursor } : {}) }, options());
    rows.push(...res.data);
    cursor = res.meta?.next_cursor ?? null;
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
  digital_equipment: unknown[];
  correction_reason: string | null;
  classrooms?: Classroom[];
}
export type InfrastructureInput = Partial<Omit<InfrastructureVersion, 'id' | 'version' | 'state' | 'correction_reason' | 'digital_equipment'>> & {
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
  /** Liste nominative d'une classe (appel hors ligne). Absente du contrat actuel : la réponse 404 est gérée par l'écran. */
  roster: (id: string, establishmentId?: string | null): Promise<RosterPupil[]> => get(`/classes/${encodeURIComponent(id)}/pupils`, undefined, establishmentId),

  // Élèves
  registerPupil: (body: PupilRegistration): Promise<PupilRegistered> => post('/pupils', body),
  pupil: (id: string): Promise<PupilFile> => get(`/pupils/${encodeURIComponent(id)}`),
  updatePupil: (id: string, body: PupilProfileInput): Promise<PupilFile> => patch(`/pupils/${encodeURIComponent(id)}`, body),
  withoutBirthAct: (): Promise<WithoutBirthActRow[]> => get('/pupils/without-birth-act'),
  birthActTransmissions: (): Promise<BirthActTransmission[]> => get('/pupils/without-birth-act/transmissions'),
  transmitBirthActList: (quarter?: string): Promise<BirthActTransmission> => post('/pupils/without-birth-act/transmissions', quarter ? { quarter } : {}),

  // Transferts et abandons
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
  alerts: (kind?: string): Promise<AttendanceAlert[]> => all('/attendance-alerts', kind ? { kind } : {}),
  teacherPresence: (date: string): Promise<TeacherPresence[]> => all('/teacher-presence', { date }),
  declarePresence: (personId: string, date: string, body: PresenceInput): Promise<TeacherPresence> =>
    put(`/staff/${encodeURIComponent(personId)}/presence/${date}`, body),
  uncoveredClasses: (date: string): Promise<UncoveredClass[]> => all('/uncovered-classes', { date }),
  presenceSummary: (month: string): Promise<Record<string, unknown>> => get('/teacher-presence/summary', { month }),

  // Personnel
  staff: (): Promise<StaffMember[]> => all('/staff'),
  staffNeeds: (): Promise<StaffNeeds> => get('/staff/needs'),

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

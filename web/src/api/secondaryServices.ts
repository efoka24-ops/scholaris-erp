// API du lot E4-B du secondaire : services annexes (cantine, transport, internat, infirmerie), situation financière par classe et
// fichier des conseils de fin d'année (DOC-33), vie scolaire des portails. Contrats : openapi.d/secondary-services.yaml,
// secondary-fees.yaml, secondary-portals.yaml. Le serveur reste juge des droits et des modules (module coupé : lecture seule).
import { http, type QueryValue } from '@/api/http';

const ROOT = '/secondary';

export type ServiceGroup = 'canteen-transport' | 'boarding';
export type ServiceKind = 'canteen' | 'transport' | 'boarding';
export const INFIRMARY_REASONS = ['illness', 'injury', 'checkup', 'medication', 'other'] as const;
export const INFIRMARY_OUTCOMES = ['back_to_class', 'rest', 'sent_home', 'referred'] as const;
export type InfirmaryReason = (typeof INFIRMARY_REASONS)[number];
export type InfirmaryOutcome = (typeof INFIRMARY_OUTCOMES)[number];

export interface ServiceOffer {
  id: string;
  service_kind: ServiceKind;
  label: string;
  stops: string[];
  capacity: number | null;
  subscribers: number;
  fee_code: string | null;
  amount_xaf: number;
  active: boolean;
}
export interface OfferInput {
  service_kind: ServiceKind;
  label: string;
  stops?: string[];
  capacity?: number | null;
  fee_code?: string | null;
  amount_xaf: number;
}
export interface ServiceSubscription {
  id: string;
  service_kind: ServiceKind;
  offer_id: string;
  offer_label: string | null;
  person_id: string;
  student_name: string | null;
  class_id: string | null;
  stop_label: string | null;
  starts_on: string;
  ends_on: string | null;
  status: 'active' | 'ended';
  invoice_id: string | null;
  end_reason: string | null;
}
export interface SubscriptionInput {
  offer_id: string;
  person_id: string;
  starts_on: string;
  ends_on?: string | null;
  stop_label?: string | null;
  invoice?: boolean;
}
export interface InfirmaryVisit {
  id: string;
  person_id: string;
  student_name: string | null;
  class_id: string | null;
  visited_at: string;
  reason_code: InfirmaryReason;
  outcome: InfirmaryOutcome;
  parent_called: boolean;
  pe_exempt_from: string | null;
  pe_exempt_until: string | null;
  notes?: string | null;
}
export interface VisitInput {
  person_id: string;
  reason_code: InfirmaryReason;
  outcome: InfirmaryOutcome;
  parent_called: boolean;
  notes?: string | null;
  pe_exempt_until?: string | null;
}
export interface HealthRecord {
  person_id: string;
  exists: boolean;
  allergies: string | null;
  conditions: string | null;
  treatments: string | null;
  emergency_instructions: string | null;
  updated_at: string | null;
}
export type HealthRecordInput = Pick<HealthRecord, 'allergies' | 'conditions' | 'treatments' | 'emergency_instructions'>;
export interface AccessLogEntry {
  id: string;
  person_id: string | null;
  user_id: string;
  /** Nom de l'auteur de la consultation (compte lié à une personne), null sinon. */
  user_name?: string | null;
  student_name?: string | null;
  access_kind: string;
  created_at: string;
}
export interface Exemption {
  person_id: string;
  class_id: string | null;
  exempt_until: string;
}

export interface SituationTotals {
  learners: number;
  debtors: number;
  invoiced_xaf: number;
  paid_xaf: number;
  balance_xaf: number;
  overdue_xaf: number;
  recovery_rate: number | null;
}
export interface FinancialSituation {
  academic_year_id: string;
  as_of: string;
  classes: (SituationTotals & { class_id: string; label: string; level_code: string })[];
  levels: (SituationTotals & { level_code: string })[];
  establishment: SituationTotals;
  forecast: { month: string; expected_xaf: number }[];
}

export interface SchoolLife {
  person_id: string;
  access: 'full' | 'restricted';
  results?: { period_id: string; period_label: string; average: number | null; rank: number | null; class_size: number | null; mention: string | null; decision: string | null }[];
  report_cards?: { blocked: boolean; items: { id: string; number: string; type: string; verification_token: string }[] };
  attendance?: { from: string; to: string; sessions: number; absences: number; unjustified_absences: number; lates: number };
  homework?: { session_date: string; subject_label: string; homework: string }[];
  services?: { id: string; service_kind: ServiceKind; offer_label: string | null; stop_label: string | null; starts_on: string; ends_on: string | null }[];
}
export interface TeacherPortal {
  teacher_person_id: string;
  academic_year_id: string | null;
  assignments: {
    assignment_id: string;
    class_id: string;
    class_name: string | null;
    group_code: string | null;
    subject_label: string;
    coverage: { planned_hours: number | null; done_hours: number; rate: number | null; last_log_on: string | null } | null;
  }[];
  homework_given: { session_date: string; class_id: string; subject_label: string; homework: string }[];
}

type One<T> = { data: T };

const group = (g: ServiceGroup): string => `${ROOT}/services/${g}`;

export const secondaryServices = {
  offers: (g: ServiceGroup, query: Record<string, QueryValue> = {}): Promise<ServiceOffer[]> => http.all<ServiceOffer>(`${group(g)}/offers`, query),
  createOffer: async (g: ServiceGroup, input: OfferInput): Promise<ServiceOffer> => (await http.post<One<ServiceOffer>>(`${group(g)}/offers`, input)).data,
  updateOffer: async (g: ServiceGroup, id: string, input: Partial<OfferInput> & { active?: boolean }): Promise<ServiceOffer> =>
    (await http.patch<One<ServiceOffer>>(`${group(g)}/offers/${encodeURIComponent(id)}`, input)).data,
  subscriptions: (g: ServiceGroup, query: Record<string, QueryValue> = {}): Promise<ServiceSubscription[]> =>
    http.all<ServiceSubscription>(`${group(g)}/subscriptions`, query),
  subscribe: async (g: ServiceGroup, input: SubscriptionInput): Promise<ServiceSubscription> =>
    (await http.post<One<ServiceSubscription>>(`${group(g)}/subscriptions`, input)).data,
  endSubscription: async (g: ServiceGroup, id: string, endsOn: string, reason: string): Promise<ServiceSubscription> =>
    (await http.post<One<ServiceSubscription>>(`${group(g)}/subscriptions/${encodeURIComponent(id)}/end`, { ends_on: endsOn, reason })).data,

  visits: (query: Record<string, QueryValue> = {}): Promise<InfirmaryVisit[]> => http.all<InfirmaryVisit>(`${ROOT}/services/infirmary/visits`, query),
  visit: async (id: string): Promise<InfirmaryVisit> => (await http.get<One<InfirmaryVisit>>(`${ROOT}/services/infirmary/visits/${encodeURIComponent(id)}`)).data,
  recordVisit: async (input: VisitInput): Promise<InfirmaryVisit> => (await http.post<One<InfirmaryVisit>>(`${ROOT}/services/infirmary/visits`, input)).data,
  record: async (personId: string): Promise<HealthRecord> => (await http.get<One<HealthRecord>>(`${ROOT}/services/infirmary/records/${encodeURIComponent(personId)}`)).data,
  saveRecord: async (personId: string, input: HealthRecordInput): Promise<HealthRecord> =>
    (await http.put<One<HealthRecord>>(`${ROOT}/services/infirmary/records/${encodeURIComponent(personId)}`, input)).data,
  accessLog: (query: Record<string, QueryValue> = {}): Promise<AccessLogEntry[]> => http.all<AccessLogEntry>(`${ROOT}/services/infirmary/access-log`, query),
  exemptions: async (date?: string): Promise<{ date: string; exemptions: Exemption[] }> =>
    (await http.get<One<{ date: string; exemptions: Exemption[] }>>(`${ROOT}/services/infirmary/pe-exemptions`, date ? { date } : {})).data,

  situation: async (academicYearId?: string): Promise<FinancialSituation> =>
    (await http.get<One<FinancialSituation>>(`${ROOT}/fees/situation`, academicYearId ? { academic_year_id: academicYearId } : {})).data,
  yearEndExport: (academicYearId: string): Promise<Blob> =>
    http.get<Blob>(`${ROOT}/carte-scolaire/year-end-export`, { academic_year_id: academicYearId }, { expect: 'blob', headers: { Accept: 'text/csv' } }),

  studentLife: async (): Promise<SchoolLife> => (await http.get<One<SchoolLife>>(`${ROOT}/portal/student/school-life`)).data,
  childLife: async (childId: string): Promise<SchoolLife> =>
    (await http.get<One<SchoolLife>>(`${ROOT}/portal/family/children/${encodeURIComponent(childId)}/school-life`)).data,
  teacher: async (): Promise<TeacherPortal> => (await http.get<One<TeacherPortal>>(`${ROOT}/portal/teacher`)).data,
};

/** Arrêts saisis un par ligne : lignes vides retirées, doublons supprimés, 40 au plus. */
export function parseStops(text: string): string[] {
  return [...new Set(text.split('\n').map((s) => s.trim()).filter((s) => s !== ''))].slice(0, 40);
}

/** Groupe de routes d'une nature de service (module activable). */
export function groupOf(kind: ServiceKind): ServiceGroup {
  return kind === 'boarding' ? 'boarding' : 'canteen-transport';
}

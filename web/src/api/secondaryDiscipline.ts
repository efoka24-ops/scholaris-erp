// API de la discipline du secondaire (préfixe /api/v1/secondary/discipline). Contrat : openapi.d/secondary-discipline.yaml.
// L'appel par heure de cours, les retards et les justificatifs restent au socle (écran Appel, /attendance-sessions) ; ces routes
// lisent leur cumul en heures et portent les propositions du barème, leurs décisions, les conseils de discipline et les exclusions.
import { http } from '@/api/http';

const ROOT = '/secondary/discipline';

export type ProposalStatus = 'proposed' | 'applied' | 'rejected' | 'superseded';

export interface ScaleTier {
  code: string;
  label?: string;
  level?: string;
  from_hours?: number;
  above_hours?: number;
}
export interface DisciplineScale {
  configured: boolean;
  scale: { tiers: ScaleTier[]; repeat_offence_code?: string };
  version: string;
  errors: string[];
  rule_key: string;
  hours_per_session: number;
  example: { tiers: ScaleTier[]; repeat_offence_code?: string };
  example_status: string;
}
export interface Period {
  from: string;
  to: string;
  label: string;
}
export interface StudentHours {
  person_id: string;
  first_name: string;
  last_name: string;
  class_id: string;
  sessions: number;
  absent_hours: number;
  unjustified_hours: number;
  justified_hours: number;
  lates: number;
  proposals: Array<{ id: string; proposed_label: string; status: ProposalStatus }>;
}
export interface ClassHours {
  class_id: string;
  class_name: string;
  period: Period;
  hours_per_session: number;
  students: StudentHours[];
}
export interface ConductProposal {
  id: string;
  person_id: string;
  class_id: string | null;
  period_from: string;
  period_to: string;
  period_label: string;
  unjustified_hours: number;
  proposed_kind: string;
  proposed_label: string;
  decision_level: string;
  scale_version: string;
  repeat_offence: boolean;
  status: ProposalStatus;
  decided_by: string | null;
  decided_at: string | null;
  decision_reason: string | null;
  council_id: string | null;
  created_at: string;
}
export interface ComputeResult {
  period: Period;
  scale_version: string;
  examined: number;
  created: ConductProposal[];
  unchanged: number;
}
export interface DisciplineCouncil {
  id: string;
  person_id: string;
  class_id: string;
  proposal_id: string | null;
  facts: string;
  convened_on: string;
  hearing_on: string;
  hearing_time: string;
  place: string;
  members: string[];
  status: 'convened' | 'decided';
  decision_kind: string | null;
  decision_reason: string | null;
  minutes: string | null;
  student_heard: boolean;
  parents_heard: boolean;
  exclusion_days: number | null;
  decided_by: string | null;
  decided_at: string | null;
}
export interface ConveneInput {
  person_id: string;
  class_id: string;
  proposal_id?: string | null;
  facts: string;
  hearing_on: string;
  hearing_time: string;
  place: string;
  members?: string[];
}
export interface CouncilDecisionInput {
  decision_kind: string;
  decision_reason: string;
  minutes?: string;
  student_heard?: boolean;
  parents_heard?: boolean;
  exclusion_days?: number | null;
}
export interface ClassExclusion {
  id: string;
  person_id: string;
  class_id: string;
  session_date: string;
  hour_label: string;
  session_id: string | null;
  reason: string;
  reported_by: string;
  client_uuid: string;
  created_at: string;
  replayed?: boolean;
}
export interface ExclusionInput {
  client_uuid: string;
  person_id: string;
  class_id: string;
  session_date: string;
  hour_label: string;
  session_id?: string | null;
  reason: string;
}

/** Décision du conseil qui exige procès-verbal et auditions (RG-PRE-3). */
export const FINAL_EXCLUSION = 'final_exclusion';
export const NO_SANCTION = 'no_sanction';

type One<T> = { data: T };
type Many<T> = { data: T[]; meta?: { next_cursor: string | null } };

const id = (value: string): string => encodeURIComponent(value);

export interface ClassChoice {
  id: string;
  structure_id: string;
  label: string;
  academic_year_id: string;
}

export const secondaryDiscipline = {
  /** Classes de l'établissement (paramétrage) ; `structure_id` est l'identifiant de classe des routes de discipline. */
  classes: async (): Promise<ClassChoice[]> => (await http.get<Many<ClassChoice>>('/secondary/setup/classes', { per_page: 200 })).data,
  scale: async (): Promise<DisciplineScale> => (await http.get<One<DisciplineScale>>(`${ROOT}/scale`)).data,
  hours: async (classId: string, from?: string, to?: string): Promise<ClassHours> =>
    (await http.get<One<ClassHours>>(`${ROOT}/hours`, { class_id: classId, ...(from ? { from } : {}), ...(to ? { to } : {}) })).data,
  compute: async (body: { class_id?: string | null; from?: string; to?: string; period_label?: string }): Promise<ComputeResult> =>
    (await http.post<One<ComputeResult>>(`${ROOT}/proposals/compute`, body)).data,
  proposals: async (status?: ProposalStatus): Promise<ConductProposal[]> =>
    (await http.get<Many<ConductProposal>>(`${ROOT}/proposals`, { per_page: 200, ...(status ? { 'filter[status]': status } : {}) })).data,
  decide: async (proposalId: string, decision: 'apply' | 'reject', reason?: string): Promise<ConductProposal> =>
    (await http.post<One<ConductProposal>>(`${ROOT}/proposals/${id(proposalId)}/decide`, { decision, ...(reason ? { reason } : {}) })).data,
  councils: async (): Promise<DisciplineCouncil[]> => (await http.get<Many<DisciplineCouncil>>(`${ROOT}/councils`, { per_page: 200 })).data,
  convene: async (body: ConveneInput): Promise<DisciplineCouncil> => (await http.post<One<DisciplineCouncil>>(`${ROOT}/councils`, body)).data,
  decideCouncil: async (councilId: string, body: CouncilDecisionInput): Promise<DisciplineCouncil> =>
    (await http.post<One<DisciplineCouncil>>(`${ROOT}/councils/${id(councilId)}/decide`, body)).data,
  exclusions: async (classId?: string): Promise<ClassExclusion[]> =>
    (await http.get<Many<ClassExclusion>>(`${ROOT}/class-exclusions`, { per_page: 200, ...(classId ? { 'filter[class_id]': classId } : {}) })).data,
  reportExclusion: async (body: ExclusionInput): Promise<ClassExclusion> =>
    (await http.post<One<ClassExclusion>>(`${ROOT}/class-exclusions`, body)).data,
};

/** Contrôle local de RG-PRE-3 avant envoi (le serveur reste juge) : champs manquants d'une exclusion définitive. */
export function finalExclusionMissing(input: CouncilDecisionInput): Array<'minutes' | 'student_heard' | 'parents_heard'> {
  if (input.decision_kind !== FINAL_EXCLUSION) return [];
  const missing: Array<'minutes' | 'student_heard' | 'parents_heard'> = [];
  if ((input.minutes ?? '').trim() === '') missing.push('minutes');
  if (input.student_heard !== true) missing.push('student_heard');
  if (input.parents_heard !== true) missing.push('parents_heard');
  return missing;
}

/** Heures affichées sans zéros inutiles (20, 20,5). */
export function formatHours(hours: number, locale = 'fr-FR'): string {
  return new Intl.NumberFormat(locale, { maximumFractionDigits: 2 }).format(hours);
}

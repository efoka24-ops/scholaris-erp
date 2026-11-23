// Stages et soutenances de l'application Supérieur (STG, lot I3) : /api/v1/higher/internship*, /higher/defenses*.
// Contrat : specs/001-socle-plateforme/contracts/openapi.d/higher-internships.yaml. Convention : brouillon → en signature (DOC-20)
// → signée (3 parties) → terminée (attestation). Soutenance : programmée → convoquée (DOC-21, NOT-13) → notée → clôturée (PV DOC-22).
import { http } from './http';
import { H, type Q } from './higher';

export type ConventionStatus = 'draft' | 'pending_signatures' | 'signed' | 'completed' | 'cancelled';
export type DefenseStatus = 'scheduled' | 'convened' | 'graded' | 'closed' | 'cancelled';
export type Party = 'student' | 'company' | 'school';
export type FollowupKind = 'visit' | 'report' | 'evaluation';
export type JuryRole = 'president' | 'supervisor' | 'member';
export type Condition = 'convention_signed' | 'completion_certificate' | 'thesis_deposited' | 'financial_clearance';

export const CONVENTION_STATUSES: ConventionStatus[] = ['draft', 'pending_signatures', 'signed', 'completed', 'cancelled'];
export const DEFENSE_STATUSES: DefenseStatus[] = ['scheduled', 'convened', 'graded', 'closed', 'cancelled'];
export const PARTIES: Party[] = ['student', 'company', 'school'];
export const FOLLOWUP_KINDS: FollowupKind[] = ['visit', 'report', 'evaluation'];
export const JURY_ROLES: JuryRole[] = ['president', 'supervisor', 'member'];
export const CONDITIONS: Condition[] = ['convention_signed', 'completion_certificate', 'thesis_deposited', 'financial_clearance'];

const seg = (v: string): string => encodeURIComponent(v);
const unwrap = async <T>(p: Promise<{ data: T }>): Promise<T> => (await p).data;

export interface Company {
  id: string; name: string; sector: string | null; city: string | null; address: string | null; phone: string | null;
  email: string | null; contact_name: string | null; active: boolean;
}
export interface CompanyInput { name: string; sector?: string; city?: string; address?: string; phone?: string; email?: string; contact_name?: string }

export interface Internship {
  id: string; enrolment_id: string; person_id: string; academic_year_id: string; school_id: string; department_id: string | null;
  program_id: string; level_id: string; group_id: string | null; company_id: string; subject: string; starts_on: string; ends_on: string;
  company_tutor_name: string; company_tutor_email: string | null; company_tutor_phone: string | null; supervisor_person_id: string;
  supervisor_name: string; status: ConventionStatus; convention_document_id: string | null; signatures: Record<Party, string | null>;
  subject_validated_at: string | null; completion_received_on: string | null; thesis_title: string | null; thesis_ref: string | null;
  thesis_deposited_at: string | null; cancel_reason: string | null; readiness?: Record<Condition, boolean>;
}
export interface InternshipInput {
  enrolment_id: string; company_id: string; subject: string; starts_on: string; ends_on: string; company_tutor_name: string;
  company_tutor_email?: string; company_tutor_phone?: string; supervisor_person_id: string;
}
export interface Readiness { conditions: Record<Condition, boolean>; missing: Condition[]; ready: boolean }

export interface Followup { id: string; internship_id: string; kind: FollowupKind; occurred_on: string; summary: string; score: number | null; document_id: string | null }
export interface FollowupInput { kind: FollowupKind; occurred_on: string; summary: string; score?: number }

export interface JuryMember { person_id: string; jury_role: JuryRole; display_name?: string }
export interface Defense {
  id: string; internship_id: string; person_id: string; school_id: string; program_id: string; level_id: string; held_on: string;
  start_time: string; end_time: string; room: string; status: DefenseStatus; grade: number | null; remarks: string | null;
  convocation_document_id: string | null; minutes_document_id: string | null; jury: Required<JuryMember>[];
}
export interface DefenseSlot { held_on: string; start_time: string; end_time: string; room: string }
export interface DefenseInput extends DefenseSlot { internship_id: string; jury: JuryMember[] }

export interface MyInternship {
  internship: Internship;
  followups: Array<{ kind: FollowupKind; occurred_on: string; summary: string | null }>;
  readiness: { conditions: Record<Condition, boolean>; missing: Condition[] };
  defense: Defense | null;
}

export const internships = {
  companies: (q: Q = {}): Promise<Company[]> => unwrap(http.get(`${H}/internship-companies`, q)),
  createCompany: (body: CompanyInput): Promise<Company> => unwrap(http.post(`${H}/internship-companies`, body)),
  updateCompany: (id: string, body: Partial<CompanyInput> & { active?: boolean }): Promise<Company> => unwrap(http.patch(`${H}/internship-companies/${seg(id)}`, body)),

  list: (q: Q = {}): Promise<Internship[]> => unwrap(http.get(`${H}/internships`, q)),
  get: (id: string): Promise<Internship> => unwrap(http.get(`${H}/internships/${seg(id)}`)),
  create: (body: InternshipInput): Promise<Internship> => unwrap(http.post(`${H}/internships`, body)),
  update: (id: string, body: Partial<InternshipInput>): Promise<Internship> => unwrap(http.patch(`${H}/internships/${seg(id)}`, body)),
  issueConvention: (id: string): Promise<Internship> => unwrap(http.post(`${H}/internships/${seg(id)}/convention`)),
  sign: (id: string, party: Party, signedOn: string): Promise<Internship> => unwrap(http.post(`${H}/internships/${seg(id)}/signatures`, { party, signed_on: signedOn })),
  validateSubject: (id: string): Promise<Internship> => unwrap(http.post(`${H}/internships/${seg(id)}/subject/validate`)),
  complete: (id: string, receivedOn: string, reference?: string): Promise<Internship> =>
    unwrap(http.post(`${H}/internships/${seg(id)}/completion`, reference ? { received_on: receivedOn, reference } : { received_on: receivedOn })),
  depositThesis: (id: string, title: string, fileRef: string): Promise<Internship> => unwrap(http.post(`${H}/internships/${seg(id)}/thesis`, { title, file_ref: fileRef })),
  cancel: (id: string, reason: string): Promise<Internship> => unwrap(http.post(`${H}/internships/${seg(id)}/cancel`, { reason })),
  readiness: (id: string): Promise<Readiness> => unwrap(http.get(`${H}/internships/${seg(id)}/readiness`)),
  followups: (id: string): Promise<Followup[]> => unwrap(http.get(`${H}/internships/${seg(id)}/followups`)),
  addFollowup: (id: string, body: FollowupInput): Promise<Followup> => unwrap(http.post(`${H}/internships/${seg(id)}/followups`, body)),

  defenses: (q: Q = {}): Promise<Defense[]> => unwrap(http.get(`${H}/defenses`, q)),
  defense: (id: string): Promise<Defense> => unwrap(http.get(`${H}/defenses/${seg(id)}`)),
  schedule: (body: DefenseInput): Promise<Defense> => unwrap(http.post(`${H}/defenses`, body)),
  reschedule: (id: string, body: DefenseSlot & { jury?: JuryMember[] }): Promise<Defense> => unwrap(http.patch(`${H}/defenses/${seg(id)}`, body)),
  convene: (id: string): Promise<Defense> => unwrap(http.post(`${H}/defenses/${seg(id)}/convene`)),
  grade: (id: string, grade: number, remarks?: string): Promise<Defense> => unwrap(http.post(`${H}/defenses/${seg(id)}/grade`, remarks ? { grade, remarks } : { grade })),
  minutes: (id: string): Promise<Defense> => unwrap(http.post(`${H}/defenses/${seg(id)}/minutes`)),
  cancelDefense: (id: string, reason: string): Promise<Defense> => unwrap(http.post(`${H}/defenses/${seg(id)}/cancel`, { reason })),

  mine: (): Promise<MyInternship> => unwrap(http.get(`${H}/students/me/internship`)),
  depositMyThesis: (title: string, fileRef: string): Promise<Internship> => unwrap(http.post(`${H}/students/me/internship/thesis`, { title, file_ref: fileRef })),
};

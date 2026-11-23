// Appels du paiement des vacations de l'application Supérieur (IPES, EX-ENS-06/07, RG-ENS-1 à 5, NOT-14, DOC-25, DOC-26).
// Contrat : specs/001-socle-plateforme/contracts/openapi.d/higher-vacations.yaml. Montants entiers en XAF ; HETD en millièmes.
import { http } from './http';
import { H, type Q } from './higher';

const seg = (v: string): string => encodeURIComponent(v);

export type VacationStatus = 'draft' | 'school_validated' | 'approved' | 'paid' | 'rejected';
export type PaymentMethod = 'bank_transfer' | 'mobile_money';
export type VacationKind = 'cm' | 'td' | 'tp' | 'other';

export const VACATION_STATUSES: VacationStatus[] = ['draft', 'school_validated', 'approved', 'paid', 'rejected'];
export const PAYMENT_METHODS: PaymentMethod[] = ['bank_transfer', 'mobile_money'];
export const VACATION_KINDS: VacationKind[] = ['cm', 'td', 'tp', 'other'];
export const MONTH = /^\d{4}-(0[1-9]|1[0-2])$/;

export interface Deduction { code: string; label: string; percent: number; amount: number }

export interface StatementLine {
  id: string; session_log_id: string; element_code: string; element_title: string; group_id: string; level_label: string | null;
  session_kind: VacationKind; held_on: string; minutes: number; hetd_milli: number; hourly_rate: number | null; payable: boolean; refusal: string | null;
}

export interface Statement {
  id: string; school_id: string; department_id: string; person_id: string; period_month: string; grade: string | null; status: VacationStatus;
  cm_minutes: number; td_minutes: number; tp_minutes: number; other_minutes: number;
  cm_hetd_milli: number; td_hetd_milli: number; tp_hetd_milli: number; other_hetd_milli: number; hetd_milli: number; hetd: number;
  hourly_rate: number | null; gross_amount: number; deductions: Deduction[]; deductions_amount: number; net_amount: number;
  excluded_minutes: number; pending_sessions: number; hold_reasons: string[]; rejection_reason: string | null;
  payment_method: PaymentMethod | null; payment_reference: string | null; paid_on: string | null;
  statement_document_id: string | null; payslip_document_id: string | null; lines?: StatementLine[];
}

export interface ToPrepare { person_id: string; school_id: string; department_id: string; period_month: string; statement_id: string | null; statement_status: VacationStatus | null }
export interface IssuedDocument { id: string; code?: string; number?: string }
export interface PayInput { payment_method: PaymentMethod; payment_reference: string; paid_on?: string }

/** HETD en millièmes affichées à la française (43 500 → « 43,5 »). */
export function formatHetd(milli: number, locale = 'fr'): string {
  return (milli / 1000).toLocaleString(locale === 'en' ? 'en-GB' : 'fr-FR', { maximumFractionDigits: 2 });
}

/** Minutes en heures (« 18 h », « 1 h 30 »). */
export function formatHours(minutes: number): string {
  const h = Math.floor(minutes / 60);
  const m = minutes % 60;
  return m === 0 ? `${h} h` : `${h} h ${String(m).padStart(2, '0')}`;
}

export const vacations = {
  statements: (q: Q = {}): Promise<Statement[]> => http.all<Statement>(`${H}/vacations/statements`, q),
  mine: (q: Q = {}): Promise<Statement[]> => http.all<Statement>(`${H}/vacations/mine`, q),
  statement: (id: string): Promise<Statement> => http.get(`${H}/vacations/statements/${seg(id)}`),
  toPrepare: async (month: string, q: Q = {}): Promise<ToPrepare[]> => (await http.get<{ data: ToPrepare[] }>(`${H}/vacations/to-prepare`, { ...q, period_month: month })).data,
  prepare: async (b: { person_id: string; period_month: string; department_id?: string }): Promise<Statement[]> =>
    (await http.post<{ data: Statement[] }>(`${H}/vacations/statements`, b)).data,
  validateSchool: (id: string): Promise<Statement> => http.post(`${H}/vacations/statements/${seg(id)}/validate-school`),
  approve: (id: string): Promise<Statement> => http.post(`${H}/vacations/statements/${seg(id)}/approve`),
  reject: (id: string, reason: string): Promise<Statement> => http.post(`${H}/vacations/statements/${seg(id)}/reject`, { reason }),
  pay: (id: string, b: PayInput): Promise<Statement> => http.post(`${H}/vacations/statements/${seg(id)}/pay`, b),
  statementDocument: (id: string): Promise<IssuedDocument> => http.post(`${H}/vacations/statements/${seg(id)}/statement-document`),
  payslip: (id: string): Promise<IssuedDocument> => http.post(`${H}/vacations/statements/${seg(id)}/payslip`),
};

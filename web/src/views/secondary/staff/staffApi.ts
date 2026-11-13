// Client du personnel du secondaire (lot E3-E) : statuts et heures du mois (noyau), paie simplifiée (module payroll_simple).
import { http } from '@/api/http';

const ROOT = '/secondary/staff';

export type EmploymentStatus = 'civil_servant' | 'state_contract' | 'private_employee' | 'hourly';
export const EMPLOYMENT_STATUSES: readonly EmploymentStatus[] = ['civil_servant', 'state_contract', 'private_employee', 'hourly'];
export type StaffCategory = 'teaching' | 'administrative' | 'service' | 'maintenance';
export const STAFF_CATEGORIES: readonly StaffCategory[] = ['teaching', 'administrative', 'service', 'maintenance'];
/** Statuts proposés pour la paie : jamais le fonctionnaire. */
export const PAYABLE_STATUSES: readonly EmploymentStatus[] = ['private_employee', 'hourly', 'state_contract'];

export interface StaffProfile {
  id: string | null;
  staff_id: string;
  person_id: string;
  full_name: string;
  employment_status: EmploymentStatus | null;
  staff_category: StaffCategory | null;
  function: string | null;
  teaches: boolean;
  grade_label: string | null;
  payroll_ref: string | null;
  updated_at: string | null;
}
export interface StaffHours {
  staff_profile_id: string | null;
  staff_id: string;
  person_id: string;
  full_name: string;
  employment_status: EmploymentStatus | null;
  declared_minutes: number;
  validated_minutes: number;
  rejected_minutes: number;
  logged_minutes: number;
}
export interface Deduction { label: string; amount: number }
export interface Payslip {
  id: string;
  staff_profile_id: string;
  person_id: string;
  month_ref: string;
  gross_amount: number;
  deductions: Deduction[];
  deductions_amount: number;
  net_amount: number;
  status: 'draft' | 'validated' | 'paid';
  created_by: string;
  validated_by: string | null;
  validated_at: string | null;
  paid_on: string | null;
  document_id: string | null;
  document_number: string | null;
}
export interface PayrollRules { eligible_statuses: EmploymentStatus[] }
export interface MonthlyHours {
  id: string;
  staff_profile_id: string;
  month_ref: string;
  minutes: number;
  note: string | null;
  status: 'declared' | 'validated';
  declared_by: string;
  validated_by: string | null;
  validated_at: string | null;
}

const data = async <T>(promise: Promise<{ data: T }>): Promise<T> => (await promise).data;

export const secondaryStaff = {
  profiles: async (category?: StaffCategory): Promise<StaffProfile[]> =>
    (await data(http.get<{ data: { items: StaffProfile[] } }>(`${ROOT}/profiles`, category ? { category } : {}))).items,
  saveProfile: (body: { person_id: string; employment_status: EmploymentStatus; staff_category: StaffCategory | null; grade_label: string | null; payroll_ref: string | null }): Promise<StaffProfile> =>
    data(http.request<{ data: StaffProfile }>('PUT', `${ROOT}/profiles`, { body })),
  hours: async (month: string): Promise<StaffHours[]> => (await data(http.get<{ data: { items: StaffHours[] } }>(`${ROOT}/hours`, { month }))).items,
  payrollRules: (): Promise<PayrollRules> => data(http.get<{ data: PayrollRules }>(`${ROOT}/payroll/rules`)),
  savePayrollRules: (eligible_statuses: EmploymentStatus[]): Promise<PayrollRules> =>
    data(http.request<{ data: PayrollRules }>('PUT', `${ROOT}/payroll/rules`, { body: { eligible_statuses } })),
  payslips: async (): Promise<Payslip[]> => (await data(http.get<{ data: { items: Payslip[] } }>(`${ROOT}/payroll/payslips`))).items,
  createPayslip: (body: { staff_profile_id: string; month_ref: string; gross_amount: number; deductions: Deduction[] }): Promise<Payslip> =>
    data(http.post<{ data: Payslip }>(`${ROOT}/payroll/payslips`, body)),
  validate: (id: string): Promise<Payslip> => data(http.post<{ data: Payslip }>(`${ROOT}/payroll/payslips/${encodeURIComponent(id)}/validate`, {})),
  pay: (id: string, locale?: 'fr' | 'en'): Promise<Payslip> =>
    data(http.post<{ data: Payslip }>(`${ROOT}/payroll/payslips/${encodeURIComponent(id)}/pay`, locale ? { locale } : {})),
  monthlyHours: async (month: string): Promise<MonthlyHours[]> => (await data(http.get<{ data: { items: MonthlyHours[] } }>(`${ROOT}/payroll/hours`, { month }))).items,
  declareMonthlyHours: (body: { staff_profile_id: string; month_ref: string; minutes: number; note: string | null }): Promise<MonthlyHours> =>
    data(http.request<{ data: MonthlyHours }>('PUT', `${ROOT}/payroll/hours`, { body })),
  validateMonthlyHours: (id: string): Promise<MonthlyHours> => data(http.post<{ data: MonthlyHours }>(`${ROOT}/payroll/hours/${encodeURIComponent(id)}/validate`, {})),
  /** PDF du DOC-30 d'un bulletin payé (route du secondaire, droits de la paie). */
  payslipPdf: (id: string): Promise<Blob> => http.request<Blob>('GET', `/secondary/payroll/${encodeURIComponent(id)}/pdf`, { expect: 'blob', headers: { Accept: 'application/pdf' } }),
};

/** Net = brut − retenues (affichage ; le serveur recalcule et refuse des retenues supérieures au brut). */
export function netOf(gross: number, deductions: readonly Deduction[]): number {
  return gross - deductions.reduce((sum, line) => sum + line.amount, 0);
}

/** Heures à deux décimales depuis des minutes. */
export function hoursOf(minutes: number): string {
  return (minutes / 60).toFixed(2);
}

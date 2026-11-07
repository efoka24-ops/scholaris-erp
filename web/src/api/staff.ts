// Personnel, vacations et congés (services S10 et congés).
import { http, type QueryValue } from './http';

export type SessionKind = 'cm' | 'td' | 'tp' | 'other';

export interface StaffMember {
  id: string;
  person_id: string;
  first_name?: string | null;
  last_name?: string | null;
  full_name?: string | null;
  phone?: string | null;
  email?: string | null;
  kind: 'permanent' | 'vacataire' | 'invite';
  status: 'active' | 'suspended' | 'left';
  grade: string | null;
  category: string | null;
  employee_number: string | null;
  department_id: string | null;
  hired_on: string | null;
  left_on: string | null;
}
export interface Qualification { id: string; staff_id: string; title: string; institution: string | null; obtained_on: string | null }
export interface Assignment {
  id: string;
  staff_id: string;
  structure_id: string | null;
  subject_label: string | null;
  session_kind: SessionKind;
  planned_minutes: number | null;
  validated_minutes: number | null;
  starts_on: string | null;
  ends_on: string | null;
  status: 'active' | 'ended';
}
export interface Rate { id: string; category: string; hourly_rate: number; monthly_cap_minutes: number | null }
export type ContractStatus = 'draft' | 'pending_approval' | 'active' | 'rejected' | 'terminated';
export interface Contract {
  id: string;
  staff_id: string;
  reference: string;
  starts_on: string;
  ends_on: string;
  hourly_rate: number;
  max_minutes: number;
  status: ContractStatus;
}
export type HourStatus = 'declared' | 'validated' | 'rejected';
export interface Hour {
  id: string;
  staff_id: string;
  held_on: string;
  minutes: number;
  session_kind: SessionKind;
  content: string | null;
  status: HourStatus;
  source: string;
  declared_by: string | null;
  teacher_signed_at: string | null;
  rejection_reason: string | null;
  statement_id: string | null;
}
export type StatementStatus = 'draft' | 'pending_approval' | 'validated' | 'paid';
export interface Statement {
  id: string;
  staff_id: string;
  period: string;
  minutes_total: number;
  hetd_milli: number;
  hourly_rate: number;
  gross_amount: number;
  status: StatementStatus;
  paid_on: string | null;
  payment_reference: string | null;
  lines?: Hour[];
}
export type LeaveType = 'annual' | 'sick' | 'maternity' | 'paternity' | 'family' | 'unpaid' | 'other';
export const LEAVE_TYPES: LeaveType[] = ['annual', 'sick', 'maternity', 'paternity', 'family', 'unpaid', 'other'];
export type LeaveStatus = 'pending' | 'approved' | 'rejected' | 'cancelled';
export interface LeaveRequest {
  id: string;
  staff_id: string;
  leave_type: LeaveType;
  starts_on: string;
  ends_on: string;
  days: number | null;
  reason: string | null;
  status: LeaveStatus;
  requested_by: string | null;
  decided_by: string | null;
}
export interface LeaveBalance {
  staff_id: string;
  leave_type: LeaveType;
  leave_year: number;
  entitled_days: number | null;
  used_days: number;
  pending_days: number;
  remaining_days: number | null;
}

type Q = Record<string, QueryValue>;
const list = <T>(path: string, query: Q = {}): Promise<T[]> => http.all<T>(path, query, {}, 5);
const one = async <T>(p: Promise<{ data: T }>): Promise<T> => (await p).data;

export const listStaff = (query: Q = {}) => list<StaffMember>('/staff', query);
export const getStaff = (id: string) => one(http.get<{ data: StaffMember }>(`/staff/${id}`));
export const createStaff = (body: Record<string, unknown>) => one(http.post<{ data: StaffMember }>('/staff', body));
export const updateStaff = (id: string, body: Record<string, unknown>) => one(http.patch<{ data: StaffMember }>(`/staff/${id}`, body));
export const listQualifications = (id: string) => one(http.get<{ data: Qualification[] }>(`/staff/${id}/qualifications`));
export const addQualification = (id: string, body: Record<string, unknown>) => one(http.post<{ data: Qualification }>(`/staff/${id}/qualifications`, body));
export const deleteQualification = (id: string, qid: string) => http.delete(`/staff/${id}/qualifications/${qid}`);
export const listAssignments = (id: string) => one(http.get<{ data: Assignment[] }>(`/staff/${id}/assignments`));
export const addAssignment = (id: string, body: Record<string, unknown>) => one(http.post<{ data: Assignment }>(`/staff/${id}/assignments`, body));
export const updateAssignment = (id: string, aid: string, body: Record<string, unknown>) => one(http.patch<{ data: Assignment }>(`/staff/${id}/assignments/${aid}`, body));
export const deleteAssignment = (id: string, aid: string) => http.delete(`/staff/${id}/assignments/${aid}`);

export const listRates = () => one(http.get<{ data: Rate[] }>('/vacation-rates'));
export const putRate = (body: Record<string, unknown>) => one(http.put<{ data: Rate }>('/vacation-rates', body));
export const listContracts = (query: Q = {}) => list<Contract>('/vacation-contracts', query);
export const createContract = (body: Record<string, unknown>) => one(http.post<{ data: Contract }>('/vacation-contracts', body));
export const contractAction = (id: string, action: 'submit' | 'approve' | 'reject' | 'terminate') => one(http.post<{ data: Contract }>(`/vacation-contracts/${id}/${action}`));

export const listHours = (query: Q = {}) => list<Hour>('/vacation-hours', query);
export const declareHour = (body: Record<string, unknown>) => one(http.post<{ data: Hour }>('/vacation-hours', body));
export const signHour = (id: string, body: Record<string, unknown> = {}) => one(http.post<{ data: Hour }>(`/vacation-hours/${id}/sign`, body));
export const validateHour = (id: string) => one(http.post<{ data: Hour }>(`/vacation-hours/${id}/validate`));
export const rejectHour = (id: string, reason: string) => one(http.post<{ data: Hour }>(`/vacation-hours/${id}/reject`, { reason }));

export const listStatements = (query: Q = {}) => list<Statement>('/vacation-statements', query);
export const getStatement = (id: string) => one(http.get<{ data: Statement }>(`/vacation-statements/${id}`));
export const generateStatement = (staffId: string, period: string) => one(http.post<{ data: Statement }>('/vacation-statements', { staff_id: staffId, period }));
export const statementAction = (id: string, action: 'submit' | 'approve' | 'reject') => one(http.post<{ data: Statement }>(`/vacation-statements/${id}/${action}`));
export const payStatement = (id: string, paidOn: string, reference: string) => one(http.post<{ data: Statement }>(`/vacation-statements/${id}/pay`, { paid_on: paidOn, payment_reference: reference }));

export const listLeaves = (query: Q = {}) => list<LeaveRequest>('/leave-requests', query);
export const requestLeave = (body: Record<string, unknown>) => one(http.post<{ data: LeaveRequest }>('/leave-requests', body));
export const leaveAction = (id: string, action: 'approve' | 'reject' | 'cancel') => one(http.post<{ data: LeaveRequest }>(`/leave-requests/${id}/${action}`));
export const leaveCalendar = (from: string, to: string) => one(http.get<{ data: LeaveRequest[] }>('/leave-calendar', { from, to }));
export const leaveBalances = (staffId: string | null, year: number) => one(http.get<{ data: LeaveBalance[] }>('/leave-balances', { staff_id: staffId, year }));
export const putLeaveBalance = (body: Record<string, unknown>) => one(http.put<{ data: LeaveBalance[] }>('/leave-balances', body));

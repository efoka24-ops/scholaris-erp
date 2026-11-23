// Portail enseignant, rapports planifiés et statistiques réglementaires.
import { http } from './http';

export interface TeacherClass {
  establishment_id: string;
  establishment_name: string | null;
  structure_id: string | null;
  subject_label: string | null;
  session_kind: string;
}
export interface TeacherLesson {
  subject: string | null;
  start: string;
  end: string;
  establishment_id: string;
  structure_id: string | null;
  room_id: string | null;
}
export interface TeacherPortal {
  person_id: string;
  kind: string;
  today: string;
  classes: TeacherClass[];
  timetable_today: TeacherLesson[];
  grading_todo: { available: boolean; reason: string | null; items: Array<Record<string, unknown>> };
  hours_month: { month: string; declared_minutes: number; validated_minutes: number; rejected_minutes: number };
}
export const getTeacherPortal = async (): Promise<TeacherPortal> => (await http.get<{ data: TeacherPortal }>('/portal/teacher')).data;

export type ReportType = 'direction' | 'censeur' | 'groupe' | 'regulatory';
export type Frequency = 'daily' | 'weekly' | 'monthly';
export interface ReportSchedule {
  id: string;
  name: string;
  report_type: ReportType;
  frequency: Frequency;
  run_day: number | null;
  recipient_ids: string[];
  status: 'active' | 'paused';
  next_run_on: string | null;
  last_run_at: string | null;
  last_run_status: string | null;
}
export interface ReportRun {
  id: string;
  run_key: string;
  trigger_kind: string;
  status: string;
  sent_count: number;
  failed_count: number;
  summary: string | null;
  created_at: string;
  /** RAPPORT-MAIL : motif de chaque destinataire en échec (absent des exécutions antérieures) */
  failures?: RunFailure[];
}
/** Destinataire en échec : motif du Messenger (no_contact, opted_out, consent_missing, template_missing, gateway_error…) ou send_error. */
export interface RunFailure { person_id: string; name: string | null; reason: string }
export interface RunResult { status: string; sent_count: number; failed_count: number; failures?: RunFailure[] }
/** Avertissement à l'enregistrement (jamais un refus) : no_email ou consent_required. */
export interface RecipientWarning { person_id: string; reason: string }
export interface SavedSchedule { schedule: ReportSchedule; warnings: RecipientWarning[] }

export const listSchedules = (): Promise<ReportSchedule[]> => http.all<ReportSchedule>('/report-schedules', {}, {}, 5);
export const createSchedule = async (body: Record<string, unknown>): Promise<ReportSchedule> => (await http.post<{ data: ReportSchedule }>('/report-schedules', body)).data;
export const updateSchedule = async (id: string, body: Record<string, unknown>): Promise<ReportSchedule> => (await http.patch<{ data: ReportSchedule }>(`/report-schedules/${id}`, body)).data;
/** Création ou modification avec les avertissements sur les destinataires (meta.recipient_warnings). */
export const saveSchedule = async (id: string | null, body: Record<string, unknown>): Promise<SavedSchedule> => {
  type Saved = { data: ReportSchedule; meta?: { recipient_warnings?: RecipientWarning[] } };
  const res = id ? await http.patch<Saved>(`/report-schedules/${id}`, body) : await http.post<Saved>('/report-schedules', body);
  return { schedule: res.data, warnings: res.meta?.recipient_warnings ?? [] };
};
export const deleteSchedule = (id: string): Promise<unknown> => http.delete(`/report-schedules/${id}`);
export const runSchedule = async (id: string): Promise<RunResult> => (await http.post<{ data: RunResult }>(`/report-schedules/${id}/run`)).data;
export const listRuns = async (id: string): Promise<ReportRun[]> => (await http.get<{ data: ReportRun[] }>(`/report-schedules/${id}/runs`)).data;

export interface Unavailable { available: false; reason: string }
export interface CountList { available: true; items: Array<Record<string, string | number | null>> }
export interface RegulatoryStats {
  generated_at: string;
  total_enrolled: number;
  by_academic_year: CountList | Unavailable;
  by_structure: CountList | Unavailable;
  by_sex: CountList | Unavailable;
  by_level: CountList | Unavailable;
}
export const getRegulatory = async (): Promise<RegulatoryStats> => (await http.get<{ data: RegulatoryStats }>('/regulatory-statistics')).data;

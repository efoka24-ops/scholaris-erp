// API du pilotage du secondaire (préfixe /api/v1/secondary/dashboards). Contrat : openapi.d/secondary-dashboards.yaml.
// Objectifs O1 à O7 du CDC calculés par le serveur ; vue réseau agrégée et masquée (jamais nominative) ; rapports en fichier joint.
import { http, type QueryValue } from '@/api/http';
import { downloadExport, type ExportFormat } from '@/api/exports';

const ROOT = '/secondary/dashboards';

export const OBJECTIVE_CODES = ['O1', 'O2', 'O3', 'O4', 'O5', 'O6', 'O7'] as const;
export type ObjectiveCode = (typeof OBJECTIVE_CODES)[number];
export type ObjectiveUnit = 'count' | 'percent' | 'hours_per_student';
export type ObjectiveReason = 'not_applicable' | 'no_term' | 'not_due' | 'no_students' | 'no_invoices' | 'no_classes' | 'no_calendar' | 'unavailable';

export interface Objective {
  code: ObjectiveCode;
  label: string;
  available: boolean;
  value: number | null;
  unit: ObjectiveUnit;
  target: number;
  direction: 'max' | 'min' | 'drop';
  met: boolean | null;
  reason: ObjectiveReason | null;
  detail: Record<string, string | number | boolean | null>;
  source: string;
  confirmation: string;
}

export interface Term {
  id: string;
  kind: string;
  ordinal: number;
  starts_on: string;
  ends_on: string;
}

export interface DashboardPeriod {
  academic_year_id: string;
  academic_year_label: string | null;
  term: Term | null;
  previous_term: Term | null;
  terms: Term[];
  today: string;
}

interface Envelope {
  head_title: string | null;
  period: DashboardPeriod;
  objectives: Objective[];
  generated_at: string;
  nominative: false;
}

export interface ExamSummary {
  session_id: string;
  exam_code: string;
  exam_label: string;
  candidates: number;
  results: number;
  passed: number;
  pass_rate: number | null;
}

export interface HeadDashboard extends Envelope {
  kind: 'head';
  restricted: ObjectiveCode[];
  figures: {
    classes: number;
    students: number;
    female: number;
    male: number;
    gender_unknown: number;
    staff: { total: number; teaching: number; by_kind: Record<string, number> };
    exams: ExamSummary[];
  };
  alerts: Array<{ code: string; deadline: string; days_left: number; status: 'upcoming' | 'overdue' }>;
}

export interface PedagogyClass {
  class_id: string;
  label: string;
  level_code: string;
  students: number;
  report_cards_issued_on?: string | null;
  report_cards_on_time?: boolean | null;
  coverage_percent?: number | null;
  unjustified_hours_per_student?: number | null;
}

export interface PedagogyDashboard extends Envelope {
  kind: 'pedagogy';
  scope: 'establishment' | 'classes';
  classes: PedagogyClass[];
}

export interface FinanceFigures {
  net_xaf: number;
  paid_xaf: number;
  balance_xaf: number;
  overdue_xaf: number;
  invoices: number;
  students_invoiced: number;
  recovery_percent: number | null;
}

export interface FinanceDashboard extends Envelope {
  kind: 'finance';
  fees_mode: 'private' | 'public_catalog' | 'none';
  totals: FinanceFigures;
  classes: Array<FinanceFigures & { class_id: string; label: string; level_code: string; students: number }>;
}

export interface NetworkRow {
  establishment: string;
  academic_year_id: string;
  students: number | null;
  female: number | null;
  male: number | null;
  exam_pass_rate: number | null;
  recovery_rate: number | null;
  recovery_applicable: boolean;
  masked: string[];
}

export interface NetworkDashboard {
  kind: 'network';
  nominative: false;
  minimum_cell_size: number;
  establishments: NetworkRow[];
  consolidated: { establishments: number; students: number | null; female: number | null; male: number | null; exam_pass_rate: number | null; recovery_rate: number | null };
}

export const CLAIM_OUTCOMES = ['calculation_error', 'other_error', 'unfounded'] as const;
export type ClaimOutcome = (typeof CLAIM_OUTCOMES)[number];

export interface ResultClaim {
  id: string;
  academic_year_id: string;
  period_id: string | null;
  class_id: string;
  person_id: string;
  subject_label: string | null;
  claim_text: string;
  received_on: string;
  status: 'open' | 'closed';
  outcome: ClaimOutcome | null;
  outcome_note: string | null;
}

export interface NewClaim {
  academic_year_id: string;
  class_id: string;
  person_id: string;
  claim_text: string;
  period_id?: string | null;
  subject_label?: string | null;
  received_on?: string;
}

export const REPORTS = ['objectives', 'rentree-classes', 'rentree-census', 'rentree-staff', 'annual-levels', 'annual-exams', 'network'] as const;
export type ReportName = (typeof REPORTS)[number];

export interface DashboardQuery {
  academic_year_id?: string;
  period_id?: string;
  previous_year_id?: string;
}

function clean(query: DashboardQuery): Record<string, QueryValue> {
  const out: Record<string, QueryValue> = {};
  for (const [key, value] of Object.entries(query)) {
    if (typeof value === 'string' && value.trim() !== '') out[key] = value.trim();
  }
  return out;
}

/** Valeur affichable d'un objectif : pourcentage, heures par élève ou nombre, sans zéros inutiles. */
export function formatObjective(value: number | null, unit: ObjectiveUnit, locale = 'fr'): string {
  if (value === null) return '—';
  const text = new Intl.NumberFormat(locale, { maximumFractionDigits: unit === 'hours_per_student' ? 2 : 1 }).format(value);
  return unit === 'percent' ? `${text} %` : text;
}

/** État affiché : atteint, non atteint, sans cible évaluable (null) ou indisponible. */
export function objectiveState(o: Objective): 'met' | 'missed' | 'open' | 'unavailable' {
  if (!o.available) return 'unavailable';
  if (o.met === null) return 'open';
  return o.met ? 'met' : 'missed';
}

export const secondaryDashboards = {
  head: (query: DashboardQuery = {}): Promise<HeadDashboard> => http.get<{ data: HeadDashboard }>(`${ROOT}/head`, clean(query)).then((r) => r.data),
  pedagogy: (query: DashboardQuery = {}): Promise<PedagogyDashboard> => http.get<{ data: PedagogyDashboard }>(`${ROOT}/pedagogy`, clean(query)).then((r) => r.data),
  finance: (query: DashboardQuery = {}): Promise<FinanceDashboard> => http.get<{ data: FinanceDashboard }>(`${ROOT}/finance`, clean(query)).then((r) => r.data),
  network: (query: DashboardQuery = {}): Promise<NetworkDashboard> => http.get<{ data: NetworkDashboard }>(`${ROOT}/network`, clean(query)).then((r) => r.data),
  claims: (academicYearId?: string): Promise<ResultClaim[]> =>
    http.get<{ data: ResultClaim[] }>(`${ROOT}/claims`, academicYearId ? { 'filter[academic_year_id]': academicYearId, per_page: 200 } : { per_page: 200 }).then((r) => r.data),
  recordClaim: (body: NewClaim): Promise<ResultClaim> => http.post<{ data: ResultClaim }>(`${ROOT}/claims`, body).then((r) => r.data),
  closeClaim: (id: string, outcome: ClaimOutcome, note: string): Promise<ResultClaim> =>
    http.post<{ data: ResultClaim }>(`${ROOT}/claims/${encodeURIComponent(id)}/close`, { outcome, outcome_note: note }).then((r) => r.data),
  download: (report: ReportName, format: ExportFormat, query: DashboardQuery = {}): Promise<void> =>
    downloadExport(`${ROOT}/reports/${report}`, clean(query), format, `rapport-${report}`),
};

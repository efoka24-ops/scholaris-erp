// Tableaux de bord de l'application Supérieur (IPES, module PIL) : GET /higher/dashboards/{kind} et .../export.
// Contrat : specs/001-socle-plateforme/contracts/openapi.d/higher-dashboards.yaml. Agrégats sans donnée nominative ; une ligne dont
// un effectif est inférieur à `suppressed_below` arrive masquée (valeurs nulles, suppressed vrai).
import { http, type QueryValue } from './http';
import { fetchExport, type ExportFormat } from './exports';
import { H } from './higher';

export type BoardKind = 'enrolment' | 'finance' | 'grading' | 'results' | 'attendance' | 'vacations' | 'national' | 'minesup';

export const BOARD_KINDS: BoardKind[] = ['enrolment', 'finance', 'grading', 'results', 'attendance', 'vacations', 'national', 'minesup'];

/** Permission de donnée qui ouvre chaque tableau (en plus de higher.dashboard.read) : adapte seulement la navigation. */
export const BOARD_PERMISSIONS: Record<BoardKind, string[]> = {
  enrolment: ['higher.admission.read', 'higher.enrolment.read'],
  finance: ['higher.billing.read', 'billing.manage'],
  grading: ['higher.grades.read', 'assessment.validate'],
  results: ['higher.grades.read', 'assessment.validate'],
  attendance: ['higher.attendance.read'],
  vacations: ['higher.vacation.read', 'higher.vacation.validate_school', 'higher.hours.validate'],
  national: ['higher.deliberation.read', 'higher.national_list.export'],
  minesup: ['higher.enrolment.read', 'higher.national_list.export'],
};

export type CardUnit = 'count' | 'percent' | 'xaf' | 'hours';

export interface BoardCard { key: string; value: number | null; unit: CardUnit }

export type Cell = string | number | boolean | null | undefined;
export interface BoardRow { suppressed: boolean; [column: string]: Cell }
export interface BoardTable { key: string; columns: string[]; rows: BoardRow[] }

export interface BoardFilters { school_id: string; program_id: string | null; academic_year_id: string | null; from: string | null; to: string | null }

export interface Board {
  board: BoardKind;
  generated_at: string;
  filters: BoardFilters;
  schools: Array<{ id: string; name: string }>;
  school_selector: boolean;
  suppressed_below: number;
  cards: BoardCard[];
  tables: BoardTable[];
  notes: string[];
}

export type BoardQuery = Record<string, QueryValue>;

const seg = (v: string): string => encodeURIComponent(v);

export const dashboards = {
  async get(kind: BoardKind, query: BoardQuery = {}): Promise<Board> {
    return (await http.get<{ data: Board }>(`${H}/dashboards/${seg(kind)}`, query)).data;
  },
  exportTable(kind: BoardKind, table: string, query: BoardQuery, format: ExportFormat): Promise<Blob> {
    return fetchExport(`${H}/dashboards/${seg(kind)}/export`, { ...query, table }, format);
  },
};

// API du conseil de classe et des bulletins du secondaire (lot E3-A, T900 à T904). Contrat : openapi.d/secondary-councils.yaml.
// Les actions du conseil (ouverture, décisions, recalcul, validation, génération des bulletins) passent par les routes du socle
// /class-councils ; la publication des règles du niveau par /calculation-rules. /secondary/councils ajoute le brouillon de règles
// alimenté par les coefficients de Setup, les paramètres des bulletins et l'aperçu des variables du bulletin.
import { http, type QueryValue } from '@/api/http';
import type { CouncilLine } from '@/api/assessment';

const ROOT = '/secondary/councils';
const enc = encodeURIComponent;

const get = async <T>(path: string, query?: Record<string, QueryValue>): Promise<T> => (await http.get<{ data: T }>(path, query ?? {})).data;
const post = async <T>(path: string, body?: unknown): Promise<T> => (await http.post<{ data: T }>(path, body ?? {})).data;
const patch = async <T>(path: string, body?: unknown): Promise<T> => (await http.patch<{ data: T }>(path, body ?? {})).data;

export interface WorkProposal {
  code: string;
  label: string;
  kind: 'distinction' | 'sanction';
}
export interface Promotion {
  code: string;
  label: string;
  conditional_possible: boolean;
  practical_below: boolean;
}
/** Valeurs propres d'une ligne, pré-remplies par le fournisseur du secondaire (saisie en séance conservée). */
export interface SecondaryLineExtras {
  work_proposal?: WorkProposal | null;
  distinction_refused?: boolean;
  refused_proposal?: WorkProposal | null;
  conduct_sanctions?: { code: string; label: string }[];
  absence_hours?: { total: number; justified: number; unjustified: number; lates: number };
  promotion?: Promotion | null;
  subject_alerts?: string[];
  practical_alerts?: string[];
  not_evaluated?: string[];
  /** Notes d'atelier de la période (contrat Workshops PracticalMarks, C-117) : points sur la somme des bases des fiches. */
  workshop_practical?: { evaluations: number; points: number; out_of: number } | null;
}
export interface SecondaryCouncilLine extends CouncilLine {
  line_extras: SecondaryLineExtras | null;
}
export interface SecondaryCouncil {
  id: string;
  structure_id: string;
  period_id: string;
  period_label: string | null;
  status: string;
  summary: Record<string, unknown> | string | null;
  minutes_status: string | null;
  lines: SecondaryCouncilLine[];
}
export interface ReportCardRequest {
  id: string;
  person_id: string;
  status: 'queued' | 'issued' | 'failed';
  document_id: string | null;
  error_message: string | null;
}
export interface CoefficientDraftLine {
  subject_id: string;
  code: string;
  label: string;
  coefficient: number;
  role: string;
  counts_in_average: boolean;
  version_no: number;
}
export interface RuleDraft {
  class_id: string;
  academic_year_id: string;
  level_code: string;
  stream_id: string | null;
  scope: 'level' | 'class';
  /** null : aucun coefficient pour tout le niveau, seules les versions des séries propres sont à publier (C-118). */
  structure_id: string | null;
  label: string;
  from_example: boolean;
  example_notice: string | null;
  coefficients: CoefficientDraftLine[];
  definition: Record<string, unknown>;
  /** C-118 : au niveau, une version par classe d'une série à coefficients propres (publiée sur la classe, qui prime sur le niveau). */
  stream_overrides?: { structure_id: string; class_label: string; stream_id: string; label: string; coefficients: CoefficientDraftLine[]; definition: Record<string, unknown> }[];
}
export interface ReportCardSettings {
  report_card_types: Record<string, string> | null;
  expected_types: Record<string, string>;
  subject_groups: unknown;
  letterhead: Record<string, string> | null;
  applied?: boolean;
}
/** Tableau du moteur de documents ({{table:x}}) : colonnes, en-tête sur plusieurs lignes, lignes de groupe et TOTAL. */
export interface DocTableCell {
  text: string;
  colspan?: number;
  rowspan?: number;
}
export type DocTableRow = (string | DocTableCell)[] | { kind: string; cells: (string | DocTableCell)[] };
export interface DocTable {
  class?: string;
  columns: { label: string; align?: string; width?: number }[];
  head?: (string | DocTableCell)[][];
  rows: DocTableRow[];
}
export interface ReportCardPreview {
  council_id: string;
  person_id: string;
  status: string;
  variables: Record<string, string | number | null>;
  snapshot: { built_at: string; variables: Record<string, string | number | null> } | null;
}

export const REPORT_TABLES = ['secondary_subjects_table', 'secondary_summary_table', 'secondary_class_profile_table', 'secondary_discipline_table', 'secondary_work_table'] as const;

/** Lit un tableau JSON du bulletin ; null si la valeur n'est pas un tableau bien formé. */
export function parseDocTable(raw: unknown): DocTable | null {
  if (typeof raw !== 'string' || raw === '') return null;
  try {
    const value = JSON.parse(raw) as Partial<DocTable>;
    return Array.isArray(value.columns) && Array.isArray(value.rows) ? (value as DocTable) : null;
  } catch {
    return null;
  }
}

export function rowCells(row: DocTableRow): { kind: string; cells: (string | DocTableCell)[] } {
  return Array.isArray(row) ? { kind: '', cells: row } : { kind: row.kind, cells: row.cells };
}

export function cellText(cell: string | DocTableCell): string {
  return typeof cell === 'string' ? cell : cell.text;
}

/** Valeurs propres décodées (le socle les rend en objet ou en texte JSON). */
export function lineExtras(line: { line_extras?: unknown }): SecondaryLineExtras {
  const raw = line.line_extras;
  if (raw && typeof raw === 'object') return raw as SecondaryLineExtras;
  if (typeof raw === 'string' && raw !== '') {
    try {
      return JSON.parse(raw) as SecondaryLineExtras;
    } catch {
      return {};
    }
  }
  return {};
}

export const secondaryCouncils = {
  list: (structureId: string, periodId?: string): Promise<SecondaryCouncil[]> =>
    http.all<SecondaryCouncil>('/class-councils', { 'filter[structure_id]': structureId, ...(periodId ? { 'filter[period_id]': periodId } : {}) }),
  find: (id: string): Promise<SecondaryCouncil> => get(`/class-councils/${enc(id)}`),
  open: (input: { structure_id: string; period_id: string; period_label: string; held_on?: string }): Promise<SecondaryCouncil> => post('/class-councils', input),
  updateLine: (id: string, lineId: string, changes: { decision?: string | null; reason?: string | null; appraisal?: string | null; conduct?: string | null }): Promise<SecondaryCouncil> =>
    patch(`/class-councils/${enc(id)}/lines/${enc(lineId)}`, changes),
  refresh: (id: string): Promise<SecondaryCouncil> => post(`/class-councils/${enc(id)}/refresh`),
  validate: (id: string): Promise<SecondaryCouncil> => post(`/class-councils/${enc(id)}/validate`),
  reportCards: (id: string): Promise<ReportCardRequest[]> => get(`/class-councils/${enc(id)}/report-cards`),
  generateReportCards: (id: string): Promise<ReportCardRequest[]> => post(`/class-councils/${enc(id)}/report-cards`),
  ruleDraft: (classId: string, scope: 'level' | 'class'): Promise<RuleDraft> => get(`${ROOT}/rule-draft`, { class_id: classId, scope }),
  publishRules: (rules: { academic_year_id: string; label: string; definition: Record<string, unknown>; structure_id: string }): Promise<unknown> =>
    post('/calculation-rules', { academic_year_id: rules.academic_year_id, label: rules.label, definition: rules.definition, structure_id: rules.structure_id }),
  settings: (): Promise<ReportCardSettings> => get(`${ROOT}/report-card-settings`),
  presetSettings: (): Promise<ReportCardSettings> => post(`${ROOT}/report-card-settings/preset`),
  preview: (councilId: string, personId: string, locale?: 'fr' | 'en'): Promise<ReportCardPreview> =>
    get(`${ROOT}/${enc(councilId)}/report-cards/${enc(personId)}`, locale ? { locale } : {}),
};

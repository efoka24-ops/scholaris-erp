export interface GridColumn {
  id: string;
  subject_id: string | null;
  title: string;
  kind: string;
  weight: number;
  max_score: number;
  status: string;
  grading_mode: string;
  anonymous: boolean;
}

export interface GridCell {
  state: 'value' | 'missing' | 'error' | 'absent_justified' | 'absent_unjustified' | 'hidden';
  value: number | null;
  absence: 'justified' | 'unjustified' | null;
  qualitative: string | null;
  version: number;
  grade_id: string | null;
  message: string | null;
}

export interface GridRow {
  number: number;
  person_id: string;
  matricule: string | null;
  name: string;
  cells: Record<string, GridCell>;
  average: number | null;
  appraisal: string | null;
}

export interface GradeGrid {
  columns: GridColumn[];
  rows: GridRow[];
  counters: { errors: number; missing: number; absent_justified: number };
  rules_version: number;
}

export interface CouncilLine {
  id: string;
  person_id: string;
  name: string;
  matricule: string | null;
  average: number | null;
  rank_in_class: number | null;
  unjustified_absences: number | null;
  conduct: string | null;
  proposal: string | null;
  decision: string | null;
  reason: string | null;
  appraisal: string | null;
}

export interface ClassCouncil {
  id: string;
  structure_id: string;
  period_id: string;
  period_label: string | null;
  status: string;
  summary: Record<string, unknown> | null;
  minutes_status: string | null;
  lines: CouncilLine[];
}

/** Une colonne n'est plus modifiable une fois la saisie validée, verrouillée ou publiée (cycle déclaré par l'application). */
export function isColumnEditable(status: string): boolean {
  return !/valid|lock|verrouill|publi|clos|close/i.test(status);
}

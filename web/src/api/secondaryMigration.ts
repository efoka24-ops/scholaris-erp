// Reprise de données d'un ancien logiciel, application Secondaire (T977, lot E4-C) : /api/v1/secondary/migration/** (contrat
// openapi.d/secondary-migration.yaml). Simulation obligatoire d'abord (fichier joint en multipart/form-data, champ `file`), rapport
// ligne par ligne, puis application seulement sans erreur. Droits par jeu : classes (paramétrage), élèves (scolarité), soldes (intendance).
import { http } from './http';

const M = '/secondary/migration';
const seg = (v: string): string => encodeURIComponent(v);
const unwrap = async <T>(p: Promise<{ data: T }>): Promise<T> => (await p).data;

export type MigrationKind = 'classes' | 'students' | 'balances';
/** Ordre conseillé : classes, puis élèves, puis soldes d'ouverture. */
export const MIGRATION_KINDS: MigrationKind[] = ['classes', 'students', 'balances'];
export type MigrationBatchStatus = 'simulated' | 'applied' | 'partial';
export type MigrationLineStatus = 'valid' | 'duplicate' | 'error' | 'applied';

export interface MigrationKindInfo { kind: MigrationKind; label: string; columns: string[]; required: string[]; allowed: boolean }
export interface MigrationSummary { total: number; valid: number; duplicate: number; error: number; applied: number }
export interface MigrationLine { line: number; status: MigrationLineStatus; label: string; messages: string[]; object_ref: string | null }
export interface MigrationBatch {
  id: string; kind: MigrationKind; academic_year_id: string; entry_date: string | null; format: 'csv' | 'xlsx'; file_name: string | null;
  status: MigrationBatchStatus; summary: MigrationSummary; unknown_columns: string[]; created_by: string; created_at: string;
  applied_at: string | null; lines?: MigrationLine[];
}

/** Un lot simulé s'applique s'il n'a aucune ligne en erreur et au moins une ligne valide. */
export const applicable = (b: MigrationBatch): boolean => (b.status === 'simulated' || b.status === 'partial') && b.summary.error === 0 && b.summary.valid > 0;

export const secondaryMigration = {
  kinds: (): Promise<MigrationKindInfo[]> => unwrap(http.get(`${M}/kinds`)),
  /** Modèle de fichier (CSV UTF-8, séparateur « ; »), lignes d'exemple fictives à remplacer. */
  template: (kind: MigrationKind): Promise<Blob> => http.get<Blob>(`${M}/templates/${seg(kind)}`, undefined, { expect: 'blob', headers: { Accept: 'text/csv, application/json;q=0.5' } }),
  batches: (kind?: MigrationKind): Promise<MigrationBatch[]> => unwrap(http.get(`${M}/batches`, kind ? { kind } : undefined)),
  batch: (id: string): Promise<MigrationBatch> => unwrap(http.get(`${M}/batches/${seg(id)}`)),
  /** Simulation : le fichier part en multipart/form-data (champ `file`) ; rien n'est écrit hors du lot. */
  simulate: (b: { kind: MigrationKind; academic_year_id: string; entry_date?: string; file: File }): Promise<MigrationBatch> => {
    const form = new FormData();
    form.append('kind', b.kind);
    form.append('academic_year_id', b.academic_year_id);
    if (b.entry_date) form.append('entry_date', b.entry_date);
    form.append('file', b.file, b.file.name);
    return unwrap(http.post(`${M}/batches`, undefined, { form }));
  },
  apply: (id: string): Promise<MigrationBatch> => unwrap(http.post(`${M}/batches/${seg(id)}/apply`)),
};

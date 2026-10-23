// Téléchargement des exports de listes (GET …/export?format=csv|xlsx) avec les filtres courants.
import { ApiError, http, type QueryValue } from './http';

export type ExportFormat = 'csv' | 'xlsx';

/** Le serveur ne sait pas (encore) produire ce format : on ne propose plus que le CSV. */
export class ExportFormatUnavailable extends Error {
  readonly format: ExportFormat;
  constructor(format: ExportFormat) {
    super(`Format ${format} indisponible`);
    this.name = 'ExportFormatUnavailable';
    this.format = format;
  }
}

const ACCEPT: Record<ExportFormat, string> = {
  csv: 'text/csv, application/json;q=0.5',
  xlsx: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet, application/json;q=0.5',
};

export async function fetchExport(path: string, query: Record<string, QueryValue>, format: ExportFormat): Promise<Blob> {
  try {
    return await http.get<Blob>(path, { ...query, format }, { expect: 'blob', headers: { Accept: ACCEPT[format] } });
  } catch (e) {
    // 400/422 : paramètre inconnu ; 415/501 : format ou extension zip absents côté serveur.
    if (format === 'xlsx' && e instanceof ApiError && [400, 415, 422, 501].includes(e.status)) throw new ExportFormatUnavailable(format);
    throw e;
  }
}

export function saveBlob(blob: Blob, filename: string): void {
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}

export async function downloadExport(path: string, query: Record<string, QueryValue>, format: ExportFormat, baseName: string): Promise<void> {
  const blob = await fetchExport(path, query, format);
  saveBlob(blob, `${baseName}.${format}`);
}

// API des examens officiels du secondaire. Les formats et codes de résultat sont configurés par établissement.
import { http, type QueryValue } from '@/api/http';
import { translate } from '@/i18n';

const ROOT = '/secondary/official-exams';
const get = async <T>(path: string, query?: Record<string, QueryValue>): Promise<T> => (await http.get<{ data: T }>(`${ROOT}${path}`, query ?? {})).data;
const post = async <T>(path: string, body?: unknown): Promise<T> => (await http.post<{ data: T }>(`${ROOT}${path}`, body ?? {})).data;
const patch = async <T>(path: string, body?: unknown): Promise<T> => (await http.patch<{ data: T }>(`${ROOT}${path}`, body ?? {})).data;

export interface ExamSession {
  id: string;
  exam_code: string;
  exam_label: string;
  academic_year_id: string;
  class_structure_id: string;
  comparison_period_id: string | null;
  registration_deadline: string | null;
  exam_on: string | null;
  required_pieces: string[];
  optional_subjects: string[];
  export_columns: { field: string; header: string }[];
  passing_codes: string[];
  status: 'open' | 'closed';
}

export interface ExamCandidate {
  id: string;
  session_id: string;
  person_id: string;
  enrollment_id: string;
  full_name: string;
  class_id: string;
  class_label: string;
  national_id: string | null;
  national_id_status: string;
  photo_ref: string | null;
  photo_url: string | null;
  fee_status: 'seen' | 'not_seen';
  missing_pieces: string[];
  pieces_received: string[];
  selected_subjects: string[];
  result_code: string | null;
  result_mark: number | null;
  status: 'blocked' | 'incomplete' | 'eligible';
  status_message: string;
}

export interface ExamSummary {
  session_id: string;
  candidate_count: number;
  results_count: number;
  passed_count: number;
  success_rate: number | null;
  result_counts: Record<string, number>;
  passing_codes: string[];
  comparison: { period_id: string; exam_average: number | null; school_average: number | null; difference: number | null; students_with_school_average: number } | null;
}

export const secondaryOfficialExams = {
  sessions: async (query: Record<string, QueryValue> = {}): Promise<ExamSession[]> => (await http.all<ExamSession>(ROOT, query)),
  createSession: (body: Partial<ExamSession> & Pick<ExamSession, 'exam_code' | 'exam_label' | 'academic_year_id' | 'class_structure_id'>): Promise<ExamSession> => post('', body),
  updateSession: (id: string, body: Partial<ExamSession>): Promise<ExamSession> => patch(`/${encodeURIComponent(id)}`, body),
  candidates: async (id: string): Promise<ExamCandidate[]> => (await http.all<ExamCandidate>(`${ROOT}/${encodeURIComponent(id)}/candidates`)),
  photoBlob: (url: string): Promise<Blob> => {
    const parsed = new URL(url, typeof window === 'undefined' ? 'http://localhost' : window.location.origin);
    const path = parsed.pathname.replace(/^\/api\/v1/, '');
    return http.request<Blob>('GET', path, { query: Object.fromEntries(parsed.searchParams.entries()), expect: 'blob' });
  },
  generate: (id: string): Promise<{ added: number; already_listed: number }> => post(`/${encodeURIComponent(id)}/candidates/generate`),
  addCandidate: (id: string, personId: string): Promise<ExamCandidate> => post(`/${encodeURIComponent(id)}/candidates`, { person_id: personId }),
  removeCandidate: (id: string): Promise<{ id: string; removed: boolean }> => http.delete(`${ROOT}/candidates/${encodeURIComponent(id)}`),
  updateCandidate: (id: string, body: { pieces_received?: string[]; selected_subjects?: string[] }): Promise<ExamCandidate> => patch(`/candidates/${encodeURIComponent(id)}`, body),
  removePhoto: (id: string): Promise<ExamCandidate> => http.delete(`${ROOT}/candidates/${encodeURIComponent(id)}/photo`),
  uploadPhoto: (id: string, file: File): Promise<ExamCandidate> => new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onerror = () => reject(new Error(translate('secx.photoUnreadable')));
    reader.onload = async () => {
      try {
        const data = String(reader.result ?? '').split(',')[1] ?? '';
        resolve(await post(`/candidates/${encodeURIComponent(id)}/photo`, { photo_base64: data }));
      } catch (error) {
        reject(error);
      }
    };
    reader.readAsDataURL(file);
  }),
  exportCsv: (id: string): Promise<Blob> => http.request<Blob>('GET', `${ROOT}/${encodeURIComponent(id)}/candidates/export`, { expect: 'blob', headers: { Accept: 'text/csv' } }),
  importResults: (id: string, csv: string): Promise<{ imported: number; rejected: { line: number; key: string; reason: string }[]; summary: ExamSummary }> => post(`/${encodeURIComponent(id)}/results/import`, { csv }),
  summary: (id: string): Promise<ExamSummary> => get(`/${encodeURIComponent(id)}/summary`),
};

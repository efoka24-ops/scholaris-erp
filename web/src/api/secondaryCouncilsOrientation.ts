// API de l'orientation du secondaire (lot E3-D, T905, module `orientation`). Contrat : openapi.d/secondary-councils.yaml.
import { http } from '@/api/http';

const ROOT = '/secondary/orientation';
const enc = encodeURIComponent;

export interface OrientationCase {
  id: string | null;
  person_id: string;
  student_name: string;
  class_id: string;
  academic_year_id: string;
  wishes: string[];
  counsellor_opinion: string | null;
  proposed_stream_id: string | null;
  interview_on: string | null;
  latest_decision?: OrientationDecision | null;
}
export interface OrientationDecision {
  id: string;
  person_id: string;
  class_id: string;
  academic_year_id: string;
  stream_id: string;
  reason: string;
  decision_on: string;
  decided_by: string;
}
export interface OrientationAppeal {
  id: string;
  decision_id: string;
  person_id: string;
  class_id: string;
  submitted_on: string;
  reason: string;
  status: 'pending' | 'accepted' | 'rejected';
  outcome_reason: string | null;
  revised_stream_id: string | null;
  decided_by: string | null;
  decided_at: string | null;
}


type One<T> = { data: T };
type Many<T> = { data: T[] };

export const secondaryOrientation = {
  cases: async (classId: string, academicYearId: string): Promise<OrientationCase[]> =>
    (await http.get<Many<OrientationCase>>(ROOT, { class_id: classId, academic_year_id: academicYearId })).data,
  me: async (academicYearId: string): Promise<OrientationCase> =>
    (await http.get<One<OrientationCase>>(`${ROOT}/me`, { academic_year_id: academicYearId })).data,
  family: async (academicYearId: string): Promise<OrientationCase[]> =>
    (await http.get<Many<OrientationCase>>(`${ROOT}/family`, { academic_year_id: academicYearId })).data,
  saveOwnWishes: async (academicYearId: string, wishes: string[]): Promise<OrientationCase> =>
    (await http.put<One<OrientationCase>>(`${ROOT}/me/wishes`, { academic_year_id: academicYearId, wishes })).data,
  saveWishes: async (personId: string, academicYearId: string, wishes: string[]): Promise<OrientationCase> =>
    (await http.put<One<OrientationCase>>(`${ROOT}/students/${enc(personId)}/wishes`, { academic_year_id: academicYearId, wishes })).data,
  saveAdvice: async (personId: string, academicYearId: string, changes: { counsellor_opinion?: string | null; proposed_stream_id?: string | null; interview_on?: string | null }): Promise<OrientationCase> =>
    (await http.put<One<OrientationCase>>(`${ROOT}/students/${enc(personId)}/advice`, { academic_year_id: academicYearId, ...changes })).data,
  decide: async (personId: string, academicYearId: string, streamId: string, reason: string): Promise<OrientationDecision> =>
    (await http.post<One<OrientationDecision>>(`${ROOT}/students/${enc(personId)}/decisions`, { academic_year_id: academicYearId, stream_id: streamId, reason })).data,
  appeals: async (classId: string): Promise<OrientationAppeal[]> =>
    (await http.get<Many<OrientationAppeal>>(`${ROOT}/appeals`, { class_id: classId })).data,
  myAppeals: async (academicYearId: string): Promise<OrientationAppeal[]> =>
    (await http.get<Many<OrientationAppeal>>(`${ROOT}/me/appeals`, { academic_year_id: academicYearId })).data,
  appeal: async (personId: string, academicYearId: string, reason: string): Promise<OrientationAppeal> =>
    (await http.post<One<OrientationAppeal>>(`${ROOT}/students/${enc(personId)}/appeals`, { academic_year_id: academicYearId, reason })).data,
  decideAppeal: async (appealId: string, outcome: 'accepted' | 'rejected', reason: string, revisedStreamId: string | null): Promise<OrientationAppeal> =>
    (await http.post<One<OrientationAppeal>>(`${ROOT}/appeals/${enc(appealId)}/decide`, { outcome, reason, revised_stream_id: revisedStreamId })).data,
};
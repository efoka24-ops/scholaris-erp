// Appels des écrans IPES complémentaires : délégués de groupe (openapi.d/higher-teaching.yaml) et séance de délibération du
// moteur du socle (openapi.d/assessment.yaml : /deliberations, ouverture, présents, démarrage, clôture, réouverture motivée).
import { http } from './http';
import { H, type Q } from './higher';

const seg = (v: string): string => encodeURIComponent(v);
const unwrap = async <T>(p: Promise<{ data: T }>): Promise<T> => (await p).data;

export interface GroupDelegate { id: string; group_id: string; person_id: string; designated_by: string | null; created_at: string }

export const delegates = {
  list: (q: Q = {}): Promise<GroupDelegate[]> => http.all<GroupDelegate>(`${H}/teaching/group-delegates`, q),
  designate: (b: { group_id: string; person_id: string }): Promise<GroupDelegate> => http.post(`${H}/teaching/group-delegates`, b),
  remove: (id: string): Promise<unknown> => http.delete(`${H}/teaching/group-delegates/${seg(id)}`),
};

export type SittingStatus = 'draft' | 'open' | 'closed' | string;
export interface SittingLine { id: string; person_id: string; name: string | null; matricule: string | null; average: number | null; proposal: string | null; decision: string | null; reason: string | null }
export interface Sitting {
  id: string; structure_id: string; period_id: string; exam_session: string; period_label: string | null; status: SittingStatus; president_id: string;
  members_expected: number; members_present: string[] | null; quorum_reached: boolean; minutes_fingerprint: string | null; minutes_version: number;
  reopen_reason: string | null; closed_at: string | null; lines?: SittingLine[];
}

export const sittings = {
  find: async (b: { structure_id: string; period_id: string; exam_session: string }): Promise<Sitting | null> => {
    const page = await http.get<{ data: Sitting[] }>('/deliberations', { 'filter[structure_id]': b.structure_id, 'filter[period_id]': b.period_id, 'filter[exam_session]': b.exam_session });
    return page.data[0] ?? null;
  },
  show: (id: string): Promise<Sitting> => unwrap(http.get(`/deliberations/${seg(id)}`)),
  open: (b: { structure_id: string; period_id: string; period_label: string; exam_session: string; president_id: string; members_expected: number }): Promise<Sitting> =>
    unwrap(http.post('/deliberations', b)),
  attendance: (id: string, present: string[]): Promise<Sitting> => unwrap(http.post(`/deliberations/${seg(id)}/attendance`, { members_present: present })),
  start: (id: string): Promise<Sitting> => unwrap(http.post(`/deliberations/${seg(id)}/start`)),
  close: (id: string): Promise<Sitting> => unwrap(http.post(`/deliberations/${seg(id)}/close`)),
  reopen: (id: string, reason: string): Promise<Sitting> => unwrap(http.post(`/deliberations/${seg(id)}/reopen`, { reason })),
};

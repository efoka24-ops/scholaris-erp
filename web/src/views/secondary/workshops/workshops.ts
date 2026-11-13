// Local API bindings for workshop, practical safety, materials and internship services.
import { http } from '@/api/http';

const ROOT = '/secondary';
const get = async <T>(path: string, query?: Record<string, string>) => (await http.get<{ data: T }>(`${ROOT}${path}`, query)).data;
const post = async <T>(path: string, body?: unknown) => (await http.post<{ data: T }>(`${ROOT}${path}`, body ?? {})).data;
const patch = async <T>(path: string, body: unknown) => (await http.patch<{ data: T }>(`${ROOT}${path}`, body)).data;

export interface Workshop { id: string; label: string; stream_id: string | null; room_id: string | null; capacity: number | null }
export interface Workstation { id: string; workshop_id: string; label: string; machine: number; ppe_required: string; status: string }
export interface PracticalSheet { id: string; workshop_id: string; title: string; objective: string; materials: string; tools: string; safety_rules: string; criteria: string; base_score: number; safety_cap: number | null; version_no: number }
export interface SessionAttendance { person_id: string; mode: 'observation' | 'workstation'; workstation_id: string | null; ppe_items: string[] }
export interface WorkshopSession { id: string; workshop_id: string; structure_id: string; sheet_id: string; session_date: string; attendance: SessionAttendance[]; client_uuid: string }
export interface MaterialItem { id: string; label: string; unit_label: string; unit_cost: number; stock_qty: number }
export interface Company { id: string; label: string; sector: string | null; status: string }
export interface Internship { id: string; person_id: string; company_id: string; tutor_name: string; starts_on: string; ends_on: string; score: number | null; score_coefficient: number | null; status: string; company: Company }

export const workshopsApi = {
  workshops: () => get<Workshop[]>('/workshops'),
  createWorkshop: (body: { label: string; room_id?: string | null; capacity?: number | null }) => post<Workshop>('/workshops', body),
  stations: (workshop_id: string) => get<Workstation[]>('/workshops/workstations', { workshop_id }),
  createStation: (body: { workshop_id: string; label: string; machine: boolean; ppe_required: string[] }) => post<Workstation>('/workshops/workstations', body),
  sheets: (workshop_id: string) => get<PracticalSheet[]>('/workshops/sheets', { workshop_id }),
  createSheet: (body: unknown) => post<PracticalSheet>('/workshops/sheets', body),
  sessions: (workshop_id: string) => get<WorkshopSession[]>('/workshops/sessions', { workshop_id }),
  createSession: (workshopId: string, body: unknown) => post<{ session: WorkshopSession; replayed: boolean }>(`/workshops/${workshopId}/sessions`, body),
  materials: (workshop_id: string) => get<MaterialItem[]>('/workshops/materials', { workshop_id }),
  createMaterial: (body: unknown) => post<MaterialItem>('/workshops/materials', body),
  moveMaterial: (id: string, body: unknown) => post<{ stock_qty: number; total_cost_xaf: number }>(`/workshops/materials/${id}/moves`, body),
  safetyEvents: (workshop_id: string) => get<unknown[]>('/workshops/safety-events', { workshop_id }),
  reportSafety: (workshop_id: string, body: unknown) => post<unknown>(`/workshops/safety-events?workshop_id=${encodeURIComponent(workshop_id)}`, body),
  evaluate: (session_id: string, body: unknown) => post<unknown>(`/workshops/sessions/${session_id}/evaluations`, body),
};

export const internshipsApi = {
  companies: () => get<Company[]>('/internships/companies'),
  createCompany: (body: unknown) => post<Company>('/internships/companies', body),
  internships: () => get<Internship[]>('/internships'),
  create: (body: unknown) => post<Internship>('/internships', body),
  update: (id: string, body: unknown) => patch<Internship>(`/internships/${id}`, body),
  visits: (internship_id: string) => get<unknown[]>('/internships/visits', { internship_id }),
  visit: (id: string, body: unknown) => post<unknown>(`/internships/${id}/visits`, body),
};

export interface SessionLearner { person_id: string; workstation_id?: string; ppe_items?: string[] }

/**
 * Une ligne par élève : « identifiant ; poste ; EPI1, EPI2 » (poste et EPI facultatifs). Le serveur décide : sans tous les EPI
 * exigés par un poste machine, l'élève reste en observation (RG-ATL-1).
 */
export function parseLearners(text: string): SessionLearner[] {
  return text.split(/\r?\n/).map((line) => line.trim()).filter(Boolean).map((line) => {
    const [person = '', station = '', ppe = ''] = line.split(';').map((part) => part.trim());
    const learner: SessionLearner = { person_id: person };
    if (station) learner.workstation_id = station;
    const items = ppe.split(',').map((item) => item.trim()).filter(Boolean);
    if (items.length) learner.ppe_items = items;
    return learner;
  });
}

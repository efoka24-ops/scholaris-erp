// Aides des tests des écrans IPES « Enseignement et présences » : contexte higher, permissions, API simulée de base.
import type { Me } from '@/api/types';
import { jsonResponse, mockApi, signIn, teacherMe, tile, type RecordedCall } from './helpers';

export const page = (items: unknown[]) => ({ data: items, meta: { next_cursor: null, per_page: 200 } });
export const problem = (status: number, detail: string, extra: Record<string, unknown> = {}) =>
  jsonResponse({ type: 'https://api.scholaris.cm/errors/x', title: 'Erreur', status, detail, trace_id: 'TR-HT', ...extra }, status);

const hiMe = { application: { key: 'higher', state: 'active', writable: true }, establishment_id: 'E1', roles: [], all_schools: true, school_ids: [], school_selector: false };
const base: Record<string, unknown> = {
  'GET /me/applications': { data: [{ app_key: 'higher', state: 'active', establishments: [{ id: 'E1' }] }] },
  'GET /higher/me': { data: hiMe },
  'GET /higher/groups': page([{ id: 'G1', school_id: 'S1', program_id: 'PR1', level_id: 'L1', code: 'G1', name: 'Groupe A', capacity: 40 }]),
  'GET /higher/levels': page([{ id: 'L1', school_id: 'S1', department_id: 'D1', program_id: 'PR1', ordinal: 1, code: 'L1', name: 'Licence 1' }]),
  'GET /higher/departments': page([]),
  'GET /higher/schools': page([{ id: 'S1', code: 'ESG', name: 'École de gestion', director_person_id: null }]),
  'GET /academic-years': page([{ id: 'Y1', label: '2026-2027' }]),
  'GET /higher/teaching/rooms': page([{ id: 'R1', school_id: 'S1', code: 'A101', name: 'Amphi A', capacity: 120, active: true }]),
};

/** Connecte une personne dans un IPES avec ces permissions et simule l'API (routes de base + routes du test). */
export function setup(perms: string[], routes: Record<string, unknown> = {}, me: Me = teacherMe): RecordedCall[] {
  signIn(me, [tile({ type: 'higher', permissions: perms })]);
  return mockApi({ ...base, ...routes } as Parameters<typeof mockApi>[0]);
}

export const find = (calls: RecordedCall[], method: string, path: string): RecordedCall | undefined => calls.find((c) => c.method === method && c.path === path);

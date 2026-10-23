import { http } from '@/api/http';

export interface PublicNurseryObservation {
  learner_id: string;
  structure_id: string;
  observer_id: string;
  frame_id: string;
  domain_id: string;
  item_id: string;
  level_code: 'acquired' | 'in_progress' | 'not_yet';
  remark?: string | null;
  observed_on?: string;
  client_uuid?: string;
}

export interface PublicNurseryLifeBookMediaDraft {
  content: string;
  mime_type: 'image/jpeg' | 'image/png';
  tagging_done: boolean;
  no_child_visible: boolean;
  child_ids: string[];
  masked_from_id?: string | null;
}

export interface PublicNurseryLifeBookDraft {
  structure_id: string;
  title: string;
  body?: string | null;
  activity_on: string;
  media?: PublicNurseryLifeBookMediaDraft[];
}

export interface PublicNurseryTransitionDecision {
  id: string;
  learner_id: string;
  school_year: string;
  from_level: 'ps' | 'ms' | 'gs';
  proposal: 'promote' | 'to_primary';
  decision: 'promote' | 'to_primary' | 'retain' | null;
  educator_opinion: string | null;
  status: string;
  parent_request_ref?: string | null;
  inspector_opinion?: string | null;
}

export interface PublicNurseryLiaisonSheet {
  learner_id: string;
  school_year: string;
  path_summary: string | null;
  strengths: string | null;
  needs: string | null;
  status: string;
  html: string;
}

export function createObservation(body: PublicNurseryObservation): Promise<{ data: Record<string, unknown> }> {
  return http.post('/public-nursery/sync/observations', body);
}

export function listLifeBooks(structureId?: string): Promise<{ data: Array<Record<string, unknown>> }> {
  return http.get('/public-nursery/development/lifebook', structureId ? { structure_id: structureId } : {});
}

export function createLifeBookDraft(body: PublicNurseryLifeBookDraft): Promise<{ data: Record<string, unknown> }> {
  return http.post('/public-nursery/development/lifebook', body);
}

export function publishLifeBook(id: string): Promise<{ data: Record<string, unknown> }> {
  return http.post(`/public-nursery/development/lifebook/${id}/publish`);
}

export function addLifeBookMedia(id: string, body: PublicNurseryLifeBookMediaDraft): Promise<{ data: Record<string, unknown> }> {
  return http.post(`/public-nursery/development/lifebook/${id}/media`, body);
}

export function listTransitionDecisions(schoolYear: string): Promise<{ data: PublicNurseryTransitionDecision[] }> {
  return http.get('/public-nursery/transition/decisions', { school_year: schoolYear });
}

export function proposeTransitionDecisions(body: { academic_year_id: string; next_year_id: string }): Promise<{ data: { status: string; summary: string; counts: Record<string, number> } }> {
  return http.post('/public-nursery/transition/decisions/propose', body);
}

export function decideTransition(id: string, body: Record<string, string | null>): Promise<{ data: PublicNurseryTransitionDecision }> {
  return http.post(`/public-nursery/transition/decisions/${id}/decide`, body);
}

export function loadLiaisonSheet(learnerId: string, schoolYear: string): Promise<{ data: PublicNurseryLiaisonSheet }> {
  return http.get('/public-nursery/transition/liaison', { learner_id: learnerId, school_year: schoolYear });
}

export function createLiaisonSheet(body: { learner_id: string; school_year: string }): Promise<{ data: PublicNurseryLiaisonSheet }> {
  return http.post('/public-nursery/transition/liaison', body);
}

export function signLiaisonSheet(body: { learner_id: string; school_year: string }): Promise<{ data: PublicNurseryLiaisonSheet }> {
  return http.post('/public-nursery/transition/liaison/sign', body);
}
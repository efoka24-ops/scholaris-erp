import type { Workflow, WorkflowActor, WorkflowLock, WorkflowOverrideStep, WorkflowStep } from '@/api/workflows';

/** Valeurs en cours de saisie pour une étape. */
export interface StepDraft {
  actor: string;
  enabled: boolean;
  recent_confirmation: boolean;
  enforced: boolean;
}

export type Draft = Record<string, StepDraft>;

/** Brouillon de départ : les valeurs actuellement appliquées, et les étapes déjà imposées par ce niveau. */
export function draftFrom(workflow: Workflow): Draft {
  const out: Draft = {};
  for (const step of workflow.steps) {
    out[step.key] = {
      actor: step.effective.actor,
      enabled: step.effective.enabled,
      recent_confirmation: step.effective.recent_confirmation,
      enforced: workflow.override?.steps[step.key]?.enforced === true,
    };
  }
  return out;
}

/**
 * Corps du PUT : uniquement les écarts. Un champ est envoyé s'il a été changé par la personne ou s'il faisait déjà partie du
 * réglage de ce niveau ; il est retiré s'il revient à la valeur par défaut (sauf étape imposée, qui fige sa valeur).
 */
export function buildPayload(workflow: Workflow, draft: Draft): Record<string, WorkflowOverrideStep> {
  const initial = draftFrom(workflow);
  const payload: Record<string, WorkflowOverrideStep> = {};
  for (const step of workflow.steps) {
    if (step.imposed_by !== null) continue;
    const now = draft[step.key];
    const before = initial[step.key];
    const stored = workflow.override?.steps[step.key] ?? {};
    if (!now || !before) continue;
    const enforced = now.enforced;
    const out: WorkflowOverrideStep = {};
    const keep = (field: 'actor' | 'enabled' | 'recent_confirmation'): boolean =>
      enforced || (now[field] !== before[field] || field in stored) && now[field] !== step.default[field];
    if (step.editable.actor && keep('actor')) out.actor = now.actor;
    if (step.editable.enabled && keep('enabled')) out.enabled = now.enabled;
    if (step.editable.recent_confirmation && keep('recent_confirmation')) out.recent_confirmation = now.recent_confirmation;
    if (enforced && Object.keys(out).length === 0) {
      // Imposer sans rien changer : on fige la valeur actuelle de la fonction.
      out.actor = now.actor;
    }
    if (enforced) out.enforced = true;
    if (Object.keys(out).length > 0) payload[step.key] = out;
  }
  return payload;
}

export function actorLabel(actors: WorkflowActor[], key: string, locale: string): string {
  const actor = actors.find((a) => a.key === key);
  return actor ? (locale === 'en' ? actor.label_en : actor.label_fr) : key;
}

export const stepLabel = (step: WorkflowStep, locale: string): string => (locale === 'en' ? step.label_en : step.label_fr);
export const lockReason = (lock: WorkflowLock, locale: string): string => (locale === 'en' ? lock.reason_en : lock.reason_fr);

/** Verrous à montrer sous une étape (hors règles de circuit). */
export const stepLocks = (step: WorkflowStep): WorkflowLock[] => step.locks;

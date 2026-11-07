// Circuits de validation configurables (permission workflows.manage, ou plateforme depuis la fiche d'un client).
// Les valeurs par défaut sont celles de l'application ; seules des modifications dans les limites déclarées sont acceptées.
import { http } from './http';

export interface WorkflowActor {
  key: string;
  label_fr: string;
  label_en: string;
  permission: string;
  role: string | null;
}

export interface WorkflowLock {
  aspect: 'actor' | 'enabled' | 'confirmation' | 'circuit' | 'step';
  code: string;
  reason_fr: string;
  reason_en: string;
}

export type WorkflowSource = 'default' | 'platform' | 'client' | 'establishment';

export interface WorkflowStepValues {
  actor: string;
  enabled: boolean;
  recent_confirmation: boolean;
}

export interface WorkflowStep {
  key: string;
  label_fr: string;
  label_en: string;
  optional: boolean;
  default: WorkflowStepValues;
  effective: WorkflowStepValues & { source: WorkflowSource; enforced_by: 'platform' | 'client' | null };
  actors: WorkflowActor[];
  editable: { actor: boolean; enabled: boolean; recent_confirmation: boolean };
  imposed_by: 'platform' | 'client' | null;
  locks: WorkflowLock[];
}

export interface WorkflowOverrideStep {
  actor?: string;
  enabled?: boolean;
  recent_confirmation?: boolean;
  enforced?: boolean;
}

export interface Workflow {
  id: string;
  application: string;
  key: string;
  label_fr: string;
  label_en: string;
  level: 'establishment' | 'client' | 'platform';
  customised: boolean;
  steps: WorkflowStep[];
  invariants: WorkflowLock[];
  override: { version: number; reason: string; updated_by: string; updated_at: string; steps: Record<string, WorkflowOverrideStep> } | null;
}

export interface WorkflowChange {
  version: number;
  kind: 'set' | 'reset';
  before: { steps?: Record<string, WorkflowOverrideStep> } | null;
  after: { steps?: Record<string, WorkflowOverrideStep> } | null;
  reason: string;
  changed_by: string;
  changed_at: string;
}

/** Portée : l'établissement actif (administrateur) ou un client (super administrateur de la plateforme, sans en-tête d'établissement). */
export type WorkflowScope = { kind: 'establishment' } | { kind: 'client'; tenant: string };

const seg = (value: string): string => encodeURIComponent(value);

function base(scope: WorkflowScope): { path: string; opts: { establishmentId?: false } } {
  return scope.kind === 'client'
    ? { path: `/platform/clients/${seg(scope.tenant)}/workflows`, opts: { establishmentId: false } }
    : { path: '/workflows', opts: {} };
}

export const workflowsApi = {
  list: (scope: WorkflowScope): Promise<Workflow[]> => {
    const b = base(scope);
    return http.all<Workflow>(b.path, {}, b.opts);
  },

  async save(scope: WorkflowScope, w: Pick<Workflow, 'application' | 'key'>, steps: Record<string, WorkflowOverrideStep>, reason: string): Promise<Workflow> {
    const b = base(scope);
    return (await http.put<{ data: Workflow }>(`${b.path}/${seg(w.application)}/${seg(w.key)}`, { steps, reason }, b.opts)).data;
  },

  async reset(scope: WorkflowScope, w: Pick<Workflow, 'application' | 'key'>, reason: string): Promise<Workflow> {
    const b = base(scope);
    // Le motif voyage en paramètre de requête : DELETE sans corps.
    return (await http.delete<{ data: Workflow }>(`${b.path}/${seg(w.application)}/${seg(w.key)}?reason=${encodeURIComponent(reason)}`, b.opts)).data;
  },

  async history(scope: WorkflowScope, w: Pick<Workflow, 'application' | 'key'>): Promise<WorkflowChange[]> {
    const b = base(scope);
    return (await http.get<{ data: WorkflowChange[] }>(`${b.path}/${seg(w.application)}/${seg(w.key)}/history`, undefined, b.opts)).data;
  },
};

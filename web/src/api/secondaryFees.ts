// API des frais du secondaire (préfixe /api/v1/secondary/fees). Contrat : openapi.d/secondary-fees.yaml. Les grilles, factures et
// paiements restent dans le socle Billing (/fee-items, /fee-schedules, /invoices…) ; le serveur reste juge des droits et des modules.
import { http } from '@/api/http';

const ROOT = '/secondary/fees';

export type FeesMode = 'private' | 'public_catalog' | 'none';
export type PlanStatus = 'pending' | 'approved' | 'refused' | 'cancelled';
export type RequestStatus = 'pending' | 'accepted' | 'refused';

export interface FeesOverview {
  mode: FeesMode;
  kinds: string[];
  refused_kinds: string[];
  blocked_document_types: string[];
  allocation_rule: string;
  allocation_kind_order: string[] | null;
  allocation_sibling_order: string | null;
  reminder_offsets: number[] | null;
  presets: Record<string, unknown>;
  confirmation: string;
}
export interface Installment {
  due_on: string;
  amount: number;
}
export interface PaymentPlan {
  id: string;
  person_id: string;
  invoice_id: string | null;
  installments: Installment[];
  total_amount: number;
  due_to_date: number;
  ends_on: string | null;
  reason: string;
  status: PlanStatus;
  in_force: boolean;
  requested_by: string;
  approved_by: string | null;
  decision_reason: string | null;
}
export interface CatalogEntry {
  id: string;
  client_wide: boolean;
  fee_kind: string;
  fee_code: string | null;
  label: string;
  max_amount: number | null;
  cycle_code: string | null;
  legal_basis: string | null;
  valid_from: string;
  valid_to: string | null;
}
export interface FeeRequest {
  id: string;
  fee_kind: string;
  fee_code: string | null;
  label: string;
  amount: number;
  justification: string | null;
  origin: string;
  requested_by: string;
  status: RequestStatus;
  decision_reason: string | null;
  created_at: string;
}
export interface PlanInput {
  person_id: string;
  invoice_id?: string | null;
  reason: string;
  installments: Installment[];
}
export interface CatalogInput {
  fee_kind: string;
  label: string;
  legal_basis: string;
  max_amount?: number | null;
  valid_from?: string;
  client_wide?: boolean;
}
export interface RequestInput {
  fee_kind: string;
  label: string;
  amount: number;
  justification: string;
}

type One<T> = { data: T };
type Many<T> = { data: T[] };

/** Frais facturé du socle (/fee-items) ; la garde du secondaire refuse au public un frais absent du catalogue (RG-FIN-2). */
export interface FeeItem {
  id: string;
  kind: string;
  code?: string;
  name: string;
  amount_xaf: number;
  active: boolean | number;
}
export interface FeeItemInput {
  kind: string;
  name: string;
  amount_xaf: number;
  code?: string;
}

export const secondaryFees = {
  feeItems: async (): Promise<FeeItem[]> => http.all<FeeItem>('/fee-items'),
  addFeeItem: async (input: FeeItemInput): Promise<FeeItem> => (await http.post<One<FeeItem>>('/fee-items', input)).data,
  overview: async (): Promise<FeesOverview> => (await http.get<One<FeesOverview>>(`${ROOT}/overview`)).data,
  setupPrivate: async (): Promise<Record<string, { value: unknown; applied: boolean }>> =>
    (await http.post<One<{ presets: Record<string, { value: unknown; applied: boolean }> }>>(`${ROOT}/private/setup`, {})).data.presets,
  plans: async (status?: PlanStatus): Promise<PaymentPlan[]> =>
    (await http.get<Many<PaymentPlan>>(`${ROOT}/payment-plans`, status ? { 'filter[status]': status } : {})).data,
  proposePlan: async (input: PlanInput): Promise<PaymentPlan> => (await http.post<One<PaymentPlan>>(`${ROOT}/payment-plans`, input)).data,
  approvePlan: async (id: string): Promise<PaymentPlan> => (await http.post<One<PaymentPlan>>(`${ROOT}/payment-plans/${encodeURIComponent(id)}/approve`, {})).data,
  refusePlan: async (id: string, reason: string): Promise<PaymentPlan> =>
    (await http.post<One<PaymentPlan>>(`${ROOT}/payment-plans/${encodeURIComponent(id)}/refuse`, { reason })).data,
  cancelPlan: async (id: string, reason: string): Promise<PaymentPlan> =>
    (await http.post<One<PaymentPlan>>(`${ROOT}/payment-plans/${encodeURIComponent(id)}/cancel`, { reason })).data,
  catalog: async (): Promise<CatalogEntry[]> => (await http.get<Many<CatalogEntry>>(`${ROOT}/catalog`)).data,
  addCatalog: async (input: CatalogInput): Promise<CatalogEntry> => (await http.post<One<CatalogEntry>>(`${ROOT}/catalog`, input)).data,
  retireCatalog: async (id: string, validTo: string, reason: string): Promise<CatalogEntry> =>
    (await http.post<One<CatalogEntry>>(`${ROOT}/catalog/${encodeURIComponent(id)}/retire`, { valid_to: validTo, reason })).data,
  requests: async (status?: RequestStatus): Promise<FeeRequest[]> => (await http.get<Many<FeeRequest>>(`${ROOT}/requests`, status ? { status } : {})).data,
  request: async (input: RequestInput): Promise<FeeRequest> => (await http.post<One<FeeRequest>>(`${ROOT}/requests`, input)).data,
  decide: async (id: string, decision: 'accepted' | 'refused', reason: string, legalBasis?: string, maxAmount?: number | null): Promise<FeeRequest> =>
    (await http.post<One<FeeRequest>>(`${ROOT}/requests/${encodeURIComponent(id)}/decide`, {
      decision,
      reason,
      ...(legalBasis ? { legal_basis: legalBasis } : {}),
      ...(maxAmount !== undefined && maxAmount !== null ? { max_amount: maxAmount } : {}),
    })).data,
};

/** Somme des échéances (entiers XAF). */
export function planTotal(installments: readonly Installment[]): number {
  return installments.reduce((sum, i) => sum + (Number.isInteger(i.amount) ? i.amount : 0), 0);
}

/** Échéances valides : au moins une, montants entiers positifs, dates AAAA-MM-JJ strictement croissantes. */
export function validInstallments(installments: readonly Installment[]): boolean {
  if (installments.length === 0) return false;
  let previous = '';
  for (const i of installments) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(i.due_on) || i.due_on <= previous || !Number.isInteger(i.amount) || i.amount < 1) return false;
    previous = i.due_on;
  }
  return true;
}

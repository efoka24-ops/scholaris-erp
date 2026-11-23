// API des portails élève et parent du secondaire (préfixe /api/v1/secondary/portal). Contrat : openapi.d/secondary-portals.yaml.
// Le serveur ne rend que l'élève connecté ou les enfants du parent connecté ; le matricule n'est jamais transmis (booléen seulement).
import { http } from '@/api/http';
import type { IssuedSecondaryDocument } from '@/api/secondaryDocuments';

const ROOT = '/secondary/portal';

export interface PortalInvoice {
  id: string;
  reference: string | null;
  /** Frais facturés (FIX-SEC) : nom lisible de la facture. */
  label?: string | null;
  net_xaf: number;
  paid_xaf: number;
  balance_xaf: number;
  installments: Array<{ label: string; due_on: string; amount_xaf: number; paid_xaf: number }>;
}
export interface StudentCard {
  person: { id: string; name: string };
  access: 'full' | 'restricted';
  enrollment: {
    id: string;
    status: string;
    class: { id: string; name: string } | null;
    level: { id: string; name: string } | null;
    academic_year: string;
  };
  national_id_registered: boolean;
  balance_xaf: number;
  fees_mode: 'fees_private' | 'fees_public_catalog' | null;
  documents?: { blocked: boolean; items: IssuedSecondaryDocument[] };
  invoices?: PortalInvoice[];
}
export interface FamilyPortal {
  guardian_id: string;
  can_pay: boolean;
  family_balance_xaf: number;
  /** D-BUL : référence de paiement famille du parent connecté (null tant qu’elle n’est pas émise). */
  payment_reference?: string | null;
  children: StudentCard[];
}
export type PortalChannel = 'orange_money' | 'mtn_momo';
export interface PortalPayment {
  id: string;
  status: 'pending' | 'confirmed' | 'failed' | 'reversed';
  amount_xaf: number;
  channel: string;
  invoice_id: string | null;
  receipt_number: string | null;
  child_id: string;
  replayed: boolean;
}
export interface PayInput {
  invoice_id: string;
  amount_xaf: number;
  channel: PortalChannel;
  payer_phone: string;
  idempotency_key: string;
}

export const secondaryPortals = {
  student: async (): Promise<StudentCard> => (await http.get<{ data: StudentCard }>(`${ROOT}/student`)).data,
  family: async (): Promise<FamilyPortal> => (await http.get<{ data: FamilyPortal }>(`${ROOT}/family`)).data,
  pay: async (childId: string, input: PayInput): Promise<PortalPayment> =>
    (await http.post<{ data: PortalPayment }>(`${ROOT}/family/children/${encodeURIComponent(childId)}/payments`, input)).data,
  payment: async (childId: string, paymentId: string): Promise<PortalPayment> =>
    (await http.get<{ data: PortalPayment }>(`${ROOT}/family/children/${encodeURIComponent(childId)}/payments/${encodeURIComponent(paymentId)}`)).data,
};

/** Téléphone mobile accepté par le serveur : 8 à 15 chiffres, « + » initial facultatif, espaces, points et tirets ignorés. */
export function validPhone(raw: string): boolean {
  return /^\+?\d{8,15}$/.test(raw.replace(/[\s.-]/g, ''));
}

/** Nom d'une facture pour la famille (FIX-SEC) : ses frais, sinon sa référence ; jamais son identifiant interne. */
export function invoiceTitle(invoice: Pick<PortalInvoice, 'label' | 'reference'>, fallback: string): string {
  const label = invoice.label?.trim();
  if (label) return label;
  const reference = invoice.reference?.trim();
  return reference ? reference : fallback;
}

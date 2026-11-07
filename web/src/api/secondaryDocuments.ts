// API des documents du secondaire (préfixe /api/v1/secondary/documents). Contrat : openapi.d/secondary-documents.yaml.
// Numéro, QR et contenu figé viennent du socle Documents ; la vérification publique se fait par /verification/:token.
import { http } from '@/api/http';

const ROOT = '/secondary/documents';

export type DocumentKind =
  | 'preregistration' | 'enrolment_form' | 'school_certificate' | 'school_card' | 'exeat' | 'class_list' | 'receipt' | 'account_statement'
  | 'payment_attestation';
export type DocumentSource = 'enrolment' | 'class' | 'invoice' | 'caller' | 'socle';

export interface DocumentKindInfo {
  doc: string;
  kind: DocumentKind;
  type: string | null;
  label_fr: string;
  label_en: string;
  qr: boolean;
  source: DocumentSource;
  module: string | null;
  available: boolean;
  direct: boolean;
  can_issue: boolean;
}
export interface IssuedSecondaryDocument {
  id: string;
  number: string;
  kind: DocumentKind;
  doc: string;
  type: string;
  status: string;
  qr: boolean;
  verification_token: string;
  locale?: 'fr' | 'en';
  subject_person_id?: string;
  enrollment_id?: string;
  class_id?: string;
  invoice_id?: string;
}
export interface IssueInput {
  kind: DocumentKind;
  enrollment_id?: string;
  class_id?: string;
  invoice_id?: string;
  destination?: string;
  reason?: string;
  effective_on?: string;
  locale?: 'fr' | 'en';
}

export const secondaryDocuments = {
  kinds: async (): Promise<DocumentKindInfo[]> => (await http.get<{ data: { items: DocumentKindInfo[] } }>(`${ROOT}/kinds`)).data.items,
  issue: async (input: IssueInput): Promise<IssuedSecondaryDocument> => (await http.post<{ data: IssuedSecondaryDocument }>(ROOT, input)).data,
  issuedFor: async (personId: string): Promise<IssuedSecondaryDocument[]> =>
    (await http.get<{ data: { items: IssuedSecondaryDocument[] } }>(`${ROOT}/issued`, { person_id: personId })).data.items,
};

/** Champs à demander selon la source de la nature (aucune saisie libre hors exeat). */
export function requiredFields(kind: DocumentKindInfo): Array<'enrollment_id' | 'class_id' | 'invoice_id' | 'destination' | 'reason' | 'effective_on'> {
  if (kind.source === 'class') return ['class_id'];
  if (kind.source === 'invoice') return ['invoice_id'];
  return kind.kind === 'exeat' ? ['enrollment_id', 'destination', 'reason', 'effective_on'] : ['enrollment_id'];
}

/** Nature émise par l'écran : connue, émise par cette route, module actif et droit présent. */
export function issuable(kind: DocumentKindInfo): boolean {
  return kind.direct && kind.available && kind.can_issue;
}

export interface DocumentCandidate {
  enrollment_id: string;
  person_id: string;
  name: string;
  status: string;
  internal_number: string | null;
}
/** Classe du secondaire telle que le paramétrage la rend (GET /secondary/setup/classes) : `structure_id` = classe du socle. */
export interface SecondaryClassRow {
  id: string;
  structure_id: string;
  label: string;
  level_code: string;
  academic_year_id: string;
}

export const secondaryDocumentPickers = {
  classes: (): Promise<SecondaryClassRow[]> => http.all<SecondaryClassRow>('/secondary/setup/classes'),
  candidates: async (classId: string): Promise<DocumentCandidate[]> =>
    (await http.get<{ data: { items: DocumentCandidate[] } }>(`${ROOT}/candidates`, { class_id: classId })).data.items,
};

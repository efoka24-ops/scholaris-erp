export type Channel = "EMAIL" | "SMS" | "WHATSAPP" | "PUSH" | "INTERNAL";

export const CHANNEL_LABELS: Record<Channel, string> = {
  EMAIL: "Email",
  SMS: "SMS",
  WHATSAPP: "WhatsApp",
  PUSH: "Notification push",
  INTERNAL: "Message interne",
};

export interface CommunicationTemplate {
  id: string;
  code: string;
  name: string;
  channel: Channel;
  subjectFr: string | null;
  subjectEn: string | null;
  bodyFr: string;
  bodyEn: string | null;
  createdAt: string;
}

export interface CommunicationTemplateInput {
  code: string;
  name: string;
  channel: Channel;
  subjectFr?: string;
  subjectEn?: string;
  bodyFr: string;
  bodyEn?: string;
}

/** Variables dynamiques disponibles dans le corps des templates (usage libre {variable}). */
export const TEMPLATE_VARIABLES = [
  "{nom_eleve}",
  "{prenom_eleve}",
  "{matricule}",
  "{classe}",
  "{date_echeance}",
  "{montant}",
  "{nom_parent}",
  "{etablissement}",
] as const;

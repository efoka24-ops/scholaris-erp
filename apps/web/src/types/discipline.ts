export type IncidentSeverity = "LOW" | "MEDIUM" | "HIGH" | "CRITICAL";

export const SEVERITY_LABELS: Record<IncidentSeverity, string> = {
  LOW: "Faible",
  MEDIUM: "Moyenne",
  HIGH: "Élevée",
  CRITICAL: "Critique",
};

/** Types d'incidents usuels — champ libre côté API, valeurs proposées ici pour la saisie. */
export const INCIDENT_TYPES = [
  "RETARD",
  "ABSENCE_INJUSTIFIEE",
  "INSOLENCE",
  "BAGARRE",
  "TRICHERIE",
  "AUTRE",
] as const;
export type IncidentType = (typeof INCIDENT_TYPES)[number];

export const INCIDENT_TYPE_LABELS: Record<IncidentType, string> = {
  RETARD: "Retard",
  ABSENCE_INJUSTIFIEE: "Absence injustifiée",
  INSOLENCE: "Insolence",
  BAGARRE: "Bagarre",
  TRICHERIE: "Tricherie",
  AUTRE: "Autre",
};

/** Types de sanctions usuels — champ libre côté API. */
export const SANCTION_TYPES = ["WARNING", "DETENTION", "SUSPENSION", "EXPULSION"] as const;
export type SanctionType = (typeof SANCTION_TYPES)[number];

export const SANCTION_TYPE_LABELS: Record<SanctionType, string> = {
  WARNING: "Avertissement",
  DETENTION: "Retenue",
  SUSPENSION: "Suspension",
  EXPULSION: "Exclusion",
};

export interface Sanction {
  id: string;
  incidentId: string;
  type: string;
  description: string;
  startDate: string | null;
  endDate: string | null;
  createdAt: string;
}

export interface Incident {
  id: string;
  studentId: string;
  incidentDate: string;
  type: string;
  severity: IncidentSeverity;
  description: string;
  location: string | null;
  createdAt: string;
  student?: { id: string; firstName: string; lastName: string; matricule: string };
  sanctions?: Sanction[];
}

export interface CreateIncidentInput {
  studentId: string;
  incidentDate: string;
  type: string;
  severity: IncidentSeverity;
  description: string;
  location?: string;
}

export interface CreateSanctionInput {
  incidentId: string;
  type: string;
  description: string;
  startDate?: string;
  endDate?: string;
}

export interface FindIncidentsQuery {
  page?: number;
  limit?: number;
  severity?: IncidentSeverity;
}

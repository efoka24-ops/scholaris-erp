import type { ClassRoom, Level } from "./structure";

export type Gender = "MALE" | "FEMALE";
export type StudentStatus = "ACTIVE" | "SUSPENDED" | "GRADUATED" | "EXCLUDED" | "ABANDONED";
export type ParentRelationship = "FATHER" | "MOTHER" | "GUARDIAN";
export type EnrollmentType = "NEW" | "RE_ENROLLMENT" | "TRANSFER";
export type EnrollmentStatus = "PENDING" | "ACTIVE" | "CANCELLED";
export type EnrollmentRegime = "EXTERNAL" | "HALF_BOARD" | "BOARDING";
export type AdmissionType = "EXAM" | "DOSSIER" | "DIRECT";
export type AdmissionStatus = "PENDING" | "ACCEPTED" | "REJECTED" | "WAITLISTED";

export interface AcademicYear {
  id: string;
  label: string;
  startDate: string;
  endDate: string;
  status: "ACTIVE" | "CLOSED" | "ARCHIVED";
}

export interface Parent {
  id: string;
  firstName: string;
  lastName: string;
  phone: string;
  whatsapp: string | null;
  email: string | null;
  profession: string | null;
  address: string | null;
  relationship: ParentRelationship;
}

export interface StudentParentLink {
  studentId: string;
  parentId: string;
  relationship: ParentRelationship;
  parent: Parent;
}

export interface Enrollment {
  id: string;
  studentId: string;
  classroomId: string;
  academicYearId: string;
  enrollmentDate: string;
  type: EnrollmentType;
  status: EnrollmentStatus;
  regime: EnrollmentRegime;
  isRepeater: boolean;
  previousSchool: string | null;
  previousAverage: number | null;
  classroom?: ClassRoom & { level?: Level };
  academicYear?: AcademicYear;
}

export interface Student {
  id: string;
  matricule: string;
  firstName: string;
  lastName: string;
  dateOfBirth: string;
  placeOfBirth: string | null;
  gender: Gender;
  nationality: string;
  photoUrl: string | null;
  bloodGroup: string | null;
  allergies: string | null;
  handicap: string | null;
  emergencyContact: string | null;
  status: StudentStatus;
  enrollments?: Enrollment[];
  parents?: StudentParentLink[];
}

export interface AdmissionApplication {
  id: string;
  applicantName: string;
  applicantInfo: Record<string, unknown> | null;
  type: AdmissionType;
  score: number | null;
  rank: number | null;
  status: AdmissionStatus;
  academicYearId: string;
  academicYear?: AcademicYear;
  createdAt: string;
}

export interface ImportReport {
  created: number;
  duplicates: number;
  enrolled?: number;
  classesCreated?: string[];
  errors: Array<{ row: number; message: string }>;
}

export interface ReEnrollReport {
  reEnrolled: number;
  failed: Array<{ studentId: string; studentName: string; reason: string }>;
}

export const STUDENT_STATUS_LABELS: Record<StudentStatus, string> = {
  ACTIVE: "Actif",
  SUSPENDED: "Suspendu",
  GRADUATED: "Diplômé",
  EXCLUDED: "Exclu",
  ABANDONED: "Abandon",
};

export const RELATIONSHIP_LABELS: Record<ParentRelationship, string> = {
  FATHER: "Père",
  MOTHER: "Mère",
  GUARDIAN: "Tuteur",
};

export const ENROLLMENT_STATUS_LABELS: Record<EnrollmentStatus, string> = {
  PENDING: "En attente",
  ACTIVE: "Active",
  CANCELLED: "Annulée",
};

export const ENROLLMENT_TYPE_LABELS: Record<EnrollmentType, string> = {
  NEW: "Nouvelle inscription",
  RE_ENROLLMENT: "Réinscription",
  TRANSFER: "Transfert",
};

export const REGIME_LABELS: Record<EnrollmentRegime, string> = {
  EXTERNAL: "Externe",
  HALF_BOARD: "Demi-pensionnaire",
  BOARDING: "Interne",
};

export const ADMISSION_TYPE_LABELS: Record<AdmissionType, string> = {
  EXAM: "Concours",
  DOSSIER: "Étude de dossier",
  DIRECT: "Admission directe",
};

export const ADMISSION_STATUS_LABELS: Record<AdmissionStatus, string> = {
  PENDING: "En attente",
  ACCEPTED: "Acceptée",
  REJECTED: "Refusée",
  WAITLISTED: "Liste d'attente",
};

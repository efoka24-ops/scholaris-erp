export type GradeType = "TEST" | "HOMEWORK" | "EXAM" | "RESIT";
export type AnnualDecision = "PASS" | "REPEAT" | "EXCLUDE";

export const GRADE_TYPE_LABELS: Record<GradeType, string> = {
  TEST: "Devoir",
  HOMEWORK: "Interrogation",
  EXAM: "Examen",
  RESIT: "Rattrapage",
};

export const ANNUAL_DECISION_LABELS: Record<AnnualDecision, string> = {
  PASS: "Admis(e) en classe supérieure",
  REPEAT: "Redouble",
  EXCLUDE: "Exclu(e)",
};

export const DELIBERATION_DECISIONS = ["ADMIS", "AVERTISSEMENT", "BLAME", "EXCLUSION_TEMPORAIRE", "EXCLUSION_DEFINITIVE"] as const;

export const DELIBERATION_DECISION_LABELS: Record<(typeof DELIBERATION_DECISIONS)[number], string> = {
  ADMIS: "Admis(e)",
  AVERTISSEMENT: "Avertissement",
  BLAME: "Blâme",
  EXCLUSION_TEMPORAIRE: "Exclusion temporaire",
  EXCLUSION_DEFINITIVE: "Exclusion définitive",
};

export interface Grade {
  id: string;
  studentId: string;
  subjectId: string | null;
  courseElementId: string | null;
  periodId: string;
  teacherId: string;
  type: GradeType;
  value: string | number | null;
  maxValue: string | number;
  weight: string | number;
  date: string;
  comment: string | null;
  isAbsent: boolean;
  isJustified: boolean;
  isLocked: boolean;
  subject?: { id: string; code: string; name: string } | null;
  courseElement?: { id: string; code: string; name: string } | null;
  period?: { id: string; type: string; number: number };
}

export interface PeriodResult {
  id: string;
  studentId: string;
  periodId: string;
  classroomId: string;
  generalAverage: string | number;
  rank: number | null;
  totalStudents: number | null;
  mention: string | null;
  decision: string | null;
  observations: string | null;
  teacherComment: string | null;
  isPublished: boolean;
  student?: { id: string; firstName: string; lastName: string; matricule: string };
  period?: { id: string; type: string; number: number };
}

export interface AnnualResult {
  id: string;
  studentId: string;
  classroomId: string;
  academicYearId: string;
  annualAverage: string | number;
  rank: number | null;
  mention: string | null;
  decision: AnnualDecision | null;
  creditsValidated: number | null;
  gpa: string | number | null;
}

export interface GradeCalculation {
  id: string;
  studentId: string;
  periodId: string;
  subjectId: string | null;
  courseElementId: string | null;
  classroomId: string;
  calculatedAverage: string | number;
  coefficient: string | number;
  weightedTotal: string | number;
  rank: number | null;
  subject?: { id: string; code: string; name: string } | null;
  courseElement?: { id: string; code: string; name: string } | null;
}

export interface ProgressSubject {
  subjectId: string | null;
  courseElementId: string | null;
  label: string;
  teacherId: string;
  studentsGraded: number;
  totalStudents: number;
  isComplete: boolean;
}

export interface ProgressClassroom {
  classroomId: string;
  classroomName: string;
  totalStudents: number;
  subjects: ProgressSubject[];
}

export interface ProgressReport {
  periodId: string;
  classrooms: ProgressClassroom[];
}

export interface ImportGradesReport {
  created: number;
  skipped: number;
  errors: Array<{ row: number; message: string }>;
}

export interface StudentGradeHistory {
  student: { id: string; firstName: string; lastName: string; matricule: string };
  grades: Grade[];
  periodResults: PeriodResult[];
  annualResults: AnnualResult[];
}

/** Couleur de la note : rouge < 10, orange [10,12[, vert ≥ 12 (barème /20 équivalent). */
export function gradeColorClass(value: number | null, maxValue: number): string {
  if (value === null) {
    return "text-muted-foreground";
  }
  const on20 = (value / maxValue) * 20;
  if (on20 < 10) {
    return "text-destructive font-medium";
  }
  if (on20 < 12) {
    return "text-orange-500 font-medium";
  }
  return "text-primary font-medium";
}

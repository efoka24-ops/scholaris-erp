import type { SubjectCategoryValue } from "@scholaris/shared";

export interface Subject {
  id: string;
  code: string;
  name: string;
  // Prisma Decimal sérialisé en chaîne par l'API.
  coefficient: string | number;
  weeklyHours: number;
  category: SubjectCategoryValue;
  isEliminatory: boolean;
  eliminatoryThreshold: string | number;
  levelIds: string[];
}

export interface TeachingUnit {
  id: string;
  code: string;
  name: string;
  credits: number;
  semester: number;
  isFundamental: boolean;
  departmentId: string;
  department?: { id: string; code: string; name: string };
  courseElements: CourseElement[];
}

export interface CourseElement {
  id: string;
  code: string;
  name: string;
  credits: number;
  hoursCm: number;
  hoursTd: number;
  hoursTp: number;
  coefficient: string | number;
  teachingUnitId: string;
}

export interface TeacherOption {
  id: string;
  firstName: string;
  lastName: string;
  email: string;
}

export interface SubjectAssignment {
  id: string;
  subjectId: string | null;
  courseElementId: string | null;
  teacherId: string;
  classroomId: string;
  academicYearId: string;
  subject: { id: string; code: string; name: string } | null;
  courseElement: { id: string; code: string; name: string } | null;
  teacher: TeacherOption;
  classroom: { id: string; code: string; name: string };
  academicYear: { id: string; label: string };
}

export interface AcademicYearOption {
  id: string;
  label: string;
  status: string;
}

export interface DepartmentOption {
  id: string;
  code: string;
  name: string;
}

export type AcademicYearStatus = "ACTIVE" | "CLOSED" | "ARCHIVED";
export type PeriodType = "SEQUENCE" | "TRIMESTER" | "SEMESTER";
export type GradingStatus = "CLOSED" | "OPEN" | "LOCKED";

export interface Period {
  id: string;
  academicYearId: string;
  type: PeriodType;
  number: number;
  startDate: string;
  endDate: string;
  gradingStatus: GradingStatus;
}

export interface AcademicYear {
  id: string;
  label: string;
  startDate: string;
  endDate: string;
  status: AcademicYearStatus;
  periods?: Period[];
}

export interface AuditLogUser {
  id: string;
  email: string;
  firstName: string;
  lastName: string;
}

export interface AuditLog {
  id: string;
  userId: string | null;
  action: string;
  resource: string;
  resourceId: string | null;
  oldValue: unknown;
  newValue: unknown;
  ipAddress: string | null;
  timestamp: string;
  user?: AuditLogUser | null;
}

export interface Tenant {
  id: string;
  code: string;
  name: string;
  type: string;
  status: string;
  address: string | null;
  phone: string | null;
  email: string | null;
  logoUrl: string | null;
  configJson: unknown;
}

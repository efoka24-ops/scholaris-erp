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

export type UserAccountStatus = "ACTIVE" | "INACTIVE" | "SUSPENDED";

export interface RoleSummary {
  id: string;
  name: string;
  description: string | null;
}

export interface UserAccount {
  id: string;
  email: string;
  firstName: string;
  lastName: string;
  phone: string | null;
  status: UserAccountStatus;
  mfaEnabled: boolean;
  lastLogin: string | null;
  avatarUrl: string | null;
  createdAt: string;
  roles: RoleSummary[];
}

export interface Permission {
  id: string;
  resource: string;
  action: string;
  description: string | null;
}

export interface Role {
  id: string;
  tenantId: string | null;
  name: string;
  description: string | null;
  isSystem: boolean;
  createdAt: string;
  permissions: Permission[];
}

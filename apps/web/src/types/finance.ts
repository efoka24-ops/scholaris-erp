import type { ClassRoom, Level } from "./structure";
import type { AcademicYear } from "./students";

export type InvoiceStatus = "PENDING" | "PARTIAL" | "PAID" | "OVERDUE";
export type PaymentMethod = "CASH" | "MOBILE_MONEY" | "BANK_TRANSFER" | "CHECK";
export type DiscountType = "PERCENTAGE" | "FIXED";

export interface FeeInstallment {
  id: string;
  feeStructureId: string;
  label: string;
  amount: number;
  dueDate: string;
  order: number;
}

export interface FeeStructure {
  id: string;
  name: string;
  levelId: string | null;
  academicYearId: string;
  totalAmount: number;
  installments: FeeInstallment[];
  level?: Level | null;
  academicYear?: AcademicYear;
}

export interface FinanceStudent {
  id: string;
  matricule: string;
  firstName: string;
  lastName: string;
}

export interface Payment {
  id: string;
  invoiceId: string;
  studentId: string;
  amount: number;
  method: PaymentMethod;
  reference: string | null;
  receiptNumber: string;
  paidAt: string;
  receivedBy: string | null;
  notes: string | null;
}

export interface Discount {
  id: string;
  studentId: string | null;
  invoiceId: string | null;
  type: DiscountType;
  value: number;
  reason: string | null;
  approvedBy: string;
}

export interface Invoice {
  id: string;
  studentId: string;
  enrollmentId: string;
  feeStructureId: string;
  academicYearId: string;
  totalAmount: number;
  paidAmount: number;
  balance: number;
  dueDate: string | null;
  status: InvoiceStatus;
  student?: FinanceStudent;
  enrollment?: { id: string; classroom?: ClassRoom };
  academicYear?: AcademicYear;
  feeStructure?: FeeStructure;
  payments?: Payment[];
  discounts?: Discount[];
}

export interface GenerateBatchReport {
  generated: number;
  skipped: Array<{ studentId: string; studentName: string; reason: string }>;
}

export interface PaymentReceipt {
  receiptNumber: string;
  paidAt: string;
  amount: number;
  method: PaymentMethod;
  reference: string | null;
  notes: string | null;
  establishment: { name: string; code: string; address: string | null };
  student: FinanceStudent;
  invoice: {
    id: string;
    totalAmount: number;
    paidAmount: number;
    balance: number;
    status: InvoiceStatus;
    academicYear?: string;
  };
  receivedBy: string | null;
}

export interface FinancialSummary {
  student: FinanceStudent;
  totals: {
    totalInvoiced: number;
    totalPaid: number;
    totalBalance: number;
    invoiceCount: number;
    discountCount: number;
  };
  invoices: Array<{
    id: string;
    academicYear?: string;
    classroom?: string;
    totalAmount: number;
    paidAmount: number;
    balance: number;
    status: InvoiceStatus;
    dueDate: string | null;
  }>;
  recentPayments: Array<{
    id: string;
    amount: number;
    method: PaymentMethod;
    receiptNumber: string;
    paidAt: string;
  }>;
}

export interface RecoveryBucket {
  id: string;
  name: string;
  invoiced: number;
  collected: number;
  outstanding: number;
  recoveryRate: number;
}

export interface FinanceDashboard {
  totals: {
    totalInvoiced: number;
    totalCollected: number;
    totalOutstanding: number;
    recoveryRate: number;
    invoiceCount: number;
    overdueCount: number;
  };
  byClassroom: RecoveryBucket[];
  byLevel: RecoveryBucket[];
}

export const INVOICE_STATUS_LABELS: Record<InvoiceStatus, string> = {
  PENDING: "En attente",
  PARTIAL: "Partiellement payée",
  PAID: "Payée",
  OVERDUE: "En retard",
};

export const PAYMENT_METHOD_LABELS: Record<PaymentMethod, string> = {
  CASH: "Espèces",
  MOBILE_MONEY: "Mobile Money",
  BANK_TRANSFER: "Virement bancaire",
  CHECK: "Chèque",
};

export const DISCOUNT_TYPE_LABELS: Record<DiscountType, string> = {
  PERCENTAGE: "Pourcentage",
  FIXED: "Montant fixe",
};

export function formatAmount(value: number): string {
  return new Intl.NumberFormat("fr-FR").format(value) + " FCFA";
}

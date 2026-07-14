import { Injectable, NotFoundException } from "@nestjs/common";
import { PrismaService } from "../../prisma/prisma.service";
import { roundAmount } from "./invoice-status.util";

@Injectable()
export class FinancialSummaryService {
  constructor(private readonly prisma: PrismaService) {}

  /** Résumé financier d'un élève : factures, paiements et solde consolidés. */
  async getSummary(studentId: string) {
    const student = await this.prisma.student.findFirst({ where: { id: studentId } });
    if (!student) {
      throw new NotFoundException("Élève introuvable");
    }

    const [invoices, discounts, payments] = await Promise.all([
      this.prisma.invoice.findMany({
        where: { studentId },
        include: { academicYear: true, enrollment: { include: { classroom: true } } },
        orderBy: { createdAt: "desc" },
      }),
      this.prisma.discount.findMany({ where: { studentId } }),
      this.prisma.payment.findMany({ where: { studentId }, orderBy: { paidAt: "desc" }, take: 10 }),
    ]);

    const totalInvoiced = roundAmount(invoices.reduce((sum, i) => sum + i.totalAmount, 0));
    const totalPaid = roundAmount(invoices.reduce((sum, i) => sum + i.paidAmount, 0));
    const totalBalance = roundAmount(invoices.reduce((sum, i) => sum + i.balance, 0));

    return {
      student: {
        id: student.id,
        matricule: student.matricule,
        firstName: student.firstName,
        lastName: student.lastName,
      },
      totals: {
        totalInvoiced,
        totalPaid,
        totalBalance,
        invoiceCount: invoices.length,
        discountCount: discounts.length,
      },
      invoices: invoices.map((invoice) => ({
        id: invoice.id,
        academicYear: invoice.academicYear?.label,
        classroom: invoice.enrollment?.classroom?.name,
        totalAmount: invoice.totalAmount,
        paidAmount: invoice.paidAmount,
        balance: invoice.balance,
        status: invoice.status,
        dueDate: invoice.dueDate,
      })),
      recentPayments: payments.map((payment) => ({
        id: payment.id,
        amount: payment.amount,
        method: payment.method,
        receiptNumber: payment.receiptNumber,
        paidAt: payment.paidAt,
      })),
    };
  }
}

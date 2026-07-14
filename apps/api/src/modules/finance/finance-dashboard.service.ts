import { Injectable } from "@nestjs/common";
import { PrismaService } from "../../prisma/prisma.service";
import { roundAmount } from "./invoice-status.util";

interface RecoveryBucket {
  id: string;
  name: string;
  invoiced: number;
  collected: number;
  outstanding: number;
  recoveryRate: number;
}

@Injectable()
export class FinanceDashboardService {
  constructor(private readonly prisma: PrismaService) {}

  /**
   * KPIs financiers de l'établissement : total facturé/encaissé/impayé et
   * taux de recouvrement global, ventilés par classe et par niveau. Calculé
   * en mémoire depuis la liste des factures (volumétrie compatible avec un
   * établissement scolaire) plutôt qu'un groupBy SQL multi-jointures.
   */
  async getDashboard(academicYearId?: string) {
    const invoices = await this.prisma.invoice.findMany({
      where: { ...(academicYearId ? { academicYearId } : {}) },
      include: { enrollment: { include: { classroom: { include: { level: true } } } } },
    });

    const totalInvoiced = roundAmount(invoices.reduce((sum, i) => sum + i.totalAmount, 0));
    const totalCollected = roundAmount(invoices.reduce((sum, i) => sum + i.paidAmount, 0));
    const totalOutstanding = roundAmount(invoices.reduce((sum, i) => sum + i.balance, 0));
    const overdueCount = invoices.filter((i) => i.status === "OVERDUE").length;
    const recoveryRate = totalInvoiced > 0 ? roundAmount((totalCollected / totalInvoiced) * 100) : 0;

    const byClassroom = this.groupBy(
      invoices,
      (i) => i.enrollment?.classroom?.id ?? "unknown",
      (i) => i.enrollment?.classroom?.name ?? "Non affecté",
    );
    const byLevel = this.groupBy(
      invoices,
      (i) => i.enrollment?.classroom?.level?.id ?? "unknown",
      (i) => i.enrollment?.classroom?.level?.name ?? "Non affecté",
    );

    return {
      totals: {
        totalInvoiced,
        totalCollected,
        totalOutstanding,
        recoveryRate,
        invoiceCount: invoices.length,
        overdueCount,
      },
      byClassroom,
      byLevel,
    };
  }

  private groupBy(
    invoices: Array<{ totalAmount: number; paidAmount: number; balance: number; enrollment: unknown }>,
    keyFn: (invoice: any) => string,
    nameFn: (invoice: any) => string,
  ): RecoveryBucket[] {
    const buckets = new Map<string, RecoveryBucket>();

    for (const invoice of invoices) {
      const id = keyFn(invoice);
      const name = nameFn(invoice);
      const bucket = buckets.get(id) ?? { id, name, invoiced: 0, collected: 0, outstanding: 0, recoveryRate: 0 };
      bucket.invoiced = roundAmount(bucket.invoiced + invoice.totalAmount);
      bucket.collected = roundAmount(bucket.collected + invoice.paidAmount);
      bucket.outstanding = roundAmount(bucket.outstanding + invoice.balance);
      buckets.set(id, bucket);
    }

    return Array.from(buckets.values())
      .map((bucket) => ({
        ...bucket,
        recoveryRate: bucket.invoiced > 0 ? roundAmount((bucket.collected / bucket.invoiced) * 100) : 0,
      }))
      .sort((a, b) => a.name.localeCompare(b.name));
  }
}

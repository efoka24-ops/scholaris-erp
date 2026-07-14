import { NotFoundException } from "@nestjs/common";
import { PrismaService } from "../../prisma/prisma.service";
import { FinancialSummaryService } from "./financial-summary.service";

describe("FinancialSummaryService", () => {
  let service: FinancialSummaryService;
  let prisma: {
    student: { findFirst: jest.Mock };
    invoice: { findMany: jest.Mock };
    discount: { findMany: jest.Mock };
    payment: { findMany: jest.Mock };
  };

  beforeEach(() => {
    prisma = {
      student: { findFirst: jest.fn() },
      invoice: { findMany: jest.fn().mockResolvedValue([]) },
      discount: { findMany: jest.fn().mockResolvedValue([]) },
      payment: { findMany: jest.fn().mockResolvedValue([]) },
    };
    service = new FinancialSummaryService(prisma as unknown as PrismaService);
  });

  it("rejette (404) si l'élève est introuvable", async () => {
    prisma.student.findFirst.mockResolvedValue(null);

    await expect(service.getSummary("inconnu")).rejects.toThrow(NotFoundException);
  });

  it("calcule les totaux consolidés (facturé/payé/solde) depuis les factures de l'élève", async () => {
    prisma.student.findFirst.mockResolvedValue({
      id: "student-1",
      matricule: "LBD/2026/0001",
      firstName: "Aminata",
      lastName: "Ngo Bassa",
    });
    prisma.invoice.findMany.mockResolvedValue([
      { id: "inv-1", totalAmount: 150000, paidAmount: 100000, balance: 50000, status: "PARTIAL", dueDate: null, academicYear: { label: "2026-2027" }, enrollment: { classroom: { name: "6ème A" } } },
      { id: "inv-2", totalAmount: 100000, paidAmount: 100000, balance: 0, status: "PAID", dueDate: null, academicYear: { label: "2025-2026" }, enrollment: { classroom: { name: "5ème B" } } },
    ]);
    prisma.discount.findMany.mockResolvedValue([{ id: "discount-1" }]);
    prisma.payment.findMany.mockResolvedValue([
      { id: "p1", amount: 100000, method: "CASH", receiptNumber: "LBD/REC/2026/000001", paidAt: new Date() },
    ]);

    const summary = await service.getSummary("student-1");

    expect(summary.totals).toEqual({
      totalInvoiced: 250000,
      totalPaid: 200000,
      totalBalance: 50000,
      invoiceCount: 2,
      discountCount: 1,
    });
    expect(summary.invoices).toHaveLength(2);
    expect(summary.recentPayments).toHaveLength(1);
  });
});

import { PrismaService } from "../../prisma/prisma.service";
import { FinanceDashboardService } from "./finance-dashboard.service";

describe("FinanceDashboardService", () => {
  let service: FinanceDashboardService;
  let prisma: { invoice: { findMany: jest.Mock } };

  beforeEach(() => {
    prisma = { invoice: { findMany: jest.fn() } };
    service = new FinanceDashboardService(prisma as unknown as PrismaService);
  });

  it("calcule les totaux et le taux de recouvrement global", async () => {
    prisma.invoice.findMany.mockResolvedValue([
      {
        totalAmount: 100000,
        paidAmount: 100000,
        balance: 0,
        status: "PAID",
        enrollment: { classroom: { id: "class-1", name: "6ème A", level: { id: "level-1", name: "6ème" } } },
      },
      {
        totalAmount: 100000,
        paidAmount: 25000,
        balance: 75000,
        status: "OVERDUE",
        enrollment: { classroom: { id: "class-2", name: "5ème A", level: { id: "level-2", name: "5ème" } } },
      },
    ]);

    const dashboard = await service.getDashboard();

    expect(dashboard.totals).toEqual({
      totalInvoiced: 200000,
      totalCollected: 125000,
      totalOutstanding: 75000,
      recoveryRate: 62.5,
      invoiceCount: 2,
      overdueCount: 1,
    });
  });

  it("ventile le recouvrement par classe et par niveau", async () => {
    prisma.invoice.findMany.mockResolvedValue([
      {
        totalAmount: 100000,
        paidAmount: 50000,
        balance: 50000,
        status: "PARTIAL",
        enrollment: { classroom: { id: "class-1", name: "6ème A", level: { id: "level-1", name: "6ème" } } },
      },
    ]);

    const dashboard = await service.getDashboard();

    expect(dashboard.byClassroom).toEqual([
      expect.objectContaining({ id: "class-1", name: "6ème A", invoiced: 100000, collected: 50000, recoveryRate: 50 }),
    ]);
    expect(dashboard.byLevel).toEqual([
      expect.objectContaining({ id: "level-1", name: "6ème", invoiced: 100000, collected: 50000, recoveryRate: 50 }),
    ]);
  });

  it("filtre par année académique quand fournie", async () => {
    prisma.invoice.findMany.mockResolvedValue([]);

    await service.getDashboard("year-1");

    expect(prisma.invoice.findMany).toHaveBeenCalledWith(
      expect.objectContaining({ where: { academicYearId: "year-1" } }),
    );
  });
});

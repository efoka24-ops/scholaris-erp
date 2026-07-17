import { BadRequestException, NotFoundException } from "@nestjs/common";
import { PaymentMethod } from "@scholaris/prisma";
import { PrismaService } from "../../prisma/prisma.service";
import { PaymentsService } from "./payments.service";
import { ReceiptService } from "./receipt.service";

describe("PaymentsService", () => {
  let service: PaymentsService;
  let prisma: {
    invoice: { findFirst: jest.Mock; update: jest.Mock };
    tenant: { findFirst: jest.Mock };
    payment: { create: jest.Mock; findFirst: jest.Mock; findMany: jest.Mock; count: jest.Mock };
    receiptSequence: { upsert: jest.Mock };
    $transaction: jest.Mock;
  };
  let receipt: ReceiptService;

  const invoice = {
    id: "invoice-1",
    studentId: "student-1",
    totalAmount: 150000,
    paidAmount: 50000,
    balance: 100000,
    dueDate: new Date("2027-01-15"),
    status: "PARTIAL",
  };

  beforeEach(() => {
    prisma = {
      invoice: { findFirst: jest.fn().mockResolvedValue(invoice), update: jest.fn() },
      tenant: { findFirst: jest.fn().mockResolvedValue({ id: "tenant-1", code: "LBD" }) },
      payment: { create: jest.fn(), findFirst: jest.fn(), findMany: jest.fn(), count: jest.fn() },
      receiptSequence: { upsert: jest.fn().mockResolvedValue({ lastNumber: 1 }) },
      $transaction: jest.fn((callback: (tx: unknown) => unknown) => callback(prisma)),
    };
    receipt = new ReceiptService();
    service = new PaymentsService(prisma as unknown as PrismaService, receipt);
  });

  describe("create", () => {
    it("enregistre le paiement et met à jour la facture (paidAmount/balance/status) dans une transaction", async () => {
      prisma.payment.create.mockResolvedValue({ id: "payment-1", receiptNumber: "LBD/REC/2026/000001" });

      await service.create({ invoiceId: "invoice-1", amount: 50000, method: PaymentMethod.CASH }, "tenant-1", "user-1");

      expect(prisma.$transaction).toHaveBeenCalledTimes(1);
      expect(prisma.payment.create).toHaveBeenCalledWith({
        data: expect.objectContaining({
          invoiceId: "invoice-1",
          studentId: "student-1",
          amount: 50000,
          method: PaymentMethod.CASH,
          receiptNumber: "LBD/REC/2026/000001",
          receivedBy: "user-1",
          tenantId: "tenant-1",
        }),
      });
      expect(prisma.invoice.update).toHaveBeenCalledWith({
        where: { id: "invoice-1" },
        data: { paidAmount: 100000, balance: 50000, status: "PARTIAL" },
      });
    });

    it("passe la facture à PAID quand le paiement solde entièrement le montant restant", async () => {
      prisma.payment.create.mockResolvedValue({ id: "payment-1" });

      await service.create({ invoiceId: "invoice-1", amount: 100000, method: PaymentMethod.CASH }, "tenant-1");

      expect(prisma.invoice.update).toHaveBeenCalledWith({
        where: { id: "invoice-1" },
        data: { paidAmount: 150000, balance: 0, status: "PAID" },
      });
    });

    it("refuse (400) un paiement dont le montant dépasse le solde restant", async () => {
      await expect(
        service.create({ invoiceId: "invoice-1", amount: 150000, method: PaymentMethod.CASH }, "tenant-1"),
      ).rejects.toThrow(BadRequestException);
      expect(prisma.payment.create).not.toHaveBeenCalled();
      expect(prisma.invoice.update).not.toHaveBeenCalled();
    });

    it("rejette (404) si la facture est introuvable", async () => {
      prisma.invoice.findFirst.mockResolvedValue(null);

      await expect(
        service.create({ invoiceId: "inconnue", amount: 1000, method: PaymentMethod.CASH }, "tenant-1"),
      ).rejects.toThrow(NotFoundException);
    });

    it("réserve un numéro de reçu atomique via ReceiptSequence dans la même transaction", async () => {
      prisma.payment.create.mockResolvedValue({ id: "payment-1" });

      await service.create({ invoiceId: "invoice-1", amount: 25000, method: PaymentMethod.MOBILE_MONEY }, "tenant-1");

      expect(prisma.receiptSequence.upsert).toHaveBeenCalledWith({
        where: { tenantId_year: { tenantId: "tenant-1", year: String(new Date().getFullYear()) } },
        update: { lastNumber: { increment: 1 } },
        create: { tenantId: "tenant-1", year: String(new Date().getFullYear()), lastNumber: 1 },
      });
    });
  });

  describe("findAll", () => {
    it("retourne une liste paginée triée par date de paiement décroissante", async () => {
      const payments = [{ id: "payment-2" }, { id: "payment-1" }];
      prisma.payment.findMany.mockResolvedValue(payments);
      prisma.payment.count.mockResolvedValue(2);

      const result = await service.findAll({});

      expect(prisma.payment.findMany).toHaveBeenCalledWith({
        where: {},
        orderBy: { paidAt: "desc" },
        skip: 0,
        take: 20,
        include: { student: true, invoice: true },
      });
      expect(result).toEqual({
        data: payments,
        meta: { total: 2, page: 1, limit: 20, totalPages: 1 },
      });
    });

    it("applique les filtres studentId/invoiceId/method/dates et la pagination", async () => {
      prisma.payment.findMany.mockResolvedValue([]);
      prisma.payment.count.mockResolvedValue(0);

      await service.findAll({
        page: 2,
        limit: 10,
        studentId: "student-1",
        invoiceId: "invoice-1",
        method: PaymentMethod.MOBILE_MONEY,
        dateFrom: "2026-01-01",
        dateTo: "2026-01-31",
      });

      expect(prisma.payment.findMany).toHaveBeenCalledWith({
        where: {
          studentId: "student-1",
          invoiceId: "invoice-1",
          method: PaymentMethod.MOBILE_MONEY,
          paidAt: { gte: new Date("2026-01-01"), lte: new Date("2026-01-31") },
        },
        orderBy: { paidAt: "desc" },
        skip: 10,
        take: 10,
        include: { student: true, invoice: true },
      });
    });
  });

  describe("getReceipt", () => {
    it("rejette (404) si le paiement est introuvable", async () => {
      prisma.payment.findFirst.mockResolvedValue(null);

      await expect(service.getReceipt("inconnu")).rejects.toThrow(NotFoundException);
    });
  });
});

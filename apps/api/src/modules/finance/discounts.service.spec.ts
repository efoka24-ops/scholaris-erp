import { BadRequestException, NotFoundException } from "@nestjs/common";
import { DiscountType } from "@scholaris/prisma";
import { PrismaService } from "../../prisma/prisma.service";
import { DiscountsService } from "./discounts.service";

describe("DiscountsService", () => {
  let service: DiscountsService;
  let prisma: {
    student: { findFirst: jest.Mock };
    invoice: { findFirst: jest.Mock; update: jest.Mock };
    discount: { create: jest.Mock };
    $transaction: jest.Mock;
  };

  const invoice = {
    id: "invoice-1",
    studentId: "student-1",
    totalAmount: 150000,
    paidAmount: 0,
    balance: 150000,
    dueDate: null,
  };

  beforeEach(() => {
    prisma = {
      student: { findFirst: jest.fn().mockResolvedValue({ id: "student-1" }) },
      invoice: { findFirst: jest.fn().mockResolvedValue(invoice), update: jest.fn() },
      discount: { create: jest.fn() },
      $transaction: jest.fn((callback: (tx: unknown) => unknown) => callback(prisma)),
    };
    service = new DiscountsService(prisma as unknown as PrismaService);
  });

  it("rejette (400) si ni studentId ni invoiceId ne sont fournis", async () => {
    await expect(
      service.create({ type: DiscountType.FIXED, value: 1000 } as never, "tenant-1", "admin-1"),
    ).rejects.toThrow(BadRequestException);
  });

  it("rejette (400) un pourcentage supérieur à 100", async () => {
    await expect(
      service.create(
        { invoiceId: "invoice-1", type: DiscountType.PERCENTAGE, value: 150 },
        "tenant-1",
        "admin-1",
      ),
    ).rejects.toThrow(BadRequestException);
  });

  it("enregistre une bourse générale sur l'élève sans toucher à une facture", async () => {
    prisma.discount.create.mockResolvedValue({ id: "discount-1" });

    await service.create(
      { studentId: "student-1", type: DiscountType.FIXED, value: 20000, reason: "Bourse mérite" },
      "tenant-1",
      "admin-1",
    );

    expect(prisma.discount.create).toHaveBeenCalledWith({
      data: {
        studentId: "student-1",
        type: DiscountType.FIXED,
        value: 20000,
        reason: "Bourse mérite",
        approvedBy: "admin-1",
        tenantId: "tenant-1",
      },
    });
    expect(prisma.invoice.update).not.toHaveBeenCalled();
  });

  it("rejette (404) si l'élève ciblé (sans facture) est introuvable", async () => {
    prisma.student.findFirst.mockResolvedValue(null);

    await expect(
      service.create({ studentId: "inconnu", type: DiscountType.FIXED, value: 1000 }, "tenant-1", "admin-1"),
    ).rejects.toThrow(NotFoundException);
  });

  it("applique immédiatement une réduction fixe à la facture ciblée", async () => {
    prisma.discount.create.mockResolvedValue({ id: "discount-1" });

    await service.create(
      { invoiceId: "invoice-1", type: DiscountType.FIXED, value: 20000, reason: "Réduction fratrie" },
      "tenant-1",
      "admin-1",
    );

    expect(prisma.invoice.update).toHaveBeenCalledWith({
      where: { id: "invoice-1" },
      data: { balance: 130000, status: "PENDING" },
    });
  });

  it("applique un pourcentage calculé sur le montant total de la facture", async () => {
    prisma.discount.create.mockResolvedValue({ id: "discount-1" });

    await service.create({ invoiceId: "invoice-1", type: DiscountType.PERCENTAGE, value: 10 }, "tenant-1", "admin-1");

    // 10% de 150000 = 15000 → balance = 135000
    expect(prisma.invoice.update).toHaveBeenCalledWith({
      where: { id: "invoice-1" },
      data: { balance: 135000, status: "PENDING" },
    });
  });

  it("plafonne la balance à 0 si la réduction dépasse le solde restant", async () => {
    prisma.invoice.findFirst.mockResolvedValue({ ...invoice, balance: 10000 });
    prisma.discount.create.mockResolvedValue({ id: "discount-1" });

    await service.create({ invoiceId: "invoice-1", type: DiscountType.FIXED, value: 50000 }, "tenant-1", "admin-1");

    expect(prisma.invoice.update).toHaveBeenCalledWith({
      where: { id: "invoice-1" },
      data: { balance: 0, status: "PAID" },
    });
  });

  it("rejette (404) si la facture ciblée est introuvable", async () => {
    prisma.invoice.findFirst.mockResolvedValue(null);

    await expect(
      service.create({ invoiceId: "inconnue", type: DiscountType.FIXED, value: 1000 }, "tenant-1", "admin-1"),
    ).rejects.toThrow(NotFoundException);
  });
});

import { BadRequestException, ConflictException, NotFoundException } from "@nestjs/common";
import { PrismaService } from "../../prisma/prisma.service";
import { FeeStructuresService } from "./fee-structures.service";
import { InvoicesService } from "./invoices.service";

describe("InvoicesService", () => {
  let service: InvoicesService;
  let prisma: {
    enrollment: { findFirst: jest.Mock; findMany: jest.Mock };
    invoice: { findFirst: jest.Mock; findMany: jest.Mock; count: jest.Mock; create: jest.Mock };
    classRoom: { findFirst: jest.Mock };
  };
  let feeStructures: { findApplicable: jest.Mock };

  const structure = {
    id: "structure-1",
    totalAmount: 150000,
    installments: [
      { dueDate: new Date("2026-10-15") },
      { dueDate: new Date("2027-01-15") },
    ],
  };

  const enrollment = {
    id: "enrollment-1",
    studentId: "student-1",
    academicYearId: "year-1",
    classroom: { id: "class-1", levelId: "level-1" },
    student: { firstName: "Aminata", lastName: "Ngo Bassa" },
  };

  beforeEach(() => {
    prisma = {
      enrollment: { findFirst: jest.fn(), findMany: jest.fn() },
      invoice: { findFirst: jest.fn(), findMany: jest.fn(), count: jest.fn(), create: jest.fn() },
      classRoom: { findFirst: jest.fn() },
    };
    feeStructures = { findApplicable: jest.fn() };
    service = new InvoicesService(prisma as unknown as PrismaService, feeStructures as unknown as FeeStructuresService);
  });

  describe("generateForEnrollment", () => {
    it("génère la facture depuis la grille applicable au niveau de la classe", async () => {
      prisma.enrollment.findFirst.mockResolvedValue(enrollment);
      prisma.invoice.findFirst.mockResolvedValue(null);
      feeStructures.findApplicable.mockResolvedValue(structure);
      prisma.invoice.create.mockResolvedValue({ id: "invoice-1" });

      await service.generateForEnrollment("enrollment-1", "tenant-1");

      expect(feeStructures.findApplicable).toHaveBeenCalledWith("level-1", "year-1");
      expect(prisma.invoice.create).toHaveBeenCalledWith({
        data: expect.objectContaining({
          studentId: "student-1",
          enrollmentId: "enrollment-1",
          feeStructureId: "structure-1",
          academicYearId: "year-1",
          totalAmount: 150000,
          paidAmount: 0,
          balance: 150000,
          dueDate: new Date("2027-01-15"),
          status: "PENDING",
          tenantId: "tenant-1",
        }),
      });
    });

    it("rejette (404) si l'inscription est introuvable", async () => {
      prisma.enrollment.findFirst.mockResolvedValue(null);

      await expect(service.generateForEnrollment("inconnu", "tenant-1")).rejects.toThrow(NotFoundException);
    });

    it("rejette (409) si une facture existe déjà pour cette inscription", async () => {
      prisma.enrollment.findFirst.mockResolvedValue(enrollment);
      prisma.invoice.findFirst.mockResolvedValue({ id: "invoice-existante" });

      await expect(service.generateForEnrollment("enrollment-1", "tenant-1")).rejects.toThrow(ConflictException);
      expect(prisma.invoice.create).not.toHaveBeenCalled();
    });

    it("rejette (400) si aucune grille tarifaire n'est applicable", async () => {
      prisma.enrollment.findFirst.mockResolvedValue(enrollment);
      prisma.invoice.findFirst.mockResolvedValue(null);
      feeStructures.findApplicable.mockResolvedValue(null);

      await expect(service.generateForEnrollment("enrollment-1", "tenant-1")).rejects.toThrow(BadRequestException);
    });
  });

  describe("generateForClass", () => {
    it("génère une facture par inscription active et rapporte les élèves ignorés", async () => {
      prisma.classRoom.findFirst.mockResolvedValue({ id: "class-1", levelId: "level-1" });
      prisma.enrollment.findMany.mockResolvedValue([
        { id: "e1", studentId: "s1", student: { firstName: "A", lastName: "A" } },
        { id: "e2", studentId: "s2", student: { firstName: "B", lastName: "B" } },
      ]);
      feeStructures.findApplicable.mockResolvedValue(structure);
      prisma.invoice.findFirst.mockResolvedValueOnce(null).mockResolvedValueOnce({ id: "existing" });
      prisma.invoice.create.mockResolvedValue({ id: "invoice-1" });

      const report = await service.generateForClass("class-1", "year-1", "tenant-1");

      expect(report.generated).toBe(1);
      expect(report.skipped).toEqual([
        expect.objectContaining({ studentId: "s2", reason: "Facture déjà générée" }),
      ]);
    });

    it("rejette (400) si la classe est introuvable", async () => {
      prisma.classRoom.findFirst.mockResolvedValue(null);

      await expect(service.generateForClass("inconnu", "year-1", "tenant-1")).rejects.toThrow(BadRequestException);
    });

    it("ignore tous les élèves avec un motif si aucune grille n'est applicable", async () => {
      prisma.classRoom.findFirst.mockResolvedValue({ id: "class-1", levelId: "level-1" });
      prisma.enrollment.findMany.mockResolvedValue([
        { id: "e1", studentId: "s1", student: { firstName: "A", lastName: "A" } },
      ]);
      feeStructures.findApplicable.mockResolvedValue(null);

      const report = await service.generateForClass("class-1", "year-1", "tenant-1");

      expect(report.generated).toBe(0);
      expect(report.skipped[0].reason).toMatch(/Aucune grille/);
      expect(prisma.invoice.create).not.toHaveBeenCalled();
    });
  });

  describe("findAll", () => {
    it("pagine et filtre par classe via l'inscription rattachée", async () => {
      prisma.invoice.findMany.mockResolvedValue([]);
      prisma.invoice.count.mockResolvedValue(12);

      const result = await service.findAll({ page: 1, limit: 20, classroomId: "class-1" });

      expect(prisma.invoice.findMany).toHaveBeenCalledWith(
        expect.objectContaining({
          where: expect.objectContaining({ enrollment: { classroomId: "class-1" } }),
        }),
      );
      expect(result.meta).toEqual({ total: 12, page: 1, limit: 20, totalPages: 1 });
    });
  });

  describe("findOne", () => {
    it("rejette (404) si la facture est introuvable", async () => {
      prisma.invoice.findFirst.mockResolvedValue(null);

      await expect(service.findOne("inconnu")).rejects.toThrow(NotFoundException);
    });
  });
});

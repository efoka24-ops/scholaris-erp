import { BadRequestException } from "@nestjs/common";
import { PrismaService } from "../../prisma/prisma.service";
import { FeeStructuresService } from "./fee-structures.service";

describe("FeeStructuresService", () => {
  let service: FeeStructuresService;
  let prisma: {
    level: { findFirst: jest.Mock };
    academicYear: { findFirst: jest.Mock };
    feeStructure: { create: jest.Mock; findFirst: jest.Mock; findMany: jest.Mock };
    feeInstallment: { createMany: jest.Mock };
    $transaction: jest.Mock;
  };

  const baseDto = {
    name: "Scolarité 6ème 2026-2027",
    academicYearId: "year-1",
    totalAmount: 150000,
    installments: [
      { label: "1ère tranche", amount: 100000, dueDate: "2026-10-15", order: 1 },
      { label: "2e tranche", amount: 50000, dueDate: "2027-01-15", order: 2 },
    ],
  };

  beforeEach(() => {
    prisma = {
      level: { findFirst: jest.fn().mockResolvedValue({ id: "level-1" }) },
      academicYear: { findFirst: jest.fn().mockResolvedValue({ id: "year-1" }) },
      feeStructure: { create: jest.fn(), findFirst: jest.fn(), findMany: jest.fn() },
      feeInstallment: { createMany: jest.fn() },
      $transaction: jest.fn((callback: (tx: unknown) => unknown) => callback(prisma)),
    };
    service = new FeeStructuresService(prisma as unknown as PrismaService);
  });

  describe("create", () => {
    it("crée la grille tarifaire et ses tranches dans une transaction", async () => {
      prisma.feeStructure.create.mockResolvedValue({ id: "structure-1" });
      prisma.feeStructure.findFirst.mockResolvedValue({ id: "structure-1", installments: [] });

      await service.create({ ...baseDto, levelId: "level-1" }, "tenant-1");

      expect(prisma.$transaction).toHaveBeenCalledTimes(1);
      expect(prisma.feeStructure.create).toHaveBeenCalledWith({
        data: expect.objectContaining({
          name: baseDto.name,
          levelId: "level-1",
          academicYearId: "year-1",
          totalAmount: 150000,
          tenantId: "tenant-1",
        }),
      });
      expect(prisma.feeInstallment.createMany).toHaveBeenCalledWith({
        data: expect.arrayContaining([
          expect.objectContaining({ feeStructureId: "structure-1", label: "1ère tranche", amount: 100000 }),
          expect.objectContaining({ feeStructureId: "structure-1", label: "2e tranche", amount: 50000 }),
        ]),
      });
    });

    it("rejette (400) quand la somme des tranches ne correspond pas au montant total", async () => {
      await expect(
        service.create({ ...baseDto, totalAmount: 999999 }, "tenant-1"),
      ).rejects.toThrow(BadRequestException);
      expect(prisma.feeStructure.create).not.toHaveBeenCalled();
    });

    it("rejette (400) si le niveau indiqué est introuvable", async () => {
      prisma.level.findFirst.mockResolvedValue(null);

      await expect(service.create({ ...baseDto, levelId: "inconnu" }, "tenant-1")).rejects.toThrow(
        BadRequestException,
      );
    });

    it("rejette (400) si l'année académique indiquée est introuvable", async () => {
      prisma.academicYear.findFirst.mockResolvedValue(null);

      await expect(service.create(baseDto, "tenant-1")).rejects.toThrow(BadRequestException);
    });

    it("accepte une grille sans levelId (s'applique à tous les niveaux)", async () => {
      prisma.feeStructure.create.mockResolvedValue({ id: "structure-1" });
      prisma.feeStructure.findFirst.mockResolvedValue({ id: "structure-1", installments: [] });

      await service.create(baseDto, "tenant-1");

      expect(prisma.level.findFirst).not.toHaveBeenCalled();
      expect(prisma.feeStructure.create).toHaveBeenCalledWith({
        data: expect.objectContaining({ levelId: undefined }),
      });
    });
  });

  describe("findApplicable", () => {
    it("priorise une grille spécifique au niveau avant de replier sur la grille générale", async () => {
      prisma.feeStructure.findFirst.mockResolvedValueOnce({ id: "specific" });

      const result = await service.findApplicable("level-1", "year-1");

      expect(result).toEqual({ id: "specific" });
      expect(prisma.feeStructure.findFirst).toHaveBeenCalledTimes(1);
    });

    it("replie sur la grille générale (levelId=null) si aucune grille spécifique n'existe", async () => {
      prisma.feeStructure.findFirst.mockResolvedValueOnce(null).mockResolvedValueOnce({ id: "general" });

      const result = await service.findApplicable("level-1", "year-1");

      expect(result).toEqual({ id: "general" });
      expect(prisma.feeStructure.findFirst).toHaveBeenNthCalledWith(
        2,
        expect.objectContaining({ where: { levelId: null, academicYearId: "year-1" } }),
      );
    });
  });
});

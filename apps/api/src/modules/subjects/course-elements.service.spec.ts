import { BadRequestException, NotFoundException } from "@nestjs/common";
import { PrismaService } from "../../prisma/prisma.service";
import { CourseElementsService } from "./course-elements.service";

describe("CourseElementsService", () => {
  let service: CourseElementsService;
  let prisma: {
    courseElement: { findMany: jest.Mock; aggregate: jest.Mock; create: jest.Mock };
    teachingUnit: { findFirst: jest.Mock };
  };

  const baseDto = {
    code: "EC-INF-101-1",
    name: "Programmation en C",
    credits: 3,
    hoursCm: 20,
    hoursTd: 15,
    hoursTp: 10,
    coefficient: 1,
    teachingUnitId: "ue-1",
  };

  beforeEach(() => {
    prisma = {
      courseElement: { findMany: jest.fn(), aggregate: jest.fn(), create: jest.fn() },
      teachingUnit: { findFirst: jest.fn() },
    };
    service = new CourseElementsService(prisma as unknown as PrismaService);
  });

  describe("create — validation crédits UE vs somme des EC", () => {
    it("crée l'EC quand la somme des crédits reste dans l'enveloppe de l'UE", async () => {
      prisma.teachingUnit.findFirst.mockResolvedValue({ id: "ue-1", code: "UE-INF-101", credits: 6 });
      prisma.courseElement.aggregate.mockResolvedValue({ _sum: { credits: 3 } });
      prisma.courseElement.create.mockResolvedValue({ id: "ec-1", ...baseDto });

      await service.create(baseDto, "tenant-1");

      expect(prisma.courseElement.create).toHaveBeenCalledWith({
        data: expect.objectContaining({ ...baseDto, tenantId: "tenant-1" }),
      });
    });

    it("accepte le cas limite : somme exactement égale aux crédits de l'UE", async () => {
      prisma.teachingUnit.findFirst.mockResolvedValue({ id: "ue-1", code: "UE-INF-101", credits: 6 });
      prisma.courseElement.aggregate.mockResolvedValue({ _sum: { credits: 3 } });
      prisma.courseElement.create.mockResolvedValue({ id: "ec-1" });

      await expect(service.create({ ...baseDto, credits: 3 }, "tenant-1")).resolves.toBeDefined();
    });

    it("renvoie 400 si la somme des crédits des EC dépasserait les crédits de l'UE", async () => {
      prisma.teachingUnit.findFirst.mockResolvedValue({ id: "ue-1", code: "UE-INF-101", credits: 6 });
      prisma.courseElement.aggregate.mockResolvedValue({ _sum: { credits: 4 } });

      await expect(service.create(baseDto, "tenant-1")).rejects.toThrow(BadRequestException);
      expect(prisma.courseElement.create).not.toHaveBeenCalled();
    });

    it("traite une UE sans EC existant (somme null) comme une somme nulle", async () => {
      prisma.teachingUnit.findFirst.mockResolvedValue({ id: "ue-1", code: "UE-INF-101", credits: 6 });
      prisma.courseElement.aggregate.mockResolvedValue({ _sum: { credits: null } });
      prisma.courseElement.create.mockResolvedValue({ id: "ec-1" });

      await expect(service.create(baseDto, "tenant-1")).resolves.toBeDefined();
    });

    it("rejette si l'UE n'existe pas", async () => {
      prisma.teachingUnit.findFirst.mockResolvedValue(null);

      await expect(service.create(baseDto, "tenant-1")).rejects.toThrow(NotFoundException);
    });
  });
});

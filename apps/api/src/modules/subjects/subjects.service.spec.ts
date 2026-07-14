import { BadRequestException, ConflictException, NotFoundException } from "@nestjs/common";
import { Prisma, SubjectCategory } from "@scholaris/prisma";
import { PrismaService } from "../../prisma/prisma.service";
import { SubjectsService } from "./subjects.service";

function uniqueConstraintError() {
  return new Prisma.PrismaClientKnownRequestError("Unique constraint failed", {
    code: "P2002",
    clientVersion: "5.22.0",
  });
}

describe("SubjectsService", () => {
  let service: SubjectsService;
  let prisma: {
    subject: { findFirst: jest.Mock; findMany: jest.Mock; count: jest.Mock; create: jest.Mock; update: jest.Mock; delete: jest.Mock };
    classRoom: { findFirst: jest.Mock };
    subjectAssignment: { findMany: jest.Mock };
  };

  const baseDto = {
    code: "MATH",
    name: "Mathématiques",
    coefficient: 4,
    weeklyHours: 5,
    category: SubjectCategory.SCIENTIFIC,
  };

  beforeEach(() => {
    prisma = {
      subject: {
        findFirst: jest.fn(),
        findMany: jest.fn(),
        count: jest.fn(),
        create: jest.fn(),
        update: jest.fn(),
        delete: jest.fn(),
      },
      classRoom: { findFirst: jest.fn() },
      subjectAssignment: { findMany: jest.fn() },
    };
    service = new SubjectsService(prisma as unknown as PrismaService);
  });

  describe("create", () => {
    it("crée la matière avec le tenant courant et les valeurs par défaut", async () => {
      prisma.subject.create.mockResolvedValue({ id: "subject-1", ...baseDto });

      await service.create(baseDto, "tenant-1");

      expect(prisma.subject.create).toHaveBeenCalledWith({
        data: {
          ...baseDto,
          isEliminatory: false,
          eliminatoryThreshold: 0,
          levelIds: [],
          tenantId: "tenant-1",
        },
      });
    });

    it("applique le seuil éliminatoire fourni pour une matière éliminatoire", async () => {
      prisma.subject.create.mockResolvedValue({ id: "subject-1" });

      await service.create({ ...baseDto, isEliminatory: true, eliminatoryThreshold: 7 }, "tenant-1");

      expect(prisma.subject.create).toHaveBeenCalledWith({
        data: expect.objectContaining({ isEliminatory: true, eliminatoryThreshold: 7 }),
      });
    });

    it("rejette un coefficient nul ou négatif", async () => {
      await expect(service.create({ ...baseDto, coefficient: 0 }, "tenant-1")).rejects.toThrow(BadRequestException);
      await expect(service.create({ ...baseDto, coefficient: -2 }, "tenant-1")).rejects.toThrow(BadRequestException);
      expect(prisma.subject.create).not.toHaveBeenCalled();
    });

    it("transforme une violation d'unicité du code (par tenant) en ConflictException", async () => {
      prisma.subject.create.mockRejectedValue(uniqueConstraintError());

      await expect(service.create(baseDto, "tenant-1")).rejects.toThrow(ConflictException);
    });
  });

  describe("update", () => {
    it("rejette un coefficient invalide à la mise à jour", async () => {
      prisma.subject.findFirst.mockResolvedValue({ id: "subject-1" });

      await expect(service.update("subject-1", { coefficient: 0 })).rejects.toThrow(BadRequestException);
      expect(prisma.subject.update).not.toHaveBeenCalled();
    });

    it("rejette si la matière n'existe pas", async () => {
      prisma.subject.findFirst.mockResolvedValue(null);

      await expect(service.update("inconnue", { name: "Physique" })).rejects.toThrow(NotFoundException);
    });
  });

  describe("findAll", () => {
    it("pagine et filtre par catégorie et par niveau", async () => {
      prisma.subject.findMany.mockResolvedValue([]);
      prisma.subject.count.mockResolvedValue(45);

      const result = await service.findAll({ category: SubjectCategory.LITERARY, levelId: "level-1", page: 2, limit: 20 });

      expect(prisma.subject.findMany).toHaveBeenCalledWith(
        expect.objectContaining({
          where: expect.objectContaining({
            deletedAt: null,
            category: SubjectCategory.LITERARY,
            levelIds: { has: "level-1" },
          }),
          skip: 20,
          take: 20,
        }),
      );
      expect(result.meta).toEqual({ total: 45, page: 2, limit: 20, totalPages: 3 });
    });
  });

  describe("remove", () => {
    it("délègue au delete Prisma (converti en soft delete par le middleware)", async () => {
      prisma.subject.findFirst.mockResolvedValue({ id: "subject-1" });
      prisma.subject.delete.mockResolvedValue({ id: "subject-1" });

      await service.remove("subject-1");

      expect(prisma.subject.delete).toHaveBeenCalledWith({ where: { id: "subject-1" } });
    });
  });

  describe("findByClassroom", () => {
    it("regroupe les enseignants assignés par matière", async () => {
      prisma.classRoom.findFirst.mockResolvedValue({ id: "class-1", code: "6A", name: "6ème A" });
      const subject = { id: "subject-1", code: "MATH", name: "Mathématiques" };
      prisma.subjectAssignment.findMany.mockResolvedValue([
        {
          subject,
          teacher: { id: "t1", firstName: "Alice", lastName: "Ngo", email: "a@ex.cm" },
          academicYear: { id: "y1", label: "2025-2026" },
        },
        {
          subject,
          teacher: { id: "t2", firstName: "Bob", lastName: "Essomba", email: "b@ex.cm" },
          academicYear: { id: "y1", label: "2025-2026" },
        },
      ]);

      const result = await service.findByClassroom("class-1");

      expect(result.subjects).toHaveLength(1);
      expect(result.subjects[0].teachers).toHaveLength(2);
      expect(result.classroom).toEqual({ id: "class-1", code: "6A", name: "6ème A" });
    });

    it("rejette si la classe n'existe pas dans le tenant", async () => {
      prisma.classRoom.findFirst.mockResolvedValue(null);

      await expect(service.findByClassroom("inconnue")).rejects.toThrow(NotFoundException);
    });
  });
});

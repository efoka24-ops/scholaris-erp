import { BadRequestException, ConflictException, NotFoundException } from "@nestjs/common";
import { PrismaService } from "../../prisma/prisma.service";
import { SubjectAssignmentsService } from "./subject-assignments.service";

describe("SubjectAssignmentsService", () => {
  let service: SubjectAssignmentsService;
  let prisma: {
    subjectAssignment: { findMany: jest.Mock; findFirst: jest.Mock; create: jest.Mock };
    user: { findFirst: jest.Mock; findMany: jest.Mock };
    classRoom: { findFirst: jest.Mock };
    academicYear: { findFirst: jest.Mock };
    subject: { findFirst: jest.Mock };
    courseElement: { findFirst: jest.Mock };
  };

  const baseDto = {
    subjectId: "subject-1",
    teacherId: "teacher-1",
    classroomId: "class-1",
    academicYearId: "year-1",
  };

  beforeEach(() => {
    prisma = {
      subjectAssignment: { findMany: jest.fn(), findFirst: jest.fn(), create: jest.fn() },
      user: { findFirst: jest.fn(), findMany: jest.fn() },
      classRoom: { findFirst: jest.fn() },
      academicYear: { findFirst: jest.fn() },
      subject: { findFirst: jest.fn() },
      courseElement: { findFirst: jest.fn() },
    };
    service = new SubjectAssignmentsService(prisma as unknown as PrismaService);
  });

  function mockAllRefsFound() {
    prisma.user.findFirst.mockResolvedValue({ id: "teacher-1" });
    prisma.classRoom.findFirst.mockResolvedValue({ id: "class-1" });
    prisma.academicYear.findFirst.mockResolvedValue({ id: "year-1" });
    prisma.subject.findFirst.mockResolvedValue({ id: "subject-1" });
    prisma.courseElement.findFirst.mockResolvedValue({ id: "ec-1" });
    prisma.subjectAssignment.findFirst.mockResolvedValue(null);
  }

  describe("create", () => {
    it("crée l'assignation matière quand toutes les références existent dans le tenant", async () => {
      mockAllRefsFound();
      prisma.subjectAssignment.create.mockResolvedValue({ id: "assignment-1" });

      await service.create(baseDto, "tenant-1");

      expect(prisma.subjectAssignment.create).toHaveBeenCalledWith({
        data: {
          subjectId: "subject-1",
          courseElementId: null,
          teacherId: "teacher-1",
          classroomId: "class-1",
          academicYearId: "year-1",
          tenantId: "tenant-1",
        },
      });
    });

    it("rejette si ni matière ni EC n'est renseigné (ou les deux)", async () => {
      await expect(service.create({ ...baseDto, subjectId: undefined }, "tenant-1")).rejects.toThrow(
        BadRequestException,
      );
      await expect(service.create({ ...baseDto, courseElementId: "ec-1" }, "tenant-1")).rejects.toThrow(
        BadRequestException,
      );
      expect(prisma.subjectAssignment.create).not.toHaveBeenCalled();
    });

    it("rejette si l'enseignant n'existe pas dans le tenant", async () => {
      mockAllRefsFound();
      prisma.user.findFirst.mockResolvedValue(null);

      await expect(service.create(baseDto, "tenant-1")).rejects.toThrow(BadRequestException);
      expect(prisma.subjectAssignment.create).not.toHaveBeenCalled();
    });

    it("rejette si la classe n'existe pas dans le tenant", async () => {
      mockAllRefsFound();
      prisma.classRoom.findFirst.mockResolvedValue(null);

      await expect(service.create(baseDto, "tenant-1")).rejects.toThrow(BadRequestException);
    });

    it("rejette si l'année académique n'existe pas", async () => {
      mockAllRefsFound();
      prisma.academicYear.findFirst.mockResolvedValue(null);

      await expect(service.create(baseDto, "tenant-1")).rejects.toThrow(BadRequestException);
    });

    it("rejette si la matière assignée n'existe pas", async () => {
      mockAllRefsFound();
      prisma.subject.findFirst.mockResolvedValue(null);

      await expect(service.create(baseDto, "tenant-1")).rejects.toThrow(NotFoundException);
    });

    it("renvoie 409 si la matière est déjà assignée à cette classe pour cette année", async () => {
      mockAllRefsFound();
      prisma.subjectAssignment.findFirst.mockResolvedValue({ id: "assignment-existante" });

      await expect(service.create(baseDto, "tenant-1")).rejects.toThrow(ConflictException);
      expect(prisma.subjectAssignment.findFirst).toHaveBeenCalledWith({
        where: expect.objectContaining({
          classroomId: "class-1",
          academicYearId: "year-1",
          subjectId: "subject-1",
        }),
      });
      expect(prisma.subjectAssignment.create).not.toHaveBeenCalled();
    });

    it("contrôle le doublon sur l'EC quand l'assignation porte sur un EC", async () => {
      mockAllRefsFound();
      prisma.subjectAssignment.create.mockResolvedValue({ id: "assignment-1" });

      await service.create({ ...baseDto, subjectId: undefined, courseElementId: "ec-1" }, "tenant-1");

      expect(prisma.subjectAssignment.findFirst).toHaveBeenCalledWith({
        where: expect.objectContaining({ courseElementId: "ec-1" }),
      });
    });
  });
});

import { BadRequestException, ConflictException, NotFoundException } from "@nestjs/common";
import { EnrollmentStatus, EnrollmentType } from "@scholaris/prisma";
import { PrismaService } from "../../prisma/prisma.service";
import { EnrollmentsService } from "./enrollments.service";

describe("EnrollmentsService", () => {
  let service: EnrollmentsService;
  let prisma: {
    student: { findFirst: jest.Mock };
    classRoom: { findFirst: jest.Mock };
    academicYear: { findFirst: jest.Mock };
    enrollment: { findFirst: jest.Mock; findMany: jest.Mock; count: jest.Mock; create: jest.Mock; update: jest.Mock };
    $transaction: jest.Mock;
  };

  const dto = {
    studentId: "student-1",
    classroomId: "class-1",
    academicYearId: "year-1",
  };

  beforeEach(() => {
    prisma = {
      student: { findFirst: jest.fn().mockResolvedValue({ id: "student-1" }) },
      classRoom: { findFirst: jest.fn().mockResolvedValue({ id: "class-1", capacity: 60 }) },
      academicYear: { findFirst: jest.fn().mockResolvedValue({ id: "year-1" }) },
      enrollment: {
        findFirst: jest.fn().mockResolvedValue(null),
        findMany: jest.fn().mockResolvedValue([]),
        count: jest.fn().mockResolvedValue(0),
        create: jest.fn().mockResolvedValue({ id: "enrollment-1" }),
        update: jest.fn(),
      },
      $transaction: jest.fn((callback: (tx: unknown) => unknown) => callback(prisma)),
    };
    service = new EnrollmentsService(prisma as unknown as PrismaService);
  });

  describe("enroll", () => {
    it("inscrit l'élève quand il reste de la place", async () => {
      prisma.enrollment.count.mockResolvedValue(59);

      await service.enroll(dto, "tenant-1");

      expect(prisma.enrollment.create).toHaveBeenCalledWith({
        data: expect.objectContaining({
          studentId: "student-1",
          classroomId: "class-1",
          academicYearId: "year-1",
          status: EnrollmentStatus.ACTIVE,
          type: EnrollmentType.NEW,
          tenantId: "tenant-1",
        }),
      });
    });

    it("refuse (400) quand la capacité de la classe est atteinte", async () => {
      prisma.enrollment.count.mockResolvedValue(60);

      await expect(service.enroll(dto, "tenant-1")).rejects.toThrow(BadRequestException);
      await expect(service.enroll(dto, "tenant-1")).rejects.toThrow(/[Cc]apacité/);
      expect(prisma.enrollment.create).not.toHaveBeenCalled();
    });

    it("rejette en 409 si l'élève est déjà inscrit pour la même année", async () => {
      prisma.enrollment.findFirst.mockResolvedValue({ id: "enrollment-0" });

      await expect(service.enroll(dto, "tenant-1")).rejects.toThrow(ConflictException);
      expect(prisma.enrollment.create).not.toHaveBeenCalled();
    });

    it("rejette si l'élève est introuvable", async () => {
      prisma.student.findFirst.mockResolvedValue(null);

      await expect(service.enroll(dto, "tenant-1")).rejects.toThrow(BadRequestException);
    });
  });

  describe("updateStatus", () => {
    it("annule une inscription existante", async () => {
      prisma.enrollment.findFirst.mockResolvedValue({ id: "enrollment-1" });

      await service.updateStatus("enrollment-1", { status: EnrollmentStatus.CANCELLED });

      expect(prisma.enrollment.update).toHaveBeenCalledWith({
        where: { id: "enrollment-1" },
        data: { status: EnrollmentStatus.CANCELLED },
      });
    });

    it("rejette si l'inscription est introuvable", async () => {
      prisma.enrollment.findFirst.mockResolvedValue(null);

      await expect(service.updateStatus("inconnue", { status: EnrollmentStatus.CANCELLED })).rejects.toThrow(
        NotFoundException,
      );
    });
  });

  describe("reEnroll", () => {
    const reEnrollDto = {
      sourceClassroomId: "class-source",
      targetClassroomId: "class-target",
      targetAcademicYearId: "year-2",
    };

    function studentEnrollment(index: number) {
      return {
        id: `enrollment-${index}`,
        studentId: `student-${index}`,
        regime: "EXTERNAL",
        student: { id: `student-${index}`, firstName: `Prénom${index}`, lastName: `Nom${index}` },
      };
    }

    beforeEach(() => {
      prisma.classRoom.findFirst.mockImplementation(({ where }: { where: { id: string } }) =>
        Promise.resolve(
          where.id === "class-target"
            ? { id: "class-target", capacity: 2 }
            : { id: where.id, capacity: 60 },
        ),
      );
    });

    it("réinscrit tous les élèves de la classe source et rapporte {reEnrolled, failed}", async () => {
      prisma.enrollment.findMany.mockResolvedValue([studentEnrollment(1), studentEnrollment(2)]);

      const report = await service.reEnroll(reEnrollDto, "tenant-1");

      expect(report).toEqual({ reEnrolled: 2, failed: [] });
      expect(prisma.enrollment.create).toHaveBeenCalledTimes(2);
      expect(prisma.enrollment.create).toHaveBeenCalledWith({
        data: expect.objectContaining({
          type: EnrollmentType.RE_ENROLLMENT,
          classroomId: "class-target",
          academicYearId: "year-2",
        }),
      });
    });

    it("liste en échec les élèves au-delà de la capacité de la classe de destination", async () => {
      prisma.enrollment.findMany.mockResolvedValue([studentEnrollment(1), studentEnrollment(2), studentEnrollment(3)]);

      const report = await service.reEnroll(reEnrollDto, "tenant-1");

      expect(report.reEnrolled).toBe(2);
      expect(report.failed).toEqual([
        expect.objectContaining({ studentId: "student-3", reason: expect.stringMatching(/[Cc]apacité/) }),
      ]);
    });

    it("liste en échec un élève déjà inscrit pour l'année cible, sans bloquer les autres", async () => {
      prisma.enrollment.findMany.mockResolvedValue([studentEnrollment(1), studentEnrollment(2)]);
      prisma.enrollment.findFirst.mockImplementation(({ where }: { where: { studentId: string } }) =>
        Promise.resolve(where.studentId === "student-1" ? { id: "existing" } : null),
      );

      const report = await service.reEnroll(reEnrollDto, "tenant-1");

      expect(report.reEnrolled).toBe(1);
      expect(report.failed).toEqual([
        expect.objectContaining({ studentId: "student-1", reason: expect.stringMatching(/[Dd]éjà inscrit/) }),
      ]);
    });

    it("rejette si la classe de destination est introuvable", async () => {
      prisma.classRoom.findFirst.mockResolvedValue(null);

      await expect(service.reEnroll(reEnrollDto, "tenant-1")).rejects.toThrow(BadRequestException);
    });
  });
});

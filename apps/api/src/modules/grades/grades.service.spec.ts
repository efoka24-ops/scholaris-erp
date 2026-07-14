import { BadRequestException, ForbiddenException, NotFoundException } from "@nestjs/common";
import { AnnualDecision, GradeType, GradingStatus, PeriodType } from "@scholaris/prisma";
import { AuditService } from "../audit/audit.service";
import type { AuthenticatedUser } from "../auth/jwt-payload.interface";
import { CalculationEngineService } from "./calculation-engine.service";
import { GradesService } from "./grades.service";

function buildPrismaMock() {
  let gradeCounter = 0;
  return {
    period: { findFirst: jest.fn(), findMany: jest.fn().mockResolvedValue([]) },
    classRoom: { findFirst: jest.fn(), findMany: jest.fn().mockResolvedValue([]) },
    enrollment: { findMany: jest.fn().mockResolvedValue([]) },
    subject: { findFirst: jest.fn() },
    courseElement: { findFirst: jest.fn(), findMany: jest.fn().mockResolvedValue([]) },
    subjectAssignment: { findMany: jest.fn().mockResolvedValue([]) },
    grade: {
      create: jest.fn().mockImplementation(({ data }) => Promise.resolve({ id: `grade-${gradeCounter++}`, ...data })),
      findFirst: jest.fn(),
      findMany: jest.fn().mockResolvedValue([]),
      update: jest.fn().mockImplementation(({ data }) => Promise.resolve({ id: "grade-updated", ...data })),
      updateMany: jest.fn().mockResolvedValue({ count: 0 }),
    },
    gradeCalculation: {
      findFirst: jest.fn().mockResolvedValue(null),
      create: jest.fn().mockImplementation(({ data }) => Promise.resolve({ id: "calc-1", ...data })),
      update: jest.fn().mockImplementation(({ data }) => Promise.resolve({ id: "calc-1", ...data })),
    },
    periodResult: {
      findFirst: jest.fn().mockResolvedValue(null),
      findMany: jest.fn().mockResolvedValue([]),
      create: jest.fn().mockImplementation(({ data }) => Promise.resolve({ id: `pr-${data.studentId}`, ...data })),
      update: jest.fn().mockImplementation(({ data, where }) => Promise.resolve({ id: where.id, ...data })),
      count: jest.fn().mockResolvedValue(0),
      updateMany: jest.fn().mockResolvedValue({ count: 0 }),
    },
    annualResult: {
      findFirst: jest.fn().mockResolvedValue(null),
      findMany: jest.fn().mockResolvedValue([]),
      create: jest.fn().mockImplementation(({ data }) => Promise.resolve({ id: `ar-${data.studentId}`, ...data })),
      update: jest.fn().mockImplementation(({ data, where }) => Promise.resolve({ id: where.id, ...data })),
    },
    tenant: { findFirst: jest.fn().mockResolvedValue({ id: "tenant-1", configJson: null }) },
    student: { findFirst: jest.fn().mockResolvedValue({ id: "student-1" }) },
    academicYear: { findFirst: jest.fn() },
    $transaction: jest.fn((arg: unknown) => (Array.isArray(arg) ? Promise.all(arg) : (arg as (tx: unknown) => unknown)(prismaMockSelf))),
  };
}

// eslint-disable-next-line @typescript-eslint/no-explicit-any
let prismaMockSelf: any;

describe("GradesService", () => {
  let service: GradesService;
  let prisma: ReturnType<typeof buildPrismaMock>;
  let audit: { log: jest.Mock };
  const user: AuthenticatedUser = { userId: "teacher-1", tenantId: "tenant-1", email: "t@x.com", permissions: [] };

  beforeEach(() => {
    prisma = buildPrismaMock();
    prismaMockSelf = prisma;
    audit = { log: jest.fn().mockResolvedValue(undefined) };
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    service = new GradesService(prisma as any, audit as unknown as AuditService, new CalculationEngineService());
  });

  describe("batchCreate", () => {
    const baseDto = {
      classroomId: "class-1",
      subjectId: "subject-1",
      periodId: "period-1",
      type: GradeType.TEST,
      maxValue: 20,
      entries: [{ studentId: "student-1", value: 15 }],
    };

    it("rejette si ni subjectId ni courseElementId n'est fourni", async () => {
      await expect(service.batchCreate({ ...baseDto, subjectId: undefined }, user)).rejects.toThrow(BadRequestException);
    });

    it("rejette si subjectId ET courseElementId sont tous deux fournis", async () => {
      await expect(service.batchCreate({ ...baseDto, courseElementId: "ec-1" }, user)).rejects.toThrow(BadRequestException);
    });

    it("rejette (404) si la période est introuvable", async () => {
      prisma.period.findFirst.mockResolvedValue(null);
      await expect(service.batchCreate(baseDto, user)).rejects.toThrow(NotFoundException);
    });

    it("rejette (403) si la période n'est pas ouverte (fermée)", async () => {
      prisma.period.findFirst.mockResolvedValue({ id: "period-1", gradingStatus: GradingStatus.CLOSED, academicYearId: "year-1" });
      await expect(service.batchCreate(baseDto, user)).rejects.toThrow(ForbiddenException);
    });

    it("rejette (403) si la période est verrouillée", async () => {
      prisma.period.findFirst.mockResolvedValue({ id: "period-1", gradingStatus: GradingStatus.LOCKED, academicYearId: "year-1" });
      await expect(service.batchCreate(baseDto, user)).rejects.toThrow(ForbiddenException);
    });

    it("rejette (400) une note hors barème", async () => {
      prisma.period.findFirst.mockResolvedValue({ id: "period-1", gradingStatus: GradingStatus.OPEN, academicYearId: "year-1" });
      prisma.subject.findFirst.mockResolvedValue({ id: "subject-1" });
      await expect(
        service.batchCreate({ ...baseDto, entries: [{ studentId: "student-1", value: 25 }] }, user),
      ).rejects.toThrow(BadRequestException);
      await expect(
        service.batchCreate({ ...baseDto, entries: [{ studentId: "student-1", value: -1 }] }, user),
      ).rejects.toThrow(BadRequestException);
    });

    it("crée les notes avec value=null pour les entrées absentes", async () => {
      prisma.period.findFirst.mockResolvedValue({ id: "period-1", gradingStatus: GradingStatus.OPEN, academicYearId: "year-1" });
      prisma.subject.findFirst.mockResolvedValue({ id: "subject-1" });

      const created = await service.batchCreate(
        { ...baseDto, entries: [{ studentId: "student-1", isAbsent: true, isJustified: true }] },
        user,
      );

      expect(created).toHaveLength(1);
      expect(prisma.grade.create).toHaveBeenCalledWith(
        expect.objectContaining({ data: expect.objectContaining({ value: null, isAbsent: true, teacherId: "teacher-1", tenantId: "tenant-1" }) }),
      );
    });

    it("crée une note par élève dans la limite du barème", async () => {
      prisma.period.findFirst.mockResolvedValue({ id: "period-1", gradingStatus: GradingStatus.OPEN, academicYearId: "year-1" });
      prisma.subject.findFirst.mockResolvedValue({ id: "subject-1" });

      const created = await service.batchCreate(baseDto, user);

      expect(created).toHaveLength(1);
      expect(prisma.grade.create).toHaveBeenCalledWith(
        expect.objectContaining({ data: expect.objectContaining({ value: 15, subjectId: "subject-1", periodId: "period-1" }) }),
      );
    });
  });

  describe("update", () => {
    it("rejette (404) si la note est introuvable", async () => {
      prisma.grade.findFirst.mockResolvedValue(null);
      await expect(service.update("grade-1", { value: 10 }, "tenant-1")).rejects.toThrow(NotFoundException);
    });

    it("rejette (403) si la note est verrouillée", async () => {
      prisma.grade.findFirst.mockResolvedValue({ id: "grade-1", isLocked: true, maxValue: 20, periodId: "period-1" });
      await expect(service.update("grade-1", { value: 10 }, "tenant-1")).rejects.toThrow(ForbiddenException);
    });

    it("rejette (400) une note hors barème", async () => {
      prisma.grade.findFirst.mockResolvedValue({ id: "grade-1", isLocked: false, maxValue: 20, periodId: "period-1", isAbsent: false, value: 10 });
      prisma.period.findFirst.mockResolvedValue({ id: "period-1", gradingStatus: GradingStatus.OPEN });
      await expect(service.update("grade-1", { value: 25 }, "tenant-1")).rejects.toThrow(BadRequestException);
    });

    it("met à jour la note dans la limite du barème", async () => {
      prisma.grade.findFirst.mockResolvedValue({ id: "grade-1", isLocked: false, maxValue: 20, periodId: "period-1", isAbsent: false, value: 10 });
      prisma.period.findFirst.mockResolvedValue({ id: "period-1", gradingStatus: GradingStatus.OPEN });

      await service.update("grade-1", { value: 18 }, "tenant-1");

      expect(prisma.grade.update).toHaveBeenCalledWith(
        expect.objectContaining({ where: { id: "grade-1" }, data: expect.objectContaining({ value: 18 }) }),
      );
    });
  });

  describe("lock / unlock", () => {
    it("rejette (404) si la période est introuvable", async () => {
      prisma.period.findFirst.mockResolvedValue(null);
      await expect(service.lock("class-1", "subject-1", "period-1", "tenant-1")).rejects.toThrow(NotFoundException);
    });

    it("rejette (404) si ni la matière ni l'EC ne sont trouvés", async () => {
      prisma.period.findFirst.mockResolvedValue({ id: "period-1", academicYearId: "year-1" });
      prisma.subject.findFirst.mockResolvedValue(null);
      prisma.courseElement.findFirst.mockResolvedValue(null);
      await expect(service.lock("class-1", "subject-1", "period-1", "tenant-1")).rejects.toThrow(NotFoundException);
    });

    it("verrouille les notes de la matière pour la classe et la période", async () => {
      prisma.period.findFirst.mockResolvedValue({ id: "period-1", academicYearId: "year-1" });
      prisma.enrollment.findMany.mockResolvedValue([{ studentId: "s1" }, { studentId: "s2" }]);
      prisma.subject.findFirst.mockResolvedValue({ id: "subject-1" });
      prisma.grade.updateMany.mockResolvedValue({ count: 4 });

      const result = await service.lock("class-1", "subject-1", "period-1", "tenant-1");

      expect(result).toEqual({ locked: true, count: 4 });
      expect(prisma.grade.updateMany).toHaveBeenCalledWith(
        expect.objectContaining({
          where: expect.objectContaining({ periodId: "period-1", subjectId: "subject-1", studentId: { in: ["s1", "s2"] } }),
          data: { isLocked: true },
        }),
      );
    });

    it("déverrouille les notes de l'EC pour la classe et la période", async () => {
      prisma.period.findFirst.mockResolvedValue({ id: "period-1", academicYearId: "year-1" });
      prisma.subject.findFirst.mockResolvedValue(null);
      prisma.courseElement.findFirst.mockResolvedValue({ id: "ec-1" });
      prisma.grade.updateMany.mockResolvedValue({ count: 2 });

      const result = await service.unlock("class-1", "ec-1", "period-1", "tenant-1");

      expect(result).toEqual({ locked: false, count: 2 });
      expect(prisma.grade.updateMany).toHaveBeenCalledWith(
        expect.objectContaining({ where: expect.objectContaining({ courseElementId: "ec-1" }), data: { isLocked: false } }),
      );
    });
  });

  describe("calculate", () => {
    it("rejette (400) si aucun élève n'est inscrit dans la classe", async () => {
      prisma.period.findFirst.mockResolvedValue({ id: "period-1", academicYearId: "year-1" });
      prisma.classRoom.findFirst.mockResolvedValue({ id: "class-1" });
      prisma.enrollment.findMany.mockResolvedValue([]);
      await expect(service.calculate("class-1", "period-1", "tenant-1")).rejects.toThrow(BadRequestException);
    });

    it("calcule moyenne générale, mention et classement pour deux élèves sur une matière", async () => {
      prisma.period.findFirst.mockResolvedValue({ id: "period-1", academicYearId: "year-1" });
      prisma.classRoom.findFirst.mockResolvedValue({ id: "class-1" });
      prisma.enrollment.findMany.mockResolvedValue([{ studentId: "s1" }, { studentId: "s2" }]);
      prisma.subjectAssignment.findMany.mockResolvedValue([
        { subjectId: "subject-1", courseElementId: null, subject: { coefficient: 2 }, courseElement: null },
      ]);
      // s1 : notes 12 et 16 (poids 1,1) → moyenne 14 ; s2 : note 8 → moyenne 8
      prisma.grade.findMany.mockResolvedValue([
        { studentId: "s1", value: 12, maxValue: 20, weight: 1, isAbsent: false, isJustified: false },
        { studentId: "s1", value: 16, maxValue: 20, weight: 1, isAbsent: false, isJustified: false },
        { studentId: "s2", value: 8, maxValue: 20, weight: 1, isAbsent: false, isJustified: false },
      ]);

      const { periodResults } = await service.calculate("class-1", "period-1", "tenant-1");

      const s1 = periodResults.find((r: any) => r.studentId === "s1")!;
      const s2 = periodResults.find((r: any) => r.studentId === "s2")!;
      expect(Number(s1.generalAverage)).toBe(14);
      expect(Number(s2.generalAverage)).toBe(8);
      expect(s1.rank).toBe(1);
      expect(s2.rank).toBe(2);
      expect(s1.mention).toBe("Bien"); // 14 ≥ seuil "Bien" (14) par défaut
      expect(audit.log).toHaveBeenCalled();
    });
  });

  describe("calculateAnnual", () => {
    it("rejette (404) si l'année académique est introuvable", async () => {
      prisma.academicYear.findFirst.mockResolvedValue(null);
      await expect(service.calculateAnnual("class-1", "year-1", "tenant-1")).rejects.toThrow(NotFoundException);
    });

    it("combine les moyennes trimestrielles pondérées et détermine la décision (PASS/REPEAT)", async () => {
      prisma.academicYear.findFirst.mockResolvedValue({ id: "year-1" });
      prisma.classRoom.findFirst.mockResolvedValue({ id: "class-1" });
      prisma.enrollment.findMany.mockResolvedValue([{ studentId: "s1" }, { studentId: "s2" }]);
      prisma.period.findMany.mockResolvedValue([
        { id: "t1", type: PeriodType.TRIMESTER, number: 1 },
        { id: "t2", type: PeriodType.TRIMESTER, number: 2 },
        { id: "t3", type: PeriodType.TRIMESTER, number: 3 },
      ]);
      prisma.periodResult.findMany.mockResolvedValue([
        { studentId: "s1", periodId: "t1", generalAverage: 12 },
        { studentId: "s1", periodId: "t2", generalAverage: 12 },
        { studentId: "s1", periodId: "t3", generalAverage: 12 },
        { studentId: "s2", periodId: "t1", generalAverage: 6 },
        { studentId: "s2", periodId: "t2", generalAverage: 6 },
        { studentId: "s2", periodId: "t3", generalAverage: 6 },
      ]);

      const { annualResults } = await service.calculateAnnual("class-1", "year-1", "tenant-1");

      const s1 = annualResults.find((r: any) => r.studentId === "s1")!;
      const s2 = annualResults.find((r: any) => r.studentId === "s2")!;
      expect(Number(s1.annualAverage)).toBe(12);
      expect(s1.decision).toBe(AnnualDecision.PASS);
      expect(Number(s2.annualAverage)).toBe(6);
      expect(s2.decision).toBe(AnnualDecision.REPEAT);
    });
  });

  describe("deliberate", () => {
    it("rejette (400) si aucun résultat calculé n'existe pour un élève", async () => {
      prisma.periodResult.findFirst.mockResolvedValue(null);
      await expect(
        service.deliberate("class-1", "period-1", { entries: [{ studentId: "s1", decision: "ADMIS" }] }),
      ).rejects.toThrow(BadRequestException);
    });

    it("met à jour la décision/observations d'un résultat déjà calculé", async () => {
      prisma.periodResult.findFirst.mockResolvedValue({ id: "pr-1", decision: null, observations: null, teacherComment: null });
      const result = await service.deliberate("class-1", "period-1", {
        entries: [{ studentId: "s1", decision: "ADMIS", observations: "Bon trimestre" }],
      });
      expect(result[0]).toEqual(expect.objectContaining({ decision: "ADMIS", observations: "Bon trimestre" }));
    });
  });

  describe("publish", () => {
    it("rejette (400) si aucun résultat n'a été calculé", async () => {
      prisma.periodResult.count.mockResolvedValue(0);
      await expect(service.publish("class-1", "period-1")).rejects.toThrow(BadRequestException);
    });

    it("publie les résultats calculés", async () => {
      prisma.periodResult.count.mockResolvedValue(3);
      prisma.periodResult.updateMany.mockResolvedValue({ count: 3 });
      const result = await service.publish("class-1", "period-1");
      expect(result).toEqual({ published: 3 });
    });
  });

  describe("findByStudent / findResults — visibilité des résultats non publiés", () => {
    it("ne renvoie que les résultats publiés pour un utilisateur sans droit de calcul/publication", async () => {
      await service.findByStudent("student-1", "tenant-1", false);
      expect(prisma.periodResult.findMany).toHaveBeenCalledWith(
        expect.objectContaining({ where: expect.objectContaining({ isPublished: true }) }),
      );
    });

    it("renvoie tous les résultats (publiés ou non) pour un censeur/admin", async () => {
      await service.findByStudent("student-1", "tenant-1", true);
      const callArg = prisma.periodResult.findMany.mock.calls[0][0];
      expect(callArg.where.isPublished).toBeUndefined();
    });
  });
});

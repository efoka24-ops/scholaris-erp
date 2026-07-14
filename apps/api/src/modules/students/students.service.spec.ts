import { ConflictException, NotFoundException } from "@nestjs/common";
import { Gender, ParentRelationship } from "@scholaris/prisma";
import { PrismaService } from "../../prisma/prisma.service";
import { MatriculeService } from "./matricule.service";
import { StudentsService, normalizeName } from "./students.service";

describe("normalizeName", () => {
  it("normalise accents, casse et espaces multiples", () => {
    expect(normalizeName("  Éloïse   NGO  Bassa ")).toBe("eloise ngo bassa");
    expect(normalizeName("ATANGANA")).toBe(normalizeName("Atangana"));
  });
});

describe("StudentsService", () => {
  let service: StudentsService;
  let prisma: {
    student: { findMany: jest.Mock; findFirst: jest.Mock; count: jest.Mock; create: jest.Mock; update: jest.Mock };
    parent: { findFirst: jest.Mock; create: jest.Mock };
    studentParent: { create: jest.Mock };
    tenant: { findFirst: jest.Mock };
    matriculeSequence: { upsert: jest.Mock };
    $transaction: jest.Mock;
  };

  const baseDto = {
    firstName: "Aminata",
    lastName: "Ngo Bassa",
    dateOfBirth: "2013-04-21",
    gender: Gender.FEMALE,
    parents: [
      {
        firstName: "Jean",
        lastName: "Ngo Bassa",
        phone: "+237690000001",
        relationship: ParentRelationship.FATHER,
      },
    ],
  };

  beforeEach(() => {
    prisma = {
      student: {
        findMany: jest.fn().mockResolvedValue([]),
        findFirst: jest.fn(),
        count: jest.fn().mockResolvedValue(0),
        create: jest.fn(),
        update: jest.fn(),
      },
      parent: { findFirst: jest.fn(), create: jest.fn() },
      studentParent: { create: jest.fn() },
      tenant: { findFirst: jest.fn().mockResolvedValue({ id: "tenant-1", code: "LBD", configJson: null }) },
      matriculeSequence: { upsert: jest.fn().mockResolvedValue({ lastNumber: 1 }) },
      // La transaction interactive délègue au même mock Prisma.
      $transaction: jest.fn((callback: (tx: unknown) => unknown) => callback(prisma)),
    };
    service = new StudentsService(prisma as unknown as PrismaService, new MatriculeService());
  });

  describe("create", () => {
    it("crée l'élève et ses parents dans une transaction avec un matricule généré", async () => {
      prisma.student.create.mockResolvedValue({ id: "student-1", matricule: "LBD/2026/0001" });
      prisma.parent.create.mockResolvedValue({ id: "parent-1" });
      prisma.student.findFirst.mockResolvedValue({ id: "student-1", parents: [] });

      await service.create(baseDto, "tenant-1");

      expect(prisma.$transaction).toHaveBeenCalledTimes(1);
      expect(prisma.matriculeSequence.upsert).toHaveBeenCalledWith(
        expect.objectContaining({ update: { lastNumber: { increment: 1 } } }),
      );
      expect(prisma.student.create).toHaveBeenCalledWith({
        data: expect.objectContaining({
          firstName: "Aminata",
          lastName: "Ngo Bassa",
          matricule: expect.stringMatching(/^LBD\/\d{4}\/0001$/),
          tenantId: "tenant-1",
        }),
      });
      expect(prisma.parent.create).toHaveBeenCalledTimes(1);
      expect(prisma.studentParent.create).toHaveBeenCalledWith({
        data: { studentId: "student-1", parentId: "parent-1", relationship: ParentRelationship.FATHER },
      });
    });

    it("rejette en 409 avec le détail des doublons (nom+prénom+date normalisés)", async () => {
      prisma.student.findMany.mockResolvedValue([
        {
          id: "student-0",
          matricule: "LBD/2025/0009",
          firstName: "aminata",
          lastName: "NGO  BASSA",
          dateOfBirth: new Date("2013-04-21"),
        },
      ]);

      await expect(service.create(baseDto, "tenant-1")).rejects.toMatchObject({
        response: expect.objectContaining({
          statusCode: 409,
          duplicates: [expect.objectContaining({ id: "student-0", matricule: "LBD/2025/0009" })],
        }),
      });
      expect(prisma.student.create).not.toHaveBeenCalled();
    });

    it("détecte le doublon malgré les accents", async () => {
      prisma.student.findMany.mockResolvedValue([
        {
          id: "student-0",
          matricule: "LBD/2025/0010",
          firstName: "Éloïse",
          lastName: "Mbïa",
          dateOfBirth: new Date("2012-01-15"),
        },
      ]);

      await expect(
        service.create({ ...baseDto, firstName: "Eloise", lastName: "Mbia", dateOfBirth: "2012-01-15" }, "tenant-1"),
      ).rejects.toThrow(ConflictException);
    });

    it("contourne la détection de doublons quand force=true", async () => {
      prisma.student.findMany.mockResolvedValue([
        {
          id: "student-0",
          matricule: "LBD/2025/0009",
          firstName: "Aminata",
          lastName: "Ngo Bassa",
          dateOfBirth: new Date("2013-04-21"),
        },
      ]);
      prisma.student.create.mockResolvedValue({ id: "student-1" });
      prisma.parent.create.mockResolvedValue({ id: "parent-1" });
      prisma.student.findFirst.mockResolvedValue({ id: "student-1", parents: [] });

      await service.create({ ...baseDto, force: true }, "tenant-1");

      expect(prisma.student.create).toHaveBeenCalled();
    });

    it("relie un parent existant via parentId sans en créer un nouveau", async () => {
      prisma.parent.findFirst.mockResolvedValue({ id: "parent-9" });
      prisma.student.create.mockResolvedValue({ id: "student-1" });
      prisma.student.findFirst.mockResolvedValue({ id: "student-1", parents: [] });

      await service.create(
        { ...baseDto, parents: [{ parentId: "parent-9", relationship: ParentRelationship.GUARDIAN }] },
        "tenant-1",
      );

      expect(prisma.parent.create).not.toHaveBeenCalled();
      expect(prisma.studentParent.create).toHaveBeenCalledWith({
        data: { studentId: "student-1", parentId: "parent-9", relationship: ParentRelationship.GUARDIAN },
      });
    });
  });

  describe("findOne", () => {
    it("rejette si l'élève est introuvable", async () => {
      prisma.student.findFirst.mockResolvedValue(null);

      await expect(service.findOne("inconnu")).rejects.toThrow(NotFoundException);
    });
  });

  describe("findAll", () => {
    it("pagine et filtre par classe via les inscriptions actives", async () => {
      prisma.student.findMany.mockResolvedValue([]);
      prisma.student.count.mockResolvedValue(45);

      const result = await service.findAll({ page: 2, limit: 20, classroomId: "class-1" });

      expect(prisma.student.findMany).toHaveBeenCalledWith(
        expect.objectContaining({
          skip: 20,
          take: 20,
          where: expect.objectContaining({
            enrollments: { some: expect.objectContaining({ classroomId: "class-1", status: "ACTIVE" }) },
          }),
        }),
      );
      expect(result.meta).toEqual({ total: 45, page: 2, limit: 20, totalPages: 3 });
    });
  });
});

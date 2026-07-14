import { BadRequestException, ConflictException } from "@nestjs/common";
import { AcademicYearStatus, Prisma } from "@scholaris/prisma";
import { PrismaService } from "../../prisma/prisma.service";
import { AuditService } from "../audit/audit.service";
import { AcademicYearsService } from "./academic-years.service";

function uniqueConstraintError() {
  return new Prisma.PrismaClientKnownRequestError("Unique constraint failed", {
    code: "P2002",
    clientVersion: "5.22.0",
  });
}

describe("AcademicYearsService", () => {
  let service: AcademicYearsService;
  let prisma: {
    academicYear: {
      findFirst: jest.Mock;
      findMany: jest.Mock;
      create: jest.Mock;
      update: jest.Mock;
      updateMany: jest.Mock;
    };
    $transaction: jest.Mock;
  };
  let audit: { log: jest.Mock };

  const baseDto = {
    label: "2026-2027",
    startDate: "2026-09-01",
    endDate: "2027-06-30",
  };

  beforeEach(() => {
    prisma = {
      academicYear: {
        findFirst: jest.fn(),
        findMany: jest.fn(),
        create: jest.fn(),
        update: jest.fn(),
        updateMany: jest.fn(),
      },
      $transaction: jest.fn(),
    };
    // $transaction(callback) exécute le callback avec le client mocké lui-même.
    prisma.$transaction.mockImplementation((callback: (tx: unknown) => unknown) => callback(prisma));
    audit = { log: jest.fn().mockResolvedValue(undefined) };
    service = new AcademicYearsService(prisma as unknown as PrismaService, audit as unknown as AuditService);
  });

  describe("create", () => {
    it("archive l'année active précédente et crée la nouvelle année ACTIVE", async () => {
      prisma.academicYear.updateMany.mockResolvedValue({ count: 1 });
      prisma.academicYear.create.mockResolvedValue({ id: "year-2", ...baseDto, status: "ACTIVE" });

      const result = await service.create(baseDto, "tenant-1");

      expect(prisma.academicYear.updateMany).toHaveBeenCalledWith({
        where: { tenantId: "tenant-1", status: AcademicYearStatus.ACTIVE },
        data: { status: AcademicYearStatus.ARCHIVED },
      });
      expect(prisma.academicYear.create).toHaveBeenCalledWith({
        data: {
          tenantId: "tenant-1",
          label: "2026-2027",
          startDate: new Date("2026-09-01"),
          endDate: new Date("2027-06-30"),
          status: AcademicYearStatus.ACTIVE,
        },
      });
      expect(result).toMatchObject({ id: "year-2", status: "ACTIVE" });
      expect(audit.log).toHaveBeenCalledWith(
        expect.objectContaining({ action: "create", resource: "academic-years", resourceId: "year-2" }),
      );
    });

    it("rejette une date de fin antérieure à la date de début", async () => {
      await expect(
        service.create({ ...baseDto, startDate: "2027-06-30", endDate: "2026-09-01" }, "tenant-1"),
      ).rejects.toThrow(BadRequestException);
      expect(prisma.$transaction).not.toHaveBeenCalled();
    });

    it("transforme une violation de contrainte unique (libellé) en ConflictException", async () => {
      prisma.academicYear.updateMany.mockResolvedValue({ count: 0 });
      prisma.academicYear.create.mockRejectedValue(uniqueConstraintError());

      await expect(service.create(baseDto, "tenant-1")).rejects.toThrow(ConflictException);
    });
  });

  describe("activate", () => {
    it("archive l'année active courante puis active la cible", async () => {
      prisma.academicYear.findFirst.mockResolvedValue({ id: "year-1", status: AcademicYearStatus.ARCHIVED });
      prisma.academicYear.updateMany.mockResolvedValue({ count: 1 });
      prisma.academicYear.update.mockResolvedValue({ id: "year-1", status: AcademicYearStatus.ACTIVE });

      const result = await service.activate("year-1", "tenant-1");

      expect(prisma.academicYear.updateMany).toHaveBeenCalledWith({
        where: { tenantId: "tenant-1", status: AcademicYearStatus.ACTIVE, id: { not: "year-1" } },
        data: { status: AcademicYearStatus.ARCHIVED },
      });
      expect(result.status).toBe(AcademicYearStatus.ACTIVE);
    });

    it("ne fait rien si l'année est déjà active", async () => {
      prisma.academicYear.findFirst.mockResolvedValue({ id: "year-1", status: AcademicYearStatus.ACTIVE });

      await service.activate("year-1", "tenant-1");

      expect(prisma.$transaction).not.toHaveBeenCalled();
    });
  });

  describe("close", () => {
    it("rejette la clôture d'une année non active", async () => {
      prisma.academicYear.findFirst.mockResolvedValue({ id: "year-1", status: AcademicYearStatus.ARCHIVED });

      await expect(service.close("year-1")).rejects.toThrow(BadRequestException);
    });
  });
});

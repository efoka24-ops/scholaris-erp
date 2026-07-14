import { BadRequestException, ConflictException, ForbiddenException, NotFoundException } from "@nestjs/common";
import { GradingStatus, PeriodType, Prisma } from "@scholaris/prisma";
import { PrismaService } from "../../prisma/prisma.service";
import { AuditService } from "../audit/audit.service";
import { PeriodsService } from "./periods.service";

function uniqueConstraintError() {
  return new Prisma.PrismaClientKnownRequestError("Unique constraint failed", {
    code: "P2002",
    clientVersion: "5.22.0",
  });
}

describe("PeriodsService", () => {
  let service: PeriodsService;
  let prisma: {
    period: { findFirst: jest.Mock; findMany: jest.Mock; create: jest.Mock; update: jest.Mock };
    academicYear: { findFirst: jest.Mock };
  };
  let audit: { log: jest.Mock };

  const baseDto = {
    academicYearId: "year-1",
    type: PeriodType.SEQUENCE,
    number: 1,
    startDate: "2026-09-01",
    endDate: "2026-10-31",
  };

  const academicYear = {
    id: "year-1",
    tenantId: "tenant-1",
    startDate: new Date("2026-09-01"),
    endDate: new Date("2027-06-30"),
  };

  beforeEach(() => {
    prisma = {
      period: { findFirst: jest.fn(), findMany: jest.fn(), create: jest.fn(), update: jest.fn() },
      academicYear: { findFirst: jest.fn() },
    };
    audit = { log: jest.fn().mockResolvedValue(undefined) };
    service = new PeriodsService(prisma as unknown as PrismaService, audit as unknown as AuditService);
  });

  describe("create", () => {
    it("crée la période (saisie fermée par défaut) quand l'année existe", async () => {
      prisma.academicYear.findFirst.mockResolvedValue(academicYear);
      prisma.period.create.mockResolvedValue({ id: "period-1", ...baseDto });

      await service.create(baseDto, "tenant-1");

      expect(prisma.period.create).toHaveBeenCalledWith({
        data: expect.objectContaining({
          academicYearId: "year-1",
          type: PeriodType.SEQUENCE,
          number: 1,
          gradingStatus: GradingStatus.CLOSED,
        }),
      });
    });

    it("rejette si l'année académique est introuvable", async () => {
      prisma.academicYear.findFirst.mockResolvedValue(null);

      await expect(service.create(baseDto, "tenant-1")).rejects.toThrow(BadRequestException);
      expect(prisma.period.create).not.toHaveBeenCalled();
    });

    it("rejette des dates hors de l'année académique", async () => {
      prisma.academicYear.findFirst.mockResolvedValue(academicYear);

      await expect(
        service.create({ ...baseDto, startDate: "2026-07-01", endDate: "2026-08-01" }, "tenant-1"),
      ).rejects.toThrow(BadRequestException);
    });

    it("transforme un doublon (type, numéro) en ConflictException", async () => {
      prisma.academicYear.findFirst.mockResolvedValue(academicYear);
      prisma.period.create.mockRejectedValue(uniqueConstraintError());

      await expect(service.create(baseDto, "tenant-1")).rejects.toThrow(ConflictException);
    });
  });

  describe("updateStatus", () => {
    it("ouvre la saisie d'une période fermée", async () => {
      prisma.period.findFirst.mockResolvedValue({ id: "period-1", gradingStatus: GradingStatus.CLOSED });
      prisma.period.update.mockResolvedValue({ id: "period-1", gradingStatus: GradingStatus.OPEN });

      const result = await service.updateStatus("period-1", GradingStatus.OPEN, "tenant-1", false);

      expect(result.gradingStatus).toBe(GradingStatus.OPEN);
      expect(audit.log).toHaveBeenCalledWith(
        expect.objectContaining({
          action: "update",
          resource: "periods",
          oldValue: { gradingStatus: GradingStatus.CLOSED },
          newValue: { gradingStatus: GradingStatus.OPEN },
        }),
      );
    });

    it("refuse de rouvrir une période verrouillée sans la permission periods:unlock", async () => {
      prisma.period.findFirst.mockResolvedValue({ id: "period-1", gradingStatus: GradingStatus.LOCKED });

      await expect(service.updateStatus("period-1", GradingStatus.OPEN, "tenant-1", false)).rejects.toThrow(
        ForbiddenException,
      );
      expect(prisma.period.update).not.toHaveBeenCalled();
    });

    it("autorise un admin (periods:unlock) à rouvrir une période verrouillée", async () => {
      prisma.period.findFirst.mockResolvedValue({ id: "period-1", gradingStatus: GradingStatus.LOCKED });
      prisma.period.update.mockResolvedValue({ id: "period-1", gradingStatus: GradingStatus.OPEN });

      const result = await service.updateStatus("period-1", GradingStatus.OPEN, "tenant-1", true);

      expect(result.gradingStatus).toBe(GradingStatus.OPEN);
    });

    it("rejette une période introuvable (ou d'un autre établissement)", async () => {
      prisma.period.findFirst.mockResolvedValue(null);

      await expect(service.updateStatus("period-x", GradingStatus.OPEN, "tenant-1", true)).rejects.toThrow(
        NotFoundException,
      );
      expect(prisma.period.findFirst).toHaveBeenCalledWith({
        where: { id: "period-x", academicYear: { tenantId: "tenant-1" } },
      });
    });
  });
});

import { ConflictException } from "@nestjs/common";
import { Prisma } from "@scholaris/prisma";
import { PrismaService } from "../../prisma/prisma.service";
import { CyclesService } from "./cycles.service";

function uniqueConstraintError() {
  return new Prisma.PrismaClientKnownRequestError("Unique constraint failed", {
    code: "P2002",
    clientVersion: "5.22.0",
  });
}

describe("CyclesService", () => {
  let service: CyclesService;
  let prisma: {
    cycle: { findFirst: jest.Mock; findMany: jest.Mock; create: jest.Mock; update: jest.Mock; delete: jest.Mock };
    level: { count: jest.Mock };
  };

  beforeEach(() => {
    prisma = {
      cycle: {
        findFirst: jest.fn(),
        findMany: jest.fn(),
        create: jest.fn(),
        update: jest.fn(),
        delete: jest.fn(),
      },
      level: { count: jest.fn() },
    };
    service = new CyclesService(prisma as unknown as PrismaService);
  });

  describe("create", () => {
    it("auto-incrémente order quand il n'est pas fourni", async () => {
      prisma.cycle.findFirst.mockResolvedValue({ order: 2 });
      prisma.cycle.create.mockResolvedValue({ id: "cycle-1", order: 3 });

      await service.create({ code: "SEC2", name: "Second cycle" }, "tenant-1");

      expect(prisma.cycle.create).toHaveBeenCalledWith({
        data: { code: "SEC2", name: "Second cycle", order: 3, tenantId: "tenant-1" },
      });
    });

    it("part de 0 quand aucun cycle n'existe encore pour le tenant", async () => {
      prisma.cycle.findFirst.mockResolvedValue(null);
      prisma.cycle.create.mockResolvedValue({ id: "cycle-1", order: 0 });

      await service.create({ code: "SEC1", name: "Premier cycle" }, "tenant-1");

      expect(prisma.cycle.create).toHaveBeenCalledWith(
        expect.objectContaining({ data: expect.objectContaining({ order: 0 }) }),
      );
    });

    it("respecte order si explicitement fourni", async () => {
      prisma.cycle.create.mockResolvedValue({ id: "cycle-1", order: 10 });

      await service.create({ code: "SEC1", name: "Premier cycle", order: 10 }, "tenant-1");

      expect(prisma.cycle.findFirst).not.toHaveBeenCalled();
      expect(prisma.cycle.create).toHaveBeenCalledWith(
        expect.objectContaining({ data: expect.objectContaining({ order: 10 }) }),
      );
    });

    it("transforme une violation de contrainte unique en ConflictException", async () => {
      prisma.cycle.findFirst.mockResolvedValue(null);
      prisma.cycle.create.mockRejectedValue(uniqueConstraintError());

      await expect(service.create({ code: "SEC1", name: "Premier cycle" }, "tenant-1")).rejects.toThrow(
        ConflictException,
      );
    });
  });

  describe("remove", () => {
    it("refuse la suppression si des niveaux sont rattachés", async () => {
      prisma.cycle.findFirst.mockResolvedValue({ id: "cycle-1" });
      prisma.level.count.mockResolvedValue(2);

      await expect(service.remove("cycle-1")).rejects.toThrow(ConflictException);
      expect(prisma.cycle.delete).not.toHaveBeenCalled();
    });

    it("supprime si aucun niveau n'est rattaché", async () => {
      prisma.cycle.findFirst.mockResolvedValue({ id: "cycle-1" });
      prisma.level.count.mockResolvedValue(0);

      await service.remove("cycle-1");

      expect(prisma.cycle.delete).toHaveBeenCalledWith({ where: { id: "cycle-1" } });
    });
  });
});

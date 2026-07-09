import { BadRequestException, ConflictException } from "@nestjs/common";
import { PrismaService } from "../../prisma/prisma.service";
import { LevelsService } from "./levels.service";

describe("LevelsService", () => {
  let service: LevelsService;
  let prisma: {
    level: { findFirst: jest.Mock; create: jest.Mock; update: jest.Mock; delete: jest.Mock };
    cycle: { findFirst: jest.Mock };
    program: { findFirst: jest.Mock };
    classRoom: { count: jest.Mock };
  };

  beforeEach(() => {
    prisma = {
      level: { findFirst: jest.fn(), create: jest.fn(), update: jest.fn(), delete: jest.fn() },
      cycle: { findFirst: jest.fn() },
      program: { findFirst: jest.fn() },
      classRoom: { count: jest.fn() },
    };
    service = new LevelsService(prisma as unknown as PrismaService);
  });

  describe("create", () => {
    it("auto-incrémente order quand il n'est pas fourni", async () => {
      prisma.cycle.findFirst.mockResolvedValue({ id: "cycle-1" });
      prisma.level.findFirst.mockResolvedValue({ order: 4 });
      prisma.level.create.mockResolvedValue({ id: "level-1", order: 5 });

      await service.create({ code: "6EME", name: "6ème", cycleId: "cycle-1" }, "tenant-1");

      expect(prisma.level.create).toHaveBeenCalledWith(
        expect.objectContaining({ data: expect.objectContaining({ order: 5 }) }),
      );
    });

    it("rejette si le cycle indiqué n'existe pas", async () => {
      prisma.cycle.findFirst.mockResolvedValue(null);

      await expect(
        service.create({ code: "6EME", name: "6ème", cycleId: "cycle-inconnu" }, "tenant-1"),
      ).rejects.toThrow(BadRequestException);
      expect(prisma.level.create).not.toHaveBeenCalled();
    });
  });

  describe("remove", () => {
    it("refuse la suppression si des classes sont rattachées au niveau", async () => {
      prisma.level.findFirst.mockResolvedValue({ id: "level-1" });
      prisma.classRoom.count.mockResolvedValue(1);

      await expect(service.remove("level-1")).rejects.toThrow(ConflictException);
      expect(prisma.level.delete).not.toHaveBeenCalled();
    });

    it("supprime si aucune classe n'est rattachée", async () => {
      prisma.level.findFirst.mockResolvedValue({ id: "level-1" });
      prisma.classRoom.count.mockResolvedValue(0);

      await service.remove("level-1");

      expect(prisma.level.delete).toHaveBeenCalledWith({ where: { id: "level-1" } });
    });
  });
});

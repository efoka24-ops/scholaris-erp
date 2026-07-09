import { BadRequestException, ConflictException } from "@nestjs/common";
import { Prisma, Section } from "@scholaris/prisma";
import { PrismaService } from "../../prisma/prisma.service";
import { ClassroomsService } from "./classrooms.service";

function uniqueConstraintError() {
  return new Prisma.PrismaClientKnownRequestError("Unique constraint failed", {
    code: "P2002",
    clientVersion: "5.22.0",
  });
}

describe("ClassroomsService", () => {
  let service: ClassroomsService;
  let prisma: {
    classRoom: { findFirst: jest.Mock; findMany: jest.Mock; create: jest.Mock; update: jest.Mock };
    level: { findFirst: jest.Mock };
    user: { findFirst: jest.Mock };
    room: { findFirst: jest.Mock };
  };

  const baseDto = {
    code: "6EME-A",
    name: "6ème A",
    capacity: 60,
    levelId: "level-1",
    section: Section.FRANCOPHONE,
  };

  beforeEach(() => {
    prisma = {
      classRoom: { findFirst: jest.fn(), findMany: jest.fn(), create: jest.fn(), update: jest.fn() },
      level: { findFirst: jest.fn() },
      user: { findFirst: jest.fn() },
      room: { findFirst: jest.fn() },
    };
    service = new ClassroomsService(prisma as unknown as PrismaService);
  });

  describe("create", () => {
    it("crée la classe quand le niveau existe et aucune référence optionnelle n'est fournie", async () => {
      prisma.level.findFirst.mockResolvedValue({ id: "level-1" });
      prisma.classRoom.create.mockResolvedValue({ id: "classroom-1", ...baseDto });

      await service.create(baseDto, "tenant-1");

      expect(prisma.classRoom.create).toHaveBeenCalledWith({ data: { ...baseDto, tenantId: "tenant-1" } });
      expect(prisma.user.findFirst).not.toHaveBeenCalled();
      expect(prisma.room.findFirst).not.toHaveBeenCalled();
    });

    it("rejette si le niveau indiqué n'existe pas", async () => {
      prisma.level.findFirst.mockResolvedValue(null);

      await expect(service.create(baseDto, "tenant-1")).rejects.toThrow(BadRequestException);
      expect(prisma.classRoom.create).not.toHaveBeenCalled();
    });

    it("rejette si l'enseignant principal indiqué n'existe pas", async () => {
      prisma.level.findFirst.mockResolvedValue({ id: "level-1" });
      prisma.user.findFirst.mockResolvedValue(null);

      await expect(service.create({ ...baseDto, mainTeacherId: "user-inconnu" }, "tenant-1")).rejects.toThrow(
        BadRequestException,
      );
    });

    it("rejette si la salle indiquée n'existe pas", async () => {
      prisma.level.findFirst.mockResolvedValue({ id: "level-1" });
      prisma.room.findFirst.mockResolvedValue(null);

      await expect(service.create({ ...baseDto, roomId: "room-inconnue" }, "tenant-1")).rejects.toThrow(
        BadRequestException,
      );
    });

    it("transforme une violation de contrainte unique en ConflictException", async () => {
      prisma.level.findFirst.mockResolvedValue({ id: "level-1" });
      prisma.classRoom.create.mockRejectedValue(uniqueConstraintError());

      await expect(service.create(baseDto, "tenant-1")).rejects.toThrow(ConflictException);
    });
  });

  describe("findAll", () => {
    it("filtre par niveau et par programme quand fournis", async () => {
      prisma.classRoom.findMany.mockResolvedValue([]);

      await service.findAll({ levelId: "level-1", programId: "program-1" });

      expect(prisma.classRoom.findMany).toHaveBeenCalledWith(
        expect.objectContaining({
          where: { levelId: "level-1", level: { programId: "program-1" } },
        }),
      );
    });
  });
});

import { BadRequestException, ConflictException, Injectable, NotFoundException } from "@nestjs/common";
import { Prisma } from "@scholaris/prisma";
import { PrismaService } from "../../prisma/prisma.service";
import { CreateClassRoomDto } from "./dto/create-classroom.dto";
import { UpdateClassRoomDto } from "./dto/update-classroom.dto";

const UNIQUE_CONSTRAINT_ERROR = "P2002";

export interface FindClassroomsQuery {
  levelId?: string;
  programId?: string;
}

@Injectable()
export class ClassroomsService {
  constructor(private readonly prisma: PrismaService) {}

  async findAll(query: FindClassroomsQuery) {
    return this.prisma.classRoom.findMany({
      where: {
        levelId: query.levelId,
        ...(query.programId ? { level: { programId: query.programId } } : {}),
      },
      orderBy: { name: "asc" },
    });
  }

  async findOne(id: string) {
    const classroom = await this.prisma.classRoom.findFirst({ where: { id } });
    if (!classroom) {
      throw new NotFoundException("Classe introuvable");
    }
    return classroom;
  }

  async create(dto: CreateClassRoomDto, tenantId: string) {
    await this.assertLevelExists(dto.levelId);
    if (dto.mainTeacherId) {
      await this.assertUserExists(dto.mainTeacherId);
    }
    if (dto.roomId) {
      await this.assertRoomExists(dto.roomId);
    }
    try {
      return await this.prisma.classRoom.create({ data: { ...dto, tenantId } });
    } catch (error) {
      throw this.mapUniqueConstraint(error);
    }
  }

  async update(id: string, dto: UpdateClassRoomDto) {
    await this.findOne(id);
    if (dto.levelId) {
      await this.assertLevelExists(dto.levelId);
    }
    if (dto.mainTeacherId) {
      await this.assertUserExists(dto.mainTeacherId);
    }
    if (dto.roomId) {
      await this.assertRoomExists(dto.roomId);
    }
    try {
      return await this.prisma.classRoom.update({ where: { id }, data: dto });
    } catch (error) {
      throw this.mapUniqueConstraint(error);
    }
  }

  private async assertLevelExists(levelId: string) {
    const level = await this.prisma.level.findFirst({ where: { id: levelId } });
    if (!level) {
      throw new BadRequestException("Le niveau indiqué est introuvable");
    }
  }

  private async assertUserExists(userId: string) {
    const user = await this.prisma.user.findFirst({ where: { id: userId } });
    if (!user) {
      throw new BadRequestException("L'enseignant principal désigné est introuvable");
    }
  }

  private async assertRoomExists(roomId: string) {
    const room = await this.prisma.room.findFirst({ where: { id: roomId } });
    if (!room) {
      throw new BadRequestException("La salle indiquée est introuvable");
    }
  }

  private mapUniqueConstraint(error: unknown): Error {
    if (error instanceof Prisma.PrismaClientKnownRequestError && error.code === UNIQUE_CONSTRAINT_ERROR) {
      return new ConflictException("Une classe avec ce code existe déjà");
    }
    return error as Error;
  }
}

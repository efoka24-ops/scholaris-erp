import { ConflictException, Injectable, NotFoundException } from "@nestjs/common";
import { Prisma } from "@scholaris/prisma";
import { PrismaService } from "../../prisma/prisma.service";
import { CreateRoomDto } from "./dto/create-room.dto";
import { UpdateRoomDto } from "./dto/update-room.dto";

const UNIQUE_CONSTRAINT_ERROR = "P2002";

@Injectable()
export class RoomsService {
  constructor(private readonly prisma: PrismaService) {}

  async findAll() {
    const rooms = await this.prisma.room.findMany({
      orderBy: { name: "asc" },
      include: { _count: { select: { classrooms: true } } },
    });
    // "Taux d'occupation" simplifié en Phase 2 : nombre de classes affectées à
    // la salle. Une occupation réelle (créneaux horaires) arrive avec le
    // module Emplois du temps.
    return rooms.map(({ _count, ...room }) => ({ ...room, classroomsCount: _count.classrooms }));
  }

  async findOne(id: string) {
    const room = await this.prisma.room.findFirst({ where: { id } });
    if (!room) {
      throw new NotFoundException("Salle introuvable");
    }
    return room;
  }

  async create(dto: CreateRoomDto, tenantId: string) {
    try {
      return await this.prisma.room.create({
        data: { ...dto, equipment: dto.equipment ?? [], tenantId },
      });
    } catch (error) {
      throw this.mapUniqueConstraint(error);
    }
  }

  async update(id: string, dto: UpdateRoomDto) {
    await this.findOne(id);
    try {
      return await this.prisma.room.update({ where: { id }, data: dto });
    } catch (error) {
      throw this.mapUniqueConstraint(error);
    }
  }

  async remove(id: string) {
    await this.findOne(id);
    const classroomsCount = await this.prisma.classRoom.count({ where: { roomId: id } });
    if (classroomsCount > 0) {
      throw new ConflictException("Impossible de supprimer une salle affectée à des classes");
    }
    await this.prisma.room.delete({ where: { id } });
  }

  private mapUniqueConstraint(error: unknown): Error {
    if (error instanceof Prisma.PrismaClientKnownRequestError && error.code === UNIQUE_CONSTRAINT_ERROR) {
      return new ConflictException("Une salle avec ce code existe déjà");
    }
    return error as Error;
  }
}

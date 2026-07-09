import { ConflictException, Injectable, NotFoundException } from "@nestjs/common";
import { Prisma } from "@scholaris/prisma";
import { PrismaService } from "../../prisma/prisma.service";
import { CreateCycleDto } from "./dto/create-cycle.dto";
import { UpdateCycleDto } from "./dto/update-cycle.dto";

const UNIQUE_CONSTRAINT_ERROR = "P2002";

@Injectable()
export class CyclesService {
  constructor(private readonly prisma: PrismaService) {}

  async findAll() {
    return this.prisma.cycle.findMany({ orderBy: { order: "asc" } });
  }

  async findOne(id: string) {
    const cycle = await this.prisma.cycle.findFirst({ where: { id } });
    if (!cycle) {
      throw new NotFoundException("Cycle introuvable");
    }
    return cycle;
  }

  async create(dto: CreateCycleDto, tenantId: string) {
    const order = dto.order ?? (await this.nextOrder(tenantId));
    try {
      return await this.prisma.cycle.create({
        data: { code: dto.code, name: dto.name, order, tenantId },
      });
    } catch (error) {
      throw this.mapUniqueConstraint(error);
    }
  }

  async update(id: string, dto: UpdateCycleDto) {
    await this.findOne(id);
    try {
      return await this.prisma.cycle.update({ where: { id }, data: dto });
    } catch (error) {
      throw this.mapUniqueConstraint(error);
    }
  }

  async remove(id: string) {
    await this.findOne(id);
    const levelsCount = await this.prisma.level.count({ where: { cycleId: id } });
    if (levelsCount > 0) {
      throw new ConflictException("Impossible de supprimer un cycle qui contient des niveaux");
    }
    await this.prisma.cycle.delete({ where: { id } });
  }

  private async nextOrder(tenantId: string): Promise<number> {
    const last = await this.prisma.cycle.findFirst({
      where: { tenantId },
      orderBy: { order: "desc" },
    });
    return (last?.order ?? -1) + 1;
  }

  private mapUniqueConstraint(error: unknown): Error {
    if (error instanceof Prisma.PrismaClientKnownRequestError && error.code === UNIQUE_CONSTRAINT_ERROR) {
      return new ConflictException("Un cycle avec ce code existe déjà");
    }
    return error as Error;
  }
}

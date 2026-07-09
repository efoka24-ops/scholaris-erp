import { BadRequestException, ConflictException, Injectable, NotFoundException } from "@nestjs/common";
import { Prisma } from "@scholaris/prisma";
import { PrismaService } from "../../prisma/prisma.service";
import { CreateLevelDto } from "./dto/create-level.dto";
import { UpdateLevelDto } from "./dto/update-level.dto";

const UNIQUE_CONSTRAINT_ERROR = "P2002";

@Injectable()
export class LevelsService {
  constructor(private readonly prisma: PrismaService) {}

  async findAll() {
    return this.prisma.level.findMany({ orderBy: { order: "asc" } });
  }

  async findOne(id: string) {
    const level = await this.prisma.level.findFirst({ where: { id } });
    if (!level) {
      throw new NotFoundException("Niveau introuvable");
    }
    return level;
  }

  async create(dto: CreateLevelDto, tenantId: string) {
    await this.assertCycleExists(dto.cycleId);
    if (dto.programId) {
      await this.assertProgramExists(dto.programId);
    }
    const order = dto.order ?? (await this.nextOrder(tenantId));
    try {
      return await this.prisma.level.create({
        data: {
          code: dto.code,
          name: dto.name,
          order,
          cycleId: dto.cycleId,
          programId: dto.programId,
          tenantId,
        },
      });
    } catch (error) {
      throw this.mapUniqueConstraint(error);
    }
  }

  async update(id: string, dto: UpdateLevelDto) {
    await this.findOne(id);
    if (dto.cycleId) {
      await this.assertCycleExists(dto.cycleId);
    }
    if (dto.programId) {
      await this.assertProgramExists(dto.programId);
    }
    try {
      return await this.prisma.level.update({ where: { id }, data: dto });
    } catch (error) {
      throw this.mapUniqueConstraint(error);
    }
  }

  /**
   * Le guide (§2.6.2) exige un 409 « si le niveau a des classes avec inscriptions ».
   * Le modèle Enrollment (Module 4) n'existe pas encore : on bloque ici sur la
   * seule présence de classes rattachées, condition strictement plus large et
   * donc sûre (aucune suppression prématurée possible). À resserrer sur les
   * inscriptions actives dès que Module 4 est livré.
   */
  async remove(id: string) {
    await this.findOne(id);
    const classroomsCount = await this.prisma.classRoom.count({ where: { levelId: id } });
    if (classroomsCount > 0) {
      throw new ConflictException("Impossible de supprimer un niveau qui a des classes rattachées");
    }
    await this.prisma.level.delete({ where: { id } });
  }

  private async nextOrder(tenantId: string): Promise<number> {
    const last = await this.prisma.level.findFirst({
      where: { tenantId },
      orderBy: { order: "desc" },
    });
    return (last?.order ?? -1) + 1;
  }

  private async assertCycleExists(cycleId: string) {
    const cycle = await this.prisma.cycle.findFirst({ where: { id: cycleId } });
    if (!cycle) {
      throw new BadRequestException("Le cycle indiqué est introuvable");
    }
  }

  private async assertProgramExists(programId: string) {
    const program = await this.prisma.program.findFirst({ where: { id: programId } });
    if (!program) {
      throw new BadRequestException("La filière/le programme indiqué est introuvable");
    }
  }

  private mapUniqueConstraint(error: unknown): Error {
    if (error instanceof Prisma.PrismaClientKnownRequestError && error.code === UNIQUE_CONSTRAINT_ERROR) {
      return new ConflictException("Un niveau avec ce code existe déjà");
    }
    return error as Error;
  }
}

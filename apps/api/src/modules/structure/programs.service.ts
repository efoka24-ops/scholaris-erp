import { BadRequestException, ConflictException, Injectable, NotFoundException } from "@nestjs/common";
import { Prisma } from "@scholaris/prisma";
import { PrismaService } from "../../prisma/prisma.service";
import { CreateProgramDto } from "./dto/create-program.dto";
import { UpdateProgramDto } from "./dto/update-program.dto";

const UNIQUE_CONSTRAINT_ERROR = "P2002";

@Injectable()
export class ProgramsService {
  constructor(private readonly prisma: PrismaService) {}

  async findAll() {
    return this.prisma.program.findMany({ orderBy: { name: "asc" } });
  }

  async findOne(id: string) {
    const program = await this.prisma.program.findFirst({ where: { id } });
    if (!program) {
      throw new NotFoundException("Filière/programme introuvable");
    }
    return program;
  }

  async create(dto: CreateProgramDto, tenantId: string) {
    await this.assertCycleExists(dto.cycleId);
    if (dto.departmentId) {
      await this.assertDepartmentExists(dto.departmentId);
    }
    try {
      return await this.prisma.program.create({
        data: { code: dto.code, name: dto.name, cycleId: dto.cycleId, departmentId: dto.departmentId, tenantId },
      });
    } catch (error) {
      throw this.mapUniqueConstraint(error);
    }
  }

  async update(id: string, dto: UpdateProgramDto) {
    await this.findOne(id);
    if (dto.cycleId) {
      await this.assertCycleExists(dto.cycleId);
    }
    if (dto.departmentId) {
      await this.assertDepartmentExists(dto.departmentId);
    }
    try {
      return await this.prisma.program.update({ where: { id }, data: dto });
    } catch (error) {
      throw this.mapUniqueConstraint(error);
    }
  }

  async remove(id: string) {
    await this.findOne(id);
    const levelsCount = await this.prisma.level.count({ where: { programId: id } });
    if (levelsCount > 0) {
      throw new ConflictException("Impossible de supprimer une filière/un programme qui a des niveaux rattachés");
    }
    await this.prisma.program.delete({ where: { id } });
  }

  private async assertCycleExists(cycleId: string) {
    const cycle = await this.prisma.cycle.findFirst({ where: { id: cycleId } });
    if (!cycle) {
      throw new BadRequestException("Le cycle indiqué est introuvable");
    }
  }

  private async assertDepartmentExists(departmentId: string) {
    const department = await this.prisma.department.findFirst({ where: { id: departmentId } });
    if (!department) {
      throw new BadRequestException("Le département indiqué est introuvable");
    }
  }

  private mapUniqueConstraint(error: unknown): Error {
    if (error instanceof Prisma.PrismaClientKnownRequestError && error.code === UNIQUE_CONSTRAINT_ERROR) {
      return new ConflictException("Une filière/un programme avec ce code existe déjà");
    }
    return error as Error;
  }
}

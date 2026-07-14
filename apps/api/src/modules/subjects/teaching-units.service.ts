import { BadRequestException, ConflictException, Injectable, NotFoundException } from "@nestjs/common";
import { Prisma, TeachingUnit } from "@scholaris/prisma";
import { PrismaService } from "../../prisma/prisma.service";
import { CreateTeachingUnitDto } from "./dto/create-teaching-unit.dto";

const UNIQUE_CONSTRAINT_ERROR = "P2002";

export interface FindTeachingUnitsQuery {
  departmentId?: string;
  semester?: number;
}

@Injectable()
export class TeachingUnitsService {
  constructor(private readonly prisma: PrismaService) {}

  /** Liste hiérarchique UE → EC (les EC supprimés sont exclus). */
  async findAll(query: FindTeachingUnitsQuery): Promise<TeachingUnit[]> {
    return this.prisma.teachingUnit.findMany({
      where: {
        deletedAt: null,
        ...(query.departmentId ? { departmentId: query.departmentId } : {}),
        ...(query.semester ? { semester: query.semester } : {}),
      },
      include: {
        courseElements: { where: { deletedAt: null }, orderBy: { code: "asc" } },
        department: { select: { id: true, code: true, name: true } },
      },
      orderBy: [{ semester: "asc" }, { code: "asc" }],
    });
  }

  async findOne(id: string): Promise<TeachingUnit> {
    const unit = await this.prisma.teachingUnit.findFirst({
      where: { id },
      include: { courseElements: { where: { deletedAt: null }, orderBy: { code: "asc" } } },
    });
    if (!unit) {
      throw new NotFoundException("Unité d'enseignement introuvable");
    }
    return unit;
  }

  async create(dto: CreateTeachingUnitDto, tenantId: string): Promise<TeachingUnit> {
    await this.assertDepartmentExists(dto.departmentId);
    try {
      return await this.prisma.teachingUnit.create({
        data: { ...dto, isFundamental: dto.isFundamental ?? false, tenantId },
      });
    } catch (error) {
      throw this.mapUniqueConstraint(error);
    }
  }

  private async assertDepartmentExists(departmentId: string): Promise<void> {
    const department = await this.prisma.department.findFirst({ where: { id: departmentId } });
    if (!department) {
      throw new BadRequestException("Le département indiqué est introuvable");
    }
  }

  private mapUniqueConstraint(error: unknown): Error {
    if (error instanceof Prisma.PrismaClientKnownRequestError && error.code === UNIQUE_CONSTRAINT_ERROR) {
      return new ConflictException("Une UE avec ce code existe déjà");
    }
    return error as Error;
  }
}

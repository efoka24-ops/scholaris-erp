import { BadRequestException, ConflictException, Injectable, NotFoundException } from "@nestjs/common";
import { CourseElement, Prisma } from "@scholaris/prisma";
import { PrismaService } from "../../prisma/prisma.service";
import { CreateCourseElementDto } from "./dto/create-course-element.dto";

const UNIQUE_CONSTRAINT_ERROR = "P2002";

export interface FindCourseElementsQuery {
  teachingUnitId?: string;
}

@Injectable()
export class CourseElementsService {
  constructor(private readonly prisma: PrismaService) {}

  async findAll(query: FindCourseElementsQuery): Promise<CourseElement[]> {
    return this.prisma.courseElement.findMany({
      where: {
        deletedAt: null,
        ...(query.teachingUnitId ? { teachingUnitId: query.teachingUnitId } : {}),
      },
      orderBy: { code: "asc" },
    });
  }

  async create(dto: CreateCourseElementDto, tenantId: string): Promise<CourseElement> {
    const unit = await this.prisma.teachingUnit.findFirst({ where: { id: dto.teachingUnitId } });
    if (!unit) {
      throw new NotFoundException("L'unité d'enseignement indiquée est introuvable");
    }

    // Règle métier : la somme des crédits des EC d'une UE ne peut pas dépasser
    // les crédits de l'UE elle-même.
    const aggregate = await this.prisma.courseElement.aggregate({
      where: { teachingUnitId: dto.teachingUnitId, deletedAt: null },
      _sum: { credits: true },
    });
    const existingCredits = aggregate._sum.credits ?? 0;
    if (existingCredits + dto.credits > unit.credits) {
      throw new BadRequestException(
        `La somme des crédits des EC (${existingCredits + dto.credits}) dépasserait les crédits de l'UE ${unit.code} (${unit.credits})`,
      );
    }

    try {
      return await this.prisma.courseElement.create({
        data: {
          ...dto,
          hoursCm: dto.hoursCm ?? 0,
          hoursTd: dto.hoursTd ?? 0,
          hoursTp: dto.hoursTp ?? 0,
          coefficient: dto.coefficient ?? 1,
          tenantId,
        },
      });
    } catch (error) {
      throw this.mapUniqueConstraint(error);
    }
  }

  private mapUniqueConstraint(error: unknown): Error {
    if (error instanceof Prisma.PrismaClientKnownRequestError && error.code === UNIQUE_CONSTRAINT_ERROR) {
      return new ConflictException("Un EC avec ce code existe déjà");
    }
    return error as Error;
  }
}

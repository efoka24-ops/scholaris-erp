import { BadRequestException, ConflictException, Injectable, NotFoundException } from "@nestjs/common";
import { Prisma, Subject } from "@scholaris/prisma";
import { buildPaginationMeta, DEFAULT_LIMIT, DEFAULT_PAGE, MAX_LIMIT, PaginatedResult } from "@scholaris/shared";
import { PrismaService } from "../../prisma/prisma.service";
import { CreateSubjectDto } from "./dto/create-subject.dto";
import { UpdateSubjectDto } from "./dto/update-subject.dto";
import { FindSubjectsQueryDto } from "./dto/find-subjects-query.dto";

const UNIQUE_CONSTRAINT_ERROR = "P2002";

@Injectable()
export class SubjectsService {
  constructor(private readonly prisma: PrismaService) {}

  async findAll(query: FindSubjectsQueryDto): Promise<PaginatedResult<Subject>> {
    const page = query.page && query.page > 0 ? query.page : DEFAULT_PAGE;
    const limit = query.limit && query.limit > 0 ? Math.min(query.limit, MAX_LIMIT) : DEFAULT_LIMIT;

    // deletedAt posé explicitement : le middleware soft-delete couvre findMany
    // mais pas count, et le total de pagination doit exclure les supprimées.
    const where: Prisma.SubjectWhereInput = {
      deletedAt: null,
      ...(query.category ? { category: query.category } : {}),
      ...(query.levelId ? { levelIds: { has: query.levelId } } : {}),
      ...(query.search
        ? {
            OR: [
              { code: { contains: query.search, mode: "insensitive" } },
              { name: { contains: query.search, mode: "insensitive" } },
            ],
          }
        : {}),
    };

    const [data, total] = await Promise.all([
      this.prisma.subject.findMany({
        where,
        orderBy: { name: "asc" },
        skip: (page - 1) * limit,
        take: limit,
      }),
      this.prisma.subject.count({ where }),
    ]);

    return { data, meta: buildPaginationMeta(total, page, limit) };
  }

  async findOne(id: string): Promise<Subject> {
    const subject = await this.prisma.subject.findFirst({ where: { id } });
    if (!subject) {
      throw new NotFoundException("Matière introuvable");
    }
    return subject;
  }

  async create(dto: CreateSubjectDto, tenantId: string): Promise<Subject> {
    this.assertCoefficientPositive(dto.coefficient);
    try {
      return await this.prisma.subject.create({
        data: {
          ...dto,
          // Une matière éliminatoire sans seuil explicite démarre à 0 (seuil configurable ensuite).
          eliminatoryThreshold: dto.eliminatoryThreshold ?? 0,
          isEliminatory: dto.isEliminatory ?? false,
          levelIds: dto.levelIds ?? [],
          tenantId,
        },
      });
    } catch (error) {
      throw this.mapUniqueConstraint(error);
    }
  }

  async update(id: string, dto: UpdateSubjectDto): Promise<Subject> {
    await this.findOne(id);
    if (dto.coefficient !== undefined) {
      this.assertCoefficientPositive(dto.coefficient);
    }
    try {
      return await this.prisma.subject.update({ where: { id }, data: dto });
    } catch (error) {
      throw this.mapUniqueConstraint(error);
    }
  }

  async remove(id: string): Promise<Subject> {
    await this.findOne(id);
    // Le middleware soft-delete de PrismaService convertit ce delete en update { deletedAt }.
    return this.prisma.subject.delete({ where: { id } });
  }

  /**
   * Matières d'une classe avec leurs enseignants assignés (année en cours ou
   * toutes années confondues si aucune année active) : lit les assignations de
   * la classe et regroupe les enseignants par matière.
   */
  async findByClassroom(classroomId: string) {
    const classroom = await this.prisma.classRoom.findFirst({ where: { id: classroomId } });
    if (!classroom) {
      throw new NotFoundException("Classe introuvable");
    }

    const assignments = await this.prisma.subjectAssignment.findMany({
      where: { classroomId, deletedAt: null, subjectId: { not: null } },
      include: {
        subject: true,
        teacher: { select: { id: true, firstName: true, lastName: true, email: true } },
        academicYear: { select: { id: true, label: true } },
      },
      orderBy: { createdAt: "asc" },
    });

    const bySubject = new Map<
      string,
      { subject: Subject; teachers: Array<{ id: string; firstName: string; lastName: string; email: string; academicYearLabel: string | null }> }
    >();

    for (const assignment of assignments) {
      if (!assignment.subject) continue;
      const entry = bySubject.get(assignment.subject.id) ?? { subject: assignment.subject, teachers: [] };
      entry.teachers.push({ ...assignment.teacher, academicYearLabel: assignment.academicYear?.label ?? null });
      bySubject.set(assignment.subject.id, entry);
    }

    return {
      classroom: { id: classroom.id, code: classroom.code, name: classroom.name },
      subjects: Array.from(bySubject.values()),
    };
  }

  private assertCoefficientPositive(coefficient: number): void {
    if (!(coefficient > 0)) {
      throw new BadRequestException("Le coefficient doit être strictement positif");
    }
  }

  private mapUniqueConstraint(error: unknown): Error {
    if (error instanceof Prisma.PrismaClientKnownRequestError && error.code === UNIQUE_CONSTRAINT_ERROR) {
      return new ConflictException("Une matière avec ce code existe déjà");
    }
    return error as Error;
  }
}

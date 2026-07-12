import { BadRequestException, ConflictException, Injectable, NotFoundException } from "@nestjs/common";
import { EnrollmentStatus, Prisma, Student } from "@scholaris/prisma";
import { buildPaginationMeta, DEFAULT_LIMIT, DEFAULT_PAGE, MAX_LIMIT, PaginatedResult } from "@scholaris/shared";
import { PrismaService } from "../../prisma/prisma.service";
import { MatriculeService } from "./matricule.service";
import { CreateParentDto } from "./dto/create-parent.dto";
import { CreateStudentDto } from "./dto/create-student.dto";
import { FindStudentsQueryDto } from "./dto/find-students-query.dto";
import { UpdateStudentDto } from "./dto/update-student.dto";

/** Normalise un nom pour la détection de doublons : accents, casse, espaces. */
export function normalizeName(value: string): string {
  return value
    .normalize("NFD")
    .replace(/[̀-ͯ]/g, "")
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, " ")
    .trim();
}

@Injectable()
export class StudentsService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly matricule: MatriculeService,
  ) {}

  /**
   * Crée l'élève et ses parents dans une seule transaction, matricule compris :
   * si la création d'un parent échoue, ni l'élève ni le numéro de séquence ne
   * sont persistés.
   */
  async create(dto: CreateStudentDto, tenantId: string) {
    const duplicates = await this.findDuplicates(dto.firstName, dto.lastName, dto.dateOfBirth);
    if (duplicates.length > 0 && !dto.force) {
      throw new ConflictException({
        message:
          "Un élève avec le même nom, prénom et date de naissance existe déjà. " +
          "Renvoyez la requête avec force=true pour créer malgré tout.",
        error: "Conflict",
        statusCode: 409,
        duplicates: duplicates.map((d) => ({
          id: d.id,
          matricule: d.matricule,
          firstName: d.firstName,
          lastName: d.lastName,
          dateOfBirth: d.dateOfBirth,
        })),
      });
    }

    const tenant = await this.prisma.tenant.findFirst({ where: { id: tenantId } });
    if (!tenant) {
      throw new NotFoundException("Établissement introuvable");
    }

    const { parents, force: _force, ...identity } = dto;

    return this.prisma.$transaction(async (tx) => {
      const matricule = await this.matricule.generate(tx, tenant);

      const student = await tx.student.create({
        data: {
          ...identity,
          dateOfBirth: new Date(dto.dateOfBirth),
          matricule,
          tenantId,
        },
      });

      for (const parentDto of parents ?? []) {
        const parentId = await this.resolveParentId(tx, parentDto, tenantId);
        await tx.studentParent.create({
          data: { studentId: student.id, parentId, relationship: parentDto.relationship },
        });
      }

      return tx.student.findFirst({
        where: { id: student.id },
        include: { parents: { include: { parent: true } } },
      });
    });
  }

  async findAll(query: FindStudentsQueryDto): Promise<PaginatedResult<Student>> {
    const page = query.page ?? DEFAULT_PAGE;
    const limit = Math.min(query.limit ?? DEFAULT_LIMIT, MAX_LIMIT);

    const enrollmentFilter: Prisma.EnrollmentWhereInput = {
      status: EnrollmentStatus.ACTIVE,
      deletedAt: null,
      ...(query.classroomId ? { classroomId: query.classroomId } : {}),
      ...(query.levelId ? { classroom: { levelId: query.levelId } } : {}),
      ...(query.academicYearId ? { academicYearId: query.academicYearId } : {}),
    };

    const where: Prisma.StudentWhereInput = {
      ...(query.status ? { status: query.status } : {}),
      ...(query.search
        ? {
            OR: [
              { firstName: { contains: query.search, mode: "insensitive" } },
              { lastName: { contains: query.search, mode: "insensitive" } },
              { matricule: { contains: query.search, mode: "insensitive" } },
            ],
          }
        : {}),
      ...(query.classroomId || query.levelId || query.academicYearId
        ? { enrollments: { some: enrollmentFilter } }
        : {}),
    };

    const orderBy = { [query.sortBy ?? "lastName"]: query.sortOrder ?? "asc" };

    const [data, total] = await Promise.all([
      this.prisma.student.findMany({
        where,
        orderBy,
        skip: (page - 1) * limit,
        take: limit,
        include: {
          enrollments: {
            where: { status: EnrollmentStatus.ACTIVE, deletedAt: null },
            include: { classroom: true, academicYear: true },
            orderBy: { enrollmentDate: "desc" },
            take: 1,
          },
        },
      }),
      this.prisma.student.count({ where: { ...where, deletedAt: null } }),
    ]);

    return { data, meta: buildPaginationMeta(total, page, limit) };
  }

  /** Dossier complet : identité + parents + historique des inscriptions. */
  async findOne(id: string) {
    const student = await this.prisma.student.findFirst({
      where: { id },
      include: {
        parents: { include: { parent: true } },
        enrollments: {
          where: { deletedAt: null },
          include: { classroom: { include: { level: true } }, academicYear: true },
          orderBy: { enrollmentDate: "desc" },
        },
      },
    });
    if (!student) {
      throw new NotFoundException("Élève introuvable");
    }
    return student;
  }

  async update(id: string, dto: UpdateStudentDto) {
    await this.findOne(id);
    return this.prisma.student.update({
      where: { id },
      data: {
        ...dto,
        ...(dto.dateOfBirth ? { dateOfBirth: new Date(dto.dateOfBirth) } : {}),
      },
    });
  }

  /**
   * Doublons potentiels : même date de naissance et mêmes nom+prénom après
   * normalisation (accents/casse/espaces), y compris nom et prénom inversés.
   */
  async findDuplicates(firstName: string, lastName: string, dateOfBirth: string | Date) {
    const candidates = await this.prisma.student.findMany({
      where: { dateOfBirth: new Date(dateOfBirth) },
    });

    const first = normalizeName(firstName);
    const last = normalizeName(lastName);

    return candidates.filter((candidate) => {
      const candidateFirst = normalizeName(candidate.firstName);
      const candidateLast = normalizeName(candidate.lastName);
      return (
        (candidateFirst === first && candidateLast === last) ||
        (candidateFirst === last && candidateLast === first)
      );
    });
  }

  private async resolveParentId(
    tx: Prisma.TransactionClient,
    parentDto: CreateParentDto,
    tenantId: string,
  ): Promise<string> {
    if (parentDto.parentId) {
      const existing = await tx.parent.findFirst({ where: { id: parentDto.parentId, tenantId } });
      if (!existing) {
        throw new BadRequestException("Le parent référencé est introuvable");
      }
      return existing.id;
    }

    if (!parentDto.firstName || !parentDto.lastName || !parentDto.phone) {
      throw new BadRequestException("Chaque parent doit avoir un nom, un prénom et un téléphone (ou un parentId)");
    }

    const { parentId: _ignored, ...data } = parentDto;
    const parent = await tx.parent.create({
      data: {
        ...data,
        firstName: parentDto.firstName,
        lastName: parentDto.lastName,
        phone: parentDto.phone,
        tenantId,
      },
    });
    return parent.id;
  }
}

import { BadRequestException, ConflictException, Injectable, NotFoundException } from "@nestjs/common";
import { SubjectAssignment } from "@scholaris/prisma";
import { PrismaService } from "../../prisma/prisma.service";
import { CreateSubjectAssignmentDto } from "./dto/create-subject-assignment.dto";

export interface FindSubjectAssignmentsQuery {
  classroomId?: string;
  teacherId?: string;
  academicYearId?: string;
}

@Injectable()
export class SubjectAssignmentsService {
  constructor(private readonly prisma: PrismaService) {}

  async findAll(query: FindSubjectAssignmentsQuery): Promise<SubjectAssignment[]> {
    return this.prisma.subjectAssignment.findMany({
      where: {
        deletedAt: null,
        ...(query.classroomId ? { classroomId: query.classroomId } : {}),
        ...(query.teacherId ? { teacherId: query.teacherId } : {}),
        ...(query.academicYearId ? { academicYearId: query.academicYearId } : {}),
      },
      include: {
        subject: { select: { id: true, code: true, name: true } },
        courseElement: { select: { id: true, code: true, name: true } },
        teacher: { select: { id: true, firstName: true, lastName: true, email: true } },
        classroom: { select: { id: true, code: true, name: true } },
        academicYear: { select: { id: true, label: true } },
      },
      orderBy: { createdAt: "desc" },
    });
  }

  /**
   * Liste des enseignants sélectionnables pour une assignation. En attendant le
   * module 1 (GET /api/users), on expose ici les utilisateurs actifs du tenant
   * (le scoping tenant est appliqué par le middleware Prisma).
   */
  async findTeachers() {
    return this.prisma.user.findMany({
      where: { deletedAt: null, status: "ACTIVE" },
      select: { id: true, firstName: true, lastName: true, email: true },
      orderBy: [{ lastName: "asc" }, { firstName: "asc" }],
    });
  }

  /**
   * Années académiques sélectionnables (pas d'endpoint dédié en Phase 0) :
   * helper pour le formulaire d'assignation, scoping tenant via middleware.
   */
  async findAcademicYears() {
    return this.prisma.academicYear.findMany({
      select: { id: true, label: true, status: true },
      orderBy: { startDate: "desc" },
    });
  }

  async create(dto: CreateSubjectAssignmentDto, tenantId: string): Promise<SubjectAssignment> {
    const hasSubject = Boolean(dto.subjectId);
    const hasCourseElement = Boolean(dto.courseElementId);
    if (hasSubject === hasCourseElement) {
      throw new BadRequestException("Renseigner soit une matière, soit un EC (exactement l'un des deux)");
    }

    const teacher = await this.prisma.user.findFirst({ where: { id: dto.teacherId } });
    if (!teacher) {
      throw new BadRequestException("L'enseignant indiqué est introuvable dans cet établissement");
    }

    const classroom = await this.prisma.classRoom.findFirst({ where: { id: dto.classroomId } });
    if (!classroom) {
      throw new BadRequestException("La classe indiquée est introuvable");
    }

    const academicYear = await this.prisma.academicYear.findFirst({ where: { id: dto.academicYearId } });
    if (!academicYear) {
      throw new BadRequestException("L'année académique indiquée est introuvable");
    }

    if (dto.subjectId) {
      const subject = await this.prisma.subject.findFirst({ where: { id: dto.subjectId } });
      if (!subject) {
        throw new NotFoundException("La matière indiquée est introuvable");
      }
    }
    if (dto.courseElementId) {
      const courseElement = await this.prisma.courseElement.findFirst({ where: { id: dto.courseElementId } });
      if (!courseElement) {
        throw new NotFoundException("L'EC indiqué est introuvable");
      }
    }

    // Unicité (matière/EC, classe, année) : contrôle applicatif car les index
    // uniques Postgres n'agissent pas quand subjectId/courseElementId est NULL.
    const duplicate = await this.prisma.subjectAssignment.findFirst({
      where: {
        deletedAt: null,
        classroomId: dto.classroomId,
        academicYearId: dto.academicYearId,
        ...(dto.subjectId ? { subjectId: dto.subjectId } : { courseElementId: dto.courseElementId }),
      },
    });
    if (duplicate) {
      throw new ConflictException("Cette matière est déjà assignée à cette classe pour cette année académique");
    }

    return this.prisma.subjectAssignment.create({
      data: {
        subjectId: dto.subjectId ?? null,
        courseElementId: dto.courseElementId ?? null,
        teacherId: dto.teacherId,
        classroomId: dto.classroomId,
        academicYearId: dto.academicYearId,
        tenantId,
      },
    });
  }
}

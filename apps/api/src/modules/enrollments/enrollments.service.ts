import { BadRequestException, ConflictException, Injectable, NotFoundException } from "@nestjs/common";
import { EnrollmentStatus, EnrollmentType, Prisma } from "@scholaris/prisma";
import { PrismaService } from "../../prisma/prisma.service";
import { CreateEnrollmentDto } from "./dto/create-enrollment.dto";
import { ReEnrollDto } from "./dto/re-enroll.dto";
import { UpdateEnrollmentStatusDto } from "./dto/update-enrollment-status.dto";

export interface ReEnrollFailure {
  studentId: string;
  studentName: string;
  reason: string;
}

export interface ReEnrollReport {
  reEnrolled: number;
  failed: ReEnrollFailure[];
}

@Injectable()
export class EnrollmentsService {
  constructor(private readonly prisma: PrismaService) {}

  /**
   * Inscrit un élève dans une classe. Refuse (400) si la capacité de la classe
   * est atteinte : nombre d'inscriptions actives (non annulées, non supprimées)
   * pour l'année visée >= ClassRoom.capacity. Le comptage et la création sont
   * faits dans la même transaction pour éviter le sur-remplissage concurrent.
   */
  async enroll(dto: CreateEnrollmentDto, tenantId: string) {
    const student = await this.prisma.student.findFirst({ where: { id: dto.studentId } });
    if (!student) {
      throw new BadRequestException("L'élève indiqué est introuvable");
    }
    const classroom = await this.prisma.classRoom.findFirst({ where: { id: dto.classroomId } });
    if (!classroom) {
      throw new BadRequestException("La classe indiquée est introuvable");
    }
    const academicYear = await this.prisma.academicYear.findFirst({ where: { id: dto.academicYearId } });
    if (!academicYear) {
      throw new BadRequestException("L'année académique indiquée est introuvable");
    }

    const alreadyEnrolled = await this.prisma.enrollment.findFirst({
      where: {
        studentId: dto.studentId,
        academicYearId: dto.academicYearId,
        status: { not: EnrollmentStatus.CANCELLED },
        deletedAt: null,
      },
    });
    if (alreadyEnrolled) {
      throw new ConflictException("Cet élève est déjà inscrit pour cette année académique");
    }

    return this.prisma.$transaction(async (tx) => {
      const activeCount = await tx.enrollment.count({
        where: {
          classroomId: dto.classroomId,
          academicYearId: dto.academicYearId,
          status: EnrollmentStatus.ACTIVE,
          deletedAt: null,
        },
      });
      if (activeCount >= classroom.capacity) {
        throw new BadRequestException(
          `Capacité de la classe atteinte (${activeCount}/${classroom.capacity}) : inscription refusée`,
        );
      }

      return tx.enrollment.create({
        data: {
          studentId: dto.studentId,
          classroomId: dto.classroomId,
          academicYearId: dto.academicYearId,
          enrollmentDate: dto.enrollmentDate ? new Date(dto.enrollmentDate) : new Date(),
          type: dto.type ?? EnrollmentType.NEW,
          status: EnrollmentStatus.ACTIVE,
          regime: dto.regime,
          isRepeater: dto.isRepeater ?? false,
          previousSchool: dto.previousSchool,
          previousAverage: dto.previousAverage,
          documents: dto.documents as Prisma.InputJsonValue | undefined,
          tenantId,
        },
      });
    });
  }

  async findAll(query: { classroomId?: string; academicYearId?: string; status?: EnrollmentStatus }) {
    return this.prisma.enrollment.findMany({
      where: {
        classroomId: query.classroomId,
        academicYearId: query.academicYearId,
        status: query.status,
        deletedAt: null,
      },
      include: { student: true, classroom: true, academicYear: true },
      orderBy: { enrollmentDate: "desc" },
    });
  }

  /** Annulation / suspension / réactivation d'une inscription (admin). */
  async updateStatus(id: string, dto: UpdateEnrollmentStatusDto) {
    const enrollment = await this.prisma.enrollment.findFirst({ where: { id } });
    if (!enrollment) {
      throw new NotFoundException("Inscription introuvable");
    }
    return this.prisma.enrollment.update({ where: { id }, data: { status: dto.status } });
  }

  /**
   * Réinscription en lot : tous les élèves à inscription ACTIVE de la classe
   * source sont réinscrits dans la classe de destination pour l'année cible,
   * dans la limite de la capacité de la destination. Rapport détaillé rendu :
   * { reEnrolled, failed: [{ studentId, studentName, reason }] }.
   */
  async reEnroll(dto: ReEnrollDto, tenantId: string): Promise<ReEnrollReport> {
    const target = await this.prisma.classRoom.findFirst({ where: { id: dto.targetClassroomId } });
    if (!target) {
      throw new BadRequestException("La classe de destination est introuvable");
    }
    const source = await this.prisma.classRoom.findFirst({ where: { id: dto.sourceClassroomId } });
    if (!source) {
      throw new BadRequestException("La classe source est introuvable");
    }
    const targetYear = await this.prisma.academicYear.findFirst({ where: { id: dto.targetAcademicYearId } });
    if (!targetYear) {
      throw new BadRequestException("L'année académique de destination est introuvable");
    }

    const sourceEnrollments = await this.prisma.enrollment.findMany({
      where: { classroomId: dto.sourceClassroomId, status: EnrollmentStatus.ACTIVE, deletedAt: null },
      include: { student: true },
      orderBy: { student: { lastName: "asc" } },
    });

    const occupied = await this.prisma.enrollment.count({
      where: {
        classroomId: dto.targetClassroomId,
        academicYearId: dto.targetAcademicYearId,
        status: EnrollmentStatus.ACTIVE,
        deletedAt: null,
      },
    });
    let available = target.capacity - occupied;

    const report: ReEnrollReport = { reEnrolled: 0, failed: [] };

    for (const enrollment of sourceEnrollments) {
      const studentName = `${enrollment.student.lastName} ${enrollment.student.firstName}`;

      const existing = await this.prisma.enrollment.findFirst({
        where: {
          studentId: enrollment.studentId,
          academicYearId: dto.targetAcademicYearId,
          status: { not: EnrollmentStatus.CANCELLED },
          deletedAt: null,
        },
      });
      if (existing) {
        report.failed.push({
          studentId: enrollment.studentId,
          studentName,
          reason: "Déjà inscrit pour l'année académique cible",
        });
        continue;
      }

      if (available <= 0) {
        report.failed.push({
          studentId: enrollment.studentId,
          studentName,
          reason: `Capacité de la classe de destination atteinte (${target.capacity})`,
        });
        continue;
      }

      try {
        await this.prisma.enrollment.create({
          data: {
            studentId: enrollment.studentId,
            classroomId: dto.targetClassroomId,
            academicYearId: dto.targetAcademicYearId,
            enrollmentDate: new Date(),
            type: EnrollmentType.RE_ENROLLMENT,
            status: EnrollmentStatus.ACTIVE,
            regime: dto.regime ?? enrollment.regime,
            isRepeater: false,
            tenantId,
          },
        });
        report.reEnrolled += 1;
        available -= 1;
      } catch (error) {
        report.failed.push({
          studentId: enrollment.studentId,
          studentName,
          reason: (error as Error).message,
        });
      }
    }

    return report;
  }
}

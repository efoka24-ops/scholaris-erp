import {
  Injectable,
  NotFoundException,
  BadRequestException,
} from "@nestjs/common";
import { PrismaService } from "../../prisma/prisma.service";
import { BulletinPdfService } from "./bulletin-pdf.service";
import * as crypto from "crypto";

@Injectable()
export class BulletinsService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly pdfService: BulletinPdfService,
  ) {}

  /**
   * Génère les bulletins pour tous les élèves d'une classe pour une période donnée
   */
  async generateBatch(
    classroomId: string,
    periodId: string,
    tenantId: string,
    autoSend?: boolean,
  ) {
    // Vérifier que la classe et la période existent
    const [classroom, period] = await Promise.all([
      this.prisma.classRoom.findFirst({
        where: { id: classroomId, tenantId },
        include: { level: true, room: true },
      }),
      this.prisma.period.findFirst({
        where: { id: periodId, tenantId },
        include: { academicYear: true },
      }),
    ]);

    if (!classroom) {
      throw new NotFoundException(`Classroom ${classroomId} not found`);
    }

    if (!period) {
      throw new NotFoundException(`Period ${periodId} not found`);
    }

    // Récupérer tous les élèves inscrits dans cette classe
    const enrollments = await this.prisma.enrollment.findMany({
      where: {
        classroomId,
        academicYearId: period.academicYearId,
        status: "active",
      },
      include: {
        student: true,
      },
    });

    if (enrollments.length === 0) {
      throw new BadRequestException("No students enrolled in this classroom");
    }

    const results = [];

    for (const enrollment of enrollments) {
      try {
        const bulletin = await this.generateSingle(
          enrollment.studentId,
          periodId,
          tenantId,
        );
        results.push({
          studentId: enrollment.studentId,
          matricule: enrollment.student.matricule,
          status: "success",
          bulletinId: bulletin.id,
        });
      } catch (error) {
        results.push({
          studentId: enrollment.studentId,
          matricule: enrollment.student.matricule,
          status: "error",
          error: error instanceof Error ? error.message : "Unknown error",
        });
      }
    }

    const successCount = results.filter((r) => r.status === "success").length;
    const errorCount = results.filter((r) => r.status === "error").length;

    return {
      total: enrollments.length,
      success: successCount,
      errors: errorCount,
      results,
    };
  }

  /**
   * Génère un bulletin pour un élève pour une période donnée
   */
  async generateSingle(studentId: string, periodId: string, tenantId: string) {
    // Récupérer l'élève avec son inscription
    const student = await this.prisma.student.findFirst({
      where: { id: studentId, tenantId },
      include: {
        enrollments: {
          where: {
            status: "active",
          },
          include: {
            classroom: {
              include: {
                level: true,
                room: true,
              },
            },
            academicYear: true,
          },
        },
      },
    });

    if (!student) {
      throw new NotFoundException(`Student ${studentId} not found`);
    }

    // Trouver l'inscription correspondant à la période
    const period = await this.prisma.period.findFirst({
      where: { id: periodId },
      include: { academicYear: true },
    });

    if (!period) {
      throw new NotFoundException(`Period ${periodId} not found`);
    }

    const enrollment = student.enrollments.find(
      (e) => e.academicYearId === period.academicYearId,
    );

    if (!enrollment) {
      throw new BadRequestException("Student not enrolled for this academic year");
    }

    // Récupérer toutes les notes de l'élève pour cette période
    const grades = await this.prisma.grade.findMany({
      where: {
        studentId,
        periodId,
        tenantId,
      },
      include: {
        subject: true,
        teacher: {
          select: {
            firstName: true,
            lastName: true,
          },
        },
      },
    });

    // Calculer la moyenne générale
    const totalWeightedScore = grades.reduce(
      (sum, g) => sum + (g.score / g.maxScore) * 20 * (g.subject.coefficient || 1),
      0,
    );
    const totalCoefficient = grades.reduce(
      (sum, g) => sum + (g.subject.coefficient || 1),
      0,
    );
    const average = totalCoefficient > 0 ? totalWeightedScore / totalCoefficient : 0;

    // Calculer le rang (simplifié - comparer avec les autres élèves de la classe)
    const classGrades = await this.prisma.grade.groupBy({
      by: ["studentId"],
      where: {
        classroomId: enrollment.classroom.id,
        periodId,
        tenantId,
      },
      _avg: {
        score: true,
      },
    });

    // Générer un code QR unique pour vérification
    const verificationCode = crypto.randomBytes(16).toString("hex");

    // Créer l'enregistrement bulletin
    const bulletin = await this.prisma.bulletin.create({
      data: {
        tenantId,
        studentId,
        periodId,
        classroomId: enrollment.classroom.id,
        verificationCode,
        status: "published",
        data: {
          student: {
            matricule: student.matricule,
            firstName: student.firstName,
            lastName: student.lastName,
            dateOfBirth: student.dateOfBirth,
          },
          classroom: {
            name: enrollment.classroom.name,
            level: enrollment.classroom.level.name,
          },
          period: {
            name: `${period.type === "SEQUENCE" ? "Séquence" : period.type === "TRIMESTER" ? "Trimestre" : "Semestre"} ${period.number}`,
            academicYear: period.academicYear.label,
          },
          grades: grades.map((g) => ({
            subject: g.subject?.name || 'N/A',
            score: Number(g.value || 0),
            maxScore: Number(g.maxValue || 20),
            coefficient: g.subject ? Number(g.subject.coefficient) : 1,
            average: g.value && g.maxValue ? (Number(g.value) / Number(g.maxValue)) * 20 : 0,
            teacher: `${g.teacher.firstName} ${g.teacher.lastName}`,
          })),
          average,
          rank: null, // À calculer après
          totalStudents: classGrades.length,
        },
      },
    });

    // Générer le PDF
    try {
      const pdfBuffer = await this.pdfService.generate(bulletin);
      await this.prisma.bulletin.update({
        where: { id: bulletin.id },
        data: { pdfUrl: `bulletins/${bulletin.id}.pdf` },
      });
    } catch (error) {
      console.error("PDF generation failed:", error);
      // Continue même si le PDF échoue
    }

    return bulletin;
  }

  /**
   * Récupère un bulletin par ID
   */
  async findOne(id: string, tenantId: string) {
    const bulletin = await this.prisma.bulletin.findFirst({
      where: { id, tenantId },
      include: {
        student: true,
        period: {
          include: {
            academicYear: true,
          },
        },
        classroom: {
          include: {
            level: true,
          },
        },
      },
    });

    if (!bulletin) {
      throw new NotFoundException(`Bulletin ${id} not found`);
    }

    return bulletin;
  }

  /**
   * Vérifie l'authenticité d'un bulletin via son code QR
   */
  async verify(verificationCode: string) {
    const bulletin = await this.prisma.bulletin.findFirst({
      where: { verificationCode },
      include: {
        student: {
          select: {
            matricule: true,
            firstName: true,
            lastName: true,
          },
        },
        period: {
          select: {
            type: true,
            number: true,
            academicYear: {
              select: {
                label: true,
              },
            },
          },
        },
      },
    });

    if (!bulletin) {
      return {
        valid: false,
        message: "Bulletin non trouvé ou code invalide",
      };
    }

    return {
      valid: true,
      message: "Bulletin authentique",
      bulletin: {
        student: bulletin.student,
        period: bulletin.period,
        generatedAt: bulletin.createdAt,
      },
    };
  }

  /**
   * Liste tous les bulletins d'un élève
   */
  async findByStudent(studentId: string, tenantId: string) {
    return this.prisma.bulletin.findMany({
      where: { studentId, tenantId },
      include: {
        period: {
          include: {
            academicYear: true,
          },
        },
        classroom: true,
      },
      orderBy: { createdAt: "desc" },
    });
  }
}

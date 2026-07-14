import { BadRequestException, ConflictException, Injectable, NotFoundException } from "@nestjs/common";
import { EnrollmentStatus, Invoice, Prisma } from "@scholaris/prisma";
import { buildPaginationMeta, DEFAULT_LIMIT, DEFAULT_PAGE, MAX_LIMIT, PaginatedResult } from "@scholaris/shared";
import { PrismaService } from "../../prisma/prisma.service";
import { FeeStructuresService } from "./fee-structures.service";
import { FindInvoicesQueryDto } from "./dto/find-invoices-query.dto";
import { computeInvoiceStatus, roundAmount } from "./invoice-status.util";

export interface GenerateBatchReport {
  generated: number;
  skipped: Array<{ studentId: string; studentName: string; reason: string }>;
}

@Injectable()
export class InvoicesService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly feeStructures: FeeStructuresService,
  ) {}

  /**
   * Génère la facture d'une inscription depuis la grille tarifaire applicable
   * à son niveau (cf. FeeStructuresService.findApplicable). Refuse si une
   * facture existe déjà pour cette inscription (409) ou si aucune grille
   * n'est applicable (400).
   */
  async generateForEnrollment(enrollmentId: string, tenantId: string): Promise<Invoice> {
    const enrollment = await this.prisma.enrollment.findFirst({
      where: { id: enrollmentId },
      include: { classroom: true, student: true },
    });
    if (!enrollment) {
      throw new NotFoundException("Inscription introuvable");
    }

    const existing = await this.prisma.invoice.findFirst({ where: { enrollmentId } });
    if (existing) {
      throw new ConflictException("Une facture existe déjà pour cette inscription");
    }

    const structure = await this.feeStructures.findApplicable(enrollment.classroom.levelId, enrollment.academicYearId);
    if (!structure) {
      throw new BadRequestException(
        "Aucune grille tarifaire applicable à ce niveau pour cette année académique",
      );
    }

    return this.createInvoice(enrollment.studentId, enrollment.id, enrollment.academicYearId, structure, tenantId);
  }

  /**
   * Génère les factures de toute une classe pour une année académique
   * donnée : une facture par inscription active, en ignorant celles qui en
   * ont déjà une ou pour lesquelles aucune grille n'est applicable (rapport
   * détaillé rendu plutôt qu'une erreur globale).
   */
  async generateForClass(classroomId: string, academicYearId: string, tenantId: string): Promise<GenerateBatchReport> {
    const classroom = await this.prisma.classRoom.findFirst({ where: { id: classroomId } });
    if (!classroom) {
      throw new BadRequestException("La classe indiquée est introuvable");
    }

    const enrollments = await this.prisma.enrollment.findMany({
      where: { classroomId, academicYearId, status: EnrollmentStatus.ACTIVE, deletedAt: null },
      include: { student: true },
      orderBy: { student: { lastName: "asc" } },
    });

    const structure = await this.feeStructures.findApplicable(classroom.levelId, academicYearId);

    const report: GenerateBatchReport = { generated: 0, skipped: [] };

    for (const enrollment of enrollments) {
      const studentName = `${enrollment.student.lastName} ${enrollment.student.firstName}`;

      if (!structure) {
        report.skipped.push({
          studentId: enrollment.studentId,
          studentName,
          reason: "Aucune grille tarifaire applicable à ce niveau pour cette année académique",
        });
        continue;
      }

      const existing = await this.prisma.invoice.findFirst({ where: { enrollmentId: enrollment.id } });
      if (existing) {
        report.skipped.push({ studentId: enrollment.studentId, studentName, reason: "Facture déjà générée" });
        continue;
      }

      await this.createInvoice(enrollment.studentId, enrollment.id, academicYearId, structure, tenantId);
      report.generated += 1;
    }

    return report;
  }

  private async createInvoice(
    studentId: string,
    enrollmentId: string,
    academicYearId: string,
    structure: Prisma.FeeStructureGetPayload<{ include: { installments: true } }>,
    tenantId: string,
  ): Promise<Invoice> {
    const dueDate = structure.installments.length
      ? structure.installments.reduce((latest, i) => (i.dueDate > latest ? i.dueDate : latest), structure.installments[0].dueDate)
      : null;

    const totalAmount = roundAmount(structure.totalAmount);

    return this.prisma.invoice.create({
      data: {
        studentId,
        enrollmentId,
        feeStructureId: structure.id,
        academicYearId,
        totalAmount,
        paidAmount: 0,
        balance: totalAmount,
        dueDate,
        status: computeInvoiceStatus(totalAmount, 0, dueDate),
        tenantId,
      },
    });
  }

  async findAll(query: FindInvoicesQueryDto): Promise<PaginatedResult<Invoice>> {
    const page = query.page ?? DEFAULT_PAGE;
    const limit = Math.min(query.limit ?? DEFAULT_LIMIT, MAX_LIMIT);

    const where: Prisma.InvoiceWhereInput = {
      ...(query.studentId ? { studentId: query.studentId } : {}),
      ...(query.academicYearId ? { academicYearId: query.academicYearId } : {}),
      ...(query.status ? { status: query.status } : {}),
      ...(query.classroomId ? { enrollment: { classroomId: query.classroomId } } : {}),
    };

    const [data, total] = await Promise.all([
      this.prisma.invoice.findMany({
        where,
        orderBy: { createdAt: "desc" },
        skip: (page - 1) * limit,
        take: limit,
        include: {
          student: true,
          enrollment: { include: { classroom: true } },
          academicYear: true,
        },
      }),
      this.prisma.invoice.count({ where }),
    ]);

    return { data, meta: buildPaginationMeta(total, page, limit) };
  }

  async findOne(id: string) {
    const invoice = await this.prisma.invoice.findFirst({
      where: { id },
      include: {
        student: true,
        enrollment: { include: { classroom: true } },
        feeStructure: { include: { installments: { orderBy: { order: "asc" } } } },
        academicYear: true,
        payments: { orderBy: { paidAt: "desc" } },
        discounts: true,
      },
    });
    if (!invoice) {
      throw new NotFoundException("Facture introuvable");
    }
    return invoice;
  }
}

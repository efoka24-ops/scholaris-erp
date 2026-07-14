import { BadRequestException, ConflictException, Injectable, NotFoundException } from "@nestjs/common";
import { AcademicYear, AcademicYearStatus, Prisma } from "@scholaris/prisma";
import { PrismaService } from "../../prisma/prisma.service";
import { AuditService } from "../audit/audit.service";
import { CreateAcademicYearDto } from "./dto/create-academic-year.dto";

const UNIQUE_CONSTRAINT_ERROR = "P2002";

@Injectable()
export class AcademicYearsService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly audit: AuditService,
  ) {}

  async findAll(): Promise<AcademicYear[]> {
    // Le filtre tenant_id est appliqué automatiquement par le middleware Prisma.
    return this.prisma.academicYear.findMany({
      orderBy: { startDate: "desc" },
      include: { periods: { orderBy: [{ type: "asc" }, { number: "asc" }] } },
    });
  }

  async findOne(id: string): Promise<AcademicYear> {
    const year = await this.prisma.academicYear.findFirst({
      where: { id },
      include: { periods: { orderBy: [{ type: "asc" }, { number: "asc" }] } },
    });
    if (!year) {
      throw new NotFoundException("Année académique introuvable");
    }
    return year;
  }

  /**
   * Règle métier §1.3 : une seule année ACTIVE à la fois par établissement.
   * La création d'une nouvelle année (ACTIVE par défaut) archive automatiquement
   * la précédente année active, dans la même transaction.
   */
  async create(dto: CreateAcademicYearDto, tenantId: string): Promise<AcademicYear> {
    const startDate = new Date(dto.startDate);
    const endDate = new Date(dto.endDate);
    if (endDate <= startDate) {
      throw new BadRequestException("La date de fin doit être postérieure à la date de début");
    }

    try {
      const created = await this.prisma.$transaction(async (tx) => {
        await tx.academicYear.updateMany({
          where: { tenantId, status: AcademicYearStatus.ACTIVE },
          data: { status: AcademicYearStatus.ARCHIVED },
        });
        return tx.academicYear.create({
          data: {
            tenantId,
            label: dto.label,
            startDate,
            endDate,
            status: AcademicYearStatus.ACTIVE,
          },
        });
      });

      await this.audit.log({
        action: "create",
        resource: "academic-years",
        resourceId: created.id,
        newValue: { label: created.label, startDate: created.startDate, endDate: created.endDate },
      });
      return created;
    } catch (error) {
      throw this.mapUniqueConstraint(error);
    }
  }

  /**
   * Réactive une année existante (ex: réouverture après clôture par erreur) :
   * archive l'année active courante puis passe la cible en ACTIVE.
   */
  async activate(id: string, tenantId: string): Promise<AcademicYear> {
    const year = await this.findOne(id);
    if (year.status === AcademicYearStatus.ACTIVE) {
      return year;
    }

    const activated = await this.prisma.$transaction(async (tx) => {
      await tx.academicYear.updateMany({
        where: { tenantId, status: AcademicYearStatus.ACTIVE, id: { not: id } },
        data: { status: AcademicYearStatus.ARCHIVED },
      });
      return tx.academicYear.update({
        where: { id },
        data: { status: AcademicYearStatus.ACTIVE },
      });
    });

    await this.audit.log({
      action: "update",
      resource: "academic-years",
      resourceId: id,
      oldValue: { status: year.status },
      newValue: { status: AcademicYearStatus.ACTIVE },
    });
    return activated;
  }

  /** Clôture une année (statut CLOSED : bulletins figés, plus de saisie). */
  async close(id: string): Promise<AcademicYear> {
    const year = await this.findOne(id);
    if (year.status !== AcademicYearStatus.ACTIVE) {
      throw new BadRequestException("Seule une année active peut être clôturée");
    }

    const closed = await this.prisma.academicYear.update({
      where: { id },
      data: { status: AcademicYearStatus.CLOSED },
    });

    await this.audit.log({
      action: "update",
      resource: "academic-years",
      resourceId: id,
      oldValue: { status: year.status },
      newValue: { status: AcademicYearStatus.CLOSED },
    });
    return closed;
  }

  private mapUniqueConstraint(error: unknown): Error {
    if (error instanceof Prisma.PrismaClientKnownRequestError && error.code === UNIQUE_CONSTRAINT_ERROR) {
      return new ConflictException("Une année académique avec ce libellé existe déjà");
    }
    return error as Error;
  }
}

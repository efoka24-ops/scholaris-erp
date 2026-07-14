import {
  BadRequestException,
  ConflictException,
  ForbiddenException,
  Injectable,
  NotFoundException,
} from "@nestjs/common";
import { GradingStatus, Period, Prisma } from "@scholaris/prisma";
import { PrismaService } from "../../prisma/prisma.service";
import { AuditService } from "../audit/audit.service";
import { CreatePeriodDto } from "./dto/create-period.dto";

const UNIQUE_CONSTRAINT_ERROR = "P2002";

export interface FindPeriodsQuery {
  academicYearId?: string;
}

/**
 * Périodes de saisie (séquences/trimestres/semestres). Le modèle Period ne
 * porte pas de tenant_id : l'isolation multi-tenant passe par la relation
 * academicYear (elle-même auto-filtrée par le middleware Prisma pour les
 * requêtes directes, mais pas pour les relations — d'où le filtre explicite ici).
 */
@Injectable()
export class PeriodsService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly audit: AuditService,
  ) {}

  async findAll(tenantId: string, query: FindPeriodsQuery): Promise<Period[]> {
    return this.prisma.period.findMany({
      where: {
        academicYear: { tenantId },
        ...(query.academicYearId ? { academicYearId: query.academicYearId } : {}),
      },
      orderBy: [{ type: "asc" }, { number: "asc" }],
    });
  }

  async create(dto: CreatePeriodDto, tenantId: string): Promise<Period> {
    const startDate = new Date(dto.startDate);
    const endDate = new Date(dto.endDate);
    if (endDate <= startDate) {
      throw new BadRequestException("La date de fin doit être postérieure à la date de début");
    }

    const year = await this.prisma.academicYear.findFirst({
      where: { id: dto.academicYearId, tenantId },
    });
    if (!year) {
      throw new BadRequestException("L'année académique indiquée est introuvable");
    }
    if (startDate < year.startDate || endDate > year.endDate) {
      throw new BadRequestException("Les dates de la période doivent être comprises dans l'année académique");
    }

    try {
      const created = await this.prisma.period.create({
        data: {
          academicYearId: dto.academicYearId,
          type: dto.type,
          number: dto.number,
          startDate,
          endDate,
          gradingStatus: GradingStatus.CLOSED,
        },
      });
      await this.audit.log({
        action: "create",
        resource: "periods",
        resourceId: created.id,
        newValue: { academicYearId: created.academicYearId, type: created.type, number: created.number },
      });
      return created;
    } catch (error) {
      if (error instanceof Prisma.PrismaClientKnownRequestError && error.code === UNIQUE_CONSTRAINT_ERROR) {
        throw new ConflictException("Une période de ce type et de ce numéro existe déjà pour cette année");
      }
      throw error;
    }
  }

  /**
   * Ouvre/ferme/verrouille la saisie des notes d'une période.
   * Règle métier §1.3 : une période VERROUILLÉE ne peut être rouverte que par un
   * utilisateur détenteur de la permission `periods:unlock` (rôle Admin).
   */
  async updateStatus(
    id: string,
    gradingStatus: GradingStatus,
    tenantId: string,
    canUnlock: boolean,
  ): Promise<Period> {
    const period = await this.prisma.period.findFirst({
      where: { id, academicYear: { tenantId } },
    });
    if (!period) {
      throw new NotFoundException("Période introuvable");
    }

    if (period.gradingStatus === gradingStatus) {
      return period;
    }

    if (period.gradingStatus === GradingStatus.LOCKED && !canUnlock) {
      throw new ForbiddenException(
        "Cette période est verrouillée : seul un administrateur peut la rouvrir",
      );
    }

    const updated = await this.prisma.period.update({
      where: { id },
      data: { gradingStatus },
    });

    await this.audit.log({
      action: "update",
      resource: "periods",
      resourceId: id,
      oldValue: { gradingStatus: period.gradingStatus },
      newValue: { gradingStatus },
    });
    return updated;
  }
}

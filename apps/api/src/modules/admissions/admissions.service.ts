import { BadRequestException, Injectable, NotFoundException } from "@nestjs/common";
import { AdmissionApplication, AdmissionStatus, AdmissionType, Prisma } from "@scholaris/prisma";
import { buildPaginationMeta, DEFAULT_LIMIT, DEFAULT_PAGE, MAX_LIMIT, PaginatedResult } from "@scholaris/shared";
import { PrismaService } from "../../prisma/prisma.service";
import { AdmissionDecisionDto } from "./dto/admission-decision.dto";
import { CreateAdmissionDto } from "./dto/create-admission.dto";
import { CreatePublicAdmissionDto } from "./dto/create-public-admission.dto";

export interface FindAdmissionsQuery {
  page?: number;
  limit?: number;
  status?: AdmissionStatus;
  academicYearId?: string;
  search?: string;
}

@Injectable()
export class AdmissionsService {
  constructor(private readonly prisma: PrismaService) {}

  async create(dto: CreateAdmissionDto, tenantId: string) {
    const academicYear = await this.prisma.academicYear.findFirst({ where: { id: dto.academicYearId } });
    if (!academicYear) {
      throw new BadRequestException("L'année académique indiquée est introuvable");
    }
    return this.prisma.admissionApplication.create({
      data: {
        applicantName: dto.applicantName,
        applicantInfo: dto.applicantInfo as Prisma.InputJsonValue | undefined,
        type: dto.type,
        score: dto.score,
        academicYearId: dto.academicYearId,
        tenantId,
      },
    });
  }

  /**
   * Pré-inscription publique (formulaire parent, sans authentification).
   * Résout le tenant par son code public, rattache la candidature à l'année
   * académique active la plus récente, et rejette silencieusement les bots
   * (honeypot rempli) en simulant un succès sans écrire en base.
   */
  async createPublic(dto: CreatePublicAdmissionDto): Promise<{ id: string } | { accepted: true }> {
    if (dto.website) {
      // Honeypot rempli : très probablement un bot. On ne révèle rien à l'appelant.
      return { accepted: true };
    }

    const tenant = await this.prisma.tenant.findFirst({ where: { code: dto.tenantCode, deletedAt: null } });
    if (!tenant) {
      throw new NotFoundException("Établissement introuvable pour ce code");
    }

    const academicYear = await this.prisma.academicYear.findFirst({
      where: { tenantId: tenant.id, status: "ACTIVE" },
      orderBy: { startDate: "desc" },
    });
    if (!academicYear) {
      throw new BadRequestException("Aucune année académique active pour cet établissement");
    }

    const applicantName = `${dto.studentLastName.trim()} ${dto.studentFirstName.trim()}`.trim();

    const application = await this.prisma.admissionApplication.create({
      data: {
        applicantName,
        type: AdmissionType.DOSSIER,
        academicYearId: academicYear.id,
        tenantId: tenant.id,
        applicantInfo: {
          source: "public-enrollment-form",
          student: {
            lastName: dto.studentLastName.trim(),
            firstName: dto.studentFirstName.trim(),
            dateOfBirth: dto.studentDateOfBirth,
            desiredLevel: dto.desiredLevel,
            previousSchool: dto.previousSchool ?? null,
          },
          parent: {
            name: dto.parentName.trim(),
            phone: dto.parentPhone.trim(),
            email: dto.parentEmail ?? null,
          },
        } as Prisma.InputJsonValue,
      },
    });

    return { id: application.id };
  }

  async findAll(query: FindAdmissionsQuery): Promise<PaginatedResult<AdmissionApplication>> {
    const page = query.page ?? DEFAULT_PAGE;
    const limit = Math.min(query.limit ?? DEFAULT_LIMIT, MAX_LIMIT);

    const where: Prisma.AdmissionApplicationWhereInput = {
      status: query.status,
      academicYearId: query.academicYearId,
      ...(query.search ? { applicantName: { contains: query.search, mode: "insensitive" } } : {}),
      deletedAt: null,
    };

    const [data, total] = await Promise.all([
      this.prisma.admissionApplication.findMany({
        where,
        orderBy: [{ status: "asc" }, { rank: "asc" }, { applicantName: "asc" }],
        skip: (page - 1) * limit,
        take: limit,
        include: { academicYear: true },
      }),
      this.prisma.admissionApplication.count({ where }),
    ]);

    return { data, meta: buildPaginationMeta(total, page, limit) };
  }

  async findOne(id: string) {
    const application = await this.prisma.admissionApplication.findFirst({
      where: { id },
      include: { academicYear: true },
    });
    if (!application) {
      throw new NotFoundException("Candidature introuvable");
    }
    return application;
  }

  /** Décision : accepter / refuser / mettre en liste d'attente. */
  async decide(id: string, dto: AdmissionDecisionDto) {
    await this.findOne(id);
    return this.prisma.admissionApplication.update({
      where: { id },
      data: {
        status: dto.status,
        ...(dto.score !== undefined ? { score: dto.score } : {}),
        ...(dto.rank !== undefined ? { rank: dto.rank } : {}),
      },
    });
  }
}

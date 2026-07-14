import { BadRequestException, Injectable, NotFoundException } from "@nestjs/common";
import { AdmissionApplication, AdmissionStatus, Prisma } from "@scholaris/prisma";
import { buildPaginationMeta, DEFAULT_LIMIT, DEFAULT_PAGE, MAX_LIMIT, PaginatedResult } from "@scholaris/shared";
import { PrismaService } from "../../prisma/prisma.service";
import { AdmissionDecisionDto } from "./dto/admission-decision.dto";
import { CreateAdmissionDto } from "./dto/create-admission.dto";

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

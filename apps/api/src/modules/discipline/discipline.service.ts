import { Injectable, NotFoundException } from '@nestjs/common';
import { PrismaService } from '../../prisma/prisma.service';
import { CreateIncidentDto } from './dto/create-incident.dto';
import { CreateSanctionDto } from './dto/create-sanction.dto';
import { FindIncidentsQueryDto } from './dto/find-incidents-query.dto';

@Injectable()
export class DisciplineService {
  constructor(private prisma: PrismaService) {}

  async findAllIncidents(tenantId: string, query: FindIncidentsQueryDto) {
    const { page = 1, limit = 20 } = query;
    const skip = (page - 1) * limit;

    const where: any = { tenantId };

    const [items, total] = await Promise.all([
      this.prisma.disciplineIncident.findMany({
        where,
        skip,
        take: limit,
        include: {
          student: { select: { firstName: true, lastName: true } },
          reporter: { select: { firstName: true, lastName: true } },
        },
        orderBy: { date: 'desc' },
      }),
      this.prisma.disciplineIncident.count({ where }),
    ]);

    return { items, total, page, limit, totalPages: Math.ceil(total / limit) };
  }

  async findStudentIncidents(tenantId: string, studentId: string) {
    return this.prisma.disciplineIncident.findMany({
      where: { tenantId, studentId },
      include: {
        reporter: { select: { firstName: true, lastName: true } },
      },
      orderBy: { date: 'desc' },
    });
  }

  async findOneIncident(tenantId: string, id: string) {
    const incident = await this.prisma.disciplineIncident.findFirst({
      where: { id, tenantId },
      include: {
        student: true,
        reporter: { select: { firstName: true, lastName: true, email: true } },
      },
    });

    if (!incident) {
      throw new NotFoundException('Incident non trouvé');
    }

    return incident;
  }

  async createIncident(tenantId: string, dto: CreateIncidentDto, reportedById: string) {
    return this.prisma.disciplineIncident.create({
      data: {
        tenantId,
        studentId: dto.studentId,
        date: new Date(dto.incidentDate || new Date()),
        type: dto.type as any, // DTO type doit correspondre à IncidentType enum
        description: dto.description,
        reportedBy: reportedById,
      },
      include: {
        student: { select: { firstName: true, lastName: true } },
        reporter: { select: { firstName: true, lastName: true } },
      },
    });
  }

  async createSanction(tenantId: string, dto: CreateSanctionDto, appliedById: string) {
    // Vérifier que l'incident existe
    const incident = await this.findOneIncident(tenantId, dto.incidentId);

    // Mettre à jour l'incident avec la sanction
    return this.prisma.disciplineIncident.update({
      where: { id: dto.incidentId },
      data: {
        sanction: dto.type as any, // DTO type doit correspondre à SanctionType enum
        sanctionDetails: dto.description,
      },
      include: {
        student: true,
        reporter: true,
      },
    });
  }

  async getStudentStats(tenantId: string, studentId: string) {
    const incidents = await this.prisma.disciplineIncident.findMany({
      where: { tenantId, studentId },
    });

    const stats = {
      totalIncidents: incidents.length,
      byType: {
        retard: incidents.filter((i) => i.type === 'RETARD').length,
        absence: incidents.filter((i) => i.type === 'ABSENCE_INJUSTIFIEE').length,
        insolence: incidents.filter((i) => i.type === 'INSOLENCE').length,
        bagarre: incidents.filter((i) => i.type === 'BAGARRE').length,
        tricherie: incidents.filter((i) => i.type === 'TRICHERIE').length,
        autre: incidents.filter((i) => i.type === 'AUTRE').length,
      },
      withSanctions: incidents.filter((i) => i.sanction !== null).length,
      withoutSanctions: incidents.filter((i) => i.sanction === null).length,
    };

    return stats;
  }
}

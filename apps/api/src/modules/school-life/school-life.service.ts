import { Injectable } from '@nestjs/common';
import { PrismaService } from '../../prisma/prisma.service';

@Injectable()
export class SchoolLifeService {
  constructor(private prisma: PrismaService) {}

  async findAllClubs(tenantId: string) {
    return this.prisma.club.findMany({
      where: { tenantId },
      include: {
        supervisor: { select: { firstName: true, lastName: true } },
        members: { include: { student: { select: { firstName: true, lastName: true } } } },
      },
    });
  }

  async createClub(tenantId: string, dto: any) {
    return this.prisma.club.create({
      data: { ...dto, tenantId },
    });
  }

  async findAllEvents(tenantId: string) {
    return this.prisma.schoolEvent.findMany({
      where: { tenantId },
      include: {
        organizer: { select: { firstName: true, lastName: true } },
      },
      orderBy: { startDate: 'desc' },
    });
  }

  async createEvent(tenantId: string, dto: any) {
    // Le formulaire envoie startDate/endDate depuis un <input type="date"> (chaîne
    // "AAAA-MM-JJ"). Prisma exige un DateTime : on convertit explicitement, sinon
    // la chaîne date-only fait échouer la création (500).
    return this.prisma.schoolEvent.create({
      data: {
        ...dto,
        ...(dto.startDate ? { startDate: new Date(dto.startDate) } : {}),
        ...(dto.endDate ? { endDate: new Date(dto.endDate) } : {}),
        tenantId,
      },
    });
  }
}

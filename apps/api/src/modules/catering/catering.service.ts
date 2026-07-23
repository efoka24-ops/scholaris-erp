import { Injectable } from '@nestjs/common';
import { PrismaService } from '../../prisma/prisma.service';

@Injectable()
export class CateringService {
  constructor(private prisma: PrismaService) {}

  async findAllMenus(tenantId: string) {
    return this.prisma.cateringMenu.findMany({
      where: { tenantId },
      orderBy: { date: 'desc' },
    });
  }

  async createMenu(tenantId: string, dto: any) {
    // date : conversion explicite en DateTime pour accepter aussi bien une chaîne
    // ISO complète qu'une date seule "AAAA-MM-JJ" (robustesse, évite un 500).
    return this.prisma.cateringMenu.create({
      data: {
        ...dto,
        ...(dto.date ? { date: new Date(dto.date) } : {}),
        tenantId,
      },
    });
  }

  async findAllSubscriptions(tenantId: string) {
    // CateringSubscription non implémenté dans le schema
    return [];
  }

  async subscribe(tenantId: string, dto: any) {
    // CateringSubscription non implémenté dans le schema
    return { message: 'Module subscription non encore implémenté' };
  }

  async findAllDorms(tenantId: string) {
    // Dormitory non implémenté dans le schema
    return [];
  }
}

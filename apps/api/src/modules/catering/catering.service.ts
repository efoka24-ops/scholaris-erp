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
    return this.prisma.cateringMenu.create({
      data: { ...dto, tenantId },
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

import { Injectable } from '@nestjs/common';
import { PrismaService } from '../../prisma/prisma.service';

@Injectable()
export class TransportService {
  constructor(private prisma: PrismaService) {}

  async findAllRoutes(tenantId: string) {
    return this.prisma.transportRoute.findMany({
      where: { tenantId },
      include: {
        vehicle: true,
        subscriptions: {
          include: {
            student: { select: { firstName: true, lastName: true } },
          },
        },
      },
    });
  }

  async createRoute(tenantId: string, dto: any) {
    return this.prisma.transportRoute.create({
      data: { ...dto, tenantId },
    });
  }

  async findAllVehicles(tenantId: string) {
    return this.prisma.transportVehicle.findMany({
      where: { tenantId },
      include: {
        routes: { select: { name: true } },
      },
    });
  }

  async subscribe(tenantId: string, dto: any) {
    return this.prisma.transportSubscription.create({
      data: { ...dto, tenantId },
    });
  }
}

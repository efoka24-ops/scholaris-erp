import { Injectable, NotFoundException } from '@nestjs/common';
import { PrismaService } from '../../prisma/prisma.service';

@Injectable()
export class AssetsService {
  constructor(private prisma: PrismaService) {}

  async findAll(tenantId: string, query: any) {
    const { page = 1, limit = 50, category, status } = query;
    const skip = (page - 1) * limit;
    const where: any = { tenantId };

    if (category) where.category = category;
    if (status) where.status = status;

    const [items, total] = await Promise.all([
      this.prisma.asset.findMany({
        where,
        skip,
        take: limit,
        include: {
          maintenances: { select: { id: true, date: true, description: true, cost: true } },
        },
        orderBy: { createdAt: 'desc' },
      }),
      this.prisma.asset.count({ where }),
    ]);

    return { items, total, page, limit, totalPages: Math.ceil(total / limit) };
  }

  async findOne(tenantId: string, id: string) {
    const asset = await this.prisma.asset.findFirst({
      where: { id, tenantId },
      include: {
        maintenances: { orderBy: { date: 'desc' } },
      },
    });

    if (!asset) {
      throw new NotFoundException('Asset non trouvé');
    }

    return asset;
  }

  async create(tenantId: string, dto: any) {
    return this.prisma.asset.create({
      data: { ...dto, tenantId },
    });
  }

  async update(tenantId: string, id: string, dto: any) {
    await this.findOne(tenantId, id);
    return this.prisma.asset.update({
      where: { id },
      data: dto,
    });
  }

  async remove(tenantId: string, id: string) {
    await this.findOne(tenantId, id);
    return this.prisma.asset.delete({ where: { id } });
  }

  async recordMaintenance(tenantId: string, assetId: string, dto: any) {
    await this.findOne(tenantId, assetId);
    return this.prisma.assetMaintenance.create({
      data: { ...dto, assetId, tenantId },
    });
  }
}

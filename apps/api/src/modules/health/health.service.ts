import { Injectable } from '@nestjs/common';
import { PrismaService } from '../../prisma/prisma.service';

@Injectable()
export class HealthService {
  constructor(private prisma: PrismaService) {}

  async findStudentRecords(tenantId: string, studentId: string) {
    return this.prisma.healthRecord.findMany({
      where: { tenantId, studentId },
      include: {
        student: { select: { firstName: true, lastName: true } },
      },
      orderBy: { createdAt: 'desc' },
    });
  }

  async createRecord(tenantId: string, dto: any) {
    return this.prisma.healthRecord.create({
      data: { ...dto, tenantId },
    });
  }

  async findVaccinations(tenantId: string, studentId: string) {
    // Vaccination info stockée dans healthRecord.vaccinations (string)
    const healthRecord = await this.prisma.healthRecord.findFirst({
      where: { tenantId, studentId },
    });
    
    return healthRecord?.vaccinations 
      ? { vaccinations: healthRecord.vaccinations } 
      : { vaccinations: null };
  }
}

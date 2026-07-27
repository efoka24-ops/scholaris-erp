import { Injectable, NotFoundException } from "@nestjs/common";
import { PrismaService } from "../../prisma/prisma.service";
import { CreateHealthRecordDto } from "./dto/create-health-record.dto";

@Injectable()
export class HealthService {
  constructor(private prisma: PrismaService) {}

  /** Tous les dossiers médicaux de l'établissement (avec l'élève). */
  async findAll(tenantId: string) {
    return this.prisma.healthRecord.findMany({
      where: { tenantId },
      include: { student: { select: { firstName: true, lastName: true, matricule: true } } },
      orderBy: { updatedAt: "desc" },
    });
  }

  async findStudentRecords(tenantId: string, studentId: string) {
    return this.prisma.healthRecord.findMany({
      where: { tenantId, studentId },
      include: { student: { select: { firstName: true, lastName: true } } },
      orderBy: { createdAt: "desc" },
    });
  }

  /**
   * Crée ou met à jour le dossier médical d'un élève. HealthRecord.studentId est
   * unique : un seul dossier par élève, donc upsert.
   */
  async upsertRecord(tenantId: string, dto: CreateHealthRecordDto) {
    const student = await this.prisma.student.findFirst({ where: { id: dto.studentId, tenantId } });
    if (!student) throw new NotFoundException("Élève introuvable");
    const { studentId, ...fields } = dto;
    return this.prisma.healthRecord.upsert({
      where: { studentId },
      update: fields,
      create: { ...fields, studentId, tenantId },
    });
  }

  async findVaccinations(tenantId: string, studentId: string) {
    const healthRecord = await this.prisma.healthRecord.findFirst({ where: { tenantId, studentId } });
    return healthRecord?.vaccinations ? { vaccinations: healthRecord.vaccinations } : { vaccinations: null };
  }
}

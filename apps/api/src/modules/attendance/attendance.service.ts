import { Injectable, NotFoundException } from '@nestjs/common';
import { PrismaService } from '../../prisma/prisma.service';
import { RecordAttendanceDto } from './dto/record-attendance.dto';
import { FindAttendanceQueryDto } from './dto/find-attendance-query.dto';

@Injectable()
export class AttendanceService {
  constructor(private prisma: PrismaService) {}

  async findByClassroom(
    tenantId: string,
    classroomId: string,
    query: FindAttendanceQueryDto,
  ) {
    const { startDate, endDate } = query;
    const where: any = { tenantId, classroomId };

    if (startDate || endDate) {
      where.date = {};
      if (startDate) where.date.gte = new Date(startDate);
      if (endDate) where.date.lte = new Date(endDate);
    }

    return this.prisma.attendance.findMany({
      where,
      include: {
        student: { select: { firstName: true, lastName: true } },
      },
      orderBy: { date: 'desc' },
    });
  }

  async findByStudent(tenantId: string, studentId: string, query: FindAttendanceQueryDto) {
    const { startDate, endDate } = query;
    const where: any = { tenantId, studentId };

    if (startDate || endDate) {
      where.date = {};
      if (startDate) where.date.gte = new Date(startDate);
      if (endDate) where.date.lte = new Date(endDate);
    }

    return this.prisma.attendance.findMany({
      where,
      include: {
        classroom: { select: { name: true } },
      },
      orderBy: { date: 'desc' },
    });
  }

  async getStats(tenantId: string, classroomId: string) {
    const records = await this.prisma.attendance.findMany({
      where: { tenantId, classroomId },
      include: {
        student: { select: { id: true, firstName: true, lastName: true } },
      },
    });

    // Grouper par étudiant
    const byStudent = records.reduce((acc: any, r) => {
      const key = r.studentId;
      if (!acc[key]) {
        acc[key] = { student: r.student, records: [] };
      }
      acc[key].records.push(r);
      return acc;
    }, {});

    return Object.values(byStudent).map((entry: any) => {
      const total = entry.records.length;
      const present = entry.records.filter((r: any) => r.status === 'PRESENT').length;
      const absent = entry.records.filter((r: any) => r.status === 'ABSENT').length;
      const late = entry.records.filter((r: any) => r.status === 'LATE').length;
      const excused = entry.records.filter((r: any) => r.status === 'EXCUSED').length;

      return {
        student: entry.student,
        stats: {
          total,
          present,
          absent,
          late,
          excused,
          attendanceRate: total > 0 ? ((present + excused) / total) * 100 : 0,
        },
      };
    });
  }

  async recordAttendance(tenantId: string, dto: RecordAttendanceDto, recordedById: string) {
    const records = await Promise.all(
      dto.records.map((record: any) =>
        this.prisma.attendance.create({
          data: {
            tenantId,
            studentId: record.studentId,
            classroomId: dto.classroomId,
            date: new Date(dto.date),
            status: record.status,
            reason: record.notes,
          },
          include: {
            student: { select: { firstName: true, lastName: true } },
          },
        }),
      ),
    );

    return { message: 'Présences enregistrées avec succès', count: records.length, records };
  }

  async justifyAbsence(tenantId: string, id: string, justification: string) {
    const record = await this.prisma.attendance.findFirst({
      where: { id, tenantId },
    });

    if (!record) {
      throw new NotFoundException('Enregistrement de présence non trouvé');
    }

    return this.prisma.attendance.update({
      where: { id },
      data: {
        status: 'EXCUSED' as any,
        reason: justification,
        justifiedBy: id, // ID du justificateur
      },
    });
  }
}

import { Injectable, NotFoundException } from '@nestjs/common';
import { PrismaService } from '../../prisma/prisma.service';
import { RecordAttendanceDto } from './dto/record-attendance.dto';
import { FindAttendanceQueryDto } from './dto/find-attendance-query.dto';
import { AttendanceStatus } from '@scholaris/prisma';

@Injectable()
export class AttendanceService {
  constructor(private prisma: PrismaService) {}

  async findByClassroom(
    tenantId: string,
    classroomId: string,
    query: FindAttendanceQueryDto,
  ) {
    const { startDate, endDate } = query;
    const where: any = { tenantId, enrollment: { classroomId } };

    if (startDate || endDate) {
      where.date = {};
      if (startDate) where.date.gte = new Date(startDate);
      if (endDate) where.date.lte = new Date(endDate);
    }

    return this.prisma.attendanceRecord.findMany({
      where,
      include: {
        enrollment: {
          include: {
            student: { select: { firstName: true, lastName: true, registrationNumber: true } },
          },
        },
        recordedBy: { select: { firstName: true, lastName: true } },
      },
      orderBy: { date: 'desc' },
    });
  }

  async findByStudent(tenantId: string, studentId: string, query: FindAttendanceQueryDto) {
    const { startDate, endDate } = query;
    const where: any = { tenantId, enrollment: { studentId } };

    if (startDate || endDate) {
      where.date = {};
      if (startDate) where.date.gte = new Date(startDate);
      if (endDate) where.date.lte = new Date(endDate);
    }

    return this.prisma.attendanceRecord.findMany({
      where,
      include: {
        enrollment: {
          include: {
            classroom: { select: { name: true } },
          },
        },
        recordedBy: { select: { firstName: true, lastName: true } },
      },
      orderBy: { date: 'desc' },
    });
  }

  async getStats(tenantId: string, classroomId: string) {
    const enrollments = await this.prisma.enrollment.findMany({
      where: { tenantId, classroomId },
      include: {
        student: { select: { id: true, firstName: true, lastName: true } },
        attendanceRecords: {
          select: { status: true },
        },
      },
    });

    return enrollments.map((enrollment) => {
      const records = enrollment.attendanceRecords;
      const total = records.length;
      const present = records.filter((r) => r.status === 'PRESENT').length;
      const absent = records.filter((r) => r.status === 'ABSENT').length;
      const late = records.filter((r) => r.status === 'LATE').length;
      const excused = records.filter((r) => r.status === 'EXCUSED').length;

      return {
        student: enrollment.student,
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
      dto.records.map((record) =>
        this.prisma.attendanceRecord.create({
          data: {
            tenantId,
            enrollmentId: record.enrollmentId,
            date: new Date(dto.date),
            status: record.status as AttendanceStatus,
            notes: record.notes,
            recordedById,
          },
          include: {
            enrollment: {
              include: {
                student: { select: { firstName: true, lastName: true } },
              },
            },
          },
        }),
      ),
    );

    return { message: 'Présences enregistrées avec succès', count: records.length, records };
  }

  async justifyAbsence(tenantId: string, id: string, justification: string) {
    const record = await this.prisma.attendanceRecord.findFirst({
      where: { id, tenantId },
    });

    if (!record) {
      throw new NotFoundException('Enregistrement de présence non trouvé');
    }

    return this.prisma.attendanceRecord.update({
      where: { id },
      data: {
        status: 'EXCUSED',
        notes: justification,
      },
    });
  }
}

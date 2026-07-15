import { Injectable, NotFoundException } from '@nestjs/common';
import { PrismaService } from '../../prisma/prisma.service';
import { CreateTimetableDto } from './dto/create-timetable.dto';
import { UpdateTimetableDto } from './dto/update-timetable.dto';
import { FindTimetablesQueryDto } from './dto/find-timetables-query.dto';

// DayOfWeek enum défini localement (correspond au schéma Prisma)
enum DayOfWeek {
  MONDAY = 'MONDAY',
  TUESDAY = 'TUESDAY',
  WEDNESDAY = 'WEDNESDAY',
  THURSDAY = 'THURSDAY',
  FRIDAY = 'FRIDAY',
  SATURDAY = 'SATURDAY',
  SUNDAY = 'SUNDAY',
}

// Mapping number (1-7) vers DayOfWeek enum
const numberToDayOfWeek = (day: number): DayOfWeek => {
  const mapping: Record<number, DayOfWeek> = {
    1: DayOfWeek.MONDAY,
    2: DayOfWeek.TUESDAY,
    3: DayOfWeek.WEDNESDAY,
    4: DayOfWeek.THURSDAY,
    5: DayOfWeek.FRIDAY,
    6: DayOfWeek.SATURDAY,
    7: DayOfWeek.SUNDAY,
  };
  return mapping[day] || DayOfWeek.MONDAY;
};

@Injectable()
export class TimetablesService {
  constructor(private prisma: PrismaService) {}

  async findAll(tenantId: string, query: FindTimetablesQueryDto) {
    const { page = 1, limit = 50, classroomId, academicYearId } = query;
    const skip = (page - 1) * limit;

    const where: any = { tenantId };
    if (classroomId) where.classroomId = classroomId;
    if (academicYearId) where.academicYearId = academicYearId;

    const [items, total] = await Promise.all([
      this.prisma.timetableSlot.findMany({
        where,
        skip,
        take: limit,
        include: {
          classroom: { select: { name: true } },
          subject: { select: { name: true } },
          teacher: { select: { firstName: true, lastName: true } },
          room: { select: { name: true } },
        },
        orderBy: [{ dayOfWeek: 'asc' }, { startTime: 'asc' }],
      }),
      this.prisma.timetableSlot.count({ where }),
    ]);

    return {
      items,
      total,
      page,
      limit,
      totalPages: Math.ceil(total / limit),
    };
  }

  async findByClassroom(tenantId: string, classroomId: string) {
    return this.prisma.timetableSlot.findMany({
      where: { tenantId, classroomId },
      include: {
        subject: { select: { name: true, code: true } },
        teacher: { select: { firstName: true, lastName: true, email: true } },
        room: { select: { name: true, capacity: true } },
      },
      orderBy: [{ dayOfWeek: 'asc' }, { startTime: 'asc' }],
    });
  }

  async findByTeacher(tenantId: string, teacherId: string) {
    return this.prisma.timetableSlot.findMany({
      where: { tenantId, teacherId },
      include: {
        classroom: { select: { name: true } },
        subject: { select: { name: true, code: true } },
        room: { select: { name: true } },
      },
      orderBy: [{ dayOfWeek: 'asc' }, { startTime: 'asc' }],
    });
  }

  async findOne(tenantId: string, id: string) {
    const slot = await this.prisma.timetableSlot.findFirst({
      where: { id, tenantId },
      include: {
        classroom: true,
        subject: true,
        teacher: true,
        room: true,
        academicYear: true,
      },
    });

    if (!slot) {
      throw new NotFoundException(`Créneau horaire non trouvé`);
    }

    return slot;
  }

  async create(tenantId: string, createDto: CreateTimetableDto) {
    const dayOfWeek = numberToDayOfWeek(createDto.dayOfWeek);
    
    // Vérifier les conflits de salle
    const conflictRoom = await this.prisma.timetableSlot.findFirst({
      where: {
        tenantId,
        roomId: createDto.roomId,
        dayOfWeek,
        OR: [
          {
            AND: [
              { startTime: { lte: createDto.startTime } },
              { endTime: { gt: createDto.startTime } },
            ],
          },
          {
            AND: [
              { startTime: { lt: createDto.endTime } },
              { endTime: { gte: createDto.endTime } },
            ],
          },
        ],
      },
    });

    if (conflictRoom) {
      throw new Error('Conflit : la salle est déjà occupée à cet horaire');
    }

    // Vérifier les conflits de professeur
    if (createDto.teacherId) {
      const conflictTeacher = await this.prisma.timetableSlot.findFirst({
        where: {
          tenantId,
          teacherId: createDto.teacherId,
          dayOfWeek,
          OR: [
            {
              AND: [
                { startTime: { lte: createDto.startTime } },
                { endTime: { gt: createDto.startTime } },
              ],
            },
            {
              AND: [
                { startTime: { lt: createDto.endTime } },
                { endTime: { gte: createDto.endTime } },
              ],
            },
          ],
        },
      });

      if (conflictTeacher) {
        throw new Error('Conflit : le professeur a déjà un cours à cet horaire');
      }
    }

    return this.prisma.timetableSlot.create({
      data: {
        tenantId,
        classroomId: createDto.classroomId,
        subjectId: createDto.subjectId,
        teacherId: createDto.teacherId || '',
        roomId: createDto.roomId,
        academicYearId: createDto.academicYearId,
        dayOfWeek,
        startTime: createDto.startTime,
        endTime: createDto.endTime,
      },
      include: {
        classroom: { select: { name: true } },
        subject: { select: { name: true } },
        teacher: { select: { firstName: true, lastName: true } },
        room: { select: { name: true } },
      },
    });
  }

  async update(tenantId: string, id: string, updateDto: UpdateTimetableDto) {
    await this.findOne(tenantId, id);

    const { dayOfWeek: dayNumber, ...rest } = updateDto as any;
    const data: any = { ...rest };
    
    if (dayNumber !== undefined) {
      data.dayOfWeek = numberToDayOfWeek(dayNumber);
    }

    return this.prisma.timetableSlot.update({
      where: { id },
      data,
      include: {
        classroom: { select: { name: true } },
        subject: { select: { name: true } },
        teacher: { select: { firstName: true, lastName: true } },
        room: { select: { name: true } },
      },
    });
  }

  async remove(tenantId: string, id: string) {
    await this.findOne(tenantId, id);
    return this.prisma.timetableSlot.delete({ where: { id } });
  }

  async generateAutomatic(tenantId: string, classroomId: string) {
    // TODO: Implémenter l'algorithme de génération automatique
    // Pour l'instant, retourner un placeholder
    return {
      message: 'Génération automatique à implémenter',
      classroomId,
    };
  }
}

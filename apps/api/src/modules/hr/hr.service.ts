import { Injectable, NotFoundException } from '@nestjs/common';
import { PrismaService } from '../../prisma/prisma.service';

@Injectable()
export class HrService {
  constructor(private prisma: PrismaService) {}

  async findAllEmployees(tenantId: string, query: any) {
    const { page = 1, limit = 50, department, status } = query;
    const skip = (page - 1) * limit;
    const where: any = { tenantId };

    if (department) where.department = department;
    if (status) where.status = status;

    const [items, total] = await Promise.all([
      this.prisma.employee.findMany({
        where,
        skip,
        take: limit,
        include: {
          user: { select: { email: true, firstName: true, lastName: true } },
        },
        orderBy: { createdAt: 'desc' },
      }),
      this.prisma.employee.count({ where }),
    ]);

    return { items, total, page, limit, totalPages: Math.ceil(total / limit) };
  }

  async findOneEmployee(tenantId: string, id: string) {
    const employee = await this.prisma.employee.findFirst({
      where: { id, tenantId },
      include: {
        user: true,
        leaveRequests: { orderBy: { createdAt: 'desc' } },
      },
    });

    if (!employee) {
      throw new NotFoundException('Employé non trouvé');
    }

    return employee;
  }

  async createEmployee(tenantId: string, dto: any) {
    // hireDate vient d'un <input type="date"> (chaîne "AAAA-MM-JJ") : conversion
    // explicite en DateTime, sinon Prisma rejette (champ hireDate requis) → 500.
    return this.prisma.employee.create({
      data: {
        ...dto,
        ...(dto.hireDate ? { hireDate: new Date(dto.hireDate) } : {}),
        tenantId,
      },
    });
  }

  async getPayroll(tenantId: string, month: string) {
    // Payroll non implémenté dans le schema - retourner vide
    return [];
  }

  async generatePayroll(tenantId: string, dto: any) {
    // Payroll non implémenté dans le schema
    return { message: 'Module paie non encore implémenté', count: 0 };
  }

  async findLeaveRequests(tenantId: string) {
    return this.prisma.leaveRequest.findMany({
      where: { tenantId },
      include: {
        employee: {
          include: {
            user: { select: { firstName: true, lastName: true } },
          },
        },
      },
      orderBy: { createdAt: 'desc' },
    });
  }

  async createLeaveRequest(tenantId: string, dto: any, userId: string) {
    const employee = await this.prisma.employee.findFirst({
      where: { tenantId, userId },
    });

    if (!employee) {
      throw new NotFoundException('Employé non trouvé');
    }

    return this.prisma.leaveRequest.create({
      data: { ...dto, tenantId, employeeId: employee.id },
    });
  }

  async approveLeave(tenantId: string, id: string) {
    const leave = await this.prisma.leaveRequest.findFirst({
      where: { id, tenantId },
    });

    if (!leave) {
      throw new NotFoundException('Demande de congé non trouvée');
    }

    return this.prisma.leaveRequest.update({
      where: { id },
      data: { status: 'APPROVED' },
    });
  }
}

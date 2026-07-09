import { BadRequestException, ConflictException, Injectable, NotFoundException } from "@nestjs/common";
import { Prisma } from "@scholaris/prisma";
import { PrismaService } from "../../prisma/prisma.service";
import { CreateDepartmentDto } from "./dto/create-department.dto";
import { UpdateDepartmentDto } from "./dto/update-department.dto";

const UNIQUE_CONSTRAINT_ERROR = "P2002";

@Injectable()
export class DepartmentsService {
  constructor(private readonly prisma: PrismaService) {}

  async findAll() {
    return this.prisma.department.findMany({ orderBy: { name: "asc" } });
  }

  async findOne(id: string) {
    const department = await this.prisma.department.findFirst({ where: { id } });
    if (!department) {
      throw new NotFoundException("Département introuvable");
    }
    return department;
  }

  async create(dto: CreateDepartmentDto, tenantId: string) {
    if (dto.headTeacherId) {
      await this.assertUserExists(dto.headTeacherId);
    }
    try {
      return await this.prisma.department.create({
        data: { code: dto.code, name: dto.name, headTeacherId: dto.headTeacherId, tenantId },
      });
    } catch (error) {
      throw this.mapUniqueConstraint(error);
    }
  }

  async update(id: string, dto: UpdateDepartmentDto) {
    await this.findOne(id);
    if (dto.headTeacherId) {
      await this.assertUserExists(dto.headTeacherId);
    }
    try {
      return await this.prisma.department.update({ where: { id }, data: dto });
    } catch (error) {
      throw this.mapUniqueConstraint(error);
    }
  }

  async remove(id: string) {
    await this.findOne(id);
    const programsCount = await this.prisma.program.count({ where: { departmentId: id } });
    if (programsCount > 0) {
      throw new ConflictException("Impossible de supprimer un département qui a des filières/programmes rattachés");
    }
    await this.prisma.department.delete({ where: { id } });
  }

  private async assertUserExists(userId: string) {
    const user = await this.prisma.user.findFirst({ where: { id: userId } });
    if (!user) {
      throw new BadRequestException("L'enseignant/chef de département désigné est introuvable");
    }
  }

  private mapUniqueConstraint(error: unknown): Error {
    if (error instanceof Prisma.PrismaClientKnownRequestError && error.code === UNIQUE_CONSTRAINT_ERROR) {
      return new ConflictException("Un département avec ce code existe déjà");
    }
    return error as Error;
  }
}

import {
  Injectable,
  NotFoundException,
  ConflictException,
  BadRequestException,
} from "@nestjs/common";
import { PrismaService } from "../../prisma/prisma.service";
import { CreateUserDto } from "./dto/create-user.dto";
import { UpdateUserDto } from "./dto/update-user.dto";
import { FindUsersQueryDto } from "./dto/find-users-query.dto";
import * as bcrypt from "bcrypt";

@Injectable()
export class UsersService {
  constructor(private readonly prisma: PrismaService) {}

  async findAll(query: FindUsersQueryDto, tenantId: string) {
    const { page = 1, limit = 20, search, status, roleId } = query;
    const skip = (page - 1) * limit;

    const where: any = {
      tenantId,
      deletedAt: null,
    };

    if (search) {
      where.OR = [
        { email: { contains: search, mode: "insensitive" } },
        { firstName: { contains: search, mode: "insensitive" } },
        { lastName: { contains: search, mode: "insensitive" } },
      ];
    }

    if (status) {
      where.status = status;
    }

    if (roleId) {
      where.userRoles = {
        some: { roleId },
      };
    }

    const [users, total] = await Promise.all([
      this.prisma.user.findMany({
        where,
        skip,
        take: limit,
        select: {
          id: true,
          email: true,
          firstName: true,
          lastName: true,
          phone: true,
          status: true,
          mfaEnabled: true,
          lastLogin: true,
          avatarUrl: true,
          createdAt: true,
          updatedAt: true,
          userRoles: {
            select: {
              role: {
                select: {
                  id: true,
                  name: true,
                  description: true,
                },
              },
            },
          },
        },
        orderBy: { createdAt: "desc" },
      }),
      this.prisma.user.count({ where }),
    ]);

    return {
      data: users.map((user) => ({
        ...user,
        roles: user.userRoles.map((ur) => ur.role),
        userRoles: undefined,
      })),
      pagination: {
        page,
        limit,
        total,
        totalPages: Math.ceil(total / limit),
      },
    };
  }

  async findOne(id: string, tenantId: string) {
    const user = await this.prisma.user.findFirst({
      where: { id, tenantId, deletedAt: null },
      select: {
        id: true,
        tenantId: true,
        email: true,
        firstName: true,
        lastName: true,
        phone: true,
        status: true,
        mfaEnabled: true,
        lastLogin: true,
        avatarUrl: true,
        failedLoginAttempts: true,
        lockedUntil: true,
        createdAt: true,
        updatedAt: true,
        userRoles: {
          select: {
            role: {
              select: {
                id: true,
                name: true,
                description: true,
                rolePermissions: {
                  select: {
                    permission: {
                      select: {
                        id: true,
                        resource: true,
                        action: true,
                        description: true,
                      },
                    },
                  },
                },
              },
            },
          },
        },
      },
    });

    if (!user) {
      throw new NotFoundException(`User ${id} not found`);
    }

    return {
      ...user,
      roles: user.userRoles.map((ur) => ({
        ...ur.role,
        permissions: ur.role.rolePermissions.map((rp) => rp.permission),
        rolePermissions: undefined,
      })),
      userRoles: undefined,
    };
  }

  async create(dto: CreateUserDto, tenantId: string) {
    // Vérifier si l'email existe déjà dans ce tenant
    const existing = await this.prisma.user.findFirst({
      where: {
        tenantId,
        email: dto.email,
        deletedAt: null,
      },
    });

    if (existing) {
      throw new ConflictException(
        `User with email ${dto.email} already exists in this tenant`,
      );
    }

    // Hasher le mot de passe
    const passwordHash = await bcrypt.hash(dto.password, 10);

    // Créer l'utilisateur avec transaction si des rôles sont fournis
    if (dto.roleIds && dto.roleIds.length > 0) {
      // Vérifier que les rôles existent
      const roles = await this.prisma.role.findMany({
        where: {
          id: { in: dto.roleIds },
          OR: [{ tenantId }, { tenantId: null }], // Rôles du tenant ou rôles système
        },
      });

      if (roles.length !== dto.roleIds.length) {
        throw new BadRequestException("One or more role IDs are invalid");
      }

      return this.prisma.$transaction(async (tx) => {
        const user = await tx.user.create({
          data: {
            tenantId,
            email: dto.email,
            passwordHash,
            firstName: dto.firstName,
            lastName: dto.lastName,
            phone: dto.phone,
            avatarUrl: dto.avatarUrl,
            status: dto.status || "ACTIVE",
            mfaEnabled: dto.mfaEnabled || false,
          },
          select: {
            id: true,
            email: true,
            firstName: true,
            lastName: true,
            phone: true,
            status: true,
            mfaEnabled: true,
            avatarUrl: true,
            createdAt: true,
          },
        });

        // Assigner les rôles
        await tx.userRole.createMany({
          data: dto.roleIds!.map((roleId) => ({
            userId: user.id,
            roleId,
          })),
        });

        // Récupérer les rôles assignés
        const userRoles = await tx.userRole.findMany({
          where: { userId: user.id },
          select: {
            role: {
              select: {
                id: true,
                name: true,
                description: true,
              },
            },
          },
        });

        return {
          ...user,
          roles: userRoles.map((ur) => ur.role),
        };
      });
    }

    // Créer sans rôles
    const user = await this.prisma.user.create({
      data: {
        tenantId,
        email: dto.email,
        passwordHash,
        firstName: dto.firstName,
        lastName: dto.lastName,
        phone: dto.phone,
        avatarUrl: dto.avatarUrl,
        status: dto.status || "ACTIVE",
        mfaEnabled: dto.mfaEnabled || false,
      },
      select: {
        id: true,
        email: true,
        firstName: true,
        lastName: true,
        phone: true,
        status: true,
        mfaEnabled: true,
        avatarUrl: true,
        createdAt: true,
      },
    });

    return { ...user, roles: [] };
  }

  async update(id: string, dto: UpdateUserDto, tenantId: string) {
    const user = await this.prisma.user.findFirst({
      where: { id, tenantId, deletedAt: null },
    });

    if (!user) {
      throw new NotFoundException(`User ${id} not found`);
    }

    // Si l'email est modifié, vérifier qu'il n'existe pas déjà
    if (dto.email && dto.email !== user.email) {
      const existing = await this.prisma.user.findFirst({
        where: {
          tenantId,
          email: dto.email,
          deletedAt: null,
          NOT: { id },
        },
      });

      if (existing) {
        throw new ConflictException(
          `User with email ${dto.email} already exists`,
        );
      }
    }

    const data: any = {};

    if (dto.email) data.email = dto.email;
    if (dto.firstName) data.firstName = dto.firstName;
    if (dto.lastName) data.lastName = dto.lastName;
    if (dto.phone !== undefined) data.phone = dto.phone;
    if (dto.avatarUrl !== undefined) data.avatarUrl = dto.avatarUrl;
    if (dto.status) data.status = dto.status;
    if (dto.mfaEnabled !== undefined) data.mfaEnabled = dto.mfaEnabled;

    // Si le mot de passe est modifié, le hasher
    if (dto.password) {
      data.passwordHash = await bcrypt.hash(dto.password, 10);
    }

    const updated = await this.prisma.user.update({
      where: { id },
      data,
      select: {
        id: true,
        email: true,
        firstName: true,
        lastName: true,
        phone: true,
        status: true,
        mfaEnabled: true,
        avatarUrl: true,
        updatedAt: true,
        userRoles: {
          select: {
            role: {
              select: {
                id: true,
                name: true,
                description: true,
              },
            },
          },
        },
      },
    });

    return {
      ...updated,
      roles: updated.userRoles.map((ur) => ur.role),
      userRoles: undefined,
    };
  }

  async remove(id: string, tenantId: string) {
    const user = await this.prisma.user.findFirst({
      where: { id, tenantId, deletedAt: null },
    });

    if (!user) {
      throw new NotFoundException(`User ${id} not found`);
    }

    // Soft delete
    await this.prisma.user.update({
      where: { id },
      data: { deletedAt: new Date() },
    });

    return { message: "User deleted successfully" };
  }

  async assignRoles(userId: string, roleIds: string[], tenantId: string) {
    const user = await this.prisma.user.findFirst({
      where: { id: userId, tenantId, deletedAt: null },
    });

    if (!user) {
      throw new NotFoundException(`User ${userId} not found`);
    }

    // Vérifier que les rôles existent
    const roles = await this.prisma.role.findMany({
      where: {
        id: { in: roleIds },
        OR: [{ tenantId }, { tenantId: null }], // Rôles du tenant ou rôles système
      },
    });

    if (roles.length !== roleIds.length) {
      throw new BadRequestException("One or more role IDs are invalid");
    }

    return this.prisma.$transaction(async (tx) => {
      // Supprimer les anciens rôles
      await tx.userRole.deleteMany({
        where: { userId },
      });

      // Assigner les nouveaux rôles
      if (roleIds.length > 0) {
        await tx.userRole.createMany({
          data: roleIds.map((roleId) => ({
            userId,
            roleId,
          })),
        });
      }

      // Récupérer les rôles assignés
      const userRoles = await tx.userRole.findMany({
        where: { userId },
        select: {
          role: {
            select: {
              id: true,
              name: true,
              description: true,
              rolePermissions: {
                select: {
                  permission: {
                    select: {
                      id: true,
                      resource: true,
                      action: true,
                      description: true,
                    },
                  },
                },
              },
            },
          },
        },
      });

      return {
        userId,
        roles: userRoles.map((ur) => ({
          ...ur.role,
          permissions: ur.role.rolePermissions.map((rp) => rp.permission),
          rolePermissions: undefined,
        })),
      };
    });
  }

  async getStats(tenantId: string) {
    const [total, active, inactive, suspended] = await Promise.all([
      this.prisma.user.count({
        where: { tenantId, deletedAt: null },
      }),
      this.prisma.user.count({
        where: { tenantId, status: "ACTIVE", deletedAt: null },
      }),
      this.prisma.user.count({
        where: { tenantId, status: "INACTIVE", deletedAt: null },
      }),
      this.prisma.user.count({
        where: { tenantId, status: "SUSPENDED", deletedAt: null },
      }),
    ]);

    return {
      total,
      active,
      inactive,
      suspended,
    };
  }
}

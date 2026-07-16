import { BadRequestException, ConflictException, ForbiddenException, NotFoundException } from "@nestjs/common";
import { Prisma } from "@scholaris/prisma";
import { PrismaService } from "../../prisma/prisma.service";
import { AuditService } from "../audit/audit.service";
import { RolesService } from "./roles.service";

describe("RolesService", () => {
  let service: RolesService;
  let prisma: {
    role: { findFirst: jest.Mock; findMany: jest.Mock; count: jest.Mock; create: jest.Mock; update: jest.Mock; delete: jest.Mock };
    permission: { findMany: jest.Mock };
    userRole: { count: jest.Mock };
    rolePermission: { deleteMany: jest.Mock; createMany: jest.Mock };
    $transaction: jest.Mock;
  };
  let audit: { log: jest.Mock };

  const tenantId = "tenant-1";

  const customRole = {
    id: "role-custom",
    tenantId,
    name: "Censeur",
    description: null,
    isSystem: false,
    createdAt: new Date(),
    rolePermissions: [],
  };

  const systemRole = {
    id: "role-system",
    tenantId: null,
    name: "SUPER_ADMIN",
    description: null,
    isSystem: true,
    createdAt: new Date(),
    rolePermissions: [],
  };

  beforeEach(() => {
    prisma = {
      role: {
        findFirst: jest.fn(),
        findMany: jest.fn(),
        count: jest.fn(),
        create: jest.fn(),
        update: jest.fn(),
        delete: jest.fn(),
      },
      permission: { findMany: jest.fn() },
      userRole: { count: jest.fn() },
      rolePermission: { deleteMany: jest.fn(), createMany: jest.fn() },
      $transaction: jest.fn(async (arg: unknown) => {
        if (Array.isArray(arg)) {
          return Promise.all(arg);
        }
        return (arg as (tx: unknown) => Promise<unknown>)(prisma);
      }),
    };
    audit = { log: jest.fn().mockResolvedValue(undefined) };
    service = new RolesService(prisma as unknown as PrismaService, audit as unknown as AuditService);
  });

  describe("create", () => {
    it("crée un rôle personnalisé avec ses permissions", async () => {
      prisma.permission.findMany.mockResolvedValue([{ id: "perm-1" }, { id: "perm-2" }]);
      prisma.role.create.mockResolvedValue(customRole);
      prisma.role.findFirst.mockResolvedValue({
        ...customRole,
        rolePermissions: [{ permission: { id: "perm-1", resource: "grades", action: "read" } }],
      });

      const result = await service.create({ name: "Censeur", permissionIds: ["perm-1", "perm-2"] }, tenantId);

      expect(prisma.role.create).toHaveBeenCalledWith(
        expect.objectContaining({ data: expect.objectContaining({ tenantId, name: "Censeur", isSystem: false }) }),
      );
      expect(prisma.rolePermission.createMany).toHaveBeenCalled();
      expect(result.permissions).toEqual([{ id: "perm-1", resource: "grades", action: "read" }]);
      expect(audit.log).toHaveBeenCalledWith(expect.objectContaining({ action: "create", resource: "roles" }));
    });

    it("rejette une liste de permissions invalides", async () => {
      prisma.permission.findMany.mockResolvedValue([{ id: "perm-1" }]);

      await expect(
        service.create({ name: "Censeur", permissionIds: ["perm-1", "perm-invalide"] }, tenantId),
      ).rejects.toThrow(BadRequestException);
      expect(prisma.role.create).not.toHaveBeenCalled();
    });

    it("rejette un nom de rôle déjà utilisé dans l'établissement (contrainte unique)", async () => {
      prisma.permission.findMany.mockResolvedValue([]);
      const prismaError = new Prisma.PrismaClientKnownRequestError("Unique constraint failed", {
        code: "P2002",
        clientVersion: "5.0.0",
      });
      prisma.role.create.mockRejectedValue(prismaError);

      await expect(service.create({ name: "Censeur" }, tenantId)).rejects.toThrow(ConflictException);
    });
  });

  describe("update", () => {
    it("refuse de modifier un rôle système", async () => {
      prisma.role.findFirst.mockResolvedValue(systemRole);

      await expect(service.update("role-system", { name: "Hack" }, tenantId)).rejects.toThrow(ForbiddenException);
      expect(prisma.role.update).not.toHaveBeenCalled();
    });

    it("refuse de modifier un rôle d'un autre établissement", async () => {
      prisma.role.findFirst.mockResolvedValue({ ...customRole, tenantId: "autre-tenant" });

      await expect(service.update("role-custom", { name: "Hack" }, tenantId)).rejects.toThrow(ForbiddenException);
    });

    it("remplace les permissions d'un rôle personnalisé", async () => {
      prisma.role.findFirst
        .mockResolvedValueOnce(customRole)
        .mockResolvedValueOnce({ ...customRole, rolePermissions: [{ permission: { id: "perm-3" } }] });
      prisma.permission.findMany.mockResolvedValue([{ id: "perm-3" }]);
      prisma.role.update.mockResolvedValue(customRole);

      const result = await service.update("role-custom", { permissionIds: ["perm-3"] }, tenantId);

      expect(prisma.rolePermission.deleteMany).toHaveBeenCalledWith({ where: { roleId: "role-custom" } });
      expect(prisma.rolePermission.createMany).toHaveBeenCalled();
      expect(result.permissions).toEqual([{ id: "perm-3" }]);
    });

    it("rejette un rôle introuvable", async () => {
      prisma.role.findFirst.mockResolvedValue(null);

      await expect(service.update("inconnu", { name: "X" }, tenantId)).rejects.toThrow(NotFoundException);
    });
  });

  describe("remove", () => {
    it("refuse de supprimer un rôle système", async () => {
      prisma.role.findFirst.mockResolvedValue(systemRole);

      await expect(service.remove("role-system", tenantId)).rejects.toThrow(ForbiddenException);
      expect(prisma.role.delete).not.toHaveBeenCalled();
    });

    it("refuse de supprimer un rôle encore assigné à des utilisateurs", async () => {
      prisma.role.findFirst.mockResolvedValue(customRole);
      prisma.userRole.count.mockResolvedValue(2);

      await expect(service.remove("role-custom", tenantId)).rejects.toThrow(ConflictException);
      expect(prisma.role.delete).not.toHaveBeenCalled();
    });

    it("supprime un rôle personnalisé inutilisé", async () => {
      prisma.role.findFirst.mockResolvedValue(customRole);
      prisma.userRole.count.mockResolvedValue(0);
      prisma.rolePermission.deleteMany.mockResolvedValue({ count: 0 });
      prisma.role.delete.mockResolvedValue(customRole);

      const result = await service.remove("role-custom", tenantId);

      expect(prisma.role.delete).toHaveBeenCalledWith({ where: { id: "role-custom" } });
      expect(result.message).toBeDefined();
      expect(audit.log).toHaveBeenCalledWith(expect.objectContaining({ action: "delete", resource: "roles" }));
    });
  });

  describe("findAllPermissionsGrouped", () => {
    it("groupe les permissions par ressource", async () => {
      prisma.permission.findMany.mockResolvedValue([
        { id: "1", resource: "grades", action: "read" },
        { id: "2", resource: "grades", action: "create" },
        { id: "3", resource: "users", action: "read" },
      ]);

      const grouped = await service.findAllPermissionsGrouped();

      expect(Object.keys(grouped)).toEqual(["grades", "users"]);
      expect(grouped.grades).toHaveLength(2);
      expect(grouped.users).toHaveLength(1);
    });
  });
});

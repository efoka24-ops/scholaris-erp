import { PrismaService } from "../../prisma/prisma.service";
import { RequestContextService } from "../../common/context/request-context.service";
import { AuditService } from "./audit.service";

describe("AuditService", () => {
  let service: AuditService;
  let prisma: {
    auditLog: { create: jest.Mock; findMany: jest.Mock; count: jest.Mock };
  };
  let context: { get: jest.Mock };

  beforeEach(() => {
    prisma = {
      auditLog: { create: jest.fn().mockResolvedValue({}), findMany: jest.fn(), count: jest.fn() },
    };
    context = {
      get: jest.fn((key: string) => (key === "userId" ? "user-1" : key === "ip" ? "10.0.0.1" : undefined)),
    };
    service = new AuditService(prisma as unknown as PrismaService, context as unknown as RequestContextService);
  });

  describe("log", () => {
    it("enregistre l'action avec l'utilisateur et l'IP du contexte de requête", async () => {
      await service.log({
        action: "update",
        resource: "tenants",
        resourceId: "tenant-1",
        oldValue: { name: "Ancien nom" },
        newValue: { name: "Nouveau nom" },
      });

      expect(prisma.auditLog.create).toHaveBeenCalledWith({
        data: {
          userId: "user-1",
          action: "update",
          resource: "tenants",
          resourceId: "tenant-1",
          oldValue: { name: "Ancien nom" },
          newValue: { name: "Nouveau nom" },
          ipAddress: "10.0.0.1",
        },
      });
    });

    it("enregistre un userId null quand aucun utilisateur n'est dans le contexte", async () => {
      context.get.mockReturnValue(undefined);

      await service.log({ action: "create", resource: "academic-years" });

      expect(prisma.auditLog.create).toHaveBeenCalledWith({
        data: expect.objectContaining({ userId: null, ipAddress: null }),
      });
    });

    it("n'échoue jamais même si l'écriture du journal échoue", async () => {
      prisma.auditLog.create.mockRejectedValue(new Error("db down"));

      await expect(service.log({ action: "delete", resource: "periods" })).resolves.toBeUndefined();
    });

    it("sérialise les valeurs non JSON (dates) avant écriture", async () => {
      const date = new Date("2026-09-01T00:00:00.000Z");

      await service.log({ action: "create", resource: "academic-years", newValue: { startDate: date } });

      expect(prisma.auditLog.create).toHaveBeenCalledWith({
        data: expect.objectContaining({ newValue: { startDate: "2026-09-01T00:00:00.000Z" } }),
      });
    });
  });

  describe("findAll", () => {
    it("pagine et filtre par utilisateur, action, ressource et dates dans le périmètre du tenant", async () => {
      prisma.auditLog.findMany.mockResolvedValue([]);
      prisma.auditLog.count.mockResolvedValue(0);

      await service.findAll("tenant-1", {
        page: 2,
        limit: 10,
        userId: "user-2",
        action: "update",
        resource: "tenants",
        dateFrom: "2026-01-01",
        dateTo: "2026-12-31",
      });

      expect(prisma.auditLog.findMany).toHaveBeenCalledWith(
        expect.objectContaining({
          where: expect.objectContaining({
            user: { tenantId: "tenant-1" },
            userId: "user-2",
            action: "update",
            resource: "tenants",
            timestamp: { gte: new Date("2026-01-01"), lte: new Date("2026-12-31") },
          }),
          skip: 10,
          take: 10,
        }),
      );
    });
  });
});

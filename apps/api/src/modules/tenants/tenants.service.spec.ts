import { BadRequestException, NotFoundException } from "@nestjs/common";
import { DEFAULT_CALCULATION_ENGINE_CONFIG } from "@scholaris/shared";
import { PrismaService } from "../../prisma/prisma.service";
import { AuditService } from "../audit/audit.service";
import { TenantsService } from "./tenants.service";

describe("TenantsService", () => {
  let service: TenantsService;
  let prisma: {
    tenant: { findFirst: jest.Mock; update: jest.Mock };
  };
  let audit: { log: jest.Mock };

  const tenant = {
    id: "tenant-1",
    name: "Établissement Démo",
    address: null,
    phone: null,
    email: null,
    logoUrl: null,
    configJson: null,
  };

  beforeEach(() => {
    prisma = {
      tenant: { findFirst: jest.fn(), update: jest.fn() },
    };
    audit = { log: jest.fn().mockResolvedValue(undefined) };
    service = new TenantsService(prisma as unknown as PrismaService, audit as unknown as AuditService);
  });

  describe("updateConfig", () => {
    it("accepte une configuration valide et la persiste dans config_json", async () => {
      prisma.tenant.findFirst.mockResolvedValue(tenant);
      prisma.tenant.update.mockResolvedValue({ ...tenant, configJson: DEFAULT_CALCULATION_ENGINE_CONFIG });

      const result = await service.updateConfig("tenant-1", DEFAULT_CALCULATION_ENGINE_CONFIG);

      expect(prisma.tenant.update).toHaveBeenCalledWith({
        where: { id: "tenant-1" },
        data: { configJson: expect.objectContaining({ evaluationType: "SEQUENTIAL" }) },
      });
      expect(result.evaluationType).toBe("SEQUENTIAL");
      expect(audit.log).toHaveBeenCalledWith(
        expect.objectContaining({ action: "update", resource: "tenants:config", resourceId: "tenant-1" }),
      );
    });

    it("rejette une configuration invalide (type d'évaluation inconnu) avec un 400", async () => {
      prisma.tenant.findFirst.mockResolvedValue(tenant);

      await expect(
        service.updateConfig("tenant-1", { ...DEFAULT_CALCULATION_ENGINE_CONFIG, evaluationType: "ANNUEL" }),
      ).rejects.toThrow(BadRequestException);
      expect(prisma.tenant.update).not.toHaveBeenCalled();
    });

    it("rejette une configuration LMD sans échelle GPA", async () => {
      prisma.tenant.findFirst.mockResolvedValue(tenant);

      await expect(
        service.updateConfig("tenant-1", {
          evaluationType: "LMD",
          roundingRule: "HUNDREDTH",
          absenceRule: "ZERO",
          mentionThresholds: [{ code: "P", label: "Passable", minAverage: 10 }],
          lmdCompensation: true,
        }),
      ).rejects.toThrow(BadRequestException);
    });

    it("rejette une configuration avec des clés inconnues", async () => {
      prisma.tenant.findFirst.mockResolvedValue(tenant);

      await expect(
        service.updateConfig("tenant-1", { ...DEFAULT_CALCULATION_ENGINE_CONFIG, cheval: "troie" }),
      ).rejects.toThrow(BadRequestException);
    });

    it("rejette un établissement introuvable", async () => {
      prisma.tenant.findFirst.mockResolvedValue(null);

      await expect(service.updateConfig("tenant-x", DEFAULT_CALCULATION_ENGINE_CONFIG)).rejects.toThrow(
        NotFoundException,
      );
    });
  });

  describe("update", () => {
    it("met à jour l'établissement et journalise anciennes/nouvelles valeurs", async () => {
      prisma.tenant.findFirst.mockResolvedValue(tenant);
      prisma.tenant.update.mockResolvedValue({ ...tenant, name: "Nouveau nom" });

      await service.update("tenant-1", { name: "Nouveau nom" });

      expect(audit.log).toHaveBeenCalledWith(
        expect.objectContaining({
          oldValue: expect.objectContaining({ name: "Établissement Démo" }),
          newValue: { name: "Nouveau nom" },
        }),
      );
    });
  });
});

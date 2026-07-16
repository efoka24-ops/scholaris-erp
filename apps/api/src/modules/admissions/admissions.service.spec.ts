import { BadRequestException, NotFoundException } from "@nestjs/common";
import { AdmissionStatus, AdmissionType } from "@scholaris/prisma";
import { PrismaService } from "../../prisma/prisma.service";
import { AdmissionsService } from "./admissions.service";

describe("AdmissionsService", () => {
  let service: AdmissionsService;
  let prisma: {
    academicYear: { findFirst: jest.Mock };
    admissionApplication: { create: jest.Mock; findFirst: jest.Mock; findMany: jest.Mock; count: jest.Mock; update: jest.Mock };
    tenant: { findFirst: jest.Mock };
  };

  beforeEach(() => {
    prisma = {
      academicYear: { findFirst: jest.fn().mockResolvedValue({ id: "year-1" }) },
      admissionApplication: {
        create: jest.fn(),
        findFirst: jest.fn(),
        findMany: jest.fn().mockResolvedValue([]),
        count: jest.fn().mockResolvedValue(0),
        update: jest.fn(),
      },
      tenant: { findFirst: jest.fn().mockResolvedValue({ id: "tenant-1", code: "DEMO" }) },
    };
    service = new AdmissionsService(prisma as unknown as PrismaService);
  });

  it("crée une candidature en statut PENDING par défaut", async () => {
    await service.create(
      { applicantName: "Essomba Marie", type: AdmissionType.EXAM, academicYearId: "year-1" },
      "tenant-1",
    );

    expect(prisma.admissionApplication.create).toHaveBeenCalledWith({
      data: expect.objectContaining({ applicantName: "Essomba Marie", tenantId: "tenant-1" }),
    });
  });

  it("rejette la création si l'année académique est introuvable", async () => {
    prisma.academicYear.findFirst.mockResolvedValue(null);

    await expect(
      service.create({ applicantName: "X", type: AdmissionType.DOSSIER, academicYearId: "inconnue" }, "tenant-1"),
    ).rejects.toThrow(BadRequestException);
  });

  it("applique la décision (accepter / refuser / liste d'attente) avec score et rang", async () => {
    prisma.admissionApplication.findFirst.mockResolvedValue({ id: "adm-1" });

    await service.decide("adm-1", { status: AdmissionStatus.ACCEPTED, score: 15.5, rank: 3 });

    expect(prisma.admissionApplication.update).toHaveBeenCalledWith({
      where: { id: "adm-1" },
      data: { status: AdmissionStatus.ACCEPTED, score: 15.5, rank: 3 },
    });
  });

  it("rejette la décision si la candidature est introuvable", async () => {
    prisma.admissionApplication.findFirst.mockResolvedValue(null);

    await expect(service.decide("inconnue", { status: AdmissionStatus.REJECTED })).rejects.toThrow(NotFoundException);
  });

  describe("createPublic (pré-inscription publique)", () => {
    const basePayload = {
      tenantCode: "DEMO",
      studentLastName: "Essomba",
      studentFirstName: "Marie-Claire",
      studentDateOfBirth: "2015-03-12",
      desiredLevel: "6ème",
      parentName: "Essomba Jean",
      parentPhone: "+237691234567",
    };

    it("crée une candidature DOSSIER en statut PENDING via le tenant résolu par code", async () => {
      prisma.admissionApplication.create.mockResolvedValue({ id: "adm-public-1" });

      const result = await service.createPublic(basePayload as any);

      expect(prisma.tenant.findFirst).toHaveBeenCalledWith({ where: { code: "DEMO", deletedAt: null } });
      expect(prisma.admissionApplication.create).toHaveBeenCalledWith({
        data: expect.objectContaining({
          applicantName: "Essomba Marie-Claire",
          type: AdmissionType.DOSSIER,
          tenantId: "tenant-1",
          academicYearId: "year-1",
        }),
      });
      expect(result).toEqual({ id: "adm-public-1" });
    });

    it("rejette avec 404 si le code établissement est inconnu", async () => {
      prisma.tenant.findFirst.mockResolvedValue(null);

      await expect(service.createPublic({ ...basePayload, tenantCode: "INCONNU" } as any)).rejects.toThrow(
        NotFoundException,
      );
      expect(prisma.admissionApplication.create).not.toHaveBeenCalled();
    });

    it("ignore silencieusement une soumission dont le honeypot est rempli (bot)", async () => {
      const result = await service.createPublic({ ...basePayload, website: "http://bot.example" } as any);

      expect(prisma.tenant.findFirst).not.toHaveBeenCalled();
      expect(prisma.admissionApplication.create).not.toHaveBeenCalled();
      expect(result).toEqual({ accepted: true });
    });
  });
});

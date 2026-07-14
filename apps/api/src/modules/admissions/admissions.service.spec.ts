import { BadRequestException, NotFoundException } from "@nestjs/common";
import { AdmissionStatus, AdmissionType } from "@scholaris/prisma";
import { PrismaService } from "../../prisma/prisma.service";
import { AdmissionsService } from "./admissions.service";

describe("AdmissionsService", () => {
  let service: AdmissionsService;
  let prisma: {
    academicYear: { findFirst: jest.Mock };
    admissionApplication: { create: jest.Mock; findFirst: jest.Mock; findMany: jest.Mock; count: jest.Mock; update: jest.Mock };
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
});

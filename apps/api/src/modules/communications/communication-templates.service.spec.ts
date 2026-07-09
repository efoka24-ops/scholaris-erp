import { NotFoundException } from "@nestjs/common";
import { Channel } from "@scholaris/prisma";
import { PrismaService } from "../../prisma/prisma.service";
import { CommunicationTemplatesService } from "./communication-templates.service";

describe("CommunicationTemplatesService", () => {
  let service: CommunicationTemplatesService;
  let prisma: { communicationTemplate: { findMany: jest.Mock; findFirst: jest.Mock; create: jest.Mock; update: jest.Mock } };

  beforeEach(() => {
    prisma = {
      communicationTemplate: { findMany: jest.fn(), findFirst: jest.fn(), create: jest.fn(), update: jest.fn() },
    };
    service = new CommunicationTemplatesService(prisma as unknown as PrismaService);
  });

  it("crée un template avec le tenantId courant", async () => {
    prisma.communicationTemplate.create.mockResolvedValue({ id: "tpl-1" });

    await service.create("tenant-1", {
      code: "CONVOCATION",
      name: "Convocation",
      channel: Channel.EMAIL,
      bodyFr: "Bonjour {nom_eleve}",
    });

    expect(prisma.communicationTemplate.create).toHaveBeenCalledWith({
      data: { tenantId: "tenant-1", code: "CONVOCATION", name: "Convocation", channel: Channel.EMAIL, bodyFr: "Bonjour {nom_eleve}" },
    });
  });

  it("rejette la mise à jour avec NotFoundException si le template n'existe pas", async () => {
    prisma.communicationTemplate.findFirst.mockResolvedValue(null);

    await expect(service.update("missing", { name: "x" })).rejects.toThrow(NotFoundException);
  });

  it("met à jour un template existant", async () => {
    prisma.communicationTemplate.findFirst.mockResolvedValue({ id: "tpl-1" });
    prisma.communicationTemplate.update.mockResolvedValue({ id: "tpl-1", name: "Nouveau nom" });

    const result = await service.update("tpl-1", { name: "Nouveau nom" });

    expect(prisma.communicationTemplate.update).toHaveBeenCalledWith({ where: { id: "tpl-1" }, data: { name: "Nouveau nom" } });
    expect(result.name).toBe("Nouveau nom");
  });
});

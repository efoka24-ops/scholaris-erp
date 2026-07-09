import { ForbiddenException, NotFoundException } from "@nestjs/common";
import { PrismaService } from "../../prisma/prisma.service";
import { InternalMessagesService } from "./internal-messages.service";

describe("InternalMessagesService", () => {
  let service: InternalMessagesService;
  let prisma: {
    internalMessage: { create: jest.Mock; findMany: jest.Mock; count: jest.Mock; findFirst: jest.Mock; update: jest.Mock };
  };

  beforeEach(() => {
    prisma = {
      internalMessage: {
        create: jest.fn(),
        findMany: jest.fn().mockResolvedValue([]),
        count: jest.fn().mockResolvedValue(0),
        findFirst: jest.fn(),
        update: jest.fn(),
      },
    };
    service = new InternalMessagesService(prisma as unknown as PrismaService);
  });

  it("crée un message interne avec l'expéditeur = utilisateur courant", async () => {
    prisma.internalMessage.create.mockResolvedValue({ id: "im-1" });

    await service.create("tenant-1", "sender-1", { recipientUserId: "recipient-1", body: "Bonjour" });

    expect(prisma.internalMessage.create).toHaveBeenCalledWith({
      data: { tenantId: "tenant-1", senderUserId: "sender-1", recipientUserId: "recipient-1", body: "Bonjour" },
    });
  });

  it("marque un message comme lu si l'appelant est le destinataire", async () => {
    prisma.internalMessage.findFirst.mockResolvedValue({ id: "im-1", recipientUserId: "user-1" });
    prisma.internalMessage.update.mockResolvedValue({ id: "im-1", readAt: new Date() });

    await service.markAsRead("im-1", "user-1");

    expect(prisma.internalMessage.update).toHaveBeenCalledWith({
      where: { id: "im-1" },
      data: { readAt: expect.any(Date) },
    });
  });

  it("rejette avec ForbiddenException si l'appelant n'est pas le destinataire", async () => {
    prisma.internalMessage.findFirst.mockResolvedValue({ id: "im-1", recipientUserId: "someone-else" });

    await expect(service.markAsRead("im-1", "user-1")).rejects.toThrow(ForbiddenException);
  });

  it("rejette avec NotFoundException si le message n'existe pas", async () => {
    prisma.internalMessage.findFirst.mockResolvedValue(null);

    await expect(service.markAsRead("missing", "user-1")).rejects.toThrow(NotFoundException);
  });
});

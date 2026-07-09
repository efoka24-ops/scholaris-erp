import { BadRequestException, NotFoundException } from "@nestjs/common";
import { Channel, MessageStatus } from "@scholaris/prisma";
import { PrismaService } from "../../prisma/prisma.service";
import { CommunicationsService } from "./communications.service";
import { EmailSenderService } from "./senders/email-sender.service";
import { SmsSenderService } from "./senders/sms-sender.service";
import { WhatsappSenderService } from "./senders/whatsapp-sender.service";
import { PushSenderService } from "./senders/push-sender.service";
import { InternalMessageService } from "./senders/internal-message.service";

describe("CommunicationsService", () => {
  let service: CommunicationsService;
  let prisma: {
    communicationTemplate: { findFirst: jest.Mock };
    user: { findFirst: jest.Mock };
    userChannelPreference: { findFirst: jest.Mock };
    communicationMessage: { create: jest.Mock; findMany: jest.Mock; count: jest.Mock };
  };
  let email: { send: jest.Mock };
  let sms: { send: jest.Mock };
  let whatsapp: { send: jest.Mock };
  let push: { send: jest.Mock };
  let internal: { send: jest.Mock };

  const recipient = { id: "user-1", email: "parent@example.com", phone: "+237600000000" };

  beforeEach(() => {
    prisma = {
      communicationTemplate: { findFirst: jest.fn() },
      user: { findFirst: jest.fn().mockResolvedValue(recipient) },
      userChannelPreference: { findFirst: jest.fn().mockResolvedValue(null) },
      communicationMessage: {
        create: jest.fn().mockImplementation(({ data }) => Promise.resolve({ id: "msg-1", ...data })),
        findMany: jest.fn(),
        count: jest.fn(),
      },
    };
    email = { send: jest.fn().mockResolvedValue({ providerMessageId: "email-1" }) };
    sms = { send: jest.fn().mockResolvedValue({ providerMessageId: "sms-1" }) };
    whatsapp = { send: jest.fn().mockResolvedValue({ providerMessageId: "wa-1" }) };
    push = { send: jest.fn().mockResolvedValue({}) };
    internal = { send: jest.fn().mockResolvedValue({}) };

    service = new CommunicationsService(
      prisma as unknown as PrismaService,
      email as unknown as EmailSenderService,
      sms as unknown as SmsSenderService,
      whatsapp as unknown as WhatsappSenderService,
      push as unknown as PushSenderService,
      internal as unknown as InternalMessageService,
    );
  });

  describe("renderTemplate", () => {
    it("substitue les variables connues et laisse les placeholders inconnus tels quels", () => {
      const template = {
        subjectFr: "Convocation pour {nom_eleve}",
        subjectEn: null,
        bodyFr: "Bonjour, {nom_eleve} de la classe {classe} est convoqué(e) le {date_echeance}.",
        bodyEn: null,
      };

      const result = service.renderTemplate(template, "fr", { nom_eleve: "Awa", classe: "5e A" });

      expect(result.subject).toBe("Convocation pour Awa");
      expect(result.body).toBe("Bonjour, Awa de la classe 5e A est convoqué(e) le {date_echeance}.");
    });

    it("bascule sur bodyEn/subjectEn en locale 'en', avec repli sur le FR si absent", () => {
      const template = { subjectFr: "Sujet FR", subjectEn: null, bodyFr: "Corps FR", bodyEn: "Body EN" };

      const result = service.renderTemplate(template, "en", {});

      expect(result.subject).toBe("Sujet FR"); // pas de subjectEn -> repli FR
      expect(result.body).toBe("Body EN");
    });
  });

  describe("send", () => {
    it("envoie via le canal explicitement demandé et persiste un CommunicationMessage SENT", async () => {
      const result = await service.send("tenant-1", {
        channel: Channel.EMAIL,
        recipientUserId: "user-1",
        body: "Bonjour",
      } as any);

      expect(email.send).toHaveBeenCalledWith({ to: "parent@example.com", subject: undefined, body: "Bonjour" });
      expect(result.status).toBe(MessageStatus.SENT);
      expect(result.providerMessageId).toBe("email-1");
      expect(prisma.communicationMessage.create).toHaveBeenCalledWith(
        expect.objectContaining({
          data: expect.objectContaining({ tenantId: "tenant-1", channel: Channel.EMAIL, status: MessageStatus.SENT }),
        }),
      );
    });

    it("rend le template et envoie sur son canal quand aucun channel/préférence n'est fourni", async () => {
      prisma.communicationTemplate.findFirst.mockResolvedValue({
        id: "tpl-1",
        channel: Channel.SMS,
        subjectFr: null,
        subjectEn: null,
        bodyFr: "Solde dû : {montant_du}",
        bodyEn: null,
      });

      const result = await service.send("tenant-1", {
        templateId: "tpl-1",
        recipientUserId: "user-1",
        variables: { montant_du: "50000 FCFA" },
      } as any);

      expect(sms.send).toHaveBeenCalledWith({ to: "+237600000000", subject: undefined, body: "Solde dû : 50000 FCFA" });
      expect(result.status).toBe(MessageStatus.SENT);
      expect(result.templateId).toBe("tpl-1");
    });

    it("utilise le canal de repli quand le canal préféré échoue, et persiste le canal final", async () => {
      prisma.userChannelPreference.findFirst.mockResolvedValue({
        preferredChannel: Channel.EMAIL,
        fallbackChannel: Channel.SMS,
      });
      email.send.mockRejectedValue(new Error("Brevo indisponible"));

      const result = await service.send("tenant-1", { recipientUserId: "user-1", body: "Bonjour" } as any);

      expect(email.send).toHaveBeenCalled();
      expect(sms.send).toHaveBeenCalled();
      expect(result.status).toBe(MessageStatus.SENT);
      expect(result.channel).toBe(Channel.SMS);
      expect(result.providerMessageId).toBe("sms-1");
    });

    it("persiste un statut FAILED avec errorMessage quand le canal préféré ET le repli échouent", async () => {
      prisma.userChannelPreference.findFirst.mockResolvedValue({
        preferredChannel: Channel.EMAIL,
        fallbackChannel: Channel.SMS,
      });
      email.send.mockRejectedValue(new Error("Brevo indisponible"));
      sms.send.mockRejectedValue(new Error("AT indisponible"));

      const result = await service.send("tenant-1", { recipientUserId: "user-1", body: "Bonjour" } as any);

      expect(result.status).toBe(MessageStatus.FAILED);
      expect(result.errorMessage).toBe("AT indisponible");
      expect(result.sentAt).toBeNull();
      expect(prisma.communicationMessage.create).toHaveBeenCalledTimes(1);
    });

    it("persiste un statut FAILED (sans repli configuré) quand le seul canal tenté échoue", async () => {
      email.send.mockRejectedValue(new Error("Brevo indisponible"));

      const result = await service.send("tenant-1", {
        channel: Channel.EMAIL,
        recipientUserId: "user-1",
        body: "Bonjour",
      } as any);

      expect(sms.send).not.toHaveBeenCalled();
      expect(result.status).toBe(MessageStatus.FAILED);
      expect(result.errorMessage).toBe("Brevo indisponible");
    });

    it("rejette avec BadRequestException si ni templateId ni body ne sont fournis", async () => {
      await expect(service.send("tenant-1", { recipientUserId: "user-1" } as any)).rejects.toThrow(BadRequestException);
    });

    it("rejette avec NotFoundException si le template n'existe pas", async () => {
      prisma.communicationTemplate.findFirst.mockResolvedValue(null);

      await expect(
        service.send("tenant-1", { templateId: "missing", recipientUserId: "user-1" } as any),
      ).rejects.toThrow(NotFoundException);
    });

    it("rejette avec NotFoundException si le destinataire n'existe pas", async () => {
      prisma.user.findFirst.mockResolvedValue(null);

      await expect(
        service.send("tenant-1", { channel: Channel.EMAIL, recipientUserId: "ghost", body: "x" } as any),
      ).rejects.toThrow(NotFoundException);
    });
  });

  describe("findAll", () => {
    it("construit la pagination à partir du total retourné par Prisma", async () => {
      prisma.communicationMessage.findMany.mockResolvedValue([{ id: "msg-1" }]);
      prisma.communicationMessage.count.mockResolvedValue(1);

      const result = await service.findAll("tenant-1", { page: 1, limit: 20 });

      expect(result.data).toHaveLength(1);
      expect(result.meta).toEqual({ total: 1, page: 1, limit: 20, totalPages: 1 });
      expect(prisma.communicationMessage.findMany).toHaveBeenCalledWith(
        expect.objectContaining({ where: { tenantId: "tenant-1" }, skip: 0, take: 20 }),
      );
    });
  });
});

import { Channel } from "@scholaris/prisma";
import { PrismaService } from "../../prisma/prisma.service";
import { ChannelPreferenceService } from "./channel-preference.service";

describe("ChannelPreferenceService", () => {
  let service: ChannelPreferenceService;
  let prisma: { userChannelPreference: { findFirst: jest.Mock; update: jest.Mock; create: jest.Mock } };

  beforeEach(() => {
    prisma = {
      userChannelPreference: { findFirst: jest.fn(), update: jest.fn(), create: jest.fn() },
    };
    service = new ChannelPreferenceService(prisma as unknown as PrismaService);
  });

  it("crée la préférence si elle n'existe pas encore", async () => {
    prisma.userChannelPreference.findFirst.mockResolvedValue(null);
    prisma.userChannelPreference.create.mockResolvedValue({ id: "pref-1" });

    await service.upsert("tenant-1", "user-1", { preferredChannel: Channel.EMAIL, fallbackChannel: Channel.SMS });

    expect(prisma.userChannelPreference.create).toHaveBeenCalledWith({
      data: { tenantId: "tenant-1", userId: "user-1", preferredChannel: Channel.EMAIL, fallbackChannel: Channel.SMS },
    });
  });

  it("met à jour la préférence existante plutôt que d'en recréer une", async () => {
    prisma.userChannelPreference.findFirst.mockResolvedValue({ id: "pref-1" });
    prisma.userChannelPreference.update.mockResolvedValue({ id: "pref-1" });

    await service.upsert("tenant-1", "user-1", { preferredChannel: Channel.SMS });

    expect(prisma.userChannelPreference.update).toHaveBeenCalledWith({
      where: { id: "pref-1" },
      data: { preferredChannel: Channel.SMS, fallbackChannel: null },
    });
  });
});

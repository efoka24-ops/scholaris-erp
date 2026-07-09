import { Injectable } from "@nestjs/common";
import { PrismaService } from "../../prisma/prisma.service";
import { ChannelPreferenceDto } from "./dto/channel-preference.dto";

/** Préférence de canal + repli d'un utilisateur (§23.2 du guide) — relation 1:1 séparée de User. */
@Injectable()
export class ChannelPreferenceService {
  constructor(private readonly prisma: PrismaService) {}

  findByUserId(userId: string) {
    return this.prisma.userChannelPreference.findFirst({ where: { userId } });
  }

  async upsert(tenantId: string, userId: string, dto: ChannelPreferenceDto) {
    const existing = await this.prisma.userChannelPreference.findFirst({ where: { userId } });
    if (existing) {
      return this.prisma.userChannelPreference.update({
        where: { id: existing.id },
        data: { preferredChannel: dto.preferredChannel, fallbackChannel: dto.fallbackChannel ?? null },
      });
    }
    return this.prisma.userChannelPreference.create({
      data: {
        tenantId,
        userId,
        preferredChannel: dto.preferredChannel,
        fallbackChannel: dto.fallbackChannel ?? null,
      },
    });
  }
}

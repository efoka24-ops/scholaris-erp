import { Injectable, NotFoundException } from "@nestjs/common";
import { PrismaService } from "../../prisma/prisma.service";
import { CreateCommunicationTemplateDto } from "./dto/create-communication-template.dto";
import { UpdateCommunicationTemplateDto } from "./dto/update-communication-template.dto";

/** CRUD des modèles de communication (§23.2 du guide) — pas de suppression, pas de soft-delete demandé. */
@Injectable()
export class CommunicationTemplatesService {
  constructor(private readonly prisma: PrismaService) {}

  findAll(tenantId: string) {
    return this.prisma.communicationTemplate.findMany({
      where: { tenantId },
      orderBy: { createdAt: "desc" },
    });
  }

  async findOne(id: string) {
    const template = await this.prisma.communicationTemplate.findFirst({ where: { id } });
    if (!template) {
      throw new NotFoundException("Modèle de communication introuvable");
    }
    return template;
  }

  create(tenantId: string, dto: CreateCommunicationTemplateDto) {
    return this.prisma.communicationTemplate.create({
      data: { tenantId, ...dto },
    });
  }

  async update(id: string, dto: UpdateCommunicationTemplateDto) {
    await this.findOne(id);
    return this.prisma.communicationTemplate.update({
      where: { id },
      data: dto,
    });
  }
}

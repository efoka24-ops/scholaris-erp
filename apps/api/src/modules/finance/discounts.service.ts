import { BadRequestException, Injectable, NotFoundException } from "@nestjs/common";
import { Discount, DiscountType } from "@scholaris/prisma";
import { PrismaService } from "../../prisma/prisma.service";
import { CreateDiscountDto } from "./dto/create-discount.dto";
import { computeInvoiceStatus, roundAmount } from "./invoice-status.util";

@Injectable()
export class DiscountsService {
  constructor(private readonly prisma: PrismaService) {}

  /**
   * Applique une réduction/bourse. Si `invoiceId` est fourni, elle est
   * appliquée immédiatement à cette facture (balance recalculée, plancher à
   * 0, statut recalculé) dans la même transaction que la création du
   * Discount. Si seul `studentId` est fourni, la réduction est enregistrée
   * sans impact immédiat (bourse générale, à valoir sur une future facture).
   */
  async create(dto: CreateDiscountDto, tenantId: string, approvedBy: string): Promise<Discount> {
    if (!dto.studentId && !dto.invoiceId) {
      throw new BadRequestException("Indiquez au moins un élève (studentId) ou une facture (invoiceId)");
    }
    if (dto.type === DiscountType.PERCENTAGE && dto.value > 100) {
      throw new BadRequestException("Un pourcentage de réduction ne peut pas dépasser 100");
    }

    if (!dto.invoiceId) {
      const student = await this.prisma.student.findFirst({ where: { id: dto.studentId } });
      if (!student) {
        throw new NotFoundException("Élève introuvable");
      }
      return this.prisma.discount.create({
        data: {
          studentId: dto.studentId,
          type: dto.type,
          value: dto.value,
          reason: dto.reason,
          approvedBy,
          tenantId,
        },
      });
    }

    const invoice = await this.prisma.invoice.findFirst({ where: { id: dto.invoiceId } });
    if (!invoice) {
      throw new NotFoundException("Facture introuvable");
    }

    const discountAmount = roundAmount(
      dto.type === DiscountType.PERCENTAGE ? (invoice.totalAmount * dto.value) / 100 : dto.value,
    );

    return this.prisma.$transaction(async (tx) => {
      const discount = await tx.discount.create({
        data: {
          studentId: dto.studentId ?? invoice.studentId,
          invoiceId: invoice.id,
          type: dto.type,
          value: dto.value,
          reason: dto.reason,
          approvedBy,
          tenantId,
        },
      });

      const balance = Math.max(0, roundAmount(invoice.balance - discountAmount));
      const status = computeInvoiceStatus(balance, invoice.paidAmount, invoice.dueDate);

      await tx.invoice.update({
        where: { id: invoice.id },
        data: { balance, status },
      });

      return discount;
    });
  }
}

import { BadRequestException, Injectable, NotFoundException } from "@nestjs/common";
import { PrismaService } from "../../prisma/prisma.service";
import { CreateFeeStructureDto } from "./dto/create-fee-structure.dto";
import { FindFeeStructuresQueryDto } from "./dto/find-fee-structures-query.dto";
import { roundAmount } from "./invoice-status.util";

@Injectable()
export class FeeStructuresService {
  constructor(private readonly prisma: PrismaService) {}

  /**
   * Crée la grille tarifaire et ses tranches dans une seule transaction. La
   * somme des tranches doit correspondre au montant total (tolérance d'1 FCFA
   * pour les arrondis) : sinon la facture générée plus tard ne balancerait
   * jamais avec son échéancier affiché.
   */
  async create(dto: CreateFeeStructureDto, tenantId: string) {
    if (dto.levelId) {
      const level = await this.prisma.level.findFirst({ where: { id: dto.levelId } });
      if (!level) {
        throw new BadRequestException("Le niveau indiqué est introuvable");
      }
    }
    const academicYear = await this.prisma.academicYear.findFirst({ where: { id: dto.academicYearId } });
    if (!academicYear) {
      throw new BadRequestException("L'année académique indiquée est introuvable");
    }

    const installmentsSum = roundAmount(dto.installments.reduce((sum, i) => sum + i.amount, 0));
    if (Math.abs(installmentsSum - roundAmount(dto.totalAmount)) > 1) {
      throw new BadRequestException(
        `La somme des tranches (${installmentsSum}) ne correspond pas au montant total (${dto.totalAmount})`,
      );
    }

    return this.prisma.$transaction(async (tx) => {
      const structure = await tx.feeStructure.create({
        data: {
          name: dto.name,
          levelId: dto.levelId,
          academicYearId: dto.academicYearId,
          totalAmount: dto.totalAmount,
          tenantId,
        },
      });

      await tx.feeInstallment.createMany({
        data: dto.installments.map((installment) => ({
          feeStructureId: structure.id,
          label: installment.label,
          amount: installment.amount,
          dueDate: new Date(installment.dueDate),
          order: installment.order,
          tenantId,
        })),
      });

      return tx.feeStructure.findFirst({
        where: { id: structure.id },
        include: { installments: { orderBy: { order: "asc" } }, level: true, academicYear: true },
      });
    });
  }

  async findAll(query: FindFeeStructuresQueryDto) {
    return this.prisma.feeStructure.findMany({
      where: {
        ...(query.levelId ? { levelId: query.levelId } : {}),
        ...(query.academicYearId ? { academicYearId: query.academicYearId } : {}),
      },
      include: { installments: { orderBy: { order: "asc" } }, level: true, academicYear: true },
      orderBy: { createdAt: "desc" },
    });
  }

  async findOne(id: string) {
    const structure = await this.prisma.feeStructure.findFirst({
      where: { id },
      include: { installments: { orderBy: { order: "asc" } }, level: true, academicYear: true },
    });
    if (!structure) {
      throw new NotFoundException("Grille tarifaire introuvable");
    }
    return structure;
  }

  /**
   * Grille applicable à un niveau pour une année donnée : priorité à une
   * grille spécifique au niveau, sinon repli sur une grille générale
   * (levelId = null) de la même année.
   */
  async findApplicable(levelId: string, academicYearId: string) {
    const specific = await this.prisma.feeStructure.findFirst({
      where: { levelId, academicYearId },
      include: { installments: { orderBy: { order: "asc" } } },
      orderBy: { createdAt: "desc" },
    });
    if (specific) {
      return specific;
    }
    return this.prisma.feeStructure.findFirst({
      where: { levelId: null, academicYearId },
      include: { installments: { orderBy: { order: "asc" } } },
      orderBy: { createdAt: "desc" },
    });
  }
}

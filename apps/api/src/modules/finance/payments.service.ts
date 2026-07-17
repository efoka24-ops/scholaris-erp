import { BadRequestException, Injectable, NotFoundException } from "@nestjs/common";
import { Payment, Prisma } from "@scholaris/prisma";
import { buildPaginationMeta, DEFAULT_LIMIT, DEFAULT_PAGE, MAX_LIMIT, PaginatedResult } from "@scholaris/shared";
import { PrismaService } from "../../prisma/prisma.service";
import { ReceiptService } from "./receipt.service";
import { CreatePaymentDto } from "./dto/create-payment.dto";
import { FindPaymentsQueryDto } from "./dto/find-payments-query.dto";
import { computeInvoiceStatus, roundAmount } from "./invoice-status.util";

@Injectable()
export class PaymentsService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly receipt: ReceiptService,
  ) {}

  /**
   * Enregistre un paiement et met à jour la facture (paidAmount / balance /
   * status) dans une même transaction. Le montant payé ne peut jamais
   * dépasser le solde restant (400) ; le numéro de reçu est réservé de façon
   * atomique via ReceiptSequence (même garantie que MatriculeSequence).
   */
  async create(dto: CreatePaymentDto, tenantId: string, receivedBy?: string): Promise<Payment> {
    const invoice = await this.prisma.invoice.findFirst({ where: { id: dto.invoiceId } });
    if (!invoice) {
      throw new NotFoundException("Facture introuvable");
    }

    const amount = roundAmount(dto.amount);
    if (amount > invoice.balance) {
      throw new BadRequestException(
        `Le montant payé (${amount}) dépasse le solde restant (${invoice.balance}) de la facture`,
      );
    }

    const tenant = await this.prisma.tenant.findFirst({ where: { id: tenantId } });
    if (!tenant) {
      throw new NotFoundException("Établissement introuvable");
    }

    return this.prisma.$transaction(async (tx) => {
      const receiptNumber = await this.receipt.generate(tx, tenant.code, tenantId);

      const payment = await tx.payment.create({
        data: {
          invoiceId: invoice.id,
          studentId: invoice.studentId,
          amount,
          method: dto.method,
          reference: dto.reference,
          receiptNumber,
          paidAt: dto.paidAt ? new Date(dto.paidAt) : new Date(),
          receivedBy,
          notes: dto.notes,
          tenantId,
        },
      });

      const paidAmount = roundAmount(invoice.paidAmount + amount);
      const balance = roundAmount(invoice.totalAmount - paidAmount);
      const status = computeInvoiceStatus(balance, paidAmount, invoice.dueDate);

      await tx.invoice.update({
        where: { id: invoice.id },
        data: { paidAmount, balance, status },
      });

      return payment;
    });
  }

  /** Liste paginée des paiements, triée par date de paiement décroissante (le plus récent en premier). */
  async findAll(query: FindPaymentsQueryDto): Promise<PaginatedResult<Payment>> {
    const page = query.page ?? DEFAULT_PAGE;
    const limit = Math.min(query.limit ?? DEFAULT_LIMIT, MAX_LIMIT);

    const where: Prisma.PaymentWhereInput = {
      ...(query.studentId ? { studentId: query.studentId } : {}),
      ...(query.invoiceId ? { invoiceId: query.invoiceId } : {}),
      ...(query.method ? { method: query.method } : {}),
      ...(query.dateFrom || query.dateTo
        ? {
            paidAt: {
              ...(query.dateFrom ? { gte: new Date(query.dateFrom) } : {}),
              ...(query.dateTo ? { lte: new Date(query.dateTo) } : {}),
            },
          }
        : {}),
    };

    const [data, total] = await Promise.all([
      this.prisma.payment.findMany({
        where,
        orderBy: { paidAt: "desc" },
        skip: (page - 1) * limit,
        take: limit,
        include: {
          student: true,
          invoice: true,
        },
      }),
      this.prisma.payment.count({ where }),
    ]);

    return { data, meta: buildPaginationMeta(total, page, limit) };
  }

  async findOne(id: string): Promise<Payment> {
    const payment = await this.prisma.payment.findFirst({ where: { id } });
    if (!payment) {
      throw new NotFoundException("Paiement introuvable");
    }
    return payment;
  }

  /** Données structurées du reçu — la mise en forme PDF arrivera avec le Module 6. */
  async getReceipt(id: string) {
    const payment = await this.prisma.payment.findFirst({
      where: { id },
      include: {
        invoice: { include: { academicYear: true, feeStructure: true } },
        student: true,
        receivedByUser: true,
        tenant: true,
      },
    });
    if (!payment) {
      throw new NotFoundException("Paiement introuvable");
    }

    return {
      receiptNumber: payment.receiptNumber,
      paidAt: payment.paidAt,
      amount: payment.amount,
      method: payment.method,
      reference: payment.reference,
      notes: payment.notes,
      establishment: {
        name: payment.tenant.name,
        code: payment.tenant.code,
        address: payment.tenant.address,
      },
      student: {
        id: payment.student.id,
        matricule: payment.student.matricule,
        firstName: payment.student.firstName,
        lastName: payment.student.lastName,
      },
      invoice: {
        id: payment.invoice.id,
        totalAmount: payment.invoice.totalAmount,
        paidAmount: payment.invoice.paidAmount,
        balance: payment.invoice.balance,
        status: payment.invoice.status,
        academicYear: payment.invoice.academicYear?.label,
      },
      receivedBy: payment.receivedByUser
        ? `${payment.receivedByUser.firstName} ${payment.receivedByUser.lastName}`
        : null,
    };
  }
}

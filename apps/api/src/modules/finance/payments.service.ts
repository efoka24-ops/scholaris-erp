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

  /**
   * Reçu de paiement imprimable (HTML A5, en-tête de l'établissement). Le montant
   * est libellé en FCFA. Réutilise getReceipt() pour les données.
   */
  async getReceiptHtml(id: string): Promise<string> {
    const r = await this.getReceipt(id);
    const esc = (v: any) => String(v ?? "").replace(/[&<>]/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;" }[c] as string));
    const fcfa = (v: any) => `${Number(v ?? 0).toLocaleString("fr-FR")} FCFA`;
    const methodLabel: Record<string, string> = {
      CASH: "Espèces",
      MOBILE_MONEY: "Mobile Money",
      BANK_TRANSFER: "Virement bancaire",
      CHECK: "Chèque",
    };
    return `<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>Reçu ${esc(r.receiptNumber)}</title>
    <style>
    *{margin:0;padding:0;box-sizing:border-box}
    body{font-family:Arial,sans-serif;font-size:10pt;color:#111;padding:8mm}
    .receipt{max-width:148mm;margin:0 auto;border:1px solid #333;padding:6mm}
    .head{text-align:center;border-bottom:2px solid #111;padding-bottom:3mm;margin-bottom:3mm}
    .head .est{font-size:13pt;font-weight:bold}
    .head .addr{font-size:8pt;color:#555}
    .title{text-align:center;font-size:12pt;font-weight:bold;text-transform:uppercase;letter-spacing:1px;margin:3mm 0}
    .num{text-align:center;font-family:monospace;font-size:9pt;color:#444;margin-bottom:3mm}
    table{width:100%;border-collapse:collapse;font-size:9.5pt}
    td{padding:1.5mm 0;border-bottom:1px dotted #ccc}
    td.k{color:#444}td.v{text-align:right;font-weight:bold}
    .amount{margin-top:4mm;text-align:center;font-size:14pt;font-weight:bold;border:1px solid #333;padding:3mm;background:#f5f5f5}
    .balance{margin-top:2mm;text-align:center;font-size:9pt;color:#666}
    .sign{display:flex;justify-content:space-between;margin-top:10mm;font-size:8.5pt;text-align:center}
    .sign .slot{width:45%}.sign .line{border-top:1px solid #333;margin-bottom:1mm;height:12mm}
    .foot{margin-top:4mm;text-align:center;font-size:7pt;color:#888}
    @page{size:A5}@media print{body{padding:0}}
    </style></head><body>
    <div class="receipt">
      <div class="head">
        <div class="est">${esc(r.establishment.name)}</div>
        ${r.establishment.address ? `<div class="addr">${esc(r.establishment.address)}</div>` : ""}
      </div>
      <div class="title">Reçu de paiement</div>
      <div class="num">N° ${esc(r.receiptNumber)}</div>
      <table>
        <tr><td class="k">Date</td><td class="v">${new Date(r.paidAt).toLocaleDateString("fr-FR")}</td></tr>
        <tr><td class="k">Élève</td><td class="v">${esc(r.student.lastName)} ${esc(r.student.firstName)}</td></tr>
        <tr><td class="k">Matricule</td><td class="v">${esc(r.student.matricule)}</td></tr>
        <tr><td class="k">Année académique</td><td class="v">${esc(r.invoice.academicYear ?? "—")}</td></tr>
        <tr><td class="k">Mode de paiement</td><td class="v">${esc(methodLabel[r.method] ?? r.method)}</td></tr>
        ${r.reference ? `<tr><td class="k">Référence</td><td class="v">${esc(r.reference)}</td></tr>` : ""}
      </table>
      <div class="amount">Montant payé : ${fcfa(r.amount)}</div>
      <div class="balance">Facture : ${fcfa(r.invoice.totalAmount)} · Total payé : ${fcfa(r.invoice.paidAmount)} · Solde : ${fcfa(r.invoice.balance)}</div>
      <div class="sign">
        <div class="slot"><div class="line"></div>L'Intendant${r.receivedBy ? ` (${esc(r.receivedBy)})` : ""}</div>
        <div class="slot"><div class="line"></div>Le Payeur</div>
      </div>
      <div class="foot">Reçu généré le ${new Date().toLocaleString("fr-FR")} · ${esc(r.establishment.name)}</div>
    </div></body></html>`;
  }
}

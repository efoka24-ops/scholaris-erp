import { Injectable } from "@nestjs/common";
import { Prisma } from "@scholaris/prisma";

/**
 * Numéro de reçu au format {code}/REC/{year}/{seq} → ex "LBD/REC/2026/000001".
 * Même pattern que MatriculeService (apps/api/src/modules/students/matricule.service.ts) :
 * un compteur ReceiptSequence par tenant+année, incrémenté via upsert atomique.
 */
const PADDING = 6;

@Injectable()
export class ReceiptService {
  /**
   * Réserve le prochain numéro de la séquence tenant+année et rend le numéro
   * de reçu formaté. À appeler DANS la transaction qui crée le Payment : deux
   * paiements concurrents ne peuvent jamais obtenir le même numéro.
   */
  async generate(
    tx: Pick<Prisma.TransactionClient, "receiptSequence">,
    tenantCode: string,
    tenantId: string,
    year: string = String(new Date().getFullYear()),
  ): Promise<string> {
    const sequence = await tx.receiptSequence.upsert({
      where: { tenantId_year: { tenantId, year } },
      update: { lastNumber: { increment: 1 } },
      create: { tenantId, year, lastNumber: 1 },
    });

    return this.format(tenantCode, year, sequence.lastNumber);
  }

  format(tenantCode: string, year: string, sequenceNumber: number): string {
    return `${tenantCode}/REC/${year}/${String(sequenceNumber).padStart(PADDING, "0")}`;
  }
}

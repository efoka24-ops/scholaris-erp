import { InvoiceStatus } from "@scholaris/prisma";

/**
 * Statut recalculé à chaque paiement / réduction (jamais stocké "à la main") :
 *  - PAID    si le solde restant est nul (ou négatif par arrondi),
 *  - OVERDUE si l'échéance est dépassée et qu'il reste un solde,
 *  - PARTIAL si un paiement partiel a déjà été enregistré,
 *  - PENDING sinon (aucun paiement, échéance non dépassée).
 */
export function computeInvoiceStatus(
  balance: number,
  paidAmount: number,
  dueDate: Date | null,
  now: Date = new Date(),
): InvoiceStatus {
  if (balance <= 0) {
    return InvoiceStatus.PAID;
  }
  if (dueDate && dueDate.getTime() < now.getTime()) {
    return InvoiceStatus.OVERDUE;
  }
  if (paidAmount > 0) {
    return InvoiceStatus.PARTIAL;
  }
  return InvoiceStatus.PENDING;
}

/** Arrondi au centime pour éviter les artefacts de virgule flottante sur les montants. */
export function roundAmount(value: number): number {
  return Math.round(value * 100) / 100;
}

import { InvoiceStatus } from "@scholaris/prisma";
import { computeInvoiceStatus, roundAmount } from "./invoice-status.util";

describe("computeInvoiceStatus", () => {
  const now = new Date("2026-07-13T00:00:00Z");

  it("rend PAID quand le solde est nul", () => {
    expect(computeInvoiceStatus(0, 100000, null, now)).toBe(InvoiceStatus.PAID);
  });

  it("rend PAID quand le solde est négatif par arrondi", () => {
    expect(computeInvoiceStatus(-0.01, 100000, null, now)).toBe(InvoiceStatus.PAID);
  });

  it("rend OVERDUE quand l'échéance est dépassée et qu'il reste un solde", () => {
    const pastDueDate = new Date("2026-01-01T00:00:00Z");
    expect(computeInvoiceStatus(50000, 0, pastDueDate, now)).toBe(InvoiceStatus.OVERDUE);
  });

  it("rend PARTIAL quand un paiement partiel a été fait et que l'échéance n'est pas dépassée", () => {
    const futureDueDate = new Date("2026-12-01T00:00:00Z");
    expect(computeInvoiceStatus(50000, 25000, futureDueDate, now)).toBe(InvoiceStatus.PARTIAL);
  });

  it("rend PENDING quand aucun paiement n'a été fait et que l'échéance n'est pas dépassée", () => {
    const futureDueDate = new Date("2026-12-01T00:00:00Z");
    expect(computeInvoiceStatus(100000, 0, futureDueDate, now)).toBe(InvoiceStatus.PENDING);
  });

  it("rend PENDING quand il n'y a pas d'échéance et aucun paiement", () => {
    expect(computeInvoiceStatus(100000, 0, null, now)).toBe(InvoiceStatus.PENDING);
  });

  it("priorise PAID sur OVERDUE même si l'échéance est dépassée", () => {
    const pastDueDate = new Date("2026-01-01T00:00:00Z");
    expect(computeInvoiceStatus(0, 100000, pastDueDate, now)).toBe(InvoiceStatus.PAID);
  });
});

describe("roundAmount", () => {
  it("arrondit au centime pour éviter les artefacts de virgule flottante", () => {
    expect(roundAmount(0.1 + 0.2)).toBe(0.3);
    expect(roundAmount(100.005)).toBeCloseTo(100.01, 2);
  });
});

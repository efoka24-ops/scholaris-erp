import { ReceiptService } from "./receipt.service";

describe("ReceiptService", () => {
  let service: ReceiptService;
  let tx: { receiptSequence: { upsert: jest.Mock } };

  beforeEach(() => {
    service = new ReceiptService();
    tx = { receiptSequence: { upsert: jest.fn() } };
  });

  it("génère le numéro de reçu au format {code}/REC/{year}/{seq} paddé sur 6 chiffres", async () => {
    tx.receiptSequence.upsert.mockResolvedValue({ lastNumber: 1 });

    const receiptNumber = await service.generate(tx as never, "LBD", "tenant-1", "2026");

    expect(receiptNumber).toBe("LBD/REC/2026/000001");
  });

  it("réserve le numéro via un upsert atomique (increment) sur la séquence tenant+année", async () => {
    tx.receiptSequence.upsert.mockResolvedValue({ lastNumber: 42 });

    const receiptNumber = await service.generate(tx as never, "LBD", "tenant-1", "2026");

    expect(tx.receiptSequence.upsert).toHaveBeenCalledWith({
      where: { tenantId_year: { tenantId: "tenant-1", year: "2026" } },
      update: { lastNumber: { increment: 1 } },
      create: { tenantId: "tenant-1", year: "2026", lastNumber: 1 },
    });
    expect(receiptNumber).toBe("LBD/REC/2026/000042");
  });

  it("ne réutilise jamais un numéro : deux générations successives rendent des reçus distincts", async () => {
    tx.receiptSequence.upsert.mockResolvedValueOnce({ lastNumber: 1 }).mockResolvedValueOnce({ lastNumber: 2 });

    const first = await service.generate(tx as never, "LBD", "tenant-1", "2026");
    const second = await service.generate(tx as never, "LBD", "tenant-1", "2026");

    expect(first).not.toBe(second);
  });

  it("isole les séquences par tenant : deux tenants différents obtiennent chacun leur numéro 1", async () => {
    tx.receiptSequence.upsert.mockResolvedValue({ lastNumber: 1 });

    const receiptA = await service.generate(tx as never, "AAA", "tenant-a", "2026");
    const receiptB = await service.generate(tx as never, "BBB", "tenant-b", "2026");

    expect(receiptA).toBe("AAA/REC/2026/000001");
    expect(receiptB).toBe("BBB/REC/2026/000001");
  });
});

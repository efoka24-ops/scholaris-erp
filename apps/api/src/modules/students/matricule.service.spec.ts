import { MatriculeService } from "./matricule.service";

describe("MatriculeService", () => {
  let service: MatriculeService;
  let tx: { matriculeSequence: { upsert: jest.Mock } };

  const tenant = { id: "tenant-1", code: "LBD", configJson: null };

  beforeEach(() => {
    service = new MatriculeService();
    tx = { matriculeSequence: { upsert: jest.fn() } };
  });

  it("génère le matricule au format par défaut {code}/{year}/{seq} paddé sur 4 chiffres", async () => {
    tx.matriculeSequence.upsert.mockResolvedValue({ lastNumber: 1 });

    const matricule = await service.generate(tx as never, tenant, "2026");

    expect(matricule).toBe("LBD/2026/0001");
  });

  it("réserve le numéro via un upsert atomique (increment) sur la séquence tenant+année", async () => {
    tx.matriculeSequence.upsert.mockResolvedValue({ lastNumber: 42 });

    const matricule = await service.generate(tx as never, tenant, "2026");

    expect(tx.matriculeSequence.upsert).toHaveBeenCalledWith({
      where: { tenantId_year: { tenantId: "tenant-1", year: "2026" } },
      update: { lastNumber: { increment: 1 } },
      create: { tenantId: "tenant-1", year: "2026", lastNumber: 1 },
    });
    expect(matricule).toBe("LBD/2026/0042");
  });

  it("respecte le format et le padding configurés dans config_json du tenant", async () => {
    tx.matriculeSequence.upsert.mockResolvedValue({ lastNumber: 7 });
    const configuredTenant = {
      ...tenant,
      configJson: { matricule: { format: "{year}-{code}-{seq}", padding: 6 } },
    };

    const matricule = await service.generate(tx as never, configuredTenant, "2026");

    expect(matricule).toBe("2026-LBD-000007");
  });

  it("ne réutilise jamais un numéro : deux générations successives rendent des matricules distincts", async () => {
    tx.matriculeSequence.upsert.mockResolvedValueOnce({ lastNumber: 1 }).mockResolvedValueOnce({ lastNumber: 2 });

    const first = await service.generate(tx as never, tenant, "2026");
    const second = await service.generate(tx as never, tenant, "2026");

    expect(first).toBe("LBD/2026/0001");
    expect(second).toBe("LBD/2026/0002");
    expect(first).not.toBe(second);
  });

  it("ignore une configuration matricule malformée et retombe sur le format par défaut", () => {
    expect(service.format({ code: "LBD", configJson: { matricule: "n'importe quoi" } as never }, "2026", 3)).toBe(
      "LBD/2026/0003",
    );
  });
});

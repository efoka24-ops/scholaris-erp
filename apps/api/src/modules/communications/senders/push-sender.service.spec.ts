import { PushSenderService } from "./push-sender.service";

describe("PushSenderService (stub FCM)", () => {
  it("ne fait aucun appel réseau et retourne un résultat vide (providerMessageId non défini)", async () => {
    const service = new PushSenderService();

    const result = await service.send({ to: "user-1", body: "Résultats publiés" });

    expect(result).toEqual({});
  });
});

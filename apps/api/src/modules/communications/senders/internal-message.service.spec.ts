import { InternalMessageService } from "./internal-message.service";

describe("InternalMessageService (canal INTERNAL du moteur de templates)", () => {
  it("ne fait aucun appel externe et retourne un succès vide", async () => {
    const service = new InternalMessageService();

    const result = await service.send({ to: "user-1", body: "Notification interne" });

    expect(result).toEqual({});
  });
});

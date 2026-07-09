import { ConfigService } from "@nestjs/config";
import axios from "axios";
import { WhatsappSenderService } from "./whatsapp-sender.service";

jest.mock("axios");
const mockedAxios = axios as jest.Mocked<typeof axios>;

describe("WhatsappSenderService", () => {
  let service: WhatsappSenderService;
  let config: { get: jest.Mock; getOrThrow: jest.Mock };

  beforeEach(() => {
    jest.clearAllMocks();
    config = {
      get: jest.fn((_key: string, fallback?: unknown) => fallback),
      getOrThrow: jest.fn((key: string) => (key === "WHATSAPP_PHONE_NUMBER_ID" ? "123456" : "wa-token")),
    };
    service = new WhatsappSenderService(config as unknown as ConfigService);
  });

  it("appelle l'API Meta Cloud avec l'endpoint, les headers et le corps attendus", async () => {
    mockedAxios.post.mockResolvedValue({ data: { messages: [{ id: "wamid.1" }] } });

    const result = await service.send({ to: "+237600000000", body: "Bulletin disponible" });

    expect(mockedAxios.post).toHaveBeenCalledWith(
      "https://graph.facebook.com/v20.0/123456/messages",
      {
        messaging_product: "whatsapp",
        to: "+237600000000",
        type: "text",
        text: { body: "Bulletin disponible" },
      },
      expect.objectContaining({
        headers: expect.objectContaining({ Authorization: "Bearer wa-token" }),
      }),
    );
    expect(result).toEqual({ providerMessageId: "wamid.1" });
  });

  it("propage l'erreur si l'appel Meta échoue", async () => {
    mockedAxios.post.mockRejectedValue(new Error("Meta indisponible"));

    await expect(service.send({ to: "+237600000000", body: "x" })).rejects.toThrow("Meta indisponible");
  });
});

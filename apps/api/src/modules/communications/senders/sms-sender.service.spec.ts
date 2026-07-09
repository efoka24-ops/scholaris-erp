import { ConfigService } from "@nestjs/config";
import axios from "axios";
import { SmsSenderService } from "./sms-sender.service";

jest.mock("axios");
const mockedAxios = axios as jest.Mocked<typeof axios>;

describe("SmsSenderService", () => {
  let service: SmsSenderService;
  let config: { get: jest.Mock; getOrThrow: jest.Mock };

  beforeEach(() => {
    jest.clearAllMocks();
    config = {
      get: jest.fn((_key: string, fallback?: unknown) => fallback),
      getOrThrow: jest.fn((key: string) => (key === "AFRICASTALKING_USERNAME" ? "sandbox" : "at-api-key")),
    };
    service = new SmsSenderService(config as unknown as ConfigService);
  });

  it("appelle l'API Africa's Talking avec l'endpoint, les headers et le corps attendus", async () => {
    mockedAxios.post.mockResolvedValue({
      data: { SMSMessageData: { Recipients: [{ messageId: "at-msg-1" }] } },
    });

    const result = await service.send({ to: "+237600000000", body: "Solde impayé" });

    expect(mockedAxios.post).toHaveBeenCalledWith(
      "https://api.africastalking.com/version1/messaging",
      expect.stringContaining("to=%2B237600000000"),
      expect.objectContaining({
        headers: expect.objectContaining({
          apiKey: "at-api-key",
          "Content-Type": "application/x-www-form-urlencoded",
        }),
      }),
    );
    expect(result).toEqual({ providerMessageId: "at-msg-1" });
  });

  it("propage l'erreur si l'appel Africa's Talking échoue", async () => {
    mockedAxios.post.mockRejectedValue(new Error("AT indisponible"));

    await expect(service.send({ to: "+237600000000", body: "x" })).rejects.toThrow("AT indisponible");
  });
});

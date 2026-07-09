import { ConfigService } from "@nestjs/config";
import axios from "axios";
import { EmailSenderService } from "./email-sender.service";

jest.mock("axios");
const mockedAxios = axios as jest.Mocked<typeof axios>;

describe("EmailSenderService", () => {
  let service: EmailSenderService;
  let config: { get: jest.Mock; getOrThrow: jest.Mock };

  beforeEach(() => {
    jest.clearAllMocks();
    config = {
      get: jest.fn((_key: string, fallback?: unknown) => fallback),
      getOrThrow: jest.fn(() => "brevo-api-key"),
    };
    service = new EmailSenderService(config as unknown as ConfigService);
  });

  it("appelle l'API Brevo avec l'endpoint, les headers et le corps attendus", async () => {
    mockedAxios.post.mockResolvedValue({ data: { messageId: "brevo-msg-1" } });

    const result = await service.send({ to: "parent@example.com", subject: "Convocation", body: "Bonjour" });

    expect(mockedAxios.post).toHaveBeenCalledWith(
      "https://api.brevo.com/v3/smtp/email",
      expect.objectContaining({
        to: [{ email: "parent@example.com" }],
        subject: "Convocation",
        htmlContent: expect.stringContaining("Bonjour"),
      }),
      expect.objectContaining({
        headers: expect.objectContaining({
          "api-key": "brevo-api-key",
          "Content-Type": "application/json",
        }),
      }),
    );
    expect(result).toEqual({ providerMessageId: "brevo-msg-1" });
  });

  it("propage l'erreur si l'appel Brevo échoue (pour permettre le repli de canal)", async () => {
    mockedAxios.post.mockRejectedValue(new Error("Brevo indisponible"));

    await expect(service.send({ to: "parent@example.com", body: "Bonjour" })).rejects.toThrow("Brevo indisponible");
  });
});

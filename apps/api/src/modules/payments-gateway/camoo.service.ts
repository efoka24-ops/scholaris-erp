import {
  BadGatewayException,
  HttpException,
  Injectable,
  Logger,
  ServiceUnavailableException,
} from "@nestjs/common";
import { ConfigService } from "@nestjs/config";
import { Prisma } from "@scholaris/prisma";
import axios, { AxiosError, AxiosInstance } from "axios";
import { PrismaService } from "../../prisma/prisma.service";
import { AuditService } from "../audit/audit.service";
import { CreateCashoutDto } from "./dto/create-cashout.dto";

/**
 * Intégration de la passerelle de paiement CAMOO Payment API (OpenAPI 1.0.0).
 *
 * Variables d'environnement attendues (lues via ConfigService, JAMAIS en dur) :
 *   - CAMOO_API_KEY    : identifiant marchand, envoyé en en-tête `X-Api-Key`.
 *   - CAMOO_API_SECRET : secret marchand, envoyé en en-tête `X-Api-Secret`
 *                        et utilisé pour vérifier la signature HMAC des webhooks.
 *   - CAMOO_BASE_URL   : URL de base de l'API (ex prod https://api.camoo.cm/v1/payment).
 *
 * Si la configuration est absente, les méthodes lèvent une
 * ServiceUnavailableException claire plutôt que de faire planter le processus.
 */
@Injectable()
export class CamooService {
  private readonly logger = new Logger(CamooService.name);

  constructor(
    private readonly config: ConfigService,
    private readonly prisma: PrismaService,
    private readonly audit: AuditService,
  ) {}

  /** Secret marchand — exposé au WebhookService pour la vérification HMAC. */
  getApiSecret(): string | null {
    return this.config.get<string>("CAMOO_API_SECRET") ?? null;
  }

  /** Construit un client axios configuré, ou null si la config CAMOO est absente. */
  private buildClient(): AxiosInstance | null {
    const apiKey = this.config.get<string>("CAMOO_API_KEY");
    const apiSecret = this.config.get<string>("CAMOO_API_SECRET");
    const baseURL = this.config.get<string>("CAMOO_BASE_URL");
    if (!apiKey || !apiSecret || !baseURL) {
      return null;
    }
    return axios.create({
      baseURL,
      timeout: 30_000,
      headers: {
        "X-Api-Key": apiKey,
        "X-Api-Secret": apiSecret,
        "Content-Type": "application/json",
        Accept: "application/json",
      },
    });
  }

  private requireClient(): AxiosInstance {
    const client = this.buildClient();
    if (!client) {
      throw new ServiceUnavailableException(
        "Passerelle de paiement CAMOO non configurée (CAMOO_API_KEY / CAMOO_API_SECRET / CAMOO_BASE_URL manquants)",
      );
    }
    return client;
  }

  /** Convertit une erreur axios en HttpException propre reprenant le status/message CAMOO. */
  private toHttpException(error: unknown, context: string): HttpException {
    const axiosError = error as AxiosError<{ message?: string; code?: string }>;
    if (axiosError.isAxiosError) {
      const status = axiosError.response?.status;
      const data = axiosError.response?.data;
      const message = data?.message ?? axiosError.message ?? "Erreur passerelle CAMOO";
      this.logger.error(`CAMOO ${context} — status=${status ?? "n/a"} : ${message}`);
      if (status) {
        return new HttpException({ message, code: data?.code, source: "camoo" }, status);
      }
      return new BadGatewayException(`Passerelle CAMOO injoignable : ${message}`);
    }
    this.logger.error(`CAMOO ${context} — erreur inattendue : ${(error as Error).message}`);
    return new BadGatewayException("Erreur inattendue de la passerelle CAMOO");
  }

  /**
   * Initie un décaissement (POST /cashout), persiste une PaymentTransaction avec
   * le résultat CAMOO et journalise l'opération.
   */
  async cashout(dto: CreateCashoutDto, tenantId: string, userId: string) {
    const client = this.requireClient();

    const payload: Record<string, unknown> = {
      amount: dto.amount,
      phone_number: dto.phoneNumber,
      currency: dto.currency ?? "XAF",
    };
    if (dto.notificationUrl) payload.notification_url = dto.notificationUrl;
    if (dto.externalReference) payload.external_reference = dto.externalReference;
    if (dto.shoppingCartDetails) payload.shopping_cart_details = dto.shoppingCartDetails;

    let response;
    try {
      response = await client.post("/cashout", payload);
    } catch (error) {
      throw this.toHttpException(error, "cashout");
    }

    const body = response.data ?? {};
    const cashOut = body.cashOut ?? {};

    const transaction = await this.prisma.paymentTransaction.create({
      data: {
        tenantId,
        gatewayId: cashOut.id ? String(cashOut.id) : null,
        externalReference: dto.externalReference ?? null,
        amount: new Prisma.Decimal(cashOut.amount ?? dto.amount),
        currency: cashOut.currency ?? dto.currency ?? "XAF",
        phoneNumber: dto.phoneNumber,
        network: cashOut.network ?? null,
        status: cashOut.status ? String(cashOut.status).toUpperCase() : "PENDING",
        studentId: dto.studentId ?? null,
        invoiceId: dto.invoiceId ?? null,
        rawResponse: body as Prisma.InputJsonValue,
      },
    });

    await this.audit.log({
      action: "cashout",
      resource: "payments",
      resourceId: transaction.id,
      newValue: {
        gatewayId: transaction.gatewayId,
        amount: dto.amount,
        currency: transaction.currency,
        phoneNumber: dto.phoneNumber,
        status: transaction.status,
      },
    });

    return { message: body.message ?? "Décaissement initié", transaction, cashOut };
  }

  /**
   * Vérifie une transaction (GET /verify?id=) et met à jour la PaymentTransaction
   * correspondante (recherchée par gatewayId).
   */
  async verify(id: string) {
    const client = this.requireClient();

    let response;
    try {
      response = await client.get("/verify", { params: { id } });
    } catch (error) {
      throw this.toHttpException(error, "verify");
    }

    const body = response.data ?? {};
    const verify = body.verify ?? {};

    const existing = await this.prisma.paymentTransaction.findFirst({
      where: { gatewayId: id },
    });

    if (existing) {
      await this.prisma.paymentTransaction.update({
        where: { id: existing.id },
        data: {
          status: verify.status ? String(verify.status).toUpperCase() : existing.status,
          network: verify.network ?? existing.network,
          fees: verify.fees != null ? new Prisma.Decimal(verify.fees) : existing.fees,
          netAmount:
            verify.net_amount != null ? new Prisma.Decimal(verify.net_amount) : existing.netAmount,
          externalReference: verify.external_reference ?? existing.externalReference,
          rawResponse: body as Prisma.InputJsonValue,
        },
      });
    }

    return body;
  }

  /** Solde du compte marchand (GET /account). */
  async account() {
    const client = this.requireClient();
    try {
      const response = await client.get("/account");
      return response.data;
    } catch (error) {
      throw this.toHttpException(error, "account");
    }
  }

  /**
   * Recherche une transaction par identifiant CAMOO (gatewayId) puis, en repli,
   * par external_reference. Utilisé par le traitement des webhooks.
   */
  async findByGatewayIdOrReference(gatewayId?: string, externalReference?: string) {
    if (gatewayId) {
      const byId = await this.prisma.paymentTransaction.findFirst({ where: { gatewayId } });
      if (byId) return byId;
    }
    if (externalReference) {
      return this.prisma.paymentTransaction.findFirst({ where: { externalReference } });
    }
    return null;
  }
}

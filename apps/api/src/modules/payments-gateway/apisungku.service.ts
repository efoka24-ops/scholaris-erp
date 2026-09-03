import { BadGatewayException, BadRequestException, Injectable, Logger, ServiceUnavailableException } from "@nestjs/common";
import { ConfigService } from "@nestjs/config";
import { Prisma } from "@scholaris/prisma";
import axios, { AxiosInstance } from "axios";
import crypto from "crypto";

import { PrismaService } from "../../prisma/prisma.service";
import { AuditService } from "../audit/audit.service";
import { CreateCashoutDto } from "./dto/create-cashout.dto";

/**
 * Passerelle apisungku : un seul service parle aux operateurs mobile money.
 * Scholaris n'y voit qu'une API HTTP et ne detient aucun secret d'operateur.
 *
 * Meme surface publique que CamooService (cashout / verify / account), pour
 * que le choix du fournisseur reste une variable de configuration.
 */
@Injectable()
export class ApisungkuService {
  private readonly logger = new Logger(ApisungkuService.name);
  private client: AxiosInstance | null = null;

  constructor(
    private readonly config: ConfigService,
    private readonly prisma: PrismaService,
    private readonly audit: AuditService,
  ) {}

  isConfigured(): boolean {
    return Boolean(this.config.get<string>("APISUNGKU_API_KEY"));
  }

  private requireClient(): AxiosInstance {
    if (this.client) return this.client;

    const apiKey = this.config.get<string>("APISUNGKU_API_KEY");
    if (!apiKey) {
      throw new ServiceUnavailableException(
        "Passerelle apisungku non configuree : definissez APISUNGKU_API_KEY.",
      );
    }

    this.client = axios.create({
      baseURL: (
        this.config.get<string>("APISUNGKU_BASE_URL") ?? "https://apisungku.trugroup.cm/v1"
      ).replace(/\/+$/, ""),
      timeout: Number(this.config.get("APISUNGKU_TIMEOUT_MS") ?? 25000),
      headers: { "X-Api-Key": apiKey, "Content-Type": "application/json" },
    });

    return this.client;
  }

  /**
   * Une reponse d'erreur explicite prouve l'echec ; une absence de reponse ne
   * prouve rien. Les deux cas sont distingues pour que l'appelant ne conclue
   * jamais a tort qu'aucun paiement n'a eu lieu.
   */
  private toHttpException(error: unknown, context: string) {
    if (axios.isAxiosError(error)) {
      if (!error.response) {
        this.logger.error(`apisungku ${context} — aucune reponse : ${error.message}`);
        return new ServiceUnavailableException(
          "Paiement en cours de verification : la passerelle n'a pas repondu.",
        );
      }
      const body = error.response.data as { error?: { code?: string; message?: string } };
      const message = body?.error?.message ?? `Erreur ${error.response.status}`;
      this.logger.warn(`apisungku ${context} — refus : ${message}`);
      return error.response.status >= 500
        ? new BadGatewayException(message)
        : new BadRequestException(message);
    }
    this.logger.error(`apisungku ${context} — erreur inattendue : ${(error as Error).message}`);
    return new BadGatewayException("Erreur inattendue de la passerelle apisungku");
  }

  /** Le XAF n'a pas de sous-unite : les operateurs refusent les decimales. */
  private toAmount(amount: number): string {
    return String(Math.round(amount));
  }

  /** Format MSISDN attendu : 237XXXXXXXXX, sans + ni espaces. */
  private toMsisdn(phone: string): string {
    const digits = String(phone).replace(/\D/g, "");
    return digits.startsWith("237") ? digits : `237${digits.replace(/^0+/, "")}`;
  }

  /**
   * Initie un encaissement et persiste la PaymentTransaction.
   *
   * Le statut renvoye n'est pas final : l'eleve ou le parent doit encore
   * autoriser le paiement sur son telephone. Le denouement arrive par webhook.
   */
  async cashout(dto: CreateCashoutDto, tenantId: string, _userId: string) {
    const client = this.requireClient();

    // La reference est notre cle d'idempotence et de rapprochement : la
    // reutiliser renvoie la transaction existante au lieu d'en creer une autre.
    const reference =
      dto.externalReference ?? `SCHOLARIS-${tenantId.slice(-6)}-${Date.now()}`;

    let response;
    try {
      response = await client.post("/deposits", {
        amount: this.toAmount(dto.amount),
        currency: dto.currency ?? "XAF",
        phoneNumber: this.toMsisdn(dto.phoneNumber),
        reference,
        customerMessage: "Frais scolaires".slice(0, 22),
        metadata: {
          tenantId,
          ...(dto.studentId ? { studentId: dto.studentId } : {}),
          ...(dto.invoiceId ? { invoiceId: dto.invoiceId } : {}),
        },
      });
    } catch (error) {
      throw this.toHttpException(error, "cashout");
    }

    const body = response.data ?? {};

    const transaction = await this.prisma.paymentTransaction.create({
      data: {
        tenantId,
        gatewayId: body.id ? String(body.id) : null,
        externalReference: reference,
        amount: new Prisma.Decimal(body.amount ?? dto.amount),
        currency: body.currency ?? dto.currency ?? "XAF",
        phoneNumber: dto.phoneNumber,
        network: body.provider ?? null,
        status: body.status ? String(body.status).toUpperCase() : "PENDING",
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

    return {
      message: "Paiement initie. Validez sur votre telephone.",
      transaction,
    };
  }

  /** Verifie le statut reel et synchronise la PaymentTransaction. */
  async verify(id: string) {
    const client = this.requireClient();

    let response;
    try {
      response = await client.get(`/transactions/${encodeURIComponent(id)}`);
    } catch (error) {
      throw this.toHttpException(error, "verify");
    }

    const body = response.data ?? {};
    const existing = await this.prisma.paymentTransaction.findFirst({
      where: { OR: [{ gatewayId: String(body.id ?? id) }, { externalReference: body.reference ?? "" }] },
    });

    if (!existing) return { transaction: null, gateway: body };

    const transaction = await this.prisma.paymentTransaction.update({
      where: { id: existing.id },
      data: {
        status: body.status ? String(body.status).toUpperCase() : existing.status,
        network: body.provider ?? existing.network,
        rawResponse: body as Prisma.InputJsonValue,
      },
    });

    return { transaction, gateway: body };
  }

  /** Soldes du compte marchand, par pays et devise. */
  async account() {
    const client = this.requireClient();
    try {
      return (await client.get("/toolkit/balances")).data;
    } catch (error) {
      throw this.toHttpException(error, "account");
    }
  }

  /** Operateurs actifs, pour construire dynamiquement le selecteur de paiement. */
  async providers(country = "CMR") {
    const client = this.requireClient();
    try {
      return (
        await client.get("/toolkit/providers", {
          params: { country, operationType: "DEPOSIT" },
        })
      ).data;
    } catch (error) {
      throw this.toHttpException(error, "providers");
    }
  }

  /**
   * Verifie l'authenticite d'un webhook, sur le corps BRUT.
   *
   * Sans cette verification, quiconque connait l'URL peut declarer une
   * facture scolaire payee.
   */
  verifyWebhook(rawBody: string, headers: Record<string, string | string[] | undefined>): boolean {
    const secret = this.config.get<string>("APISUNGKU_WEBHOOK_SECRET");
    if (!secret) {
      this.logger.error("APISUNGKU_WEBHOOK_SECRET absent — webhook refuse");
      return false;
    }

    const signature = headers["x-apisungku-signature"];
    const timestamp = headers["x-apisungku-timestamp"];
    if (typeof signature !== "string" || typeof timestamp !== "string") return false;

    // Fenetre de 5 minutes : sans elle, une signature capturee reste rejouable.
    const age = Math.abs(Date.now() / 1000 - Number(timestamp));
    if (!Number.isFinite(age) || age > 300) return false;

    const expected =
      "sha256=" + crypto.createHmac("sha256", secret).update(`${timestamp}.${rawBody}`).digest("hex");

    const a = Buffer.from(expected);
    const b = Buffer.from(signature);
    return a.length === b.length && crypto.timingSafeEqual(a, b);
  }

  /**
   * Applique le statut final recu par webhook.
   *
   * Seuls COMPLETED et FAILED sont conclusifs. NEEDS_ATTENTION signale une
   * issue indeterminee : la transaction est laissee en l'etat pour examen
   * humain, jamais resolue automatiquement.
   */
  async applyWebhook(data: {
    id?: string;
    reference?: string;
    status?: string;
    type?: string;
  }) {
    const status = String(data.status ?? "").toUpperCase();

    const existing = await this.prisma.paymentTransaction.findFirst({
      where: {
        OR: [
          ...(data.id ? [{ gatewayId: data.id }] : []),
          ...(data.reference ? [{ externalReference: data.reference }] : []),
        ],
      },
    });

    if (!existing) return { received: true, matched: false };

    if (status === "NEEDS_ATTENTION") {
      this.logger.error(
        `apisungku ${data.id} en NEEDS_ATTENTION (transaction ${existing.id}) — verification manuelle requise`,
      );
      return { received: true, matched: true, escalated: true };
    }

    // On n'avance que depuis un statut non final : un webhook rejoue ou arrive
    // dans le desordre ne peut pas revenir sur une decision deja prise.
    if (existing.status === "COMPLETED" || existing.status === "FAILED") {
      return { received: true, matched: true, ignored: true };
    }

    if (status === "COMPLETED" || status === "FAILED") {
      await this.prisma.paymentTransaction.update({
        where: { id: existing.id },
        data: { status, rawResponse: data as Prisma.InputJsonValue },
      });
    }

    return { received: true, matched: true };
  }
}

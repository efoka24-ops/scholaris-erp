import { Injectable, Logger, ServiceUnavailableException, UnauthorizedException } from "@nestjs/common";
import { Prisma } from "@scholaris/prisma";
import * as crypto from "crypto";
import { PrismaService } from "../../prisma/prisma.service";
import { CamooService } from "./camoo.service";

/**
 * Traitement des notifications (webhooks) CAMOO.
 *
 * CAMOO envoie un HTTP GET vers le `notification_url` du marchand. La signature
 * HMAC-SHA256 est fournie dans le query param `sig`. Le marchand DOIT vérifier
 * la signature avant de faire confiance aux données ; l'endpoint doit être
 * idempotent (CAMOO réessaie jusqu'à recevoir HTTP 200) ; les valeurs de
 * `status` sont traitées de façon insensible à la casse.
 *
 * Représentation canonique signée (voir README/rapport) :
 *   - on exclut le paramètre `sig` lui-même ;
 *   - on trie les clés restantes par ordre lexicographique croissant ;
 *   - on concatène `key=value` séparés par `&` (aucun encodage supplémentaire) ;
 *   - HMAC-SHA256(clé = CAMOO_API_SECRET) → hexadécimal minuscule.
 */
@Injectable()
export class WebhookService {
  private readonly logger = new Logger(WebhookService.name);

  constructor(
    private readonly camoo: CamooService,
    private readonly prisma: PrismaService,
  ) {}

  /** Représentation canonique : clés triées, `sig` exclu, `key=value` joints par `&`. */
  buildCanonicalString(query: Record<string, unknown>): string {
    return Object.keys(query)
      .filter((key) => key !== "sig")
      .sort()
      .map((key) => `${key}=${query[key] ?? ""}`)
      .join("&");
  }

  /** Vérifie la signature HMAC-SHA256 en comparaison timing-safe. */
  verifySignature(query: Record<string, unknown>, sig?: string): boolean {
    const secret = this.camoo.getApiSecret();
    if (!secret) {
      throw new ServiceUnavailableException("Passerelle CAMOO non configurée (CAMOO_API_SECRET manquant)");
    }
    if (!sig || typeof sig !== "string") {
      return false;
    }
    const canonical = this.buildCanonicalString(query);
    const expected = crypto.createHmac("sha256", secret).update(canonical).digest("hex");

    const expectedBuf = Buffer.from(expected, "utf8");
    const providedBuf = Buffer.from(sig.toLowerCase(), "utf8");
    if (expectedBuf.length !== providedBuf.length) {
      return false;
    }
    return crypto.timingSafeEqual(expectedBuf, providedBuf);
  }

  /**
   * Traite une notification : vérifie la signature, retrouve la transaction
   * (par gatewayId puis external_reference), met à jour son statut de façon
   * idempotente. Lève UnauthorizedException si la signature est invalide.
   */
  async handleNotification(query: Record<string, unknown>): Promise<{ received: true }> {
    const sig = typeof query.sig === "string" ? query.sig : undefined;
    if (!this.verifySignature(query, sig)) {
      this.logger.warn("Notification CAMOO rejetée : signature invalide");
      throw new UnauthorizedException("Signature de notification invalide");
    }

    const gatewayId = this.firstString(query.id, query.cashout_id, query.transaction_id);
    const externalReference = this.firstString(query.external_reference);
    const status = this.firstString(query.status);

    const transaction = await this.camoo.findByGatewayIdOrReference(gatewayId, externalReference);
    if (!transaction) {
      // Idempotence : on accepte (200) même si la transaction est inconnue, pour
      // éviter des retries infinis de CAMOO sur une référence qui ne nous concerne pas.
      this.logger.warn(
        `Notification CAMOO sans transaction correspondante (id=${gatewayId ?? "?"}, ref=${externalReference ?? "?"})`,
      );
      return { received: true };
    }

    const normalizedStatus = status ? status.toUpperCase() : transaction.status;

    // Idempotence : si le statut est déjà à jour ET la notification déjà enregistrée,
    // on ne réécrit rien.
    if (normalizedStatus === transaction.status && transaction.notifiedAt) {
      return { received: true };
    }

    await this.prisma.paymentTransaction.update({
      where: { id: transaction.id },
      data: {
        status: normalizedStatus,
        notifiedAt: new Date(),
        gatewayId: transaction.gatewayId ?? gatewayId ?? null,
        rawResponse: query as Prisma.InputJsonValue,
      },
    });

    this.logger.log(`Notification CAMOO traitée : transaction=${transaction.id} statut=${normalizedStatus}`);
    return { received: true };
  }

  private firstString(...values: unknown[]): string | undefined {
    for (const value of values) {
      if (typeof value === "string" && value.length > 0) return value;
    }
    return undefined;
  }
}

import { Injectable } from "@nestjs/common";
import { Prisma, Tenant } from "@scholaris/prisma";

/**
 * Format par défaut : {code}/{year}/{seq} → ex "LBD/2026/0001".
 * Configurable par établissement via Tenant.config_json :
 *   { "matricule": { "format": "{code}-{year}-{seq}", "padding": 5 } }
 */
const DEFAULT_FORMAT = "{code}/{year}/{seq}";
const DEFAULT_PADDING = 4;

interface MatriculeConfig {
  format?: string;
  padding?: number;
}

@Injectable()
export class MatriculeService {
  /**
   * Réserve le prochain numéro de la séquence tenant+année et rend le matricule
   * formaté. À appeler DANS la transaction qui crée l'élève : l'upsert avec
   * `lastNumber: { increment: 1 }` est atomique côté base, deux créations
   * concurrentes ne peuvent donc jamais obtenir le même numéro.
   */
  async generate(
    tx: Pick<Prisma.TransactionClient, "matriculeSequence">,
    tenant: Pick<Tenant, "id" | "code" | "configJson">,
    year: string = String(new Date().getFullYear()),
  ): Promise<string> {
    const sequence = await tx.matriculeSequence.upsert({
      where: { tenantId_year: { tenantId: tenant.id, year } },
      update: { lastNumber: { increment: 1 } },
      create: { tenantId: tenant.id, year, lastNumber: 1 },
    });

    return this.format(tenant, year, sequence.lastNumber);
  }

  format(tenant: Pick<Tenant, "code" | "configJson">, year: string, sequenceNumber: number): string {
    const config = this.readConfig(tenant.configJson);
    const format = config.format ?? DEFAULT_FORMAT;
    const padding = config.padding ?? DEFAULT_PADDING;

    return format
      .replace("{code}", tenant.code)
      .replace("{year}", year)
      .replace("{seq}", String(sequenceNumber).padStart(padding, "0"));
  }

  private readConfig(configJson: Prisma.JsonValue | null): MatriculeConfig {
    if (!configJson || typeof configJson !== "object" || Array.isArray(configJson)) {
      return {};
    }
    const matricule = (configJson as Record<string, unknown>).matricule;
    if (!matricule || typeof matricule !== "object" || Array.isArray(matricule)) {
      return {};
    }
    const { format, padding } = matricule as Record<string, unknown>;
    return {
      format: typeof format === "string" ? format : undefined,
      padding: typeof padding === "number" ? padding : undefined,
    };
  }
}

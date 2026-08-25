import { randomBytes, createHash } from "crypto";
import { PrismaService } from "../../prisma/prisma.service";

/** Durée de vie par défaut d'un lien d'activation (Chantier 1) : 72h. */
export const ACTIVATION_TOKEN_TTL_HOURS = 72;

function hashToken(token: string): string {
  return createHash("sha256").update(token).digest("hex");
}

/**
 * Génère un token d'activation à durée limitée pour `userId`, stocke son hash
 * en base (jamais le token en clair) et retourne le token en clair à insérer
 * dans le lien envoyé par email (`/activate?token=...`).
 */
export async function createActivationToken(
  prisma: PrismaService,
  userId: string,
  ttlHours: number = ACTIVATION_TOKEN_TTL_HOURS,
): Promise<string> {
  const token = randomBytes(32).toString("base64url");
  const expiresAt = new Date(Date.now() + ttlHours * 60 * 60 * 1000);
  await prisma.accountActivationToken.create({
    data: { userId, tokenHash: hashToken(token), expiresAt },
  });
  return token;
}

export { hashToken };

import { BadRequestException, Injectable, UnauthorizedException } from "@nestjs/common";
import { ConfigService } from "@nestjs/config";
import { JwtService } from "@nestjs/jwt";
import * as bcrypt from "bcrypt";
import { createHash } from "crypto";
import { generateSecret, generateURI, verify } from "otplib";
import { PrismaService } from "../../prisma/prisma.service";
import { AuditService } from "../audit/audit.service";
import { AuthenticatedUser, JwtAccessPayload, JwtRefreshPayload } from "./jwt-payload.interface";

export interface TokenPair {
  accessToken: string;
  refreshToken: string;
  expiresIn: string;
}

/** Chantier 1 : réponse de connexion lorsque le mot de passe doit être changé
 * avant tout accès complet — un accessToken restreint (sans permissions,
 * courte durée) est émis, valable uniquement pour POST /auth/change-password. */
export interface PasswordChangeRequired {
  requiresPasswordChange: true;
  accessToken: string;
  expiresIn: string;
}

export interface MfaEnrollment {
  secret: string;
  otpauthUrl: string;
}

const MFA_ISSUER = "SCHOLARIS ERP";

// Chantier 2 : verrouillage de compte après échecs de connexion successifs.
const MAX_FAILED_ATTEMPTS = 5;
const LOCK_DURATION_MS = 15 * 60 * 1000;

// Chantier 3 : rôles à privilèges pour lesquels le MFA est requis (setup forcé,
// sans bloquer la connexion — cf. login()).
const PRIVILEGED_ROLE_NAMES = ["SUPER_ADMIN", "Directeur", "Admin Établissement"];

@Injectable()
export class AuthService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly jwt: JwtService,
    private readonly config: ConfigService,
    private readonly audit: AuditService,
  ) {}

  /**
   * Recherche par email SEUL (pas de tenantId dans le contexte à ce stade : c'est
   * justement le login qui va le révéler). @@unique([tenantId, email]) empêche un
   * doublon dans un même établissement ; deux tenants distincts pourraient en théorie
   * partager un email — cas non traité en Phase 0 (findFirst retourne le premier trouvé).
   */
  async validateUser(email: string, password: string) {
    const user = await this.prisma.user.findFirst({ where: { email }, include: { tenant: true } });
    if (!user) {
      throw new UnauthorizedException("Identifiants invalides");
    }

    // Chantier 2 : compte verrouillé après trop d'échecs successifs.
    if (user.lockedUntil && user.lockedUntil.getTime() > Date.now()) {
      const minutesLeft = Math.ceil((user.lockedUntil.getTime() - Date.now()) / 60000);
      throw new UnauthorizedException(
        `Compte temporairement verrouillé suite à plusieurs échecs de connexion. Réessayez dans ${minutesLeft} min.`,
      );
    }

    // Chantier 9 : établissement suspendu par le Super Admin → authentification bloquée.
    if (!user.tenant.active) {
      throw new UnauthorizedException("Cet établissement est actuellement suspendu. Contactez le support SCHOLARIS.");
    }

    const passwordMatches = await bcrypt.compare(password, user.passwordHash);
    if (!passwordMatches) {
      await this.registerFailedAttempt(user.id, user.failedLoginAttempts);
      throw new UnauthorizedException("Identifiants invalides");
    }

    if (user.status !== "ACTIVE") {
      throw new UnauthorizedException("Compte désactivé");
    }

    if (user.failedLoginAttempts > 0 || user.lockedUntil) {
      await this.prisma.user.update({
        where: { id: user.id },
        data: { failedLoginAttempts: 0, lockedUntil: null },
      });
    }

    return user;
  }

  /** Chantier 2 : incrémente le compteur d'échecs et verrouille au-delà du seuil. */
  private async registerFailedAttempt(userId: string, currentAttempts: number): Promise<void> {
    const attempts = currentAttempts + 1;
    const data: { failedLoginAttempts: number; lockedUntil?: Date } = { failedLoginAttempts: attempts };
    if (attempts >= MAX_FAILED_ATTEMPTS) {
      data.lockedUntil = new Date(Date.now() + LOCK_DURATION_MS);
    }
    await this.prisma.user.update({ where: { id: userId }, data });
  }

  /**
   * Changement de mot de passe par l'utilisateur connecté : vérifie le mot de
   * passe actuel, impose une longueur minimale, puis remplace le hash bcrypt.
   */
  async changePassword(userId: string, currentPassword: string, newPassword: string): Promise<{ success: true }> {
    if (!newPassword || newPassword.length < 8) {
      throw new BadRequestException("Le nouveau mot de passe doit contenir au moins 8 caractères");
    }
    const user = await this.prisma.user.findFirst({ where: { id: userId } });
    if (!user) {
      throw new UnauthorizedException("Utilisateur introuvable");
    }
    const matches = await bcrypt.compare(currentPassword, user.passwordHash);
    if (!matches) {
      throw new BadRequestException("Le mot de passe actuel est incorrect");
    }
    if (await bcrypt.compare(newPassword, user.passwordHash)) {
      throw new BadRequestException("Le nouveau mot de passe doit être différent de l'ancien");
    }
    const passwordHash = await bcrypt.hash(newPassword, 10);
    await this.prisma.user.update({
      where: { id: user.id },
      // Chantier 1 : un changement de mot de passe réussi lève l'obligation.
      data: { passwordHash, mustChangePassword: false },
    });
    await this.audit.log({ action: "change-password", resource: "users", resourceId: user.id });
    return { success: true };
  }

  async login(email: string, password: string, mfaCode?: string): Promise<TokenPair | PasswordChangeRequired> {
    const user = await this.validateUser(email, password);

    // Chantier 1 : mot de passe temporaire non encore changé → token restreint,
    // aucun accès complet tant que POST /auth/change-password n'a pas été appelé.
    if (user.mustChangePassword) {
      await this.prisma.user.update({ where: { id: user.id }, data: { lastLogin: new Date() } });
      return this.issueRestrictedToken(user.id, user.tenantId, user.email);
    }

    // §1.2 : si le MFA est activé sur le compte, le code TOTP est exigé au login.
    if (user.mfaEnabled) {
      if (!mfaCode) {
        throw new UnauthorizedException({
          message: "Code MFA requis",
          mfaRequired: true,
          statusCode: 401,
          error: "Unauthorized",
        });
      }
      const valid = await this.isTotpValid(user.mfaSecret, mfaCode);
      if (!valid) {
        throw new UnauthorizedException("Code MFA invalide");
      }
    }

    const permissions = await this.resolvePermissions(user.id);

    await this.prisma.user.update({
      where: { id: user.id },
      data: { lastLogin: new Date() },
    });

    const tokens = await this.issueTokens({ id: user.id, tenantId: user.tenantId, email: user.email }, permissions);

    // Chantier 3 : MFA obligatoire pour les rôles à privilèges — ne bloque pas la
    // connexion mais signale au front qu'il doit rediriger vers l'enrôlement MFA.
    if (!user.mfaEnabled && (await this.hasPrivilegedRole(user.id))) {
      return { ...tokens, mfaSetupRequired: true } as TokenPair & { mfaSetupRequired: true };
    }

    return tokens;
  }

  /** Chantier 1 : token d'accès à validité courte, sans permission, réservé au flux de changement de mot de passe forcé. */
  private async issueRestrictedToken(userId: string, tenantId: string, email: string): Promise<PasswordChangeRequired> {
    const accessSecret = this.config.get<string>("JWT_ACCESS_SECRET") || "dev-jwt-access-secret-CHANGE-IN-PRODUCTION";
    const expiresIn = "15m";
    const accessPayload: JwtAccessPayload = { sub: userId, tenantId, email, permissions: [] };
    const accessToken = await this.jwt.signAsync(accessPayload, { secret: accessSecret, expiresIn });
    return { requiresPasswordChange: true, accessToken, expiresIn };
  }

  /** Chantier 3 : vérifie si l'utilisateur porte un rôle à privilèges (SUPER_ADMIN, Directeur, Admin Établissement). */
  private async hasPrivilegedRole(userId: string): Promise<boolean> {
    const count = await this.prisma.userRole.count({
      where: { userId, role: { name: { in: PRIVILEGED_ROLE_NAMES } } },
    });
    return count > 0;
  }

  /**
   * Chantier 1 : consomme un token d'activation (lien envoyé par email au
   * provisioning d'un compte) et retourne un token restreint pour forcer le
   * changement de mot de passe, comme un login sur un compte mustChangePassword.
   */
  async activateAccount(token: string): Promise<PasswordChangeRequired> {
    if (!token) {
      throw new BadRequestException("Token d'activation requis");
    }
    const tokenHash = createHash("sha256").update(token).digest("hex");
    const record = await this.prisma.accountActivationToken.findFirst({
      where: { tokenHash },
      include: { user: true },
      orderBy: { createdAt: "desc" },
    });
    if (!record) {
      throw new UnauthorizedException("Lien d'activation invalide");
    }
    if (record.usedAt) {
      throw new UnauthorizedException("Ce lien d'activation a déjà été utilisé");
    }
    if (record.expiresAt.getTime() < Date.now()) {
      throw new UnauthorizedException("Ce lien d'activation a expiré");
    }

    await this.prisma.$transaction([
      this.prisma.accountActivationToken.update({ where: { id: record.id }, data: { usedAt: new Date() } }),
      this.prisma.user.update({
        where: { id: record.userId },
        data: { status: "ACTIVE", mustChangePassword: true },
      }),
    ]);

    return this.issueRestrictedToken(record.userId, record.user.tenantId, record.user.email);
  }

  async refresh(refreshToken: string): Promise<TokenPair> {
    let payload: JwtRefreshPayload;
    try {
      const refreshSecret = this.config.get<string>("JWT_REFRESH_SECRET") || "dev-jwt-refresh-secret-CHANGE-IN-PRODUCTION";
      payload = await this.jwt.verifyAsync<JwtRefreshPayload>(refreshToken, {
        secret: refreshSecret,
      });
    } catch {
      throw new UnauthorizedException("Refresh token invalide ou expiré");
    }

    const user = await this.prisma.user.findFirst({
      where: { id: payload.sub, tenantId: payload.tenantId },
    });
    if (!user || user.status !== "ACTIVE") {
      throw new UnauthorizedException("Utilisateur introuvable ou désactivé");
    }

    const permissions = await this.resolvePermissions(user.id);
    return this.issueTokens({ id: user.id, tenantId: user.tenantId, email: user.email }, permissions);
  }

  async me(userId: string, tenantId: string): Promise<AuthenticatedUser> {
    const user = await this.prisma.user.findFirst({ where: { id: userId, tenantId } });
    if (!user) {
      throw new UnauthorizedException("Utilisateur introuvable");
    }
    const permissions = await this.resolvePermissions(user.id);
    return { userId: user.id, tenantId: user.tenantId, email: user.email, permissions };
  }

  /**
   * Étape 1 de l'enrôlement MFA : génère un secret TOTP et l'URL otpauth://
   * (à afficher en QR code côté client). Le MFA n'est PAS encore actif : il ne
   * le devient qu'après vérification d'un premier code valide (verifyMfa),
   * preuve que l'utilisateur a bien enregistré le secret dans son application.
   */
  async enableMfa(userId: string, tenantId: string): Promise<MfaEnrollment> {
    const user = await this.prisma.user.findFirst({ where: { id: userId, tenantId } });
    if (!user) {
      throw new UnauthorizedException("Utilisateur introuvable");
    }
    if (user.mfaEnabled) {
      throw new BadRequestException("Le MFA est déjà activé sur ce compte");
    }

    const secret = generateSecret();
    const otpauthUrl = generateURI({ issuer: MFA_ISSUER, label: user.email, secret });

    await this.prisma.user.update({
      where: { id: user.id },
      data: { mfaSecret: secret, mfaEnabled: false },
    });

    return { secret, otpauthUrl };
  }

  /**
   * Étape 2 de l'enrôlement MFA : vérifie le premier code TOTP et active
   * définitivement le MFA sur le compte.
   */
  async verifyMfa(userId: string, tenantId: string, code: string): Promise<{ mfaEnabled: boolean }> {
    const user = await this.prisma.user.findFirst({ where: { id: userId, tenantId } });
    if (!user) {
      throw new UnauthorizedException("Utilisateur introuvable");
    }
    if (!user.mfaSecret) {
      throw new BadRequestException("Aucun enrôlement MFA en cours : appelez d'abord /auth/mfa/enable");
    }

    const valid = await this.isTotpValid(user.mfaSecret, code);
    if (!valid) {
      throw new UnauthorizedException("Code MFA invalide");
    }

    if (!user.mfaEnabled) {
      await this.prisma.user.update({ where: { id: user.id }, data: { mfaEnabled: true } });
      await this.audit.log({
        action: "mfa-enable",
        resource: "users",
        resourceId: user.id,
        oldValue: { mfaEnabled: false },
        newValue: { mfaEnabled: true },
      });
    }

    return { mfaEnabled: true };
  }

  private async isTotpValid(secret: string | null, token: string): Promise<boolean> {
    if (!secret) {
      return false;
    }
    try {
      const result = await verify({ secret, token, epochTolerance: 30 });
      return result.valid;
    } catch {
      return false;
    }
  }

  private async resolvePermissions(userId: string): Promise<string[]> {
    const userRoles = await this.prisma.userRole.findMany({
      where: { userId },
      include: { role: { include: { rolePermissions: { include: { permission: true } } } } },
    });

    const codes = new Set<string>();
    for (const userRole of userRoles) {
      for (const rolePermission of userRole.role.rolePermissions) {
        codes.add(`${rolePermission.permission.resource}:${rolePermission.permission.action}`);
      }
    }
    return Array.from(codes);
  }

  private async issueTokens(
    user: { id: string; tenantId: string; email: string },
    permissions: string[],
  ): Promise<TokenPair> {
    const accessPayload: JwtAccessPayload = {
      sub: user.id,
      tenantId: user.tenantId,
      email: user.email,
      permissions,
    };
    const refreshPayload: JwtRefreshPayload = { sub: user.id, tenantId: user.tenantId };

    const accessExpiresIn = this.config.get<string>("JWT_ACCESS_EXPIRES_IN", "15m");

    const accessSecret = this.config.get<string>("JWT_ACCESS_SECRET") || "dev-jwt-access-secret-CHANGE-IN-PRODUCTION";
    const refreshSecret = this.config.get<string>("JWT_REFRESH_SECRET") || "dev-jwt-refresh-secret-CHANGE-IN-PRODUCTION";

    const [accessToken, refreshToken] = await Promise.all([
      this.jwt.signAsync(accessPayload, {
        secret: accessSecret,
        expiresIn: accessExpiresIn,
      }),
      this.jwt.signAsync(refreshPayload, {
        secret: refreshSecret,
        expiresIn: this.config.get<string>("JWT_REFRESH_EXPIRES_IN", "7d"),
      }),
    ]);

    return { accessToken, refreshToken, expiresIn: accessExpiresIn };
  }
}

import { BadRequestException, Injectable, UnauthorizedException } from "@nestjs/common";
import { ConfigService } from "@nestjs/config";
import { JwtService } from "@nestjs/jwt";
import * as bcrypt from "bcrypt";
import { generateSecret, generateURI, verify } from "otplib";
import { PrismaService } from "../../prisma/prisma.service";
import { AuditService } from "../audit/audit.service";
import { AuthenticatedUser, JwtAccessPayload, JwtRefreshPayload } from "./jwt-payload.interface";

export interface TokenPair {
  accessToken: string;
  refreshToken: string;
  expiresIn: string;
}

export interface MfaEnrollment {
  secret: string;
  otpauthUrl: string;
}

const MFA_ISSUER = "SCHOLARIS ERP";

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
    const user = await this.prisma.user.findFirst({ where: { email } });
    if (!user) {
      throw new UnauthorizedException("Identifiants invalides");
    }
    const passwordMatches = await bcrypt.compare(password, user.passwordHash);
    if (!passwordMatches) {
      throw new UnauthorizedException("Identifiants invalides");
    }
    if (user.status !== "ACTIVE") {
      throw new UnauthorizedException("Compte désactivé");
    }
    return user;
  }

  async login(email: string, password: string, mfaCode?: string): Promise<TokenPair> {
    const user = await this.validateUser(email, password);

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

    return this.issueTokens({ id: user.id, tenantId: user.tenantId, email: user.email }, permissions);
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

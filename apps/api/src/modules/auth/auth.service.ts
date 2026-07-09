import { Injectable, UnauthorizedException } from "@nestjs/common";
import { ConfigService } from "@nestjs/config";
import { JwtService } from "@nestjs/jwt";
import * as bcrypt from "bcrypt";
import { PrismaService } from "../../prisma/prisma.service";
import { AuthenticatedUser, JwtAccessPayload, JwtRefreshPayload } from "./jwt-payload.interface";

export interface TokenPair {
  accessToken: string;
  refreshToken: string;
  expiresIn: string;
}

@Injectable()
export class AuthService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly jwt: JwtService,
    private readonly config: ConfigService,
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

  async login(email: string, password: string): Promise<TokenPair> {
    const user = await this.validateUser(email, password);
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
      payload = await this.jwt.verifyAsync<JwtRefreshPayload>(refreshToken, {
        secret: this.config.getOrThrow<string>("JWT_REFRESH_SECRET"),
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

    const [accessToken, refreshToken] = await Promise.all([
      this.jwt.signAsync(accessPayload, {
        secret: this.config.getOrThrow<string>("JWT_ACCESS_SECRET"),
        expiresIn: accessExpiresIn,
      }),
      this.jwt.signAsync(refreshPayload, {
        secret: this.config.getOrThrow<string>("JWT_REFRESH_SECRET"),
        expiresIn: this.config.get<string>("JWT_REFRESH_EXPIRES_IN", "7d"),
      }),
    ]);

    return { accessToken, refreshToken, expiresIn: accessExpiresIn };
  }
}

import { UnauthorizedException } from "@nestjs/common";
import { ConfigService } from "@nestjs/config";
import { JwtService } from "@nestjs/jwt";
import * as bcrypt from "bcrypt";
import { PrismaService } from "../../prisma/prisma.service";
import { AuthService } from "./auth.service";

describe("AuthService", () => {
  let service: AuthService;
  let prisma: {
    user: { findFirst: jest.Mock; update: jest.Mock };
    userRole: { findMany: jest.Mock };
  };
  let jwt: { signAsync: jest.Mock; verifyAsync: jest.Mock };
  let config: { get: jest.Mock; getOrThrow: jest.Mock };

  const baseUser = {
    id: "user-1",
    tenantId: "tenant-1",
    email: "admin@scholaris.dev",
    status: "ACTIVE",
  };

  beforeEach(() => {
    prisma = {
      user: { findFirst: jest.fn(), update: jest.fn() },
      userRole: { findMany: jest.fn().mockResolvedValue([]) },
    };
    jwt = {
      signAsync: jest.fn().mockResolvedValue("signed.jwt.token"),
      verifyAsync: jest.fn(),
    };
    config = {
      get: jest.fn((key: string, fallback?: unknown) => fallback),
      getOrThrow: jest.fn((key: string) => `secret-for-${key}`),
    };

    service = new AuthService(prisma as unknown as PrismaService, jwt as unknown as JwtService, config as unknown as ConfigService);
  });

  describe("login", () => {
    it("retourne une paire de tokens pour des identifiants valides", async () => {
      const passwordHash = await bcrypt.hash("ChangeMe123!", 4);
      prisma.user.findFirst.mockResolvedValue({ ...baseUser, passwordHash });
      prisma.user.update.mockResolvedValue({});

      const result = await service.login("admin@scholaris.dev", "ChangeMe123!");

      expect(result.accessToken).toBe("signed.jwt.token");
      expect(result.refreshToken).toBe("signed.jwt.token");
      expect(jwt.signAsync).toHaveBeenCalledTimes(2);
      expect(prisma.user.update).toHaveBeenCalledWith({
        where: { id: "user-1" },
        data: { lastLogin: expect.any(Date) },
      });
    });

    it("rejette un mot de passe invalide", async () => {
      const passwordHash = await bcrypt.hash("ChangeMe123!", 4);
      prisma.user.findFirst.mockResolvedValue({ ...baseUser, passwordHash });

      await expect(service.login("admin@scholaris.dev", "wrong-password")).rejects.toThrow(UnauthorizedException);
    });

    it("rejette un email inconnu", async () => {
      prisma.user.findFirst.mockResolvedValue(null);

      await expect(service.login("inconnu@scholaris.dev", "whatever")).rejects.toThrow(UnauthorizedException);
    });

    it("rejette un compte désactivé", async () => {
      const passwordHash = await bcrypt.hash("ChangeMe123!", 4);
      prisma.user.findFirst.mockResolvedValue({ ...baseUser, status: "SUSPENDED", passwordHash });

      await expect(service.login("admin@scholaris.dev", "ChangeMe123!")).rejects.toThrow(UnauthorizedException);
    });
  });

  describe("refresh", () => {
    it("émet un nouvel access token pour un refresh token valide", async () => {
      jwt.verifyAsync.mockResolvedValue({ sub: "user-1", tenantId: "tenant-1" });
      prisma.user.findFirst.mockResolvedValue(baseUser);

      const result = await service.refresh("valid.refresh.token");

      expect(result.accessToken).toBe("signed.jwt.token");
    });

    it("rejette un refresh token invalide", async () => {
      jwt.verifyAsync.mockRejectedValue(new Error("expired"));

      await expect(service.refresh("bad.token")).rejects.toThrow(UnauthorizedException);
    });
  });
});

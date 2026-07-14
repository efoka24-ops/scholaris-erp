import { BadRequestException, UnauthorizedException } from "@nestjs/common";
import { ConfigService } from "@nestjs/config";
import { JwtService } from "@nestjs/jwt";
import * as bcrypt from "bcrypt";
import { PrismaService } from "../../prisma/prisma.service";
import { AuditService } from "../audit/audit.service";
import { AuthService } from "./auth.service";

jest.mock("otplib", () => ({
  generateSecret: jest.fn(() => "JBSWY3DPEHPK3PXP"),
  generateURI: jest.fn(
    ({ issuer, label }: { issuer: string; label: string }) =>
      `otpauth://totp/${issuer}:${label}?secret=JBSWY3DPEHPK3PXP`,
  ),
  verify: jest.fn(async ({ token }: { token: string }) => ({ valid: token === "123456" })),
}));

describe("AuthService", () => {
  let service: AuthService;
  let prisma: {
    user: { findFirst: jest.Mock; update: jest.Mock };
    userRole: { findMany: jest.Mock };
  };
  let jwt: { signAsync: jest.Mock; verifyAsync: jest.Mock };
  let config: { get: jest.Mock; getOrThrow: jest.Mock };
  let audit: { log: jest.Mock };

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

    audit = { log: jest.fn().mockResolvedValue(undefined) };
    service = new AuthService(
      prisma as unknown as PrismaService,
      jwt as unknown as JwtService,
      config as unknown as ConfigService,
      audit as unknown as AuditService,
    );
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

  describe("login avec MFA", () => {
    it("exige un code MFA quand le MFA est activé sur le compte", async () => {
      const passwordHash = await bcrypt.hash("ChangeMe123!", 4);
      prisma.user.findFirst.mockResolvedValue({ ...baseUser, passwordHash, mfaEnabled: true, mfaSecret: "SECRET" });

      await expect(service.login("admin@scholaris.dev", "ChangeMe123!")).rejects.toThrow("Code MFA requis");
    });

    it("rejette un code MFA invalide", async () => {
      const passwordHash = await bcrypt.hash("ChangeMe123!", 4);
      prisma.user.findFirst.mockResolvedValue({ ...baseUser, passwordHash, mfaEnabled: true, mfaSecret: "SECRET" });

      await expect(service.login("admin@scholaris.dev", "ChangeMe123!", "000000")).rejects.toThrow(
        UnauthorizedException,
      );
    });

    it("connecte l'utilisateur avec un code MFA valide", async () => {
      const passwordHash = await bcrypt.hash("ChangeMe123!", 4);
      prisma.user.findFirst.mockResolvedValue({ ...baseUser, passwordHash, mfaEnabled: true, mfaSecret: "SECRET" });
      prisma.user.update.mockResolvedValue({});

      const result = await service.login("admin@scholaris.dev", "ChangeMe123!", "123456");

      expect(result.accessToken).toBe("signed.jwt.token");
    });
  });

  describe("enableMfa / verifyMfa", () => {
    it("génère un secret et une URL otpauth sans activer le MFA", async () => {
      prisma.user.findFirst.mockResolvedValue({ ...baseUser, mfaEnabled: false });
      prisma.user.update.mockResolvedValue({});

      const result = await service.enableMfa("user-1", "tenant-1");

      expect(result.secret).toBe("JBSWY3DPEHPK3PXP");
      expect(result.otpauthUrl).toContain("otpauth://totp/");
      expect(prisma.user.update).toHaveBeenCalledWith({
        where: { id: "user-1" },
        data: { mfaSecret: "JBSWY3DPEHPK3PXP", mfaEnabled: false },
      });
    });

    it("refuse un enrôlement si le MFA est déjà activé", async () => {
      prisma.user.findFirst.mockResolvedValue({ ...baseUser, mfaEnabled: true });

      await expect(service.enableMfa("user-1", "tenant-1")).rejects.toThrow(BadRequestException);
    });

    it("active le MFA après vérification d'un premier code valide", async () => {
      prisma.user.findFirst.mockResolvedValue({ ...baseUser, mfaEnabled: false, mfaSecret: "JBSWY3DPEHPK3PXP" });
      prisma.user.update.mockResolvedValue({});

      const result = await service.verifyMfa("user-1", "tenant-1", "123456");

      expect(result.mfaEnabled).toBe(true);
      expect(prisma.user.update).toHaveBeenCalledWith({ where: { id: "user-1" }, data: { mfaEnabled: true } });
      expect(audit.log).toHaveBeenCalledWith(expect.objectContaining({ action: "mfa-enable", resourceId: "user-1" }));
    });

    it("rejette la vérification sans enrôlement préalable", async () => {
      prisma.user.findFirst.mockResolvedValue({ ...baseUser, mfaEnabled: false, mfaSecret: null });

      await expect(service.verifyMfa("user-1", "tenant-1", "123456")).rejects.toThrow(BadRequestException);
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

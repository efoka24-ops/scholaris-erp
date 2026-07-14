import { Body, Controller, Get, HttpCode, HttpStatus, Post } from "@nestjs/common";
import { ApiBearerAuth, ApiOperation, ApiTags } from "@nestjs/swagger";
import { Public } from "../../common/decorators/public.decorator";
import { CurrentUser } from "../../common/decorators/current-user.decorator";
import { AuthService } from "./auth.service";
import { LoginDto } from "./dto/login.dto";
import { MfaVerifyDto } from "./dto/mfa-verify.dto";
import { RefreshTokenDto } from "./dto/refresh-token.dto";
import { AuthenticatedUser } from "./jwt-payload.interface";

@ApiTags("auth")
@Controller("auth")
export class AuthController {
  constructor(private readonly authService: AuthService) {}

  @Public()
  @Post("login")
  @HttpCode(HttpStatus.OK)
  @ApiOperation({ summary: "Connexion (email + mot de passe, + code TOTP si MFA activé) → JWT access + refresh" })
  login(@Body() dto: LoginDto) {
    return this.authService.login(dto.email, dto.password, dto.mfaCode);
  }

  @Public()
  @Post("refresh")
  @HttpCode(HttpStatus.OK)
  @ApiOperation({ summary: "Renouvelle le JWT access à partir d'un refresh token valide" })
  refresh(@Body() dto: RefreshTokenDto) {
    return this.authService.refresh(dto.refreshToken);
  }

  @Get("me")
  @ApiBearerAuth()
  @ApiOperation({ summary: "Profil courant (permissions résolues) — vérifie que le JwtAuthGuard fonctionne" })
  me(@CurrentUser() user: AuthenticatedUser) {
    return this.authService.me(user.userId, user.tenantId);
  }

  @Post("mfa/enable")
  @HttpCode(HttpStatus.OK)
  @ApiBearerAuth()
  @ApiOperation({ summary: "Génère un secret TOTP + otpauth URL (QR) — MFA actif après /auth/mfa/verify" })
  enableMfa(@CurrentUser() user: AuthenticatedUser) {
    return this.authService.enableMfa(user.userId, user.tenantId);
  }

  @Post("mfa/verify")
  @HttpCode(HttpStatus.OK)
  @ApiBearerAuth()
  @ApiOperation({ summary: "Vérifie le premier code TOTP et active le MFA sur le compte" })
  verifyMfa(@Body() dto: MfaVerifyDto, @CurrentUser() user: AuthenticatedUser) {
    return this.authService.verifyMfa(user.userId, user.tenantId, dto.code);
  }
}

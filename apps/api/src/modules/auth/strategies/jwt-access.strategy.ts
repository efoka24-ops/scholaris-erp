import { Injectable } from "@nestjs/common";
import { ConfigService } from "@nestjs/config";
import { PassportStrategy } from "@nestjs/passport";
import { ExtractJwt, Strategy } from "passport-jwt";
import { RequestContextService } from "../../../common/context/request-context.service";
import { AuthenticatedUser, JwtAccessPayload } from "../jwt-payload.interface";

@Injectable()
export class JwtAccessStrategy extends PassportStrategy(Strategy, "jwt-access") {
  constructor(
    config: ConfigService,
    private readonly context: RequestContextService,
  ) {
    const secret = config.get<string>("JWT_ACCESS_SECRET") || "dev-jwt-access-secret-CHANGE-IN-PRODUCTION";
    if (!config.get<string>("JWT_ACCESS_SECRET")) {
      console.warn("⚠️  JWT_ACCESS_SECRET non défini, utilisation de la valeur par défaut (dev uniquement)");
    }
    super({
      jwtFromRequest: ExtractJwt.fromAuthHeaderAsBearerToken(),
      ignoreExpiration: false,
      secretOrKey: secret,
    });
  }

  // Exécuté à l'intérieur du même contexte AsyncLocalStorage ouvert par
  // RequestContextMiddleware : poser tenantId/userId ici les rend visibles
  // à PrismaService pour le reste de la requête.
  validate(payload: JwtAccessPayload): AuthenticatedUser {
    this.context.set("tenantId", payload.tenantId);
    this.context.set("userId", payload.sub);
    return {
      userId: payload.sub,
      tenantId: payload.tenantId,
      email: payload.email,
      permissions: payload.permissions,
    };
  }
}

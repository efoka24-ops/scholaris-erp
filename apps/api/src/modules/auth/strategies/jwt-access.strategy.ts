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
    super({
      jwtFromRequest: ExtractJwt.fromAuthHeaderAsBearerToken(),
      ignoreExpiration: false,
      secretOrKey: config.getOrThrow<string>("JWT_ACCESS_SECRET"),
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

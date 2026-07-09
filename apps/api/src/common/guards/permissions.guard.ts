import { CanActivate, ExecutionContext, ForbiddenException, Injectable } from "@nestjs/common";
import { Reflector } from "@nestjs/core";
import { PERMISSIONS_KEY } from "../decorators/require-permissions.decorator";
import type { AuthenticatedUser } from "../../modules/auth/jwt-payload.interface";

/** À poser après JwtAuthGuard (global) sur les endpoints marqués @RequirePermissions(...). */
@Injectable()
export class PermissionsGuard implements CanActivate {
  constructor(private readonly reflector: Reflector) {}

  canActivate(context: ExecutionContext): boolean {
    const required = this.reflector.getAllAndOverride<string[]>(PERMISSIONS_KEY, [
      context.getHandler(),
      context.getClass(),
    ]);
    if (!required || required.length === 0) {
      return true;
    }

    const request = context.switchToHttp().getRequest();
    const user: AuthenticatedUser | undefined = request.user;
    if (!user) {
      throw new ForbiddenException("Utilisateur non authentifié");
    }

    const hasPermission = required.some((permission) => user.permissions.includes(permission));
    if (!hasPermission) {
      throw new ForbiddenException(`Permission requise : ${required.join(" ou ")}`);
    }
    return true;
  }
}

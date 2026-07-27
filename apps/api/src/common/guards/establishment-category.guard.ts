import { CanActivate, ExecutionContext, ForbiddenException, Injectable } from "@nestjs/common";
import { Reflector } from "@nestjs/core";
import { PrismaService } from "../../prisma/prisma.service";
import { REQUIRES_CATEGORY_KEY } from "../decorators/requires-category.decorator";
import { resolveCategory, type EstablishmentCategory } from "../establishment/establishment-features";
import type { AuthenticatedUser } from "../../modules/auth/jwt-payload.interface";

/**
 * Garde de catégorie d'établissement (défense en profondeur). N'agit que sur les
 * routes annotées @RequiresCategory : si la catégorie de l'établissement courant
 * n'est pas autorisée, renvoie 403. Le Super Admin (tenants:create) passe toujours.
 */
@Injectable()
export class EstablishmentCategoryGuard implements CanActivate {
  constructor(
    private readonly reflector: Reflector,
    private readonly prisma: PrismaService,
  ) {}

  async canActivate(context: ExecutionContext): Promise<boolean> {
    const required = this.reflector.getAllAndOverride<EstablishmentCategory[] | undefined>(REQUIRES_CATEGORY_KEY, [
      context.getHandler(),
      context.getClass(),
    ]);
    if (!required || required.length === 0) return true;

    const req = context.switchToHttp().getRequest();
    const user = req.user as AuthenticatedUser | undefined;
    if (!user) return true; // laissé au JwtAuthGuard
    if (user.permissions?.includes("tenants:create")) return true; // Super Admin

    const tenant = await this.prisma.tenant.findFirst({
      where: { id: user.tenantId },
      select: { type: true, configJson: true },
    });
    const category = resolveCategory(
      tenant?.type,
      (tenant?.configJson as { establishmentCategory?: string } | null)?.establishmentCategory,
    );
    if (!required.includes(category)) {
      throw new ForbiddenException(
        "Cette fonctionnalité n'est pas disponible pour ce type d'établissement.",
      );
    }
    return true;
  }
}

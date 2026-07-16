import { Controller, Get, Param } from "@nestjs/common";
import { ApiOperation, ApiTags } from "@nestjs/swagger";
import { Public } from "../../common/decorators/public.decorator";
import { TenantsService } from "./tenants.service";

/**
 * Endpoints publics (sans JWT) consommés par la page vitrine du site avant
 * toute authentification. N'expose que des informations non sensibles.
 */
@ApiTags("public")
@Controller("public/tenants")
export class PublicTenantsController {
  constructor(private readonly tenantsService: TenantsService) {}

  @Public()
  @Get(":code")
  @ApiOperation({ summary: "Informations publiques minimales d'un établissement (vitrine)" })
  findByCode(@Param("code") code: string) {
    return this.tenantsService.findPublicByCode(code);
  }
}

import { Controller, Get, Param, Query } from "@nestjs/common";
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
  @Get()
  @ApiOperation({ summary: "Annuaire des établissements ouverts à la pré-inscription en ligne" })
  findList(@Query("search") search?: string) {
    return this.tenantsService.findPublicList(search);
  }

  @Public()
  @Get(":code")
  @ApiOperation({ summary: "Informations publiques minimales d'un établissement (vitrine)" })
  findByCode(@Param("code") code: string) {
    return this.tenantsService.findPublicByCode(code);
  }
}

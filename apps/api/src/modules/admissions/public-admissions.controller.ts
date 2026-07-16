import { Body, Controller, Post } from "@nestjs/common";
import { ApiOperation, ApiTags } from "@nestjs/swagger";
import { Throttle } from "@nestjs/throttler";
import { Public } from "../../common/decorators/public.decorator";
import { AdmissionsService } from "./admissions.service";
import { CreatePublicAdmissionDto } from "./dto/create-public-admission.dto";

/**
 * Formulaire de pré-inscription publique (page vitrine, sans authentification).
 * Rate-limité plus strictement que le throttle global de l'application
 * (100 req/min) : 5 soumissions par minute et par IP, pour limiter l'abus
 * d'un endpoint public non authentifié qui écrit en base.
 */
@ApiTags("public")
@Controller("public/admissions")
export class PublicAdmissionsController {
  constructor(private readonly admissionsService: AdmissionsService) {}

  @Public()
  @Throttle({ default: { limit: 5, ttl: 60_000 } })
  @Post()
  @ApiOperation({ summary: "Pré-inscription publique déposée par un parent (candidature DOSSIER, statut PENDING)" })
  create(@Body() dto: CreatePublicAdmissionDto) {
    return this.admissionsService.createPublic(dto);
  }
}

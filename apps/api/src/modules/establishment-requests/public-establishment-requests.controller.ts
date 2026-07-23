import { Body, Controller, Post } from "@nestjs/common";
import { ApiOperation, ApiTags } from "@nestjs/swagger";
import { Throttle } from "@nestjs/throttler";
import { Public } from "../../common/decorators/public.decorator";
import { EstablishmentRequestsService } from "./establishment-requests.service";
import { CreateEstablishmentRequestDto } from "./dto/create-establishment-request.dto";

/**
 * Demande publique de création d'établissement (directeur, sans authentification).
 * Rate-limité (5/min/IP) comme les autres endpoints publics qui écrivent en base.
 */
@ApiTags("public")
@Controller("public/establishment-requests")
export class PublicEstablishmentRequestsController {
  constructor(private readonly service: EstablishmentRequestsService) {}

  @Public()
  @Throttle({ default: { limit: 5, ttl: 60_000 } })
  @Post()
  @ApiOperation({ summary: "Déposer une demande de création d'établissement (directeur)" })
  create(@Body() dto: CreateEstablishmentRequestDto) {
    return this.service.createPublic(dto);
  }
}

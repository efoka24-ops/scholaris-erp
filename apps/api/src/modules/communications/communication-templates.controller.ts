import { Body, Controller, Get, Param, Post, Put } from "@nestjs/common";
import { ApiBearerAuth, ApiOperation, ApiTags } from "@nestjs/swagger";
import { PERMISSIONS } from "@scholaris/shared";
import { RequirePermissions } from "../../common/decorators/require-permissions.decorator";
import { CurrentUser } from "../../common/decorators/current-user.decorator";
import { AuthenticatedUser } from "../auth/jwt-payload.interface";
import { CommunicationTemplatesService } from "./communication-templates.service";
import { CreateCommunicationTemplateDto } from "./dto/create-communication-template.dto";
import { UpdateCommunicationTemplateDto } from "./dto/update-communication-template.dto";

@ApiTags("communication-templates")
@ApiBearerAuth()
@Controller("communication-templates")
export class CommunicationTemplatesController {
  constructor(private readonly templatesService: CommunicationTemplatesService) {}

  @Get()
  @RequirePermissions(PERMISSIONS.COMMUNICATION_TEMPLATES_READ)
  @ApiOperation({ summary: "Liste des modèles de communication de l'établissement courant" })
  findAll(@CurrentUser() user: AuthenticatedUser) {
    return this.templatesService.findAll(user.tenantId);
  }

  @Post()
  @RequirePermissions(PERMISSIONS.COMMUNICATION_TEMPLATES_CREATE)
  @ApiOperation({ summary: "Créer un modèle de communication (convocation, relance, etc.)" })
  create(@CurrentUser() user: AuthenticatedUser, @Body() dto: CreateCommunicationTemplateDto) {
    return this.templatesService.create(user.tenantId, dto);
  }

  @Put(":id")
  @RequirePermissions(PERMISSIONS.COMMUNICATION_TEMPLATES_UPDATE)
  @ApiOperation({ summary: "Modifier un modèle de communication" })
  update(@Param("id") id: string, @Body() dto: UpdateCommunicationTemplateDto) {
    return this.templatesService.update(id, dto);
  }
}

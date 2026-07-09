import { Body, Controller, Get, Param, Put } from "@nestjs/common";
import { ApiBearerAuth, ApiOperation, ApiTags } from "@nestjs/swagger";
import { PERMISSIONS } from "@scholaris/shared";
import { RequirePermissions } from "../../common/decorators/require-permissions.decorator";
import { CurrentUser } from "../../common/decorators/current-user.decorator";
import { AuthenticatedUser } from "../auth/jwt-payload.interface";
import { ChannelPreferenceService } from "./channel-preference.service";
import { ChannelPreferenceDto } from "./dto/channel-preference.dto";

// Réutilise la permission users:update existante plutôt que d'en créer une redondante
// (la préférence de canal fait partie du profil de l'utilisateur ciblé par :id).
@ApiTags("users")
@ApiBearerAuth()
@Controller("users")
export class ChannelPreferenceController {
  constructor(private readonly channelPreferenceService: ChannelPreferenceService) {}

  @Get(":id/channel-preference")
  @RequirePermissions(PERMISSIONS.USERS_UPDATE)
  @ApiOperation({ summary: "Préférence de canal (+ repli) d'un utilisateur" })
  findOne(@Param("id") id: string) {
    return this.channelPreferenceService.findByUserId(id);
  }

  @Put(":id/channel-preference")
  @RequirePermissions(PERMISSIONS.USERS_UPDATE)
  @ApiOperation({ summary: "Définir la préférence de canal (+ repli) d'un utilisateur" })
  upsert(@CurrentUser() user: AuthenticatedUser, @Param("id") id: string, @Body() dto: ChannelPreferenceDto) {
    return this.channelPreferenceService.upsert(user.tenantId, id, dto);
  }
}

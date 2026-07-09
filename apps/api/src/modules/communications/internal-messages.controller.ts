import { Body, Controller, Get, Param, Post, Put, Query } from "@nestjs/common";
import { ApiBearerAuth, ApiOperation, ApiTags } from "@nestjs/swagger";
import { PERMISSIONS } from "@scholaris/shared";
import { RequirePermissions } from "../../common/decorators/require-permissions.decorator";
import { CurrentUser } from "../../common/decorators/current-user.decorator";
import { AuthenticatedUser } from "../auth/jwt-payload.interface";
import { InternalMessagesService } from "./internal-messages.service";
import { CreateInternalMessageDto } from "./dto/create-internal-message.dto";
import { PaginationQueryDto } from "./dto/pagination-query.dto";

@ApiTags("internal-messages")
@ApiBearerAuth()
@Controller("internal-messages")
export class InternalMessagesController {
  constructor(private readonly internalMessagesService: InternalMessagesService) {}

  @Get()
  @RequirePermissions(PERMISSIONS.INTERNAL_MESSAGES_READ)
  @ApiOperation({ summary: "Messages internes envoyés/reçus par l'utilisateur courant" })
  findAll(@CurrentUser() user: AuthenticatedUser, @Query() query: PaginationQueryDto) {
    return this.internalMessagesService.findAllForUser(user.tenantId, user.userId, query);
  }

  @Post()
  @RequirePermissions(PERMISSIONS.INTERNAL_MESSAGES_CREATE)
  @ApiOperation({ summary: "Envoyer un message interne à un autre utilisateur de l'établissement" })
  create(@CurrentUser() user: AuthenticatedUser, @Body() dto: CreateInternalMessageDto) {
    return this.internalMessagesService.create(user.tenantId, user.userId, dto);
  }

  @Put(":id")
  @RequirePermissions(PERMISSIONS.INTERNAL_MESSAGES_READ)
  @ApiOperation({ summary: "Marquer un message interne comme lu (destinataire uniquement)" })
  markAsRead(@CurrentUser() user: AuthenticatedUser, @Param("id") id: string) {
    return this.internalMessagesService.markAsRead(id, user.userId);
  }
}

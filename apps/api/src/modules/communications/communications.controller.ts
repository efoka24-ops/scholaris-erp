import { Body, Controller, Get, HttpCode, HttpStatus, Post, Query } from "@nestjs/common";
import { ApiBearerAuth, ApiOperation, ApiTags } from "@nestjs/swagger";
import { PERMISSIONS } from "@scholaris/shared";
import { RequirePermissions } from "../../common/decorators/require-permissions.decorator";
import { CurrentUser } from "../../common/decorators/current-user.decorator";
import { AuthenticatedUser } from "../auth/jwt-payload.interface";
import { CommunicationsService } from "./communications.service";
import { SendMessageDto } from "./dto/send-message.dto";
import { PaginationQueryDto } from "./dto/pagination-query.dto";

@ApiTags("communications")
@ApiBearerAuth()
@Controller("communications")
export class CommunicationsController {
  constructor(private readonly communicationsService: CommunicationsService) {}

  @Post("send")
  @HttpCode(HttpStatus.CREATED)
  @RequirePermissions(PERMISSIONS.COMMUNICATIONS_CREATE)
  @ApiOperation({ summary: "Envoyer un message ad-hoc ou basé sur un template (email/SMS/WhatsApp/push/interne)" })
  send(@CurrentUser() user: AuthenticatedUser, @Body() dto: SendMessageDto) {
    return this.communicationsService.send(user.tenantId, dto);
  }

  @Get()
  @RequirePermissions(PERMISSIONS.COMMUNICATIONS_READ)
  @ApiOperation({ summary: "Journal paginé des communications envoyées" })
  findAll(@CurrentUser() user: AuthenticatedUser, @Query() query: PaginationQueryDto) {
    return this.communicationsService.findAll(user.tenantId, query);
  }
}

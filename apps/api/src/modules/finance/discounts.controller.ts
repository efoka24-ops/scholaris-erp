import { Body, Controller, Post } from "@nestjs/common";
import { ApiBearerAuth, ApiTags } from "@nestjs/swagger";
import { CurrentUser } from "../../common/decorators/current-user.decorator";
import { RequirePermissions } from "../../common/decorators/require-permissions.decorator";
import type { AuthenticatedUser } from "../auth/jwt-payload.interface";
import { CreateDiscountDto } from "./dto/create-discount.dto";
import { DiscountsService } from "./discounts.service";

@ApiTags("discounts")
@ApiBearerAuth()
@Controller("discounts")
export class DiscountsController {
  constructor(private readonly discountsService: DiscountsService) {}

  @Post()
  @RequirePermissions("discounts:create")
  create(@Body() dto: CreateDiscountDto, @CurrentUser() user: AuthenticatedUser) {
    return this.discountsService.create(dto, user.tenantId, user.userId);
  }
}

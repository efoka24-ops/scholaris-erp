import { Body, Controller, Get, Param, Post, Query } from "@nestjs/common";
import { ApiBearerAuth, ApiTags } from "@nestjs/swagger";
import { CurrentUser } from "../../common/decorators/current-user.decorator";
import { RequirePermissions } from "../../common/decorators/require-permissions.decorator";
import type { AuthenticatedUser } from "../auth/jwt-payload.interface";
import { CreatePaymentDto } from "./dto/create-payment.dto";
import { FindPaymentsQueryDto } from "./dto/find-payments-query.dto";
import { PaymentsService } from "./payments.service";

@ApiTags("payments")
@ApiBearerAuth()
@Controller("payments")
export class PaymentsController {
  constructor(private readonly paymentsService: PaymentsService) {}

  @Post()
  @RequirePermissions("payments:create")
  create(@Body() dto: CreatePaymentDto, @CurrentUser() user: AuthenticatedUser) {
    return this.paymentsService.create(dto, user.tenantId, user.userId);
  }

  @Get()
  @RequirePermissions("payments:read")
  findAll(@Query() query: FindPaymentsQueryDto) {
    return this.paymentsService.findAll(query);
  }

  @Get(":id")
  @RequirePermissions("payments:read")
  findOne(@Param("id") id: string) {
    return this.paymentsService.findOne(id);
  }

  @Get(":id/receipt")
  @RequirePermissions("payments:read")
  getReceipt(@Param("id") id: string) {
    return this.paymentsService.getReceipt(id);
  }
}

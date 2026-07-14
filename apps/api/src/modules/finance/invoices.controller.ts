import { Body, Controller, Get, Param, Post, Query } from "@nestjs/common";
import { ApiBearerAuth, ApiTags } from "@nestjs/swagger";
import { CurrentUser } from "../../common/decorators/current-user.decorator";
import { RequirePermissions } from "../../common/decorators/require-permissions.decorator";
import type { AuthenticatedUser } from "../auth/jwt-payload.interface";
import { GenerateBatchDto } from "./dto/generate-batch.dto";
import { FindInvoicesQueryDto } from "./dto/find-invoices-query.dto";
import { InvoicesService } from "./invoices.service";

@ApiTags("invoices")
@ApiBearerAuth()
@Controller("invoices")
export class InvoicesController {
  constructor(private readonly invoicesService: InvoicesService) {}

  @Get()
  @RequirePermissions("invoices:read")
  findAll(@Query() query: FindInvoicesQueryDto) {
    return this.invoicesService.findAll(query);
  }

  @Get(":id")
  @RequirePermissions("invoices:read")
  findOne(@Param("id") id: string) {
    return this.invoicesService.findOne(id);
  }

  @Post("generate/:enrollmentId")
  @RequirePermissions("invoices:create")
  generate(@Param("enrollmentId") enrollmentId: string, @CurrentUser() user: AuthenticatedUser) {
    return this.invoicesService.generateForEnrollment(enrollmentId, user.tenantId);
  }

  @Post("generate-batch/:classId")
  @RequirePermissions("invoices:create")
  generateBatch(
    @Param("classId") classId: string,
    @Body() dto: GenerateBatchDto,
    @CurrentUser() user: AuthenticatedUser,
  ) {
    return this.invoicesService.generateForClass(classId, dto.academicYearId, user.tenantId);
  }
}

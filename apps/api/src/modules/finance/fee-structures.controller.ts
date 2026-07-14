import { Body, Controller, Get, Param, Post, Query } from "@nestjs/common";
import { ApiBearerAuth, ApiTags } from "@nestjs/swagger";
import { CurrentUser } from "../../common/decorators/current-user.decorator";
import { RequirePermissions } from "../../common/decorators/require-permissions.decorator";
import type { AuthenticatedUser } from "../auth/jwt-payload.interface";
import { CreateFeeStructureDto } from "./dto/create-fee-structure.dto";
import { FindFeeStructuresQueryDto } from "./dto/find-fee-structures-query.dto";
import { FeeStructuresService } from "./fee-structures.service";

@ApiTags("fee-structures")
@ApiBearerAuth()
@Controller("fee-structures")
export class FeeStructuresController {
  constructor(private readonly feeStructuresService: FeeStructuresService) {}

  @Get()
  @RequirePermissions("fee-structures:read")
  findAll(@Query() query: FindFeeStructuresQueryDto) {
    return this.feeStructuresService.findAll(query);
  }

  @Get(":id")
  @RequirePermissions("fee-structures:read")
  findOne(@Param("id") id: string) {
    return this.feeStructuresService.findOne(id);
  }

  @Post()
  @RequirePermissions("fee-structures:create")
  create(@Body() dto: CreateFeeStructureDto, @CurrentUser() user: AuthenticatedUser) {
    return this.feeStructuresService.create(dto, user.tenantId);
  }
}

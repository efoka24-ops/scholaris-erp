import { Body, Controller, Get, Param, Post, Put, Query, Res } from "@nestjs/common";
import type { Response } from "express";
import { ApiBearerAuth, ApiQuery, ApiTags } from "@nestjs/swagger";
import { AdmissionStatus } from "@scholaris/prisma";
import { RequirePermissions } from "../../common/decorators/require-permissions.decorator";
import { CurrentUser } from "../../common/decorators/current-user.decorator";
import type { AuthenticatedUser } from "../auth/jwt-payload.interface";
import { AdmissionsService } from "./admissions.service";
import { AdmissionDecisionDto } from "./dto/admission-decision.dto";
import { CreateAdmissionDto } from "./dto/create-admission.dto";

@ApiTags("admissions")
@ApiBearerAuth()
@Controller("admissions")
export class AdmissionsController {
  constructor(private readonly admissionsService: AdmissionsService) {}

  @Get()
  @RequirePermissions("admissions:read")
  @ApiQuery({ name: "page", required: false })
  @ApiQuery({ name: "limit", required: false })
  @ApiQuery({ name: "status", required: false, enum: AdmissionStatus })
  @ApiQuery({ name: "academicYearId", required: false })
  @ApiQuery({ name: "search", required: false })
  findAll(
    @Query("page") page?: string,
    @Query("limit") limit?: string,
    @Query("status") status?: AdmissionStatus,
    @Query("academicYearId") academicYearId?: string,
    @Query("search") search?: string,
  ) {
    return this.admissionsService.findAll({
      page: page ? Number(page) : undefined,
      limit: limit ? Number(limit) : undefined,
      status,
      academicYearId,
      search,
    });
  }

  @Get(":id")
  @RequirePermissions("admissions:read")
  findOne(@Param("id") id: string) {
    return this.admissionsService.findOne(id);
  }

  @Post()
  @RequirePermissions("admissions:create")
  create(@Body() dto: CreateAdmissionDto, @CurrentUser() user: AuthenticatedUser) {
    return this.admissionsService.create(dto, user.tenantId);
  }

  @Put(":id/decision")
  @RequirePermissions("admissions:decide")
  decide(@Param("id") id: string, @Body() dto: AdmissionDecisionDto) {
    return this.admissionsService.decide(id, dto);
  }

  @Get(":id/documents/:fileName")
  @RequirePermissions("admissions:read")
  async downloadDocument(
    @Param("id") id: string,
    @Param("fileName") fileName: string,
    @Res() res: Response,
  ) {
    const path = await this.admissionsService.getDocumentPath(id, fileName);
    res.sendFile(path);
  }
}

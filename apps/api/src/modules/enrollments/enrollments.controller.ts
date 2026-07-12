import { Body, Controller, Get, Param, Post, Put, Query } from "@nestjs/common";
import { ApiBearerAuth, ApiQuery, ApiTags } from "@nestjs/swagger";
import { EnrollmentStatus } from "@scholaris/prisma";
import { RequirePermissions } from "../../common/decorators/require-permissions.decorator";
import { CurrentUser } from "../../common/decorators/current-user.decorator";
import type { AuthenticatedUser } from "../auth/jwt-payload.interface";
import { EnrollmentsService } from "./enrollments.service";
import { CreateEnrollmentDto } from "./dto/create-enrollment.dto";
import { ReEnrollDto } from "./dto/re-enroll.dto";
import { UpdateEnrollmentStatusDto } from "./dto/update-enrollment-status.dto";

@ApiTags("enrollments")
@ApiBearerAuth()
@Controller("enrollments")
export class EnrollmentsController {
  constructor(private readonly enrollmentsService: EnrollmentsService) {}

  @Get()
  @RequirePermissions("enrollments:read")
  @ApiQuery({ name: "classroomId", required: false })
  @ApiQuery({ name: "academicYearId", required: false })
  @ApiQuery({ name: "status", required: false, enum: EnrollmentStatus })
  findAll(
    @Query("classroomId") classroomId?: string,
    @Query("academicYearId") academicYearId?: string,
    @Query("status") status?: EnrollmentStatus,
  ) {
    return this.enrollmentsService.findAll({ classroomId, academicYearId, status });
  }

  @Post()
  @RequirePermissions("enrollments:create")
  enroll(@Body() dto: CreateEnrollmentDto, @CurrentUser() user: AuthenticatedUser) {
    return this.enrollmentsService.enroll(dto, user.tenantId);
  }

  @Post("re-enroll")
  @RequirePermissions("enrollments:re-enroll")
  reEnroll(@Body() dto: ReEnrollDto, @CurrentUser() user: AuthenticatedUser) {
    return this.enrollmentsService.reEnroll(dto, user.tenantId);
  }

  @Put(":id/status")
  @RequirePermissions("enrollments:update")
  updateStatus(@Param("id") id: string, @Body() dto: UpdateEnrollmentStatusDto) {
    return this.enrollmentsService.updateStatus(id, dto);
  }
}

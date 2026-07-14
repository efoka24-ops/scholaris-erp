import { Body, Controller, Get, Post, Query } from "@nestjs/common";
import { ApiBearerAuth, ApiQuery, ApiTags } from "@nestjs/swagger";
import { RequirePermissions } from "../../common/decorators/require-permissions.decorator";
import { CurrentUser } from "../../common/decorators/current-user.decorator";
import type { AuthenticatedUser } from "../auth/jwt-payload.interface";
import { CourseElementsService } from "./course-elements.service";
import { CreateCourseElementDto } from "./dto/create-course-element.dto";

@ApiTags("course-elements")
@ApiBearerAuth()
@Controller("course-elements")
export class CourseElementsController {
  constructor(private readonly courseElementsService: CourseElementsService) {}

  @Get()
  @RequirePermissions("course-elements:read")
  @ApiQuery({ name: "teachingUnitId", required: false })
  findAll(@Query("teachingUnitId") teachingUnitId?: string) {
    return this.courseElementsService.findAll({ teachingUnitId });
  }

  @Post()
  @RequirePermissions("course-elements:create")
  create(@Body() dto: CreateCourseElementDto, @CurrentUser() user: AuthenticatedUser) {
    return this.courseElementsService.create(dto, user.tenantId);
  }
}

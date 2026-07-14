import { Body, Controller, Get, Post, Query } from "@nestjs/common";
import { ApiBearerAuth, ApiQuery, ApiTags } from "@nestjs/swagger";
import { RequirePermissions } from "../../common/decorators/require-permissions.decorator";
import { CurrentUser } from "../../common/decorators/current-user.decorator";
import type { AuthenticatedUser } from "../auth/jwt-payload.interface";
import { SubjectAssignmentsService } from "./subject-assignments.service";
import { CreateSubjectAssignmentDto } from "./dto/create-subject-assignment.dto";

@ApiTags("subject-assignments")
@ApiBearerAuth()
@Controller("subject-assignments")
export class SubjectAssignmentsController {
  constructor(private readonly subjectAssignmentsService: SubjectAssignmentsService) {}

  @Get()
  @RequirePermissions("subject-assignments:read")
  @ApiQuery({ name: "classroomId", required: false })
  @ApiQuery({ name: "teacherId", required: false })
  @ApiQuery({ name: "academicYearId", required: false })
  findAll(
    @Query("classroomId") classroomId?: string,
    @Query("teacherId") teacherId?: string,
    @Query("academicYearId") academicYearId?: string,
  ) {
    return this.subjectAssignmentsService.findAll({ classroomId, teacherId, academicYearId });
  }

  // Sélecteur d'enseignants du frontend, en attendant GET /api/users (Module 1).
  @Get("teachers")
  @RequirePermissions("subject-assignments:read")
  findTeachers() {
    return this.subjectAssignmentsService.findTeachers();
  }

  // Sélecteur d'années académiques du frontend (pas d'endpoint dédié en Phase 0).
  @Get("academic-years")
  @RequirePermissions("subject-assignments:read")
  findAcademicYears() {
    return this.subjectAssignmentsService.findAcademicYears();
  }

  // La permission subject-assignments:create est attribuable au rôle "chef de
  // département" de chaque établissement en plus des admins (RBAC par rôle).
  @Post()
  @RequirePermissions("subject-assignments:create")
  create(@Body() dto: CreateSubjectAssignmentDto, @CurrentUser() user: AuthenticatedUser) {
    return this.subjectAssignmentsService.create(dto, user.tenantId);
  }
}

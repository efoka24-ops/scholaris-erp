import { Body, Controller, Get, Param, Post, Put, Query } from "@nestjs/common";
import { ApiBearerAuth, ApiTags } from "@nestjs/swagger";
import { RequirePermissions } from "../../common/decorators/require-permissions.decorator";
import { CurrentUser } from "../../common/decorators/current-user.decorator";
import type { AuthenticatedUser } from "../auth/jwt-payload.interface";
import { StudentsService } from "./students.service";
import { StudentsImportService } from "./students-import.service";
import { CreateStudentDto } from "./dto/create-student.dto";
import { UpdateStudentDto } from "./dto/update-student.dto";
import { FindStudentsQueryDto } from "./dto/find-students-query.dto";
import { ImportStudentsDto } from "./dto/import-students.dto";

@ApiTags("students")
@ApiBearerAuth()
@Controller("students")
export class StudentsController {
  constructor(
    private readonly studentsService: StudentsService,
    private readonly importService: StudentsImportService,
  ) {}

  @Get()
  @RequirePermissions("students:read")
  findAll(@Query() query: FindStudentsQueryDto) {
    return this.studentsService.findAll(query);
  }

  @Get(":id")
  @RequirePermissions("students:read")
  findOne(@Param("id") id: string) {
    return this.studentsService.findOne(id);
  }

  @Post()
  @RequirePermissions("students:create")
  create(@Body() dto: CreateStudentDto, @CurrentUser() user: AuthenticatedUser) {
    return this.studentsService.create(dto, user.tenantId);
  }

  @Post("import")
  @RequirePermissions("students:import")
  import(@Body() dto: ImportStudentsDto, @CurrentUser() user: AuthenticatedUser) {
    return this.importService.import(dto, user.tenantId);
  }

  @Put(":id")
  @RequirePermissions("students:update")
  update(@Param("id") id: string, @Body() dto: UpdateStudentDto) {
    return this.studentsService.update(id, dto);
  }
}

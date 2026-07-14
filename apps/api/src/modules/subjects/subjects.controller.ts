import { Body, Controller, Delete, Get, Param, Post, Put, Query } from "@nestjs/common";
import { ApiBearerAuth, ApiTags } from "@nestjs/swagger";
import { RequirePermissions } from "../../common/decorators/require-permissions.decorator";
import { CurrentUser } from "../../common/decorators/current-user.decorator";
import type { AuthenticatedUser } from "../auth/jwt-payload.interface";
import { SubjectsService } from "./subjects.service";
import { SubjectsImportService } from "./subjects-import.service";
import { CreateSubjectDto } from "./dto/create-subject.dto";
import { UpdateSubjectDto } from "./dto/update-subject.dto";
import { FindSubjectsQueryDto } from "./dto/find-subjects-query.dto";
import { ImportSubjectsDto } from "./dto/import-subjects.dto";

@ApiTags("subjects")
@ApiBearerAuth()
@Controller("subjects")
export class SubjectsController {
  constructor(
    private readonly subjectsService: SubjectsService,
    private readonly subjectsImportService: SubjectsImportService,
  ) {}

  // Lecture ouverte à tout utilisateur authentifié (enseignants, secrétariat…) :
  // pas de @RequirePermissions, le JwtAuthGuard global suffit.
  @Get()
  findAll(@Query() query: FindSubjectsQueryDto) {
    return this.subjectsService.findAll(query);
  }

  // Déclaré avant ":id" pour que "by-classroom" ne soit pas capté comme un id.
  @Get("by-classroom/:classId")
  findByClassroom(@Param("classId") classId: string) {
    return this.subjectsService.findByClassroom(classId);
  }

  @Get(":id")
  findOne(@Param("id") id: string) {
    return this.subjectsService.findOne(id);
  }

  @Post()
  @RequirePermissions("subjects:create")
  create(@Body() dto: CreateSubjectDto, @CurrentUser() user: AuthenticatedUser) {
    return this.subjectsService.create(dto, user.tenantId);
  }

  @Post("import")
  @RequirePermissions("subjects:create")
  import(@Body() dto: ImportSubjectsDto, @CurrentUser() user: AuthenticatedUser) {
    return this.subjectsImportService.import(dto.fileBase64, user.tenantId, dto.dryRun ?? false);
  }

  @Put(":id")
  @RequirePermissions("subjects:update")
  update(@Param("id") id: string, @Body() dto: UpdateSubjectDto) {
    return this.subjectsService.update(id, dto);
  }

  @Delete(":id")
  @RequirePermissions("subjects:delete")
  remove(@Param("id") id: string) {
    return this.subjectsService.remove(id);
  }
}

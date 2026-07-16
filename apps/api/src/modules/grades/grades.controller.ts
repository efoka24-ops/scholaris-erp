import { Body, Controller, Get, Param, Post, Put, Query } from "@nestjs/common";
import { ApiBearerAuth, ApiOperation, ApiQuery, ApiTags } from "@nestjs/swagger";
import { PERMISSIONS } from "@scholaris/shared";
import { RequirePermissions } from "../../common/decorators/require-permissions.decorator";
import { CurrentUser } from "../../common/decorators/current-user.decorator";
import type { AuthenticatedUser } from "../auth/jwt-payload.interface";
import { GradesService } from "./grades.service";
import { GradesImportService } from "./grades-import.service";
import { CreateGradeBatchDto } from "./dto/create-grade-batch.dto";
import { UpdateGradeDto } from "./dto/update-grade.dto";
import { ImportGradesDto } from "./dto/import-grades.dto";
import { DeliberationDto } from "./dto/deliberation.dto";
import { CalculateAnnualQueryDto } from "./dto/calculate-annual-query.dto";
import { assertStudentAccess } from "../../common/guards/student-scope.util";
import { PrismaService } from "../../prisma/prisma.service";

@ApiTags("grades")
@ApiBearerAuth()
@Controller("grades")
export class GradesController {
  constructor(
    private readonly gradesService: GradesService,
    private readonly importService: GradesImportService,
    private readonly prisma: PrismaService,
  ) {}

  @Post("batch")
  @RequirePermissions(PERMISSIONS.GRADES_CREATE)
  @ApiOperation({ summary: "Saisie collective de notes (une évaluation, une note par élève)" })
  batchCreate(@Body() dto: CreateGradeBatchDto, @CurrentUser() user: AuthenticatedUser) {
    return this.gradesService.batchCreate(dto, user);
  }

  @Post("import")
  @RequirePermissions(PERMISSIONS.GRADES_IMPORT)
  @ApiOperation({ summary: "Import Excel des notes d'une évaluation" })
  import(@Body() dto: ImportGradesDto, @CurrentUser() user: AuthenticatedUser) {
    return this.importService.import(dto, user);
  }

  @Put(":id")
  @RequirePermissions(PERMISSIONS.GRADES_UPDATE)
  @ApiOperation({ summary: "Modifie une note avant verrouillage" })
  update(@Param("id") id: string, @Body() dto: UpdateGradeDto, @CurrentUser() user: AuthenticatedUser) {
    return this.gradesService.update(id, dto, user.tenantId);
  }

  @Put("lock/:classId/:subjectId/:periodId")
  @RequirePermissions(PERMISSIONS.GRADES_LOCK)
  @ApiOperation({ summary: "Verrouille la saisie d'une matière/EC pour une classe et une période" })
  lock(
    @Param("classId") classId: string,
    @Param("subjectId") subjectId: string,
    @Param("periodId") periodId: string,
    @CurrentUser() user: AuthenticatedUser,
  ) {
    return this.gradesService.lock(classId, subjectId, periodId, user.tenantId);
  }

  @Put("unlock/:classId/:subjectId/:periodId")
  @RequirePermissions(PERMISSIONS.GRADES_UNLOCK)
  @ApiOperation({ summary: "Rouvre une saisie verrouillée (censeur/admin uniquement)" })
  unlock(
    @Param("classId") classId: string,
    @Param("subjectId") subjectId: string,
    @Param("periodId") periodId: string,
    @CurrentUser() user: AuthenticatedUser,
  ) {
    return this.gradesService.unlock(classId, subjectId, periodId, user.tenantId);
  }

  @Post("calculate/:classId/:periodId")
  @RequirePermissions(PERMISSIONS.GRADES_CALCULATE)
  @ApiOperation({ summary: "Calcule moyennes de matières/EC, moyenne générale et classement pour une période" })
  calculate(@Param("classId") classId: string, @Param("periodId") periodId: string, @CurrentUser() user: AuthenticatedUser) {
    return this.gradesService.calculate(classId, periodId, user.tenantId);
  }

  @Post("calculate-annual/:classId")
  @RequirePermissions(PERMISSIONS.GRADES_CALCULATE)
  @ApiOperation({ summary: "Calcule le bilan annuel (moyenne, rang, mention, décision, GPA en LMD)" })
  calculateAnnual(
    @Param("classId") classId: string,
    @Query() query: CalculateAnnualQueryDto,
    @CurrentUser() user: AuthenticatedUser,
  ) {
    return this.gradesService.calculateAnnual(classId, query.academicYearId, user.tenantId);
  }

  @Get("progress/:periodId")
  @RequirePermissions(PERMISSIONS.GRADES_PROGRESS)
  @ApiOperation({ summary: "Avancement de la saisie (matières saisies vs non saisies) pour une période" })
  @ApiQuery({ name: "classroomId", required: false })
  getProgress(
    @Param("periodId") periodId: string,
    @CurrentUser() user: AuthenticatedUser,
    @Query("classroomId") classroomId?: string,
  ) {
    return this.gradesService.getProgress(periodId, user.tenantId, classroomId);
  }

  @Get("student/:studentId")
  @RequirePermissions(PERMISSIONS.GRADES_READ)
  @ApiOperation({ summary: "Historique des notes et résultats d'un élève" })
  async findByStudent(@Param("studentId") studentId: string, @CurrentUser() user: AuthenticatedUser) {
    // Anti-IDOR : un Parent/Élève ne peut consulter que ses propres notes (cf. audit RBAC).
    await assertStudentAccess(this.prisma, user, studentId);
    return this.gradesService.findByStudent(studentId, user.tenantId, this.canViewUnpublished(user));
  }

  @Get("results/:classId/:periodId")
  @RequirePermissions(PERMISSIONS.GRADES_READ)
  @ApiOperation({ summary: "Tableau récapitulatif des résultats d'une classe pour une période" })
  findResults(@Param("classId") classId: string, @Param("periodId") periodId: string, @CurrentUser() user: AuthenticatedUser) {
    return this.gradesService.findResults(classId, periodId, user.tenantId, this.canViewUnpublished(user));
  }

  @Post("deliberation/:classId/:periodId")
  @RequirePermissions(PERMISSIONS.GRADES_DELIBERATION)
  @ApiOperation({ summary: "Délibération : décisions/observations sur les résultats déjà calculés" })
  deliberate(@Param("classId") classId: string, @Param("periodId") periodId: string, @Body() dto: DeliberationDto) {
    return this.gradesService.deliberate(classId, periodId, dto);
  }

  @Post("publish/:classId/:periodId")
  @RequirePermissions(PERMISSIONS.GRADES_PUBLISH)
  @ApiOperation({ summary: "Publie les résultats (visibles pour parents/élèves)" })
  publish(@Param("classId") classId: string, @Param("periodId") periodId: string) {
    return this.gradesService.publish(classId, periodId);
  }

  /** Censeur/admin/directeur (droits calcul, délibération ou publication) voient aussi les résultats non publiés. */
  private canViewUnpublished(user: AuthenticatedUser): boolean {
    return [PERMISSIONS.GRADES_CALCULATE, PERMISSIONS.GRADES_DELIBERATION, PERMISSIONS.GRADES_PUBLISH].some((permission) =>
      user.permissions.includes(permission),
    );
  }
}

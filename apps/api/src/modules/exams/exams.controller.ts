import { Body, Controller, Get, Param, Post, Put } from "@nestjs/common";
import { ApiBearerAuth, ApiOperation, ApiTags } from "@nestjs/swagger";
import { RequirePermissions } from "../../common/decorators/require-permissions.decorator";
import { CurrentUser } from "../../common/decorators/current-user.decorator";
import type { AuthenticatedUser } from "../auth/jwt-payload.interface";
import { ExamsService } from "./exams.service";
import { CreateExamDto } from "./dto/create-exam.dto";
import { RegisterBatchDto, RegisterCandidateDto } from "./dto/register-candidate.dto";
import { SubmitResultsDto } from "./dto/submit-results.dto";

@ApiTags("exams")
@ApiBearerAuth()
@Controller("exams")
export class ExamsController {
  constructor(private readonly exams: ExamsService) {}

  @Post()
  @RequirePermissions("exams:create")
  @ApiOperation({ summary: "Créer un examen officiel ou configurable" })
  create(@Body() dto: CreateExamDto, @CurrentUser() user: AuthenticatedUser) {
    return this.exams.create(dto, user.tenantId);
  }

  @Get()
  @RequirePermissions("exams:read")
  @ApiOperation({ summary: "Lister les examens de l'établissement" })
  findAll() {
    return this.exams.findAll();
  }

  @Get(":id")
  @RequirePermissions("exams:read")
  findOne(@Param("id") id: string) {
    return this.exams.findOne(id);
  }

  @Post(":id/register")
  @RequirePermissions("exams:register")
  @ApiOperation({ summary: "Inscrire un élève à l'examen" })
  register(@Param("id") id: string, @Body() dto: RegisterCandidateDto, @CurrentUser() user: AuthenticatedUser) {
    return this.exams.registerOne(id, dto, user.tenantId, user.userId);
  }

  @Post(":id/register-batch")
  @RequirePermissions("exams:register")
  @ApiOperation({ summary: "Inscription batch (classe/niveau) avec rapport d'éligibilité" })
  registerBatch(@Param("id") id: string, @Body() dto: RegisterBatchDto, @CurrentUser() user: AuthenticatedUser) {
    return this.exams.registerBatch(id, dto, user.tenantId);
  }

  @Get(":id/candidates")
  @RequirePermissions("exams:read")
  @ApiOperation({ summary: "Liste des candidats inscrits" })
  candidates(@Param("id") id: string) {
    return this.exams.getCandidates(id);
  }

  @Put(":id/candidates/:cid/validate")
  @RequirePermissions("exams:register")
  @ApiOperation({ summary: "Valider une inscription (frais payés requis)" })
  validate(@Param("id") id: string, @Param("cid") cid: string, @CurrentUser() user: AuthenticatedUser) {
    return this.exams.validateCandidate(id, cid, user.userId);
  }

  @Post(":id/results")
  @RequirePermissions("exams:results")
  @ApiOperation({ summary: "Saisir/importer les résultats et déclencher le calcul" })
  submitResults(@Param("id") id: string, @Body() dto: SubmitResultsDto) {
    return this.exams.submitResults(id, dto);
  }

  @Get(":id/results")
  @RequirePermissions("exams:read")
  @ApiOperation({ summary: "Résultats : taux de réussite, mentions, classement" })
  results(@Param("id") id: string) {
    return this.exams.getResults(id);
  }
}

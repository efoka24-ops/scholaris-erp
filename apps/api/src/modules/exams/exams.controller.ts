import { Body, Controller, Get, Param, Post, Put, Res } from "@nestjs/common";
import { Response } from "express";
import { ApiBearerAuth, ApiOperation, ApiTags } from "@nestjs/swagger";
import { RequirePermissions } from "../../common/decorators/require-permissions.decorator";
import { CurrentUser } from "../../common/decorators/current-user.decorator";
import type { AuthenticatedUser } from "../auth/jwt-payload.interface";
import { ExamsService } from "./exams.service";
import { ExamPrintService } from "./exam-print.service";
import { CreateExamDto } from "./dto/create-exam.dto";
import { RegisterBatchDto, RegisterCandidateDto } from "./dto/register-candidate.dto";
import { SubmitResultsDto } from "./dto/submit-results.dto";

@ApiTags("exams")
@ApiBearerAuth()
@Controller("exams")
export class ExamsController {
  constructor(
    private readonly exams: ExamsService,
    private readonly print: ExamPrintService,
  ) {}

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

  // --- Exports & impressions ---------------------------------------------

  @Get(":id/export/candidates")
  @RequirePermissions("exams:read")
  @ApiOperation({ summary: "Exporter la liste des candidats (CSV)" })
  async exportCandidates(@Param("id") id: string, @Res() res: Response) {
    const csv = await this.print.candidatesCsv(id);
    this.sendCsv(res, `candidats-${id}.csv`, csv);
  }

  @Get(":id/export/results")
  @RequirePermissions("exams:read")
  @ApiOperation({ summary: "Exporter les résultats (CSV)" })
  async exportResults(@Param("id") id: string, @Res() res: Response) {
    const csv = await this.print.resultsCsv(id);
    this.sendCsv(res, `resultats-${id}.csv`, csv);
  }

  @Get(":id/print/candidate-list")
  @RequirePermissions("exams:read")
  @ApiOperation({ summary: "Imprimer la liste officielle des candidats (A4)" })
  async printCandidateList(@Param("id") id: string, @CurrentUser() user: AuthenticatedUser, @Res() res: Response) {
    this.sendHtml(res, await this.print.candidateListHtml(id, user.tenantId));
  }

  @Get(":id/print/receipts")
  @RequirePermissions("exams:read")
  @ApiOperation({ summary: "Imprimer les récépissés d'inscription (A5, 4/page)" })
  async printReceipts(@Param("id") id: string, @CurrentUser() user: AuthenticatedUser, @Res() res: Response) {
    this.sendHtml(res, await this.print.receiptsHtml(id, user.tenantId));
  }

  @Get(":id/print/results-board")
  @RequirePermissions("exams:read")
  @ApiOperation({ summary: "Tableau d'affichage des résultats (A4)" })
  async printResultsBoard(@Param("id") id: string, @CurrentUser() user: AuthenticatedUser, @Res() res: Response) {
    this.sendHtml(res, await this.print.resultsBoardHtml(id, user.tenantId));
  }

  private sendCsv(res: Response, filename: string, csv: string): void {
    res.setHeader("Content-Type", "text/csv; charset=utf-8");
    res.setHeader("Content-Disposition", `attachment; filename="${filename}"`);
    res.send("﻿" + csv); // BOM UTF-8 pour Excel
  }

  private sendHtml(res: Response, html: string): void {
    res.setHeader("Content-Type", "text/html; charset=utf-8");
    res.send(html);
  }
}

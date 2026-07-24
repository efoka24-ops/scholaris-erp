import { Body, Controller, Get, Param, Post, Put, Query, Res } from "@nestjs/common";
import { Response } from "express";
import { ApiBearerAuth, ApiOperation, ApiTags } from "@nestjs/swagger";
import { RequirePermissions } from "../../common/decorators/require-permissions.decorator";
import { CurrentUser } from "../../common/decorators/current-user.decorator";
import type { AuthenticatedUser } from "../auth/jwt-payload.interface";
import { StudentsService } from "./students.service";
import { StudentsImportService } from "./students-import.service";
import { StudentCardsService } from "./student-cards.service";
import { CreateStudentDto } from "./dto/create-student.dto";
import { UpdateStudentDto } from "./dto/update-student.dto";
import { FindStudentsQueryDto } from "./dto/find-students-query.dto";
import { ImportStudentsDto } from "./dto/import-students.dto";
import { assertStudentAccess } from "../../common/guards/student-scope.util";
import { PrismaService } from "../../prisma/prisma.service";

@ApiTags("students")
@ApiBearerAuth()
@Controller("students")
export class StudentsController {
  constructor(
    private readonly studentsService: StudentsService,
    private readonly importService: StudentsImportService,
    private readonly cardsService: StudentCardsService,
    private readonly prisma: PrismaService,
  ) {}

  @Get()
  @RequirePermissions("students:read")
  findAll(@Query() query: FindStudentsQueryDto) {
    return this.studentsService.findAll(query);
  }

  @Get("export/csv")
  @RequirePermissions("students:read")
  @ApiOperation({ summary: "Exporter la liste des élèves en CSV (filtrable classe/niveau/année)" })
  async exportCsv(@Query() query: FindStudentsQueryDto, @Res() res: Response) {
    const { data } = await this.studentsService.findAll({ ...query, page: 1, limit: 5000 } as FindStudentsQueryDto);
    const esc = (v: any) => {
      const s = String(v ?? "");
      return /[",;\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
    };
    const head = ["Matricule", "Nom", "Prenom", "Sexe", "Date_naissance", "Lieu_naissance", "Classe", "Statut"];
    const lines = (data as any[]).map((s) =>
      [
        s.matricule,
        s.lastName,
        s.firstName,
        s.gender === "MALE" ? "M" : s.gender === "FEMALE" ? "F" : "",
        s.dateOfBirth ? new Date(s.dateOfBirth).toLocaleDateString("fr-FR") : "",
        s.placeOfBirth ?? "",
        s.enrollments?.[0]?.classroom?.name ?? "",
        s.status,
      ]
        .map(esc)
        .join(";"),
    );
    res.setHeader("Content-Type", "text/csv; charset=utf-8");
    res.setHeader("Content-Disposition", `attachment; filename="eleves.csv"`);
    res.send("﻿" + [head.join(";"), ...lines].join("\r\n"));
  }

  @Get("print/cards")
  @RequirePermissions("students:read")
  @ApiOperation({ summary: "Cartes scolaires avec QR (A4, 8 par page)" })
  async printCards(@Query() query: FindStudentsQueryDto, @CurrentUser() user: AuthenticatedUser, @Res() res: Response) {
    const html = await this.cardsService.cardsHtml(query, user.tenantId);
    res.setHeader("Content-Type", "text/html; charset=utf-8");
    res.send(html);
  }

  @Get("print/labels")
  @RequirePermissions("students:read")
  @ApiOperation({ summary: "Étiquettes de dossiers (A4, format Avery)" })
  async printLabels(@Query() query: FindStudentsQueryDto, @CurrentUser() user: AuthenticatedUser, @Res() res: Response) {
    const html = await this.cardsService.labelsHtml(query, user.tenantId);
    res.setHeader("Content-Type", "text/html; charset=utf-8");
    res.send(html);
  }

  @Get("print/class-list")
  @RequirePermissions("students:read")
  @ApiOperation({ summary: "Liste de classe imprimable (A4, format officiel)" })
  async printClassList(
    @Query() query: FindStudentsQueryDto,
    @CurrentUser() user: AuthenticatedUser,
    @Res() res: Response,
  ) {
    const { data } = await this.studentsService.findAll({ ...query, page: 1, limit: 5000 } as FindStudentsQueryDto);
    const tenant = await this.prisma.tenant.findFirst({ where: { id: user.tenantId }, select: { name: true } });
    const esc = (v: any) => String(v ?? "").replace(/[&<>]/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;" }[c] as string));
    const rows = (data as any[])
      .map(
        (s, i) => `<tr><td class="c">${i + 1}</td><td>${esc(s.matricule)}</td><td>${esc(s.lastName)} ${esc(s.firstName)}</td>
        <td class="c">${s.gender === "MALE" ? "M" : "F"}</td><td class="c">${s.dateOfBirth ? new Date(s.dateOfBirth).toLocaleDateString("fr-FR") : "—"}</td>
        <td>${esc(s.enrollments?.[0]?.classroom?.name ?? "—")}</td></tr>`,
      )
      .join("");
    const html = `<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>Liste de classe</title>
    <style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:Arial,sans-serif;font-size:10pt;padding:10mm}
    h1{text-align:center;font-size:13pt;margin-bottom:2mm}.est{text-align:center;font-weight:bold;margin-bottom:3mm}
    table{width:100%;border-collapse:collapse}th,td{border:1px solid #333;padding:1.5mm 2mm;font-size:9pt}th{background:#e8e8e8}.c{text-align:center}
    @page{size:A4}</style></head><body>
    <div class="est">${esc(tenant?.name ?? "Établissement")}</div>
    <h1>Liste des élèves (${(data as any[]).length})</h1>
    <table><thead><tr><th>#</th><th>Matricule</th><th>Nom &amp; Prénom</th><th>Sexe</th><th>Date naiss.</th><th>Classe</th></tr></thead>
    <tbody>${rows || `<tr><td colspan="6" class="c">Aucun élève</td></tr>`}</tbody></table></body></html>`;
    res.setHeader("Content-Type", "text/html; charset=utf-8");
    res.send(html);
  }

  @Get(":id")
  @RequirePermissions("students:read")
  async findOne(@Param("id") id: string, @CurrentUser() user: AuthenticatedUser) {
    // Anti-IDOR : un Parent/Élève ne peut consulter que son propre dossier (cf. audit RBAC).
    await assertStudentAccess(this.prisma, user, id);
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

  @Post("import/validate")
  @RequirePermissions("students:import")
  @ApiOperation({ summary: "Valider un fichier d'import (dry-run) et retourner le rapport de pré-import" })
  validateImport(@Body() dto: ImportStudentsDto, @CurrentUser() user: AuthenticatedUser) {
    return this.importService.validate(dto, user.tenantId);
  }

  @Get("import/template")
  @RequirePermissions("students:import")
  @ApiOperation({ summary: "Template Excel d'import (base64, à décoder côté client)" })
  async importTemplate() {
    const buffer = await this.importService.generateTemplate();
    return {
      filename: "template-import-eleves.xlsx",
      contentType: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
      base64: buffer.toString("base64"),
    };
  }

  @Put(":id")
  @RequirePermissions("students:update")
  update(@Param("id") id: string, @Body() dto: UpdateStudentDto) {
    return this.studentsService.update(id, dto);
  }
}

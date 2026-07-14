import { BadRequestException, Injectable } from "@nestjs/common";
import * as ExcelJS from "exceljs";
import { PrismaService } from "../../prisma/prisma.service";
import type { AuthenticatedUser } from "../auth/jwt-payload.interface";
import { GradesService } from "./grades.service";
import { ImportGradesDto } from "./dto/import-grades.dto";
import { normalizeName } from "../students/students.service";

export interface ImportGradesRowError {
  row: number;
  message: string;
}

export interface ImportGradesReport {
  created: number;
  skipped: number;
  errors: ImportGradesRowError[];
}

/** En-têtes reconnues (insensibles à la casse et aux accents). */
const HEADER_ALIASES: Record<string, string> = {
  matricule: "matricule",
  note: "value",
  valeur: "value",
  absent: "isAbsent",
  absence: "isAbsent",
  justifie: "isJustified",
  justifiee: "isJustified",
  commentaire: "comment",
};

const TRUTHY_VALUES = new Set(["1", "oui", "yes", "true", "x", "vrai"]);

/**
 * Import Excel d'une évaluation (une feuille = une évaluation : matière/EC,
 * période, type, barème communs à toutes les lignes). Chaque ligne valide
 * passe par GradesService.batchCreate (mêmes contrôles : barème, période
 * ouverte). Un matricule inconnu est compté en erreur, pas en doublon.
 */
@Injectable()
export class GradesImportService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly gradesService: GradesService,
  ) {}

  async import(dto: ImportGradesDto, user: AuthenticatedUser): Promise<ImportGradesReport> {
    const rows = await this.parseWorkbook(dto.contentBase64);
    const report: ImportGradesReport = { created: 0, skipped: 0, errors: [] };

    const entries: Array<{ studentId: string; value?: number; isAbsent?: boolean; isJustified?: boolean; comment?: string }> = [];

    for (const { rowNumber, values } of rows) {
      const matricule = this.asText(values.matricule);
      if (!matricule) {
        report.errors.push({ row: rowNumber, message: "Matricule manquant" });
        continue;
      }

      const student = await this.prisma.student.findFirst({ where: { matricule } });
      if (!student) {
        report.errors.push({ row: rowNumber, message: `Élève introuvable pour le matricule ${matricule}` });
        continue;
      }

      const isAbsent = TRUTHY_VALUES.has(normalizeName(this.asText(values.isAbsent) ?? ""));
      const isJustified = TRUTHY_VALUES.has(normalizeName(this.asText(values.isJustified) ?? ""));
      const valueText = this.asText(values.value);
      const value = valueText ? Number(valueText.replace(",", ".")) : undefined;

      if (!isAbsent && (value === undefined || Number.isNaN(value))) {
        report.errors.push({ row: rowNumber, message: `Note manquante ou invalide pour ${matricule}` });
        continue;
      }

      entries.push({
        studentId: student.id,
        value: isAbsent ? undefined : value,
        isAbsent,
        isJustified,
        comment: this.asText(values.comment),
      });
    }

    if (entries.length === 0) {
      return report;
    }

    try {
      const created = await this.gradesService.batchCreate(
        {
          classroomId: dto.classroomId,
          subjectId: dto.subjectId,
          courseElementId: dto.courseElementId,
          periodId: dto.periodId,
          type: dto.type,
          date: dto.date,
          maxValue: dto.maxValue,
          weight: dto.weight,
          entries,
        },
        user,
      );
      report.created = created.length;
    } catch (error) {
      report.errors.push({ row: 0, message: (error as Error).message });
    }

    return report;
  }

  private async parseWorkbook(contentBase64: string): Promise<Array<{ rowNumber: number; values: Record<string, unknown> }>> {
    let worksheet: ExcelJS.Worksheet | undefined;
    try {
      const workbook = new ExcelJS.Workbook();
      await workbook.xlsx.load(Buffer.from(contentBase64, "base64") as unknown as ExcelJS.Buffer);
      worksheet = workbook.worksheets[0];
    } catch {
      throw new BadRequestException("Fichier Excel illisible (format .xlsx attendu)");
    }
    if (!worksheet) {
      throw new BadRequestException("Le classeur ne contient aucune feuille");
    }

    const headerRow = worksheet.getRow(1);
    const columns = new Map<number, string>();
    headerRow.eachCell((cell, colNumber) => {
      const field = HEADER_ALIASES[normalizeName(String(cell.value ?? ""))];
      if (field) {
        columns.set(colNumber, field);
      }
    });
    if (!Array.from(columns.values()).some((field) => field === "matricule")) {
      throw new BadRequestException("En-têtes non reconnues : colonnes attendues « Matricule », « Note »");
    }

    const rows: Array<{ rowNumber: number; values: Record<string, unknown> }> = [];
    worksheet.eachRow((row, rowNumber) => {
      if (rowNumber === 1) {
        return;
      }
      const values: Record<string, unknown> = {};
      columns.forEach((field, colNumber) => {
        const cell = row.getCell(colNumber);
        values[field] = cell.value ?? undefined;
      });
      if (Object.values(values).some((value) => value !== undefined && String(value).trim() !== "")) {
        rows.push({ rowNumber, values });
      }
    });
    return rows;
  }

  private asText(value: unknown): string | undefined {
    if (value === undefined || value === null) {
      return undefined;
    }
    const text = String(value).trim();
    return text === "" ? undefined : text;
  }
}

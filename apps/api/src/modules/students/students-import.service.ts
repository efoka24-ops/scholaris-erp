import { BadRequestException, ConflictException, Injectable } from "@nestjs/common";
import { Gender } from "@scholaris/prisma";
import * as ExcelJS from "exceljs";
import { StudentsService, normalizeName } from "./students.service";
import { CreateStudentDto } from "./dto/create-student.dto";
import { ImportStudentsDto } from "./dto/import-students.dto";

export interface ImportRowError {
  row: number;
  message: string;
}

export interface ImportReport {
  created: number;
  duplicates: number;
  errors: ImportRowError[];
}

/** En-têtes reconnues (insensibles à la casse et aux accents). */
const HEADER_ALIASES: Record<string, string> = {
  nom: "lastName",
  "last name": "lastName",
  lastname: "lastName",
  prenom: "firstName",
  "first name": "firstName",
  firstname: "firstName",
  "date de naissance": "dateOfBirth",
  "date naissance": "dateOfBirth",
  dateofbirth: "dateOfBirth",
  sexe: "gender",
  genre: "gender",
  gender: "gender",
  "lieu de naissance": "placeOfBirth",
  placeofbirth: "placeOfBirth",
  nationalite: "nationality",
  nationality: "nationality",
  "groupe sanguin": "bloodGroup",
  allergies: "allergies",
  "contact urgence": "emergencyContact",
  "contact d urgence": "emergencyContact",
};

const GENDER_ALIASES: Record<string, Gender> = {
  m: Gender.MALE,
  masculin: Gender.MALE,
  male: Gender.MALE,
  garcon: Gender.MALE,
  h: Gender.MALE,
  f: Gender.FEMALE,
  feminin: Gender.FEMALE,
  female: Gender.FEMALE,
  fille: Gender.FEMALE,
};

@Injectable()
export class StudentsImportService {
  constructor(private readonly studentsService: StudentsService) {}

  /**
   * Importe un classeur Excel (première feuille, ligne 1 = en-têtes).
   * Chaque ligne valide passe par StudentsService.create (matricule généré,
   * détection de doublons) : un doublon est compté, pas créé.
   */
  async import(dto: ImportStudentsDto, tenantId: string): Promise<ImportReport> {
    const rows = await this.parseWorkbook(dto.contentBase64);
    const report: ImportReport = { created: 0, duplicates: 0, errors: [] };
    const seenInFile = new Set<string>();

    for (const { rowNumber, values } of rows) {
      let studentDto: CreateStudentDto;
      try {
        studentDto = this.toStudentDto(values);
      } catch (error) {
        report.errors.push({ row: rowNumber, message: (error as Error).message });
        continue;
      }

      const fileKey = [
        normalizeName(studentDto.firstName),
        normalizeName(studentDto.lastName),
        studentDto.dateOfBirth,
      ].join("|");
      if (seenInFile.has(fileKey)) {
        report.duplicates += 1;
        continue;
      }
      seenInFile.add(fileKey);

      try {
        await this.studentsService.create(studentDto, tenantId);
        report.created += 1;
      } catch (error) {
        if (error instanceof ConflictException) {
          report.duplicates += 1;
        } else {
          report.errors.push({ row: rowNumber, message: (error as Error).message });
        }
      }
    }

    return report;
  }

  private async parseWorkbook(
    contentBase64: string,
  ): Promise<Array<{ rowNumber: number; values: Record<string, unknown> }>> {
    let worksheet: ExcelJS.Worksheet | undefined;
    try {
      const workbook = new ExcelJS.Workbook();
      // Cast : le type Buffer déclaré par exceljs est incompatible avec celui de @types/node 22.
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
    if (!Array.from(columns.values()).some((field) => field === "lastName")) {
      throw new BadRequestException(
        "En-têtes non reconnues : colonnes attendues « Nom », « Prénom », « Date de naissance », « Sexe »",
      );
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

  private toStudentDto(values: Record<string, unknown>): CreateStudentDto {
    const lastName = this.asText(values.lastName);
    const firstName = this.asText(values.firstName);
    if (!lastName || !firstName) {
      throw new Error("Nom et prénom sont requis");
    }

    const dateOfBirth = this.parseDate(values.dateOfBirth);
    if (!dateOfBirth) {
      throw new Error("Date de naissance manquante ou invalide (formats acceptés : JJ/MM/AAAA, AAAA-MM-JJ)");
    }

    const gender = GENDER_ALIASES[normalizeName(this.asText(values.gender) ?? "")];
    if (!gender) {
      throw new Error("Sexe manquant ou invalide (M/F attendu)");
    }

    return {
      firstName,
      lastName,
      dateOfBirth,
      gender,
      placeOfBirth: this.asText(values.placeOfBirth),
      nationality: this.asText(values.nationality),
      bloodGroup: this.asText(values.bloodGroup),
      allergies: this.asText(values.allergies),
      emergencyContact: this.asText(values.emergencyContact),
    };
  }

  private asText(value: unknown): string | undefined {
    if (value === undefined || value === null) {
      return undefined;
    }
    const text = String(value).trim();
    return text === "" ? undefined : text;
  }

  /** Rend une date ISO (AAAA-MM-JJ) à partir d'une cellule Excel Date, ISO ou JJ/MM/AAAA. */
  private parseDate(value: unknown): string | undefined {
    if (value instanceof Date && !Number.isNaN(value.getTime())) {
      return value.toISOString().slice(0, 10);
    }
    const text = this.asText(value);
    if (!text) {
      return undefined;
    }
    const french = /^(\d{1,2})\/(\d{1,2})\/(\d{4})$/.exec(text);
    if (french) {
      const [, day, month, year] = french;
      return `${year}-${month.padStart(2, "0")}-${day.padStart(2, "0")}`;
    }
    const iso = /^(\d{4})-(\d{2})-(\d{2})/.exec(text);
    if (iso) {
      return text.slice(0, 10);
    }
    return undefined;
  }
}

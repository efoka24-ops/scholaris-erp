import { BadRequestException, ConflictException, Injectable } from "@nestjs/common";
import { Gender, ParentRelationship } from "@scholaris/prisma";
import * as ExcelJS from "exceljs";
import { PrismaService } from "../../prisma/prisma.service";
import { EnrollmentsService } from "../enrollments/enrollments.service";
import { StudentsService, normalizeName } from "./students.service";
import { CreateStudentDto } from "./dto/create-student.dto";
import { CreateParentDto } from "./dto/create-parent.dto";
import { ImportStudentsDto } from "./dto/import-students.dto";

export interface ImportRowError {
  row: number;
  message: string;
}

export interface ImportReport {
  created: number;
  duplicates: number;
  enrolled: number;
  errors: ImportRowError[];
}

export interface ValidationRow {
  row: number;
  name: string;
  status: "valid" | "warning" | "error";
  messages: string[];
}

export interface ValidationReport {
  total: number;
  valid: number;
  warnings: number;
  errors: number;
  rows: ValidationRow[];
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
  // Classe (inscription automatique)
  classe: "classCode",
  "classe code": "classCode",
  "code classe": "classCode",
  class: "classCode",
  // Parent / tuteur
  "parent nom": "parentLastName",
  "nom parent": "parentLastName",
  "parent prenom": "parentFirstName",
  "prenom parent": "parentFirstName",
  "parent telephone": "parentPhone",
  "telephone parent": "parentPhone",
  "parent whatsapp": "parentWhatsapp",
  "parent email": "parentEmail",
  "parent profession": "parentProfession",
  "parent relation": "parentRelation",
  relation: "parentRelation",
};

const RELATION_ALIASES: Record<string, ParentRelationship> = {
  pere: ParentRelationship.FATHER,
  father: ParentRelationship.FATHER,
  papa: ParentRelationship.FATHER,
  mere: ParentRelationship.MOTHER,
  mother: ParentRelationship.MOTHER,
  maman: ParentRelationship.MOTHER,
  tuteur: ParentRelationship.GUARDIAN,
  guardian: ParentRelationship.GUARDIAN,
  tutrice: ParentRelationship.GUARDIAN,
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
  constructor(
    private readonly studentsService: StudentsService,
    private readonly enrollmentsService: EnrollmentsService,
    private readonly prisma: PrismaService,
  ) {}

  /**
   * Validation à blanc (dry-run) : parse le classeur et classe chaque ligne
   * (valide / avertissement / erreur) SANS rien créer. Sert au rapport de
   * pré-import affiché avant confirmation.
   */
  async validate(dto: ImportStudentsDto, tenantId: string): Promise<ValidationReport> {
    const rows = await this.parseWorkbook(dto.contentBase64);
    // Codes de classe existants (pour vérifier l'existence + capacité).
    const classrooms = await this.prisma.classRoom.findMany({
      select: { id: true, code: true, name: true, capacity: true },
    });
    const classByCode = new Map(classrooms.map((c) => [normalizeName(c.code), c]));

    const seenInFile = new Set<string>();
    const out: ValidationRow[] = [];

    for (const { rowNumber, values } of rows) {
      const messages: string[] = [];
      let status: ValidationRow["status"] = "valid";
      let name = "";
      try {
        const parsed = this.parseRow(values);
        name = `${parsed.dto.lastName} ${parsed.dto.firstName}`;
        // Doublon dans le fichier.
        const key = [normalizeName(parsed.dto.firstName), normalizeName(parsed.dto.lastName), parsed.dto.dateOfBirth].join("|");
        if (seenInFile.has(key)) {
          status = "warning";
          messages.push("Doublon dans le fichier");
        }
        seenInFile.add(key);
        // Classe.
        if (parsed.classCode) {
          const cls = classByCode.get(normalizeName(parsed.classCode));
          if (!cls) {
            status = "warning";
            messages.push(`Classe « ${parsed.classCode} » introuvable (élève créé sans inscription)`);
          }
        } else {
          if (status === "valid") status = "warning";
          messages.push("Aucune classe indiquée (élève non inscrit)");
        }
        // Parent sans téléphone.
        if (parsed.parent && !parsed.parent.phone) {
          if (status === "valid") status = "warning";
          messages.push("Parent sans téléphone");
        }
      } catch (error) {
        status = "error";
        messages.push((error as Error).message);
      }
      out.push({ row: rowNumber, name, status, messages });
    }

    return {
      total: out.length,
      valid: out.filter((r) => r.status === "valid").length,
      warnings: out.filter((r) => r.status === "warning").length,
      errors: out.filter((r) => r.status === "error").length,
      rows: out,
    };
  }

  /** Génère le template Excel d'import (en-têtes obligatoires + parents + classe). */
  async generateTemplate(): Promise<Buffer> {
    const wb = new ExcelJS.Workbook();
    const ws = wb.addWorksheet("Élèves");
    ws.columns = [
      { header: "Nom", key: "nom", width: 20 },
      { header: "Prénom", key: "prenom", width: 20 },
      { header: "Sexe", key: "sexe", width: 8 },
      { header: "Date_naissance", key: "dob", width: 16 },
      { header: "Lieu_naissance", key: "lieu", width: 18 },
      { header: "Nationalité", key: "nat", width: 16 },
      { header: "Classe_code", key: "classe", width: 14 },
      { header: "Parent_nom", key: "pnom", width: 18 },
      { header: "Parent_prénom", key: "pprenom", width: 18 },
      { header: "Parent_téléphone", key: "ptel", width: 18 },
      { header: "Parent_email", key: "pmail", width: 22 },
      { header: "Parent_relation", key: "prel", width: 14 },
    ];
    ws.getRow(1).font = { bold: true };
    ws.addRow(["MBALLA", "Jean", "M", "12/03/2012", "Yaoundé", "Camerounaise", "6EME-A", "MBALLA", "Paul", "+237690000001", "paul@example.com", "Père"]);
    const buf = await wb.xlsx.writeBuffer();
    return Buffer.from(buf as ArrayBuffer);
  }

  /**
   * Importe un classeur Excel (première feuille, ligne 1 = en-têtes).
   * Chaque ligne valide passe par StudentsService.create (matricule généré,
   * détection de doublons) : un doublon est compté, pas créé.
   */
  async import(dto: ImportStudentsDto, tenantId: string): Promise<ImportReport> {
    const rows = await this.parseWorkbook(dto.contentBase64);
    const report: ImportReport = { created: 0, duplicates: 0, enrolled: 0, errors: [] };
    const seenInFile = new Set<string>();

    // Résolution des classes + année académique active (inscription automatique).
    const classrooms = await this.prisma.classRoom.findMany({ select: { id: true, code: true } });
    const classByCode = new Map(classrooms.map((c) => [normalizeName(c.code), c.id]));
    const activeYear = await this.prisma.academicYear.findFirst({ where: { status: "ACTIVE" } });

    for (const { rowNumber, values } of rows) {
      let parsed: { dto: CreateStudentDto; classCode?: string; parent?: CreateParentDto };
      try {
        parsed = this.parseRow(values);
      } catch (error) {
        report.errors.push({ row: rowNumber, message: (error as Error).message });
        continue;
      }
      const studentDto = parsed.dto;
      if (parsed.parent) studentDto.parents = [parsed.parent];

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
        const student = await this.studentsService.create(studentDto, tenantId);
        report.created += 1;

        // Inscription automatique si un code classe valide est fourni.
        if (parsed.classCode && activeYear) {
          const classroomId = classByCode.get(normalizeName(parsed.classCode));
          if (classroomId) {
            try {
              await this.enrollmentsService.enroll(
                { studentId: (student as any).id, classroomId, academicYearId: activeYear.id } as any,
                tenantId,
              );
              report.enrolled += 1;
            } catch (error) {
              report.errors.push({
                row: rowNumber,
                message: `Élève créé mais non inscrit : ${(error as Error).message}`,
              });
            }
          }
        }
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

  private parseRow(values: Record<string, unknown>): {
    dto: CreateStudentDto;
    classCode?: string;
    parent?: CreateParentDto;
  } {
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

    const dto: CreateStudentDto = {
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

    // Parent (créé seulement si un nom ou un téléphone est présent).
    let parent: CreateParentDto | undefined;
    const parentLastName = this.asText(values.parentLastName);
    const parentPhone = this.asText(values.parentPhone);
    if (parentLastName || parentPhone) {
      parent = {
        firstName: this.asText(values.parentFirstName),
        lastName: parentLastName,
        phone: parentPhone,
        whatsapp: this.asText(values.parentWhatsapp),
        email: this.asText(values.parentEmail),
        profession: this.asText(values.parentProfession),
        relationship:
          RELATION_ALIASES[normalizeName(this.asText(values.parentRelation) ?? "")] ??
          ParentRelationship.GUARDIAN,
      } as CreateParentDto;
    }

    return { dto, classCode: this.asText(values.classCode), parent };
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

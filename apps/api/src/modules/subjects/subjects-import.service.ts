import { BadRequestException, Injectable } from "@nestjs/common";
import { SubjectCategory } from "@scholaris/prisma";
import { SubjectImportReport, SubjectImportRowError } from "@scholaris/shared";
import * as ExcelJS from "exceljs";
import { PrismaService } from "../../prisma/prisma.service";
import { SubjectsService } from "./subjects.service";
import { CreateSubjectDto } from "./dto/create-subject.dto";

/** Ligne parsée avec son numéro de ligne Excel d'origine (pour le rapport). */
interface ParsedRow {
  row: number;
  dto: CreateSubjectDto;
}

// En-têtes attendues (insensibles à la casse et aux accents) → clé interne.
const HEADER_ALIASES: Record<string, keyof RawRow> = {
  code: "code",
  nom: "name",
  name: "name",
  coefficient: "coefficient",
  coef: "coefficient",
  heures: "weeklyHours",
  "heures hebdo": "weeklyHours",
  "heures hebdomadaires": "weeklyHours",
  categorie: "category",
  category: "category",
};

interface RawRow {
  code?: string;
  name?: string;
  coefficient?: string;
  weeklyHours?: string;
  category?: string;
}

// Libellés français (et valeurs d'enum) acceptés dans la colonne catégorie.
const CATEGORY_ALIASES: Record<string, SubjectCategory> = {
  litteraire: SubjectCategory.LITERARY,
  literary: SubjectCategory.LITERARY,
  scientifique: SubjectCategory.SCIENTIFIC,
  scientific: SubjectCategory.SCIENTIFIC,
  technique: SubjectCategory.TECHNICAL,
  technical: SubjectCategory.TECHNICAL,
  langue: SubjectCategory.LANGUAGE,
  langues: SubjectCategory.LANGUAGE,
  language: SubjectCategory.LANGUAGE,
  sport: SubjectCategory.SPORTS,
  sports: SubjectCategory.SPORTS,
};

function normalize(text: string): string {
  return text
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .trim()
    .toLowerCase();
}

@Injectable()
export class SubjectsImportService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly subjectsService: SubjectsService,
  ) {}

  /**
   * Importe un référentiel de matières depuis un classeur Excel (colonnes :
   * code, nom, coefficient, heures, catégorie). Chaque ligne invalide est
   * consignée dans le rapport sans interrompre le traitement des suivantes.
   * En mode dryRun, rien n'est créé : le rapport sert de prévisualisation.
   */
  async import(fileBase64: string, tenantId: string, dryRun = false): Promise<SubjectImportReport> {
    const { rows, errors } = await this.parse(fileBase64);

    // Doublons de code au sein du fichier lui-même.
    const seenCodes = new Map<string, number>();
    const uniqueRows: ParsedRow[] = [];
    for (const parsed of rows) {
      const codeKey = parsed.dto.code.toUpperCase();
      const firstRow = seenCodes.get(codeKey);
      if (firstRow !== undefined) {
        errors.push({ row: parsed.row, message: `Code "${parsed.dto.code}" en doublon avec la ligne ${firstRow}` });
      } else {
        seenCodes.set(codeKey, parsed.row);
        uniqueRows.push(parsed);
      }
    }

    // Codes déjà présents dans le référentiel du tenant (scoping via middleware).
    const existing = await this.prisma.subject.findMany({
      where: { deletedAt: null, code: { in: uniqueRows.map((parsed) => parsed.dto.code) } },
      select: { code: true },
    });
    const existingCodes = new Set(existing.map((subject) => subject.code.toUpperCase()));

    const importable: ParsedRow[] = [];
    for (const parsed of uniqueRows) {
      if (existingCodes.has(parsed.dto.code.toUpperCase())) {
        errors.push({ row: parsed.row, message: `Une matière avec le code "${parsed.dto.code}" existe déjà` });
      } else {
        importable.push(parsed);
      }
    }

    let created = 0;
    if (!dryRun) {
      for (const parsed of importable) {
        try {
          await this.subjectsService.create(parsed.dto, tenantId);
          created += 1;
        } catch (error) {
          errors.push({ row: parsed.row, message: error instanceof Error ? error.message : "Erreur inconnue" });
        }
      }
    }

    errors.sort((a, b) => a.row - b.row);
    return {
      created,
      errors,
      rows: importable.map((parsed) => ({
        ...parsed.dto,
        isEliminatory: parsed.dto.isEliminatory ?? false,
        levelIds: parsed.dto.levelIds ?? [],
      })),
    };
  }

  /** Parse le classeur : première feuille, ligne 1 = en-têtes. */
  private async parse(fileBase64: string): Promise<{ rows: ParsedRow[]; errors: SubjectImportRowError[] }> {
    const workbook = new ExcelJS.Workbook();
    try {
      // Cast nécessaire : les typages Buffer d'exceljs sont en retard sur @types/node ≥ 22.
      await workbook.xlsx.load(Buffer.from(fileBase64, "base64") as unknown as ExcelJS.Buffer);
    } catch {
      throw new BadRequestException("Fichier illisible : un classeur Excel (.xlsx) est attendu");
    }

    const worksheet = workbook.worksheets[0];
    if (!worksheet) {
      throw new BadRequestException("Le classeur ne contient aucune feuille");
    }

    // Ligne d'en-têtes → correspondance colonne → champ.
    const columns = new Map<number, keyof RawRow>();
    worksheet.getRow(1).eachCell((cell, colNumber) => {
      const field = HEADER_ALIASES[normalize(this.cellText(cell))];
      if (field) {
        columns.set(colNumber, field);
      }
    });

    const requiredFields: Array<keyof RawRow> = ["code", "name", "coefficient", "weeklyHours", "category"];
    const mappedFields = new Set(columns.values());
    const missing = requiredFields.filter((field) => !mappedFields.has(field));
    if (missing.length > 0) {
      throw new BadRequestException(
        "Colonnes manquantes dans le fichier : les en-têtes attendues sont code, nom, coefficient, heures, catégorie",
      );
    }

    const rows: ParsedRow[] = [];
    const errors: SubjectImportRowError[] = [];

    worksheet.eachRow((row, rowNumber) => {
      if (rowNumber === 1) return; // en-têtes

      const raw: RawRow = {};
      row.eachCell({ includeEmpty: false }, (cell, colNumber) => {
        const field = columns.get(colNumber);
        if (field) {
          raw[field] = this.cellText(cell);
        }
      });

      // Ligne entièrement vide : ignorée silencieusement.
      if (!raw.code && !raw.name && !raw.coefficient && !raw.weeklyHours && !raw.category) {
        return;
      }

      const rowErrors: string[] = [];

      const code = raw.code?.trim();
      if (!code) rowErrors.push("le code est requis");

      const name = raw.name?.trim();
      if (!name) rowErrors.push("le nom est requis");

      const coefficient = Number(String(raw.coefficient ?? "").replace(",", "."));
      if (!raw.coefficient || Number.isNaN(coefficient) || coefficient <= 0) {
        rowErrors.push("le coefficient doit être un nombre strictement positif");
      }

      const weeklyHours = Number(raw.weeklyHours);
      if (!raw.weeklyHours || !Number.isInteger(weeklyHours) || weeklyHours <= 0) {
        rowErrors.push("les heures hebdomadaires doivent être un entier strictement positif");
      }

      const category = raw.category ? CATEGORY_ALIASES[normalize(raw.category)] : undefined;
      if (!category) {
        rowErrors.push(
          `catégorie "${raw.category ?? ""}" inconnue (attendu : littéraire, scientifique, technique, langue ou sport)`,
        );
      }

      if (rowErrors.length > 0 || !code || !name || !category) {
        errors.push({ row: rowNumber, message: `Ligne ${rowNumber} : ${rowErrors.join(" ; ")}` });
        return;
      }

      rows.push({
        row: rowNumber,
        dto: { code, name, coefficient, weeklyHours, category, isEliminatory: false, levelIds: [] },
      });
    });

    return { rows, errors };
  }

  private cellText(cell: ExcelJS.Cell): string {
    const value = cell.value;
    if (value === null || value === undefined) return "";
    if (typeof value === "object") {
      // Cellules riches (formules, texte enrichi…) : on prend le texte affiché.
      return String(cell.text ?? "");
    }
    return String(value);
  }
}

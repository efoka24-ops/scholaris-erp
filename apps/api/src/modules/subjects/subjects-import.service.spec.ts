import { BadRequestException } from "@nestjs/common";
import { SubjectCategory } from "@scholaris/prisma";
import * as ExcelJS from "exceljs";
import { PrismaService } from "../../prisma/prisma.service";
import { SubjectsImportService } from "./subjects-import.service";
import { SubjectsService } from "./subjects.service";

async function workbookBase64(rows: Array<Array<string | number>>, headers = ["Code", "Nom", "Coefficient", "Heures", "Catégorie"]) {
  const workbook = new ExcelJS.Workbook();
  const sheet = workbook.addWorksheet("Matières");
  sheet.addRow(headers);
  rows.forEach((row) => sheet.addRow(row));
  const buffer = await workbook.xlsx.writeBuffer();
  return Buffer.from(buffer).toString("base64");
}

describe("SubjectsImportService", () => {
  let service: SubjectsImportService;
  let prisma: { subject: { findMany: jest.Mock } };
  let subjectsService: { create: jest.Mock };

  beforeEach(() => {
    prisma = { subject: { findMany: jest.fn().mockResolvedValue([]) } };
    subjectsService = { create: jest.fn().mockResolvedValue({ id: "subject-1" }) };
    service = new SubjectsImportService(
      prisma as unknown as PrismaService,
      subjectsService as unknown as SubjectsService,
    );
  });

  it("importe des lignes valides (libellés de catégorie en français, accents et casse tolérés)", async () => {
    const file = await workbookBase64([
      ["MATH", "Mathématiques", 4, 5, "Scientifique"],
      ["FRAN", "Français", 5, 6, "LITTÉRAIRE"],
      ["ANG", "Anglais", 3, 3, "langue"],
    ]);

    const report = await service.import(file, "tenant-1");

    expect(report.created).toBe(3);
    expect(report.errors).toHaveLength(0);
    expect(subjectsService.create).toHaveBeenCalledWith(
      expect.objectContaining({ code: "MATH", name: "Mathématiques", coefficient: 4, weeklyHours: 5, category: SubjectCategory.SCIENTIFIC }),
      "tenant-1",
    );
    expect(subjectsService.create).toHaveBeenCalledWith(
      expect.objectContaining({ code: "FRAN", category: SubjectCategory.LITERARY }),
      "tenant-1",
    );
    expect(subjectsService.create).toHaveBeenCalledWith(
      expect.objectContaining({ code: "ANG", category: SubjectCategory.LANGUAGE }),
      "tenant-1",
    );
  });

  it("consigne les lignes invalides dans le rapport sans bloquer les lignes valides", async () => {
    const file = await workbookBase64([
      ["MATH", "Mathématiques", 4, 5, "Scientifique"],
      ["", "Sans code", 2, 2, "Technique"], // code manquant
      ["PHY", "Physique", -1, 4, "Scientifique"], // coefficient négatif
      ["CHIM", "Chimie", 2, 3, "Alchimie"], // catégorie inconnue
    ]);

    const report = await service.import(file, "tenant-1");

    expect(report.created).toBe(1);
    expect(report.errors).toHaveLength(3);
    expect(report.errors.map((error) => error.row)).toEqual([3, 4, 5]);
    expect(report.errors[1].message).toContain("coefficient");
    expect(report.errors[2].message).toContain("catégorie");
  });

  it("signale les doublons de code dans le fichier et avec le référentiel existant", async () => {
    prisma.subject.findMany.mockResolvedValue([{ code: "MATH" }]);
    const file = await workbookBase64([
      ["MATH", "Mathématiques", 4, 5, "Scientifique"], // existe déjà en base
      ["SVT", "SVT", 2, 2, "Scientifique"],
      ["SVT", "SVT bis", 2, 2, "Scientifique"], // doublon interne au fichier
    ]);

    const report = await service.import(file, "tenant-1");

    expect(report.created).toBe(1);
    expect(report.errors).toHaveLength(2);
    expect(report.errors[0].message).toContain("existe déjà");
    expect(report.errors[1].message).toContain("doublon");
  });

  it("en mode dryRun, ne crée rien et renvoie les lignes valides pour prévisualisation", async () => {
    const file = await workbookBase64([
      ["MATH", "Mathématiques", 4, 5, "Scientifique"],
      ["", "Sans code", 2, 2, "Technique"],
    ]);

    const report = await service.import(file, "tenant-1", true);

    expect(subjectsService.create).not.toHaveBeenCalled();
    expect(report.created).toBe(0);
    expect(report.rows).toHaveLength(1);
    expect(report.rows[0]).toEqual(expect.objectContaining({ code: "MATH" }));
    expect(report.errors).toHaveLength(1);
  });

  it("rejette un fichier sans les colonnes attendues", async () => {
    const file = await workbookBase64([["MATH", "Mathématiques"]], ["Code", "Libellé quelconque"]);

    await expect(service.import(file, "tenant-1")).rejects.toThrow(BadRequestException);
  });

  it("rejette un contenu qui n'est pas un classeur Excel", async () => {
    const file = Buffer.from("ceci n'est pas un xlsx").toString("base64");

    await expect(service.import(file, "tenant-1")).rejects.toThrow(BadRequestException);
  });
});

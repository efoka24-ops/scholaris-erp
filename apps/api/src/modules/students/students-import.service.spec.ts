import { BadRequestException, ConflictException } from "@nestjs/common";
import { Gender } from "@scholaris/prisma";
import * as ExcelJS from "exceljs";
import { StudentsService } from "./students.service";
import { StudentsImportService } from "./students-import.service";

async function workbookBase64(rows: Array<Array<string | Date>>): Promise<string> {
  const workbook = new ExcelJS.Workbook();
  const sheet = workbook.addWorksheet("Élèves");
  sheet.addRow(["Nom", "Prénom", "Date de naissance", "Sexe", "Lieu de naissance"]);
  rows.forEach((row) => sheet.addRow(row));
  const buffer = await workbook.xlsx.writeBuffer();
  return Buffer.from(buffer).toString("base64");
}

describe("StudentsImportService", () => {
  let service: StudentsImportService;
  let studentsService: { create: jest.Mock };

  beforeEach(() => {
    studentsService = { create: jest.fn().mockResolvedValue({ id: "student-1" }) };
    service = new StudentsImportService(studentsService as unknown as StudentsService);
  });

  it("crée un élève par ligne valide et rapporte {created, duplicates, errors}", async () => {
    const contentBase64 = await workbookBase64([
      ["Atangana", "Paul", "21/04/2013", "M", "Yaoundé"],
      ["Ngo Bassa", "Aminata", "2012-09-03", "F", "Douala"],
    ]);

    const report = await service.import({ contentBase64 }, "tenant-1");

    expect(report).toEqual({ created: 2, duplicates: 0, errors: [] });
    expect(studentsService.create).toHaveBeenCalledTimes(2);
    expect(studentsService.create).toHaveBeenCalledWith(
      expect.objectContaining({
        lastName: "Atangana",
        firstName: "Paul",
        dateOfBirth: "2013-04-21",
        gender: Gender.MALE,
        placeOfBirth: "Yaoundé",
      }),
      "tenant-1",
    );
  });

  it("compte en doublon une ligne rejetée en 409 par la détection de doublons", async () => {
    studentsService.create
      .mockResolvedValueOnce({ id: "student-1" })
      .mockRejectedValueOnce(new ConflictException("doublon"));
    const contentBase64 = await workbookBase64([
      ["Atangana", "Paul", "21/04/2013", "M", "Yaoundé"],
      ["Mbia", "Éloïse", "15/01/2012", "F", "Bafoussam"],
    ]);

    const report = await service.import({ contentBase64 }, "tenant-1");

    expect(report).toEqual({ created: 1, duplicates: 1, errors: [] });
  });

  it("compte en doublon une ligne répétée dans le fichier lui-même (sans rappeler create)", async () => {
    const contentBase64 = await workbookBase64([
      ["Atangana", "Paul", "21/04/2013", "M", "Yaoundé"],
      ["ATANGANA", "paul", "21/04/2013", "M", "Yaoundé"],
    ]);

    const report = await service.import({ contentBase64 }, "tenant-1");

    expect(report).toEqual({ created: 1, duplicates: 1, errors: [] });
    expect(studentsService.create).toHaveBeenCalledTimes(1);
  });

  it("rapporte en erreur (avec le numéro de ligne) les lignes invalides sans bloquer les autres", async () => {
    const contentBase64 = await workbookBase64([
      ["", "Paul", "21/04/2013", "M", "Yaoundé"],
      ["Mbia", "Éloïse", "pas une date", "F", "Bafoussam"],
      ["Fouda", "Simon", "01/09/2011", "X", "Ebolowa"],
      ["Owona", "Colette", "12/12/2012", "F", "Kribi"],
    ]);

    const report = await service.import({ contentBase64 }, "tenant-1");

    expect(report.created).toBe(1);
    expect(report.duplicates).toBe(0);
    expect(report.errors).toEqual([
      { row: 2, message: expect.stringMatching(/Nom et prénom/) },
      { row: 3, message: expect.stringMatching(/[Dd]ate de naissance/) },
      { row: 4, message: expect.stringMatching(/[Ss]exe/) },
    ]);
  });

  it("rejette un fichier illisible", async () => {
    await expect(service.import({ contentBase64: "cGFzIHVuIHhsc3g=" }, "tenant-1")).rejects.toThrow(
      BadRequestException,
    );
  });
});

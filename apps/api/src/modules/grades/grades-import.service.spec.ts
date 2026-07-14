import { BadRequestException } from "@nestjs/common";
import { GradeType } from "@scholaris/prisma";
import * as ExcelJS from "exceljs";
import { PrismaService } from "../../prisma/prisma.service";
import type { AuthenticatedUser } from "../auth/jwt-payload.interface";
import { GradesImportService } from "./grades-import.service";
import { GradesService } from "./grades.service";

async function workbookBase64(rows: Array<Array<string | number>>): Promise<string> {
  const workbook = new ExcelJS.Workbook();
  const sheet = workbook.addWorksheet("Notes");
  sheet.addRow(["Matricule", "Note", "Absent", "Justifié"]);
  rows.forEach((row) => sheet.addRow(row));
  const buffer = await workbook.xlsx.writeBuffer();
  return Buffer.from(buffer).toString("base64");
}

describe("GradesImportService", () => {
  let service: GradesImportService;
  let prisma: { student: { findFirst: jest.Mock } };
  let gradesService: { batchCreate: jest.Mock };
  const user: AuthenticatedUser = { userId: "teacher-1", tenantId: "tenant-1", email: "t@x.com", permissions: [] };

  const baseDto = {
    contentBase64: "",
    classroomId: "class-1",
    subjectId: "subject-1",
    periodId: "period-1",
    type: GradeType.TEST,
    maxValue: 20,
  };

  beforeEach(() => {
    prisma = { student: { findFirst: jest.fn() } };
    gradesService = { batchCreate: jest.fn().mockResolvedValue([{ id: "grade-1" }, { id: "grade-2" }]) };
    service = new GradesImportService(prisma as unknown as PrismaService, gradesService as unknown as GradesService);
  });

  it("importe les notes valides et délègue à GradesService.batchCreate", async () => {
    prisma.student.findFirst.mockImplementation(({ where }: { where: { matricule: string } }) =>
      Promise.resolve(where.matricule === "LBD/2026/0001" ? { id: "student-1" } : null),
    );
    const contentBase64 = await workbookBase64([["LBD/2026/0001", 15]]);

    const report = await service.import({ ...baseDto, contentBase64 }, user);

    expect(report).toEqual({ created: 2, skipped: 0, errors: [] });
    expect(gradesService.batchCreate).toHaveBeenCalledWith(
      expect.objectContaining({
        classroomId: "class-1",
        subjectId: "subject-1",
        periodId: "period-1",
        entries: [expect.objectContaining({ studentId: "student-1", value: 15 })],
      }),
      user,
    );
  });

  it("rapporte en erreur un matricule introuvable", async () => {
    prisma.student.findFirst.mockResolvedValue(null);
    const contentBase64 = await workbookBase64([["INCONNU", 12]]);

    const report = await service.import({ ...baseDto, contentBase64 }, user);

    expect(report.errors).toEqual([{ row: 2, message: expect.stringMatching(/introuvable/) }]);
    expect(gradesService.batchCreate).not.toHaveBeenCalled();
  });

  it("marque une entrée absente (colonne Absent) sans exiger de note", async () => {
    prisma.student.findFirst.mockResolvedValue({ id: "student-1" });
    const contentBase64 = await workbookBase64([["LBD/2026/0001", "", "oui", "oui"]]);

    await service.import({ ...baseDto, contentBase64 }, user);

    expect(gradesService.batchCreate).toHaveBeenCalledWith(
      expect.objectContaining({ entries: [expect.objectContaining({ studentId: "student-1", isAbsent: true, isJustified: true })] }),
      user,
    );
  });

  it("rapporte en erreur une note manquante pour un élève non absent", async () => {
    prisma.student.findFirst.mockResolvedValue({ id: "student-1" });
    const contentBase64 = await workbookBase64([["LBD/2026/0001", ""]]);

    const report = await service.import({ ...baseDto, contentBase64 }, user);

    expect(report.errors).toEqual([{ row: 2, message: expect.stringMatching(/[Nn]ote manquante/) }]);
    expect(gradesService.batchCreate).not.toHaveBeenCalled();
  });

  it("rejette un fichier illisible", async () => {
    await expect(service.import({ ...baseDto, contentBase64: "cGFzIHVuIHhsc3g=" }, user)).rejects.toThrow(BadRequestException);
  });

  it("propage les erreurs de GradesService.batchCreate (ex: barème, période fermée) dans le rapport", async () => {
    prisma.student.findFirst.mockResolvedValue({ id: "student-1" });
    gradesService.batchCreate.mockRejectedValue(new BadRequestException("Barème dépassé"));
    const contentBase64 = await workbookBase64([["LBD/2026/0001", 15]]);

    const report = await service.import({ ...baseDto, contentBase64 }, user);

    expect(report.created).toBe(0);
    expect(report.errors).toEqual([{ row: 0, message: expect.stringMatching(/Barème/) }]);
  });
});

import {
  BadRequestException,
  ConflictException,
  Injectable,
  NotFoundException,
} from "@nestjs/common";
import { OfficialExam, Prisma, ExamRegistrationStatus } from "@scholaris/prisma";
import { PrismaService } from "../../prisma/prisma.service";
import { AuditService } from "../audit/audit.service";
import { CreateExamDto } from "./dto/create-exam.dto";
import { RegisterBatchDto, RegisterCandidateDto } from "./dto/register-candidate.dto";
import { SubmitResultsDto } from "./dto/submit-results.dto";

// Seuils de mention MINESEC (moyenne /20). Ordre décroissant pour la résolution.
const MENTION_THRESHOLDS: Array<{ min: number; label: string }> = [
  { min: 18, label: "Excellent" },
  { min: 16, label: "Très Bien" },
  { min: 14, label: "Bien" },
  { min: 12, label: "Assez Bien" },
  { min: 10, label: "Passable" },
];

interface EligibilityCheck {
  eligible: boolean;
  reason?: string;
}

@Injectable()
export class ExamsService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly audit: AuditService,
  ) {}

  // --- Examens ------------------------------------------------------------

  async create(dto: CreateExamDto, tenantId: string): Promise<OfficialExam> {
    const existing = await this.prisma.officialExam.findFirst({
      where: { code: dto.code, name: dto.name, academicYearId: dto.academicYearId },
    });
    if (existing) {
      throw new ConflictException(`Un examen « ${dto.name} » existe déjà pour cette année`);
    }
    if (new Date(dto.registrationEnd) < new Date(dto.registrationStart)) {
      throw new BadRequestException("La fin d'inscription doit être postérieure au début");
    }

    const exam = await this.prisma.officialExam.create({
      data: {
        tenantId,
        name: dto.name,
        code: dto.code,
        levelId: dto.levelId ?? null,
        academicYearId: dto.academicYearId,
        registrationStart: new Date(dto.registrationStart),
        registrationEnd: new Date(dto.registrationEnd),
        examStart: dto.examStart ? new Date(dto.examStart) : null,
        examEnd: dto.examEnd ? new Date(dto.examEnd) : null,
        feeAmount: dto.feeAmount ?? 0,
        minAge: dto.minAge ?? null,
        maxAge: dto.maxAge ?? null,
        requiredSequences: dto.requiredSequences ?? 0,
        passMark: dto.passMark ?? 10,
        oralMinMark: dto.oralMinMark ?? null,
        isOfficial: dto.isOfficial ?? true,
        ...(dto.config !== undefined && dto.config !== null
          ? { configJson: dto.config as Prisma.InputJsonValue }
          : {}),
      },
    });

    await this.audit.log({
      action: "create",
      resource: "exams",
      resourceId: exam.id,
      newValue: { name: exam.name, code: exam.code },
    });
    return exam;
  }

  async findAll(): Promise<OfficialExam[]> {
    return this.prisma.officialExam.findMany({
      where: { deletedAt: null },
      orderBy: { registrationStart: "desc" },
    });
  }

  async findOne(id: string): Promise<OfficialExam> {
    const exam = await this.prisma.officialExam.findFirst({ where: { id, deletedAt: null } });
    if (!exam) {
      throw new NotFoundException("Examen introuvable");
    }
    return exam;
  }

  // --- Inscriptions -------------------------------------------------------

  /** Vérifie les conditions d'éligibilité d'un élève à un examen. */
  private async checkEligibility(
    exam: OfficialExam,
    studentId: string,
  ): Promise<EligibilityCheck> {
    const student = await this.prisma.student.findFirst({ where: { id: studentId } });
    if (!student) {
      return { eligible: false, reason: "Élève introuvable" };
    }

    // Âge (au 1er janvier de l'année d'examen, approximé par l'année civile courante).
    if (exam.maxAge != null || exam.minAge != null) {
      const age = this.ageInYears(student.dateOfBirth, exam.examStart ?? exam.registrationEnd);
      if (exam.maxAge != null && age > exam.maxAge) {
        return { eligible: false, reason: `Âge ${age} > ${exam.maxAge} ans` };
      }
      if (exam.minAge != null && age < exam.minAge) {
        return { eligible: false, reason: `Âge ${age} < ${exam.minAge} ans` };
      }
    }

    // Inscription active dans l'année académique de l'examen (et au bon niveau si défini).
    const enrollment = await this.prisma.enrollment.findFirst({
      where: {
        studentId,
        academicYearId: exam.academicYearId,
        ...(exam.levelId ? { classroom: { levelId: exam.levelId } } : {}),
      },
    });
    if (!enrollment) {
      return {
        eligible: false,
        reason: exam.levelId
          ? "Non inscrit dans le niveau requis pour cette année"
          : "Non inscrit pour cette année académique",
      };
    }

    // Nombre de séquences composées (périodes distinctes où l'élève a des notes).
    if (exam.requiredSequences > 0) {
      const periods = await this.prisma.grade.findMany({
        where: { studentId },
        distinct: ["periodId"],
        select: { periodId: true },
      });
      if (periods.length < exam.requiredSequences) {
        return {
          eligible: false,
          reason: `${periods.length}/${exam.requiredSequences} séquences composées`,
        };
      }
    }

    return { eligible: true };
  }

  private ageInYears(dob: Date, at: Date): number {
    let age = at.getFullYear() - dob.getFullYear();
    const m = at.getMonth() - dob.getMonth();
    if (m < 0 || (m === 0 && at.getDate() < dob.getDate())) age--;
    return age;
  }

  async registerOne(examId: string, dto: RegisterCandidateDto, tenantId: string, userId: string) {
    const exam = await this.findOne(examId);
    this.assertRegistrationOpen(exam);

    const existing = await this.prisma.examRegistration.findFirst({
      where: { examId, studentId: dto.studentId },
    });
    if (existing) {
      throw new ConflictException("Cet élève est déjà inscrit à cet examen");
    }

    const eligibility = await this.checkEligibility(exam, dto.studentId);
    if (!eligibility.eligible) {
      throw new BadRequestException(`Élève non éligible : ${eligibility.reason}`);
    }

    const registration = await this.prisma.examRegistration.create({
      data: {
        tenantId,
        examId,
        studentId: dto.studentId,
        registrationNumber: await this.buildRegNumber(exam),
        series: dto.series ?? null,
        centerCode: dto.centerCode ?? null,
        centerName: dto.centerName ?? null,
        feePaid: dto.feePaid ?? false,
        status: ExamRegistrationStatus.PENDING,
      },
    });

    await this.audit.log({
      action: "create",
      resource: "exam-registrations",
      resourceId: registration.id,
      newValue: { examId, studentId: dto.studentId },
    });
    return registration;
  }

  /**
   * Inscription batch : parcourt les élèves, vérifie l'éligibilité de chacun,
   * inscrit les éligibles et retourne un rapport (éligibles / rejetés / motifs).
   */
  async registerBatch(examId: string, dto: RegisterBatchDto, tenantId: string) {
    const exam = await this.findOne(examId);
    this.assertRegistrationOpen(exam);

    const alreadyRegistered = new Set(
      (
        await this.prisma.examRegistration.findMany({
          where: { examId, studentId: { in: dto.studentIds } },
          select: { studentId: true },
        })
      ).map((r) => r.studentId),
    );

    const registered: Array<{ studentId: string; registrationNumber: string }> = [];
    const rejected: Array<{ studentId: string; reason: string }> = [];
    let seq = await this.prisma.examRegistration.count({ where: { examId } });
    const year = this.yearOf(exam.registrationStart);

    for (const studentId of dto.studentIds) {
      if (alreadyRegistered.has(studentId)) {
        rejected.push({ studentId, reason: "Déjà inscrit" });
        continue;
      }
      const eligibility = await this.checkEligibility(exam, studentId);
      if (!eligibility.eligible) {
        rejected.push({ studentId, reason: eligibility.reason ?? "Non éligible" });
        continue;
      }
      seq += 1;
      const registrationNumber = `${exam.code}-${year}-${String(seq).padStart(5, "0")}`;
      await this.prisma.examRegistration.create({
        data: {
          tenantId,
          examId,
          studentId,
          registrationNumber,
          series: dto.series ?? null,
          centerCode: dto.centerCode ?? null,
          centerName: dto.centerName ?? null,
          status: ExamRegistrationStatus.PENDING,
        },
      });
      registered.push({ studentId, registrationNumber });
    }

    await this.audit.log({
      action: "register-batch",
      resource: "exams",
      resourceId: examId,
      newValue: { registered: registered.length, rejected: rejected.length },
    });

    return {
      total: dto.studentIds.length,
      registeredCount: registered.length,
      rejectedCount: rejected.length,
      registered,
      rejected,
    };
  }

  private yearOf(d: Date): string {
    return String(d.getFullYear());
  }

  private async buildRegNumber(exam: OfficialExam): Promise<string> {
    const count = await this.prisma.examRegistration.count({ where: { examId: exam.id } });
    return `${exam.code}-${this.yearOf(exam.registrationStart)}-${String(count + 1).padStart(5, "0")}`;
  }

  private assertRegistrationOpen(exam: OfficialExam): void {
    const now = new Date();
    if (now < exam.registrationStart || now > exam.registrationEnd) {
      throw new BadRequestException(
        "Les inscriptions ne sont pas ouvertes pour cet examen (hors période)",
      );
    }
  }

  async getCandidates(examId: string) {
    await this.findOne(examId);
    const registrations = await this.prisma.examRegistration.findMany({
      where: { examId },
      orderBy: { registrationNumber: "asc" },
    });
    // Enrichit avec l'identité de l'élève (nom, prénom, matricule).
    const studentIds = registrations.map((r) => r.studentId);
    const students = await this.prisma.student.findMany({
      where: { id: { in: studentIds } },
      select: { id: true, firstName: true, lastName: true, matricule: true, gender: true, dateOfBirth: true },
    });
    const byId = new Map(students.map((s) => [s.id, s]));
    return registrations.map((r) => ({
      ...r,
      student: byId.get(r.studentId) ?? null,
    }));
  }

  async validateCandidate(examId: string, registrationId: string, userId: string) {
    const registration = await this.prisma.examRegistration.findFirst({
      where: { id: registrationId, examId },
    });
    if (!registration) {
      throw new NotFoundException("Inscription introuvable");
    }
    if (!registration.feePaid) {
      throw new BadRequestException("Les frais d'examen doivent être payés avant validation");
    }
    return this.prisma.examRegistration.update({
      where: { id: registrationId },
      data: { status: ExamRegistrationStatus.VALIDATED, validatedBy: userId },
    });
  }

  // --- Résultats ----------------------------------------------------------

  /**
   * Import des notes puis calcul : moyenne = Σ(note×coeff)/Σ(coeff) (absent → 0),
   * décision (admis/échoué/oral), mention, et classement par moyenne décroissante.
   */
  async submitResults(examId: string, dto: SubmitResultsDto) {
    const exam = await this.findOne(examId);

    const registrations = await this.prisma.examRegistration.findMany({ where: { examId } });
    const byNumber = new Map(registrations.map((r) => [r.registrationNumber, r]));

    const unknown = [
      ...new Set(dto.results.map((r) => r.registrationNumber).filter((n) => !byNumber.has(n))),
    ];
    if (unknown.length > 0) {
      throw new BadRequestException(
        `N° d'inscription inconnus : ${unknown.slice(0, 10).join(", ")}${unknown.length > 10 ? "…" : ""}`,
      );
    }

    // Remplace les résultats existants de cet examen (ré-import idempotent).
    const regIds = registrations.map((r) => r.id);
    await this.prisma.examResult.deleteMany({ where: { registrationId: { in: regIds } } });

    for (const line of dto.results) {
      const reg = byNumber.get(line.registrationNumber)!;
      await this.prisma.examResult.create({
        data: {
          tenantId: reg.tenantId,
          registrationId: reg.id,
          subject: line.subject,
          coefficient: line.coefficient,
          mark: line.isAbsent ? 0 : line.mark ?? null,
          isAbsent: line.isAbsent ?? false,
        },
      });
    }

    // Calcul par candidat.
    const passMark = Number(exam.passMark);
    const oralMin = exam.oralMinMark != null ? Number(exam.oralMinMark) : null;
    const computed: Array<{ regId: string; average: number | null }> = [];

    for (const reg of registrations) {
      const results = dto.results.filter((r) => r.registrationNumber === reg.registrationNumber);
      if (results.length === 0) {
        computed.push({ regId: reg.id, average: null });
        continue;
      }
      let weighted = 0;
      let coeffSum = 0;
      for (const r of results) {
        const mark = r.isAbsent ? 0 : r.mark ?? 0;
        weighted += mark * r.coefficient;
        coeffSum += r.coefficient;
      }
      const average = coeffSum > 0 ? Math.round((weighted / coeffSum) * 100) / 100 : 0;
      computed.push({ regId: reg.id, average });
    }

    // Classement (moyenne décroissante, null en dernier).
    const ranked = [...computed]
      .filter((c) => c.average != null)
      .sort((a, b) => (b.average ?? 0) - (a.average ?? 0));
    const rankByReg = new Map<string, number>();
    ranked.forEach((c, i) => rankByReg.set(c.regId, i + 1));

    for (const c of computed) {
      const average = c.average;
      let status: ExamRegistrationStatus;
      let mention: string | null = null;
      if (average == null) {
        status = ExamRegistrationStatus.VALIDATED;
      } else if (average >= passMark) {
        status = ExamRegistrationStatus.PASSED;
        mention = this.mentionFor(average);
      } else if (oralMin != null && average >= oralMin) {
        status = ExamRegistrationStatus.FAILED;
        mention = "Oral de rattrapage";
      } else {
        status = ExamRegistrationStatus.FAILED;
      }
      await this.prisma.examRegistration.update({
        where: { id: c.regId },
        data: {
          average: average ?? null,
          mention,
          status,
          rank: rankByReg.get(c.regId) ?? null,
        },
      });
    }

    await this.audit.log({
      action: "submit-results",
      resource: "exams",
      resourceId: examId,
      newValue: { lines: dto.results.length },
    });

    return this.getResults(examId);
  }

  private mentionFor(average: number): string | null {
    for (const t of MENTION_THRESHOLDS) {
      if (average >= t.min) return t.label;
    }
    return null;
  }

  async getResults(examId: string) {
    const exam = await this.findOne(examId);
    const registrations = await this.prisma.examRegistration.findMany({
      where: { examId },
      orderBy: [{ rank: "asc" }, { registrationNumber: "asc" }],
    });
    const studentIds = registrations.map((r) => r.studentId);
    const students = await this.prisma.student.findMany({
      where: { id: { in: studentIds } },
      select: { id: true, firstName: true, lastName: true, matricule: true },
    });
    const byId = new Map(students.map((s) => [s.id, s]));

    const graded = registrations.filter((r) => r.average != null);
    const passed = graded.filter((r) => r.status === ExamRegistrationStatus.PASSED);
    const best = graded[0]
      ? { ...graded[0], student: byId.get(graded[0].studentId) ?? null }
      : null;

    const mentionDistribution: Record<string, number> = {};
    for (const r of passed) {
      const key = r.mention ?? "Sans mention";
      mentionDistribution[key] = (mentionDistribution[key] ?? 0) + 1;
    }

    return {
      exam: { id: exam.id, name: exam.name, code: exam.code, passMark: Number(exam.passMark) },
      stats: {
        totalGraded: graded.length,
        passedCount: passed.length,
        successRate: graded.length > 0 ? Math.round((passed.length / graded.length) * 10000) / 100 : 0,
        best: best
          ? { name: `${best.student?.lastName ?? ""} ${best.student?.firstName ?? ""}`.trim(), average: Number(best.average) }
          : null,
        mentionDistribution,
      },
      candidates: registrations.map((r) => ({
        registrationNumber: r.registrationNumber,
        student: byId.get(r.studentId) ?? null,
        series: r.series,
        average: r.average != null ? Number(r.average) : null,
        mention: r.mention,
        rank: r.rank,
        status: r.status,
      })),
    };
  }
}

import { BadRequestException, ForbiddenException, Injectable, NotFoundException } from "@nestjs/common";
import {
  AnnualDecision,
  EnrollmentStatus,
  Grade,
  GradeType,
  GradingStatus,
  Period,
  PeriodType,
  Prisma,
} from "@scholaris/prisma";
import { calculationEngineSchema, DEFAULT_CALCULATION_ENGINE_CONFIG, type CalculationEngineConfig } from "@scholaris/shared";
import { PrismaService } from "../../prisma/prisma.service";
import { AuditService } from "../audit/audit.service";
import type { AuthenticatedUser } from "../auth/jwt-payload.interface";
import { CalculationEngineService, type RawGrade } from "./calculation-engine.service";
import { CreateGradeBatchDto } from "./dto/create-grade-batch.dto";
import { DeliberationDto } from "./dto/deliberation.dto";
import { UpdateGradeDto } from "./dto/update-grade.dto";

/** Pondérations conventionnelles CC/Examen pour le calcul annuel LMD (aucun champ
 * dédié n'existe dans calculationEngineSchema — cf. décision documentée dans le
 * rapport du module). */
const LMD_CC_WEIGHT = 0.4;
const LMD_EXAM_WEIGHT = 0.6;

@Injectable()
export class GradesService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly audit: AuditService,
    private readonly engine: CalculationEngineService,
  ) {}

  // ─── Saisie ───────────────────────────────────────────────────────────

  /**
   * Saisie collective : une évaluation (matière OU EC, période, type, barème)
   * et une note par élève. Refuse (400) toute note hors barème et (403) toute
   * saisie sur une période qui n'est pas ouverte.
   */
  async batchCreate(dto: CreateGradeBatchDto, user: AuthenticatedUser): Promise<Grade[]> {
    const hasSubject = Boolean(dto.subjectId);
    const hasCourseElement = Boolean(dto.courseElementId);
    if (hasSubject === hasCourseElement) {
      throw new BadRequestException("Renseigner soit une matière, soit un EC (exactement l'un des deux)");
    }

    const period = await this.getPeriodOrThrow(dto.periodId, user.tenantId);
    this.ensurePeriodOpen(period);

    if (dto.subjectId) {
      const subject = await this.prisma.subject.findFirst({ where: { id: dto.subjectId } });
      if (!subject) {
        throw new BadRequestException("La matière indiquée est introuvable");
      }
    }
    if (dto.courseElementId) {
      const courseElement = await this.prisma.courseElement.findFirst({ where: { id: dto.courseElementId } });
      if (!courseElement) {
        throw new BadRequestException("L'EC indiqué est introuvable");
      }
    }

    const maxValue = dto.maxValue;
    const weight = dto.weight ?? 1;
    const date = dto.date ? new Date(dto.date) : new Date();

    for (const entry of dto.entries) {
      if (entry.isAbsent) {
        continue;
      }
      if (entry.value === undefined || entry.value === null) {
        throw new BadRequestException(`Note manquante pour l'élève ${entry.studentId}`);
      }
      if (entry.value < 0 || entry.value > maxValue) {
        throw new BadRequestException(`La note de l'élève ${entry.studentId} (${entry.value}) est hors barème [0, ${maxValue}]`);
      }
    }

    const created = await this.prisma.$transaction(
      dto.entries.map((entry) =>
        this.prisma.grade.create({
          data: {
            studentId: entry.studentId,
            subjectId: dto.subjectId ?? null,
            courseElementId: dto.courseElementId ?? null,
            periodId: dto.periodId,
            teacherId: user.userId,
            type: dto.type,
            value: entry.isAbsent ? null : entry.value,
            maxValue,
            weight,
            date,
            comment: entry.comment ?? dto.comment,
            isAbsent: entry.isAbsent ?? false,
            isJustified: entry.isJustified ?? false,
            tenantId: user.tenantId,
          },
        }),
      ),
    );

    await this.audit.log({
      action: "create",
      resource: "grades",
      resourceId: dto.periodId,
      newValue: { count: created.length, subjectId: dto.subjectId, courseElementId: dto.courseElementId },
    });
    return created;
  }

  /** Modification d'une note existante, avant verrouillage et pendant que la période est ouverte. */
  async update(id: string, dto: UpdateGradeDto, tenantId: string): Promise<Grade> {
    const grade = await this.prisma.grade.findFirst({ where: { id } });
    if (!grade) {
      throw new NotFoundException("Note introuvable");
    }
    if (grade.isLocked) {
      throw new ForbiddenException("Cette note est verrouillée : elle ne peut plus être modifiée");
    }
    const period = await this.prisma.period.findFirst({ where: { id: grade.periodId, academicYear: { tenantId } } });
    if (period) {
      this.ensurePeriodOpen(period);
    }

    const maxValue = dto.maxValue ?? Number(grade.maxValue);
    const isAbsent = dto.isAbsent ?? grade.isAbsent;
    if (!isAbsent) {
      const value = dto.value ?? (grade.value === null ? undefined : Number(grade.value));
      if (value === undefined) {
        throw new BadRequestException("La note est requise si l'élève n'est pas absent");
      }
      if (value < 0 || value > maxValue) {
        throw new BadRequestException(`La note (${value}) est hors barème [0, ${maxValue}]`);
      }
    }

    return this.prisma.grade.update({
      where: { id },
      data: {
        value: isAbsent ? null : (dto.value ?? grade.value),
        maxValue: dto.maxValue ?? grade.maxValue,
        weight: dto.weight ?? grade.weight,
        isAbsent,
        isJustified: dto.isJustified ?? grade.isJustified,
        comment: dto.comment ?? grade.comment,
      },
    });
  }

  // ─── Verrouillage ─────────────────────────────────────────────────────

  async lock(classroomId: string, subjectOrCourseElementId: string, periodId: string, tenantId: string) {
    return this.setLock(classroomId, subjectOrCourseElementId, periodId, tenantId, true);
  }

  async unlock(classroomId: string, subjectOrCourseElementId: string, periodId: string, tenantId: string) {
    return this.setLock(classroomId, subjectOrCourseElementId, periodId, tenantId, false);
  }

  private async setLock(classroomId: string, targetId: string, periodId: string, tenantId: string, lock: boolean) {
    const period = await this.getPeriodOrThrow(periodId, tenantId);
    const enrollments = await this.getActiveEnrollments(classroomId, period.academicYearId);
    const studentIds = enrollments.map((e) => e.studentId);

    const [subject, courseElement] = await Promise.all([
      this.prisma.subject.findFirst({ where: { id: targetId } }),
      this.prisma.courseElement.findFirst({ where: { id: targetId } }),
    ]);
    if (!subject && !courseElement) {
      throw new NotFoundException("Matière ou élément constitutif introuvable");
    }

    const result = await this.prisma.grade.updateMany({
      where: {
        periodId,
        studentId: { in: studentIds },
        deletedAt: null,
        ...(subject ? { subjectId: targetId } : { courseElementId: targetId }),
      },
      data: { isLocked: lock },
    });

    await this.audit.log({
      action: lock ? "lock" : "unlock",
      resource: "grades",
      resourceId: `${classroomId}:${targetId}:${periodId}`,
      newValue: { count: result.count },
    });
    return { locked: lock, count: result.count };
  }

  // ─── Calcul ───────────────────────────────────────────────────────────

  /** Calcule moyennes de matières/EC, moyenne générale et classement d'une classe pour une période donnée. */
  async calculate(classroomId: string, periodId: string, tenantId: string) {
    const period = await this.getPeriodOrThrow(periodId, tenantId);
    const classroom = await this.prisma.classRoom.findFirst({ where: { id: classroomId } });
    if (!classroom) {
      throw new NotFoundException("Classe introuvable");
    }

    const config = await this.getConfig(tenantId);
    const enrollments = await this.getActiveEnrollments(classroomId, period.academicYearId);
    const studentIds = enrollments.map((e) => e.studentId);
    if (studentIds.length === 0) {
      throw new BadRequestException("Aucun élève inscrit dans cette classe pour cette année académique");
    }

    const assignments = await this.prisma.subjectAssignment.findMany({
      where: { classroomId, academicYearId: period.academicYearId, deletedAt: null },
      include: { subject: true, courseElement: true },
    });

    const subjectAverages: Array<{ subjectId: string | null; courseElementId: string | null; coefficient: number; perStudent: Map<string, number | null> }> = [];

    for (const assignment of assignments) {
      const coefficient = assignment.subject
        ? Number(assignment.subject.coefficient)
        : Number(assignment.courseElement?.coefficient ?? 1);

      const grades = await this.prisma.grade.findMany({
        where: {
          periodId,
          studentId: { in: studentIds },
          deletedAt: null,
          ...(assignment.subjectId ? { subjectId: assignment.subjectId } : { courseElementId: assignment.courseElementId }),
        },
      });

      const byStudent = new Map<string, Grade[]>();
      for (const grade of grades) {
        const list = byStudent.get(grade.studentId) ?? [];
        list.push(grade);
        byStudent.set(grade.studentId, list);
      }

      const perStudent = new Map<string, number | null>();
      for (const studentId of studentIds) {
        const studentGrades = this.toRawGrades(byStudent.get(studentId) ?? []);
        const average = this.engine.calculateSubjectAverage(studentGrades, config.absenceRule);
        perStudent.set(studentId, average === null ? null : this.engine.applyRounding(average, config.roundingRule));
      }

      subjectAverages.push({ subjectId: assignment.subjectId, courseElementId: assignment.courseElementId, coefficient, perStudent });

      const ranked = this.engine.calculateRanking(
        studentIds.map((studentId) => ({ id: studentId, average: perStudent.get(studentId) ?? null })),
      );
      for (const entry of ranked) {
        if (entry.average === null) {
          continue;
        }
        await this.upsertGradeCalculation({
          tenantId,
          studentId: entry.id,
          periodId,
          classroomId,
          subjectId: assignment.subjectId,
          courseElementId: assignment.courseElementId,
          calculatedAverage: entry.average,
          coefficient,
          weightedTotal: entry.average * coefficient,
          rank: entry.rank,
        });
      }
    }

    const generalAverages = studentIds.map((studentId) => {
      const subjects = subjectAverages.map((s) => ({ average: s.perStudent.get(studentId) ?? null, coefficient: s.coefficient }));
      const average = this.engine.calculateGeneralAverage(subjects);
      return { studentId, average: average === null ? null : this.engine.applyRounding(average, config.roundingRule) };
    });

    const ranked = this.engine.calculateRanking(generalAverages.map((g) => ({ id: g.studentId, average: g.average })));
    const totalStudents = ranked.filter((r) => r.average !== null).length;

    const periodResults = [];
    for (const entry of ranked) {
      const mention = entry.average === null ? null : this.engine.determineMention(entry.average, config.mentionThresholds);
      const result = await this.upsertPeriodResult({
        tenantId,
        studentId: entry.id,
        periodId,
        classroomId,
        generalAverage: entry.average ?? 0,
        rank: entry.rank,
        totalStudents,
        mention: mention?.label ?? null,
      });
      periodResults.push(result);
    }

    await this.audit.log({
      action: "calculate",
      resource: "grades",
      resourceId: `${classroomId}:${periodId}`,
      newValue: { studentsCount: studentIds.length },
    });

    return { periodResults };
  }

  /** Bilan annuel (POST /grades/calculate-annual/:classId) : moyenne annuelle pondérée, classement, mention, décision — mode LMD (UE/EC/GPA) ou classique (trimestres pondérés). */
  async calculateAnnual(classroomId: string, academicYearId: string, tenantId: string) {
    const academicYear = await this.prisma.academicYear.findFirst({ where: { id: academicYearId, tenantId } });
    if (!academicYear) {
      throw new NotFoundException("Année académique introuvable");
    }
    const classroom = await this.prisma.classRoom.findFirst({ where: { id: classroomId } });
    if (!classroom) {
      throw new NotFoundException("Classe introuvable");
    }

    const config = await this.getConfig(tenantId);
    const enrollments = await this.getActiveEnrollments(classroomId, academicYearId);
    const studentIds = enrollments.map((e) => e.studentId);
    if (studentIds.length === 0) {
      throw new BadRequestException("Aucun élève inscrit dans cette classe pour cette année académique");
    }

    if (config.evaluationType === "LMD") {
      return this.calculateAnnualLMD(classroomId, academicYearId, studentIds, config, tenantId);
    }

    const periodType = config.evaluationType === "SEMESTER" ? PeriodType.SEMESTER : PeriodType.TRIMESTER;
    const periods = await this.prisma.period.findMany({ where: { academicYearId, type: periodType }, orderBy: { number: "asc" } });
    if (periods.length === 0) {
      throw new BadRequestException(`Aucune période de type ${periodType} trouvée pour cette année académique`);
    }

    const periodResults = await this.prisma.periodResult.findMany({
      where: { classroomId, periodId: { in: periods.map((p) => p.id) } },
    });

    const annualAverages = studentIds.map((studentId) => {
      const perPeriod = periods.map((period) => {
        const result = periodResults.find((r) => r.studentId === studentId && r.periodId === period.id);
        return result ? Number(result.generalAverage) : null;
      });
      const average = this.engine.calculateAnnual(perPeriod, config);
      return { studentId, average: average === null ? null : this.engine.applyRounding(average, config.roundingRule) };
    });

    const ranked = this.engine.calculateRanking(annualAverages.map((a) => ({ id: a.studentId, average: a.average })));
    const threshold = this.passThreshold(config);

    const results = [];
    for (const entry of ranked) {
      const mention = entry.average === null ? null : this.engine.determineMention(entry.average, config.mentionThresholds);
      const decision = entry.average === null ? null : entry.average >= threshold ? AnnualDecision.PASS : AnnualDecision.REPEAT;
      const saved = await this.upsertAnnualResult({
        tenantId,
        studentId: entry.id,
        classroomId,
        academicYearId,
        annualAverage: entry.average ?? 0,
        rank: entry.rank,
        mention: mention?.label ?? null,
        decision,
        creditsValidated: null,
        gpa: null,
      });
      results.push(saved);
    }

    await this.audit.log({
      action: "calculate-annual",
      resource: "grades",
      resourceId: `${classroomId}:${academicYearId}`,
      newValue: { mode: config.evaluationType },
    });
    return { annualResults: results };
  }

  private async calculateAnnualLMD(
    classroomId: string,
    academicYearId: string,
    studentIds: string[],
    config: CalculationEngineConfig,
    tenantId: string,
  ) {
    const assignments = await this.prisma.subjectAssignment.findMany({
      where: { classroomId, academicYearId, deletedAt: null, courseElementId: { not: null } },
    });
    const assignedEcIds = [...new Set(assignments.map((a) => a.courseElementId!).filter(Boolean))];
    const courseElements = await this.prisma.courseElement.findMany({ where: { id: { in: assignedEcIds }, deletedAt: null } });

    const grades = await this.prisma.grade.findMany({
      where: { studentId: { in: studentIds }, courseElementId: { in: assignedEcIds }, period: { academicYearId }, deletedAt: null },
    });

    const validationThreshold = this.passThreshold(config);
    const perStudent: Array<{ studentId: string; average: number | null; mention: string | null; decision: AnnualDecision | null; creditsValidated: number; gpa: number | null }> = [];

    for (const studentId of studentIds) {
      const unitsMap = new Map<string, typeof courseElements>();
      for (const ec of courseElements) {
        const list = unitsMap.get(ec.teachingUnitId) ?? [];
        list.push(ec);
        unitsMap.set(ec.teachingUnitId, list);
      }

      const unitsInput = Array.from(unitsMap.entries()).map(([ueId, elements]) => ({
        ueId,
        elements: elements.map((ec) => {
          const ecGrades = grades.filter((g) => g.courseElementId === ec.id && g.studentId === studentId);
          const cc = this.engine.calculateSubjectAverage(
            this.toRawGrades(ecGrades.filter((g) => g.type === GradeType.TEST || g.type === GradeType.HOMEWORK)),
            config.absenceRule,
          );
          const exam = this.engine.calculateSubjectAverage(
            this.toRawGrades(ecGrades.filter((g) => g.type === GradeType.EXAM)),
            config.absenceRule,
          );
          const resit = this.engine.calculateSubjectAverage(
            this.toRawGrades(ecGrades.filter((g) => g.type === GradeType.RESIT)),
            config.absenceRule,
          );
          return {
            ecId: ec.id,
            credits: ec.credits,
            ccScore: cc,
            examScore: exam,
            resitScore: resit,
            ccWeight: LMD_CC_WEIGHT,
            examWeight: LMD_EXAM_WEIGHT,
          };
        }),
      }));

      const lmd = this.engine.calculateLMD(unitsInput, config, validationThreshold);
      const gpa = config.gpaScale && config.gpaScale.length > 0
        ? this.engine.calculateGPA(lmd.units.map((u) => ({ averageOn20: u.average, credits: u.credits })), config.gpaScale)
        : null;

      const average = lmd.overallAverage;
      const mention = average === null ? null : this.engine.determineMention(average, config.mentionThresholds);
      const decision = average === null
        ? null
        : lmd.creditsTotal > 0 && lmd.creditsEarned >= lmd.creditsTotal * 0.5
          ? AnnualDecision.PASS
          : AnnualDecision.REPEAT;

      perStudent.push({
        studentId,
        average,
        mention: mention?.label ?? null,
        decision,
        creditsValidated: lmd.creditsEarned,
        gpa: gpa === null ? null : this.engine.applyRounding(gpa, "HUNDREDTH"),
      });
    }

    const ranked = this.engine.calculateRanking(perStudent.map((p) => ({ id: p.studentId, average: p.average })));

    const saved = [];
    for (const entry of ranked) {
      const source = perStudent.find((p) => p.studentId === entry.id)!;
      const roundedAverage = entry.average === null ? 0 : this.engine.applyRounding(entry.average, config.roundingRule);
      const record = await this.upsertAnnualResult({
        tenantId,
        studentId: entry.id,
        classroomId,
        academicYearId,
        annualAverage: roundedAverage,
        rank: entry.rank,
        mention: source.mention,
        decision: source.decision,
        creditsValidated: source.creditsValidated,
        gpa: source.gpa,
      });
      saved.push(record);
    }

    await this.audit.log({
      action: "calculate-annual",
      resource: "grades",
      resourceId: `${classroomId}:${academicYearId}`,
      newValue: { mode: "LMD" },
    });
    return { annualResults: saved };
  }

  // ─── Consultation ─────────────────────────────────────────────────────

  /** Avancement de la saisie (matières saisies vs non saisies) pour une période, par classe. */
  async getProgress(periodId: string, tenantId: string, classroomId?: string) {
    const period = await this.getPeriodOrThrow(periodId, tenantId);
    const classrooms = await this.prisma.classRoom.findMany({ where: classroomId ? { id: classroomId } : {} });

    const report = [];
    for (const classroom of classrooms) {
      const enrollments = await this.getActiveEnrollments(classroom.id, period.academicYearId);
      const studentCount = enrollments.length;
      if (studentCount === 0) {
        continue;
      }
      const studentIds = enrollments.map((e) => e.studentId);

      const assignments = await this.prisma.subjectAssignment.findMany({
        where: { classroomId: classroom.id, academicYearId: period.academicYearId, deletedAt: null },
        include: { subject: true, courseElement: true },
      });

      const subjects = [];
      for (const assignment of assignments) {
        const graded = await this.prisma.grade.findMany({
          where: {
            periodId,
            deletedAt: null,
            studentId: { in: studentIds },
            ...(assignment.subjectId ? { subjectId: assignment.subjectId } : { courseElementId: assignment.courseElementId }),
          },
          select: { studentId: true },
          distinct: ["studentId"],
        });
        subjects.push({
          subjectId: assignment.subjectId,
          courseElementId: assignment.courseElementId,
          label: assignment.subject?.name ?? assignment.courseElement?.name ?? "—",
          teacherId: assignment.teacherId,
          studentsGraded: graded.length,
          totalStudents: studentCount,
          isComplete: graded.length >= studentCount,
        });
      }

      report.push({ classroomId: classroom.id, classroomName: classroom.name, totalStudents: studentCount, subjects });
    }

    return { periodId, classrooms: report };
  }

  /** Historique complet des notes d'un élève (enseignant/censeur/admin voient tout ; parent/élève : résultats publiés uniquement). */
  async findByStudent(studentId: string, tenantId: string, canViewUnpublished: boolean) {
    const student = await this.prisma.student.findFirst({ where: { id: studentId } });
    if (!student) {
      throw new NotFoundException("Élève introuvable");
    }

    const grades = await this.prisma.grade.findMany({
      where: { studentId, deletedAt: null },
      include: { subject: true, courseElement: true, period: true },
      orderBy: { date: "desc" },
    });

    const periodResults = await this.prisma.periodResult.findMany({
      where: { studentId, deletedAt: null, ...(canViewUnpublished ? {} : { isPublished: true }) },
      include: { period: true },
      orderBy: { createdAt: "desc" },
    });

    const annualResults = await this.prisma.annualResult.findMany({
      where: { studentId, deletedAt: null },
      include: { academicYear: true },
      orderBy: { createdAt: "desc" },
    });

    return { student, grades, periodResults, annualResults };
  }

  /** Tableau récapitulatif d'une classe pour une période (résultats + détail des moyennes de matières). */
  async findResults(classroomId: string, periodId: string, tenantId: string, canViewUnpublished: boolean) {
    await this.getPeriodOrThrow(periodId, tenantId);

    const where: Prisma.PeriodResultWhereInput = {
      classroomId,
      periodId,
      deletedAt: null,
      ...(canViewUnpublished ? {} : { isPublished: true }),
    };
    const periodResults = await this.prisma.periodResult.findMany({
      where,
      include: { student: true },
      orderBy: [{ rank: "asc" }],
    });
    const gradeCalculations = await this.prisma.gradeCalculation.findMany({
      where: { classroomId, periodId, deletedAt: null },
      include: { subject: true, courseElement: true },
    });

    return { periodResults, gradeCalculations };
  }

  // ─── Délibération & publication ──────────────────────────────────────

  async deliberate(classroomId: string, periodId: string, dto: DeliberationDto) {
    const updated = [];
    for (const entry of dto.entries) {
      const existing = await this.prisma.periodResult.findFirst({ where: { studentId: entry.studentId, periodId, classroomId } });
      if (!existing) {
        throw new BadRequestException(
          `Aucun résultat calculé pour l'élève ${entry.studentId} sur cette période — lancez d'abord le calcul`,
        );
      }
      const result = await this.prisma.periodResult.update({
        where: { id: existing.id },
        data: {
          decision: entry.decision ?? existing.decision,
          observations: entry.observations ?? existing.observations,
          teacherComment: entry.teacherComment ?? existing.teacherComment,
        },
      });
      updated.push(result);
    }

    await this.audit.log({
      action: "deliberation",
      resource: "grades",
      resourceId: `${classroomId}:${periodId}`,
      newValue: { count: updated.length },
    });
    return updated;
  }

  async publish(classroomId: string, periodId: string) {
    const existingCount = await this.prisma.periodResult.count({ where: { classroomId, periodId } });
    if (existingCount === 0) {
      throw new BadRequestException("Aucun résultat calculé pour cette classe et cette période — lancez d'abord le calcul");
    }
    const result = await this.prisma.periodResult.updateMany({ where: { classroomId, periodId }, data: { isPublished: true } });

    await this.audit.log({
      action: "publish",
      resource: "grades",
      resourceId: `${classroomId}:${periodId}`,
      newValue: { count: result.count },
    });
    return { published: result.count };
  }

  // ─── Helpers ──────────────────────────────────────────────────────────

  private async getPeriodOrThrow(periodId: string, tenantId: string): Promise<Period> {
    const period = await this.prisma.period.findFirst({ where: { id: periodId, academicYear: { tenantId } } });
    if (!period) {
      throw new NotFoundException("Période introuvable");
    }
    return period;
  }

  private ensurePeriodOpen(period: Period): void {
    if (period.gradingStatus !== GradingStatus.OPEN) {
      throw new ForbiddenException("La saisie des notes est fermée ou verrouillée pour cette période");
    }
  }

  private async getActiveEnrollments(classroomId: string, academicYearId: string) {
    return this.prisma.enrollment.findMany({
      where: { classroomId, academicYearId, status: EnrollmentStatus.ACTIVE, deletedAt: null },
      select: { studentId: true },
    });
  }

  private async getConfig(tenantId: string): Promise<CalculationEngineConfig> {
    const tenant = await this.prisma.tenant.findFirst({ where: { id: tenantId } });
    const parsed = calculationEngineSchema.safeParse(tenant?.configJson);
    return parsed.success ? parsed.data : DEFAULT_CALCULATION_ENGINE_CONFIG;
  }

  /** Seuil de validation/passage dérivé des mentions configurées (plus petit seuil strictement positif, ex: PASSABLE=10) ; 10 par défaut. */
  private passThreshold(config: CalculationEngineConfig): number {
    const positive = config.mentionThresholds.map((t) => t.minAverage).filter((v) => v > 0);
    return positive.length > 0 ? Math.min(...positive) : 10;
  }

  private toRawGrades(grades: Grade[]): RawGrade[] {
    return grades.map((g) => ({
      value: g.value === null ? null : Number(g.value),
      maxValue: Number(g.maxValue),
      weight: Number(g.weight),
      isAbsent: g.isAbsent,
      isJustified: g.isJustified,
    }));
  }

  private async upsertGradeCalculation(data: {
    tenantId: string;
    studentId: string;
    periodId: string;
    classroomId: string;
    subjectId: string | null;
    courseElementId: string | null;
    calculatedAverage: number;
    coefficient: number;
    weightedTotal: number;
    rank: number | null;
  }) {
    const existing = await this.prisma.gradeCalculation.findFirst({
      where: { studentId: data.studentId, periodId: data.periodId, subjectId: data.subjectId, courseElementId: data.courseElementId },
    });
    if (existing) {
      return this.prisma.gradeCalculation.update({
        where: { id: existing.id },
        data: {
          classroomId: data.classroomId,
          calculatedAverage: data.calculatedAverage,
          coefficient: data.coefficient,
          weightedTotal: data.weightedTotal,
          rank: data.rank,
        },
      });
    }
    return this.prisma.gradeCalculation.create({ data });
  }

  private async upsertPeriodResult(data: {
    tenantId: string;
    studentId: string;
    periodId: string;
    classroomId: string;
    generalAverage: number;
    rank: number | null;
    totalStudents: number;
    mention: string | null;
  }) {
    const existing = await this.prisma.periodResult.findFirst({ where: { studentId: data.studentId, periodId: data.periodId } });
    if (existing) {
      return this.prisma.periodResult.update({
        where: { id: existing.id },
        data: {
          classroomId: data.classroomId,
          generalAverage: data.generalAverage,
          rank: data.rank,
          totalStudents: data.totalStudents,
          mention: data.mention,
        },
      });
    }
    return this.prisma.periodResult.create({ data });
  }

  private async upsertAnnualResult(data: {
    tenantId: string;
    studentId: string;
    classroomId: string;
    academicYearId: string;
    annualAverage: number;
    rank: number | null;
    mention: string | null;
    decision: AnnualDecision | null;
    creditsValidated: number | null;
    gpa: number | null;
  }) {
    const existing = await this.prisma.annualResult.findFirst({ where: { studentId: data.studentId, academicYearId: data.academicYearId } });
    if (existing) {
      return this.prisma.annualResult.update({
        where: { id: existing.id },
        data: {
          classroomId: data.classroomId,
          annualAverage: data.annualAverage,
          rank: data.rank,
          mention: data.mention,
          decision: data.decision,
          creditsValidated: data.creditsValidated,
          gpa: data.gpa,
        },
      });
    }
    return this.prisma.annualResult.create({ data });
  }
}

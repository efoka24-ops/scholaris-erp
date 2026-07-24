import {
  Injectable,
  NotFoundException,
  BadRequestException,
} from "@nestjs/common";
import { PrismaService } from "../../prisma/prisma.service";
import { BulletinPdfService } from "./bulletin-pdf.service";
import * as crypto from "crypto";

@Injectable()
export class BulletinsService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly pdfService: BulletinPdfService,
  ) {}

  /**
   * Génère les bulletins pour tous les élèves d'une classe pour une période donnée
   */
  async generateBatch(
    classroomId: string,
    periodId: string,
    tenantId: string,
    autoSend?: boolean,
  ) {
    // Vérifier que la classe et la période existent
    const [classroom, period] = await Promise.all([
      this.prisma.classRoom.findFirst({
        where: { id: classroomId, tenantId },
        include: { level: true, room: true },
      }),
      this.prisma.period.findFirst({
        where: { id: periodId },
        include: { academicYear: true },
      }),
    ]);

    if (!classroom) {
      throw new NotFoundException(`Classroom ${classroomId} not found`);
    }

    if (!period) {
      throw new NotFoundException(`Period ${periodId} not found`);
    }

    // Récupérer tous les élèves inscrits dans cette classe
    const enrollments = await this.prisma.enrollment.findMany({
      where: {
        classroomId,
        academicYearId: period.academicYearId,
        status: "ACTIVE",
      },
      include: {
        student: true,
      },
    });

    if (enrollments.length === 0) {
      throw new BadRequestException("No students enrolled in this classroom");
    }

    const results = [];

    for (const enrollment of enrollments) {
      try {
        const bulletin = await this.generateSingle(
          enrollment.studentId,
          periodId,
          tenantId,
        );
        results.push({
          studentId: enrollment.studentId,
          matricule: (enrollment as any).student?.matricule,
          status: "success",
          bulletinId: bulletin.id,
        });
      } catch (error) {
        results.push({
          studentId: enrollment.studentId,
          matricule: (enrollment as any).student?.matricule,
          status: "error",
          error: error instanceof Error ? error.message : "Unknown error",
        });
      }
    }

    const successCount = results.filter((r) => r.status === "success").length;
    const errorCount = results.filter((r) => r.status === "error").length;

    return {
      total: enrollments.length,
      success: successCount,
      errors: errorCount,
      results,
    };
  }

  /**
   * Génère un bulletin pour un élève pour une période donnée
   */
  async generateSingle(studentId: string, periodId: string, tenantId: string) {
    // Récupérer l'élève avec son inscription
    const student = await this.prisma.student.findFirst({
      where: { id: studentId, tenantId },
      include: {
        enrollments: {
          where: {
            status: "ACTIVE",
          },
          include: {
            classroom: {
              include: {
                level: true,
                room: true,
              },
            },
            academicYear: true,
          },
        },
      },
    });

    if (!student) {
      throw new NotFoundException(`Student ${studentId} not found`);
    }

    // Trouver l'inscription correspondant à la période
    const period = await this.prisma.period.findFirst({
      where: { id: periodId },
      include: { academicYear: true },
    });

    if (!period) {
      throw new NotFoundException(`Period ${periodId} not found`);
    }

    const enrollment = (student as any).enrollments?.find(
      (e: any) => e.academicYearId === period.academicYearId,
    );

    if (!enrollment) {
      throw new BadRequestException("Student not enrolled for this academic year");
    }

    // Snapshot enrichi au format MINESEC (Seq1/Seq2, groupes, rangs, profil classe, conduite).
    const bulletinData = await this.buildSecondaryBulletinData(
      tenantId,
      studentId,
      enrollment.classroom,
      period,
    );

    // Générer un code QR unique pour vérification
    const verificationCode = crypto.randomBytes(16).toString("hex");

    // Créer l'enregistrement bulletin
    const bulletin = await this.prisma.bulletin.create({
      data: {
        tenantId,
        studentId,
        periodId,
        classroomId: enrollment.classroom.id,
        verificationCode,
        status: "published",
        data: {
          student: {
            matricule: student.matricule,
            firstName: student.firstName,
            lastName: student.lastName,
            dateOfBirth: student.dateOfBirth,
            placeOfBirth: (student as any).placeOfBirth ?? null,
            gender: student.gender,
            repeating: (enrollment as any).isRepeating ?? false,
          },
          classroom: {
            name: enrollment.classroom.name,
            level: enrollment.classroom.level.name,
          },
          period: {
            name: `${period.type === "SEQUENCE" ? "Séquence" : period.type === "TRIMESTER" ? "Trimestre" : "Semestre"} ${period.number}`,
            type: period.type,
            number: period.number,
            academicYear: period.academicYear.label,
          },
          // Champs enrichis MINESEC (consommés par le template PDF secondaire).
          minesec: bulletinData,
          // Champs hérités (rétro-compatibilité de l'ancien template simple).
          grades: bulletinData.subjects.map((s) => ({
            subject: s.name,
            score: s.seq1 ?? s.moy ?? 0,
            maxScore: 20,
            coefficient: s.coefficient,
            average: s.moy ?? 0,
            teacher: s.teacher,
          })),
          average: bulletinData.generalAverage,
          rank: bulletinData.rank,
          totalStudents: bulletinData.classSize,
        },
      },
    });

    // Générer le PDF
    try {
      const pdfBuffer = await this.pdfService.generate(bulletin);
      await this.prisma.bulletin.update({
        where: { id: bulletin.id },
        data: { pdfUrl: `bulletins/${bulletin.id}.pdf` },
      });
    } catch (error) {
      console.error("PDF generation failed:", error);
      // Continue même si le PDF échoue
    }

    return bulletin;
  }

  /**
   * Construit le snapshot enrichi d'un bulletin secondaire (format MINESEC) :
   * Seq1/Seq2 par matière, moyenne matière, coef, total, rang/%réussite/max/min
   * calculés sur la classe, regroupement en 3 groupes, moyenne générale, rang,
   * profil de classe et conduite (absences).
   *
   * Convention MINESEC : un bulletin de TRIMESTRE N agrège les séquences
   * (2N-1) et (2N) ; un bulletin de SÉQUENCE n'a que Seq1.
   */
  private async buildSecondaryBulletinData(
    tenantId: string,
    studentId: string,
    classroom: { id: string; name: string; level: { name: string } },
    period: { id: string; type: string; number: number; academicYearId: string },
  ) {
    // 1) Périodes de séquence concernées.
    let seqPeriodIds: { seq1?: string; seq2?: string } = {};
    if (period.type === "TRIMESTER") {
      const seqs = await this.prisma.period.findMany({
        where: {
          academicYearId: period.academicYearId,
          type: "SEQUENCE",
          number: { in: [period.number * 2 - 1, period.number * 2] },
        },
        orderBy: { number: "asc" },
      });
      seqPeriodIds.seq1 = seqs.find((s) => s.number === period.number * 2 - 1)?.id;
      seqPeriodIds.seq2 = seqs.find((s) => s.number === period.number * 2)?.id;
    } else {
      // SÉQUENCE ou SEMESTRE : la période elle-même tient lieu de Seq1.
      seqPeriodIds.seq1 = period.id;
    }
    const periodIds = [seqPeriodIds.seq1, seqPeriodIds.seq2].filter(Boolean) as string[];

    // En-tête établissement (nom, adresse/BP, tél, logo).
    const tenant = await this.prisma.tenant.findFirst({
      where: { id: tenantId },
      select: { name: true, address: true, phone: true, email: true, logoUrl: true },
    });

    // 2) Élèves de la classe (inscriptions actives).
    const enrollments = await this.prisma.enrollment.findMany({
      where: { classroomId: classroom.id, academicYearId: period.academicYearId, status: "ACTIVE" },
      select: { studentId: true },
    });
    const classStudentIds = enrollments.map((e) => e.studentId);

    // 3) Toutes les notes de la classe sur les périodes concernées.
    const grades = await this.prisma.grade.findMany({
      where: {
        tenantId,
        studentId: { in: classStudentIds },
        periodId: { in: periodIds },
        subjectId: { not: null },
      },
      include: {
        subject: { select: { id: true, name: true, coefficient: true, category: true } },
        teacher: { select: { firstName: true, lastName: true } },
      },
    });

    // Note /20 d'une note brute.
    const toTwenty = (g: (typeof grades)[number]): number | null => {
      if (g.isAbsent) return 0;
      if (g.value == null) return null;
      const max = Number(g.maxValue) || 20;
      return (Number(g.value) / max) * 20;
    };

    // 4) Moyenne d'un élève dans une matière pour une séquence (moyenne pondérée des notes).
    //    Puis moyenne matière = moyenne(Seq1, Seq2) sur les séquences renseignées.
    type SubjMeta = { id: string; name: string; coefficient: number; category: string; teacher: string };
    const subjectMeta = new Map<string, SubjMeta>();
    // studentId -> subjectId -> periodId -> {sum, weight}
    const acc = new Map<string, Map<string, Map<string, { sum: number; w: number }>>>();

    for (const g of grades) {
      if (!g.subject) continue;
      const note = toTwenty(g);
      if (note == null) continue;
      const w = Number(g.weight) || 1;
      if (!subjectMeta.has(g.subject.id)) {
        subjectMeta.set(g.subject.id, {
          id: g.subject.id,
          name: g.subject.name,
          coefficient: Number(g.subject.coefficient) || 1,
          category: String(g.subject.category),
          teacher: `${g.teacher.firstName} ${g.teacher.lastName}`.trim(),
        });
      }
      const byStudent = acc.get(g.studentId) ?? new Map();
      acc.set(g.studentId, byStudent);
      const bySubject = byStudent.get(g.subject.id) ?? new Map();
      byStudent.set(g.subject.id, bySubject);
      const cell = bySubject.get(g.periodId) ?? { sum: 0, w: 0 };
      cell.sum += note * w;
      cell.w += w;
      bySubject.set(g.periodId, cell);
    }

    const seqAvg = (studentId: string, subjectId: string, seqPeriodId?: string): number | null => {
      if (!seqPeriodId) return null;
      const cell = acc.get(studentId)?.get(subjectId)?.get(seqPeriodId);
      if (!cell || cell.w === 0) return null;
      return cell.sum / cell.w;
    };
    const subjectMoy = (studentId: string, subjectId: string): number | null => {
      const s1 = seqAvg(studentId, subjectId, seqPeriodIds.seq1);
      const s2 = seqAvg(studentId, subjectId, seqPeriodIds.seq2);
      const vals = [s1, s2].filter((v): v is number => v != null);
      if (vals.length === 0) return null;
      return vals.reduce((a, b) => a + b, 0) / vals.length;
    };

    const round2 = (v: number) => Math.round(v * 100) / 100;

    // 5) Lignes de matières pour l'élève cible + stats de classe par matière.
    const subjects = [...subjectMeta.values()].map((meta) => {
      const s1 = seqAvg(studentId, meta.id, seqPeriodIds.seq1);
      const s2 = seqAvg(studentId, meta.id, seqPeriodIds.seq2);
      const moy = subjectMoy(studentId, meta.id);

      // Moyennes de tous les élèves dans cette matière (pour rang/max/min/%réussite).
      const classMoys = classStudentIds
        .map((sid) => subjectMoy(sid, meta.id))
        .filter((v): v is number => v != null);
      const max = classMoys.length ? Math.max(...classMoys) : null;
      const min = classMoys.length ? Math.min(...classMoys) : null;
      const successRate = classMoys.length
        ? Math.round((classMoys.filter((v) => v >= 10).length / classMoys.length) * 100)
        : null;
      const rank =
        moy != null ? classMoys.filter((v) => v > moy).length + 1 : null;

      return {
        id: meta.id,
        name: meta.name,
        teacher: meta.teacher,
        category: meta.category,
        group: this.groupForCategory(meta.category),
        coefficient: meta.coefficient,
        seq1: s1 != null ? round2(s1) : null,
        seq2: s2 != null ? round2(s2) : null,
        moy: moy != null ? round2(moy) : null,
        total: moy != null ? round2(moy * meta.coefficient) : null,
        rank,
        successRate,
        max: max != null ? round2(max) : null,
        min: min != null ? round2(min) : null,
        appreciation: this.appreciationFor(moy),
      };
    });

    // 6) Regroupement en 3 groupes + sous-totaux.
    const groups = [1, 2, 3].map((groupNo) => {
      const rows = subjects.filter((s) => s.group === groupNo);
      const coefSum = rows.reduce((a, s) => a + s.coefficient, 0);
      const totalSum = rows.reduce((a, s) => a + (s.total ?? 0), 0);
      return {
        group: groupNo,
        label: this.groupLabel(groupNo),
        subjects: rows,
        coefSum: round2(coefSum),
        totalSum: round2(totalSum),
        average: coefSum > 0 ? round2(totalSum / coefSum) : null,
      };
    }).filter((g) => g.subjects.length > 0);

    // 7) Moyenne générale de chaque élève + rang + profil de classe.
    const generalAverageOf = (sid: string): number | null => {
      let tot = 0;
      let coef = 0;
      for (const meta of subjectMeta.values()) {
        const m = subjectMoy(sid, meta.id);
        if (m != null) {
          tot += m * meta.coefficient;
          coef += meta.coefficient;
        }
      }
      return coef > 0 ? tot / coef : null;
    };

    const generalAverage = generalAverageOf(studentId);
    const classGenerals = classStudentIds
      .map((sid) => generalAverageOf(sid))
      .filter((v): v is number => v != null);
    const rank =
      generalAverage != null ? classGenerals.filter((v) => v > generalAverage).length + 1 : null;
    const classAverage = classGenerals.length
      ? round2(classGenerals.reduce((a, b) => a + b, 0) / classGenerals.length)
      : null;
    const nbrMoy = classGenerals.filter((v) => v >= 10).length;

    const totalCoef = subjects.reduce((a, s) => a + s.coefficient, 0);
    const totalPoints = subjects.reduce((a, s) => a + (s.total ?? 0), 0);

    // 8) Conduite : absences comptabilisées via les notes marquées absentes.
    const absencesAll = grades.filter((g) => g.studentId === studentId && g.isAbsent);
    const conduct = {
      absencesUnjustified: absencesAll.filter((g) => !g.isJustified).length,
      absencesJustified: absencesAll.filter((g) => g.isJustified).length,
    };

    return {
      establishment: {
        name: tenant?.name ?? "Établissement",
        address: tenant?.address ?? null,
        phone: tenant?.phone ?? null,
        email: tenant?.email ?? null,
        logoUrl: tenant?.logoUrl ?? null,
      },
      seqLabels: {
        seq1: seqPeriodIds.seq1 ? `Séq${period.type === "TRIMESTER" ? period.number * 2 - 1 : period.number}` : "Séq1",
        seq2: seqPeriodIds.seq2 ? `Séq${period.number * 2}` : null,
      },
      subjects,
      groups,
      totalCoef: round2(totalCoef),
      totalPoints: round2(totalPoints),
      generalAverage: generalAverage != null ? round2(generalAverage) : null,
      mention: generalAverage != null ? this.getMentionLabel(generalAverage) : null,
      rank,
      classSize: classStudentIds.length,
      classProfile: {
        classAverage,
        successRate: classGenerals.length
          ? Math.round((nbrMoy / classGenerals.length) * 100)
          : null,
        nbrMoy,
        max: classGenerals.length ? round2(Math.max(...classGenerals)) : null,
        min: classGenerals.length ? round2(Math.min(...classGenerals)) : null,
      },
      conduct,
    };
  }

  private groupForCategory(category: string): number {
    switch (category) {
      case "LITERARY":
      case "LANGUAGE":
        return 1; // Groupe 1 : littéraire / langues
      case "SCIENTIFIC":
      case "TECHNICAL":
        return 2; // Groupe 2 : scientifique / technique
      case "SPORTS":
        return 3; // Groupe 3 : EPS / autres
      default:
        return 2;
    }
  }

  private groupLabel(groupNo: number): string {
    switch (groupNo) {
      case 1:
        return "Groupe 1 — Littéraire & Langues";
      case 2:
        return "Groupe 2 — Scientifique & Technique";
      case 3:
        return "Groupe 3 — EPS & Autres";
      default:
        return `Groupe ${groupNo}`;
    }
  }

  private appreciationFor(moy: number | null): string {
    if (moy == null) return "—";
    if (moy >= 16) return "Très Bien";
    if (moy >= 14) return "Bien";
    if (moy >= 12) return "Assez Bien";
    if (moy >= 10) return "Passable";
    return "Insuffisant";
  }

  private getMentionLabel(average: number): string {
    if (average >= 18) return "EXCELLENT";
    if (average >= 16) return "TRÈS BIEN";
    if (average >= 14) return "BIEN";
    if (average >= 12) return "ASSEZ BIEN";
    if (average >= 10) return "PASSABLE";
    return "INSUFFISANT";
  }

  /**
   * Récupère un bulletin par ID
   */
  async findOne(id: string, tenantId: string) {
    const bulletin = await this.prisma.bulletin.findFirst({
      where: { id, tenantId },
      include: {
        student: true,
        period: {
          include: {
            academicYear: true,
          },
        },
        classroom: {
          include: {
            level: true,
          },
        },
      },
    });

    if (!bulletin) {
      throw new NotFoundException(`Bulletin ${id} not found`);
    }

    return bulletin;
  }

  /**
   * Vérifie l'authenticité d'un bulletin via son code QR
   */
  async verify(verificationCode: string) {
    const bulletin = await this.prisma.bulletin.findFirst({
      where: { verificationCode },
      include: {
        student: {
          select: {
            matricule: true,
            firstName: true,
            lastName: true,
          },
        },
        period: {
          select: {
            type: true,
            number: true,
            academicYear: {
              select: {
                label: true,
              },
            },
          },
        },
      },
    });

    if (!bulletin) {
      return {
        valid: false,
        message: "Bulletin non trouvé ou code invalide",
      };
    }

    return {
      valid: true,
      message: "Bulletin authentique",
      bulletin: {
        student: bulletin.student,
        period: bulletin.period,
        generatedAt: bulletin.createdAt,
      },
    };
  }

  /**
   * Liste tous les bulletins d'un élève
   */
  async findByStudent(studentId: string, tenantId: string) {
    return this.prisma.bulletin.findMany({
      where: { studentId, tenantId },
      include: {
        period: {
          include: {
            academicYear: true,
          },
        },
        classroom: true,
      },
      orderBy: { createdAt: "desc" },
    });
  }
}

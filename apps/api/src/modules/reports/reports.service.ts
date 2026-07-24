import { BadRequestException, Injectable, NotFoundException } from "@nestjs/common";
import { PrismaService } from "../../prisma/prisma.service";

/**
 * Reporting par niveau (§5 du scénario) : agrège les résultats de toutes les
 * classes d'un niveau pour une période. Réutilise la convention MINESEC
 * (trimestre N = séquences 2N-1 et 2N) et le calcul de moyenne pondérée par
 * coefficient, appliqués à l'échelle du niveau.
 */
@Injectable()
export class ReportsService {
  constructor(private readonly prisma: PrismaService) {}

  async levelReport(levelId: string, periodId: string, tenantId: string) {
    const level = await this.prisma.level.findFirst({
      where: { id: levelId, tenantId },
      include: { classrooms: true },
    });
    if (!level) throw new NotFoundException("Niveau introuvable");

    const period = await this.prisma.period.findFirst({ where: { id: periodId } });
    if (!period) throw new NotFoundException("Période introuvable");

    // Séquences concernées.
    let seqIds: string[] = [];
    if (period.type === "TRIMESTER") {
      const seqs = await this.prisma.period.findMany({
        where: {
          academicYearId: period.academicYearId,
          type: "SEQUENCE",
          number: { in: [period.number * 2 - 1, period.number * 2] },
        },
      });
      seqIds = seqs.map((s) => s.id);
    } else {
      seqIds = [period.id];
    }
    // Repli : si aucune séquence dédiée, utiliser la période elle-même.
    if (seqIds.length === 0) seqIds = [period.id];

    const classroomIds = level.classrooms.map((c) => c.id);
    if (classroomIds.length === 0) {
      throw new BadRequestException("Aucune classe dans ce niveau");
    }

    // Élèves inscrits (avec classe et genre).
    const enrollments = await this.prisma.enrollment.findMany({
      where: { classroomId: { in: classroomIds }, academicYearId: period.academicYearId, status: "ACTIVE" },
      include: { student: { select: { id: true, firstName: true, lastName: true, gender: true } } },
    });
    const classByStudent = new Map(enrollments.map((e) => [e.studentId, e.classroomId]));
    const studentIds = enrollments.map((e) => e.studentId);

    // Toutes les notes du niveau sur les séquences.
    const grades = await this.prisma.grade.findMany({
      where: { tenantId, studentId: { in: studentIds }, periodId: { in: seqIds }, subjectId: { not: null } },
      include: { subject: { select: { id: true, name: true, coefficient: true } } },
    });

    const toTwenty = (g: (typeof grades)[number]): number | null => {
      if (g.isAbsent) return 0;
      if (g.value == null) return null;
      const max = Number(g.maxValue) || 20;
      return (Number(g.value) / max) * 20;
    };

    // studentId -> subjectId -> periodId -> {sum,w}
    const acc = new Map<string, Map<string, Map<string, { sum: number; w: number }>>>();
    const subjectMeta = new Map<string, { id: string; name: string; coefficient: number }>();
    for (const g of grades) {
      if (!g.subject) continue;
      const note = toTwenty(g);
      if (note == null) continue;
      const w = Number(g.weight) || 1;
      subjectMeta.set(g.subject.id, {
        id: g.subject.id,
        name: g.subject.name,
        coefficient: Number(g.subject.coefficient) || 1,
      });
      const bySub = acc.get(g.studentId) ?? new Map();
      acc.set(g.studentId, bySub);
      const byPer = bySub.get(g.subject.id) ?? new Map();
      bySub.set(g.subject.id, byPer);
      const cell = byPer.get(g.periodId) ?? { sum: 0, w: 0 };
      cell.sum += note * w;
      cell.w += w;
      byPer.set(g.periodId, cell);
    }

    const subjectMoy = (sid: string, subjId: string): number | null => {
      const byPer = acc.get(sid)?.get(subjId);
      if (!byPer) return null;
      const vals: number[] = [];
      for (const seqId of seqIds) {
        const cell = byPer.get(seqId);
        if (cell && cell.w > 0) vals.push(cell.sum / cell.w);
      }
      return vals.length ? vals.reduce((a, b) => a + b, 0) / vals.length : null;
    };
    const generalAvg = (sid: string): number | null => {
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
    const round2 = (v: number) => Math.round(v * 100) / 100;

    // Moyennes générales par élève.
    const studentAverages = studentIds
      .map((sid) => ({ studentId: sid, average: generalAvg(sid) }))
      .filter((x): x is { studentId: string; average: number } => x.average != null);

    const levelAverage = studentAverages.length
      ? round2(studentAverages.reduce((a, s) => a + s.average, 0) / studentAverages.length)
      : null;
    const passCount = studentAverages.filter((s) => s.average >= 10).length;
    const successRate = studentAverages.length
      ? Math.round((passCount / studentAverages.length) * 100)
      : null;

    // Effectifs (garçons / filles).
    const boys = enrollments.filter((e) => e.student.gender === "MALE").length;
    const girls = enrollments.filter((e) => e.student.gender === "FEMALE").length;

    // Moyennes par classe.
    const classInfo = new Map(level.classrooms.map((c) => [c.id, c.name]));
    const byClass = new Map<string, number[]>();
    for (const s of studentAverages) {
      const cid = classByStudent.get(s.studentId);
      if (!cid) continue;
      const arr = byClass.get(cid) ?? [];
      arr.push(s.average);
      byClass.set(cid, arr);
    }
    const classComparison = [...byClass.entries()]
      .map(([cid, avgs]) => ({
        classroomId: cid,
        name: classInfo.get(cid) ?? cid,
        studentCount: avgs.length,
        average: round2(avgs.reduce((a, b) => a + b, 0) / avgs.length),
        successRate: Math.round((avgs.filter((v) => v >= 10).length / avgs.length) * 100),
      }))
      .sort((a, b) => b.average - a.average);

    // Distribution des moyennes.
    const buckets = [
      { label: "[0-5[", min: 0, max: 5 },
      { label: "[5-10[", min: 5, max: 10 },
      { label: "[10-12[", min: 10, max: 12 },
      { label: "[12-14[", min: 12, max: 14 },
      { label: "[14-16[", min: 14, max: 16 },
      { label: "[16-20]", min: 16, max: 20.01 },
    ].map((b) => ({
      label: b.label,
      count: studentAverages.filter((s) => s.average >= b.min && s.average < b.max).length,
    }));

    // Top 10 élèves.
    const nameById = new Map(enrollments.map((e) => [e.studentId, `${e.student.lastName} ${e.student.firstName}`]));
    const ranked = [...studentAverages].sort((a, b) => b.average - a.average);
    const top10 = ranked.slice(0, 10).map((s, i) => ({
      rank: i + 1,
      name: nameById.get(s.studentId) ?? "—",
      classroom: classInfo.get(classByStudent.get(s.studentId) ?? "") ?? "—",
      average: round2(s.average),
    }));

    // Par matière : moyenne niveau + taux de réussite.
    const bySubject = [...subjectMeta.values()]
      .map((meta) => {
        const moys = studentIds
          .map((sid) => subjectMoy(sid, meta.id))
          .filter((v): v is number => v != null);
        if (moys.length === 0) return null;
        return {
          subject: meta.name,
          coefficient: meta.coefficient,
          average: round2(moys.reduce((a, b) => a + b, 0) / moys.length),
          successRate: Math.round((moys.filter((v) => v >= 10).length / moys.length) * 100),
        };
      })
      .filter(Boolean)
      .sort((a: any, b: any) => b.average - a.average);

    return {
      level: { id: level.id, name: level.name },
      period: { id: period.id, type: period.type, number: period.number },
      effectifs: { total: enrollments.length, boys, girls, classes: level.classrooms.length },
      results: {
        levelAverage,
        successRate,
        gradedStudents: studentAverages.length,
        bestClass: classComparison[0] ?? null,
        weakestClass: classComparison[classComparison.length - 1] ?? null,
      },
      top10,
      distribution: buckets,
      bySubject,
      classComparison,
    };
  }
}

import { Injectable } from "@nestjs/common";
import type { CalculationEngineConfig, GpaScaleEntry, MentionThreshold } from "@scholaris/shared";

export type RoundingRule = CalculationEngineConfig["roundingRule"];
export type AbsenceRule = CalculationEngineConfig["absenceRule"];

/** Note brute d'une évaluation, avant application de la règle d'absence. */
export interface RawGrade {
  value: number | null;
  maxValue: number;
  weight: number;
  isAbsent: boolean;
  isJustified: boolean;
}

export interface WeightedValue {
  value: number;
  weight: number;
}

export interface LmdCourseElementInput {
  ecId: string;
  credits: number;
  /** Note de contrôle continu, sur 20. */
  ccScore: number | null;
  /** Note d'examen de la session normale, sur 20. */
  examScore: number | null;
  /** Note de la session de rattrapage, sur 20 (facultative). */
  resitScore?: number | null;
  /** Pondération du CC dans la note d'EC (ex: 0.4). */
  ccWeight: number;
  /** Pondération de l'examen dans la note d'EC (ex: 0.6). */
  examWeight: number;
}

export interface LmdCourseElementResult {
  ecId: string;
  credits: number;
  note: number;
}

export interface LmdTeachingUnitInput {
  ueId: string;
  elements: LmdCourseElementInput[];
}

export interface LmdTeachingUnitResult {
  ueId: string;
  credits: number;
  average: number;
  validated: boolean;
  elements: LmdCourseElementResult[];
}

export interface LmdResult {
  units: LmdTeachingUnitResult[];
  overallAverage: number | null;
  creditsEarned: number;
  creditsTotal: number;
}

/**
 * Moteur de calcul des notes — service sans état, indépendant de Prisma.
 *
 * Il consomme la configuration existante de l'établissement
 * (`Tenant.configJson`, cf. `calculationEngineSchema` /
 * `DEFAULT_CALCULATION_ENGINE_CONFIG` dans `packages/shared`) sans la
 * redéfinir : c'est GradesService qui charge cette configuration et la
 * transmet aux méthodes ci-dessous.
 *
 * Toutes les méthodes sont pures (aucun accès base de données, aucun effet de
 * bord) afin d'être exhaustivement testées avec des cas vérifiés à la main.
 */
@Injectable()
export class CalculationEngineService {
  /**
   * Applique la règle d'absence configurée à une note brute et renvoie la
   * valeur effective à utiliser dans les calculs (ou `null` si l'évaluation
   * doit être exclue de la moyenne — poids neutralisé).
   *
   * - ZERO       : une absence (justifiée ou non) vaut 0.
   * - NEUTRALIZED: l'évaluation est retirée du calcul (poids exclu).
   * - POSTPONED  : absence justifiée → neutralisée en attendant le
   *                rattrapage ; absence non justifiée → 0 (dissuasif).
   */
  handleAbsence(grade: { value: number | null; isAbsent: boolean; isJustified: boolean }, rule: AbsenceRule): number | null {
    if (!grade.isAbsent) {
      return grade.value;
    }
    switch (rule) {
      case "ZERO":
        return 0;
      case "NEUTRALIZED":
        return null;
      case "POSTPONED":
        return grade.isJustified ? null : 0;
      default:
        return 0;
    }
  }

  /** Moyenne pondérée générique : Σ(valeur×poids)/Σ(poids). `null` si aucun poids exploitable. */
  calculateWeightedAverage(items: WeightedValue[]): number | null {
    const totalWeight = items.reduce((sum, item) => sum + item.weight, 0);
    if (totalWeight <= 0) {
      return null;
    }
    const total = items.reduce((sum, item) => sum + item.value * item.weight, 0);
    return total / totalWeight;
  }

  /**
   * Moyenne d'une matière/EC à partir de ses notes brutes : chaque note est
   * ramenée sur 20 (barème variable), l'absence est traitée selon la règle
   * configurée, puis la moyenne pondérée est calculée sur les notes restantes.
   */
  calculateSubjectAverage(grades: RawGrade[], absenceRule: AbsenceRule): number | null {
    const items: WeightedValue[] = [];
    for (const grade of grades) {
      const effective = this.handleAbsence(grade, absenceRule);
      if (effective === null || !grade.maxValue) {
        continue;
      }
      const normalized = (effective / grade.maxValue) * 20;
      items.push({ value: normalized, weight: grade.weight });
    }
    return this.calculateWeightedAverage(items);
  }

  /**
   * Mode SEQUENTIAL : moyenne d'une matière sur une séquence, à partir de ses
   * notes brutes pondérées. Moyenne_matière = Σ(note×poids)/Σ(poids).
   *
   * Exemple vérifié à la main : notes 12, 15, 18 avec poids 1, 2, 1 →
   * (12×1 + 15×2 + 18×1) / (1+2+1) = (12+30+18)/4 = 60/4 = 15.
   */
  calculateSequential(grades: RawGrade[], config: CalculationEngineConfig): number | null {
    return this.calculateSubjectAverage(grades, config.absenceRule);
  }

  /**
   * Moyenne trimestrielle d'une matière : combine les moyennes de séquences
   * selon les pondérations `config.sequenceWeights` (mode SEQUENTIAL, ex:
   * deux séquences de poids égal [1,1]). En mode TRIMESTER (pas de séquences
   * intermédiaires), transmettre un tableau à un seul élément — la moyenne
   * trimestrielle est alors directement celle passée en entrée.
   */
  calculateTrimester(sequenceAverages: Array<number | null>, config: CalculationEngineConfig): number | null {
    const weights = config.sequenceWeights && config.sequenceWeights.length > 0
      ? config.sequenceWeights
      : sequenceAverages.map(() => 1);
    const items: WeightedValue[] = [];
    sequenceAverages.forEach((average, index) => {
      if (average === null) {
        return;
      }
      const weight = weights[index] ?? weights[weights.length - 1] ?? 1;
      items.push({ value: average, weight });
    });
    return this.calculateWeightedAverage(items);
  }

  /** Moyenne générale d'une période (élève) : Σ(moy_matière × coeff)/Σ(coeff). Les matières non évaluées (moyenne null) sont exclues. */
  calculateGeneralAverage(subjectAverages: Array<{ average: number | null; coefficient: number }>): number | null {
    const items = subjectAverages
      .filter((subject): subject is { average: number; coefficient: number } => subject.average !== null)
      .map((subject) => ({ value: subject.average, weight: subject.coefficient }));
    return this.calculateWeightedAverage(items);
  }

  /**
   * Moyenne annuelle : combine les moyennes générales des trimestres/semestres
   * selon les pondérations `config.trimesterWeights` (ex: 3 trimestres de
   * poids égal [1,1,1]).
   */
  calculateAnnual(trimesterAverages: Array<number | null>, config: CalculationEngineConfig): number | null {
    const weights = config.trimesterWeights && config.trimesterWeights.length > 0
      ? config.trimesterWeights
      : trimesterAverages.map(() => 1);
    const items: WeightedValue[] = [];
    trimesterAverages.forEach((average, index) => {
      if (average === null) {
        return;
      }
      const weight = weights[index] ?? weights[weights.length - 1] ?? 1;
      items.push({ value: average, weight });
    });
    return this.calculateWeightedAverage(items);
  }

  /** Note_EC = CC×Pcc + Exam×Pex (une composante manquante compte pour 0). */
  calculateECNote(ccScore: number | null, examScore: number | null, ccWeight: number, examWeight: number): number {
    const cc = ccScore ?? 0;
    const exam = examScore ?? 0;
    return cc * ccWeight + exam * examWeight;
  }

  /** Rattrapage : la meilleure note est conservée entre session normale et rattrapage. */
  applyResit(normalNote: number, resitScore?: number | null): number {
    if (resitScore === null || resitScore === undefined) {
      return normalNote;
    }
    return Math.max(normalNote, resitScore);
  }

  /** Moy_UE = Σ(Note_EC×crédits_EC)/Σ(crédits). */
  calculateUEAverage(ecNotes: Array<{ note: number; credits: number }>): number | null {
    return this.calculateWeightedAverage(ecNotes.map((ec) => ({ value: ec.note, weight: ec.credits })));
  }

  /**
   * Pipeline complet LMD : note d'EC (CC/Examen, avec meilleure note conservée
   * en cas de rattrapage) → moyenne d'UE pondérée par les crédits → validation
   * si moyenne ≥ seuil. Si `config.lmdCompensation` est activé, une UE dont la
   * moyenne est sous le seuil est tout de même validée dès lors que la moyenne
   * générale (toutes UE confondues, pondérée par les crédits) atteint le
   * seuil de validation.
   */
  calculateLMD(units: LmdTeachingUnitInput[], config: CalculationEngineConfig, validationThreshold = 10): LmdResult {
    const compensationEnabled = config.lmdCompensation ?? false;

    const ueResults: LmdTeachingUnitResult[] = units.map((unit) => {
      const elements: LmdCourseElementResult[] = unit.elements.map((ec) => {
        const normalNote = this.calculateECNote(ec.ccScore, ec.examScore, ec.ccWeight, ec.examWeight);
        const finalNote = this.applyResit(normalNote, ec.resitScore);
        return { ecId: ec.ecId, credits: ec.credits, note: this.roundTo(finalNote, 2) };
      });
      const totalCredits = elements.reduce((sum, ec) => sum + ec.credits, 0);
      const average = this.calculateUEAverage(elements) ?? 0;
      return {
        ueId: unit.ueId,
        credits: totalCredits,
        average: this.roundTo(average, 2),
        validated: average >= validationThreshold,
        elements,
      };
    });

    const overallAverage = this.calculateUEAverage(ueResults.map((unit) => ({ note: unit.average, credits: unit.credits })));
    const creditsTotal = ueResults.reduce((sum, unit) => sum + unit.credits, 0);

    const generalCompensates = compensationEnabled && overallAverage !== null && overallAverage >= validationThreshold;
    const finalUnits = ueResults.map((unit) => ({ ...unit, validated: unit.validated || generalCompensates }));
    const creditsEarned = finalUnits.filter((unit) => unit.validated).reduce((sum, unit) => sum + unit.credits, 0);

    return {
      units: finalUnits,
      overallAverage: overallAverage === null ? null : this.roundTo(overallAverage, 2),
      creditsEarned,
      creditsTotal,
    };
  }

  /**
   * Convertit une moyenne /20 en points GPA via l'échelle configurée
   * (`config.gpaScale`, note ramenée sur 100 → points, cf.
   * `calculationEngineSchema` — barème /4 par défaut mais entièrement
   * paramétrable par l'échelle fournie).
   */
  scoreToGpaPoints(averageOn20: number, gpaScale: GpaScaleEntry[]): number {
    if (gpaScale.length === 0) {
      return 0;
    }
    const scoreOn100 = (averageOn20 / 20) * 100;
    const sorted = [...gpaScale].sort((a, b) => b.minScore - a.minScore);
    const match = sorted.find((entry) => scoreOn100 >= entry.minScore);
    return match ? match.points : sorted[sorted.length - 1].points;
  }

  /** GPA global pondéré par les crédits (cursus LMD). */
  calculateGPA(entries: Array<{ averageOn20: number; credits: number }>, gpaScale: GpaScaleEntry[]): number | null {
    const items = entries.map((entry) => ({
      value: this.scoreToGpaPoints(entry.averageOn20, gpaScale),
      weight: entry.credits,
    }));
    return this.calculateWeightedAverage(items);
  }

  /**
   * Classement : tri décroissant par moyenne, ex æquo = même rang, le rang
   * suivant est décalé du nombre d'ex æquo (classement "1,2,2,4"). Les
   * entrées sans moyenne (`null`) ne sont pas classées (`rank: null`) et sont
   * reléguées en fin de liste.
   */
  calculateRanking<T extends { average: number | null }>(entries: T[]): Array<T & { rank: number | null }> {
    const indexed = entries.map((entry, index) => ({ entry, index }));
    indexed.sort((a, b) => {
      if (a.entry.average === null && b.entry.average === null) return a.index - b.index;
      if (a.entry.average === null) return 1;
      if (b.entry.average === null) return -1;
      if (b.entry.average !== a.entry.average) return b.entry.average - a.entry.average;
      return a.index - b.index;
    });

    const result: Array<T & { rank: number | null }> = [];
    let previousAverage: number | null = null;
    let previousRank = 0;
    indexed.forEach(({ entry }, position) => {
      if (entry.average === null) {
        result.push({ ...entry, rank: null });
        return;
      }
      if (entry.average === previousAverage) {
        result.push({ ...entry, rank: previousRank });
      } else {
        const rank = position + 1;
        result.push({ ...entry, rank });
        previousRank = rank;
        previousAverage = entry.average;
      }
    });
    return result;
  }

  /** Arrondi selon la règle configurée (dixième/centième/demi-point/entier/aucun). */
  applyRounding(value: number, rule: RoundingRule): number {
    switch (rule) {
      case "NONE":
        return value;
      case "TENTH":
        return this.roundTo(value, 1);
      case "HUNDREDTH":
        return this.roundTo(value, 2);
      case "HALF_POINT":
        return Math.round(value * 2) / 2;
      case "INTEGER":
        return Math.round(value);
      default:
        return value;
    }
  }

  /**
   * Détermine la mention applicable à une moyenne, selon les seuils
   * configurés (triés décroissant par `minAverage`) — le premier seuil
   * atteint ou dépassé s'applique.
   */
  determineMention(average: number, thresholds: MentionThreshold[]): MentionThreshold | null {
    if (thresholds.length === 0) {
      return null;
    }
    const sorted = [...thresholds].sort((a, b) => b.minAverage - a.minAverage);
    return sorted.find((threshold) => average >= threshold.minAverage) ?? sorted[sorted.length - 1];
  }

  private roundTo(value: number, decimals: number): number {
    const factor = 10 ** decimals;
    return Math.round(value * factor) / factor;
  }
}

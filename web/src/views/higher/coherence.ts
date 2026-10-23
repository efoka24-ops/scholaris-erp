// Contrôle de cohérence d'une maquette calculé en direct dans le navigateur, miroir de Curriculum\Domain\CoherenceChecker
// (RG-MAQ-2 : N crédits par semestre en LMD ; UE sans EC ; crédits UE = somme des EC ; EC sans volume ; groupes à choix).
// Le contrôle du serveur (GET /curricula/{id}/check) reste la référence de la publication ; celui-ci guide la saisie.
import type { CurriculumSemester, Regime } from '@/api/higher';

export type LiveIssue =
  | { kind: 'emptySemester'; label: string }
  | { kind: 'creditsSemester'; label: string; credits: number; target: number }
  | { kind: 'unitNoElement'; code: string }
  | { kind: 'unitCredits'; code: string; declared: number; sum: number }
  | { kind: 'noVolume'; code: string }
  | { kind: 'choiceCredits'; label: string; group: string };

export interface LiveCheck {
  issues: LiveIssue[];
  semesters: Array<{ ordinal: number; label: string; credits: number; hours: number }>;
}

export function liveCheck(regime: Regime, semesters: CurriculumSemester[], creditsPerSemester = 30): LiveCheck {
  const creditBased = regime === 'lmd' || regime === 'certificate';
  const issues: LiveIssue[] = [];
  const totals: LiveCheck['semesters'] = [];
  for (const s of semesters) {
    let credits = 0;
    let hours = 0;
    const groups = new Map<string, number[]>();
    let choiceCount = 1;
    if (s.units.length === 0) issues.push({ kind: 'emptySemester', label: s.label });
    for (const u of s.units) {
      if (u.choice_group) {
        groups.set(u.choice_group, [...(groups.get(u.choice_group) ?? []), u.credits]);
        choiceCount = u.choice_count ?? 1;
      } else {
        credits += u.credits;
      }
      if (creditBased && u.elements.length === 0) issues.push({ kind: 'unitNoElement', code: u.code });
      if (creditBased && u.elements.length > 0) {
        const sum = u.elements.reduce((a, e) => a + e.credits, 0);
        if (sum !== u.credits) issues.push({ kind: 'unitCredits', code: u.code, declared: u.credits, sum });
      }
      for (const e of u.elements) {
        const presential = e.cm_hours + e.td_hours + e.tp_hours;
        hours += presential;
        if (presential <= 0) issues.push({ kind: 'noVolume', code: e.code });
      }
    }
    for (const [group, options] of groups) {
      if (new Set(options).size !== 1) {
        issues.push({ kind: 'choiceCredits', label: s.label, group });
        continue;
      }
      credits += Math.min(choiceCount, options.length) * (options[0] ?? 0);
    }
    if (regime === 'lmd' && credits !== creditsPerSemester) issues.push({ kind: 'creditsSemester', label: s.label, credits, target: creditsPerSemester });
    totals.push({ ordinal: s.ordinal, label: s.label, credits, hours: Math.round(hours * 100) / 100 });
  }
  return { issues, semesters: totals };
}

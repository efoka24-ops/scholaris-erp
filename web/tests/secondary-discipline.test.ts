// Lot E2-B : contrôle local de RG-PRE-3, affichage des heures, menu Discipline et textes fr/en alignés.
import { describe, expect, it } from 'vitest';
import { FINAL_EXCLUSION, finalExclusionMissing, formatHours } from '@/api/secondaryDiscipline';
import { secondaryDisciplineFr } from '@/i18n/secondary-discipline-fr';
import { secondaryDisciplineEn } from '@/i18n/secondary-discipline-en';
import { MENU, visibleEntries } from '@/views/secondary/menu';

describe('discipline du secondaire', () => {
  it('une exclusion définitive exige procès-verbal et auditions', () => {
    expect(finalExclusionMissing({ decision_kind: FINAL_EXCLUSION, decision_reason: 'x' })).toEqual(['minutes', 'student_heard', 'parents_heard']);
    expect(finalExclusionMissing({ decision_kind: FINAL_EXCLUSION, decision_reason: 'x', minutes: 'PV', student_heard: true, parents_heard: true })).toEqual([]);
    expect(finalExclusionMissing({ decision_kind: 'conduct_blame', decision_reason: 'x' })).toEqual([]);
  });

  it('les heures s’affichent sans zéros inutiles', () => {
    expect(formatHours(20)).toBe('20');
    expect(formatHours(20.5)).toBe('20,5');
  });

  it('le menu Discipline suit les droits, sans module', () => {
    const hasRoute = (): boolean => true;
    const sg = visibleEntries(null, (...p) => p.includes('secondary.discipline.manage'), hasRoute, MENU).map((e) => e.name);
    expect(sg).toContain('sec-discipline');
    expect(sg).toContain('sec-discipline-councils');
    const teacher = visibleEntries(null, (...p) => p.includes('secondary.attendance.take'), hasRoute, MENU).map((e) => e.name);
    expect(teacher).toContain('sec-discipline');
    expect(teacher).not.toContain('sec-discipline-councils');
  });

  it('les textes existent dans les deux langues', () => {
    expect(Object.keys(secondaryDisciplineEn).sort()).toEqual(Object.keys(secondaryDisciplineFr).sort());
  });
});

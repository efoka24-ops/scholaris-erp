import { describe, expect, it } from 'vitest';
import { letterFor, nextStage, onStep } from '@/api/secondaryGrades';
import { secondaryGradesEn } from '@/i18n/secondary-grades-en';
import { secondaryGradesFr } from '@/i18n/secondary-grades-fr';
import { MENU } from '@/views/secondary/menu';
import { S } from '@/views/secondary/access';

// Notes du secondaire (lot E2-C) : contrôles d'écran identiques au serveur, cycle proposé, lettres [EN], menu et textes bilingues.
describe('notes du secondaire', () => {
  it('contrôle le pas de saisie comme le serveur', () => {
    expect(onStep(12.25, 0.25)).toBe(true);
    expect(onStep(12.3, 0.25)).toBe(false);
    expect(onStep(12.37, null)).toBe(true);
    expect(onStep(12.375, null)).toBe(false);
  });

  it('propose l\'étape suivante du cycle', () => {
    expect(['draft', 'submitted', 'validated', 'locked', 'published'].map(nextStage)).toEqual(['submitted', 'validated', 'locked', 'published', null]);
    expect(nextStage('not_evaluated')).toBeNull();
  });

  it('donne une lettre seulement si l\'établissement a une table', () => {
    expect(letterFor(15, [])).toBeNull();
    const bands = [{ min: 10, letter: 'C' }, { min: 16, letter: 'A' }, { min: 13, letter: 'B' }];
    expect([letterFor(17, bands), letterFor(13, bands), letterFor(9.5, bands), letterFor(null, bands)]).toEqual(['A', 'B', null, null]);
  });

  it('ajoute l\'entrée Notes au menu avec les droits de notes', () => {
    const entry = MENU.find((e) => e.name === 'sec-grades');
    expect(entry?.perms).toEqual(S.grades);
    expect(S.grades).toContain('secondary.grades.enter');
  });

  it('a les mêmes clés en français et en anglais', () => {
    expect(Object.keys(secondaryGradesEn).sort()).toEqual(Object.keys(secondaryGradesFr).sort());
  });
});

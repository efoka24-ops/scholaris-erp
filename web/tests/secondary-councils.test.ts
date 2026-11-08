// Lots E3-A et E3-D : lecture des tableaux du bulletin, valeurs propres des lignes du conseil, menu (conseils, bulletins, orientation),
// textes fr/en alignés.
import { describe, expect, it } from 'vitest';
import { cellText, lineExtras, parseDocTable, rowCells } from '@/api/secondaryCouncils';
import { secondaryCouncilsFr } from '@/i18n/secondary-councils-fr';
import { secondaryCouncilsEn } from '@/i18n/secondary-councils-en';
import { MENU, visibleEntries } from '@/views/secondary/menu';
import { secondaryRoutes } from '@/views/secondary/routes';

describe('conseil de classe et bulletins du secondaire', () => {
  it('relit un tableau du bulletin, refuse une valeur mal formée', () => {
    const table = parseDocTable(JSON.stringify({ columns: [{ label: '' }], rows: [{ kind: 'total', cells: ['TOTAL 1', { text: '11,30', colspan: 2 }] }, ['Maths', '7,75']] }));
    expect(table).not.toBeNull();
    expect(rowCells(table!.rows[0]!).kind).toBe('total');
    expect(rowCells(table!.rows[1]!).cells.map(cellText)).toEqual(['Maths', '7,75']);
    expect(cellText({ text: '11,30', colspan: 2 })).toBe('11,30');
    expect(parseDocTable('texte libre')).toBeNull();
    expect(parseDocTable(JSON.stringify({ rows: [] }))).toBeNull();
  });

  it('décode les valeurs propres en objet ou en texte JSON', () => {
    expect(lineExtras({ line_extras: { distinction_refused: true } }).distinction_refused).toBe(true);
    expect(lineExtras({ line_extras: '{"subject_alerts":["m"]}' }).subject_alerts).toEqual(['m']);
    expect(lineExtras({ line_extras: null })).toEqual({});
    expect(lineExtras({ line_extras: '{' })).toEqual({});
  });

  it('le menu suit les droits et le module orientation', () => {
    const hasRoute = (): boolean => true;
    const censor = visibleEntries(null, (...p) => p.includes('secondary.council.prepare'), hasRoute, MENU).map((e) => e.name);
    expect(censor).toEqual(expect.arrayContaining(['sec-councils', 'sec-report-cards']));
    const teacher = visibleEntries(null, (...p) => p.includes('secondary.reportcard.read'), hasRoute, MENU).map((e) => e.name);
    expect(teacher).toContain('sec-report-cards');
    expect(teacher).not.toContain('sec-councils');
    const withoutModule = visibleEntries(null, (...p) => p.includes('secondary.orientation.manage'), hasRoute, MENU).map((e) => e.name);
    expect(withoutModule).not.toContain('sec-orientation');
  });

  it('chaque entrée du menu est unique et les écrans sont routés', () => {
    const names = MENU.map((e) => e.name);
    expect(new Set(names).size).toBe(names.length);
    const routed = secondaryRoutes.map((r) => r.name);
    expect(routed).toEqual(expect.arrayContaining(['sec-councils', 'sec-report-cards', 'sec-orientation']));
  });

  it('les textes existent dans les deux langues', () => {
    expect(Object.keys(secondaryCouncilsEn).sort()).toEqual(Object.keys(secondaryCouncilsFr).sort());
  });
});

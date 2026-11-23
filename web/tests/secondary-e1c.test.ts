import { describe, expect, it } from 'vitest';
import { issuable, requiredFields, type DocumentKindInfo } from '@/api/secondaryDocuments';
import { validPhone } from '@/api/secondaryPortals';
import { SECONDARY_MODULES, type ModuleState, type SecondaryModule, type SecondaryProfile } from '@/api/secondaryProfile';
import { en } from '@/i18n/en';
import { fr } from '@/i18n/fr';
import { secondaryFr } from '@/i18n/secondary-fr';
import { secondaryRoutes } from '@/views/secondary/routes';
import { headTitleKey, MENU, visibleEntries } from '@/views/secondary/menu';
import { draftFrom, missing, moduleAllowed, openPayload } from '@/views/secondary/setup/profileModel';

// E1-C (T836, T837, T833, T834) : menu qui suit les modules actifs, assistant de profil, documents et portails. Données fictives.
function profile(active: SecondaryModule[], extra: Partial<SecondaryProfile> = {}, readOnly: SecondaryModule[] = []): SecondaryProfile {
  const state = (m: SecondaryModule): ModuleState => (active.includes(m) ? 'active' : readOnly.includes(m) ? 'read_only' : 'inactive');
  return {
    establishment_id: 'e-1', subtype: 'leg', stored: true, profile_version: 1, confirmation: 'to_confirm', ownership: 'public',
    ownership_options: ['public'], cycles: ['first', 'second'], teaching_kinds: ['general'], sections: ['fr'], head_title: 'proviseur',
    modules: SECONDARY_MODULES.map((code) => ({ code, state: state(code), default_for_type: active.includes(code) })),
    ...extra,
  };
}
const everyRoute = (): boolean => true;
const everyPerm = (): boolean => true;
const names = (p: SecondaryProfile | null, can: (...perms: readonly string[]) => boolean = everyPerm, has: (name: string) => boolean = everyRoute): string[] => visibleEntries(p, can, has).map((e) => e.name);

describe('menu du secondaire (T837)', () => {
  it("aucune entrée d'un module inactif ; le noyau est toujours là", () => {
    const shown = names(profile(['official_exams', 'fees_public_catalog']));
    expect(shown).toContain('sec-exams');
    expect(shown).toContain('sec-fees');
    for (const name of ['sec-workshops', 'sec-internships', 'sec-payroll', 'sec-orientation', 'sec-services']) expect(shown).not.toContain(name);
    for (const name of ['sec-profile', 'sec-documents', 'sec-student', 'sec-family']) expect(shown).toContain(name);
  });

  it('un module coupé avec données reste au menu (lecture seule) ; un module activé apparaît', () => {
    expect(names(profile([], {}, ['workshops']))).toContain('sec-workshops');
    expect(names(profile(['workshops', 'internships']))).toEqual(expect.arrayContaining(['sec-workshops', 'sec-internships']));
    expect(names(profile(['library']))).toContain('sec-services');
  });

  it('les rôles non concernés et les écrans non livrés sont masqués', () => {
    const parentOnly = (...p: readonly string[]): boolean => p.includes('secondary.family.read');
    expect(names(profile(['fees_private']), parentOnly)).toEqual(['sec-family']);
    expect(names(profile(['official_exams']), everyPerm, (n) => n !== 'sec-exams')).not.toContain('sec-exams');
    expect(names(null)).not.toContain('sec-fees');
  });

  it('les écrans livrés ont leur route et chaque entrée a ses textes FR et EN', () => {
    const routed = new Set(secondaryRoutes.map((r) => r.name));
    for (const name of ['sec-home', 'sec-profile', 'sec-documents', 'sec-student', 'sec-family']) expect(routed.has(name)).toBe(true);
    for (const e of MENU) {
      expect(fr[e.title]).toBeTruthy();
      expect(en[e.text]).toBeTruthy();
    }
  });

  it('vocabulaire du chef selon le profil, sans test du type', () => {
    expect(headTitleKey(profile([], { head_title: 'principal' }))).toBe('sec.head.principal');
    expect(headTitleKey(profile([], { head_title: null }))).toBe('sec.head.other');
  });
});

describe('assistant de profil (T836)', () => {
  it("pré-remplit avec les valeurs de départ et exige ce qui manque pour « autre »", () => {
    const autre = profile([], { subtype: 'autre', stored: false, ownership: null, ownership_options: ['public', 'private_secular', 'private_faith'], cycles: [], teaching_kinds: [], sections: [], head_title: null });
    const d = draftFrom(autre);
    expect(d.sections).toEqual(['fr']);
    expect(missing(d)).toEqual(['ownership', 'cycles', 'teaching_kinds', 'head_title']);
  });

  it('un module de second cycle ne part pas sans le second cycle', () => {
    const d = draftFrom(profile(['second_cycle', 'orientation', 'official_exams']));
    d.cycles = ['first'];
    expect(moduleAllowed('orientation', d.cycles)).toBe(false);
    expect(openPayload(d).modules).toEqual(['official_exams']);
  });

  it('les 7 types ont un libellé et une explication en clair, FR et EN', () => {
    for (const code of ['leg', 'lycee_technique', 'ces', 'cetic', 'college_prive', 'lycee_prive', 'autre']) {
      expect(fr[`sec.type.${code}` as keyof typeof fr]).toBeTruthy();
      expect(en[`sec.type.${code}.text` as keyof typeof en]).toBeTruthy();
    }
    for (const m of SECONDARY_MODULES) expect(fr[`sec.module.${m}.text` as keyof typeof fr]).toBeTruthy();
    expect(Object.keys(secondaryFr).every((k) => k in en)).toBe(true);
  });
});

describe('documents et portails (T833, T834)', () => {
  const kind = (over: Partial<DocumentKindInfo>): DocumentKindInfo => ({
    doc: 'DOC-03', kind: 'school_certificate', type: 'sec_doc03_school_certificate', label_fr: 'Certificat', label_en: 'Certificate', qr: true,
    source: 'enrolment', module: null, available: true, direct: true, can_issue: true, ...over,
  });

  it('champs demandés selon la source, jamais de saisie libre hors exeat', () => {
    expect(requiredFields(kind({}))).toEqual(['enrollment_id']);
    expect(requiredFields(kind({ kind: 'exeat' }))).toEqual(['enrollment_id', 'destination', 'reason', 'effective_on']);
    expect(requiredFields(kind({ kind: 'class_list', source: 'class' }))).toEqual(['class_id']);
    expect(requiredFields(kind({ kind: 'payment_attestation', source: 'invoice' }))).toEqual(['invoice_id']);
  });

  it("une nature n'est proposée que si elle est émise ici, disponible et permise", () => {
    expect(issuable(kind({}))).toBe(true);
    expect(issuable(kind({ direct: false }))).toBe(false);
    expect(issuable(kind({ available: false }))).toBe(false);
    expect(issuable(kind({ can_issue: false }))).toBe(false);
  });

  it('téléphone du payeur', () => {
    expect(validPhone('+237 600 00 00 01')).toBe(true);
    expect(validPhone('abc')).toBe(false);
  });
});

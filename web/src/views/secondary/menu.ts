// Menu de l'application secondaire (T837) : une entrée n'apparaît que si (1) son écran existe, (2) le rôle a l'une de ses
// permissions et (3) l'un de ses modules est utilisable (actif, ou coupé avec données en lecture seule). Aucune entrée d'un module
// inactif ; aucun test du type d'établissement (D9) : on lit l'état des modules du profil. Le vocabulaire (proviseur ou principal)
// vient de head_title du profil.
import type { MessageKey } from '@/i18n';
import { usableModules, type SecondaryModule, type SecondaryProfile } from '@/api/secondaryProfile';
import { S } from './access';

export interface MenuEntry {
  name: string;
  title: MessageKey;
  text: MessageKey;
  perms: readonly string[];
  /** Modules dont l'un doit être utilisable ; absent = noyau (toujours présent). */
  modules?: readonly SecondaryModule[];
}

export const MENU: MenuEntry[] = [
  { name: 'sec-profile', title: 'sec.profile.title', text: 'sec.home.profileText', perms: S.profileRead },
  { name: 'sec-setup', title: 'sec.menu.setup', text: 'sec.home.setupText', perms: S.settingsRead },
  { name: 'sec-enrolment', title: 'sec.menu.enrolment', text: 'sec.home.enrolmentText', perms: S.enrolRead },
  { name: 'sec-documents', title: 'sec.docs.title', text: 'sec.home.documentsText', perms: S.documentsRead },
  { name: 'sec-fees', title: 'sec.menu.fees', text: 'sec.home.feesText', perms: S.feesRead, modules: ['fees_private', 'fees_public_catalog'] },
  { name: 'sec-cartescolaire', title: 'sec.menu.cartescolaire', text: 'sec.home.cartescolaireText', perms: S.carteScolaire },
  { name: 'sec-grades', title: 'secg.title', text: 'secg.menuText', perms: S.grades },
  { name: 'sec-discipline', title: 'sec.menu.discipline', text: 'sec.home.disciplineText', perms: S.discipline },
  { name: 'sec-discipline-councils', title: 'sec.menu.disciplineCouncils', text: 'sec.home.disciplineCouncilsText', perms: S.disciplineCouncils },
  { name: 'sec-timetable', title: 'sectt.title', text: 'sectt.menuText', perms: S.timetable },
  { name: 'sec-councils', title: 'sec.menu.councils', text: 'sec.home.councilsText', perms: S.councils },
  { name: 'sec-report-cards', title: 'sec.menu.reportCards', text: 'sec.home.reportCardsText', perms: S.reportCards },
  { name: 'sec-syllabus', title: 'secsy.title', text: 'secsy.menuText', perms: S.syllabus },
  { name: 'sec-exams', title: 'sec.menu.exams', text: 'sec.home.examsText', perms: S.examsRead, modules: ['official_exams'] },
  { name: 'sec-orientation', title: 'sec.menu.orientation', text: 'sec.home.orientationText', perms: S.orientation, modules: ['orientation'] },
  { name: 'sec-workshops', title: 'sec.menu.workshops', text: 'sec.home.workshopsText', perms: S.workshops, modules: ['workshops'] },
  { name: 'sec-internships', title: 'sec.menu.internships', text: 'sec.home.internshipsText', perms: S.internships, modules: ['internships'] },
  { name: 'sec-staff', title: 'secst.title', text: 'secst.menuText', perms: S.staff },
  { name: 'sec-payroll', title: 'sec.menu.payroll', text: 'sec.home.payrollText', perms: S.payroll, modules: ['payroll_simple'] },
  { name: 'sec-services', title: 'sec.menu.services', text: 'sec.home.servicesText', perms: S.services, modules: ['boarding', 'canteen_transport', 'library', 'infirmary'] },
  { name: 'sec-finance-reports', title: 'sec.menu.financeReports', text: 'sec.home.financeReportsText', perms: S.financeReports },
  { name: 'sec-teacher', title: 'sec.menu.teacher', text: 'sec.home.teacherText', perms: S.teacher },
  { name: 'sec-dashboards', title: 'secdb.title', text: 'secdb.menuText', perms: S.dashboards },
  { name: 'sec-student', title: 'sec.portal.studentTitle', text: 'sec.home.studentText', perms: S.self },
  { name: 'sec-family', title: 'sec.portal.familyTitle', text: 'sec.home.familyText', perms: S.family },
];

/**
 * Entrées visibles. `hasRoute` dit si l'écran est livré (les écrans des lots suivants s'ajoutent sans toucher ce filtre), `can` si le
 * rôle a l'une des permissions. Sans profil (lecture refusée ou en cours), seules les entrées du noyau restent possibles.
 */
export function visibleEntries(
  profile: SecondaryProfile | null,
  can: (...perms: readonly string[]) => boolean,
  hasRoute: (name: string) => boolean,
  entries: readonly MenuEntry[] = MENU,
): MenuEntry[] {
  const usable = usableModules(profile);
  return entries.filter((e) => hasRoute(e.name) && can(...e.perms) && (e.modules === undefined || e.modules.some((m) => usable.has(m))));
}

/** Clé du titre du chef d'établissement selon le profil (vocabulaire, jamais un rôle de plus). */
export function headTitleKey(profile: SecondaryProfile | null): MessageKey {
  if (profile?.head_title === 'proviseur') return 'sec.head.proviseur';
  if (profile?.head_title === 'principal') return 'sec.head.principal';
  return 'sec.head.other';
}

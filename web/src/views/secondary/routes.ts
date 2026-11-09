// Routes de l'application « Enseignement secondaire » (/secondaire/*), réservées aux établissements de type `secondary`. Les écrans des
// autres lots s'ajoutent ici (édition ciblée) et apparaissent au menu par menu.ts (module actif + permission). Le serveur reste juge.
import type { RouteRecordRaw } from 'vue-router';
import type { MessageKey } from '@/i18n';
import { S, SPACE } from './access';

type Screen = [path: string, name: string, view: () => Promise<unknown>, perms: readonly string[], titleKey: MessageKey];

const screens: Screen[] = [
  ['profil', 'sec-profile', () => import('./setup/ProfileView.vue'), S.profileRead, 'sec.profile.title'],
  ['parametrage', 'sec-setup', () => import('./setup/SetupView.vue'), [...S.settingsRead, ...S.profileRead, 'secondary.enrol.manage'], 'secs.title'],
  ['inscriptions', 'sec-enrolment', () => import('./enrolment/EnrolmentView.vue'), [...S.enrolRead, 'secondary.settings.manage'], 'sece.title'],
  ['documents', 'sec-documents', () => import('./documents/DocumentsView.vue'), S.documentsRead, 'sec.docs.title'],
  ['frais', 'sec-fees', () => import('./fees/FeesView.vue'), S.feesRead, 'secf.title'],
  ['carte-scolaire', 'sec-cartescolaire', () => import('./cartescolaire/CarteScolaireView.vue'), S.carteScolaire, 'seccs.title'],
  ['notes', 'sec-grades', () => import('./grades/GradesView.vue'), S.grades, 'secg.title'],
  ['discipline', 'sec-discipline', () => import('./discipline/DisciplineView.vue'), S.discipline, 'secd.title'],
  ['conseils-de-discipline', 'sec-discipline-councils', () => import('./discipline/DisciplineCouncilsView.vue'), S.disciplineCouncils, 'secdc.title'],
  ['emploi-du-temps', 'sec-timetable', () => import('./timetable/TimetableView.vue'), S.timetable, 'sectt.title'],
  ['cahier-de-textes', 'sec-syllabus', () => import('./syllabus/SyllabusView.vue'), S.syllabus, 'secsy.title'],
  ['examens', 'sec-exams', () => import('./exams/OfficialExamsView.vue'), S.examsRead, 'sec.menu.exams'],
  ['ateliers', 'sec-workshops', () => import('./workshops/WorkshopsView.vue'), S.workshops, 'sec.menu.workshops'],
  ['stages', 'sec-internships', () => import('./workshops/InternshipsView.vue'), S.internships, 'sec.menu.internships'],
  ['personnel', 'sec-staff', () => import('./staff/StaffView.vue'), S.staff, 'secst.title'],
  ['paie', 'sec-payroll', () => import('./staff/PayrollView.vue'), S.payroll, 'secpay.title'],
  ['conseils-de-classe', 'sec-councils', () => import('./councils/ClassCouncilView.vue'), S.councils, 'secc.title'],
  ['bulletins', 'sec-report-cards', () => import('./councils/ReportCardView.vue'), S.reportCards, 'secb.title'],
  ['tableaux-de-bord', 'sec-dashboards', () => import('./dashboards/DashboardsView.vue'), S.dashboards, 'secdb.title'],
  ['orientation', 'sec-orientation', () => import('./councils/OrientationView.vue'), S.orientation, 'seco.title'],
  ['mon-espace', 'sec-student', () => import('./portals/StudentPortalView.vue'), S.self, 'sec.portal.studentTitle'],
  ['services', 'sec-services', () => import('./services/ServicesView.vue'), S.services, 'secsv.title'],
  ['situation-financiere', 'sec-finance-reports', () => import('./fees/FinanceReportsView.vue'), S.financeReports, 'secfr.title'],
  ['espace-enseignant', 'sec-teacher', () => import('./portals/TeacherPortalView.vue'), S.teacher, 'sect.title'],
  ['famille', 'sec-family', () => import('./portals/FamilyPortalView.vue'), S.family, 'sec.portal.familyTitle'],
];

const schoolOnly = { needsEstablishment: true, establishmentTypes: ['secondary'] };

export const secondaryRoutes: RouteRecordRaw[] = [
  {
    path: '/secondaire',
    name: 'sec-home',
    component: () => import('./HomeView.vue'),
    meta: { ...schoolOnly, perms: [...SPACE], titleKey: 'sec.home.title', nav: { labelKey: 'nav.secondary', order: 8 } },
  },
  ...screens.map(([path, name, view, perms, titleKey]): RouteRecordRaw => ({
    path: `/secondaire/${path}`,
    name,
    component: view,
    meta: { ...schoolOnly, perms: [...perms], titleKey },
  })),
];

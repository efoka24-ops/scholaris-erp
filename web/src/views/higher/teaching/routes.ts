// Routes « Enseignement et présences » de l'application IPES (lot I2), même forme que ../routes.ts.
// Les titres ht.* sont des MessageKey une fois higher-teaching-{fr,en}.ts fusionnés dans fr.ts/en.ts.
import type { RouteRecordRaw } from 'vue-router';
import type { MessageKey } from '@/i18n';
import { higherGuard } from '../access';
import { TP } from './access';
import type { HigherTeachingKey } from '@/i18n/higher-teaching-fr';

type Screen = [path: string, name: string, view: () => Promise<unknown>, perms: readonly string[], titleKey: HigherTeachingKey];

/** Écrans dans l'ordre des menus (TeachingFrame les reprend pour la sous-navigation). */
export const TEACHING_SCREENS: Screen[] = [
  ['enseignement/enseignants', 'higher-teachers', () => import('./TeachersView.vue'), TP.teacherRead, 'ht.nav.teachers'],
  ['enseignement/salles', 'higher-rooms', () => import('./RoomsView.vue'), TP.timetableRead, 'ht.nav.rooms'],
  ['enseignement/affectations', 'higher-assignments', () => import('./AssignmentsView.vue'), TP.assignRead, 'ht.nav.assignments'],
  ['enseignement/emploi-du-temps', 'higher-timetable', () => import('./TimetableView.vue'), TP.timetableRead, 'ht.nav.timetable'],
  ['enseignement/volumes', 'higher-consumption', () => import('./ConsumptionView.vue'), TP.timetableRead, 'ht.nav.consumption'],
  ['enseignement/cahier-de-textes', 'higher-session-logs', () => import('./SessionLogsView.vue'), TP.logAny, 'ht.nav.logs'],
  ['enseignement/heures', 'higher-hours', () => import('./HoursView.vue'), TP.logRead, 'ht.nav.hours'],
  ['enseignement/examens', 'higher-exams', () => import('./ExamsView.vue'), TP.examRead, 'ht.nav.exams'],
  ['presences/assiduite', 'higher-assiduity', () => import('./AssiduityView.vue'), TP.attendanceRead, 'ht.nav.assiduity'],
  ['presences/remplacements', 'higher-replacements', () => import('./ReplacementsView.vue'), TP.replacementRead, 'ht.nav.replacements'],
  ['presences/discipline', 'higher-discipline', () => import('./DisciplineView.vue'), TP.disciplineRead, 'ht.nav.discipline'],
];

const higherOnly = { needsEstablishment: true, establishmentTypes: ['higher'] };

export const higherTeachingRoutes: RouteRecordRaw[] = TEACHING_SCREENS.map(([path, name, view, perms, titleKey]): RouteRecordRaw => ({
  path: `/superieur/${path}`,
  name,
  component: view,
  beforeEnter: higherGuard,
  meta: { ...higherOnly, perms: [...perms], titleKey: titleKey as MessageKey },
}));

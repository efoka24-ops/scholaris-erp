// Routes des écoles publiques (primaire) : personnel d'une école (directeur, enseignant). Le serveur reste juge des droits ;
// les permissions ci-dessous adaptent seulement l'accès aux écrans (un rôle « agrégats seuls » n'en voit aucun).
import type { RouteRecordRaw } from 'vue-router';
import type { MessageKey } from '@/i18n';
import { P } from './access';

type Screen = [path: string, name: string, view: () => Promise<unknown>, perms: readonly string[], titleKey: MessageKey, withProps?: boolean];

const screens: Screen[] = [
  ['appel', 'ps-call', () => import('./CallView.vue'), P.attendanceWrite, 'ps.call.title'],
  ['classes', 'ps-classes', () => import('./ClassesView.vue'), P.pupilsRead, 'ps.classes.title'],
  ['eleves', 'ps-pupils', () => import('./PupilsView.vue'), P.pupilsRead, 'ps.pupils.title'],
  ['eleves/inscription', 'ps-enrol', () => import('./EnrolPupilView.vue'), P.pupilsWrite, 'ps.enrol.title'],
  ['eleves/:id', 'ps-pupil', () => import('./PupilView.vue'), P.pupilsRead, 'ps.pupil.title', true],
  ['transferts', 'ps-transfers', () => import('./TransfersView.vue'), P.pupilsWrite, 'ps.transfers.title'],
  ['abandons', 'ps-dropouts', () => import('./DropoutsView.vue'), P.pupilsWrite, 'ps.dropouts.title'],
  ['ecole', 'ps-school', () => import('./SchoolView.vue'), P.schoolRead, 'ps.school.title'],
  ['presence-enseignants', 'ps-presence', () => import('./TeacherPresenceView.vue'), P.presenceRead, 'ps.presence.title'],
  ['personnel', 'ps-staff', () => import('./StaffView.vue'), P.staffRead, 'ps.staff.title'],
  ['messages', 'ps-messages', () => import('./MessagesView.vue'), P.messagesSend, 'ps.messages.title'],
  ['appareil', 'ps-device', () => import('./DeviceView.vue'), P.attendanceWrite, 'ps.device.title'],
];

const schoolOnly = { needsEstablishment: true, establishmentTypes: ['primary'] };

export const publicSchoolsRoutes: RouteRecordRaw[] = [
  {
    path: '/ecole-publique',
    name: 'ps-home',
    component: () => import('./HomeView.vue'),
    meta: { ...schoolOnly, perms: [...P.space], titleKey: 'ps.home.title', nav: { labelKey: 'nav.publicSchools', order: 7 } },
  },
  ...screens.map(([path, name, view, perms, titleKey, withProps]): RouteRecordRaw => ({
    path: `/ecole-publique/${path}`,
    name,
    component: view,
    props: withProps === true,
    meta: { ...schoolOnly, perms: [...perms], titleKey },
  })),
];

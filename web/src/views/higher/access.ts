// Garde des routes IPES (beforeEnter) et permissions utilisées par les écrans. Le serveur refuse de toute façon (403, 404)
// ce que l'interface masquerait à tort : ces listes ne servent qu'à adapter menus et boutons.
import type { RouteLocationNormalized, RouteLocationRaw } from 'vue-router';
import { useContextStore } from '@/stores/context';
import { useHigherStore } from './store';

export const P = {
  access: ['higher.access'],
  settingsRead: ['higher.settings.read', 'higher.curriculum.read', 'higher.settings.manage'],
  settingsManage: ['higher.settings.manage'],
  registersRead: ['higher.settings.read', 'higher.curriculum.read', 'higher.tutelage.manage', 'higher.regulation.amend', 'higher.calendar.manage', 'higher.settings.manage'],
  tutelageManage: ['higher.tutelage.manage'],
  regulationAmend: ['higher.regulation.amend'],
  calendarManage: ['higher.calendar.manage', 'higher.settings.manage'],
  curriculumRead: ['higher.curriculum.read', 'higher.curriculum.manage', 'higher.curriculum.approve'],
  curriculumManage: ['higher.curriculum.manage'],
  curriculumApprove: ['higher.curriculum.approve'],
  admissionRead: ['higher.admission.read', 'higher.application.manage', 'higher.admission.decide'],
  applicationManage: ['higher.application.manage'],
  admissionDecide: ['higher.admission.decide'],
  enrolmentRead: ['higher.enrolment.read', 'higher.enrol', 'higher.enrolment.validate'],
  enrol: ['higher.enrol'],
  enrolmentValidate: ['higher.enrolment.validate'],
  derogate: ['higher.enrolment.derogate'],
  billingRead: ['higher.billing.read', 'billing.manage', 'billing.cash'],
  billingManage: ['billing.manage'],
  billingCash: ['billing.cash'],
  documentRead: ['higher.document.read', 'documents.issue', 'documents.templates.manage'],
  documentIssue: ['documents.issue'],
  documentTemplates: ['documents.templates.manage'],
  quitus: ['higher.quitus.issue'],
} as const;

/** Les routes IPES ne s'ouvrent que si l'application est visible pour le client ; sinon retour à l'accueil IPES (message). */
export async function higherGuard(to: RouteLocationNormalized): Promise<true | RouteLocationRaw> {
  const ctx = useContextStore();
  if (!ctx.establishmentId) return true; // le garde global renvoie déjà vers l'accueil
  const higher = useHigherStore();
  await higher.ensure();
  if (!higher.visible && to.name !== 'higher') return { name: 'higher' };
  return true;
}

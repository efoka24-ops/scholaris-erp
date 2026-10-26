// Permissions des rôles de la Maternelle privée, miroir de database/migrations/202610220801_roles_nursery.sql et 202610231303_roles_nursery_services_consents.sql (généré depuis la matrice du serveur ;
// le test nursery-private-permissions.test.ts compare ce fichier à la migration). Elles adaptent seulement menus et boutons : le serveur reste juge (403).
export const NURSERY_ROLE_PERMISSIONS: Record<string, string[]> = {
  nursery_promoteur: [
    'nursery.dashboard.read', 'reporting.read',
  ],
  nursery_directeur: [
    'nursery.settings.manage', 'nursery.section.manage', 'nursery.child.read', 'nursery.pickup.read',
    'nursery.pickup.restrict', 'nursery.pickup.manage', 'nursery.pickup.handover', 'nursery.register.read',
    'nursery.dev.read', 'nursery.dev.validate', 'nursery.frame.manage', 'nursery.lifebook.read',
    'nursery.need.read', 'nursery.allergy.read', 'nursery.message.send', 'nursery.transition.decide',
    'nursery.dashboard.read', 'nursery.service.manage', 'nursery.service.attend', 'nursery.consent.manage',
  ],
  nursery_educator: [
    'nursery.child.read', 'nursery.pickup.handover', 'nursery.register.read', 'nursery.arrival.record',
    'nursery.dev.observe', 'nursery.dev.read', 'nursery.lifebook.write', 'nursery.lifebook.read',
    'nursery.need.report', 'nursery.need.read', 'nursery.allergy.read', 'nursery.message.send',
    'nursery.transition.opinion', 'attendance.take', 'nursery.service.attend',
  ],
  nursery_assistant: [
    'nursery.child.read', 'nursery.pickup.handover', 'nursery.arrival.record', 'nursery.register.read',
    'nursery.allergy.read', 'attendance.take', 'nursery.service.attend',
  ],
  nursery_secretaire: [
    'people.manage', 'nursery.enrol', 'nursery.child.read', 'documents.issue',
    'nursery.pickup.manage', 'nursery.pickup.read', 'nursery.register.read', 'nursery.allergy.read',
    'nursery.service.manage', 'nursery.consent.manage',
  ],
  nursery_cantine: [
    'nursery.allergy.read', 'nursery.service.attend',
  ],
  // Le parent n'a aucune permission nursery.* en base : ses routes de portail vérifient qu'il est garant de l'enfant.
};

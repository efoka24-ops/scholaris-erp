// Permissions utilisées par les écrans des écoles publiques (le serveur refuse de toute façon ce que l'interface masquerait à tort :
// ces listes ne servent qu'à adapter menus et boutons). Un rôle « agrégats seuls » (public_schools.aggregates.read) n'en détient
// aucune : aucun écran nominatif ne lui est proposé.
export const P = {
  schoolRead: ['public_schools.school.read'],
  schoolWrite: ['public_schools.school.write'],
  pupilsRead: ['public_schools.pupils.read'],
  pupilsWrite: ['public_schools.pupils.write'],
  attendanceRead: ['public_schools.attendance.read'],
  attendanceWrite: ['public_schools.attendance.write'],
  presenceRead: ['public_schools.teacher_presence.read'],
  presenceWrite: ['public_schools.teacher_presence.write'],
  /** Contrôle de l'inspection sur la présence des enseignants (échantillon, confirmation ou écart). */
  presenceValidate: ['public_schools.teacher_presence.validate'],
  staffRead: ['public_schools.staff.read'],
  staffWrite: ['public_schools.staff.write'],
  messagesSend: ['public_schools.messages.send'],
  /** Photos géolocalisées de visite : lecture (direction, inspection), envoi (inspection, conseillers pédagogiques). */
  inspectionRead: ['public_schools.inspection.read'],
  inspectionWrite: ['public_schools.inspection.write'],
  devicesManage: ['sync.devices.manage'],
  /** Alertes d'assiduité : lues avec la permission d'appel ; classes sans enseignant : avec la présence des enseignants. */
  alerts: ['public_schools.attendance.read', 'public_schools.teacher_presence.read'],
  /** Lot P2 : évaluation (enseignant titulaire, directeur), bulletins, passage, APEE, questionnaire statistique, référentiel. */
  evaluation: ['public_primary.evaluation.write', 'public_primary.evaluation.validate'],
  promotion: ['public_primary.promotion.write', 'public_primary.promotion.validate', 'public_primary.promotion.opinion'],
  apee: ['public_schools.apee.read'],
  census: ['public_schools.census.write', 'public_schools.census.validate'],
  referential: [
    'public_primary.referential.manage',
    'public_primary.evaluation.write',
    'public_primary.evaluation.validate',
    'public_primary.promotion.validate',
  ],
  /** Accès à l'espace : au moins une permission de travail dans l'école (école, élèves, appel, personnel, messages, inspection). */
  space: [
    'public_primary.evaluation.write',
    'public_primary.evaluation.validate',
    'public_primary.promotion.opinion',
    'public_primary.referential.manage',
    'public_schools.apee.read',
    'public_schools.census.write',
    'public_schools.pupils.read',
    'public_schools.attendance.write',
    'public_schools.teacher_presence.read',
    'public_schools.staff.read',
    'public_schools.messages.send',
    'public_schools.inspection.write',
  ],
} as const;

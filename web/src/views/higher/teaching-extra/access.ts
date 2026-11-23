// Permissions des écrans « délégués » et « emploi du temps imprimable » (miroir de TeachingPermissions côté serveur).
export const XP = {
  delegateRead: ['scheduling.manage', 'higher.assignment.manage', 'higher.session_log.read', 'higher.session_log.validate', 'higher.hours.validate'],
  delegateManage: ['scheduling.manage', 'higher.assignment.manage'],
  timetableRead: ['higher.timetable.read', 'scheduling.manage'],
} as const;

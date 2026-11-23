// Permissions des écrans « Vacations » (miroir de VacationPermissions côté serveur). Elles n'adaptent que menus et boutons :
// le serveur refuse de toute façon (403, 404).
export const VP = {
  read: ['higher.vacation.read', 'higher.vacation.validate', 'higher.vacation.validate_school', 'higher.vacation.pay', 'higher.hours.validate'],
  readOwn: ['higher.vacation.read_own'],
  prepare: ['higher.hours.validate', 'higher.vacation.pay'],
  validateSchool: ['higher.vacation.validate_school'],
  approve: ['higher.vacation.validate'],
  pay: ['higher.vacation.pay'],
  reject: ['higher.vacation.validate_school', 'higher.vacation.validate', 'higher.vacation.pay'],
  statementDocument: ['higher.hours.validate', 'higher.vacation.validate_school', 'higher.vacation.pay'],
} as const;

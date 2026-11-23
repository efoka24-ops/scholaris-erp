// Permissions des écrans de délibération (DeliberationPermissions.php, RoleMatrix « deliberations »). Le serveur tranche de toute façon.
export const DP = {
  read: ['higher.deliberation.read', 'higher.deliberation.prepare', 'higher.deliberation.preside'],
  prepare: ['higher.deliberation.prepare'],
  preside: ['higher.deliberation.preside'],
  prepareOrPreside: ['higher.deliberation.prepare', 'higher.deliberation.preside'],
  sign: ['assessment.deliberation.sign'],
  publish: ['assessment.publish', 'higher.deliberation.prepare'],
  portal: ['higher.jury.portal'],
  self: ['self.read'],
} as const;

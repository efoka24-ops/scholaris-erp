// Permissions des écrans « Examens nationaux » et « Tutelle » (NationalExamsPermissions, TutelagePermissions). Le serveur
// contrôle le périmètre (403/404) : ces listes adaptent seulement menus et boutons.
export const X = {
  read: ['higher.national_list.manage', 'higher.national_list.export'],
  manage: ['higher.national_list.manage'],
  export: ['higher.national_list.export'],
} as const;

export const T = {
  internal: ['higher.tutelage.manage', 'higher.tutelage.transmit'],
  external: ['higher.tutelage.read'],
} as const;

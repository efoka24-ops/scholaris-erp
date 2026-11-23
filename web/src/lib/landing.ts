// Page d'arrivée selon le rôle exercé, après le choix de l'établissement.
export function landingFor(role: string | null | undefined): string {
  switch (role) {
    case 'parent':
      return 'portal-family';
    case 'apprenant':
      return 'portal-learner';
    case 'enseignant':
      return 'attendance';
    case 'comptable':
    case 'caissier':
      return 'billing';
    case 'super_admin':
    case 'direction_groupe':
    case 'directeur':
      return 'dashboard';
    default:
      return 'home';
  }
}

/** Vocabulaire par type d'établissement (RG-IHM-5). La clé est celle de i18n : vocab.<terme>.<type>. */
export const ESTABLISHMENT_TYPES = ['nursery', 'primary', 'secondary', 'higher'] as const;
export type EstablishmentType = (typeof ESTABLISHMENT_TYPES)[number];

export function asEstablishmentType(type: string | null | undefined): EstablishmentType {
  return (ESTABLISHMENT_TYPES as readonly string[]).includes(type ?? '') ? (type as EstablishmentType) : 'secondary';
}

// Annuaire des noms de l'IPES : GET /api/v1/higher/directory/names?ids=a,b,c (100 au plus ; contrat openapi.d/higher-directory.yaml).
// Seules les personnes rattachées à l'établissement sont rendues ; un identifiant absent de la réponse reste affiché tel quel.
import { http } from './http';
import { H } from './higher';

export interface PersonName { person_id: string; first_name: string; last_name: string; matricule: string | null }
export const NAMES_MAX = 100;

export const directory = {
  names: async (ids: string[]): Promise<PersonName[]> => (await http.get<{ data: PersonName[] }>(`${H}/directory/names`, { ids: ids.join(',') })).data,
};

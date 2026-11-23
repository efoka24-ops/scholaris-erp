import { http } from './http';

/** Compte créé ou ré-émis avec un mot de passe provisoire : le mot de passe n'est rendu qu'une fois, ne jamais le stocker. */
export interface ProvisionalAccount {
  user_id: string;
  login: string;
  status: string;
  must_change_password: boolean;
  provisional_password: string;
  person_id?: string;
  person_linked?: boolean;
}

export interface NewStaffInput {
  first_name: string;
  last_name: string;
  phone?: string;
  email?: string;
  locale: string;
  person_id?: string;
}

/** POST /users avec `provisional_password` : compte directement utilisable, mot de passe provisoire généré par le serveur. */
export const createStaffAccount = async (input: NewStaffInput): Promise<ProvisionalAccount> =>
  (await http.post<{ data: ProvisionalAccount }>('/users', { ...input, provisional_password: true })).data;

/** POST /users/{id}/provisional-password : nouveau mot de passe provisoire (mot de passe perdu). */
export const reissueProvisionalPassword = async (userId: string, resetTwoFactor = false): Promise<ProvisionalAccount> =>
  (await http.post<{ data: ProvisionalAccount }>(`/users/${userId}/provisional-password`, { reset_two_factor: resetTwoFactor })).data;

/** POST /auth/change-password : remplace le mot de passe (obligatoire après un mot de passe provisoire). */
export const changePassword = (input: { current_password: string; new_password: string; new_password_confirmation: string }): Promise<{ changed: boolean }> =>
  http.post<{ changed: boolean }>('/auth/change-password', input);

/** POST /auth/reconfirm : confirmation récente par mot de passe (10 minutes) pour qui n'a pas de double authentification. */
export const reconfirmPassword = (password: string): Promise<{ confirmed: boolean; confirmed_at: string; valid_for: number }> =>
  http.post('/auth/reconfirm', { password });

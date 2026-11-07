// « Mon profil » (C-112 point 6) : identité, coordonnées, langue, sessions et réglages personnels de la personne connectée.
// Le mot de passe et la double authentification restent sur les écrans existants (/changer-mot-de-passe, /securite).
import { http } from './http';

export interface ProfileSession { id: string; device: string | null; created_at: string; last_used_at: string | null; expires_at: string; current: boolean }
export interface MyProfile {
  user_id: string;
  first_name: string;
  last_name: string;
  phone: string | null;
  email: string | null;
  locale: 'fr' | 'en';
  two_factor_enabled: boolean;
  last_login_at: string | null;
  platform_member: boolean;
  sessions: ProfileSession[];
  preferences: { channels: { app: boolean; sms: boolean; email: boolean }; daily_digest: boolean };
}
export interface ProfileChanges { first_name?: string; last_name?: string; phone?: string | null; email?: string | null; locale?: 'fr' | 'en' }

const SESSION = { establishmentId: false as const };

export const fetchProfile = async (): Promise<MyProfile> => (await http.get<{ data: MyProfile }>('/me/profile', undefined, SESSION)).data;
export const updateProfile = async (changes: ProfileChanges): Promise<MyProfile> => (await http.patch<{ data: MyProfile }>('/me/profile', { ...changes }, SESSION)).data;
export const updatePreferences = async (prefs: { notify_sms?: boolean; notify_email?: boolean; daily_digest?: boolean }): Promise<MyProfile> =>
  (await http.patch<{ data: MyProfile }>('/me/preferences', { ...prefs }, SESSION)).data;
export const logoutOtherDevices = async (): Promise<number> =>
  (await http.post<{ data: { revoked_sessions: number } }>('/me/sessions/logout-others', {}, SESSION)).data.revoked_sessions;

import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { ApiError, http } from '@/api/http';
import type { LoginResponse, Me } from '@/api/types';

export interface LoginInput {
  identifier: string;
  password: string;
  remember: boolean;
}

export const useAuthStore = defineStore('auth', () => {
  const me = ref<Me | null>(null);
  const loaded = ref(false);
  const lastLogin = ref<LoginResponse | null>(null);

  const isAuthenticated = computed(() => me.value !== null);
  const displayName = computed(() => me.value?.greeting ?? me.value?.first_name ?? '');
  const twoFactorPending = computed(() => !!me.value && me.value.two_factor.required && me.value.two_factor.enabled && !me.value.two_factor.verified);
  const twoFactorSetupNeeded = computed(() => !!me.value && me.value.two_factor.required && !me.value.two_factor.enabled);

  async function login(input: LoginInput): Promise<LoginResponse> {
    // Le client web reçoit le jeton dans un cookie HttpOnly : il n'apparaît jamais dans le JavaScript de la page.
    const res = await http.post<LoginResponse>('/auth/login', {
      identifier: input.identifier.trim(),
      password: input.password,
      remember: input.remember,
      client: 'web',
    });
    lastLogin.value = res;
    await loadMe(true);
    return res;
  }

  /** Charge le profil. Retourne null (sans erreur) si la personne n'est pas connectée. */
  async function loadMe(force = false): Promise<Me | null> {
    if (loaded.value && !force) return me.value;
    try {
      const res = await http.get<{ data: Me }>('/me');
      me.value = res.data;
    } catch (e) {
      if (e instanceof ApiError && (e.status === 401 || e.status === 403)) {
        me.value = null;
      } else if (!(e instanceof ApiError && e.network)) {
        throw e;
      }
      // Hors ligne : on conserve le profil déjà connu en mémoire, s'il existe.
    }
    loaded.value = true;
    return me.value;
  }

  async function logout(): Promise<void> {
    try {
      await http.post('/auth/logout');
    } catch {
      /* la session locale est quand même effacée */
    }
    clear();
  }

  function clear(): void {
    me.value = null;
    lastLogin.value = null;
    loaded.value = true;
  }

  return { me, loaded, lastLogin, isAuthenticated, displayName, twoFactorPending, twoFactorSetupNeeded, login, loadMe, logout, clear };
});

import { createRouter, createWebHistory, type Router, type RouteLocationNormalized, type RouteLocationRaw } from 'vue-router';
import { routes } from './routes';
import { useAuthStore } from '@/stores/auth';
import { useContextStore } from '@/stores/context';
import { usePlatformStore } from '@/stores/platform';
import { translate } from '@/i18n';
import { http, type RegularisationLevel } from '@/api/http';
import type { Problem } from '@/api/types';
import { invalidateRegularisation, isLockedForRegularisation, regularisationNotice } from '@/views/platform/regularisationGate';

/**
 * Décide où envoyer la personne pour une destination donnée (true = laisser passer).
 * Séparé du routeur pour être testé sans navigation.
 */
export async function guard(to: RouteLocationNormalized): Promise<RouteLocationRaw | true> {
  const auth = useAuthStore();
  const ctx = useContextStore();

  if (!auth.loaded) await auth.loadMe();

  if (to.meta.public) {
    if (auth.isAuthenticated && (to.name === 'login' || to.name === 'activation')) return { name: 'home' };
    return true;
  }

  if (!auth.isAuthenticated) return { name: 'login', query: to.fullPath !== '/' ? { redirect: to.fullPath } : {} };

  // Mot de passe provisoire : à remplacer avant tout (le serveur n'ouvre rien d'autre à cette session).
  if (auth.passwordChangeRequired && to.name !== 'change-password') return { name: 'change-password' };

  // Double authentification : exigée pour les rôles sensibles, avant tout accès aux ressources.
  if (auth.twoFactorSetupNeeded && to.name !== 'twofactor-setup') return { name: 'twofactor-setup' };
  if (auth.twoFactorPending && to.name !== 'twofactor') return { name: 'twofactor' };
  if (to.meta.bare) return true;

  if (!ctx.loaded && auth.me) {
    try {
      await ctx.load(auth.me.context);
    } catch {
      // Hors ligne ou erreur : l'écran d'accueil affichera le message ; les écrans hors ligne restent utilisables.
    }
  }

  // Séparation plateforme / client (le serveur l'impose de toute façon) : l'administrateur de plateforme ne voit que ses écrans
  // (et la sécurité de son compte) ; un compte client n'ouvre jamais un écran /platform.
  const platform = usePlatformStore();
  if (await platform.ensure(auth.me, { loaded: ctx.loaded, tiles: ctx.tiles.length })) {
    if (auth.me && !auth.me.two_factor.verified) return { name: 'twofactor' };
    if (to.meta.platform || to.name === 'security' || to.name === 'forbidden') return true;
    return { name: 'platform-dashboard' };
  }
  if (to.meta.platform) return { name: 'forbidden' };

  // Mode régularisation de paiement (niveau « bloqué ») : toute page, sauf l'écran de régularisation, y renvoie. La déconnexion et
  // le paiement se font depuis cet écran ; le serveur refuse de toute façon l'accès aux applications bloquées.
  if (auth.me && to.name !== 'regularisation' && (await isLockedForRegularisation(auth.me.id))) return { name: 'regularisation' };

  if (to.meta.needsEstablishment && !ctx.establishmentId && ctx.loaded) return { name: 'home' };
  if (to.meta.roles && !to.meta.roles.some((r) => ctx.activeRole === r)) return { name: 'forbidden' };
  if (to.meta.perms && ctx.loaded && !ctx.can(...to.meta.perms)) return { name: 'forbidden' };
  return true;
}

/** Routeurs dont une redirection vers l'écran de régularisation est en cours. */
const redirecting = new WeakSet<Router>();

/**
 * Réponse 423 du blocage central reçue par un onglet déjà ouvert (sans attendre la navigation suivante) :
 * - `locked` : le cache du garde est oublié et la personne est envoyée TOUT DE SUITE vers l'écran de régularisation, sauf si elle
 *   y est déjà, sur un écran public, sans cadre (connexion, double authentification) ou de plateforme, ou si une redirection est
 *   en cours (plusieurs appels refusés en même temps) : jamais de boucle ;
 * - `read_only` : pas de redirection ; le message du serveur est montré par le bandeau d'abonnement, qui se relit.
 */
export function handleRegularisationProblem(router: Router, level: RegularisationLevel, problem: Problem): void {
  invalidateRegularisation();
  if (level === 'read_only') {
    regularisationNotice.value = problem.detail || problem.title || null;
    return;
  }
  const current = router.currentRoute.value;
  if (redirecting.has(router) || current.name === 'regularisation' || current.meta.public || current.meta.bare || current.meta.platform) return;
  redirecting.add(router);
  void router.replace({ name: 'regularisation' }).finally(() => redirecting.delete(router));
}

export function createAppRouter(history = createWebHistory(import.meta.env.BASE_URL)): Router {
  const router = createRouter({ history, routes });
  http.configure({ onRegularisation: (level, problem) => handleRegularisationProblem(router, level, problem) });
  router.beforeEach(guard);
  // Le message d'une écriture refusée (lecture seule) ne survit pas au changement d'écran.
  router.afterEach((to, from) => {
    if (to.path !== from.path) regularisationNotice.value = null;
  });
  router.afterEach((to) => {
    const title = to.meta.titleKey ? `${translate(to.meta.titleKey)} · SCHOLARIS` : 'SCHOLARIS';
    if (typeof document !== 'undefined') document.title = title;
  });
  return router;
}

import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter } from 'vue-router';
import { IDBFactory } from 'fake-indexeddb';
import { mount, flushPromises as vueFlush, type VueWrapper } from '@vue/test-utils';
import type { Component } from 'vue';
import { http } from '@/api/http';
import { routes } from '@/router/routes';
import { resetDbForTests } from '@/offline/db';
import { setLocale } from '@/i18n';
import { useAuthStore } from '@/stores/auth';
import { useContextStore } from '@/stores/context';
import type { ContextTile, Me } from '@/api/types';

export interface RecordedCall {
  method: string;
  path: string;
  query: Record<string, string>;
  headers: Record<string, string>;
  body: Record<string, unknown> | undefined;
}

type Handler = Response | ((call: RecordedCall) => unknown) | { [key: string]: unknown } | unknown[];

export function jsonResponse(body: unknown, status = 200, headers: Record<string, string> = {}): Response {
  const problem = status >= 400;
  return new Response(status === 204 ? null : JSON.stringify(body), {
    status,
    headers: { 'Content-Type': problem ? 'application/problem+json' : 'application/json', ...headers },
  });
}

/** Simule l'API : « METHODE /chemin » → réponse (valeur JSON, Response ou fonction). Retourne la liste des appels reçus. */
/** Corps JSON, ou undefined pour un corps brut (CSV d'import, par exemple). */
function parseJsonBody(raw: string): Record<string, unknown> | undefined {
  try {
    return JSON.parse(raw) as Record<string, unknown>;
  } catch {
    return undefined;
  }
}

export function mockApi(map: Record<string, Handler>): RecordedCall[] {
  const calls: RecordedCall[] = [];
  http.configure({
    baseUrl: '/api/v1',
    getEstablishmentId: () => useContextStore().establishmentId,
    getLocale: () => 'fr',
    onUnauthorized: undefined,
    fetchImpl: (async (input: RequestInfo | URL, init?: RequestInit) => {
      const url = new URL(String(input), 'http://localhost');
      const path = url.pathname.replace(/^\/api\/v1/, '');
      const method = (init?.method ?? 'GET').toUpperCase();
      const call: RecordedCall = {
        method,
        path,
        query: Object.fromEntries(url.searchParams.entries()),
        headers: Object.fromEntries(Object.entries((init?.headers ?? {}) as Record<string, string>)),
        body: typeof init?.body === 'string' ? parseJsonBody(init.body) : undefined,
      };
      calls.push(call);
      const handler = map[`${method} ${path}`];
      if (handler === undefined) {
        return jsonResponse({ type: 'about:blank', title: 'Introuvable', status: 404, detail: `Route simulée absente : ${method} ${path}`, trace_id: 'TEST' }, 404);
      }
      const value = typeof handler === 'function' ? (handler as (c: RecordedCall) => unknown)(call) : handler;
      return value instanceof Response ? value : jsonResponse(value);
    }) as typeof fetch,
  });
  return calls;
}

export function freshEnvironment(): void {
  (globalThis as { indexedDB: IDBFactory }).indexedDB = new IDBFactory();
  resetDbForTests();
  setActivePinia(createPinia());
  setLocale('fr');
}

export const teacherMe: Me = {
  id: 'U1',
  person_id: 'P-TEACH',
  first_name: 'Joseph',
  last_name: 'Ateba',
  locale: 'fr',
  status: 'active',
  greeting: 'Joseph',
  two_factor: { enabled: true, required: true, verified: true },
  context: { establishment_id: 'E1', role: 'enseignant' },
};

export function tile(over: Partial<ContextTile> = {}): ContextTile {
  return { kind: 'establishment', id: 'E1', name: 'Collège Les Palmiers', type: 'secondary', accent: '#F26B1D', logo: null, roles: [{ code: 'enseignant', label: 'Enseignant' }], ...over };
}

export function signIn(me: Me = teacherMe, tiles: ContextTile[] = [tile()], role = me.context.role): void {
  const auth = useAuthStore();
  const ctx = useContextStore();
  auth.me = me;
  auth.loaded = true;
  ctx.tiles = tiles;
  ctx.activeId = tiles[0]?.id ?? null;
  ctx.activeRole = role;
  ctx.loaded = true;
}

export async function mountView(component: Component, props: Record<string, unknown> = {}, path = '/'): Promise<{ wrapper: VueWrapper; router: ReturnType<typeof createRouter> }> {
  const router = createRouter({ history: createMemoryHistory(), routes });
  await router.push(path);
  await router.isReady();
  const wrapper = mount(component, { props, global: { plugins: [router] } });
  await flushPromises();
  return { wrapper, router };
}

/** Laisse passer les promesses ET les tâches différées (IndexedDB simulé, imports dynamiques de vues). */
export async function flushPromises(rounds = 6): Promise<void> {
  for (let i = 0; i < rounds; i++) {
    await new Promise((r) => setTimeout(r, 15));
    await vueFlush();
  }
}

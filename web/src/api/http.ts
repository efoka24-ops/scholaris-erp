import type { Page, Problem } from './types';
import { ulid } from '@/lib/ulid';

export type QueryValue = string | number | boolean | null | undefined;

export interface HttpConfig {
  baseUrl: string;
  fetchImpl?: typeof fetch;
  /** Établissement actif ; null pour la vue de groupe ou avant le choix du contexte. */
  getEstablishmentId: () => string | null;
  getLocale: () => string;
  /** Jeton Bearer (mobile). Le web s'authentifie par cookie HttpOnly et n'en a pas besoin. */
  getToken?: () => string | null;
  /** Appelé sur un 401 hors connexion : la session a expiré. */
  onUnauthorized?: () => void;
  /**
   * Appelé sur un 423 de régularisation de paiement (hors liste blanche) : `locked` = accès bloqué (écran de régularisation),
   * `read_only` = abonnement suspendu, écriture refusée (lecture et export permis).
   */
  onRegularisation?: (level: RegularisationLevel, problem: Problem) => void;
}

export type RegularisationLevel = 'locked' | 'read_only';

/** Types d'erreur 423 du blocage central (middleware serveur EnforceTenantRestriction). */
export const REGULARISATION_REQUIRED = 'https://api.scholaris.cm/errors/regularisation-required';
export const SUBSCRIPTION_SUSPENDED = 'https://api.scholaris.cm/errors/subscription-suspended';
/** Liste blanche du serveur (EnforceTenantRestriction::ALLOWED), chemins sous /api/v1 : jamais de redirection pour ces appels. */
const REGULARISATION_ALLOWED = /^\/(auth\/|me$|me\/contexts?$|me\/regularisation(\/|$)|applications\/[^/]+\/payments$|application-payments\/[^/]+\/receipt$|public\/|health$|platform\/|webhooks?\/)/;

/** Niveau de restriction porté par une réponse 423, ou null (autre erreur, ou appel de la liste blanche). */
export function regularisationLevel(status: number, problem: Problem | null, path: string): RegularisationLevel | null {
  if (status !== 423 || !problem || REGULARISATION_ALLOWED.test(path.split('?')[0] ?? path)) return null;
  if (problem.type === REGULARISATION_REQUIRED) return 'locked';
  if (problem.type === SUBSCRIPTION_SUSPENDED) return 'read_only';
  return null;
}

export interface RequestOptions {
  query?: Record<string, QueryValue>;
  body?: unknown;
  /** Remplace l'établissement actif ; false = ne jamais envoyer X-Establishment-Id. */
  establishmentId?: string | null | false;
  /** Clé d'idempotence fournie par l'appelant (à conserver entre deux tentatives d'un même envoi). */
  idempotencyKey?: string;
  signal?: AbortSignal;
  headers?: Record<string, string>;
  /** Envoi de fichier (multipart/form-data) : l'en-tête Content-Type, avec sa frontière, est posé par le navigateur. */
  form?: FormData;
  /** Corps brut (ex. CSV) au lieu de JSON. */
  rawBody?: { contentType: string; data: string };
  expect?: 'json' | 'blob' | 'text';
}

export class ApiError extends Error {
  readonly status: number;
  readonly problem: Problem | null;
  readonly retryAfter: number | null;
  readonly network: boolean;

  constructor(status: number, problem: Problem | null, opts: { retryAfter?: number | null; network?: boolean; message?: string } = {}) {
    super(opts.message ?? problem?.detail ?? problem?.title ?? `HTTP ${status}`);
    this.name = 'ApiError';
    this.status = status;
    this.problem = problem;
    this.retryAfter = opts.retryAfter ?? null;
    this.network = opts.network ?? false;
  }

  get traceId(): string | null {
    return this.problem?.trace_id ?? null;
  }
  get slug(): string {
    const t = this.problem?.type ?? '';
    return t.substring(t.lastIndexOf('/') + 1);
  }
  get fieldErrors(): Record<string, string[]> {
    return this.problem?.errors ?? {};
  }
  get isConflict(): boolean {
    return this.status === 409;
  }
  get isTwoFactorRequired(): boolean {
    return this.status === 403 && this.slug === 'two-factor-required';
  }
}

/** Routes où l'API exige une clé d'idempotence (conventions : POST /payments, /cash/receipts, /documents). */
const IDEMPOTENT_POST = /^\/(payments|cash\/receipts|documents)$/;
/**
 * Routes hors contexte d'établissement : l'en-tête X-Establishment-Id n'y est jamais envoyé. Sous /public, seules les routes
 * anonymes (catalogue, demandes d'accès, candidature en ligne, vérification d'un document) en sont exclues : les routes
 * authentifiées des écoles publiques (/public/classes, /public/pupils…) exigent l'en-tête.
 */
const NO_ESTABLISHMENT = /^\/(auth|me|portal|health|vocabulary)(\/|$)|^\/public\/(applications|access-requests|admissions|verify)(\/|$)/;
const MUTATING = new Set(['POST', 'PUT', 'PATCH', 'DELETE']);
const OVERRIDDEN = new Set(['PUT', 'PATCH', 'DELETE']);

export class HttpClient {
  constructor(private cfg: HttpConfig) {}

  configure(cfg: Partial<HttpConfig>): void {
    this.cfg = { ...this.cfg, ...cfg };
  }

  buildUrl(path: string, query?: Record<string, QueryValue>): string {
    const params = new URLSearchParams();
    for (const [k, v] of Object.entries(query ?? {})) {
      if (v !== undefined && v !== null && v !== '') params.set(k, String(v));
    }
    const qs = params.toString();
    return `${this.cfg.baseUrl}${path}${qs ? `?${qs}` : ''}`;
  }

  async request<T = unknown>(method: string, path: string, opts: RequestOptions = {}): Promise<T> {
    const headers: Record<string, string> = {
      Accept: 'application/json, application/problem+json',
      'Accept-Language': this.cfg.getLocale(),
      // Exigé par l'API pour toute écriture authentifiée par cookie (protection CSRF).
      'X-Requested-With': 'XMLHttpRequest',
      ...opts.headers,
    };
    const upper = method.toUpperCase();
    // L'hébergement de production refuse PUT, PATCH et DELETE (403 avant PHP) : envoi en POST, méthode réelle en en-tête.
    const sent = OVERRIDDEN.has(upper) ? 'POST' : upper;
    if (sent !== upper) headers['X-HTTP-Method-Override'] = upper;

    const establishment = opts.establishmentId === undefined ? this.cfg.getEstablishmentId() : opts.establishmentId;
    if (establishment && opts.establishmentId !== false && !NO_ESTABLISHMENT.test(path)) {
      headers['X-Establishment-Id'] = establishment;
    }

    if (upper === 'POST' && (opts.idempotencyKey || IDEMPOTENT_POST.test(path))) {
      headers['Idempotency-Key'] = opts.idempotencyKey ?? ulid();
    }

    const token = this.cfg.getToken?.();
    if (token) headers.Authorization = `Bearer ${token}`;

    let body: BodyInit | undefined;
    if (opts.form) {
      body = opts.form;
    } else if (opts.rawBody) {
      headers['Content-Type'] = opts.rawBody.contentType;
      body = opts.rawBody.data;
    } else if (opts.body !== undefined && MUTATING.has(upper)) {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(opts.body);
    }

    const doFetch = this.cfg.fetchImpl ?? ((...a: Parameters<typeof fetch>) => fetch(...a));
    let response: Response;
    try {
      response = await doFetch(this.buildUrl(path, opts.query), {
        method: sent,
        headers,
        body,
        credentials: 'same-origin',
        signal: opts.signal,
      });
    } catch (e) {
      if (e instanceof DOMException && e.name === 'AbortError') throw e;
      throw new ApiError(0, null, { network: true, message: 'Réseau indisponible' });
    }

    if (response.ok) {
      if (response.status === 204) return undefined as T;
      if (opts.expect === 'blob') return (await response.blob()) as T;
      if (opts.expect === 'text') return (await response.text()) as T;
      return (await response.json().catch(() => undefined)) as T;
    }

    const problem = await readProblem(response);
    const retry = response.headers.get('Retry-After');
    const retryAfter = retry && /^\d+$/.test(retry) ? parseInt(retry, 10) : null;
    const error = new ApiError(response.status, problem, { retryAfter });
    if (response.status === 401 && !path.startsWith('/auth/login') && !path.startsWith('/auth/activation')) {
      this.cfg.onUnauthorized?.();
    }
    const level = regularisationLevel(response.status, problem, path);
    if (level && problem) this.cfg.onRegularisation?.(level, problem);
    throw error;
  }

  get<T>(path: string, query?: Record<string, QueryValue>, opts: RequestOptions = {}): Promise<T> {
    return this.request<T>('GET', path, { ...opts, query });
  }
  post<T>(path: string, body?: unknown, opts: RequestOptions = {}): Promise<T> {
    return this.request<T>('POST', path, { ...opts, body: body ?? {} });
  }
  put<T>(path: string, body?: unknown, opts: RequestOptions = {}): Promise<T> {
    return this.request<T>('PUT', path, { ...opts, body });
  }
  patch<T>(path: string, body?: unknown, opts: RequestOptions = {}): Promise<T> {
    return this.request<T>('PATCH', path, { ...opts, body });
  }
  delete<T = void>(path: string, opts: RequestOptions = {}): Promise<T> {
    return this.request<T>('DELETE', path, opts);
  }

  /** Une page de liste (pagination par curseur : data, links, meta.next_cursor). */
  async page<T>(path: string, query: Record<string, QueryValue> = {}, opts: RequestOptions = {}): Promise<{ items: T[]; nextCursor: string | null }> {
    const res = await this.get<Page<T>>(path, { per_page: 50, ...query }, opts);
    return { items: res.data ?? [], nextCursor: res.meta?.next_cursor ?? null };
  }

  /** Parcourt toutes les pages (plafonné) en suivant le curseur. */
  async all<T>(path: string, query: Record<string, QueryValue> = {}, opts: RequestOptions = {}, maxPages = 20): Promise<T[]> {
    const out: T[] = [];
    let cursor: string | null = null;
    for (let i = 0; i < maxPages; i++) {
      const { items, nextCursor }: { items: T[]; nextCursor: string | null } = await this.page<T>(path, { per_page: 200, ...query, cursor }, opts);
      out.push(...items);
      if (!nextCursor) break;
      cursor = nextCursor;
    }
    return out;
  }
}

async function readProblem(response: Response): Promise<Problem | null> {
  const text = await response.text().catch(() => '');
  if (!text) return null;
  try {
    const json = JSON.parse(text) as Partial<Problem>;
    if (json && typeof json === 'object') {
      return {
        type: json.type ?? 'about:blank',
        title: json.title ?? '',
        status: json.status ?? response.status,
        detail: json.detail ?? '',
        ...json,
      } as Problem;
    }
  } catch {
    /* corps non JSON (page d'erreur du serveur) */
  }
  return null;
}

/** Instance de l'application ; configurée au démarrage (main.ts) et dans les tests. */
export const http = new HttpClient({
  baseUrl: '/api/v1',
  getEstablishmentId: () => null,
  getLocale: () => 'fr',
});

import { describe, expect, it, vi } from 'vitest';
import { ApiError, HttpClient } from '@/api/http';
import { describeError } from '@/api/errors';
import { ULID_PATTERN } from '@/lib/ulid';
import { jsonResponse } from './helpers';

function client(fetchImpl: typeof fetch, over: Partial<ConstructorParameters<typeof HttpClient>[0]> = {}): HttpClient {
  return new HttpClient({ baseUrl: '/api/v1', fetchImpl, getEstablishmentId: () => 'E1', getLocale: () => 'fr', ...over });
}
const lastInit = (f: ReturnType<typeof vi.fn>): RequestInit => f.mock.calls.at(-1)?.[1] as RequestInit;
const hdr = (f: ReturnType<typeof vi.fn>, name: string): string | undefined => (lastInit(f).headers as Record<string, string>)[name];

describe('client HTTP', () => {
  it("envoie l'établissement actif, la langue et l'en-tête anti-CSRF sur une route métier", async () => {
    const f = vi.fn(async (_url: string, _init?: RequestInit) => jsonResponse({ data: [] }));
    await client(f as unknown as typeof fetch).get('/people', { 'filter[q]': 'foka' });
    expect(f.mock.calls[0]?.[0]).toBe('/api/v1/people?filter%5Bq%5D=foka');
    expect(hdr(f, 'X-Establishment-Id')).toBe('E1');
    expect(hdr(f, 'Accept-Language')).toBe('fr');
    expect(hdr(f, 'X-Requested-With')).toBe('XMLHttpRequest');
    expect(lastInit(f).credentials).toBe('same-origin');
  });

  it("n'envoie pas X-Establishment-Id sur /me, /auth et /portal", async () => {
    const f = vi.fn(async (_url: string, _init?: RequestInit) => jsonResponse({ data: {} }));
    const c = client(f as unknown as typeof fetch);
    for (const p of ['/me', '/me/contexts', '/auth/login', '/portal/family']) {
      await c.request(p.startsWith('/auth') ? 'POST' : 'GET', p, { body: {} });
      expect(hdr(f, 'X-Establishment-Id')).toBeUndefined();
    }
  });

  it("n'envoie pas X-Establishment-Id pour la vue de groupe (établissement nul)", async () => {
    const f = vi.fn(async (_url: string, _init?: RequestInit) => jsonResponse({ data: {} }));
    await client(f as unknown as typeof fetch, { getEstablishmentId: () => null }).get('/dashboards/groupe');
    expect(hdr(f, 'X-Establishment-Id')).toBeUndefined();
  });

  it('autorise le remplacement explicite de l\'établissement (paiement dans un autre établissement)', async () => {
    const f = vi.fn(async (_url: string, _init?: RequestInit) => jsonResponse({ data: {} }));
    await client(f as unknown as typeof fetch).get('/payments/P1', undefined, { establishmentId: 'E9' });
    expect(hdr(f, 'X-Establishment-Id')).toBe('E9');
  });

  it('ajoute une Idempotency-Key ULID sur POST /payments, /cash/receipts et /documents, et sur eux seuls', async () => {
    const f = vi.fn(async (_url: string, _init?: RequestInit) => jsonResponse({ data: {} }));
    const c = client(f as unknown as typeof fetch);
    for (const p of ['/payments', '/cash/receipts', '/documents']) {
      await c.post(p, { amount_xaf: 1000 });
      expect(hdr(f, 'Idempotency-Key')).toMatch(ULID_PATTERN);
    }
    await c.post('/people', {});
    expect(hdr(f, 'Idempotency-Key')).toBeUndefined();
    await c.get('/payments');
    expect(hdr(f, 'Idempotency-Key')).toBeUndefined();
  });

  it("conserve la clé d'idempotence fournie par l'appelant (rejeu d'un même envoi)", async () => {
    const f = vi.fn(async (_url: string, _init?: RequestInit) => jsonResponse({ data: {} }));
    const c = client(f as unknown as typeof fetch);
    await c.post('/payments', { amount_xaf: 1 }, { idempotencyKey: 'ABC-FIXED-KEY' });
    await c.post('/payments', { amount_xaf: 1 }, { idempotencyKey: 'ABC-FIXED-KEY' });
    expect(f.mock.calls.map((_, i) => ((f.mock.calls[i]?.[1] as RequestInit).headers as Record<string, string>)['Idempotency-Key'])).toEqual(['ABC-FIXED-KEY', 'ABC-FIXED-KEY']);
  });

  it('lit une erreur RFC 9457 avec son trace_id et ses erreurs de champ (422)', async () => {
    const f = vi.fn(async (_url: string, _init?: RequestInit) => jsonResponse({ type: 'https://api.scholaris.cm/errors/validation', title: 'Données invalides', status: 422, detail: 'Corrigez les champs.', trace_id: 'TR-42', errors: { amount_xaf: ['Montant requis.'] } }, 422));
    const err = await client(f as unknown as typeof fetch).post('/people', {}).catch((e: unknown) => e);
    expect(err).toBeInstanceOf(ApiError);
    const e = err as ApiError;
    expect(e.status).toBe(422);
    expect(e.traceId).toBe('TR-42');
    expect(e.fieldErrors.amount_xaf).toEqual(['Montant requis.']);
    expect(describeError(e).traceId).toBe('TR-42');
  });

  it('expose Retry-After sur un 429 et le dit à la personne', async () => {
    const f = vi.fn(async (_url: string, _init?: RequestInit) => jsonResponse({ type: 't', title: 'Trop', status: 429, detail: '', trace_id: 'x' }, 429, { 'Retry-After': '42' }));
    const e = (await client(f as unknown as typeof fetch).get('/people').catch((x: unknown) => x)) as ApiError;
    expect(e.retryAfter).toBe(42);
    expect(describeError(e).message).toContain('42');
  });

  it('transmet attempts_left et locked_until (423)', async () => {
    const f = vi.fn(async (_url: string, _init?: RequestInit) => jsonResponse({ type: 'https://api.scholaris.cm/errors/locked', title: 'Compte verrouillé', status: 423, detail: 'Verrouillé.', attempts_left: 0, locked_until: '2026-10-09T10:15:00+01:00', trace_id: 'x' }, 423, { 'Retry-After': '900' }));
    const e = (await client(f as unknown as typeof fetch).post('/auth/login', {}).catch((x: unknown) => x)) as ApiError;
    expect(e.problem?.locked_until).toBe('2026-10-09T10:15:00+01:00');
    expect(e.retryAfter).toBe(900);
  });

  it('reconnaît la double authentification requise (403)', async () => {
    const f = vi.fn(async (_url: string, _init?: RequestInit) => jsonResponse({ type: 'https://api.scholaris.cm/errors/two-factor-required', title: 'Double authentification requise', status: 403, detail: 'Validez votre double authentification.', trace_id: 'x' }, 403));
    const e = (await client(f as unknown as typeof fetch).get('/me/contexts').catch((x: unknown) => x)) as ApiError;
    expect(e.isTwoFactorRequired).toBe(true);
  });

  it('conserve les deux versions d\'un conflit 409', async () => {
    const f = vi.fn(async (_url: string, _init?: RequestInit) => jsonResponse({ type: 'https://api.scholaris.cm/errors/conflict', title: 'Conflit', status: 409, detail: 'Conflit.', server_version: { status: 'validated' }, client_version: { status: 'open' } }, 409));
    const e = (await client(f as unknown as typeof fetch).post('/attendance-sessions', {}).catch((x: unknown) => x)) as ApiError;
    expect(e.isConflict).toBe(true);
    expect(e.problem?.server_version).toEqual({ status: 'validated' });
    expect(e.problem?.client_version).toEqual({ status: 'open' });
  });

  it('signale une session expirée (401) sauf sur la connexion', async () => {
    const onUnauthorized = vi.fn();
    const f = vi.fn(async (_url: string, _init?: RequestInit) => jsonResponse({ type: 't', title: 'Non authentifié', status: 401, detail: 'Connectez-vous.', trace_id: 'x' }, 401));
    const c = client(f as unknown as typeof fetch, { onUnauthorized });
    await c.get('/me').catch(() => undefined);
    expect(onUnauthorized).toHaveBeenCalledTimes(1);
    await c.post('/auth/login', {}).catch(() => undefined);
    expect(onUnauthorized).toHaveBeenCalledTimes(1);
  });

  it('transforme une coupure réseau en erreur réseau identifiable', async () => {
    const f = vi.fn(async (_url: string, _init?: RequestInit) => {
      throw new TypeError('Failed to fetch');
    });
    const e = (await client(f as unknown as typeof fetch).get('/people').catch((x: unknown) => x)) as ApiError;
    expect(e.network).toBe(true);
    expect(e.status).toBe(0);
  });

  it('tolère un corps non JSON sur une erreur serveur', async () => {
    const f = vi.fn(async (_url: string, _init?: RequestInit) => new Response('<html>Bad gateway</html>', { status: 502 }));
    const e = (await client(f as unknown as typeof fetch).get('/people').catch((x: unknown) => x)) as ApiError;
    expect(e.status).toBe(502);
    expect(e.problem).toBeNull();
    expect(describeError(e).message.length).toBeGreaterThan(0);
  });

  it('parcourt la pagination par curseur', async () => {
    const pages: Record<string, unknown> = {
      '': { data: [1, 2], meta: { next_cursor: 'c2' } },
      c2: { data: [3], meta: { next_cursor: null } },
    };
    const f = vi.fn(async (url: string, _init?: RequestInit) => jsonResponse(pages[new URL(url, 'http://x').searchParams.get('cursor') ?? ''] ));
    const all = await client(f as unknown as typeof fetch).all<number>('/people');
    expect(all).toEqual([1, 2, 3]);
    expect(f).toHaveBeenCalledTimes(2);
  });
});

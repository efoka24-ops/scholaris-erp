import { expect as baseExpect, type Browser, type BrowserContext, type Page, type TestInfo } from '@playwright/test';
import { spawn, execFile, execFileSync, type ChildProcess } from 'node:child_process';
import { promisify } from 'node:util';
import { randomBytes, createHmac } from 'node:crypto';
import { existsSync, mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

/**
 * Pile RÉELLE pour les parcours de bout en bout 1x-*.spec.ts (C-110) : serveur PHP intégré (`php -S`) sur le Kernel du
 * dépôt, base SQLite fichier migrée, propre à chaque fichier de test, données fictives semées par l'API (comme le ferait
 * l'établissement) et passerelles SMS/e-mail remplacées par un enregistreur lu par le test (fixtures/stack/boot.php).
 *
 * Le front construit (e2e/.dist, servi par `vite preview`) appelle /api/v1 : chaque contexte de navigateur relaie ces
 * appels vers la pile du fichier (`attach`). Aucun faux backend : statuts, champs et refus viennent du serveur réel.
 */
/** Attentes allongées : la pile PHP réelle (un processus par requête, sans cache) répond en 0,5 à 4 s par appel. */
export const expect = baseExpect.configure({ timeout: 45_000 });

const here = dirname(fileURLToPath(import.meta.url));
const ROOT = join(here, '..', '..', '..');
const TOOL = join(here, '..', 'fixtures', 'stack', 'tool.php');
const SERVER = join(here, '..', 'fixtures', 'stack', 'server.php');
const SEED = join(here, '..', 'fixtures', 'stack', 'seed.php');
const OPCACHE = ['-d', 'zend_extension=opcache', '-d', 'opcache.enable=1', '-d', 'opcache.enable_cli=1', '-d', 'opcache.memory_consumption=256', '-d', 'opcache.max_accelerated_files=20000'];
const PHP = process.env.E2E_PHP ?? 'php';

export interface ApiOptions {
  token?: string | null;
  establishment?: string | null;
  /** Statut attendu ; à défaut, tout 2xx. */
  expect?: number;
  headers?: Record<string, string>;
}

export interface StackCall {
  method: string;
  path: string;
  override: string | null;
  status: number;
}

export interface Account {
  login: string;
  password: string;
  token: string;
  person_id?: string;
}

export class Stack {
  readonly calls: StackCall[] = [];
  private phoneSeq = 0;

  readonly url: string;
  readonly dir: string;
  private readonly env: NodeJS.ProcessEnv;
  private readonly procs: ChildProcess[];
  private readonly workers: string[];
  private next = 0;

  constructor(workers: string[], dir: string, env: NodeJS.ProcessEnv, procs: ChildProcess[]) {
    this.url = workers[0]!;
    this.workers = workers;
    this.dir = dir;
    this.env = env;
    this.procs = procs;
  }

  /**
   * Semis d'un scénario (fixtures/stack/seed.php) par l'API réelle, en mémoire : identifiants fictifs et ressources en JSON.
   */
  async seed<T = any>(scenario: string, ...args: string[]): Promise<T> {
    const { stdout: out } = await promisify(execFile)(PHP, [...OPCACHE, SEED, scenario, ...args], { cwd: ROOT, env: this.env, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024, timeout: 600_000 });
    return JSON.parse(out.trim().split('\n').pop()!) as T;
  }

  /** Outil d'installation (fixtures/stack/tool.php) : réponse JSON. */
  tool<T = any>(...args: string[]): T {
    const out = execFileSync(PHP, [TOOL, ...args], { cwd: ROOT, env: this.env, encoding: 'utf8' });
    return JSON.parse(out.trim().split('\n').pop()!) as T;
  }

  /** Numéro fictif unique dans la plage +237 69 9xx xx xx réservée aux tests. */
  phone(): string {
    return `+2376995${String(process.pid % 100).padStart(2, '0')}${String(++this.phoneSeq).padStart(3, '0')}`;
  }

  /** Messages (SMS, e-mails) émis par le serveur, du plus ancien au plus récent. */
  outbox(): Array<{ channel: string; to: string; message: string; subject?: string }> {
    const file = join(this.dir, 'outbox.jsonl');
    if (!existsSync(file)) return [];
    return readFileSync(file, 'utf8').split('\n').filter(Boolean).map((l) => JSON.parse(l));
  }

  /** Dernier code à 6 chiffres reçu par ce numéro (ou cette adresse). */
  lastCode(to: string): string | null {
    const msgs = this.outbox().filter((m) => m.to.replace(/\s/g, '') === to.replace(/\s/g, ''));
    for (const m of msgs.reverse()) {
      const found = /\b(\d{6})\b/.exec(m.message);
      if (found) return found[1]!;
    }
    return null;
  }

  async api<T = any>(method: string, path: string, body?: unknown, opts: ApiOptions = {}): Promise<T> {
    const headers: Record<string, string> = { Accept: 'application/json', 'Accept-Language': 'fr', 'User-Agent': 'Mozilla/5.0 (Recette E2E) Chrome/120.0', ...(opts.headers ?? {}) };
    if (opts.token) headers.Authorization = `Bearer ${opts.token}`;
    if (opts.establishment) headers['X-Establishment-Id'] = opts.establishment;
    let raw: string | undefined;
    if (body !== undefined) {
      headers['Content-Type'] = 'application/json';
      raw = JSON.stringify(body);
    }
    const res = await fetch(this.url + '/api/v1' + path, { method, headers, body: raw });
    const text = await res.text();
    const ok = opts.expect !== undefined ? res.status === opts.expect : res.status >= 200 && res.status < 300;
    if (!ok) throw new Error(`${method} ${path} -> ${res.status} ${text.slice(0, 1500)}`);
    return (text && (res.headers.get('content-type') ?? '').includes('json') ? JSON.parse(text) : {}) as T;
  }

  /** Téléversement multipart (fichier joint) par l'API. */
  async upload<T = any>(path: string, fields: Record<string, string>, file: { field: string; name: string; type: string; content: string | Buffer }, opts: ApiOptions = {}): Promise<T> {
    const form = new FormData();
    for (const [k, v] of Object.entries(fields)) form.append(k, v);
    form.append(file.field, new Blob([typeof file.content === 'string' ? file.content : new Uint8Array(file.content)], { type: file.type }), file.name);
    const headers: Record<string, string> = { Accept: 'application/json', 'Accept-Language': 'fr' };
    if (opts.token) headers.Authorization = `Bearer ${opts.token}`;
    if (opts.establishment) headers['X-Establishment-Id'] = opts.establishment;
    const res = await fetch(this.url + '/api/v1' + path, { method: 'POST', headers, body: form });
    const text = await res.text();
    if (!(opts.expect !== undefined ? res.status === opts.expect : res.ok)) throw new Error(`POST ${path} -> ${res.status} ${text.slice(0, 1500)}`);
    return (text ? JSON.parse(text) : {}) as T;
  }

  /** Connexion par l'API ; enrôlement ou vérification TOTP si le serveur l'exige. */
  async login(login: string, password: string, totpSecret?: string): Promise<{ token: string; secret?: string }> {
    const res = await this.api('POST', '/auth/login', { identifier: login, password });
    const token = String(res.token);
    if (res.two_factor_required !== true) return { token };
    if (res.two_factor_setup_required === true) {
      const setup = await this.api('POST', '/auth/2fa/setup', {}, { token });
      await this.api('POST', '/auth/2fa/confirm', { code: await freshTotp(String(setup.secret)) }, { token });
      return { token, secret: String(setup.secret) };
    }
    if (!totpSecret) throw new Error('Secret TOTP requis pour ' + login);
    await this.api('POST', '/auth/2fa/verify', { code: await freshTotp(totpSecret) }, { token });
    return { token, secret: totpSecret };
  }

  /** Parcours d'activation réel : demande de code, code lu dans le SMS, ticket, mot de passe. */
  async activate(identifier: string, password: string): Promise<void> {
    await this.api('POST', '/auth/activation/request', { identifier });
    const code = this.lastCode(identifier);
    if (!code) throw new Error('Aucun code reçu par ' + identifier);
    const verified = await this.api('POST', '/auth/activation/verify', { identifier, code });
    await this.api('POST', '/auth/activation/password', { ticket: String(verified.ticket), password });
  }

  /**
   * Client mis en service par l'outil de la plateforme (application ouverte en essai), administrateur activé et connecté.
   */
  async client(app: string, type: string, name: string): Promise<{ tenant: string; establishment: string; admin: Account }> {
    const phone = this.phone();
    const created = this.tool('client', app, type, phone, 'Awa', 'Directrice', name);
    const password = 'Recette-E2E-' + randomBytes(4).toString('hex') + '!';
    await this.activate(phone, password);
    const { token } = await this.login(phone, password);
    let establishment = created.establishment_id as string | null;
    if (!establishment) {
      const list = await this.api('GET', '/establishments', undefined, { token });
      establishment = String(list.data[0].id);
    }
    return { tenant: String(created.tenant_id), establishment, admin: { login: phone, password, token, person_id: String(created.admin.person_id) } };
  }

  /** Compte d'un rôle de l'établissement : personne + utilisateur créés par l'administrateur, rôle attribué, activation. */
  async user(admin: Account, establishment: string, firstName: string, lastName: string, role: string, personId?: string): Promise<Account> {
    const phone = this.phone();
    const created = await this.api('POST', '/users', { first_name: firstName, last_name: lastName, phone, ...(personId ? { person_id: personId } : {}) }, { token: admin.token, establishment, expect: 201 });
    const person = String(created.data.person_id);
    await this.api('POST', '/role-assignments', { person_id: person, role, scope_type: 'establishment', scope_id: establishment }, { token: admin.token, establishment, expect: 201 });
    const password = 'Recette-E2E-' + randomBytes(4).toString('hex') + '!';
    await this.activate(phone, password);
    const { token } = await this.login(phone, password);
    return { login: phone, password, token, person_id: person };
  }

  /**
   * Relaie les appels /api/v1 du navigateur vers la pile et consigne méthode, surcharge et statut. Les écritures du front
   * partent en POST avec X-HTTP-Method-Override (contrainte de l'hébergement) : `expectOnlyGetPost` le vérifie.
   */
  async attach(target: BrowserContext | Page): Promise<void> {
    await target.route('**/api/v1/**', async (route) => {
      const req = route.request();
      const u = new URL(req.url());
      try {
        const response = await route.fetch({ url: this.workers[this.next++ % this.workers.length] + u.pathname + u.search, maxRedirects: 0 });
        this.calls.push({ method: req.method(), path: u.pathname.replace(/^\/api\/v1/, ''), override: (await req.headerValue('x-http-method-override')) ?? null, status: response.status() });
        await route.fulfill({ response });
      } catch {
        await route.abort('connectionfailed').catch(() => undefined);
      }
    });
  }

  expectOnlyGetPost(): void {
    const direct = this.calls.filter((c) => !['GET', 'POST', 'HEAD', 'OPTIONS'].includes(c.method)).map((c) => `${c.method} ${c.path}`);
    expect(direct, 'Aucune écriture ne part en PATCH/PUT/DELETE direct (le front surcharge par X-HTTP-Method-Override).').toEqual([]);
  }

  /** Appels du navigateur refusés par le serveur avec un statut 5xx (défaut serveur). */
  serverErrors(): string[] {
    return this.calls.filter((c) => c.status >= 500).map((c) => `${c.method} ${c.path} -> ${c.status}`);
  }

  async stop(): Promise<void> {
    for (const p of this.procs) p.kill();
    await new Promise((r) => setTimeout(r, 200));
    try {
      rmSync(this.dir, { recursive: true, force: true, maxRetries: 5 });
    } catch {
      // Base encore verrouillée par Windows : dossier temporaire laissé au nettoyage du système.
    }
  }
}

async function freePort(): Promise<number> {
  return new Promise((resolve, reject) => {
    const srv = createServer();
    srv.once('error', reject);
    srv.listen(0, '127.0.0.1', () => {
      const port = (srv.address() as { port: number }).port;
      srv.close(() => resolve(port));
    });
  });
}

/**
 * Démarre une pile neuve (base migrée vide) : plusieurs serveurs `php -S` (mono-requête chacun) sur la même base, entre
 * lesquels le navigateur répartit ses appels, comme les processus PHP de l'hébergement. À appeler dans `test.beforeAll`.
 */
export async function startStack(opts: { twoFactor?: boolean; workers?: number } = {}): Promise<Stack> {
  const dir = mkdtempSync(join(tmpdir(), 'scholaris-e2e-'));
  const env: NodeJS.ProcessEnv = { ...process.env, E2E_DIR: dir, E2E_APP_KEY: randomBytes(32).toString('base64'), E2E_2FA: opts.twoFactor ? '1' : '0' };
  execFileSync(PHP, [TOOL, 'migrate'], { cwd: ROOT, env, encoding: 'utf8' });
  const procs: ChildProcess[] = [];
  const workers: string[] = [];
  for (let i = 0; i < (opts.workers ?? 4); i++) {
    const port = await freePort();
    procs.push(spawn(PHP, [...OPCACHE, '-S', `127.0.0.1:${port}`, SERVER], { cwd: ROOT, env, stdio: 'ignore' }));
    workers.push(`http://127.0.0.1:${port}`);
  }
  const deadline = Date.now() + 20_000;
  for (const url of workers) {
    for (;;) {
      try {
        const res = await fetch(url + '/api/v1/health');
        if (res.status < 500) break;
      } catch {
        // Serveur pas encore à l'écoute.
      }
      if (Date.now() > deadline) throw new Error('Le serveur PHP de test ne répond pas sur ' + url);
      await new Promise((r) => setTimeout(r, 150));
    }
  }
  return new Stack(workers, dir, env, procs);
}

/** Code TOTP (RFC 6238, SHA-1, 6 chiffres, 30 s) d'un secret base32 : ce que l'application d'authentification afficherait. */
export function totp(secret: string, at = Date.now()): string {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = '';
  for (const ch of secret.replace(/=+$/, '').toUpperCase()) bits += alphabet.indexOf(ch).toString(2).padStart(5, '0');
  const bytes = Buffer.from(bits.match(/.{8}/g)!.map((b) => parseInt(b, 2)));
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(Math.floor(at / 1000 / 30)));
  const h = createHmac('sha1', bytes).update(counter).digest();
  const o = h[h.length - 1]! & 0xf;
  const code = ((h.readUInt32BE(o) & 0x7fffffff) % 1_000_000).toString();
  return code.padStart(6, '0');
}

/** Connexion par l'écran de connexion réel. */
export async function uiLogin(page: Page, identifier: string, password: string): Promise<void> {
  await page.goto('/connexion');
  await page.getByLabel(/^(E-mail ou téléphone|Numéro de téléphone ou e-mail)$/).fill(identifier);
  await page.getByLabel('Mot de passe', { exact: true }).fill(password);
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page).not.toHaveURL(/\/connexion/);
}

/** Date du jour à Douala (AAAA-MM-JJ). */
export function isoDate(d = new Date()): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone: 'Africa/Douala', year: 'numeric', month: '2-digit', day: '2-digit' }).format(d);
}

/** Contexte de navigateur propre à un acteur (sa session, ses cookies), relié à la pile. */
export async function actorPage(browser: Browser, stack: Stack, info: TestInfo): Promise<Page> {
  const viewport = info.project.use.viewport ?? { width: 1280, height: 800 };
  const context = await browser.newContext({ baseURL: String(info.project.use.baseURL), viewport, locale: 'fr-FR', timezoneId: 'Africa/Douala', serviceWorkers: 'block' });
  await stack.attach(context);
  return context.newPage();
}

const usedTotp = new Map<string, string>();

/**
 * Code TOTP jamais présenté pour ce secret (le serveur refuse le rejeu d'un code) : si le code courant a déjà servi, attend
 * la fenêtre de 30 s suivante, comme le ferait la personne devant son application d'authentification.
 */
export async function freshTotp(secret: string): Promise<string> {
  for (;;) {
    const code = totp(secret);
    if (usedTotp.get(secret) !== code) {
      usedTotp.set(secret, code);
      return code;
    }
    await new Promise((r) => setTimeout(r, 30_000 - (Date.now() % 30_000) + 200));
  }
}

/** Saisie d'un code à 6 chiffres dans le composant à cases (CodeInput) : collage dans la première case. */
export async function typeCode(page: Page, code: string): Promise<void> {
  const first = page.getByRole('textbox', { name: /chiffre 1|1er chiffre|Chiffre 1/i }).first();
  await first.click();
  await page.keyboard.type(code);
}

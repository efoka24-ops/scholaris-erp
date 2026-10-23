// Coffre de l'appareil (T645) : tout ce que l'application garde hors ligne (classes, listes d'appel, appels en attente) est
// chiffré (AES-GCM 256) avec une clé tirée du code personnel à 6 chiffres (PBKDF2-SHA-256) ; la clé ne vit qu'en mémoire.
// 5 codes erronés de suite effacent le coffre (données et appels non envoyés compris) : l'appareil partagé ne garde rien.
// Le stockage est la base IndexedDB de l'application (zone « cache » de src/offline/db.ts, qui bascule en mémoire si IndexedDB manque).
import { ref } from 'vue';
import { cacheDelete, cacheGet, cachePut, idbAll, idbDelete } from '@/offline/db';

export const MAX_FAILURES = 5;
export const PIN_PATTERN = /^[0-9]{6}$/;
const PREFIX = 'ps:';
const META_KEY = `${PREFIX}meta`;
const DATA_PREFIX = `${PREFIX}d:`;
const CHECK_TEXT = 'scholaris-public-schools';
const DEFAULT_ITERATIONS = 150_000;

interface Sealed {
  iv: string;
  data: string;
}
interface Meta {
  salt: string;
  iterations: number;
  check: Sealed;
  failures: number;
  /** Appareil inscrit côté serveur (Sync) ; absent tant que la direction ne l'a pas inscrit. */
  device_id: string | null;
}

let key: CryptoKey | null = null;
/** Réactif : les écrans se verrouillent dès que le coffre se ferme ou est effacé. */
export const unlocked = ref(false);
export const initialised = ref<boolean | null>(null);
export const failures = ref(0);

const subtle = (): SubtleCrypto => {
  const s = globalThis.crypto?.subtle;
  if (!s) throw new Error('Chiffrement indisponible sur cet appareil (HTTPS requis).');
  return s;
};

function toB64(bytes: Uint8Array): string {
  let s = '';
  bytes.forEach((b) => (s += String.fromCharCode(b)));
  return btoa(s);
}
function fromB64(text: string): Uint8Array<ArrayBuffer> {
  const s = atob(text);
  const out = new Uint8Array(new ArrayBuffer(s.length));
  for (let i = 0; i < s.length; i++) out[i] = s.charCodeAt(i);
  return out;
}
const random = (n: number): Uint8Array<ArrayBuffer> => globalThis.crypto.getRandomValues(new Uint8Array(new ArrayBuffer(n)));

async function derive(pin: string, salt: Uint8Array<ArrayBuffer>, iterations: number): Promise<CryptoKey> {
  const material = await subtle().importKey('raw', new TextEncoder().encode(pin), 'PBKDF2', false, ['deriveKey']);
  return subtle().deriveKey({ name: 'PBKDF2', salt, iterations, hash: 'SHA-256' }, material, { name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']);
}

async function seal(k: CryptoKey, value: unknown): Promise<Sealed> {
  const iv = random(12);
  const data = await subtle().encrypt({ name: 'AES-GCM', iv }, k, new TextEncoder().encode(JSON.stringify(value)));
  return { iv: toB64(iv), data: toB64(new Uint8Array(data)) };
}
async function open<T>(k: CryptoKey, sealed: Sealed): Promise<T> {
  const plain = await subtle().decrypt({ name: 'AES-GCM', iv: fromB64(sealed.iv) }, k, fromB64(sealed.data));
  return JSON.parse(new TextDecoder().decode(plain)) as T;
}

async function readMeta(): Promise<Meta | undefined> {
  return cacheGet<Meta>(META_KEY);
}

/** État du coffre au démarrage d'un écran. */
export async function refreshVault(): Promise<{ initialised: boolean; unlocked: boolean; failures: number; deviceId: string | null }> {
  const meta = await readMeta();
  initialised.value = meta !== undefined;
  failures.value = meta?.failures ?? 0;
  unlocked.value = key !== null && meta !== undefined;
  return { initialised: meta !== undefined, unlocked: unlocked.value, failures: failures.value, deviceId: meta?.device_id ?? null };
}

/** Crée le coffre avec un code à 6 chiffres. `iterations` n'est abaissé que par les tests. */
export async function setupPin(pin: string, iterations = DEFAULT_ITERATIONS): Promise<void> {
  if (!PIN_PATTERN.test(pin)) throw new Error('Le code personnel compte 6 chiffres.');
  const salt = random(16);
  const k = await derive(pin, salt, iterations);
  const previous = await readMeta();
  await cachePut<Meta>(META_KEY, { salt: toB64(salt), iterations, check: await seal(k, CHECK_TEXT), failures: 0, device_id: previous?.device_id ?? null });
  key = k;
  unlocked.value = true;
  initialised.value = true;
  failures.value = 0;
}

export type UnlockOutcome = { ok: true } | { ok: false; wiped: boolean; remaining: number };

/** Vérifie le code sur l'appareil (fonctionne sans réseau). Le cinquième échec efface le coffre. */
export async function unlockWithPin(pin: string): Promise<UnlockOutcome> {
  const meta = await readMeta();
  if (!meta) return { ok: false, wiped: false, remaining: MAX_FAILURES };
  let good = false;
  let candidate: CryptoKey | null = null;
  if (PIN_PATTERN.test(pin)) {
    candidate = await derive(pin, fromB64(meta.salt), meta.iterations);
    try {
      good = (await open<string>(candidate, meta.check)) === CHECK_TEXT;
    } catch {
      good = false;
    }
  }
  if (good && candidate) {
    await cachePut<Meta>(META_KEY, { ...meta, failures: 0 });
    key = candidate;
    unlocked.value = true;
    failures.value = 0;
    return { ok: true };
  }
  const count = meta.failures + 1;
  if (count >= MAX_FAILURES) {
    await wipeVault();
    return { ok: false, wiped: true, remaining: 0 };
  }
  await cachePut<Meta>(META_KEY, { ...meta, failures: count });
  failures.value = count;
  return { ok: false, wiped: false, remaining: MAX_FAILURES - count };
}

/** Referme le coffre (la clé quitte la mémoire) sans rien effacer. */
export function lockVault(): void {
  key = null;
  unlocked.value = false;
}

/** Efface tout ce que l'application garde sur l'appareil : données, appels en attente, code. */
export async function wipeVault(): Promise<void> {
  key = null;
  const rows = await idbAll<{ key: string }>('cache');
  for (const row of rows) if (row.key.startsWith(PREFIX)) await idbDelete('cache', row.key);
  unlocked.value = false;
  initialised.value = false;
  failures.value = 0;
}

export async function setDeviceId(deviceId: string | null): Promise<void> {
  const meta = await readMeta();
  if (meta) await cachePut<Meta>(META_KEY, { ...meta, device_id: deviceId });
}

function requireKey(): CryptoKey {
  if (!key) throw new Error('Coffre verrouillé : saisissez votre code personnel.');
  return key;
}

export async function sealPut(name: string, value: unknown): Promise<void> {
  await cachePut(`${DATA_PREFIX}${name}`, await seal(requireKey(), value));
}
export async function sealGet<T>(name: string): Promise<T | undefined> {
  const sealed = await cacheGet<Sealed>(`${DATA_PREFIX}${name}`);
  if (!sealed) return undefined;
  try {
    return await open<T>(requireKey(), sealed);
  } catch {
    return undefined;
  }
}
export async function sealDelete(name: string): Promise<void> {
  await cacheDelete(`${DATA_PREFIX}${name}`);
}
/** Noms des éléments du coffre qui commencent par `prefix`. */
export async function sealNames(prefix: string): Promise<string[]> {
  const rows = await idbAll<{ key: string }>('cache');
  return rows.map((r) => r.key).filter((k) => k.startsWith(`${DATA_PREFIX}${prefix}`)).map((k) => k.slice(DATA_PREFIX.length));
}

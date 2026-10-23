// Stockage local IndexedDB : file d'attente d'envoi (appel, notes) et cache de lecture (classes, emploi du temps, brouillons).
// Si IndexedDB est indisponible (navigation privée, politique du navigateur), un stockage en mémoire prend le relais :
// l'application reste utilisable, mais rien ne survit à la fermeture de l'onglet (l'interface le signale).
export type StoreName = 'queue' | 'cache';

const DB_NAME = 'scholaris';
const DB_VERSION = 1;
const KEY_PATH: Record<StoreName, string> = { queue: 'client_uuid', cache: 'key' };

let dbPromise: Promise<IDBDatabase> | null = null;
const memory: Record<StoreName, Map<string, unknown>> = { queue: new Map(), cache: new Map() };
export let persistent = true;

function openDb(): Promise<IDBDatabase> {
  if (dbPromise) return dbPromise;
  dbPromise = new Promise<IDBDatabase>((resolve, reject) => {
    if (typeof indexedDB === 'undefined') {
      reject(new Error('IndexedDB indisponible'));
      return;
    }
    const req = indexedDB.open(DB_NAME, DB_VERSION);
    req.onupgradeneeded = () => {
      const db = req.result;
      if (!db.objectStoreNames.contains('queue')) db.createObjectStore('queue', { keyPath: KEY_PATH.queue });
      if (!db.objectStoreNames.contains('cache')) db.createObjectStore('cache', { keyPath: KEY_PATH.cache });
    };
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error ?? new Error('Ouverture IndexedDB impossible'));
    req.onblocked = () => reject(new Error('Ouverture IndexedDB bloquée'));
  });
  dbPromise.catch(() => {
    persistent = false;
  });
  return dbPromise;
}

/** Réinitialise la connexion (tests). */
export function resetDbForTests(): void {
  dbPromise = null;
  persistent = true;
  memory.queue.clear();
  memory.cache.clear();
}

function wrap<T>(req: IDBRequest<T>): Promise<T> {
  return new Promise((resolve, reject) => {
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}

async function withStore<T>(store: StoreName, mode: IDBTransactionMode, run: (s: IDBObjectStore) => IDBRequest<T>): Promise<T> {
  const db = await openDb();
  return wrap(run(db.transaction(store, mode).objectStore(store)));
}

export async function idbPut<T extends object>(store: StoreName, value: T): Promise<void> {
  // Copie JSON : les objets réactifs de Vue (Proxy) ne sont pas clonables par IndexedDB, et toutes nos données sont du JSON.
  const plain = JSON.parse(JSON.stringify(value)) as T;
  try {
    await withStore(store, 'readwrite', (s) => s.put(plain));
  } catch {
    persistent = false;
    memory[store].set(String((plain as Record<string, unknown>)[KEY_PATH[store]]), plain);
  }
}

export async function idbGet<T>(store: StoreName, key: string): Promise<T | undefined> {
  try {
    return (await withStore(store, 'readonly', (s) => s.get(key))) as T | undefined;
  } catch {
    persistent = false;
    return memory[store].get(key) as T | undefined;
  }
}

export async function idbDelete(store: StoreName, key: string): Promise<void> {
  try {
    await withStore(store, 'readwrite', (s) => s.delete(key));
  } catch {
    memory[store].delete(key);
  }
}

export async function idbAll<T>(store: StoreName): Promise<T[]> {
  try {
    return (await withStore(store, 'readonly', (s) => s.getAll())) as T[];
  } catch {
    persistent = false;
    return [...memory[store].values()] as T[];
  }
}

export async function cachePut<T>(key: string, value: T): Promise<void> {
  await idbPut('cache', { key, value, saved_at: new Date().toISOString() });
}

export async function cacheGet<T>(key: string): Promise<T | undefined> {
  const row = await idbGet<{ key: string; value: T }>('cache', key);
  return row?.value;
}

export async function cacheDelete(key: string): Promise<void> {
  await idbDelete('cache', key);
}

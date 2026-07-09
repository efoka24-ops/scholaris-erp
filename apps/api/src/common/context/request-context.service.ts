import { Injectable } from "@nestjs/common";
import { AsyncLocalStorage } from "node:async_hooks";

export interface RequestContextStore {
  tenantId?: string;
  userId?: string;
  ip?: string;
}

/**
 * Porte le tenant/utilisateur courant à travers toute la chaîne de la requête
 * (middleware → guard → service → PrismaService) sans avoir à le passer en paramètre
 * explicite partout. Alimenté par RequestContextMiddleware (ip) et JwtAccessStrategy
 * (tenantId/userId, une fois le token décodé).
 */
@Injectable()
export class RequestContextService {
  private readonly als = new AsyncLocalStorage<RequestContextStore>();

  run<T>(store: RequestContextStore, callback: () => T): T {
    return this.als.run(store, callback);
  }

  set<K extends keyof RequestContextStore>(key: K, value: RequestContextStore[K]): void {
    const store = this.als.getStore();
    if (store) {
      store[key] = value;
    }
  }

  get<K extends keyof RequestContextStore>(key: K): RequestContextStore[K] | undefined {
    return this.als.getStore()?.[key];
  }

  getStore(): RequestContextStore | undefined {
    return this.als.getStore();
  }
}

import axios from "axios";

/**
 * Client browser-side vers les Route Handlers Next.js (jamais directement vers
 * l'API NestJS) : ce sont ces routes qui portent les cookies httpOnly.
 */
export const apiClient = axios.create({
  baseURL: "/api",
  timeout: 10_000,
});

/**
 * Client browser-side pour toutes les ressources métier (cycles, classes,
 * notes, paiements...) : passe par le proxy générique `/api/proxy/**`
 * (voir `app/api/proxy/[...path]/route.ts`) qui porte le Bearer token côté
 * serveur. Un seul appel `resourceClient.get("/cycles")` suffit — pas besoin
 * d'écrire une Route Handler par ressource.
 */
export const resourceClient = axios.create({
  baseURL: "/api/proxy",
  timeout: 10_000,
});

function withAutoRefresh(client: typeof apiClient) {
  client.interceptors.response.use(
    (response) => response,
    async (error) => {
      const originalRequest = error.config;
      const isAuthRoute = originalRequest?.baseURL === "/api" && originalRequest?.url?.startsWith("/auth/");
      if (error.response?.status === 401 && !isAuthRoute && !originalRequest._retry) {
        originalRequest._retry = true;
        try {
          await apiClient.post("/auth/refresh");
          return client(originalRequest);
        } catch {
          // Le refresh a échoué : laisser l'appelant gérer la redirection vers /login.
        }
      }
      return Promise.reject(error);
    },
  );
}

withAutoRefresh(apiClient);
withAutoRefresh(resourceClient);

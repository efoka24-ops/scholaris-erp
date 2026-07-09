import axios from "axios";

/**
 * Client browser-side vers les Route Handlers Next.js (jamais directement vers
 * l'API NestJS) : ce sont ces routes qui portent les cookies httpOnly.
 */
export const apiClient = axios.create({
  baseURL: "/api",
  timeout: 10_000,
});

apiClient.interceptors.response.use(
  (response) => response,
  async (error) => {
    const originalRequest = error.config;
    const isAuthRoute = originalRequest?.url?.startsWith("/auth/");
    if (error.response?.status === 401 && !isAuthRoute && !originalRequest._retry) {
      originalRequest._retry = true;
      try {
        await apiClient.post("/auth/refresh");
        return apiClient(originalRequest);
      } catch {
        // Le refresh a échoué : laisser l'appelant gérer la redirection vers /login.
      }
    }
    return Promise.reject(error);
  },
);

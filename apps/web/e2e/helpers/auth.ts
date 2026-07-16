import type { APIRequestContext, Page } from "@playwright/test";
import { expect } from "@playwright/test";

/**
 * Helpers d'authentification pour les tests E2E Playwright.
 *
 * Deux modes sont nécessaires :
 * - `loginUI` : connexion via le formulaire `/login` (pose le cookie de session
 *   sur le contexte du navigateur). À utiliser pour vérifier la redirection
 *   /dashboard, la sidebar, et pour que les appels `page.request.*` vers
 *   `/api/proxy/**` (même origine, cookie transmis) soient authentifiés.
 * - `apiLogin` : connexion directe contre l'API NestJS (`NEST_API_URL`), qui
 *   renvoie un `accessToken` Bearer. À utiliser pour les assertions RBAC
 *   fines (200/403) indépendantes du rendu frontend, à la manière de
 *   `apps/web/e2e/security/security-tests.spec.ts`.
 */

export const API_BASE_URL = process.env.NEST_API_URL || "http://localhost:3001/api";

export const DEMO_PASSWORD = "Test123!";

export const DEMO_ACCOUNTS = {
  superAdmin: { email: "admin@scholaris.dev", password: "ChangeMe123!" },
  directeur: { email: "directeur@demo.scholaris.cm", password: DEMO_PASSWORD },
  censeur: { email: "censeur@demo.scholaris.cm", password: DEMO_PASSWORD },
  chefDepartement: { email: "chef-departement@demo.scholaris.cm", password: DEMO_PASSWORD },
  enseignant: { email: "enseignant@demo.scholaris.cm", password: DEMO_PASSWORD },
  intendant: { email: "intendant@demo.scholaris.cm", password: DEMO_PASSWORD },
  secretaire: { email: "secretaire@demo.scholaris.cm", password: DEMO_PASSWORD },
  adminEtablissement: { email: "admin-etablissement@demo.scholaris.cm", password: DEMO_PASSWORD },
  infirmier: { email: "infirmier@demo.scholaris.cm", password: DEMO_PASSWORD },
  bibliothecaire: { email: "bibliothecaire@demo.scholaris.cm", password: DEMO_PASSWORD },
  parent: { email: "parent@demo.scholaris.cm", password: DEMO_PASSWORD },
  eleve: { email: "eleve@demo.scholaris.cm", password: DEMO_PASSWORD },
} as const;

/** Connexion via le formulaire UI. Attend la redirection vers /dashboard. */
export async function loginUI(page: Page, email: string, password: string): Promise<void> {
  await page.goto("/login");
  await page.getByLabel("Email").fill(email);
  await page.getByLabel("Mot de passe").fill(password);
  await page.getByRole("button", { name: /se connecter/i }).click();
  await expect(page).toHaveURL(/\/dashboard/, { timeout: 15_000 });
}

/** Connexion directe API, retourne le token Bearer + l'utilisateur courant. */
export async function apiLogin(
  request: APIRequestContext,
  email: string,
  password: string,
): Promise<{ accessToken: string; user: any }> {
  const response = await request.post(`${API_BASE_URL}/auth/login`, {
    data: { email, password },
  });
  expect(response.ok(), `Échec de connexion API pour ${email}`).toBeTruthy();
  const body = await response.json();
  return { accessToken: body.accessToken, user: body.user };
}

export function authHeader(accessToken: string) {
  return { Authorization: `Bearer ${accessToken}` };
}

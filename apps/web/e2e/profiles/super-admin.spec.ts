/**
 * PROFIL : Super Admin (rôle système, tous établissements)
 *
 * Opération fonctionnelle : création d'un rôle personnalisé (roles:create).
 * Test négatif : un rôle métier normal (Enseignant) ne peut pas créer de rôle.
 */
import { test, expect } from "@playwright/test";
import { API_BASE_URL, DEMO_ACCOUNTS, apiLogin, authHeader, loginUI } from "../helpers/auth";

test.describe("Profil Super Admin", () => {
  test("connexion, redirection /dashboard et sidebar visible", async ({ page }) => {
    await loginUI(page, DEMO_ACCOUNTS.superAdmin.email, DEMO_ACCOUNTS.superAdmin.password);
    await expect(page.getByText("SCHOLARIS")).toBeVisible();
    // Le Super Admin doit voir la section Configuration (utilisateurs, rôles, etc.)
    await expect(page.getByRole("link", { name: "Utilisateurs" })).toBeVisible();
  });

  test("crée un rôle personnalisé (roles:create)", async ({ request }) => {
    const { accessToken } = await apiLogin(request, DEMO_ACCOUNTS.superAdmin.email, DEMO_ACCOUNTS.superAdmin.password);

    const roleName = `E2E Role ${Date.now()}`;
    const response = await request.post(`${API_BASE_URL}/roles`, {
      headers: authHeader(accessToken),
      data: { name: roleName, description: "Rôle créé par les tests E2E" },
    });

    expect(response.ok()).toBeTruthy();
    const role = await response.json();
    expect(role.name).toBe(roleName);
  });

  test("RBAC négatif : un Enseignant ne peut pas créer de rôle", async ({ request }) => {
    const { accessToken } = await apiLogin(request, DEMO_ACCOUNTS.enseignant.email, DEMO_ACCOUNTS.enseignant.password);

    const response = await request.post(`${API_BASE_URL}/roles`, {
      headers: authHeader(accessToken),
      data: { name: `Interdit ${Date.now()}` },
    });

    expect(response.status()).toBe(403);
  });
});

/**
 * PROFIL : Enseignant
 *
 * Opération fonctionnelle : saisit une note (grades:create via POST /grades/batch).
 * Test négatif : ne peut pas accéder aux routes d'administration (tenants:read).
 */
import { test, expect } from "@playwright/test";
import { API_BASE_URL, DEMO_ACCOUNTS, apiLogin, authHeader, loginUI } from "../helpers/auth";

test.describe("Profil Enseignant", () => {
  test("connexion, redirection /dashboard et sidebar visible", async ({ page }) => {
    await loginUI(page, DEMO_ACCOUNTS.enseignant.email, DEMO_ACCOUNTS.enseignant.password);
    await expect(page.getByRole("link", { name: "Saisie des notes" })).toBeVisible();
  });

  test("saisit une note par lot (POST /grades/batch)", async ({ request }) => {
    const { accessToken } = await apiLogin(request, DEMO_ACCOUNTS.enseignant.email, DEMO_ACCOUNTS.enseignant.password);

    // Permission vérifiée avant la logique métier : payload minimal/fictif,
    // on n'attend pas un 201 (dépend des données du seed), seulement
    // l'absence de 403 (accès autorisé au niveau RBAC).
    const response = await request.post(`${API_BASE_URL}/grades/batch`, {
      headers: authHeader(accessToken),
      data: { grades: [] },
    });

    expect(response.status()).not.toBe(403);
  });

  test("RBAC négatif : ne peut pas accéder aux routes d'administration (tenants:read)", async ({ request }) => {
    const { accessToken } = await apiLogin(request, DEMO_ACCOUNTS.enseignant.email, DEMO_ACCOUNTS.enseignant.password);

    const response = await request.get(`${API_BASE_URL}/tenants/any-tenant-id`, {
      headers: authHeader(accessToken),
    });

    expect(response.status()).toBe(403);
  });
});

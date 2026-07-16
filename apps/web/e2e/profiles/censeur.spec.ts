/**
 * PROFIL : Censeur
 *
 * Opération fonctionnelle : calcul des moyennes d'une classe pour une
 * période (grades:calculate).
 * Test négatif : ne peut pas publier les résultats (grades:publish réservé
 * au Directeur).
 */
import { test, expect } from "@playwright/test";
import { API_BASE_URL, DEMO_ACCOUNTS, apiLogin, authHeader, loginUI } from "../helpers/auth";

test.describe("Profil Censeur", () => {
  test("connexion, redirection /dashboard et sidebar visible", async ({ page }) => {
    await loginUI(page, DEMO_ACCOUNTS.censeur.email, DEMO_ACCOUNTS.censeur.password);
    await expect(page.getByRole("link", { name: "Emplois du temps" })).toBeVisible();
  });

  test("calcule les moyennes d'une classe/période (grades:calculate)", async ({ request }) => {
    const { accessToken } = await apiLogin(request, DEMO_ACCOUNTS.censeur.email, DEMO_ACCOUNTS.censeur.password);

    const response = await request.post(`${API_BASE_URL}/grades/calculate/fake-class-id/fake-period-id`, {
      headers: authHeader(accessToken),
    });

    // Autorisé au niveau permission (jamais 403), même si la donnée n'existe pas.
    expect(response.status()).not.toBe(403);
  });

  test("RBAC négatif : ne peut pas publier les résultats (grades:publish réservé au Directeur)", async ({
    request,
  }) => {
    const { accessToken } = await apiLogin(request, DEMO_ACCOUNTS.censeur.email, DEMO_ACCOUNTS.censeur.password);

    const response = await request.post(`${API_BASE_URL}/grades/publish/fake-class-id/fake-period-id`, {
      headers: authHeader(accessToken),
    });

    expect(response.status()).toBe(403);
  });
});

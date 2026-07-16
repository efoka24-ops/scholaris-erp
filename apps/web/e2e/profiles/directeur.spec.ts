/**
 * PROFIL : Directeur
 *
 * Opération fonctionnelle : publication des résultats d'une classe/période
 * (grades:publish) — action stratégique réservée au Directeur.
 * Test négatif : ne peut pas créer/modifier de structure tarifaire
 * (fee-structures:create, réservé à Intendant/Admin Établissement).
 */
import { test, expect } from "@playwright/test";
import { API_BASE_URL, DEMO_ACCOUNTS, apiLogin, authHeader, loginUI } from "../helpers/auth";

test.describe("Profil Directeur", () => {
  test("connexion, redirection /dashboard et sidebar visible", async ({ page }) => {
    await loginUI(page, DEMO_ACCOUNTS.directeur.email, DEMO_ACCOUNTS.directeur.password);
    await expect(page.getByRole("link", { name: "Tableau de bord" }).first()).toBeVisible();
  });

  test("publie les résultats d'une classe/période (grades:publish)", async ({ request }) => {
    const { accessToken } = await apiLogin(request, DEMO_ACCOUNTS.directeur.email, DEMO_ACCOUNTS.directeur.password);

    // La permission est vérifiée avant la logique métier : un identifiant
    // fictif renvoie 404/400 côté service, mais jamais 403 pour ce rôle.
    const response = await request.post(`${API_BASE_URL}/grades/publish/fake-class-id/fake-period-id`, {
      headers: authHeader(accessToken),
    });

    expect(response.status()).not.toBe(403);
  });

  test("RBAC négatif : ne peut pas créer de grille tarifaire (fee-structures:create)", async ({ request }) => {
    const { accessToken } = await apiLogin(request, DEMO_ACCOUNTS.directeur.email, DEMO_ACCOUNTS.directeur.password);

    const response = await request.post(`${API_BASE_URL}/fee-structures`, {
      headers: authHeader(accessToken),
      data: { name: "Tentative interdite", totalAmount: 1000, items: [] },
    });

    expect(response.status()).toBe(403);
  });
});

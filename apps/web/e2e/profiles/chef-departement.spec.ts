/**
 * PROFIL : Chef de département
 *
 * Opération fonctionnelle : crée puis modifie une matière (subjects:create,
 * subjects:update).
 * Test négatif : ne peut pas supprimer une matière (subjects:delete, réservé
 * à l'Admin Établissement).
 */
import { test, expect } from "@playwright/test";
import { API_BASE_URL, DEMO_ACCOUNTS, apiLogin, authHeader, loginUI } from "../helpers/auth";

test.describe("Profil Chef de département", () => {
  test("connexion, redirection /dashboard et sidebar visible", async ({ page }) => {
    await loginUI(page, DEMO_ACCOUNTS.chefDepartement.email, DEMO_ACCOUNTS.chefDepartement.password);
    await expect(page.getByRole("link", { name: "Matières" })).toBeVisible();
  });

  test("crée puis modifie une matière", async ({ request }) => {
    const { accessToken } = await apiLogin(
      request,
      DEMO_ACCOUNTS.chefDepartement.email,
      DEMO_ACCOUNTS.chefDepartement.password,
    );

    const code = `E2E${Date.now().toString().slice(-6)}`;
    const createResponse = await request.post(`${API_BASE_URL}/subjects`, {
      headers: authHeader(accessToken),
      data: {
        code,
        name: "Matière E2E Chef de département",
        coefficient: 2,
        weeklyHours: 3,
        category: "SCIENTIFIC",
      },
    });
    expect(createResponse.ok()).toBeTruthy();
    const subject = await createResponse.json();

    const updateResponse = await request.put(`${API_BASE_URL}/subjects/${subject.id}`, {
      headers: authHeader(accessToken),
      data: { name: "Matière E2E modifiée" },
    });
    expect(updateResponse.ok()).toBeTruthy();
    const updated = await updateResponse.json();
    expect(updated.name).toBe("Matière E2E modifiée");
  });

  test("RBAC négatif : ne peut pas supprimer une matière (subjects:delete)", async ({ request }) => {
    const { accessToken } = await apiLogin(
      request,
      DEMO_ACCOUNTS.chefDepartement.email,
      DEMO_ACCOUNTS.chefDepartement.password,
    );

    const response = await request.delete(`${API_BASE_URL}/subjects/fake-subject-id`, {
      headers: authHeader(accessToken),
    });

    expect(response.status()).toBe(403);
  });
});

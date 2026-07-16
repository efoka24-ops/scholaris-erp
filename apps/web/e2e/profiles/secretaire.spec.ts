/**
 * PROFIL : Secrétaire
 *
 * Opération fonctionnelle : inscrit un élève (students:create) et vérifie
 * que le matricule est bien généré/renvoyé.
 * Test négatif : ne peut pas accéder au patrimoine (assets:read, réservé à
 * Intendant/Admin Établissement).
 */
import { test, expect } from "@playwright/test";
import { API_BASE_URL, DEMO_ACCOUNTS, apiLogin, authHeader, loginUI } from "../helpers/auth";

test.describe("Profil Secrétaire", () => {
  test("connexion, redirection /dashboard et sidebar visible", async ({ page }) => {
    await loginUI(page, DEMO_ACCOUNTS.secretaire.email, DEMO_ACCOUNTS.secretaire.password);
    await expect(page.getByRole("link", { name: "Inscriptions" })).toBeVisible();
  });

  test("inscrit un élève et vérifie que le matricule est généré", async ({ request }) => {
    const { accessToken } = await apiLogin(request, DEMO_ACCOUNTS.secretaire.email, DEMO_ACCOUNTS.secretaire.password);

    const response = await request.post(`${API_BASE_URL}/students`, {
      headers: authHeader(accessToken),
      data: {
        firstName: "E2E",
        lastName: `Secretaire${Date.now()}`,
        dateOfBirth: "2012-06-10",
        gender: "FEMALE",
      },
    });

    expect(response.ok()).toBeTruthy();
    const student = await response.json();
    expect(student.matricule).toBeTruthy();
    expect(typeof student.matricule).toBe("string");
  });

  test("RBAC négatif : ne peut pas accéder au patrimoine (assets:read)", async ({ request }) => {
    const { accessToken } = await apiLogin(request, DEMO_ACCOUNTS.secretaire.email, DEMO_ACCOUNTS.secretaire.password);

    const response = await request.get(`${API_BASE_URL}/assets`, {
      headers: authHeader(accessToken),
    });

    expect(response.status()).toBe(403);
  });
});

/**
 * PROFIL : Admin Établissement
 *
 * Opération fonctionnelle : modification de la config établissement
 * (tenants:update) puis vérification de la persistance (GET relit la valeur).
 * Test négatif : ne peut pas publier les résultats (grades:publish, réservé
 * au Directeur).
 */
import { test, expect } from "@playwright/test";
import { API_BASE_URL, DEMO_ACCOUNTS, apiLogin, authHeader, loginUI } from "../helpers/auth";

test.describe("Profil Admin Établissement", () => {
  test("connexion, redirection /dashboard et sidebar visible", async ({ page }) => {
    await loginUI(page, DEMO_ACCOUNTS.adminEtablissement.email, DEMO_ACCOUNTS.adminEtablissement.password);
    await expect(page.getByRole("link", { name: "Établissement" })).toBeVisible();
  });

  test("modifie la config établissement et vérifie la persistance", async ({ request }) => {
    const { accessToken, user } = await apiLogin(
      request,
      DEMO_ACCOUNTS.adminEtablissement.email,
      DEMO_ACCOUNTS.adminEtablissement.password,
    );
    const tenantId = user.tenantId;

    const newPhone = `+237 6${Math.floor(10000000 + Math.random() * 89999999)}`;
    const updateResponse = await request.put(`${API_BASE_URL}/tenants/${tenantId}`, {
      headers: authHeader(accessToken),
      data: { phone: newPhone },
    });
    expect(updateResponse.ok()).toBeTruthy();

    const readResponse = await request.get(`${API_BASE_URL}/tenants/${tenantId}`, {
      headers: authHeader(accessToken),
    });
    expect(readResponse.ok()).toBeTruthy();
    const tenant = await readResponse.json();
    expect(tenant.phone).toBe(newPhone);
  });

  test("RBAC négatif : ne peut pas publier les résultats (grades:publish réservé au Directeur)", async ({
    request,
  }) => {
    const { accessToken } = await apiLogin(
      request,
      DEMO_ACCOUNTS.adminEtablissement.email,
      DEMO_ACCOUNTS.adminEtablissement.password,
    );

    const response = await request.post(`${API_BASE_URL}/grades/publish/fake-class-id/fake-period-id`, {
      headers: authHeader(accessToken),
    });

    expect(response.status()).toBe(403);
  });
});

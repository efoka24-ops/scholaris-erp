/**
 * PROFIL : Bibliothécaire
 *
 * Opération fonctionnelle : ajoute un livre au catalogue (library:create).
 * Test négatif : ne peut pas accéder à la finance (fee-structures:read).
 */
import { test, expect } from "@playwright/test";
import { API_BASE_URL, DEMO_ACCOUNTS, apiLogin, authHeader, loginUI } from "../helpers/auth";

test.describe("Profil Bibliothécaire", () => {
  test("connexion, redirection /dashboard et sidebar visible", async ({ page }) => {
    await loginUI(page, DEMO_ACCOUNTS.bibliothecaire.email, DEMO_ACCOUNTS.bibliothecaire.password);
    await expect(page.getByRole("link", { name: "Bibliothèque" })).toBeVisible();
  });

  test("ajoute un livre au catalogue (library:create)", async ({ request }) => {
    const { accessToken } = await apiLogin(
      request,
      DEMO_ACCOUNTS.bibliothecaire.email,
      DEMO_ACCOUNTS.bibliothecaire.password,
    );

    const response = await request.post(`${API_BASE_URL}/library/books`, {
      headers: authHeader(accessToken),
      data: {
        title: `Livre E2E ${Date.now()}`,
        author: "Auteur Test",
        isbn: `978-2-${Date.now().toString().slice(-9)}`,
        quantity: 3,
      },
    });

    expect(response.ok()).toBeTruthy();
    const book = await response.json();
    expect(book.title).toContain("Livre E2E");
  });

  test("RBAC négatif : ne peut pas accéder aux grilles tarifaires (fee-structures:read)", async ({ request }) => {
    const { accessToken } = await apiLogin(
      request,
      DEMO_ACCOUNTS.bibliothecaire.email,
      DEMO_ACCOUNTS.bibliothecaire.password,
    );

    const response = await request.get(`${API_BASE_URL}/fee-structures`, {
      headers: authHeader(accessToken),
    });

    expect(response.status()).toBe(403);
  });
});

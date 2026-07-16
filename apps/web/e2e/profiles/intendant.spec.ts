/**
 * PROFIL : Intendant
 *
 * Opération fonctionnelle : enregistre un paiement sur une facture existante
 * et vérifie que le solde de la facture est mis à jour (parcours identique à
 * apps/web/e2e/integration/financial-cycle.spec.ts, mais rejoué avec le
 * compte Intendant plutôt que Super Admin).
 * Test négatif : ne peut pas saisir de notes (grades:create réservé à
 * Enseignant/Censeur).
 */
import { test, expect } from "@playwright/test";
import { API_BASE_URL, DEMO_ACCOUNTS, apiLogin, authHeader, loginUI } from "../helpers/auth";

test.describe("Profil Intendant", () => {
  test("connexion, redirection /dashboard et sidebar visible", async ({ page }) => {
    await loginUI(page, DEMO_ACCOUNTS.intendant.email, DEMO_ACCOUNTS.intendant.password);
    await expect(page.getByRole("link", { name: "Paiements" })).toBeVisible();
  });

  test("enregistre un paiement et vérifie la mise à jour du solde", async ({ request }) => {
    const { accessToken, user } = await apiLogin(request, DEMO_ACCOUNTS.intendant.email, DEMO_ACCOUNTS.intendant.password);

    // Récupère une facture existante du tenant démo pour y adosser le paiement.
    const invoicesResponse = await request.get(`${API_BASE_URL}/invoices?limit=1`, {
      headers: authHeader(accessToken),
    });
    expect(invoicesResponse.ok()).toBeTruthy();
    const invoices = await invoicesResponse.json();

    test.skip(!invoices.data?.length, "Aucune facture de démo disponible pour ce tenant — jeu de données à compléter");

    const invoice = invoices.data[0];
    const amount = Math.min(1000, invoice.balance || 1000);

    const paymentResponse = await request.post(`${API_BASE_URL}/payments`, {
      headers: authHeader(accessToken),
      data: {
        invoiceId: invoice.id,
        amount,
        method: "CASH",
        reference: `E2E-INTENDANT-${Date.now()}`,
      },
    });
    expect(paymentResponse.ok()).toBeTruthy();

    const updatedInvoiceResponse = await request.get(`${API_BASE_URL}/invoices/${invoice.id}`, {
      headers: authHeader(accessToken),
    });
    expect(updatedInvoiceResponse.ok()).toBeTruthy();
    const updatedInvoice = await updatedInvoiceResponse.json();
    expect(updatedInvoice.amountPaid).toBeGreaterThanOrEqual(amount);
  });

  test("RBAC négatif : ne peut pas saisir de notes (grades:create)", async ({ request }) => {
    const { accessToken } = await apiLogin(request, DEMO_ACCOUNTS.intendant.email, DEMO_ACCOUNTS.intendant.password);

    const response = await request.post(`${API_BASE_URL}/grades/batch`, {
      headers: authHeader(accessToken),
      data: { grades: [] },
    });

    expect(response.status()).toBe(403);
  });
});

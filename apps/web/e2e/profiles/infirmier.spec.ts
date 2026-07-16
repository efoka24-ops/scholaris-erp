/**
 * PROFIL : Infirmier(ère)
 *
 * ⚠️ Écart documenté : le rôle "Infirmier(ère)" existe côté RBAC
 * (packages/prisma/src/seed.ts, BUSINESS_ROLES) avec un socle minimal
 * (students:read, internal-messages:*), mais AUCUN module "santé scolaire"
 * n'est exposé côté API — aucune permission `health:*` n'existe, aucun
 * controller ne gère HealthRecord. Le lien de sidebar "Santé scolaire"
 * (/health) pointe donc vers une page qui n'a pas de backend dédié.
 * Ce test se limite donc à la connexion + sidebar, comme demandé, et ne
 * couvre pas d'opération fonctionnelle "santé" puisqu'elle n'existe pas.
 * Test négatif : ne peut pas consulter les notes (grades:read non accordé).
 */
import { test, expect } from "@playwright/test";
import { API_BASE_URL, DEMO_ACCOUNTS, apiLogin, authHeader, loginUI } from "../helpers/auth";

test.describe("Profil Infirmier(ère)", () => {
  test("connexion, redirection /dashboard et sidebar visible", async ({ page }) => {
    await loginUI(page, DEMO_ACCOUNTS.infirmier.email, DEMO_ACCOUNTS.infirmier.password);
    // Le lien "Santé scolaire" est présent dans la sidebar (non filtrée par
    // rôle, cf. ACCES_PROFILS.md) mais ne correspond à aucun module backend.
    await expect(page.getByRole("link", { name: "Santé scolaire" })).toBeVisible();
  });

  test("RBAC négatif : ne peut pas consulter les notes (grades:read non accordé)", async ({ request }) => {
    const { accessToken } = await apiLogin(request, DEMO_ACCOUNTS.infirmier.email, DEMO_ACCOUNTS.infirmier.password);

    const response = await request.get(`${API_BASE_URL}/grades/student/fake-student-id`, {
      headers: authHeader(accessToken),
    });

    expect(response.status()).toBe(403);
  });

  test("permission accordée : peut lire la fiche d'un élève (students:read)", async ({ request }) => {
    const { accessToken } = await apiLogin(request, DEMO_ACCOUNTS.infirmier.email, DEMO_ACCOUNTS.infirmier.password);

    const response = await request.get(`${API_BASE_URL}/students?limit=1`, {
      headers: authHeader(accessToken),
    });

    expect(response.status()).not.toBe(403);
  });
});

import { test, expect } from "@playwright/test";

/**
 * Nécessite une session authentifiée (cookie httpOnly posé après un login
 * réussi) et l'API + PostgreSQL démarrés — non exécutable dans cet
 * environnement sans Docker (voir README). Écrit pour valider le parcours
 * décrit au §2.6.3 du guide dès que l'environnement le permet.
 */
test("parcours : classe créée depuis /academics/classrooms/new apparaît dans la liste", async ({ page }) => {
  await page.goto("/login");
  await page.getByLabel("Email").fill(process.env.SEED_SUPER_ADMIN_EMAIL ?? "admin@scholaris.dev");
  await page.getByLabel("Mot de passe").fill(process.env.SEED_SUPER_ADMIN_PASSWORD ?? "ChangeMe123!");
  await page.getByRole("button", { name: "Se connecter" }).click();
  await expect(page).toHaveURL(/\/dashboard/);

  await page.goto("/academics/classrooms/new");
  const code = `E2E-${Date.now()}`;
  await page.getByLabel("Code").fill(code);
  await page.getByLabel("Nom").fill("Classe E2E");
  await page.getByRole("button", { name: "Créer la classe" }).click();

  await expect(page).toHaveURL(/\/academics\/classrooms$/);
  await expect(page.getByText(code)).toBeVisible();
});

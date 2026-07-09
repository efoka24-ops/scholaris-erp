import { test, expect } from "@playwright/test";

test("la page de login s'affiche et redirige les visiteurs non authentifiés vers /login", async ({ page }) => {
  await page.goto("/");
  await expect(page).toHaveURL(/\/login$/);
  await expect(page.getByRole("heading", { name: "SCHOLARIS ERP" })).toBeVisible();
  await expect(page.getByLabel("Email")).toBeVisible();
  await expect(page.getByLabel("Mot de passe")).toBeVisible();
});

test("/dashboard redirige vers /login sans session", async ({ page }) => {
  await page.goto("/dashboard");
  await expect(page).toHaveURL(/\/login/);
});

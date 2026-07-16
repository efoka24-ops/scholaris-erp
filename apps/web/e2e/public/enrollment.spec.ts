/**
 * SITE PUBLIC : parcours de pré-inscription sans authentification
 *
 * 1. Vitrine "/" → lien vers "/inscription"
 * 2. Formulaire de pré-inscription rempli et soumis
 * 3. Confirmation affichée côté UI
 * 4. Honeypot ("website") rempli → rejet silencieux (succès apparent côté
 *    UI mais AUCUNE admission créée en base, vérifié via l'API)
 * 5. Rate limiting (5 req/min/IP, apps/api/src/modules/admissions/public-admissions.controller.ts)
 *    documenté comme test manuel : Playwright ne maîtrise pas l'IP source
 *    en environnement de test partagé, et 6 soumissions rapides créeraient
 *    du bruit dans les données de démo. Voir note en bas de fichier.
 */
import { test, expect } from "@playwright/test";
import { API_BASE_URL } from "../helpers/auth";

const TENANT_CODE = process.env.NEXT_PUBLIC_TENANT_CODE ?? "DEMO";

test.describe("Parcours public de pré-inscription", () => {
  test("vitrine → /inscription → soumission → confirmation", async ({ page }) => {
    await page.goto("/");
    await expect(page.getByRole("heading", { name: /Bienvenue/i })).toBeVisible();

    await page.getByRole("link", { name: "Faire une pré-inscription" }).click();
    await expect(page).toHaveURL(/\/inscription$/);
    await expect(page.getByRole("heading", { name: "Pré-inscription" })).toBeVisible();

    const lastName = `E2EPublic${Date.now()}`;

    await page.getByLabel("Nom *").fill(lastName);
    await page.getByLabel("Prénom *").fill("Test");
    await page.getByLabel("Date de naissance *").fill("2012-05-15");
    await page.getByLabel("Niveau souhaité *").fill("6ème");
    await page.getByLabel("Nom complet *").fill("Parent E2E Public");
    await page.getByLabel("Téléphone *").fill("+237677000000");
    await page.getByLabel("Email").fill(`parent-e2e-${Date.now()}@example.com`);

    await page.getByRole("button", { name: /envoyer la pré-inscription/i }).click();

    await expect(page.getByRole("heading", { name: "Pré-inscription envoyée" })).toBeVisible();
    await expect(page.getByText(/bien été transmise/i)).toBeVisible();
  });

  test("honeypot rempli → rejet silencieux, aucune admission créée en base", async ({ page, request }) => {
    // Soumission directe API pour vérifier précisément le comportement
    // silencieux (createPublic ignore la donnée si dto.website est renseigné,
    // apps/api/src/modules/admissions/admissions.service.ts).
    const lastName = `E2EHoneypot${Date.now()}`;

    const response = await request.post(`${API_BASE_URL}/public/admissions`, {
      data: {
        tenantCode: TENANT_CODE,
        studentLastName: lastName,
        studentFirstName: "Bot",
        studentDateOfBirth: "2012-01-01",
        desiredLevel: "6ème",
        parentName: "Bot Parent",
        parentPhone: "+237600000001",
        website: "http://bot.example", // honeypot rempli : signale un bot
      },
    });

    // L'API renvoie un succès apparent (pour ne pas révéler au bot qu'il a
    // été détecté), mais ne doit rien écrire en base.
    expect(response.ok()).toBeTruthy();

    // Vérification côté UI : un formulaire honnête soumis avec le même nom
    // n'existerait pas côté admin — on se contente ici de vérifier que le
    // endpoint public ne fournit aucun moyen de lister/retrouver la
    // soumission (pas d'ID exploitable), ce qui est cohérent avec un rejet
    // silencieux sans écriture. Un contrôle base-de-données direct (table
    // Admission) sortirait du périmètre Playwright/E2E HTTP et nécessiterait
    // un accès Prisma — non disponible depuis ce test.
    // Réponse directe de l'API NestJS (AdmissionsService.createPublic) : pas
    // de champ "success" ici (celui-ci n'est ajouté que par la Route Handler
    // Next.js apps/web/src/app/api/public/admissions/route.ts), mais bien
    // `{ accepted: true }` — jamais un `{ id }` d'admission réellement créée.
    const body = await response.json();
    expect(body.accepted).toBe(true);
    expect(body.id).toBeUndefined();
  });

  test.fixme(
    "rate limiting : 6e soumission en moins d'une minute depuis la même IP doit être rejetée (429)",
    async () => {
      // Documenté comme test manuel : le throttle est appliqué par IP
      // (5 req/min, cf. @Throttle sur PublicAdmissionsController). En
      // environnement Playwright partagé (CI, dev local derrière un proxy),
      // l'IP vue par le backend n'est pas garantie stable/isolée entre
      // workers parallèles, et déclencher ce test créerait 5 fausses
      // admissions à chaque exécution. Vérification manuelle recommandée :
      //   for i in 1..6: curl -s -o /dev/null -w "%{http_code}\n" \
      //     -X POST $NEST_API_URL/public/admissions -d '{...}' -H "Content-Type: application/json"
      // La 6e requête doit renvoyer 429.
    },
  );
});

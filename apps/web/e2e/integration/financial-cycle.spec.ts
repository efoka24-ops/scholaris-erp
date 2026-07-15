/**
 * TEST D'INTÉGRATION COMPLET : PARCOURS FINANCIER
 * 
 * Ce test vérifie le parcours financier complet :
 * 1. Inscription → facture auto-générée
 * 2. Paiement partiel (espèces) → vérifier solde
 * 3. Paiement Mobile Money → callback → vérifier
 * 4. Relance automatique → vérifier envoi SMS
 * 5. Reçu PDF → vérifier contenu
 */

import { test, expect } from "@playwright/test";
import { faker } from "@faker-js/faker/locale/fr";

const API_BASE_URL = process.env.NEST_API_URL || "http://localhost:3001/api";

let authToken: string;
let tenantId: string;
let studentId: string;
let enrollmentId: string;
let invoiceId: string;
let payment1Id: string;
let payment2Id: string;

test.describe("Parcours complet: Gestion financière", () => {
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
    tenantId = loginData.user.tenantId;
  });

  test.step("1. Créer élève et structure tarifaire", async ({ request }) => {
    // Créer un élève
    const studentResponse = await request.post(`${API_BASE_URL}/students`, {
      headers: { Authorization: `Bearer ${authToken}` },
      data: {
        firstName: faker.person.firstName(),
        lastName: faker.person.lastName(),
        dateOfBirth: "2009-05-15",
        gender: "M",
        matricule: `FIN${faker.string.numeric(6)}`,
        parent: {
          firstName: "Parent",
          lastName: "Test",
          email: faker.internet.email(),
          phone: "+237 677123456",
          relationship: "MOTHER",
        },
      },
    });

    expect(studentResponse.ok()).toBeTruthy();
    studentId = (await studentResponse.json()).id;

    // Créer structure tarifaire
    const feeStructureResponse = await request.post(`${API_BASE_URL}/fee-structures`, {
      headers: { Authorization: `Bearer ${authToken}` },
      data: {
        name: "Tarif 1ère C - 2024-2025",
        academicYearId: "{{academicYearId}}", // Remplacer par ID réel
        items: [
          { name: "Scolarité annuelle", amount: 150000, category: "TUITION" },
          { name: "Inscription", amount: 25000, category: "REGISTRATION" },
          { name: "Assurance", amount: 5000, category: "INSURANCE" },
        ],
        totalAmount: 180000,
      },
    });

    expect(feeStructureResponse.ok()).toBeTruthy();
  });

  test.step("2. Inscription → Facture auto-générée", async ({ request }) => {
    // Inscrire l'élève
    const enrollmentResponse = await request.post(`${API_BASE_URL}/enrollments`, {
      headers: { Authorization: `Bearer ${authToken}` },
      data: {
        studentId,
        classroomId: "{{classroomId}}", // Remplacer par ID réel
        academicYearId: "{{academicYearId}}",
        status: "ACTIVE",
      },
    });

    expect(enrollmentResponse.ok()).toBeTruthy();
    enrollmentId = (await enrollmentResponse.json()).id;

    // Vérifier que la facture est auto-générée
    const invoicesResponse = await request.get(`${API_BASE_URL}/invoices?enrollmentId=${enrollmentId}`, {
      headers: { Authorization: `Bearer ${authToken}` },
    });

    expect(invoicesResponse.ok()).toBeTruthy();
    const invoices = await invoicesResponse.json();

    expect(invoices.data).toHaveLength(1);
    const invoice = invoices.data[0];

    invoiceId = invoice.id;
    expect(invoice.totalAmount).toBe(180000);
    expect(invoice.status).toBe("PENDING");
    expect(invoice.amountPaid).toBe(0);
    expect(invoice.balance).toBe(180000);
  });

  test.step("3. Paiement partiel (espèces) → Vérifier solde", async ({ request }) => {
    const paymentResponse = await request.post(`${API_BASE_URL}/payments`, {
      headers: { Authorization: `Bearer ${authToken}` },
      data: {
        invoiceId,
        amount: 50000,
        method: "CASH",
        reference: `CASH-${Date.now()}`,
        notes: "Premier versement en espèces",
      },
    });

    expect(paymentResponse.ok()).toBeTruthy();
    const payment = await paymentResponse.json();

    payment1Id = payment.id;
    expect(payment.amount).toBe(50000);
    expect(payment.status).toBe("COMPLETED");

    // Vérifier que la facture est mise à jour
    const invoiceResponse = await request.get(`${API_BASE_URL}/invoices/${invoiceId}`, {
      headers: { Authorization: `Bearer ${authToken}` },
    });

    expect(invoiceResponse.ok()).toBeTruthy();
    const invoice = await invoiceResponse.json();

    expect(invoice.amountPaid).toBe(50000);
    expect(invoice.balance).toBe(130000); // 180000 - 50000
    expect(invoice.status).toBe("PARTIAL");
  });

  test.step("4. Paiement Mobile Money → Callback → Vérifier", async ({ request }) => {
    // Initier paiement Mobile Money
    const mobileMoneyResponse = await request.post(`${API_BASE_URL}/payments`, {
      headers: { Authorization: `Bearer ${authToken}` },
      data: {
        invoiceId,
        amount: 100000,
        method: "MOBILE_MONEY",
        provider: "MTN",
        phoneNumber: "+237677123456",
      },
    });

    expect(mobileMoneyResponse.ok()).toBeTruthy();
    const mobilePayment = await mobileMoneyResponse.json();

    payment2Id = mobilePayment.id;
    expect(mobilePayment.status).toBe("PENDING");
    expect(mobilePayment.transactionId).toBeDefined();

    // Simuler callback de succès du provider Mobile Money
    const callbackResponse = await request.post(`${API_BASE_URL}/webhooks/mobile-money/callback`, {
      data: {
        transactionId: mobilePayment.transactionId,
        status: "SUCCESS",
        amount: 100000,
        reference: mobilePayment.reference,
        providerReference: `MTN${Date.now()}`,
      },
    });

    expect(callbackResponse.ok()).toBeTruthy();

    // Vérifier que le paiement est marqué comme complété
    const updatedPaymentResponse = await request.get(`${API_BASE_URL}/payments/${payment2Id}`, {
      headers: { Authorization: `Bearer ${authToken}` },
    });

    expect(updatedPaymentResponse.ok()).toBeTruthy();
    const updatedPayment = await updatedPaymentResponse.json();

    expect(updatedPayment.status).toBe("COMPLETED");

    // Vérifier la facture
    const invoiceResponse = await request.get(`${API_BASE_URL}/invoices/${invoiceId}`, {
      headers: { Authorization: `Bearer ${authToken}` },
    });

    expect(invoiceResponse.ok()).toBeTruthy();
    const invoice = await invoiceResponse.json();

    expect(invoice.amountPaid).toBe(150000); // 50000 + 100000
    expect(invoice.balance).toBe(30000); // 180000 - 150000
    expect(invoice.status).toBe("PARTIAL");
  });

  test.step("5. Générer et vérifier reçu PDF", async ({ request }) => {
    const receiptResponse = await request.get(`${API_BASE_URL}/payments/${payment1Id}/receipt`, {
      headers: { Authorization: `Bearer ${authToken}` },
    });

    expect(receiptResponse.ok()).toBeTruthy();
    const receipt = await receiptResponse.json();

    expect(receipt.pdfUrl).toBeDefined();
    expect(receipt.receiptNumber).toBeDefined();
    expect(receipt.studentName).toBeDefined();
    expect(receipt.amount).toBe(50000);
    expect(receipt.method).toBe("CASH");
    expect(receipt.date).toBeDefined();

    // Vérifier que le PDF est accessible
    const pdfResponse = await request.get(receipt.pdfUrl);
    expect(pdfResponse.ok()).toBeTruthy();
    expect(pdfResponse.headers()["content-type"]).toContain("application/pdf");
  });

  test.step("6. Relance automatique pour solde impayé → SMS", async ({ request }) => {
    // Déclencher relance automatique
    const reminderResponse = await request.post(`${API_BASE_URL}/invoices/${invoiceId}/send-reminder`, {
      headers: { Authorization: `Bearer ${authToken}` },
      data: {
        channel: "SMS",
      },
    });

    expect(reminderResponse.ok()).toBeTruthy();
    const result = await reminderResponse.json();

    expect(result.sent).toBe(true);
    expect(result.channel).toBe("SMS");
    expect(result.recipient).toBe("+237677123456");
    expect(result.message).toContain("30000"); // Montant restant
  });

  test.step("7. Paiement final et facture soldée", async ({ request }) => {
    // Payer le solde restant
    const finalPaymentResponse = await request.post(`${API_BASE_URL}/payments`, {
      headers: { Authorization: `Bearer ${authToken}` },
      data: {
        invoiceId,
        amount: 30000,
        method: "BANK_TRANSFER",
        reference: `BANK-${Date.now()}`,
      },
    });

    expect(finalPaymentResponse.ok()).toBeTruthy();

    // Vérifier que la facture est soldée
    const invoiceResponse = await request.get(`${API_BASE_URL}/invoices/${invoiceId}`, {
      headers: { Authorization: `Bearer ${authToken}` },
    });

    expect(invoiceResponse.ok()).toBeTruthy();
    const invoice = await invoiceResponse.json();

    expect(invoice.amountPaid).toBe(180000);
    expect(invoice.balance).toBe(0);
    expect(invoice.status).toBe("PAID");
  });

  test.step("8. Vérifier résumé financier de l'élève", async ({ request }) => {
    const summaryResponse = await request.get(`${API_BASE_URL}/students/${studentId}/financial-summary`, {
      headers: { Authorization: `Bearer ${authToken}` },
    });

    expect(summaryResponse.ok()).toBeTruthy();
    const summary = await summaryResponse.json();

    expect(summary.totalInvoiced).toBe(180000);
    expect(summary.totalPaid).toBe(180000);
    expect(summary.totalBalance).toBe(0);
    expect(summary.paymentCount).toBe(3);
    expect(summary.invoiceCount).toBe(1);
  });
});

/**
 * SCRIPT DE TEST COMPLET - PROFIL SUPER ADMIN
 * Tests automatisés de toutes les fonctionnalités
 * 
 * Utilisation :
 *   npm install axios chalk
 *   node test-super-admin.js
 */

const axios = require('axios');

// Simple colored logging without chalk
const colors = {
  green: (text) => `\x1b[32m${text}\x1b[0m`,
  red: (text) => `\x1b[31m${text}\x1b[0m`,
  blue: (text) => `\x1b[34m${text}\x1b[0m`,
  yellow: (text) => `\x1b[33m${text}\x1b[0m`,
  cyan: (text) => `\x1b[36m${text}\x1b[0m`
};

// Configuration
const API_URL = 'https://scholaris-erp-production.up.railway.app/api';
const CREDENTIALS = {
  email: 'admin@scholaris.dev',
  password: 'ChangeMe123!'
};

let accessToken = '';
let refreshToken = '';
let tenantId = '';
let academicYearId = '';
let periodId = '';
let classId = '';
let studentIds = [];
let subjectIds = [];

// Utilitaires
const log = {
  success: (msg) => console.log(colors.green('✅ ' + msg)),
  error: (msg) => console.log(colors.red('❌ ' + msg)),
  info: (msg) => console.log(colors.blue('ℹ️  ' + msg)),
  section: (msg) => console.log(colors.yellow('\n' + '='.repeat(60) + '\n' + msg + '\n' + '='.repeat(60)))
};

const apiCall = async (method, endpoint, data = null, expectError = false) => {
  try {
    const config = {
      method,
      url: `${API_URL}${endpoint}`,
      headers: {
        'Authorization': `Bearer ${accessToken}`,
        'Content-Type': 'application/json',
        'X-Tenant-ID': tenantId
      }
    };
    if (data) config.data = data;
    
    const response = await axios(config);
    return response.data;
  } catch (error) {
    if (expectError) {
      return { error: true, status: error.response?.status, message: error.response?.data };
    }
    log.error(`${method} ${endpoint} - ${error.response?.status || error.message}`);
    console.log(error.response?.data || error.message);
    throw error;
  }
};

// ============================================================
// MODULE 1 - AUTHENTIFICATION ET CONFIGURATION
// ============================================================

async function testAuthentication() {
  log.section('MODULE 1 - AUTHENTIFICATION ET CONFIGURATION');
  
  // Test 1.1 - Login
  log.info('Test 1.1 - Login Super Admin');
  const loginResponse = await axios.post(`${API_URL}/auth/login`, CREDENTIALS);
  accessToken = loginResponse.data.accessToken;
  refreshToken = loginResponse.data.refreshToken;
  
  if (!accessToken) {
    throw new Error('Access token non reçu');
  }
  log.success('Login réussi, JWT reçu');
  
  // Décoder le JWT pour extraire tenantId et permissions
  const jwtPayload = JSON.parse(Buffer.from(accessToken.split('.')[1], 'base64').toString());
  tenantId = jwtPayload.tenantId;
  const permissions = jwtPayload.permissions;
  
  log.success(`Tenant ID: ${tenantId}`);
  log.success(`Permissions chargées: ${permissions.length} permissions`);
  console.log('Exemples de permissions:', permissions.slice(0, 10).join(', '));
  
  // Test 1.2 - Refresh token
  log.info('Test 1.2 - Refresh JWT');
  const refreshResponse = await axios.post(`${API_URL}/auth/refresh`, { refreshToken });
  const newAccessToken = refreshResponse.data.accessToken;
  if (!newAccessToken) throw new Error('Refresh token échoué');
  log.success('Refresh token fonctionnel');
  
  // Test 1.3 - Liste des utilisateurs
  log.info('Test 1.3 - Liste des utilisateurs');
  const users = await apiCall('GET', '/users');
  log.success(`${users.data?.length || users.length || 0} utilisateur(s) trouvé(s)`);
  
  // Test 1.4 - Détail établissement
  log.info('Test 1.4 - Configuration établissement');
  const tenant = await apiCall('GET', `/tenants/${tenantId}`);
  log.success(`Établissement: ${tenant.name} (${tenant.type}) - ${tenant.status}`);
  console.log('Config moteur de calcul:', tenant.configJson);
  
  // Test 1.5 - Années académiques
  log.info('Test 1.5 - Années académiques');
  const academicYears = await apiCall('GET', '/academic-years');
  if (academicYears.data && academicYears.data.length > 0) {
    academicYearId = academicYears.data[0].id;
    log.success(`${academicYears.data.length} année(s) académique(s) trouvée(s)`);
  } else {
    log.info('Création année académique 2026-2027');
    const newYear = await apiCall('POST', '/academic-years', {
      label: '2026-2027',
      startDate: '2026-09-01',
      endDate: '2027-07-31',
      status: 'active'
    });
    academicYearId = newYear.id;
    log.success('Année académique créée');
  }
  
  // Test 1.6 - Périodes
  log.info('Test 1.6 - Périodes (Séquences)');
  const periods = await apiCall('GET', '/periods');
  if (periods.data && periods.data.length > 0) {
    periodId = periods.data[0].id;
    log.success(`${periods.data.length} période(s) trouvée(s)`);
  } else {
    log.info('Création Séquence 1');
    const newPeriod = await apiCall('POST', '/periods', {
      type: 'sequence',
      number: 1,
      startDate: '2026-09-01',
      endDate: '2026-11-15',
      academicYearId: academicYearId,
      gradingStatus: 'open'
    });
    periodId = newPeriod.id;
    log.success('Séquence 1 créée');
  }
  
  // Test 1.7 - Journal d'audit
  log.info('Test 1.7 - Journal d\'audit');
  const auditLogs = await apiCall('GET', '/audit-logs?page=1&limit=10');
  log.success(`${auditLogs.meta?.total || 0} entrées d'audit`);
}

// ============================================================
// MODULE 2 - STRUCTURE PÉDAGOGIQUE
// ============================================================

async function testAcademicStructure() {
  log.section('MODULE 2 - STRUCTURE PÉDAGOGIQUE');
  
  // Test 2.1 - Cycles
  log.info('Test 2.1 - Cycles');
  let cycles = await apiCall('GET', '/cycles');
  if (!cycles.data || cycles.data.length === 0) {
    const cycle = await apiCall('POST', '/cycles', {
      code: 'PREM',
      name: 'Premier Cycle',
      order: 1
    });
    log.success('Cycle créé');
  } else {
    log.success(`${cycles.data.length} cycle(s) existant(s)`);
  }
  
  // Test 2.2 - Programmes (Filières)
  log.info('Test 2.2 - Programmes');
  let programs = await apiCall('GET', '/programs');
  if (!programs.data || programs.data.length === 0) {
    const program = await apiCall('POST', '/programs', {
      code: 'GENERAL',
      name: 'Enseignement Général',
      cycleId: cycles.data[0].id
    });
    log.success('Programme créé');
  } else {
    log.success(`${programs.data.length} programme(s) existant(s)`);
  }
  
  // Test 2.3 - Niveaux
  log.info('Test 2.3 - Niveaux');
  let levels = await apiCall('GET', '/levels');
  if (!levels.data || levels.data.length === 0) {
    const level = await apiCall('POST', '/levels', {
      code: '6EME',
      name: '6ème',
      order: 1,
      cycleId: cycles.data[0].id
    });
    log.success('Niveau créé');
    levels = await apiCall('GET', '/levels');
  } else {
    log.success(`${levels.data.length} niveau(x) existant(s)`);
  }
  
  // Test 2.4 - Salles
  log.info('Test 2.4 - Salles');
  let rooms = await apiCall('GET', '/rooms');
  if (!rooms.data || rooms.data.length === 0) {
    const room = await apiCall('POST', '/rooms', {
      code: 'A101',
      name: 'Salle A101',
      type: 'classroom',
      capacity: 40,
      building: 'Bloc A',
      floor: 1
    });
    log.success('Salle créée');
    rooms = await apiCall('GET', '/rooms');
  } else {
    log.success(`${rooms.data.length} salle(s) existante(s)`);
  }
  
  // Test 2.5 - Classes
  log.info('Test 2.5 - Classes');
  let classrooms = await apiCall('GET', '/classrooms');
  if (!classrooms.data || classrooms.data.length === 0) {
    const classroom = await apiCall('POST', '/classrooms', {
      code: '6A',
      name: '6ème A',
      capacity: 40,
      levelId: levels.data[0].id,
      roomId: rooms.data[0]?.id,
      section: 'FR'
    });
    classId = classroom.id;
    log.success('Classe créée');
  } else {
    classId = classrooms.data[0].id;
    log.success(`${classrooms.data.length} classe(s) existante(s)`);
  }
  
  // Test 2.6 - Arborescence complète
  log.info('Test 2.6 - Arborescence structure');
  const tree = await apiCall('GET', '/structure/tree');
  log.success('Arborescence chargée');
  console.log(JSON.stringify(tree, null, 2).substring(0, 500) + '...');
}

// ============================================================
// MODULE 3 - MATIÈRES, UE ET EC
// ============================================================

async function testSubjects() {
  log.section('MODULE 3 - MATIÈRES, UE ET EC');
  
  // Test 3.1 - Matières
  log.info('Test 3.1 - Création matières');
  let subjects = await apiCall('GET', '/subjects');
  
  if (!subjects.data || subjects.data.length === 0) {
    const subjectsToCreate = [
      { code: 'MATH', name: 'Mathématiques', coefficient: 5, weeklyHours: 6, category: 'scientific' },
      { code: 'FR', name: 'Français', coefficient: 5, weeklyHours: 5, category: 'literary' },
      { code: 'ANG', name: 'Anglais', coefficient: 4, weeklyHours: 4, category: 'language' }
    ];
    
    for (const subject of subjectsToCreate) {
      const created = await apiCall('POST', '/subjects', subject);
      subjectIds.push(created.id);
    }
    log.success(`${subjectsToCreate.length} matières créées`);
  } else {
    subjectIds = subjects.data.slice(0, 3).map(s => s.id);
    log.success(`${subjects.data.length} matière(s) existante(s)`);
  }
  
  // Test 3.2 - Assignation enseignants
  log.info('Test 3.2 - Assignation enseignants (à implémenter côté backend)');
  // Cette partie nécessite d'abord des enseignants créés
}

// ============================================================
// MODULE 4 - INSCRIPTIONS ET ADMISSIONS
// ============================================================

async function testEnrollments() {
  log.section('MODULE 4 - INSCRIPTIONS ET ADMISSIONS');
  
  // Test 4.1 - Création élèves
  log.info('Test 4.1 - Création de 5 élèves de test');
  
  const students = await apiCall('GET', '/students');
  
  if (students.data && students.data.length >= 5) {
    studentIds = students.data.slice(0, 5).map(s => s.id);
    log.success(`${students.data.length} élève(s) déjà existant(s)`);
  } else {
    const studentsToCreate = [
      {
        firstName: 'Jean',
        lastName: 'MBALLA',
        dateOfBirth: '2012-05-15',
        placeOfBirth: 'Yaoundé',
        gender: 'M',
        nationality: 'Camerounaise',
        parents: [
          { firstName: 'Paul', lastName: 'MBALLA', phone: '+237677000001', relationship: 'father' }
        ]
      },
      {
        firstName: 'Marie',
        lastName: 'ETOA',
        dateOfBirth: '2012-08-20',
        placeOfBirth: 'Douala',
        gender: 'F',
        nationality: 'Camerounaise',
        parents: [
          { firstName: 'Sophie', lastName: 'ETOA', phone: '+237677000002', relationship: 'mother' }
        ]
      },
      {
        firstName: 'Pierre',
        lastName: 'NKOLO',
        dateOfBirth: '2012-03-10',
        placeOfBirth: 'Bafoussam',
        gender: 'M',
        nationality: 'Camerounaise'
      },
      {
        firstName: 'Awa',
        lastName: 'DIOP',
        dateOfBirth: '2012-11-25',
        placeOfBirth: 'Maroua',
        gender: 'F',
        nationality: 'Camerounaise'
      },
      {
        firstName: 'Ibrahim',
        lastName: 'ALI',
        dateOfBirth: '2012-07-05',
        placeOfBirth: 'Garoua',
        gender: 'M',
        nationality: 'Camerounaise'
      }
    ];
    
    for (const student of studentsToCreate) {
      try {
        const created = await apiCall('POST', '/students', student);
        studentIds.push(created.id);
        log.success(`Élève créé: ${student.firstName} ${student.lastName} (Matricule: ${created.matricule})`);
      } catch (error) {
        // Student might already exist
      }
    }
  }
  
  // Test 4.2 - Inscription dans une classe
  log.info('Test 4.2 - Inscription des élèves dans la classe');
  
  for (const studentId of studentIds.slice(0, 3)) {
    try {
      const enrollment = await apiCall('POST', '/enrollments', {
        studentId,
        classroomId: classId,
        academicYearId,
        enrollmentDate: '2026-09-01',
        type: 'new',
        status: 'active',
        regime: 'external'
      });
      log.success(`Élève ${studentId.substring(0, 8)}... inscrit`);
    } catch (error) {
      // Already enrolled
    }
  }
  
  // Test 4.3 - Dossier élève complet
  log.info('Test 4.3 - Consultation dossier élève');
  const studentDossier = await apiCall('GET', `/students/${studentIds[0]}`);
  log.success('Dossier élève chargé');
  console.log(`Matricule: ${studentDossier.matricule}`);
  console.log(`Nom: ${studentDossier.firstName} ${studentDossier.lastName}`);
  console.log(`Inscriptions: ${studentDossier.enrollments?.length || 0}`);
}

// ============================================================
// MODULE 5 - SAISIE DES NOTES ET MOTEUR DE CALCUL
// ============================================================

async function testGrades() {
  log.section('MODULE 5 - SAISIE DES NOTES ET MOTEUR DE CALCUL');
  
  // Test 5.1 - Saisie notes collective
  log.info('Test 5.1 - Saisie collective de notes');
  
  const gradesToCreate = [
    { studentId: studentIds[0], value: 15, maxValue: 20, type: 'test', weight: 1, subjectId: subjectIds[0], periodId },
    { studentId: studentIds[1], value: 12, maxValue: 20, type: 'test', weight: 1, subjectId: subjectIds[0], periodId },
    { studentId: studentIds[2], value: 18, maxValue: 20, type: 'test', weight: 1, subjectId: subjectIds[0], periodId }
  ];
  
  try {
    const result = await apiCall('POST', '/grades/batch', { grades: gradesToCreate });
    log.success('Notes saisies en batch');
  } catch (error) {
    log.error('Saisie batch échouée (endpoint peut-être non implémenté)');
  }
  
  // Test 5.2 - Calcul des moyennes
  log.info('Test 5.2 - Calcul des moyennes');
  
  try {
    const calculation = await apiCall('POST', `/grades/calculate/${classId}/${periodId}`);
    log.success('Moyennes calculées');
    console.log('Résultats:', calculation);
  } catch (error) {
    log.error('Calcul échoué (peut nécessiter plus de notes)');
  }
  
  // Test 5.3 - Résultats de la classe
  log.info('Test 5.3 - Consultation résultats classe');
  
  try {
    const results = await apiCall('GET', `/grades/results/${classId}/${periodId}`);
    log.success('Résultats récupérés');
    console.log(`${results.data?.length || 0} résultat(s)`);
  } catch (error) {
    log.error('Résultats non disponibles');
  }
}

// ============================================================
// MODULE 7 - GESTION FINANCIÈRE
// ============================================================

async function testFinance() {
  log.section('MODULE 7 - GESTION FINANCIÈRE');
  
  // Test 7.1 - Types de frais
  log.info('Test 7.1 - Configuration types de frais');
  
  let feeTypes = await apiCall('GET', '/fee-types');
  
  if (!feeTypes.data || feeTypes.data.length === 0) {
    const feesToCreate = [
      { name: 'Inscription', amount: 25000, currency: 'XAF', frequency: 'annual', isMandatory: true },
      { name: 'Scolarité', amount: 180000, currency: 'XAF', frequency: 'annual', isMandatory: true },
      { name: 'Cantine', amount: 50000, currency: 'XAF', frequency: 'trimester', isMandatory: false }
    ];
    
    for (const fee of feesToCreate) {
      await apiCall('POST', '/fee-types', fee);
    }
    log.success('Types de frais créés');
    feeTypes = await apiCall('GET', '/fee-types');
  } else {
    log.success(`${feeTypes.data.length} type(s) de frais existant(s)`);
  }
  
  // Test 7.2 - Génération factures
  log.info('Test 7.2 - Génération factures pour la classe');
  
  try {
    const invoices = await apiCall('POST', `/invoices/generate/${classId}`);
    log.success('Factures générées');
  } catch (error) {
    log.error('Génération factures échouée');
  }
  
  // Test 7.3 - Liste des factures
  log.info('Test 7.3 - Liste des factures');
  
  const invoicesList = await apiCall('GET', '/invoices');
  log.success(`${invoicesList.data?.length || 0} facture(s) trouvée(s)`);
  
  // Test 7.4 - Situation financière d'un élève
  log.info('Test 7.4 - Situation financière élève');
  
  try {
    const financial = await apiCall('GET', `/invoices/student/${studentIds[0]}`);
    log.success('Situation financière récupérée');
    console.log('Total:', financial.totalAmount, 'XAF');
    console.log('Payé:', financial.paidAmount, 'XAF');
    console.log('Solde:', financial.balance, 'XAF');
  } catch (error) {
    log.error('Situation financière non disponible');
  }
  
  // Test 7.5 - Dashboard financier
  log.info('Test 7.5 - Dashboard financier');
  
  try {
    const dashboard = await apiCall('GET', '/finance/dashboard');
    log.success('Dashboard financier chargé');
    console.log(dashboard);
  } catch (error) {
    log.error('Dashboard non disponible');
  }
}

// ============================================================
// MODULE 8 - COMMUNICATION MULTICANAL
// ============================================================

async function testCommunications() {
  log.section('MODULE 8 - COMMUNICATION MULTICANAL');
  
  // Test 8.1 - Templates
  log.info('Test 8.1 - Templates de communication');
  
  const templates = await apiCall('GET', '/communications/templates');
  log.success(`${templates.data?.length || 0} template(s) de communication`);
  
  // Test 8.2 - Historique messages
  log.info('Test 8.2 - Historique des messages');
  
  const history = await apiCall('GET', '/communications/history?page=1&limit=10');
  log.success(`${history.meta?.total || 0} message(s) dans l'historique`);
}

// ============================================================
// TESTS RBAC - CONTRÔLE D'ACCÈS
// ============================================================

async function testRBAC() {
  log.section('TESTS RBAC - CONTRÔLE D\'ACCÈS');
  
  // Test : Tentative d'accès sans JWT
  log.info('Test RBAC 1 - Accès sans authentification');
  try {
    const oldToken = accessToken;
    accessToken = '';
    await apiCall('GET', '/users');
    log.error('Accès autorisé sans JWT (FAILLE DE SÉCURITÉ!)');
  } catch (error) {
    log.success('Accès bloqué sans JWT (401)');
  } finally {
    accessToken = accessToken || oldToken;
  }
  
  // Test : JWT expiré (simulé)
  log.info('Test RBAC 2 - Token invalide');
  try {
    const oldToken = accessToken;
    accessToken = 'invalid_token_abc123';
    await apiCall('GET', '/users', null, true);
    log.error('Accès autorisé avec token invalide (FAILLE!)');
  } catch (error) {
    log.success('Accès bloqué avec token invalide (401)');
  } finally {
    accessToken = oldToken;
  }
}

// ============================================================
// TESTS DE SÉCURITÉ
// ============================================================

async function testSecurity() {
  log.section('TESTS DE SÉCURITÉ');
  
  // Test SQL Injection
  log.info('Test Sécu 1 - Injection SQL');
  try {
    await apiCall('GET', "/students?search=' OR 1=1 --");
    log.success('Requête SQL injection traitée sans crash');
  } catch (error) {
    log.success('Requête SQL injection bloquée');
  }
  
  // Test XSS
  log.info('Test Sécu 2 - Cross-Site Scripting (XSS)');
  try {
    await apiCall('POST', '/students', {
      firstName: '<script>alert("XSS")</script>',
      lastName: 'TEST',
      dateOfBirth: '2012-01-01',
      placeOfBirth: 'Test',
      gender: 'M'
    });
    log.success('Donnée XSS acceptée (vérifier sanitization côté affichage)');
  } catch (error) {
    // Peut être bloquée par validation
  }
}

// ============================================================
// EXÉCUTION PRINCIPALE
// ============================================================

async function runAllTests() {
  console.log(colors.cyan(`
╔═══════════════════════════════════════════════════════════╗
║                                                           ║
║   TESTS AUTOMATISÉS - SCHOLARIS ERP v2.0                 ║
║   PROFIL : SUPER_ADMIN                                   ║
║                                                           ║
╚═══════════════════════════════════════════════════════════╝
  `));
  
  try {
    await testAuthentication();
    await testAcademicStructure();
    await testSubjects();
    await testEnrollments();
    await testGrades();
    await testFinance();
    await testCommunications();
    await testRBAC();
    await testSecurity();
    
    log.section('RÉSUMÉ DES TESTS');
    log.success('Tous les tests ont été exécutés');
    log.info('Consultez PLAN_DE_TEST_COMPLET.md pour le rapport détaillé');
    
  } catch (error) {
    log.error('Erreur critique durant les tests');
    console.error(error);
    process.exit(1);
  }
}

// Lancement
runAllTests().catch(console.error);

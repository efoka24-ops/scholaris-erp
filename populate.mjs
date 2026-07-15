/**
 * SCRIPT DE POPULATION - SCHOLARIS ERP
 * Créer des données de test complètes via l'API
 */

const API_URL = 'https://scholaris-erp-production.up.railway.app/api';
const TOKEN = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiJiNzJhM2IyNS1iMWM5LTQxZjItODMwNi04Mzk5YzcyYzRjNDUiLCJ0ZW5hbnRJZCI6ImZiN2I2NjQ2LTNmYTUtNDVmYy1iNWExLTUwODRlMTViNmIwYiIsImVtYWlsIjoiYWRtaW5Ac2Nob2xhcmlzLmRldiIsInBlcm1pc3Npb25zIjpbInVzZXJzOnJlYWQiLCJhY2FkZW1pYy15ZWFyczpjcmVhdGUiLCJhY2FkZW1pYy15ZWFyczp1cGRhdGUiLCJkZXBhcnRtZW50czp1cGRhdGUiLCJ1c2Vyczp1cGRhdGUiLCJsZXZlbHM6Y3JlYXRlIiwibGV2ZWxzOnVwZGF0ZSIsImxldmVsczpyZWFkIiwidXNlcnM6ZGVsZXRlIiwicGVyaW9kczp1bmxvY2siLCJkZXBhcnRtZW50czpjcmVhdGUiLCJhdWRpdC1sb2dzOnJlYWQiLCJwZXJpb2RzOmNyZWF0ZSIsInRlbmFudHM6dXBkYXRlIiwicGVyaW9kczp1cGRhdGUiLCJhY2FkZW1pYy15ZWFyczpyZWFkIiwidGVuYW50czpyZWFkIiwicHJvZ3JhbXM6Y3JlYXRlIiwidXNlcnM6Y3JlYXRlIiwicGVyaW9kczpyZWFkIiwiY3ljbGVzOnJlYWQiLCJjbGFzc3Jvb21zOnJlYWQiLCJsZXZlbHM6ZGVsZXRlIiwicm9vbXM6Y3JlYXRlIiwiY3ljbGVzOmNyZWF0ZSIsImNvbW11bmljYXRpb25zOnJlYWQiLCJyb29tczp1cGRhdGUiLCJzdHJ1Y3R1cmU6cmVhZCIsImRlcGFydG1lbnRzOnJlYWQiLCJjbGFzc3Jvb21zOnVwZGF0ZSIsImludGVybmFsLW1lc3NhZ2VzOmNyZWF0ZSIsInN1YmplY3RzOnVwZGF0ZSIsInN1YmplY3QtYXNzaWdubWVudHM6cmVhZCIsInJvb21zOnJlYWQiLCJzdWJqZWN0LWFzc2lnbm1lbnRzOmNyZWF0ZSIsInByb2dyYW1zOnJlYWQiLCJzdWJqZWN0czpkZWxldGUiLCJjb3Vyc2UtZWxlbWVudHM6Y3JlYXRlIiwicHJvZ3JhbXM6dXBkYXRlIiwiY2xhc3Nyb29tczpjcmVhdGUiLCJzdHVkZW50czp1cGRhdGUiLCJpbnRlcm5hbC1tZXNzYWdlczpyZWFkIiwiY29tbXVuaWNhdGlvbi10ZW1wbGF0ZXM6Y3JlYXRlIiwidGVhY2hpbmctdW5pdHM6cmVhZCIsImVucm9sbG1lbnRzOnVwZGF0ZSIsImZlZS1zdHJ1Y3R1cmVzOmNyZWF0ZSIsImVucm9sbG1lbnRzOnJlLWVucm9sbCIsInN0dWRlbnRzOmltcG9ydCIsInN0dWRlbnRzOmNyZWF0ZSIsImFkbWlzc2lvbnM6Y3JlYXRlIiwic3ViamVjdHM6cmVhZCIsImZlZS1zdHJ1Y3R1cmVzOnJlYWQiLCJjb21tdW5pY2F0aW9uLXRlbXBsYXRlczpyZWFkIiwiaW52b2ljZXM6Y3JlYXRlIiwiaW52b2ljZXM6cmVhZCIsInRlYWNoaW5nLXVuaXRzOmNyZWF0ZSIsImVucm9sbG1lbnRzOnJlYWQiLCJzdHVkZW50czpyZWFkIiwiY29tbXVuaWNhdGlvbi10ZW1wbGF0ZXM6dXBkYXRlIiwiZW5yb2xsbWVudHM6Y3JlYXRlIiwicGF5bWVudHM6Y3JlYXRlIiwiYWRtaXNzaW9uczpyZWFkIiwic3ViamVjdHM6Y3JlYXRlIiwicGF5bWVudHM6cmVhZCIsImdyYWRlczpkZWxpYmVyYXRpb24iLCJncmFkZXM6cmVhZCIsImdyYWRlczpjcmVhdGUiLCJjb3Vyc2UtZWxlbWVudHM6cmVhZCIsImRpc2NvdW50czpjcmVhdGUiLCJncmFkZXM6dXBkYXRlIiwiZmluYW5jZS1kYXNoYm9hcmQ6cmVhZCIsImdyYWRlczpsb2NrIiwiY29tbXVuaWNhdGlvbnM6Y3JlYXRlIiwiZ3JhZGVzOnVubG9jayIsImdyYWRlczpjYWxjdWxhdGUiLCJhZG1pc3Npb25zOmRlY2lkZSIsImdyYWRlczpwcm9ncmVzcyIsImdyYWRlczpwdWJsaXNoIiwiZ3JhZGVzOmltcG9ydCJdLCJpYXQiOjE3ODQxMTc3OTEsImV4cCI6MTc4NDExODY5MX0.A9_cZUn3AaSTuLX4B36rL4tVvpANOblIp8STSu99YiI'; // Récupérer avec POST /api/auth/login
const TENANT_ID = 'fb7b6646-3fa5-45fc-b5a1-5084e15b6b0b';

async function api(method, endpoint, data = null) {
  const response = await fetch(`${API_URL}${endpoint}`, {
    method,
    headers: {
      'Authorization': `Bearer ${TOKEN}`,
      'Content-Type': 'application/json',
      'X-Tenant-ID': TENANT_ID
    },
    body: data ? JSON.stringify(data) : null
  });
  
  if (!response.ok) {
    const error = await response.text();
    throw new Error(`${method} ${endpoint}: ${response.status} - ${error}`);
  }
  
  return response.json();
}

console.log('🚀 Début de la population de données...\n');

// 1. Créer la structure pédagogique
console.log('📚 Création de la structure pédagogique...');
const cycle = await api('POST', '/cycles', {
  code: 'PREM',
  name: 'Premier Cycle',
  order: 1
});
console.log(`✅ Cycle créé: ${cycle.name}`);

const program = await api('POST', '/programs', {
  code: 'GENERAL',
  name: 'Enseignement Général',
  cycleId: cycle.id
});
console.log(`✅ Programme créé: ${program.name}`);

const level = await api('POST', '/levels', {
  code: '6EME',
  name: '6ème',
  order: 1,
  cycleId: cycle.id
});
console.log(`✅ Niveau créé: ${level.name}`);

const room = await api('POST', '/rooms', {
  code: 'A101',
  name: 'Salle A101',
  type: 'classroom',
  capacity: 40,
  building: 'Bloc A',
  floor: 1
});
console.log(`✅ Salle créée: ${room.name}`);

const classroom = await api('POST', '/classrooms', {
  code: '6A',
  name: '6ème A',
  capacity: 40,
  levelId: level.id,
  roomId: room.id,
  section: 'FR'
});
console.log(`✅ Classe créée: ${classroom.name}\n`);

// 2. Créer des matières
console.log('📖 Création des matières...');
const subjects = [
  { code: 'MATH', name: 'Mathématiques', coefficient: 5, weeklyHours: 6, category: 'scientific' },
  { code: 'FR', name: 'Français', coefficient: 5, weeklyHours: 5, category: 'literary' },
  { code: 'ANG', name: 'Anglais', coefficient: 4, weeklyHours: 4, category: 'language' }
];

for (const subject of subjects) {
  const created = await api('POST', '/subjects', subject);
  console.log(`✅ Matière créée: ${created.name}`);
}

console.log('\n✅ Population terminée avec succès !');
console.log('Rechargez la page pour voir les données.');

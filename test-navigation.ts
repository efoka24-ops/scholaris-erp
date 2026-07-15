import { test, expect } from '@playwright/test';

const ALL_NAV_LINKS = [
  // Principal
  { section: 'Principal', label: 'Tableau de bord', href: '/dashboard' },
  
  // Académique
  { section: 'Académique', label: 'Structure pédagogique', href: '/academics/structure' },
  { section: 'Académique', label: 'Classes', href: '/academics/classrooms' },
  { section: 'Académique', label: 'Salles', href: '/academics/rooms' },
  { section: 'Académique', label: 'Matières', href: '/academics/subjects' },
  { section: 'Académique', label: 'UE & EC', href: '/academics/teaching-units' },
  { section: 'Académique', label: 'Assignations', href: '/academics/assignments' },
  { section: 'Académique', label: 'Années académiques', href: '/settings/academic-years' },
  
  // Élèves
  { section: 'Élèves', label: 'Élèves', href: '/students' },
  { section: 'Élèves', label: 'Admissions', href: '/admissions' },
  { section: 'Élèves', label: 'Inscriptions', href: '/enrollments' },
  
  // Notes & Bulletins
  { section: 'Notes & Bulletins', label: 'Saisie des notes', href: '/grades/entry' },
  { section: 'Notes & Bulletins', label: 'Calculs', href: '/grades/calculations' },
  { section: 'Notes & Bulletins', label: 'Bulletins', href: '/bulletins' },
  
  // Finance
  { section: 'Finance', label: 'Tableau de bord', href: '/finance/dashboard' },
  { section: 'Finance', label: 'Grilles tarifaires', href: '/finance/fee-structures' },
  { section: 'Finance', label: 'Factures', href: '/finance/invoices' },
  { section: 'Finance', label: 'Paiements', href: '/finance/payments' },
  
  // Vie Scolaire
  { section: 'Vie Scolaire', label: 'Emplois du temps', href: '/timetables' },
  { section: 'Vie Scolaire', label: 'Présences', href: '/attendance' },
  { section: 'Vie Scolaire', label: 'Discipline', href: '/discipline' },
  { section: 'Vie Scolaire', label: 'Santé scolaire', href: '/health' },
  { section: 'Vie Scolaire', label: 'Clubs & Activités', href: '/school-life/clubs' },
  { section: 'Vie Scolaire', label: 'Bibliothèque', href: '/library' },
  { section: 'Vie Scolaire', label: 'Transport', href: '/transport' },
  { section: 'Vie Scolaire', label: 'Cantine & Internat', href: '/catering' },
  
  // Gestion
  { section: 'Gestion', label: 'Patrimoine', href: '/assets' },
  { section: 'Gestion', label: 'RH & Paie', href: '/hr' },
  
  // Communication
  { section: 'Communication', label: 'Messages', href: '/communications' },
  { section: 'Communication', label: 'Templates', href: '/communications/templates' },
  
  // Configuration
  { section: 'Configuration', label: 'Établissement', href: '/settings/establishment' },
  { section: 'Configuration', label: 'Utilisateurs', href: '/settings/users' },
  { section: 'Configuration', label: 'Moteur de calcul', href: '/settings/calculation-engine' },
  { section: 'Configuration', label: 'Journal d'audit', href: '/settings/audit-logs' },
];

async function testAllLinksForProfile(profileEmail: string, password: string) {
  console.log(`\n🧪 Test du profil: ${profileEmail}\n`);
  
  const results = {
    profile: profileEmail,
    tested: 0,
    working: [],
    notFound: [],
    errors: []
  };
  
  for (const link of ALL_NAV_LINKS) {
    const url = `http://localhost:3000${link.href}`;
    
    try {
      const response = await fetch(url, {
        method: 'HEAD',
        credentials: 'include'
      });
      
      results.tested++;
      
      if (response.status === 404) {
        results.notFound.push(link);
        console.log(`❌ 404: ${link.label} (${link.href})`);
      } else if (response.ok) {
        results.working.push(link);
        console.log(`✅ OK: ${link.label} (${link.href})`);
      } else {
        results.errors.push({ ...link, status: response.status });
        console.log(`⚠️  ${response.status}: ${link.label} (${link.href})`);
      }
    } catch (error) {
      results.errors.push({ ...link, error: error.message });
      console.log(`💥 ERROR: ${link.label} - ${error.message}`);
    }
  }
  
  return results;
}

// Export pour utilisation dans d'autres scripts
if (require.main === module) {
  testAllLinksForProfile('directeur@demo.scholaris.cm', 'Test123!');
}

import { PrismaClient, TenantType, TenantStatus, UserStatus } from "../generated/client";
import * as bcrypt from "bcrypt";

const prisma = new PrismaClient();

const BASE_PERMISSIONS: Array<{ resource: string; action: string; description: string }> = [
  { resource: "users", action: "create", description: "Créer un utilisateur" },
  { resource: "users", action: "read", description: "Consulter les utilisateurs" },
  { resource: "users", action: "update", description: "Modifier un utilisateur" },
  { resource: "users", action: "delete", description: "Désactiver un utilisateur" },
  { resource: "tenants", action: "read", description: "Consulter l'établissement" },
  { resource: "tenants", action: "update", description: "Modifier la configuration de l'établissement" },
  { resource: "academic-years", action: "create", description: "Créer une année académique" },
  { resource: "academic-years", action: "read", description: "Consulter les années académiques" },
  { resource: "academic-years", action: "update", description: "Activer/clore une année académique" },
  { resource: "periods", action: "read", description: "Consulter les périodes" },
  { resource: "periods", action: "create", description: "Créer une période" },
  { resource: "periods", action: "update", description: "Ouvrir/fermer/verrouiller une période de saisie" },
  { resource: "periods", action: "unlock", description: "Rouvrir une période verrouillée (Admin uniquement)" },
  { resource: "audit-logs", action: "read", description: "Consulter le journal d'audit" },
  // Module 2 — Structure pédagogique
  { resource: "cycles", action: "read", description: "Consulter les cycles" },
  { resource: "cycles", action: "create", description: "Créer un cycle" },
  { resource: "departments", action: "read", description: "Consulter les départements" },
  { resource: "departments", action: "create", description: "Créer un département" },
  { resource: "departments", action: "update", description: "Modifier un département" },
  { resource: "programs", action: "read", description: "Consulter les filières/programmes" },
  { resource: "programs", action: "create", description: "Créer une filière/un programme" },
  { resource: "programs", action: "update", description: "Modifier une filière/un programme" },
  { resource: "levels", action: "read", description: "Consulter les niveaux" },
  { resource: "levels", action: "create", description: "Créer un niveau" },
  { resource: "levels", action: "update", description: "Modifier un niveau" },
  { resource: "levels", action: "delete", description: "Supprimer un niveau" },
  { resource: "classrooms", action: "read", description: "Consulter les classes" },
  { resource: "classrooms", action: "create", description: "Créer une classe" },
  { resource: "classrooms", action: "update", description: "Modifier une classe" },
  { resource: "rooms", action: "read", description: "Consulter les salles" },
  { resource: "rooms", action: "create", description: "Créer une salle" },
  { resource: "rooms", action: "update", description: "Modifier une salle" },
  { resource: "structure", action: "read", description: "Consulter l'arborescence académique complète" },
  // Module 8 : Communication multicanal
  { resource: "communication-templates", action: "create", description: "Créer un modèle de communication" },
  { resource: "communication-templates", action: "read", description: "Consulter les modèles de communication" },
  { resource: "communication-templates", action: "update", description: "Modifier un modèle de communication" },
  { resource: "communications", action: "create", description: "Envoyer une communication" },
  { resource: "communications", action: "read", description: "Consulter le journal des communications" },
  { resource: "internal-messages", action: "create", description: "Envoyer un message interne" },
  { resource: "internal-messages", action: "read", description: "Consulter la messagerie interne" },
  // Module 3 — Matières, UE et EC
  { resource: "subjects", action: "read", description: "Consulter les matières" },
  { resource: "subjects", action: "create", description: "Créer une matière (ou importer depuis Excel)" },
  { resource: "subjects", action: "update", description: "Modifier une matière" },
  { resource: "subjects", action: "delete", description: "Supprimer une matière" },
  { resource: "teaching-units", action: "read", description: "Consulter les unités d'enseignement (UE)" },
  { resource: "teaching-units", action: "create", description: "Créer une unité d'enseignement (UE)" },
  { resource: "course-elements", action: "read", description: "Consulter les éléments constitutifs (EC)" },
  { resource: "course-elements", action: "create", description: "Créer un élément constitutif (EC)" },
  { resource: "subject-assignments", action: "read", description: "Consulter les assignations enseignant/matière" },
  { resource: "subject-assignments", action: "create", description: "Assigner un enseignant à une matière ou un EC" },
  // Module 4 — Inscriptions & Admissions
  { resource: "students", action: "create", description: "Créer un élève (dossier + parents)" },
  { resource: "students", action: "read", description: "Consulter les élèves" },
  { resource: "students", action: "update", description: "Modifier un dossier élève" },
  { resource: "students", action: "import", description: "Importer des élèves depuis Excel" },
  { resource: "enrollments", action: "create", description: "Inscrire un élève dans une classe" },
  { resource: "enrollments", action: "read", description: "Consulter les inscriptions" },
  { resource: "enrollments", action: "update", description: "Annuler/suspendre une inscription" },
  { resource: "enrollments", action: "re-enroll", description: "Réinscription en lot d'une classe vers une autre" },
  { resource: "admissions", action: "create", description: "Enregistrer une candidature d'admission" },
  { resource: "admissions", action: "read", description: "Consulter les candidatures d'admission" },
  { resource: "admissions", action: "decide", description: "Accepter/refuser/mettre en liste d'attente une candidature" },
  // Module 7 — Gestion financière
  { resource: "fee-structures", action: "create", description: "Créer une grille tarifaire" },
  { resource: "fee-structures", action: "read", description: "Consulter les grilles tarifaires" },
  { resource: "invoices", action: "create", description: "Générer une ou plusieurs factures élève" },
  { resource: "invoices", action: "read", description: "Consulter les factures" },
  { resource: "payments", action: "create", description: "Enregistrer un paiement" },
  { resource: "payments", action: "read", description: "Consulter un paiement / reçu" },
  { resource: "discounts", action: "create", description: "Appliquer une réduction/bourse à une facture" },
  { resource: "finance-dashboard", action: "read", description: "Consulter les indicateurs financiers de l'établissement" },
];

async function main() {
  const tenantCode = process.env.SEED_TENANT_CODE ?? "DEMO";
  const tenantName = process.env.SEED_TENANT_NAME ?? "Établissement Démo";
  const adminEmail = process.env.SEED_SUPER_ADMIN_EMAIL ?? "admin@scholaris.dev";
  const adminPassword = process.env.SEED_SUPER_ADMIN_PASSWORD ?? "ChangeMe123!";

  console.log(`→ Seed du tenant "${tenantCode}"…`);
  const tenant = await prisma.tenant.upsert({
    where: { code: tenantCode },
    update: {},
    create: {
      code: tenantCode,
      name: tenantName,
      type: TenantType.SECONDAIRE,
      status: TenantStatus.PUBLIC,
      configJson: {
        moteurCalcul: {
          typeOrganisation: "SEQUENTIEL",
          bareme: 20,
          seuilValidation: 10,
          arrondi: "CENTIEME",
        },
      },
    },
  });

  console.log("→ Seed des permissions de base…");
  const permissions = await Promise.all(
    BASE_PERMISSIONS.map((p) =>
      prisma.permission.upsert({
        where: { resource_action: { resource: p.resource, action: p.action } },
        update: {},
        create: p,
      }),
    ),
  );

  console.log("→ Seed du rôle SUPER_ADMIN (système, tous établissements)…");
  const superAdminRole = await prisma.role.upsert({
    where: { tenantId_name: { tenantId: null as unknown as string, name: "SUPER_ADMIN" } },
    update: {},
    create: {
      tenantId: null,
      name: "SUPER_ADMIN",
      description: "Accès complet, tous établissements",
      isSystem: true,
    },
  });

  await Promise.all(
    permissions.map((permission) =>
      prisma.rolePermission.upsert({
        where: { roleId_permissionId: { roleId: superAdminRole.id, permissionId: permission.id } },
        update: {},
        create: { roleId: superAdminRole.id, permissionId: permission.id },
      }),
    ),
  );

  console.log(`→ Seed de l'utilisateur super admin (${adminEmail})…`);
  const passwordHash = await bcrypt.hash(adminPassword, 12);
  const superAdmin = await prisma.user.upsert({
    where: { tenantId_email: { tenantId: tenant.id, email: adminEmail } },
    update: {},
    create: {
      tenantId: tenant.id,
      email: adminEmail,
      passwordHash,
      firstName: "Super",
      lastName: "Admin",
      status: UserStatus.ACTIVE,
    },
  });

  await prisma.userRole.upsert({
    where: { userId_roleId: { userId: superAdmin.id, roleId: superAdminRole.id } },
    update: {},
    create: { userId: superAdmin.id, roleId: superAdminRole.id },
  });

  console.log("✔ Seed terminé.");
  console.log(`  Tenant   : ${tenant.code} (${tenant.id})`);
  console.log(`  Login    : ${adminEmail}`);
  console.log(`  Mot de passe : ${adminPassword} (à changer immédiatement)`);
}

main()
  .catch((err) => {
    console.error("✘ Échec du seed :", err);
    process.exit(1);
  })
  .finally(async () => {
    await prisma.$disconnect();
  });

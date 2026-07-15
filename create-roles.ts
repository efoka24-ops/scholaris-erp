import { PrismaClient } from "@scholaris/prisma";
import * as dotenv from "dotenv";

dotenv.config();

const prisma = new PrismaClient();

// Définition des rôles métier avec leurs permissions
const BUSINESS_ROLES = [
  {
    name: "Directeur",
    description: "Directeur de l'établissement - accès complet en lecture, gestion administrative",
    permissions: [
      // Lecture complète
      "tenants:read", "users:read", "academic-years:read", "periods:read",
      "cycles:read", "departments:read", "programs:read", "levels:read",
      "classrooms:read", "rooms:read", "structure:read",
      "subjects:read", "teaching-units:read", "course-elements:read",
      "subject-assignments:read",
      "students:read", "enrollments:read", "admissions:read",
      "grades:read", "grades:progress", "grades:calculate", "grades:publish",
      "fee-structures:read", "invoices:read", "payments:read",
      "finance-dashboard:read",
      "communications:read", "communication-templates:read",
      "internal-messages:read", "internal-messages:create",
      "audit-logs:read",
      // Gestion administrative
      "users:create", "users:update",
      "academic-years:create", "academic-years:update",
      "admissions:decide",
      "grades:deliberation",
    ],
  },
  {
    name: "Censeur",
    description: "Censeur - gestion vie scolaire, présences, discipline, emplois du temps",
    permissions: [
      // Structure et académique
      "cycles:read", "departments:read", "programs:read", "levels:read",
      "classrooms:read", "rooms:read", "structure:read",
      "subjects:read", "subject-assignments:read",
      "students:read", "enrollments:read",
      "academic-years:read", "periods:read",
      // Vie scolaire (gestion complète)
      "grades:read", "grades:unlock", "grades:progress",
      "internal-messages:read", "internal-messages:create",
    ],
  },
  {
    name: "Enseignant",
    description: "Enseignant - saisie notes, consultation emploi du temps et élèves",
    permissions: [
      // Consultation
      "classrooms:read", "students:read", "enrollments:read",
      "subjects:read", "subject-assignments:read",
      "academic-years:read", "periods:read",
      // Saisie notes
      "grades:create", "grades:read", "grades:update", "grades:lock",
      "grades:import", "grades:progress",
      // Communication
      "internal-messages:read", "internal-messages:create",
    ],
  },
  {
    name: "Intendant",
    description: "Intendant - gestion financière, patrimoine, approvisionnement",
    permissions: [
      // Finance
      "fee-structures:create", "fee-structures:read",
      "invoices:create", "invoices:read",
      "payments:create", "payments:read",
      "discounts:create",
      "finance-dashboard:read",
      // Consultation élèves/classes
      "students:read", "enrollments:read",
      "classrooms:read",
      // Communication
      "internal-messages:read", "internal-messages:create",
    ],
  },
  {
    name: "Secrétaire",
    description: "Secrétaire - inscriptions, admissions, communication",
    permissions: [
      // Inscriptions & admissions
      "students:create", "students:read", "students:update", "students:import",
      "enrollments:create", "enrollments:read", "enrollments:update",
      "admissions:create", "admissions:read",
      // Structure (consultation)
      "cycles:read", "programs:read", "levels:read", "classrooms:read",
      "academic-years:read",
      // Communication
      "communications:create", "communications:read",
      "communication-templates:create", "communication-templates:read",
      "communication-templates:update",
      "internal-messages:read", "internal-messages:create",
    ],
  },
  {
    name: "Parent",
    description: "Parent d'élève - consultation notes, présences, communication",
    permissions: [
      "grades:read", // Limité à ses enfants
      "students:read", // Limité à ses enfants
      "communications:read",
      "internal-messages:read", "internal-messages:create",
    ],
  },
  {
    name: "Élève",
    description: "Élève - consultation notes, emploi du temps",
    permissions: [
      "grades:read", // Limité à lui-même
      "students:read", // Limité à lui-même
      "internal-messages:read",
    ],
  },
];

async function createRoles() {
  try {
    console.log("🔑 Création des rôles métier...\n");

    // Récupérer le tenant
    const tenant = await prisma.tenant.findFirst();
    if (!tenant) {
      throw new Error("Aucun tenant trouvé. Exécutez d'abord le seed.");
    }

    console.log(`✅ Tenant trouvé: ${tenant.name} (${tenant.id})\n`);

    // Récupérer toutes les permissions
    const allPermissions = await prisma.permission.findMany();
    const permissionMap = new Map(
      allPermissions.map((p) => [`${p.resource}:${p.action}`, p.id])
    );

    for (const roleConfig of BUSINESS_ROLES) {
      console.log(`→ Création du rôle "${roleConfig.name}"...`);

      // Créer le rôle
      const role = await prisma.role.upsert({
        where: {
          tenantId_name: {
            tenantId: tenant.id,
            name: roleConfig.name,
          },
        },
        update: {
          description: roleConfig.description,
        },
        create: {
          tenantId: tenant.id,
          name: roleConfig.name,
          description: roleConfig.description,
          isSystem: false,
        },
      });

      // Assigner les permissions
      let assignedCount = 0;
      for (const permKey of roleConfig.permissions) {
        const permissionId = permissionMap.get(permKey);
        if (permissionId) {
          await prisma.rolePermission.upsert({
            where: {
              roleId_permissionId: {
                roleId: role.id,
                permissionId,
              },
            },
            update: {},
            create: {
              roleId: role.id,
              permissionId,
            },
          });
          assignedCount++;
        } else {
          console.warn(`  ⚠️ Permission inconnue: ${permKey}`);
        }
      }

      console.log(`  ✅ ${assignedCount} permissions assignées`);
    }

    console.log(`\n🎉 ${BUSINESS_ROLES.length} rôles créés avec succès !`);
    console.log("\nRôles disponibles :");
    for (const role of BUSINESS_ROLES) {
      console.log(`  - ${role.name}: ${role.permissions.length} permissions`);
    }
  } catch (error) {
    console.error("❌ Erreur:", error);
    throw error;
  } finally {
    await prisma.$disconnect();
  }
}

createRoles();

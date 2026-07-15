import { PrismaClient } from "@scholaris/prisma";
import * as dotenv from "dotenv";

dotenv.config();

const prisma = new PrismaClient();

// Mapping email → rôle
const USER_ROLE_ASSIGNMENTS = [
  { email: "directeur@demo.scholaris.cm", roleName: "Directeur" },
  { email: "censeur@demo.scholaris.cm", roleName: "Censeur" },
  { email: "enseignant@demo.scholaris.cm", roleName: "Enseignant" },
  { email: "intendant@demo.scholaris.cm", roleName: "Intendant" },
  { email: "secretaire@demo.scholaris.cm", roleName: "Secrétaire" },
];

async function assignRoles() {
  try {
    console.log("🔗 Assignment des rôles aux utilisateurs...\n");

    // Récupérer le tenant
    const tenant = await prisma.tenant.findFirst();
    if (!tenant) {
      throw new Error("Aucun tenant trouvé.");
    }

    console.log(`✅ Tenant: ${tenant.name}\n`);

    for (const assignment of USER_ROLE_ASSIGNMENTS) {
      console.log(`→ ${assignment.email}...`);

      // Trouver l'utilisateur
      const user = await prisma.user.findUnique({
        where: {
          tenantId_email: {
            tenantId: tenant.id,
            email: assignment.email,
          },
        },
      });

      if (!user) {
        console.log(`  ⚠️  Utilisateur introuvable`);
        continue;
      }

      // Trouver le rôle
      const role = await prisma.role.findUnique({
        where: {
          tenantId_name: {
            tenantId: tenant.id,
            name: assignment.roleName,
          },
        },
      });

      if (!role) {
        console.log(`  ⚠️  Rôle "${assignment.roleName}" introuvable`);
        continue;
      }

      // Assigner le rôle
      await prisma.userRole.upsert({
        where: {
          userId_roleId: {
            userId: user.id,
            roleId: role.id,
          },
        },
        update: {},
        create: {
          userId: user.id,
          roleId: role.id,
        },
      });

      console.log(`  ✅ Rôle "${assignment.roleName}" assigné`);
    }

    console.log("\n🎉 Tous les rôles ont été assignés !");
    console.log("\n📊 Vérification finale...\n");

    // Vérification
    for (const assignment of USER_ROLE_ASSIGNMENTS) {
      const user = await prisma.user.findUnique({
        where: {
          tenantId_email: {
            tenantId: tenant.id,
            email: assignment.email,
          },
        },
        include: {
          userRoles: {
            include: {
              role: {
                include: {
                  _count: {
                    select: { rolePermissions: true },
                  },
                },
              },
            },
          },
        },
      });

      if (user) {
        const roleNames = user.userRoles
          .map((ur) => `${ur.role.name} (${ur.role._count.rolePermissions} perms)`)
          .join(", ");
        console.log(`  ${assignment.email}: ${roleNames}`);
      }
    }

    console.log("\n✅ Terminé ! Les utilisateurs peuvent maintenant se connecter avec leurs permissions.");
  } catch (error) {
    console.error("❌ Erreur:", error);
    throw error;
  } finally {
    await prisma.$disconnect();
  }
}

assignRoles();

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
  { resource: "periods", action: "update", description: "Ouvrir/fermer/verrouiller une période de saisie" },
  { resource: "audit-logs", action: "read", description: "Consulter le journal d'audit" },
  // Module 8 : Communication multicanal
  { resource: "communication-templates", action: "create", description: "Créer un modèle de communication" },
  { resource: "communication-templates", action: "read", description: "Consulter les modèles de communication" },
  { resource: "communication-templates", action: "update", description: "Modifier un modèle de communication" },
  { resource: "communications", action: "create", description: "Envoyer une communication" },
  { resource: "communications", action: "read", description: "Consulter le journal des communications" },
  { resource: "internal-messages", action: "create", description: "Envoyer un message interne" },
  { resource: "internal-messages", action: "read", description: "Consulter la messagerie interne" },
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

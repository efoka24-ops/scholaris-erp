/**
 * Seed CIBLÉ et RÉSILIENT des 12 rôles métier + leurs permissions.
 * Le seed complet échoue sur le proxy public Railway (P1017 "Server has closed
 * the connection") car il enchaîne trop d'opérations. Ici chaque opération est
 * réessayée individuellement avec reconnexion, ce qui survit aux coupures.
 */
import { PrismaClient } from "../generated/client";
import { BUSINESS_ROLES } from "./seed";

let prisma = new PrismaClient();

async function withRetry<T>(label: string, fn: (p: PrismaClient) => Promise<T>, max = 6): Promise<T> {
  for (let attempt = 1; ; attempt++) {
    try {
      return await fn(prisma);
    } catch (err: any) {
      const transient = err?.code === "P1017" || err?.code === "P1001" || /closed the connection/i.test(err?.message ?? "");
      if (!transient || attempt >= max) throw err;
      // reconnexion propre avant de réessayer
      try { await prisma.$disconnect(); } catch {}
      prisma = new PrismaClient();
      await new Promise((r) => setTimeout(r, 1000 * attempt));
      console.warn(`  ↻ ${label} : coupure (${err.code}), reconnexion (tentative ${attempt + 1})`);
    }
  }
}

async function main() {
  const tenant = await withRetry("tenant", (p) => p.tenant.findFirst({ where: { code: "DEMO" } }));
  if (!tenant) throw new Error("Tenant DEMO introuvable");

  const permissions = await withRetry("permissions", (p) => p.permission.findMany());
  const permissionMap = new Map(permissions.map((pm) => [`${pm.resource}:${pm.action}`, pm.id]));
  console.log(`Tenant ${tenant.code} — ${permissions.length} permissions disponibles`);

  for (const roleConfig of BUSINESS_ROLES) {
    const role = await withRetry(`role ${roleConfig.name}`, (p) =>
      p.role.upsert({
        where: { tenantId_name: { tenantId: tenant.id, name: roleConfig.name } },
        update: { description: roleConfig.description },
        create: { tenantId: tenant.id, name: roleConfig.name, description: roleConfig.description, isSystem: false },
      }),
    );

    let assigned = 0;
    for (const permKey of roleConfig.permissions) {
      const permissionId = permissionMap.get(permKey);
      if (!permissionId) {
        console.warn(`  ⚠ Permission inconnue (${roleConfig.name}) : ${permKey}`);
        continue;
      }
      await withRetry(`perm ${roleConfig.name}/${permKey}`, (p) =>
        p.rolePermission.upsert({
          where: { roleId_permissionId: { roleId: role.id, permissionId } },
          update: {},
          create: { roleId: role.id, permissionId },
        }),
      );
      assigned++;
    }
    console.log(`  ✔ ${roleConfig.name.padEnd(22)} : ${assigned} permission(s)`);
  }

  console.log("✅ Rôles métier seedés avec succès.");
}

main()
  .catch((e) => {
    console.error("✘ Échec :", e);
    process.exit(1);
  })
  .finally(async () => {
    try { await prisma.$disconnect(); } catch {}
  });

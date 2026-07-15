import { PrismaClient } from "@scholaris/prisma";

const prisma = new PrismaClient();

async function checkRoles() {
  const roles = await prisma.role.findMany({
    include: {
      _count: {
        select: { rolePermissions: true },
      },
    },
  });
  
  console.log("\n📋 Rôles dans la base de données:\n");
  for (const role of roles) {
    console.log(`  - ${role.name}: ${role._count.rolePermissions} permissions`);
  }
  console.log(`\nTotal: ${roles.length} rôles\n`);
  
  await prisma.$disconnect();
}

checkRoles();

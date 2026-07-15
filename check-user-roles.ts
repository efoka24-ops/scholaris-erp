import { PrismaClient } from "@scholaris/prisma";

const prisma = new PrismaClient();

async function checkUserRoles() {
  const users = await prisma.user.findMany({
    where: {
      email: {
        in: [
          "directeur@demo.scholaris.cm",
          "censeur@demo.scholaris.cm",
          "enseignant@demo.scholaris.cm",
          "intendant@demo.scholaris.cm",
          "secretaire@demo.scholaris.cm",
        ],
      },
    },
    include: {
      userRoles: {
        include: {
          role: true,
        },
      },
    },
  });
  
  console.log("\n👤 Utilisateurs et leurs rôles:\n");
  for (const user of users) {
    const roleNames = user.userRoles.map((ur) => ur.role.name).join(", ");
    console.log(`  ${user.email}: ${roleNames || "AUCUN RÔLE"}`);
  }
  console.log();
  
  await prisma.$disconnect();
}

checkUserRoles();

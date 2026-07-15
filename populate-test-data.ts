import { PrismaClient } from "@scholaris/prisma";
import * as bcrypt from "bcrypt";
import * as dotenv from "dotenv";
import * as path from "path";

// Charger les variables d'environnement depuis .env à la racine
dotenv.config({ path: path.join(__dirname, ".env") });

const prisma = new PrismaClient();

/**
 * Script de population de données de test
 * Usage: npx tsx populate-test-data.ts
 */

async function main() {
  console.log("🌱 Début de la population des données de test...\n");

  // Récupérer le tenant DEMO
  const tenant = await prisma.tenant.findFirst({
    where: { code: "DEMO" },
  });

  if (!tenant) {
    throw new Error("Tenant DEMO not found. Please run seed first.");
  }

  console.log(`✅ Tenant trouvé: ${tenant.name} (${tenant.id})\n`);

  // 1. STRUCTURE PÉDAGOGIQUE
  console.log("📚 Création de la structure pédagogique...");

  // Cycles
  const cycles = await Promise.all([
    prisma.cycle.upsert({
      where: { tenantId_code: { tenantId: tenant.id, code: "PRIM" } },
      update: {},
      create: {
        tenantId: tenant.id,
        code: "PRIM",
        name: "Primaire",
        order: 1,
      },
    }),
    prisma.cycle.upsert({
      where: { tenantId_code: { tenantId: tenant.id, code: "SEC" } },
      update: {},
      create: {
        tenantId: tenant.id,
        code: "SEC",
        name: "Secondaire",
        order: 2,
      },
    }),
    prisma.cycle.upsert({
      where: { tenantId_code: { tenantId: tenant.id, code: "SUP" } },
      update: {},
      create: {
        tenantId: tenant.id,
        code: "SUP",
        name: "Supérieur",
        order: 3,
      },
    }),
  ]);

  console.log(`✅ ${cycles.length} cycles créés`);

  // Programmes / Filières (Secondaire)
  const programs = await Promise.all([
    prisma.program.upsert({
      where: { tenantId_code: { tenantId: tenant.id, code: "A" } },
      update: {},
      create: {
        tenantId: tenant.id,
        cycleId: cycles[1].id, // Secondaire
        code: "A",
        name: "Série A - Littéraire",
      },
    }),
    prisma.program.upsert({
      where: { tenantId_code: { tenantId: tenant.id, code: "C" } },
      update: {},
      create: {
        tenantId: tenant.id,
        cycleId: cycles[1].id,
        code: "C",
        name: "Série C - Scientifique",
      },
    }),
    prisma.program.upsert({
      where: { tenantId_code: { tenantId: tenant.id, code: "D" } },
      update: {},
      create: {
        tenantId: tenant.id,
        cycleId: cycles[1].id,
        code: "D",
        name: "Série D - Sciences naturelles",
      },
    }),
  ]);

  console.log(`✅ ${programs.length} programmes créés`);

  // Niveaux (Secondaire)
  const levels = await Promise.all([
    prisma.level.upsert({
      where: { tenantId_code: { tenantId: tenant.id, code: "6EME" } },
      update: {},
      create: {
        tenantId: tenant.id,
        cycleId: cycles[1].id,
        code: "6EME",
        name: "6ème",
        order: 1,
      },
    }),
    prisma.level.upsert({
      where: { tenantId_code: { tenantId: tenant.id, code: "5EME" } },
      update: {},
      create: {
        tenantId: tenant.id,
        cycleId: cycles[1].id,
        code: "5EME",
        name: "5ème",
        order: 2,
      },
    }),
    prisma.level.upsert({
      where: { tenantId_code: { tenantId: tenant.id, code: "TLE" } },
      update: {},
      create: {
        tenantId: tenant.id,
        cycleId: cycles[1].id,
        code: "TLE",
        name: "Terminale",
        order: 7,
      },
    }),
  ]);

  console.log(`✅ ${levels.length} niveaux créés`);

  // Salles
  const rooms = await Promise.all([
    prisma.room.upsert({
      where: { tenantId_code: { tenantId: tenant.id, code: "A101" } },
      update: {},
      create: {
        tenantId: tenant.id,
        code: "A101",
        name: "Salle A101",
        type: "SALLE_CLASSE",
        capacity: 40,
        building: "Bloc A",
        floor: "1",
      },
    }),
    prisma.room.upsert({
      where: { tenantId_code: { tenantId: tenant.id, code: "A102" } },
      update: {},
      create: {
        tenantId: tenant.id,
        code: "A102",
        name: "Salle A102",
        type: "SALLE_CLASSE",
        capacity: 40,
        building: "Bloc A",
        floor: "1",
      },
    }),
    prisma.room.upsert({
      where: { tenantId_code: { tenantId: tenant.id, code: "LAB-PHY" } },
      update: {},
      create: {
        tenantId: tenant.id,
        code: "LAB-PHY",
        name: "Laboratoire de Physique",
        type: "LABORATOIRE",
        capacity: 30,
        building: "Bloc B",
        floor: "2",
      },
    }),
  ]);

  console.log(`✅ ${rooms.length} salles créées`);

  // Classes
  const classes = await Promise.all([
    prisma.classRoom.upsert({
      where: { tenantId_code: { tenantId: tenant.id, code: "6A" } },
      update: {},
      create: {
        tenantId: tenant.id,
        levelId: levels[0].id, // 6ème
        roomId: rooms[0].id,
        code: "6A",
        name: "6ème A",
        capacity: 40,
        section: "FRANCOPHONE",
      },
    }),
    prisma.classRoom.upsert({
      where: { tenantId_code: { tenantId: tenant.id, code: "6B" } },
      update: {},
      create: {
        tenantId: tenant.id,
        levelId: levels[0].id,
        roomId: rooms[1].id,
        code: "6B",
        name: "6ème B",
        capacity: 40,
        section: "ANGLOPHONE",
      },
    }),
    prisma.classRoom.upsert({
      where: { tenantId_code: { tenantId: tenant.id, code: "TLEC1" } },
      update: {},
      create: {
        tenantId: tenant.id,
        levelId: levels[2].id, // Terminale
        code: "TLEC1",
        name: "Terminale C1",
        capacity: 35,
        section: "FRANCOPHONE",
      },
    }),
  ]);

  console.log(`✅ ${classes.length} classes créées\n`);

  // 2. MATIÈRES
  console.log("📖 Création des matières...");

  const subjects = await Promise.all([
    prisma.subject.upsert({
      where: { tenantId_code: { tenantId: tenant.id, code: "MATH" } },
      update: {},
      create: {
        tenantId: tenant.id,
        code: "MATH",
        name: "Mathématiques",
        coefficient: 5,
        weeklyHours: 6,
        category: "SCIENTIFIC",
        isEliminatory: true,
        eliminatoryThreshold: 5,
      },
    }),
    prisma.subject.upsert({
      where: { tenantId_code: { tenantId: tenant.id, code: "FR" } },
      update: {},
      create: {
        tenantId: tenant.id,
        code: "FR",
        name: "Français",
        coefficient: 4,
        weeklyHours: 5,
        category: "LITERARY",
        isEliminatory: false,
      },
    }),
    prisma.subject.upsert({
      where: { tenantId_code: { tenantId: tenant.id, code: "ANG" } },
      update: {},
      create: {
        tenantId: tenant.id,
        code: "ANG",
        name: "Anglais",
        coefficient: 3,
        weeklyHours: 4,
        category: "LANGUAGE",
        isEliminatory: false,
      },
    }),
    prisma.subject.upsert({
      where: { tenantId_code: { tenantId: tenant.id, code: "PHY" } },
      update: {},
      create: {
        tenantId: tenant.id,
        code: "PHY",
        name: "Physique",
        coefficient: 4,
        weeklyHours: 5,
        category: "SCIENTIFIC",
        isEliminatory: false,
      },
    }),
    prisma.subject.upsert({
      where: { tenantId_code: { tenantId: tenant.id, code: "SVT" } },
      update: {},
      create: {
        tenantId: tenant.id,
        code: "SVT",
        name: "SVT",
        coefficient: 3,
        weeklyHours: 4,
        category: "SCIENTIFIC",
        isEliminatory: false,
      },
    }),
  ]);

  console.log(`✅ ${subjects.length} matières créées\n`);

  // 3. ANNÉE ACADÉMIQUE ET PÉRIODES
  console.log("📅 Création de l'année académique...");

  const academicYear = await prisma.academicYear.upsert({
    where: { tenantId_label: { tenantId: tenant.id, label: "2026-2027" } },
    update: {},
    create: {
      tenantId: tenant.id,
      label: "2026-2027",
      startDate: new Date("2026-09-01"),
      endDate: new Date("2027-07-31"),
      status: "ACTIVE",
    },
  });

  console.log(`✅ Année académique créée: ${academicYear.name}`);

  // Périodes (Séquences)
  const periods = await Promise.all([
    prisma.period.upsert({
      where: { academicYearId_type_number: { academicYearId: academicYear.id, type: "SEQUENCE", number: 1 } },
      update: {},
      create: {
        academicYearId: academicYear.id,
        type: "SEQUENCE",
        number: 1,
        startDate: new Date("2026-09-01"),
        endDate: new Date("2026-10-31"),
        gradingStatus: "OPEN",
      },
    }),
    prisma.period.upsert({
      where: { academicYearId_type_number: { academicYearId: academicYear.id, type: "SEQUENCE", number: 2 } },
      update: {},
      create: {
        academicYearId: academicYear.id,
        type: "SEQUENCE",
        number: 2,
        startDate: new Date("2026-11-01"),
        endDate: new Date("2026-12-20"),
        gradingStatus: "CLOSED",
      },
    }),
  ]);

  console.log(`✅ ${periods.length} périodes créées\n`);

  // 4. UTILISATEURS (différents profils)
  console.log("👥 Création des utilisateurs...");

  const passwordHash = await bcrypt.hash("Test123!", 10);

  // Récupérer les rôles
  const roles = await prisma.role.findMany({
    where: {
      OR: [
        { tenantId: tenant.id },
        { tenantId: null }, // Rôles système
      ],
    },
  });

  const findRole = (name: string) => roles.find((r) => r.name === name);

  // Directeur
  const directeur = await prisma.user.upsert({
    where: { tenantId_email: { tenantId: tenant.id, email: "directeur@demo.scholaris.cm" } },
    update: {},
    create: {
      tenantId: tenant.id,
      email: "directeur@demo.scholaris.cm",
      passwordHash,
      firstName: "Jean",
      lastName: "Directeur",
      status: "ACTIVE",
    },
  });

  if (findRole("Directeur")) {
    await prisma.userRole.upsert({
      where: { userId_roleId: { userId: directeur.id, roleId: findRole("Directeur")!.id } },
      update: {},
      create: { userId: directeur.id, roleId: findRole("Directeur")!.id },
    });
  }

  console.log(`✅ Directeur créé: ${directeur.email}`);

  // Censeur
  const censeur = await prisma.user.upsert({
    where: { tenantId_email: { tenantId: tenant.id, email: "censeur@demo.scholaris.cm" } },
    update: {},
    create: {
      tenantId: tenant.id,
      email: "censeur@demo.scholaris.cm",
      passwordHash,
      firstName: "Marie",
      lastName: "Censeur",
      status: "ACTIVE",
    },
  });

  if (findRole("Censeur")) {
    await prisma.userRole.upsert({
      where: { userId_roleId: { userId: censeur.id, roleId: findRole("Censeur")!.id } },
      update: {},
      create: { userId: censeur.id, roleId: findRole("Censeur")!.id },
    });
  }

  console.log(`✅ Censeur créé: ${censeur.email}`);

  // Enseignant
  const enseignant = await prisma.user.upsert({
    where: { tenantId_email: { tenantId: tenant.id, email: "enseignant@demo.scholaris.cm" } },
    update: {},
    create: {
      tenantId: tenant.id,
      email: "enseignant@demo.scholaris.cm",
      passwordHash,
      firstName: "Paul",
      lastName: "Enseignant",
      status: "ACTIVE",
    },
  });

  if (findRole("Enseignant")) {
    await prisma.userRole.upsert({
      where: { userId_roleId: { userId: enseignant.id, roleId: findRole("Enseignant")!.id } },
      update: {},
      create: { userId: enseignant.id, roleId: findRole("Enseignant")!.id },
    });
  }

  console.log(`✅ Enseignant créé: ${enseignant.email}`);

  // Intendant
  const intendant = await prisma.user.upsert({
    where: { tenantId_email: { tenantId: tenant.id, email: "intendant@demo.scholaris.cm" } },
    update: {},
    create: {
      tenantId: tenant.id,
      email: "intendant@demo.scholaris.cm",
      passwordHash,
      firstName: "Sophie",
      lastName: "Intendant",
      status: "ACTIVE",
    },
  });

  if (findRole("Intendant")) {
    await prisma.userRole.upsert({
      where: { userId_roleId: { userId: intendant.id, roleId: findRole("Intendant")!.id } },
      update: {},
      create: { userId: intendant.id, roleId: findRole("Intendant")!.id },
    });
  }

  console.log(`✅ Intendant créé: ${intendant.email}`);

  // Secrétaire
  const secretaire = await prisma.user.upsert({
    where: { tenantId_email: { tenantId: tenant.id, email: "secretaire@demo.scholaris.cm" } },
    update: {},
    create: {
      tenantId: tenant.id,
      email: "secretaire@demo.scholaris.cm",
      passwordHash,
      firstName: "Claire",
      lastName: "Secrétaire",
      status: "ACTIVE",
    },
  });

  if (findRole("Secrétaire")) {
    await prisma.userRole.upsert({
      where: { userId_roleId: { userId: secretaire.id, roleId: findRole("Secrétaire")!.id } },
      update: {},
      create: { userId: secretaire.id, roleId: findRole("Secrétaire")!.id },
    });
  }

  console.log(`✅ Secrétaire créé: ${secretaire.email}\n`);

  // 5. ÉLÈVES
  console.log("👨‍🎓 Création des élèves...");

  const students = [];
  for (let i = 1; i <= 10; i++) {
    const student = await prisma.student.upsert({
      where: { tenantId_matricule: { tenantId: tenant.id, matricule: `DEMO/2026/${String(i).padStart(4, "0")}` } },
      update: {},
      create: {
        tenantId: tenant.id,
        matricule: `DEMO/2026/${String(i).padStart(4, "0")}`,
        firstName: `Élève${i}`,
        lastName: `Test${i}`,
        dateOfBirth: new Date(`2010-0${(i % 9) + 1}-15`),
        placeOfBirth: "Douala",
        gender: i % 2 === 0 ? "MALE" : "FEMALE",
        nationality: "Camerounaise",
        status: "ACTIVE",
      },
    });
    students.push(student);

    // Inscription dans une classe
    await prisma.enrollment.upsert({
      where: { studentId_academicYearId: { studentId: student.id, academicYearId: academicYear.id } },
      update: {},
      create: {
        tenantId: tenant.id,
        studentId: student.id,
        classroomId: classes[i % 2].id, // Alterner entre 6A et 6B
        academicYearId: academicYear.id,
        enrollmentType: "new",
        regimeType: i % 3 === 0 ? "boarding" : "external",
        status: "ACTIVE",
      },
    });
  }

  console.log(`✅ ${students.length} élèves créés et inscrits\n`);

  // 6. QUELQUES NOTES (pour tests)
  console.log("📝 Création de notes de test...");

  let gradesCount = 0;
  for (const student of students.slice(0, 5)) {
    for (const subject of subjects.slice(0, 3)) {
      await prisma.grade.create({
        data: {
          tenantId: tenant.id,
          studentId: student.id,
          classroomId: classes[0].id,
          subjectId: subject.id,
          periodId: periods[0].id,
          evaluationType: "test",
          score: Math.floor(Math.random() * 10) + 8, // Notes entre 8 et 18
          maxScore: 20,
          weight: 1,
          isAbsent: false,
          teacherId: enseignant.id,
        },
      });
      gradesCount++;
    }
  }

  console.log(`✅ ${gradesCount} notes créées\n`);

  console.log("🎉 Population des données de test terminée avec succès !");
  console.log("\n📊 Résumé:");
  console.log(`  - ${cycles.length} cycles`);
  console.log(`  - ${programs.length} programmes`);
  console.log(`  - ${levels.length} niveaux`);
  console.log(`  - ${classes.length} classes`);
  console.log(`  - ${rooms.length} salles`);
  console.log(`  - ${subjects.length} matières`);
  console.log(`  - 1 année académique avec ${periods.length} périodes`);
  console.log(`  - 6 utilisateurs (directeur, censeur, enseignant, intendant, secrétaire + admin existant)`);
  console.log(`  - ${students.length} élèves inscrits`);
  console.log(`  - ${gradesCount} notes`);
  console.log("\n🔑 Comptes de test:");
  console.log("  - admin@scholaris.dev / ChangeMe123! (Super Admin)");
  console.log("  - directeur@demo.scholaris.cm / Test123! (Directeur)");
  console.log("  - censeur@demo.scholaris.cm / Test123! (Censeur)");
  console.log("  - enseignant@demo.scholaris.cm / Test123! (Enseignant)");
  console.log("  - intendant@demo.scholaris.cm / Test123! (Intendant)");
  console.log("  - secretaire@demo.scholaris.cm / Test123! (Secrétaire)");
}

main()
  .catch((e) => {
    console.error("❌ Erreur lors de la population:", e);
    process.exit(1);
  })
  .finally(async () => {
    await prisma.$disconnect();
  });

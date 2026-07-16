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
  { resource: "roles", action: "create", description: "Créer un rôle personnalisé" },
  { resource: "roles", action: "read", description: "Consulter les rôles et leurs permissions" },
  { resource: "roles", action: "update", description: "Modifier un rôle (nom, permissions)" },
  { resource: "roles", action: "delete", description: "Supprimer un rôle personnalisé inutilisé" },
  { resource: "permissions", action: "read", description: "Consulter la liste des permissions disponibles" },
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
  // Module 5 — Saisie des notes et moteur de calcul
  { resource: "grades", action: "create", description: "Saisir des notes (individuelle ou collective)" },
  { resource: "grades", action: "read", description: "Consulter les notes et résultats" },
  { resource: "grades", action: "update", description: "Modifier une note avant verrouillage" },
  { resource: "grades", action: "import", description: "Importer des notes depuis Excel" },
  { resource: "grades", action: "lock", description: "Verrouiller la saisie d'une évaluation" },
  { resource: "grades", action: "unlock", description: "Rouvrir une saisie verrouillée (censeur/admin)" },
  { resource: "grades", action: "calculate", description: "Calculer moyennes et classements (période ou bilan annuel)" },
  { resource: "grades", action: "progress", description: "Consulter l'avancement de la saisie des notes" },
  { resource: "grades", action: "deliberation", description: "Délibérer (décisions, observations) sur une période" },
  { resource: "grades", action: "publish", description: "Publier les résultats aux parents/élèves" },
  // Module 6 — Bulletins et diplômes
  { resource: "bulletins", action: "generate", description: "Générer les bulletins d'une classe/période" },
  { resource: "bulletins", action: "read", description: "Consulter/télécharger un bulletin" },
  { resource: "bulletins", action: "send", description: "Envoyer les bulletins aux parents/élèves" },
  // Module 9 — Emplois du temps
  { resource: "timetables", action: "read", description: "Consulter les emplois du temps" },
  { resource: "timetables", action: "create", description: "Créer un créneau d'emploi du temps" },
  { resource: "timetables", action: "update", description: "Modifier un créneau d'emploi du temps" },
  { resource: "timetables", action: "delete", description: "Supprimer un créneau d'emploi du temps" },
  // Module 10 — Présences
  { resource: "attendance", action: "read", description: "Consulter les présences/absences" },
  { resource: "attendance", action: "create", description: "Faire l'appel / saisir une absence" },
  { resource: "attendance", action: "update", description: "Modifier une présence saisie" },
  // Module 11 — Discipline
  { resource: "discipline", action: "read", description: "Consulter les incidents disciplinaires" },
  { resource: "discipline", action: "create", description: "Déclarer un incident disciplinaire" },
  // Module 12 — Vie scolaire (clubs, activités)
  { resource: "school-life", action: "read", description: "Consulter les clubs et activités scolaires" },
  { resource: "school-life", action: "create", description: "Créer un club/une activité scolaire" },
  // Module 14 — Bibliothèque
  { resource: "library", action: "read", description: "Consulter le catalogue et les emprunts" },
  { resource: "library", action: "create", description: "Enregistrer un ouvrage ou un emprunt" },
  { resource: "library", action: "update", description: "Modifier/retourner un emprunt" },
  // Module 15 — Transport scolaire
  { resource: "transport", action: "read", description: "Consulter les circuits et abonnements transport" },
  { resource: "transport", action: "create", description: "Créer un circuit ou un abonnement transport" },
  // Module 16 — Cantine / internat
  { resource: "catering", action: "read", description: "Consulter les menus et abonnements cantine" },
  { resource: "catering", action: "create", description: "Créer un menu ou un abonnement cantine" },
  // Module 17 — Patrimoine
  { resource: "assets", action: "read", description: "Consulter le patrimoine (biens, équipements)" },
  { resource: "assets", action: "create", description: "Enregistrer un bien au patrimoine" },
  { resource: "assets", action: "update", description: "Modifier un bien du patrimoine" },
  { resource: "assets", action: "delete", description: "Retirer un bien du patrimoine" },
  // Module 18 — RH & paie
  { resource: "hr", action: "read", description: "Consulter les dossiers employés et congés" },
  { resource: "hr", action: "create", description: "Créer un employé ou une demande de congé" },
  { resource: "hr", action: "update", description: "Modifier un dossier employé ou statut de congé" },
  // Gestion des utilisateurs — assignation de rôles
  { resource: "users", action: "assign-roles", description: "Assigner un ou plusieurs rôles à un utilisateur" },
];

/**
 * Rôles métier (établissement) — matrice alignée sur le document de
 * référence officiel TRU GROUP SARL (12 rôles × 15 domaines fonctionnels,
 * ~120 fonctionnalités), traduite en permissions resource:action réellement
 * vérifiées par les contrôleurs NestJS. Quand la matrice officielle distingue
 * une étape "Valider" d'une étape "Créer" sans qu'une permission dédiée
 * existe côté backend, on retient la permission la plus proche déjà
 * existante (souvent la lecture, ou la même permission que "Créer") — ces
 * cas sont documentés dans le rapport d'audit RBAC, PAS résolus ici (un vrai
 * système d'approbation/workflow est hors scope RBAC).
 *
 * - Super Admin : toutes permissions, tous établissements (dynamique).
 * - Admin Établissement : administrateur technique d'UN tenant — config,
 *   moteur de calcul, utilisateurs (y compris suppression), structure
 *   pédagogique CRUD. Pas de validation pédagogique fine (pas grades:publish).
 * - Directeur : lecture large + décisions stratégiques, pas de saisie opérationnelle.
 * - Censeur : responsable pédagogique — calcule et délibère (la publication reste Directeur).
 * - Chef de département : coordination pédagogique d'un département (matières/UE-EC/
 *   assignations/classes/emplois du temps de son périmètre, filtrage fin côté service).
 * - Enseignant : saisie notes/présences de sa classe (filtrage fin géré côté service).
 * - Intendant : finance + patrimoine/transport/cantine + paie.
 * - Secrétaire : inscriptions, admissions (hors décision), communication, bibliothèque.
 * - Infirmier(ère) : santé scolaire — AUCUN module backend dédié n'existe encore
 *   (le modèle Prisma HealthRecord existe mais n'est exposé par aucun controller) ;
 *   rôle créé avec un socle minimal (lecture élèves + messagerie) en attendant
 *   l'implémentation d'un module santé (permissions health:* à créer alors).
 * - Bibliothécaire : catalogue/emprunts/retours (library:*). Pas de library:delete
 *   (pénalités) : aucun endpoint ne l'expose actuellement.
 * - Parent / Élève : lecture seule, scopée à leurs propres données (cf. student-scope.util.ts).
 *
 * Remplace les anciens scripts autonomes create-roles.ts / assign-roles.ts,
 * désormais intégrés ici pour qu'un seul `npx prisma db seed` produise un
 * état complet et cohérent (idempotent, upsert partout).
 */
const BUSINESS_ROLES: Array<{ name: string; description: string; permissions: string[] }> = [
  {
    name: "Admin Établissement",
    description: "Administrateur technique de l'établissement — config, moteur de calcul, utilisateurs",
    permissions: [
      // Administration (tenant, utilisateurs, années académiques, périodes, audit)
      "tenants:read", "tenants:update",
      "users:create", "users:read", "users:update", "users:delete", "users:assign-roles",
      "academic-years:create", "academic-years:read", "academic-years:update",
      "periods:read", "periods:create", "periods:update", "periods:unlock",
      "audit-logs:read",
      // Structure pédagogique — CRUD complet
      "cycles:read", "cycles:create",
      "departments:read", "departments:create", "departments:update",
      "programs:read", "programs:create", "programs:update",
      "levels:read", "levels:create", "levels:update", "levels:delete",
      "classrooms:read", "classrooms:create", "classrooms:update",
      "rooms:read", "rooms:create", "rooms:update",
      "structure:read",
      "subjects:read", "subjects:create", "subjects:update", "subjects:delete",
      "teaching-units:read", "teaching-units:create",
      "course-elements:read", "course-elements:create",
      "subject-assignments:read", "subject-assignments:create",
      // Inscriptions/élèves — total sauf décision d'admission (Directeur)
      "students:create", "students:read", "students:update", "students:import",
      "enrollments:create", "enrollments:read", "enrollments:update", "enrollments:re-enroll",
      "admissions:create", "admissions:read",
      // Notes : déverrouillage/calcul/import (pas de saisie/publication)
      "grades:read", "grades:unlock", "grades:calculate", "grades:import", "grades:progress",
      // Bulletins — total génération/envoi
      "bulletins:generate", "bulletins:read", "bulletins:send",
      // Finance — CRUD grilles tarifaires, total factures
      "fee-structures:create", "fee-structures:read",
      "invoices:create", "invoices:read",
      "finance-dashboard:read",
      // Emplois du temps — total
      "timetables:read", "timetables:create", "timetables:update", "timetables:delete",
      // Vie scolaire, bibliothèque, transport, cantine, présences, discipline — oversight
      "attendance:read", "discipline:read",
      "school-life:read", "school-life:create",
      "library:read",
      "transport:read", "transport:create",
      "catering:read", "catering:create",
      // RH & Patrimoine — CRUD complet
      "hr:read", "hr:create", "hr:update",
      "assets:read", "assets:create", "assets:update", "assets:delete",
      // Communication — CRUD templates
      "communications:read", "communication-templates:create", "communication-templates:read", "communication-templates:update",
      "internal-messages:read", "internal-messages:create",
    ],
  },
  {
    name: "Directeur",
    description: "Directeur de l'établissement — vision globale, décisions stratégiques",
    permissions: [
      // Lecture large sur l'ensemble de l'établissement
      "tenants:read", "users:read", "academic-years:read", "periods:read",
      "cycles:read", "departments:read", "programs:read", "levels:read",
      "classrooms:read", "rooms:read", "structure:read",
      "subjects:read", "teaching-units:read", "course-elements:read", "subject-assignments:read",
      "students:read", "enrollments:read", "admissions:read",
      "grades:read", "grades:progress",
      "fee-structures:read", "invoices:read", "payments:read", "finance-dashboard:read",
      "communications:read", "communication-templates:read",
      "internal-messages:read", "internal-messages:create",
      "audit-logs:read",
      "bulletins:read", "timetables:read", "attendance:read", "discipline:read",
      "school-life:read", "library:read", "transport:read", "catering:read",
      "assets:read", "hr:read",
      // Décisions stratégiques et gestion administrative
      "admissions:decide", "grades:publish",
      // Discipline : valide sanctions/conseil de discipline/décisions/récompenses
      "discipline:create",
      "users:create", "users:update", "users:assign-roles",
      "academic-years:create", "academic-years:update",
      "periods:create", "periods:update",
      "bulletins:generate", "bulletins:send",
    ],
  },
  {
    name: "Censeur",
    description: "Censeur — responsable pédagogique (calcul, délibération, vie scolaire)",
    permissions: [
      "cycles:read", "departments:read", "programs:read", "levels:read",
      "classrooms:read", "classrooms:create", "classrooms:update",
      "rooms:read", "structure:read",
      "subjects:read", "subject-assignments:read", "subject-assignments:create",
      "students:read", "enrollments:read", "enrollments:update",
      "academic-years:read", "periods:read", "periods:create", "periods:update",
      // Emplois du temps : génération, CRUD, remplacements
      "timetables:read", "timetables:create", "timetables:update", "timetables:delete",
      // Pédagogie : calcule et délibère (la publication reste au Directeur)
      "grades:read", "grades:unlock", "grades:calculate", "grades:progress", "grades:deliberation",
      // Bulletins : génération
      "bulletins:generate", "bulletins:read",
      // Vie scolaire : supervision présences (dont sorties) et traitement des signalements
      "attendance:read", "attendance:create", "attendance:update",
      "discipline:read", "discipline:create",
      // Clubs/événements, internat (registre de présence rattaché à catering:*)
      "school-life:read", "school-life:create",
      "catering:read", "catering:create",
      "internal-messages:read", "internal-messages:create",
    ],
  },
  {
    name: "Chef de département",
    description: "Coordination pédagogique d'un département/filière (matières, classes, emplois du temps)",
    permissions: [
      "cycles:read", "departments:read", "programs:read", "levels:read",
      "structure:read", "academic-years:read", "periods:read",
      // Matières / UE / EC / assignations de son département
      "subjects:read", "subjects:create", "subjects:update",
      "teaching-units:read", "teaching-units:create",
      "course-elements:read", "course-elements:create",
      "subject-assignments:read", "subject-assignments:create",
      // Classes en CRUD
      "classrooms:read", "classrooms:create", "classrooms:update",
      "rooms:read",
      "students:read", "enrollments:read",
      // Emplois du temps en CRUD
      "timetables:read", "timetables:create", "timetables:update", "timetables:delete",
      // Présences : appel/retards, pointage enseignants
      "attendance:read", "attendance:create",
      // Discipline : convoque conseil, attribue récompenses
      "discipline:read", "discipline:create",
      // Clubs/événements
      "school-life:read", "school-life:create",
      "internal-messages:read", "internal-messages:create",
    ],
  },
  {
    name: "Enseignant",
    description: "Enseignant — saisie notes/présences, consultation de sa classe",
    permissions: [
      "classrooms:read", "students:read", "enrollments:read",
      "subjects:read", "subject-assignments:read",
      "academic-years:read", "periods:read", "timetables:read",
      // Saisie notes (filtrage classe/matière géré côté service)
      "grades:create", "grades:read", "grades:update", "grades:lock", "grades:import", "grades:progress",
      // Appel et signalement
      "attendance:create", "attendance:read",
      "discipline:create",
      "internal-messages:read", "internal-messages:create",
    ],
  },
  {
    name: "Intendant",
    description: "Intendant — finance, patrimoine, transport, cantine, paie",
    permissions: [
      "fee-structures:create", "fee-structures:read",
      "invoices:create", "invoices:read",
      "payments:create", "payments:read",
      "discounts:create",
      "finance-dashboard:read",
      "assets:read", "assets:create", "assets:update", "assets:delete",
      "transport:read", "transport:create",
      "catering:read", "catering:create",
      // Paie, bulletins de paie, déclarations CNPS (portées par hr:*)
      "hr:read", "hr:create", "hr:update",
      "students:read", "enrollments:read", "classrooms:read",
      "internal-messages:read", "internal-messages:create",
    ],
  },
  {
    name: "Secrétaire",
    description: "Secrétaire — inscriptions, admissions, communication, bibliothèque, transport/cantine",
    permissions: [
      "students:create", "students:read", "students:update", "students:import",
      "enrollments:create", "enrollments:read", "enrollments:update",
      "admissions:create", "admissions:read",
      "cycles:read", "departments:read", "programs:read", "levels:read",
      "classrooms:read", "rooms:read", "structure:read", "academic-years:read",
      "communications:create", "communications:read", "communication-templates:read",
      "bulletins:generate", "bulletins:read", "bulletins:send",
      "library:read", "library:create",
      "transport:read", "transport:create",
      "catering:read", "catering:create",
      "internal-messages:read", "internal-messages:create",
    ],
  },
  {
    name: "Infirmier(ère)",
    description: "Santé scolaire — socle minimal en attendant un module santé dédié (cf. écarts documentés)",
    permissions: [
      // Aucune permission health:* n'existe : aucun controller n'expose HealthRecord.
      // Socle minimal accordé pour rester opérationnel (identifier un élève, communiquer).
      "students:read",
      "internal-messages:read", "internal-messages:create",
    ],
  },
  {
    name: "Bibliothécaire",
    description: "Bibliothèque — catalogue, emprunts, retours",
    permissions: [
      "library:read", "library:create", "library:update",
      "students:read",
      "internal-messages:read", "internal-messages:create",
    ],
  },
  {
    name: "Parent",
    description: "Parent d'élève — consultation scopée à ses propres enfants",
    permissions: [
      "grades:read", // Scopé à ses enfants (cf. student-scope.util.ts)
      "students:read", // Scopé à ses enfants
      "communications:read",
      "internal-messages:read", "internal-messages:create",
    ],
  },
  {
    name: "Élève",
    description: "Élève — consultation scopée à ses propres données",
    permissions: [
      "grades:read", // Scopé à lui-même (cf. student-scope.util.ts)
      "students:read", // Scopé à lui-même
      "internal-messages:read",
    ],
  },
];

/**
 * Seed idempotent des cycles et niveaux du système éducatif camerounais.
 * Upsert par contrainte unique (tenantId, code) — ne casse pas la structure Cycle → Level existante.
 */
async function seedAcademicStructure(tenantId: string, tenantType: TenantType) {
  const cycleDefs: Array<{ code: string; name: string; order: number; levels: Array<{ code: string; name: string; order: number }> }> = [
    {
      code: "PRIMAIRE",
      name: "Primaire",
      order: 1,
      levels: [
        { code: "SIL", name: "SIL (Section d'Initiation au Langage)", order: 1 },
        { code: "CP", name: "CP (Cours Préparatoire)", order: 2 },
        { code: "CE1", name: "CE1 (Cours Élémentaire 1)", order: 3 },
        { code: "CE2", name: "CE2 (Cours Élémentaire 2)", order: 4 },
        { code: "CM1", name: "CM1 (Cours Moyen 1)", order: 5 },
        { code: "CM2", name: "CM2 (Cours Moyen 2)", order: 6 },
      ],
    },
    {
      code: "COLLEGE",
      name: "Secondaire 1er cycle (Collège)",
      order: 2,
      levels: [
        { code: "6EME", name: "6ème", order: 1 },
        { code: "5EME", name: "5ème", order: 2 },
        { code: "4EME", name: "4ème", order: 3 },
        { code: "3EME", name: "3ème", order: 4 },
      ],
    },
    {
      code: "LYCEE",
      name: "Secondaire 2nd cycle (Lycée)",
      order: 3,
      levels: [
        { code: "2NDE", name: "2nde", order: 1 },
        { code: "1ERE", name: "1ère", order: 2 },
        { code: "TLE", name: "Terminale", order: 3 },
      ],
    },
  ];

  if (tenantType === TenantType.SUPERIEUR) {
    cycleDefs.push({
      code: "SUPERIEUR",
      name: "Supérieur",
      order: 4,
      levels: [
        { code: "L1", name: "Licence 1", order: 1 },
        { code: "L2", name: "Licence 2", order: 2 },
        { code: "L3", name: "Licence 3", order: 3 },
        { code: "M1", name: "Master 1", order: 4 },
        { code: "M2", name: "Master 2", order: 5 },
        { code: "DOCTORAT", name: "Doctorat", order: 6 },
      ],
    });
  }

  for (const cycleDef of cycleDefs) {
    const cycle = await prisma.cycle.upsert({
      where: { tenantId_code: { tenantId, code: cycleDef.code } },
      update: { name: cycleDef.name, order: cycleDef.order },
      create: { tenantId, code: cycleDef.code, name: cycleDef.name, order: cycleDef.order },
    });

    for (const levelDef of cycleDef.levels) {
      await prisma.level.upsert({
        where: { tenantId_code: { tenantId, code: levelDef.code } },
        update: { name: levelDef.name, order: levelDef.order, cycleId: cycle.id },
        create: {
          tenantId,
          code: levelDef.code,
          name: levelDef.name,
          order: levelDef.order,
          cycleId: cycle.id,
        },
      });
    }
  }
}

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

  console.log("→ Seed des cycles et niveaux scolaires (système camerounais)…");
  await seedAcademicStructure(tenant.id, tenant.type);

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

  console.log("→ Seed du rôle SUPER_ADMIN (système)…");
  const superAdminRole = await prisma.role.upsert({
    where: { tenantId_name: { tenantId: tenant.id, name: "SUPER_ADMIN" } },
    update: {},
    create: {
      tenantId: tenant.id,
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

  console.log("→ Seed des rôles métier (Directeur, Censeur, Enseignant, Intendant, Secrétaire, Parent, Élève)…");
  const permissionMap = new Map(permissions.map((p) => [`${p.resource}:${p.action}`, p.id]));
  for (const roleConfig of BUSINESS_ROLES) {
    const role = await prisma.role.upsert({
      where: { tenantId_name: { tenantId: tenant.id, name: roleConfig.name } },
      update: { description: roleConfig.description },
      create: {
        tenantId: tenant.id,
        name: roleConfig.name,
        description: roleConfig.description,
        isSystem: false,
      },
    });

    let assignedCount = 0;
    for (const permKey of roleConfig.permissions) {
      const permissionId = permissionMap.get(permKey);
      if (!permissionId) {
        console.warn(`  ⚠ Permission inconnue pour le rôle "${roleConfig.name}" : ${permKey}`);
        continue;
      }
      await prisma.rolePermission.upsert({
        where: { roleId_permissionId: { roleId: role.id, permissionId } },
        update: {},
        create: { roleId: role.id, permissionId },
      });
      assignedCount++;
    }
    console.log(`  ✔ ${roleConfig.name} : ${assignedCount} permission(s)`);
  }

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

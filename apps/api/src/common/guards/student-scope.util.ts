import { ForbiddenException } from "@nestjs/common";
import { PERMISSIONS } from "@scholaris/shared";
import { PrismaService } from "../../prisma/prisma.service";
import type { AuthenticatedUser } from "../../modules/auth/jwt-payload.interface";

/**
 * Permissions "personnel" : leur présence indique un rôle de gestion (staff)
 * qui a un accès large aux dossiers élèves, par opposition aux rôles Parent
 * et Élève dont l'accès en lecture doit être strictement limité à leurs
 * propres données (anti-IDOR — cf. audit RBAC modules 9-18).
 */
const STAFF_STUDENT_ACCESS_PERMISSIONS: string[] = [
  PERMISSIONS.STUDENTS_CREATE,
  PERMISSIONS.STUDENTS_UPDATE,
  PERMISSIONS.STUDENTS_IMPORT,
  PERMISSIONS.ENROLLMENTS_CREATE,
  PERMISSIONS.GRADES_CREATE,
  PERMISSIONS.GRADES_UPDATE,
  PERMISSIONS.GRADES_CALCULATE,
  PERMISSIONS.GRADES_DELIBERATION,
  PERMISSIONS.GRADES_PUBLISH,
  PERMISSIONS.GRADES_PROGRESS,
];

/**
 * Vérifie que l'utilisateur connecté a le droit de consulter les données de
 * `studentId`. Le personnel (toute permission de gestion élèves/notes) a un
 * accès large. Un compte Parent ou Élève sans permission de gestion doit être
 * rattaché à l'élève via `Student.userId` (lui-même) ou `Parent.userId` +
 * `StudentParent` (son enfant) — sinon 403.
 *
 * NB : ce scoping dépend des colonnes `Student.userId` / `Parent.userId`
 * (ajoutées au schéma pour corriger cette faille). Tant que ces comptes ne
 * sont pas explicitement liés lors de la création de l'utilisateur, un
 * Parent/Élève sans lien renseigné n'aura accès à AUCUN élève (fail-closed).
 */
export async function assertStudentAccess(
  prisma: PrismaService,
  user: AuthenticatedUser,
  studentId: string,
): Promise<void> {
  const isStaff = STAFF_STUDENT_ACCESS_PERMISSIONS.some((permission) => user.permissions.includes(permission));
  if (isStaff) {
    return;
  }

  const student = await prisma.student.findFirst({
    where: { id: studentId, tenantId: user.tenantId },
    select: {
      userId: true,
      parents: { select: { parent: { select: { userId: true } } } },
    },
  });

  if (!student) {
    return; // 404 levé par l'appelant, pas la peine de dupliquer ici
  }

  const isSelf = student.userId !== null && student.userId === user.userId;
  const isParentOf = student.parents.some((sp) => sp.parent.userId !== null && sp.parent.userId === user.userId);

  if (!isSelf && !isParentOf) {
    throw new ForbiddenException("Accès refusé : cet élève n'est pas rattaché à votre compte");
  }
}

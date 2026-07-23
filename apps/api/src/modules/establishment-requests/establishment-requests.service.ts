import { BadRequestException, ConflictException, Injectable, NotFoundException } from "@nestjs/common";
import { EstablishmentRequest, Prisma, TenantStatus } from "@scholaris/prisma";
import { randomBytes } from "crypto";
import * as bcrypt from "bcrypt";
import { PrismaService } from "../../prisma/prisma.service";
import { AuditService } from "../audit/audit.service";
import { SmtpMailService } from "../../common/mail/smtp-mail.service";
import { CreateEstablishmentRequestDto } from "./dto/create-establishment-request.dto";

// Config par défaut du moteur de calcul (secondaire général camerounais).
const DEFAULT_CONFIG = {
  evaluationType: "SEQUENTIAL",
  sequenceWeights: [1, 1],
  trimesterWeights: [1, 1, 1],
  roundingRule: "HUNDREDTH",
  absenceRule: "ZERO",
  mentionThresholds: [
    { code: "EXCELLENT", label: "Excellent", minAverage: 18 },
    { code: "TRES_BIEN", label: "Très Bien", minAverage: 16 },
    { code: "BIEN", label: "Bien", minAverage: 14 },
    { code: "ASSEZ_BIEN", label: "Assez Bien", minAverage: 12 },
    { code: "PASSABLE", label: "Passable", minAverage: 10 },
    { code: "INSUFFISANT", label: "Insuffisant", minAverage: 0 },
  ],
};

function generatePassword(): string {
  // 10 caractères base64url + un suffixe garantissant maj/min/chiffre/spécial.
  return randomBytes(9).toString("base64").replace(/[+/=]/g, "").slice(0, 10) + "A9!";
}

@Injectable()
export class EstablishmentRequestsService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly audit: AuditService,
    private readonly mail: SmtpMailService,
  ) {}

  /** Dépôt public (directeur, sans authentification) : crée une demande PENDING. */
  async createPublic(dto: CreateEstablishmentRequestDto): Promise<{ id: string } | { accepted: true }> {
    if (dto.website) {
      // Honeypot rempli : bot probable, on n'écrit rien.
      return { accepted: true };
    }
    const request = await this.prisma.establishmentRequest.create({
      data: {
        name: dto.name,
        code: dto.code,
        type: dto.type,
        status: dto.status ?? TenantStatus.PUBLIC,
        address: dto.address ?? null,
        phone: dto.phone ?? null,
        email: dto.email ?? null,
        directorFirstName: dto.directorFirstName,
        directorLastName: dto.directorLastName,
        directorEmail: dto.directorEmail,
        directorPhone: dto.directorPhone ?? null,
      },
    });
    return { id: request.id };
  }

  async findAll(status?: string): Promise<EstablishmentRequest[]> {
    return this.prisma.establishmentRequest.findMany({
      where: status ? { requestStatus: status as any } : undefined,
      orderBy: [{ requestStatus: "asc" }, { createdAt: "desc" }],
    });
  }

  private async findOneOrThrow(id: string): Promise<EstablishmentRequest> {
    const req = await this.prisma.establishmentRequest.findFirst({ where: { id } });
    if (!req) throw new NotFoundException("Demande d'établissement introuvable");
    return req;
  }

  /**
   * Validation par le Super Admin : crée en transaction le Tenant, le rôle
   * "Admin Établissement" (avec ses permissions), le compte directeur lié à ce
   * rôle, et une année académique par défaut. Un mot de passe est généré et
   * envoyé au directeur par email.
   */
  async approve(id: string): Promise<{ tenantId: string; directorEmail: string; emailSent: boolean }> {
    const req = await this.findOneOrThrow(id);
    if (req.requestStatus !== "PENDING") {
      throw new BadRequestException("Cette demande a déjà été traitée");
    }

    // Unicité du code établissement
    const codeExists = await this.prisma.tenant.findFirst({ where: { code: req.code } });
    if (codeExists) {
      throw new ConflictException(`Un établissement avec le code "${req.code}" existe déjà`);
    }

    // Permissions du rôle "Admin Établissement" : on réplique celles d'un rôle
    // Admin Établissement déjà existant (matrice officielle) ; à défaut, toutes
    // les permissions SAUF tenants:create (réservée au Super Admin plateforme).
    const templateRole = await this.prisma.role.findFirst({
      where: { name: "Admin Établissement" },
      include: { rolePermissions: true },
    });
    let permissionIds: string[];
    if (templateRole && templateRole.rolePermissions.length > 0) {
      permissionIds = templateRole.rolePermissions.map((rp) => rp.permissionId);
    } else {
      const all = await this.prisma.permission.findMany({
        where: { NOT: { AND: [{ resource: "tenants" }, { action: "create" }] } },
      });
      permissionIds = all.map((p) => p.id);
    }

    const password = generatePassword();
    const passwordHash = await bcrypt.hash(password, 10);
    const currentYear = new Date().getFullYear();

    const tenantId = await this.prisma.$transaction(async (tx) => {
      const tenant = await tx.tenant.create({
        data: {
          name: req.name,
          code: req.code,
          type: req.type,
          status: req.status,
          address: req.address,
          phone: req.phone,
          email: req.email,
          configJson: DEFAULT_CONFIG as unknown as Prisma.InputJsonValue,
        },
      });

      const role = await tx.role.create({
        data: { tenantId: tenant.id, name: "Admin Établissement", description: "Administrateur de l'établissement", isSystem: false },
      });
      if (permissionIds.length > 0) {
        await tx.rolePermission.createMany({
          data: permissionIds.map((permissionId) => ({ roleId: role.id, permissionId })),
        });
      }

      const director = await tx.user.create({
        data: {
          tenantId: tenant.id,
          email: req.directorEmail,
          passwordHash,
          firstName: req.directorFirstName,
          lastName: req.directorLastName,
          phone: req.directorPhone,
          status: "ACTIVE",
        },
      });
      await tx.userRole.create({ data: { userId: director.id, roleId: role.id } });

      await tx.academicYear.create({
        data: {
          tenantId: tenant.id,
          label: `${currentYear}-${currentYear + 1}`,
          startDate: new Date(`${currentYear}-09-01`),
          endDate: new Date(`${currentYear + 1}-07-31`),
          status: "ACTIVE",
        },
      });

      await tx.establishmentRequest.update({
        where: { id: req.id },
        data: { requestStatus: "APPROVED", createdTenantId: tenant.id },
      });

      return tenant.id;
    });

    await this.audit.log({
      action: "approve",
      resource: "establishment-requests",
      resourceId: req.id,
      newValue: { tenantId, code: req.code },
    });

    const emailSent = await this.mail.send({
      to: req.directorEmail,
      subject: `SCHOLARIS — Votre établissement « ${req.name} » a été validé`,
      html: `
        <p>Bonjour ${req.directorFirstName} ${req.directorLastName},</p>
        <p>Votre demande de création de l'établissement <strong>${req.name}</strong>
        (code <strong>${req.code}</strong>) a été validée.</p>
        <p>Voici vos identifiants d'accès administrateur d'établissement :</p>
        <ul>
          <li><strong>Email</strong> : ${req.directorEmail}</li>
          <li><strong>Mot de passe temporaire</strong> : ${password}</li>
        </ul>
        <p>Connectez-vous puis <strong>changez ce mot de passe immédiatement</strong>.
        Vous pourrez ensuite configurer votre établissement, définir les rôles et créer les comptes de votre équipe.</p>
        <p>— L'équipe SCHOLARIS</p>
      `,
      text:
        `Bonjour ${req.directorFirstName} ${req.directorLastName},\n\n` +
        `Votre établissement "${req.name}" (${req.code}) a été validé.\n` +
        `Email : ${req.directorEmail}\nMot de passe temporaire : ${password}\n\n` +
        `Connectez-vous et changez ce mot de passe immédiatement.\n— SCHOLARIS`,
    });

    return { tenantId, directorEmail: req.directorEmail, emailSent };
  }

  async reject(id: string, reason?: string): Promise<EstablishmentRequest> {
    const req = await this.findOneOrThrow(id);
    if (req.requestStatus !== "PENDING") {
      throw new BadRequestException("Cette demande a déjà été traitée");
    }
    const updated = await this.prisma.establishmentRequest.update({
      where: { id },
      data: { requestStatus: "REJECTED", rejectionReason: reason ?? null },
    });

    await this.mail.send({
      to: req.directorEmail,
      subject: `SCHOLARIS — Votre demande d'établissement « ${req.name} »`,
      html: `
        <p>Bonjour ${req.directorFirstName} ${req.directorLastName},</p>
        <p>Votre demande de création de l'établissement <strong>${req.name}</strong> n'a pas pu être validée.</p>
        ${reason ? `<p>Motif : ${reason}</p>` : ""}
        <p>Vous pouvez nous contacter pour plus d'informations.</p>
        <p>— L'équipe SCHOLARIS</p>
      `,
    });

    return updated;
  }
}

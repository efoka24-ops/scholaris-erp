import { BadRequestException, ConflictException, Injectable, NotFoundException } from "@nestjs/common";
import { ConfigService } from "@nestjs/config";
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

/**
 * Construit l'email d'identifiants du directeur : accès au tableau de bord,
 * mini guide de démarrage, et consigne de support (écrire au Super Admin avec
 * une capture d'écran en cas de difficulté).
 */
function buildCredentialsEmail(params: {
  firstName: string;
  lastName: string;
  name: string;
  code: string;
  email: string;
  password: string;
  loginUrl: string;
  supportEmail: string;
}): { subject: string; html: string; text: string } {
  const { firstName, lastName, name, code, email, password, loginUrl, supportEmail } = params;
  const subject = `SCHOLARIS — Accès à votre tableau de bord « ${name} »`;
  const html = `
    <p>Bonjour ${firstName} ${lastName},</p>
    <p>Votre établissement <strong>${name}</strong> (code <strong>${code}</strong>) est activé sur SCHOLARIS.
    Voici vos identifiants d'administrateur :</p>
    <ul>
      <li><strong>Adresse de connexion</strong> : <a href="${loginUrl}">${loginUrl}</a></li>
      <li><strong>Email</strong> : ${email}</li>
      <li><strong>Mot de passe temporaire</strong> : ${password}</li>
    </ul>
    <p><strong>À la première connexion, changez immédiatement ce mot de passe</strong>
    (menu Paramètres → Mon profil → Changer le mot de passe).</p>
    <h3>Guide de démarrage rapide</h3>
    <ol>
      <li>Connectez-vous et changez votre mot de passe.</li>
      <li>Configurez votre établissement : moteur de calcul, années académiques et périodes.</li>
      <li>Créez la structure pédagogique : cycles, niveaux, classes et matières (avec coefficients).</li>
      <li>Ajoutez vos enseignants et le personnel, puis attribuez les rôles.</li>
      <li>Importez vos élèves (menu Élèves → Importer, template Excel fourni).</li>
      <li>Saisissez les notes, générez les bulletins et gérez les paiements.</li>
    </ol>
    <p>En cas de difficulté, écrivez au Super Administrateur à
    <a href="mailto:${supportEmail}">${supportEmail}</a> en <strong>joignant une capture d'écran</strong>
    du problème rencontré (page concernée et message affiché).</p>
    <p>— L'équipe SCHOLARIS</p>`;
  const text =
    `Bonjour ${firstName} ${lastName},\n\n` +
    `Votre établissement "${name}" (${code}) est activé sur SCHOLARIS.\n` +
    `Connexion : ${loginUrl}\nEmail : ${email}\nMot de passe temporaire : ${password}\n\n` +
    `Changez ce mot de passe dès la première connexion (Paramètres → Mon profil).\n\n` +
    `Guide rapide : 1) changez le mot de passe 2) configurez l'établissement (moteur de calcul, années, périodes) ` +
    `3) créez cycles/niveaux/classes/matières 4) ajoutez le personnel et les rôles 5) importez les élèves ` +
    `6) saisissez les notes, générez les bulletins, gérez les paiements.\n\n` +
    `En cas de difficulté, écrivez au Super Admin à ${supportEmail} en joignant une capture d'écran.\n— SCHOLARIS`;
  return { subject, html, text };
}

@Injectable()
export class EstablishmentRequestsService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly audit: AuditService,
    private readonly mail: SmtpMailService,
    private readonly config: ConfigService,
  ) {}

  /** Adresse de connexion + email de support pour les emails d'identifiants. */
  private mailContext(): { loginUrl: string; supportEmail: string } {
    return {
      loginUrl: `${this.config.get<string>("APP_PUBLIC_URL") ?? "https://scholaris.cm"}/login`,
      supportEmail:
        this.config.get<string>("SUPPORT_EMAIL") ??
        this.config.get<string>("SMTP_FROM_EMAIL") ??
        this.config.get<string>("SMTP_USER") ??
        "support@scholaris.cm",
    };
  }

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
  async approve(
    id: string,
  ): Promise<{ tenantId: string; directorEmail: string; emailSent: boolean; emailError?: string; temporaryPassword?: string }> {
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

    const { loginUrl, supportEmail } = this.mailContext();
    const emailResult = await this.mail.sendDetailed({
      to: req.directorEmail,
      ...buildCredentialsEmail({
        firstName: req.directorFirstName,
        lastName: req.directorLastName,
        name: req.name,
        code: req.code,
        email: req.directorEmail,
        password,
        loginUrl,
        supportEmail,
      }),
    });

    // Si l'email n'a pas pu partir, on renvoie le mot de passe temporaire pour que
    // le Super Admin puisse le communiquer manuellement au directeur, + la raison.
    return {
      tenantId,
      directorEmail: req.directorEmail,
      emailSent: emailResult.sent,
      ...(emailResult.sent ? {} : { emailError: emailResult.reason, temporaryPassword: password }),
    };
  }

  /**
   * (Re)génère un mot de passe temporaire pour le directeur d'une demande DÉJÀ
   * validée et lui renvoie ses identifiants par email (guide + support inclus).
   * Utile lorsque l'email initial n'est pas parti (le mot de passe d'origine
   * n'est pas récupérable car stocké en hash). La mise à jour du compte se fait
   * en SQL paramétré pour cibler le tenant créé (le compte directeur appartient
   * à un autre établissement que celui du Super Admin).
   */
  async resendCredentials(
    id: string,
  ): Promise<{ directorEmail: string; emailSent: boolean; emailError?: string; temporaryPassword?: string }> {
    const req = await this.findOneOrThrow(id);
    if (req.requestStatus !== "APPROVED" || !req.createdTenantId) {
      throw new BadRequestException("La demande doit être validée avant de renvoyer les identifiants");
    }

    const password = generatePassword();
    const passwordHash = await bcrypt.hash(password, 10);
    const affected = await this.prisma.$executeRaw`
      UPDATE users SET password_hash = ${passwordHash}, updated_at = NOW()
      WHERE tenant_id = ${req.createdTenantId} AND email = ${req.directorEmail} AND deleted_at IS NULL`;
    if (affected === 0) {
      throw new NotFoundException("Compte directeur introuvable pour cet établissement");
    }

    await this.audit.log({
      action: "resend-credentials",
      resource: "establishment-requests",
      resourceId: req.id,
      newValue: { tenantId: req.createdTenantId, directorEmail: req.directorEmail },
    });

    const { loginUrl, supportEmail } = this.mailContext();
    const emailResult = await this.mail.sendDetailed({
      to: req.directorEmail,
      ...buildCredentialsEmail({
        firstName: req.directorFirstName,
        lastName: req.directorLastName,
        name: req.name,
        code: req.code,
        email: req.directorEmail,
        password,
        loginUrl,
        supportEmail,
      }),
    });

    return {
      directorEmail: req.directorEmail,
      emailSent: emailResult.sent,
      ...(emailResult.sent ? {} : { emailError: emailResult.reason, temporaryPassword: password }),
    };
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

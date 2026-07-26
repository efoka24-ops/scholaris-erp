import { Body, Controller, Get, Post } from "@nestjs/common";
import { ApiBearerAuth, ApiOperation, ApiTags } from "@nestjs/swagger";
import { RequirePermissions } from "../decorators/require-permissions.decorator";
import { SmtpMailService } from "./smtp-mail.service";

/**
 * Diagnostic de la configuration email. Réservé aux détenteurs de
 * `tenants:create` (permission propre au Super Admin plateforme).
 */
@ApiTags("mail")
@ApiBearerAuth()
@Controller("mail")
export class MailController {
  constructor(private readonly mail: SmtpMailService) {}

  @Get("status")
  @RequirePermissions("tenants:create")
  @ApiOperation({ summary: "État de la configuration SMTP (sans mot de passe)" })
  status() {
    return { configured: this.mail.isConfigured(), config: this.mail.describeConfig() };
  }

  @Post("verify")
  @RequirePermissions("tenants:create")
  @ApiOperation({ summary: "Teste la connexion SMTP (nodemailer verify) et renvoie l'erreur exacte" })
  verify() {
    return this.mail.verifyConnection();
  }

  @Post("test")
  @RequirePermissions("tenants:create")
  @ApiOperation({ summary: "Envoie un email de test à l'adresse fournie et renvoie le résultat détaillé" })
  test(@Body() body: { to: string }) {
    return this.mail.sendDetailed({
      to: body.to,
      subject: "SCHOLARIS — Email de test SMTP",
      html: "<p>Ceci est un email de test envoyé depuis SCHOLARIS pour valider la configuration SMTP.</p>",
      text: "Ceci est un email de test SCHOLARIS pour valider la configuration SMTP.",
    });
  }
}

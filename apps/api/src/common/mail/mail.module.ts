import { Global, Module } from "@nestjs/common";
import { SmtpMailService } from "./smtp-mail.service";

@Global()
@Module({
  providers: [SmtpMailService],
  exports: [SmtpMailService],
})
export class MailModule {}

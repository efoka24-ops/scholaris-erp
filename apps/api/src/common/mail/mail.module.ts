import { Global, Module } from "@nestjs/common";
import { SmtpMailService } from "./smtp-mail.service";
import { MailController } from "./mail.controller";

@Global()
@Module({
  controllers: [MailController],
  providers: [SmtpMailService],
  exports: [SmtpMailService],
})
export class MailModule {}

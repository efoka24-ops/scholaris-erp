import { Module } from "@nestjs/common";
import { CommunicationTemplatesController } from "./communication-templates.controller";
import { CommunicationTemplatesService } from "./communication-templates.service";
import { CommunicationsController } from "./communications.controller";
import { CommunicationsService } from "./communications.service";
import { ChannelPreferenceController } from "./channel-preference.controller";
import { ChannelPreferenceService } from "./channel-preference.service";
import { InternalMessagesController } from "./internal-messages.controller";
import { InternalMessagesService } from "./internal-messages.service";
import { WhatsappWebhookController } from "./whatsapp-webhook.controller";
import { WhatsappIntentResolverService } from "./chatbot/whatsapp-intent-resolver.service";
import { EmailSenderService } from "./senders/email-sender.service";
import { SmsSenderService } from "./senders/sms-sender.service";
import { WhatsappSenderService } from "./senders/whatsapp-sender.service";
import { PushSenderService } from "./senders/push-sender.service";
import { InternalMessageService } from "./senders/internal-message.service";

@Module({
  controllers: [
    CommunicationTemplatesController,
    CommunicationsController,
    ChannelPreferenceController,
    InternalMessagesController,
    WhatsappWebhookController,
  ],
  providers: [
    CommunicationTemplatesService,
    CommunicationsService,
    ChannelPreferenceService,
    InternalMessagesService,
    WhatsappIntentResolverService,
    EmailSenderService,
    SmsSenderService,
    WhatsappSenderService,
    PushSenderService,
    InternalMessageService,
  ],
  exports: [CommunicationsService],
})
export class CommunicationsModule {}

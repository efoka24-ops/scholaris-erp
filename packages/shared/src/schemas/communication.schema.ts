import { z } from "zod";

// Canaux supportés (§23.1 du guide) — doit rester synchronisé avec l'enum Prisma `Channel`.
export const channelEnum = z.enum(["EMAIL", "SMS", "WHATSAPP", "PUSH", "INTERNAL"]);
export type ChannelInput = z.infer<typeof channelEnum>;

export const localeEnum = z.enum(["fr", "en"]);
export type LocaleInput = z.infer<typeof localeEnum>;

export const createCommunicationTemplateSchema = z.object({
  code: z.string().min(1, "Le code est requis"),
  name: z.string().min(1, "Le nom est requis"),
  channel: channelEnum,
  subjectFr: z.string().optional(),
  subjectEn: z.string().optional(),
  bodyFr: z.string().min(1, "Le corps (FR) est requis"),
  bodyEn: z.string().optional(),
});
export type CreateCommunicationTemplateInput = z.infer<typeof createCommunicationTemplateSchema>;

export const updateCommunicationTemplateSchema = createCommunicationTemplateSchema.partial();
export type UpdateCommunicationTemplateInput = z.infer<typeof updateCommunicationTemplateSchema>;

// Envoi d'un message : soit basé sur un template (templateId + variables), soit ad-hoc
// (channel + body fournis directement). Voir CommunicationsService.render() côté API.
export const sendMessageSchema = z
  .object({
    templateId: z.string().uuid().optional(),
    channel: channelEnum.optional(),
    recipientUserId: z.string().uuid("Destinataire invalide"),
    subject: z.string().optional(),
    body: z.string().optional(),
    locale: localeEnum.default("fr"),
    variables: z.record(z.string()).optional(),
  })
  .refine((data) => Boolean(data.templateId) || Boolean(data.channel && data.body), {
    message: "Fournir soit un templateId, soit un channel + body pour un message ad-hoc",
    path: ["templateId"],
  });
export type SendMessageInput = z.infer<typeof sendMessageSchema>;

export const channelPreferenceSchema = z.object({
  preferredChannel: channelEnum,
  fallbackChannel: channelEnum.optional().nullable(),
});
export type ChannelPreferenceInput = z.infer<typeof channelPreferenceSchema>;

export const createInternalMessageSchema = z.object({
  recipientUserId: z.string().uuid("Destinataire invalide"),
  body: z.string().min(1, "Le message ne peut pas être vide"),
});
export type CreateInternalMessageInput = z.infer<typeof createInternalMessageSchema>;

// Messagerie interne (C-113) : boîte de réception, fil, nouvelle conversation, signalement, modération, support SCHOLARIS
// côté établissement, et boîte du support côté plateforme (/platform/support/**, jamais d'en-tête X-Establishment-Id).
// Contrats : specs/001-socle-plateforme/contracts/openapi.d/messaging.yaml et messaging-support.yaml.
import { http } from './http';

export type ConversationKind = 'teacher_parent' | 'direction_parent' | 'support';
export type SupportStatus = 'open' | 'pending' | 'resolved';
export type ReportStatus = 'open' | 'actioned' | 'dismissed';

export const SUPPORT_STATUSES: SupportStatus[] = ['open', 'pending', 'resolved'];
export const REPORT_STATUSES: ReportStatus[] = ['open', 'actioned', 'dismissed'];
/** Rôles qui détiennent messaging.chat, messaging.moderate et messaging.support côté serveur (migrations 202610089060 et 202611060201). */
export const CHAT_ROLES = ['super_admin', 'directeur', 'enseignant', 'parent'];
export const MODERATOR_ROLES = ['super_admin', 'directeur'];
export const SUPPORT_ROLES = ['super_admin', 'directeur'];
/** Interrogation légère : toutes les 30 s, seulement quand l'écran est ouvert et visible. */
export const POLL_MS = 30_000;

export interface Participant {
  person_id: string;
  name: string | null;
  chat_role: string;
}

export interface Conversation {
  id: string;
  subject: string;
  kind: ConversationKind;
  learner_id: string | null;
  status: 'open' | 'archived';
  support_status: SupportStatus | null;
  created_by: string;
  last_message_at: string | null;
  archived_at: string | null;
  created_at: string;
  updated_at: string;
  unread_count?: number;
  participants?: Participant[];
}

export interface Attachment {
  document_id: string;
  number: string;
  type: string;
}

export interface ChatMessage {
  id: string;
  conversation_id: string;
  sender_id: string | null;
  sender_side: 'client' | 'platform';
  body: string | null;
  attachments: Attachment[];
  moderation_status: 'visible' | 'hidden';
  created_at: string;
  moderated_by?: string | null;
  moderated_at?: string | null;
  moderation_reason?: string | null;
}

export interface Contact {
  person_id: string;
  name: string;
  chat_role: string;
  kind: 'teacher_parent' | 'direction_parent';
}

export interface LearnerChoice {
  learner_id: string;
  name: string;
}

export interface ConversationReport {
  id: string;
  conversation_id: string;
  message_id: string;
  reported_by: string;
  reason: string;
  status: ReportStatus;
  resolved_by: string | null;
  resolved_at: string | null;
  created_at: string;
}

export interface PageOf<T> {
  items: T[];
  nextCursor: string | null;
}

export interface NewConversation {
  subject: string;
  kind: 'teacher_parent' | 'direction_parent';
  learner_id: string;
  participant_ids: string[];
  body: string;
}

export const fetchConversations = (filters: { status?: string | null; kind?: string | null } = {}, cursor: string | null = null): Promise<PageOf<Conversation>> =>
  http.page<Conversation>('/conversations', { 'filter[status]': filters.status || null, 'filter[kind]': filters.kind || null, cursor, per_page: 30 });

export const fetchConversation = async (id: string): Promise<Conversation> =>
  (await http.get<{ data: Conversation }>(`/conversations/${encodeURIComponent(id)}`)).data;

export const fetchMessages = (id: string, cursor: string | null = null): Promise<PageOf<ChatMessage>> =>
  http.page<ChatMessage>(`/conversations/${encodeURIComponent(id)}/messages`, { cursor, per_page: 50 });

export const postMessage = async (id: string, body: string): Promise<ChatMessage> =>
  (await http.post<{ data: ChatMessage }>(`/conversations/${encodeURIComponent(id)}/messages`, { body })).data;

export const createConversation = async (input: NewConversation): Promise<Conversation> =>
  (await http.post<{ data: Conversation }>('/conversations', input)).data;

export const createSupportConversation = async (subject: string, body: string): Promise<Conversation> =>
  (await http.post<{ data: Conversation }>('/support-conversations', { subject, body })).data;

export const archiveConversation = async (id: string): Promise<Conversation> =>
  (await http.post<{ data: Conversation }>(`/conversations/${encodeURIComponent(id)}/archive`)).data;

export const reportMessage = async (conversationId: string, messageId: string, reason: string): Promise<ConversationReport> =>
  (await http.post<{ data: ConversationReport }>(`/conversations/${encodeURIComponent(conversationId)}/messages/${encodeURIComponent(messageId)}/report`, { reason })).data;

export const fetchUnread = async (): Promise<number> => (await http.get<{ data: { unread: number } }>('/conversation-unread')).data.unread;

export const fetchLearners = async (): Promise<LearnerChoice[]> => (await http.get<{ data: LearnerChoice[] }>('/conversation-learners')).data;

export const fetchContacts = async (learnerId: string): Promise<Contact[]> =>
  (await http.get<{ data: Contact[] }>('/conversation-contacts', { learner_id: learnerId })).data;

// Modération (direction : messaging.moderate). Chaque lecture d'une conversation par un modérateur non participant est journalisée.
export const fetchReports = (status: ReportStatus | null, cursor: string | null = null): Promise<PageOf<ConversationReport>> =>
  http.page<ConversationReport>('/conversation-reports', { 'filter[status]': status, cursor, per_page: 50 });

export const hideMessage = async (conversationId: string, messageId: string, reason: string): Promise<ChatMessage> =>
  (await http.post<{ data: ChatMessage }>(`/conversations/${encodeURIComponent(conversationId)}/messages/${encodeURIComponent(messageId)}/hide`, { reason })).data;

export const restoreMessage = async (conversationId: string, messageId: string): Promise<ChatMessage> =>
  (await http.post<{ data: ChatMessage }>(`/conversations/${encodeURIComponent(conversationId)}/messages/${encodeURIComponent(messageId)}/restore`)).data;

export const dismissReport = async (id: string): Promise<ConversationReport> =>
  (await http.post<{ data: ConversationReport }>(`/conversation-reports/${encodeURIComponent(id)}/dismiss`)).data;

// Boîte du support (plateforme).
const NO_ESTABLISHMENT = { establishmentId: false as const };

export interface SupportConversation {
  id: string;
  tenant_id: string;
  tenant_name: string | null;
  establishment_id: string;
  establishment_name: string | null;
  subject: string;
  status: 'open' | 'archived';
  support_status: SupportStatus;
  assignee: string | null;
  opened_by: 'client' | 'platform';
  last_message_at: string | null;
  created_at: string;
  updated_at: string;
  unread_count?: number;
  participants?: Participant[];
}

export interface SupportMessage {
  id: string;
  conversation_id: string;
  sender_id: string;
  sender_side: 'client' | 'platform';
  body: string | null;
  moderation_status: 'visible' | 'hidden';
  created_at: string;
}

export interface SupportAgent {
  user_id: string;
  name: string;
  role: string;
}

export const fetchSupportInbox = (filters: { status?: string | null; tenantId?: string | null; assignee?: string | null }, cursor: string | null = null): Promise<PageOf<SupportConversation>> =>
  http.page<SupportConversation>(
    '/platform/support/conversations',
    { 'filter[status]': filters.status || null, 'filter[tenant_id]': filters.tenantId || null, 'filter[assignee]': filters.assignee || null, cursor, per_page: 50 },
    NO_ESTABLISHMENT,
  );

export const fetchSupportConversation = async (id: string): Promise<SupportConversation> =>
  (await http.get<{ data: SupportConversation }>(`/platform/support/conversations/${encodeURIComponent(id)}`, undefined, NO_ESTABLISHMENT)).data;

export const fetchSupportMessages = (id: string, cursor: string | null = null): Promise<PageOf<SupportMessage>> =>
  http.page<SupportMessage>(`/platform/support/conversations/${encodeURIComponent(id)}/messages`, { cursor, per_page: 50 }, NO_ESTABLISHMENT);

export const replySupport = async (id: string, body: string): Promise<SupportMessage> =>
  (await http.post<{ data: SupportMessage }>(`/platform/support/conversations/${encodeURIComponent(id)}/messages`, { body }, NO_ESTABLISHMENT)).data;

export const setSupportStatus = async (id: string, status: SupportStatus): Promise<SupportConversation> =>
  (await http.post<{ data: SupportConversation }>(`/platform/support/conversations/${encodeURIComponent(id)}/status`, { status }, NO_ESTABLISHMENT)).data;

export const assignSupport = async (id: string, userId: string | null): Promise<SupportConversation> =>
  (await http.post<{ data: SupportConversation }>(`/platform/support/conversations/${encodeURIComponent(id)}/assign`, { user_id: userId }, NO_ESTABLISHMENT)).data;

export const fetchSupportAgents = async (): Promise<SupportAgent[]> =>
  (await http.get<{ data: SupportAgent[] }>('/platform/support/agents', undefined, NO_ESTABLISHMENT)).data;

export const openSupportToClient = async (input: { tenant_id: string; establishment_id: string; subject: string; body: string }): Promise<SupportConversation> =>
  (await http.post<{ data: SupportConversation }>('/platform/support/conversations', input, NO_ESTABLISHMENT)).data;

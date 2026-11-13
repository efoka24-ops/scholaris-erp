import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import InboxView from '@/views/messaging/InboxView.vue';
import ThreadView from '@/views/messaging/ThreadView.vue';
import NewConversationView from '@/views/messaging/NewConversationView.vue';
import ModerationView from '@/views/messaging/ModerationView.vue';
import PlatformSupportView from '@/views/platform/PlatformSupportView.vue';
import PlatformSupportThreadView from '@/views/platform/PlatformSupportThreadView.vue';
import { linkify } from '@/views/messaging/linkify';
import { routes } from '@/router/routes';
import { flushPromises, freshEnvironment, mockApi, mountView, signIn, teacherMe, tile } from './helpers';

beforeEach(freshEnvironment);
afterEach(() => vi.useRealTimers());

const page = <T,>(data: T[], next: string | null = null) => ({ data, links: { next: null }, meta: { next_cursor: next } });
const conversation = (over: Record<string, unknown> = {}) => ({
  id: 'C1', subject: 'Suivi de Kevin', kind: 'teacher_parent', learner_id: 'K1', status: 'open', support_status: null, created_by: 'P-PAR',
  last_message_at: '2026-11-05T10:00:00+01:00', archived_at: null, created_at: '2026-11-05T09:00:00+01:00', updated_at: '2026-11-05T10:00:00+01:00', unread_count: 0, ...over,
});
const message = (over: Record<string, unknown> = {}) => ({
  id: '01M1', conversation_id: 'C1', sender_id: 'P-PAR', sender_side: 'client', body: 'Bonjour', attachments: [], moderation_status: 'visible', created_at: '2026-11-05T09:00:00+01:00', ...over,
});

describe('messagerie pour tous (C-113)', () => {
  it('liste les conversations, non lues en gras, sans v-html', async () => {
    signIn();
    mockApi({
      'GET /conversations': page([conversation({ unread_count: 2 }), conversation({ id: 'C2', subject: 'Support', kind: 'support', support_status: 'pending' })]),
      'GET /conversation-unread': { data: { unread: 2 } },
    });
    const { wrapper } = await mountView(InboxView, {}, '/messagerie');
    const items = wrapper.findAll('.item');
    expect(items).toHaveLength(2);
    expect(items[0]!.classes()).toContain('unread');
    expect(items[0]!.text()).toContain('2 non lus');
    expect(items[1]!.text()).toContain('Réponse du support');
    expect(wrapper.html()).not.toContain('v-html');
  });

  it('interroge à nouveau toutes les 30 s tant que l\'écran est ouvert, plus après fermeture', async () => {
    signIn();
    const calls = mockApi({ 'GET /conversations': page([]), 'GET /conversation-unread': { data: { unread: 0 } } });
    vi.useFakeTimers({ shouldAdvanceTime: true });
    const { wrapper } = await mountView(InboxView, {}, '/messagerie');
    const before = calls.filter((c) => c.path === '/conversations').length;
    await vi.advanceTimersByTimeAsync(30_000);
    expect(calls.filter((c) => c.path === '/conversations').length).toBe(before + 1);
    wrapper.unmount();
    await vi.advanceTimersByTimeAsync(60_000);
    expect(calls.filter((c) => c.path === '/conversations').length).toBe(before + 1);
  });

  it('affiche le fil dans l\'ordre, nomme le support, rend les liens sûrs et envoie un message', async () => {
    signIn({ ...teacherMe, person_id: 'P-TEACH' });
    const calls = mockApi({
      'GET /conversations/C1': { data: { ...conversation(), participants: [{ person_id: 'P-PAR', name: 'Paula Foka', chat_role: 'parent' }, { person_id: 'P-TEACH', name: 'Joseph Ateba', chat_role: 'teacher' }] } },
      'GET /conversations/C1/messages': page([
        message({ id: '01M3', sender_id: null, sender_side: 'platform', body: 'Voir https://aide.scholaris.cm/guide. Merci' }),
        message({ id: '01M2', sender_id: 'P-TEACH', body: '<b>gras</b> javascript:alert(1)' }),
        message({ id: '01M1', body: null, moderation_status: 'hidden' }),
      ]),
      'POST /conversations/C1/messages': { data: message({ id: '01M4', sender_id: 'P-TEACH', body: 'Réponse' }) },
      'GET /conversation-unread': { data: { unread: 0 } },
    });
    const { wrapper } = await mountView(ThreadView, { id: 'C1' }, '/messagerie/C1');
    const bubbles = wrapper.findAll('.bubble');
    expect(bubbles.map((b) => b.find('.who strong').text())).toEqual(['Paula Foka', 'Vous', 'Support SCHOLARIS']);
    expect(bubbles[0]!.text()).toContain('Message masqué');
    expect(bubbles[1]!.find('b').exists()).toBe(false);
    expect(bubbles[1]!.text()).toContain('<b>gras</b>');
    const link = bubbles[2]!.get('a');
    expect(link.attributes('href')).toBe('https://aide.scholaris.cm/guide');
    expect(link.attributes('rel')).toContain('noopener');
    await wrapper.get('#msg-draft').setValue('Réponse');
    await wrapper.get('form.composer').trigger('submit');
    await flushPromises();
    expect(calls.find((c) => c.method === 'POST')?.body).toEqual({ body: 'Réponse' });
    expect(wrapper.findAll('.bubble')).toHaveLength(4);
  });

  it('signale le message d\'un autre participant', async () => {
    signIn();
    const calls = mockApi({
      'GET /conversations/C1': { data: { ...conversation(), participants: [] } },
      'GET /conversations/C1/messages': page([message()]),
      'POST /conversations/C1/messages/01M1/report': { data: { id: 'R1', status: 'open' } },
      'GET /conversation-unread': { data: { unread: 0 } },
    });
    const { wrapper } = await mountView(ThreadView, { id: 'C1' }, '/messagerie/C1');
    await wrapper.get('.bubble .link').trigger('click');
    await wrapper.get('#rep-01M1').setValue('Propos déplacés');
    await wrapper.get('form.report').trigger('submit');
    await flushPromises();
    expect(calls.find((c) => c.path.endsWith('/report'))?.body).toEqual({ reason: 'Propos déplacés' });
    expect(wrapper.text()).toContain('Signalement envoyé');
  });

  it('propose seulement les élèves et interlocuteurs donnés par le serveur', async () => {
    signIn({ ...teacherMe, context: { establishment_id: 'E1', role: 'parent' } }, [tile()], 'parent');
    const calls = mockApi({
      'GET /conversation-learners': { data: [{ learner_id: 'K1', name: 'Kevin Foka' }] },
      'GET /conversation-contacts': { data: [{ person_id: 'T1', name: 'Tom Ndi', chat_role: 'teacher', kind: 'teacher_parent' }] },
      'POST /conversations': { data: conversation({ id: 'C9' }) },
    });
    const { wrapper, router } = await mountView(NewConversationView, {}, '/messagerie/nouvelle');
    await flushPromises();
    expect(calls.find((c) => c.path === '/conversation-contacts')?.query).toEqual({ learner_id: 'K1' });
    await wrapper.get('#nc-subject').setValue('Rendez-vous');
    await wrapper.get('#nc-body').setValue('Bonjour');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(calls.find((c) => c.method === 'POST')?.body).toEqual({ subject: 'Rendez-vous', kind: 'teacher_parent', learner_id: 'K1', participant_ids: ['T1'], body: 'Bonjour' });
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('inbox-thread'));
  });

  it('écrit au support SCHOLARIS (direction)', async () => {
    signIn({ ...teacherMe, context: { establishment_id: 'E1', role: 'directeur' } }, [tile()], 'directeur');
    const calls = mockApi({ 'POST /support-conversations': { data: conversation({ id: 'S1', kind: 'support' }) } });
    const { wrapper } = await mountView(NewConversationView, {}, '/messagerie/nouvelle?support=1');
    expect(wrapper.find('#nc-learner').exists()).toBe(false);
    await wrapper.get('#nc-subject').setValue('Question');
    await wrapper.get('#nc-body').setValue('Comment faire ?');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(calls.find((c) => c.path === '/support-conversations')?.body).toEqual({ subject: 'Question', body: 'Comment faire ?' });
  });

  it('modère : voir, masquer avec motif, classer sans suite', async () => {
    signIn({ ...teacherMe, context: { establishment_id: 'E1', role: 'directeur' } }, [tile()], 'directeur');
    const report = { id: 'R1', conversation_id: 'C1', message_id: '01M1', reported_by: 'P-PAR', reason: 'Insulte', status: 'open', resolved_by: null, resolved_at: null, created_at: '2026-11-05T09:00:00+01:00' };
    const calls = mockApi({
      'GET /conversation-reports': page([report]),
      'GET /conversations/C1/messages': page([message({ body: 'Texte signalé' })]),
      'POST /conversations/C1/messages/01M1/hide': { data: message({ moderation_status: 'hidden' }) },
      'POST /conversation-reports/R1/dismiss': { data: { ...report, status: 'dismissed' } },
    });
    const { wrapper } = await mountView(ModerationView, {}, '/messagerie/moderation');
    await wrapper.get('.reports .btn--ghost').trigger('click');
    await flushPromises();
    expect(wrapper.text()).toContain('Texte signalé');
    await wrapper.get('#mod-R1').setValue('Charte');
    const hide = wrapper.findAll('.actions .btn').find((b) => b.text() === 'Masquer')!;
    await hide.trigger('click');
    await flushPromises();
    expect(calls.find((c) => c.path.endsWith('/hide'))?.body).toEqual({ reason: 'Charte' });
    expect(wrapper.text()).toContain('Décision enregistrée');
  });

  it('les routes exigent les rôles du serveur et la boîte du support est réservée à la plateforme', () => {
    const byName = new Map(routes.map((r) => [r.name, r]));
    expect(byName.get('inbox')?.meta?.roles).toContain('parent');
    expect(byName.get('inbox-moderation')?.meta?.roles).toEqual(['super_admin', 'directeur']);
    expect(byName.get('platform-support')?.meta?.platform).toBe(true);
    expect(byName.get('platform-support')?.meta?.perms).toEqual(['platform.support.manage']);
  });

  it('linkify ne crée un lien que pour http et https', () => {
    expect(linkify('a https://x.cm b')).toEqual([{ text: 'a ' }, { text: 'https://x.cm', href: 'https://x.cm' }, { text: ' b' }]);
    expect(linkify('javascript:alert(1) data:text/html,x').every((s) => !s.href)).toBe(true);
  });
});

describe('boîte du support (plateforme)', () => {
  const supportRow = {
    id: 'S1', tenant_id: 'T1', tenant_name: 'Groupe Alpha', establishment_id: 'E1', establishment_name: 'Collège Alpha', subject: 'Bulletins', status: 'open',
    support_status: 'open', assignee: null, opened_by: 'client', last_message_at: '2026-11-05T10:00:00+01:00', created_at: '2026-11-05T09:00:00+01:00', updated_at: '2026-11-05T10:00:00+01:00', unread_count: 1,
  };

  it('filtre par statut sans en-tête d\'établissement', async () => {
    signIn();
    const calls = mockApi({
      'GET /platform/support/conversations': page([supportRow]),
      'GET /platform/clients': { data: [{ id: 'T1', name: 'Groupe Alpha', status: 'active' }] },
      'GET /platform/support/agents': { data: [{ user_id: 'U9', name: 'Awa Support', role: 'platform_support' }] },
    });
    const { wrapper } = await mountView(PlatformSupportView, {}, '/platform/support');
    expect(wrapper.text()).toContain('Groupe Alpha · Collège Alpha');
    const call = calls.find((c) => c.path === '/platform/support/conversations')!;
    expect(call.query['filter[status]']).toBe('open');
    expect(call.headers['X-Establishment-Id']).toBeUndefined();
  });

  it('répond, change le statut et assigne', async () => {
    signIn();
    const calls = mockApi({
      'GET /platform/support/conversations/S1': { data: { ...supportRow, participants: [{ person_id: 'P1', name: 'Dora Bello', chat_role: 'client_admin' }] } },
      'GET /platform/support/conversations/S1/messages': page([{ id: '01M1', conversation_id: 'S1', sender_id: 'P1', sender_side: 'client', body: 'Aide', moderation_status: 'visible', created_at: '2026-11-05T09:00:00+01:00' }]),
      'GET /platform/support/agents': { data: [{ user_id: 'U9', name: 'Awa Support', role: 'platform_support' }] },
      'POST /platform/support/conversations/S1/messages': { data: { id: '01M2', conversation_id: 'S1', sender_id: 'U9', sender_side: 'platform', body: 'Voici', moderation_status: 'visible', created_at: '2026-11-05T10:00:00+01:00' } },
      'POST /platform/support/conversations/S1/status': { data: { ...supportRow, support_status: 'resolved' } },
      'POST /platform/support/conversations/S1/assign': { data: { ...supportRow, assignee: 'U9' } },
    });
    const { wrapper } = await mountView(PlatformSupportThreadView, { id: 'S1' }, '/platform/support/S1');
    expect(wrapper.text()).toContain('Dora Bello');
    await wrapper.get('#sup-draft').setValue('Voici');
    await wrapper.get('form.composer').trigger('submit');
    await flushPromises();
    expect(calls.find((c) => c.method === 'POST' && c.path.endsWith('/messages'))?.body).toEqual({ body: 'Voici' });
    await wrapper.get('#sup-th-status').setValue('resolved');
    await wrapper.get('#sup-th-assignee').setValue('U9');
    await flushPromises();
    expect(calls.find((c) => c.path.endsWith('/status'))?.body).toEqual({ status: 'resolved' });
    expect(calls.find((c) => c.path.endsWith('/assign'))?.body).toEqual({ user_id: 'U9' });
  });
});

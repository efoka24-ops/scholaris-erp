<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use Tests\Support\Messaging\ChatTestCase;

/** Messagerie interne : périmètre, consentement, pièces jointes, signalement, modération, notifications, isolation. */
final class ConversationsTest extends ChatTestCase
{
    protected function setUp(): void
    {
        $this->bootChat();
    }

    /** @return list<array<string, mixed>> */
    private function messagesOf(string $conversation, string $user, string $tenant = 't1', ?string $establishment = 'e1'): array
    {
        return $this->api('GET', '/conversations/' . $conversation . '/messages', $user, $tenant, $establishment)[1]['data'];
    }

    public function testUnParentOuvreUneConversationAvecLEnseignantDeSonEnfant(): void
    {
        [$response, $body] = $this->api('POST', '/conversations', 'paula', 't1', 'e1', $this->conversationBody());

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('open', $body['data']['status']);
        self::assertSame('teacher_parent', $body['data']['kind']);
        self::assertSame('kid1', $body['data']['learner_id']);
        self::assertSame('par1', $body['data']['created_by']);
        self::assertEqualsCanonicalizing(
            [['person_id' => 'par1', 'name' => 'Paula Foka', 'chat_role' => 'parent'], ['person_id' => 'tea1', 'name' => 'Tom Ndi', 'chat_role' => 'teacher']],
            $body['data']['participants'],
        );
        self::assertArrayNotHasKey('tenant_id', $body['data']);
        $messages = $this->messagesOf($body['data']['id'], 'paula');
        self::assertCount(1, $messages);
        self::assertSame('Bonjour, puis-je vous rencontrer ?', $messages[0]['body']);
        self::assertSame('par1', $messages[0]['sender_id']);
    }

    public function testLEnseignantEtLaDirectionPeuventOuvrirAvecUnParentDeLEleve(): void
    {
        $this->openConversation('tom', ['participant_ids' => ['par1']]);
        $this->openConversation('dora', ['kind' => 'direction_parent', 'participant_ids' => ['par1']]);
        $this->openConversation('paula', ['kind' => 'direction_parent', 'participant_ids' => ['dir1']]);

        [, $list] = $this->api('GET', '/conversations', 'paula', 't1', 'e1');
        self::assertCount(3, $list['data']);
    }

    public function testPerimetreUnParentNeJointQueLesEnseignantsEtLaDirectionDeSesEnfants(): void
    {
        $cases = [
            'enseignant d\'un autre élève' => $this->conversationBody(['participant_ids' => ['tea2']]),
            'élève d\'une autre famille' => $this->conversationBody(['learner_id' => 'kid2']),
            'autre parent' => $this->conversationBody(['participant_ids' => ['par2']]),
            'personne inconnue' => $this->conversationBody(['participant_ids' => ['ghost']]),
            'direction avec la nature enseignant' => $this->conversationBody(['participant_ids' => ['dir1']]),
            'enseignant avec la nature direction' => $this->conversationBody(['kind' => 'direction_parent']),
        ];
        foreach ($cases as $label => $body) {
            self::assertSame(422, $this->code('POST', '/conversations', 'paula', 't1', 'e1', $body), $label);
        }
        self::assertSame(422, $this->code('POST', '/conversations', 'tina', 't1', 'e1', $this->conversationBody(['participant_ids' => ['par1']])), 'enseignant hors classe');
        self::assertSame(422, $this->code('POST', '/conversations', 'tom', 't1', 'e1', $this->conversationBody(['participant_ids' => ['tea2']])), 'sans parent');
        self::assertSame(422, $this->code('POST', '/conversations', 'tom', 't1', 'e1', $this->conversationBody(['participant_ids' => ['par1', 'dir1']])), 'direction hors nature');
        self::assertSame([], $this->api('GET', '/conversations', 'paula', 't1', 'e1')[1]['data']);
    }

    public function testApprenantNonInscritDansLEtablissement(): void
    {
        $this->learners->enrol('t1', 'e2', 'kid3');
        $this->people->add('t1', 'kid3', 'Kim', 'Foka', null, null, 'fr', false);
        $this->people->guarantor('t1', 'kid3', 'par1');

        self::assertSame(422, $this->code('POST', '/conversations', 'paula', 't1', 'e1', $this->conversationBody(['learner_id' => 'kid3'])));
    }

    public function testLeConsentementDuParentEstExigeALaCreationEtAChaqueEnvoi(): void
    {
        $this->people->revokeConsent('t1', 'par1');
        [$refused, $body] = $this->api('POST', '/conversations', 'tom', 't1', 'e1', $this->conversationBody(['participant_ids' => ['par1']]));
        self::assertSame(422, $refused->getStatusCode());
        self::assertStringContainsString('consentement', $body['errors']['participant_ids'][0]);

        $this->people->add('t1', 'par1', 'Paula', 'Foka', '+237670111111');
        $conversation = $this->openConversation('tom', ['participant_ids' => ['par1']]);
        $this->people->revokeConsent('t1', 'par1');

        self::assertSame(403, $this->code('POST', '/conversations/' . $conversation . '/messages', 'tom', 't1', 'e1', ['body' => 'Rappel']));
        self::assertSame(200, $this->code('GET', '/conversations/' . $conversation, 'tom', 't1', 'e1'), 'la lecture reste possible');
    }

    public function testLePerimetreEstRecontroleAChaqueEnvoi(): void
    {
        $conversation = $this->openConversation();
        self::assertSame(201, $this->code('POST', '/conversations/' . $conversation . '/messages', 'paula', 't1', 'e1', ['body' => 'Merci']));

        $this->world->teacher('t1', 'e1', 'tea1', []);

        self::assertSame(403, $this->code('POST', '/conversations/' . $conversation . '/messages', 'paula', 't1', 'e1', ['body' => 'Et ensuite ?']));
    }

    public function testPermissionsEtComptesSansPersonne(): void
    {
        self::assertSame(403, $this->code('GET', '/conversations', 'carol', 't1', 'e1'));
        self::assertSame(403, $this->code('POST', '/conversations', 'carol', 't1', 'e1', $this->conversationBody()));
        self::assertSame(403, $this->code('GET', '/conversations', 'mona', 't1', 'e1'), 'le modérateur seul n\'a pas de boîte de réception');
        self::assertSame(403, $this->code('GET', '/conversation-reports', 'paula', 't1', 'e1'));

        $this->access->grant('ghost', 'e1');
        $this->permissions->grant('ghost', 'messaging.chat');
        self::assertSame(403, $this->code('GET', '/conversations', 'ghost', 't1', 'e1'), 'compte sans personne');
    }

    public function testUnNonParticipantNeVoitRienEtRecoitUn404(): void
    {
        $conversation = $this->openConversation();

        foreach ([['GET', ''], ['GET', '/messages'], ['POST', '/messages']] as [$method, $suffix]) {
            self::assertSame(404, $this->code($method, '/conversations/' . $conversation . $suffix, 'pierre', 't1', 'e1', $method === 'POST' ? ['body' => 'Coucou'] : null), $method . $suffix);
            self::assertSame(404, $this->code($method, '/conversations/' . $conversation . $suffix, 'tina', 't1', 'e1', $method === 'POST' ? ['body' => 'Coucou'] : null));
        }
        self::assertSame([], $this->api('GET', '/conversations', 'pierre', 't1', 'e1')[1]['data']);
        self::assertSame(404, $this->code('POST', '/conversations/' . $conversation . '/messages/xyz/report', 'pierre', 't1', 'e1', ['reason' => 'Spam spam']));
    }

    public function testEchangeNonLusEtOrdreDesMessages(): void
    {
        $conversation = $this->openConversation();
        $this->api('POST', '/conversations/' . $conversation . '/messages', 'tom', 't1', 'e1', ['body' => 'Oui, jeudi à 15 h.']);
        $this->api('POST', '/conversations/' . $conversation . '/messages', 'tom', 't1', 'e1', ['body' => 'Cela vous convient ?']);

        $listed = $this->api('GET', '/conversations', 'paula', 't1', 'e1')[1]['data'];
        self::assertSame(2, $listed[0]['unread_count']);
        self::assertSame(0, $this->api('GET', '/conversations', 'tom', 't1', 'e1')[1]['data'][0]['unread_count'], 'répondre vaut lecture');

        $messages = $this->messagesOf($conversation, 'paula');
        self::assertSame(['Cela vous convient ?', 'Oui, jeudi à 15 h.', 'Bonjour, puis-je vous rencontrer ?'], array_column($messages, 'body'));
        self::assertSame(0, $this->api('GET', '/conversations', 'paula', 't1', 'e1')[1]['data'][0]['unread_count']);
        $this->api('POST', '/conversations/' . $conversation . '/messages', 'paula', 't1', 'e1', ['body' => 'Parfait, merci.']);
        self::assertSame(1, $this->api('GET', '/conversations', 'tom', 't1', 'e1')[1]['data'][0]['unread_count']);
    }

    public function testPiecesJointesParReferenceDeDocument(): void
    {
        $conversation = $this->openConversation();

        [$ok, $body] = $this->api('POST', '/conversations/' . $conversation . '/messages', 'tom', 't1', 'e1', ['body' => 'Voici l\'attestation.', 'attachments' => ['doc-kid1']]);
        self::assertSame(201, $ok->getStatusCode());
        self::assertSame([['document_id' => 'doc-kid1', 'number' => 'DOC-doc-kid1', 'type' => 'certificate']], $body['data']['attachments']);

        self::assertSame(422, $this->code('POST', '/conversations/' . $conversation . '/messages', 'tom', 't1', 'e1', ['body' => 'x', 'attachments' => ['doc-kid2']]), 'document d\'un autre élève');
        self::assertSame(422, $this->code('POST', '/conversations/' . $conversation . '/messages', 'tom', 't1', 'e1', ['body' => 'x', 'attachments' => ['inconnu']]));
        self::assertSame(422, $this->code('POST', '/conversations/' . $conversation . '/messages', 'tom', 't1', 'e1', ['body' => 'x', 'attachments' => ['a', 'b', 'c', 'd', 'e', 'f']]));
        self::assertSame(422, $this->code('POST', '/conversations', 'paula', 't1', 'e1', $this->conversationBody(['attachments' => ['doc-kid2']])));
    }

    public function testNotificationSansLeTexteDuMessage(): void
    {
        $this->openConversation(override: ['body' => 'Texte très confidentiel']);

        $row = $this->connections()->pdo()->query("SELECT m.template_key, m.body, d.channel, d.status FROM message_deliveries d JOIN messages m ON m.id = d.message_id WHERE d.recipient_id = 'tea1' AND d.channel = 'push'")->fetch();
        self::assertSame('chat_new_message', $row['template_key']);
        self::assertNull($row['body']);
        self::assertSame('delivered', $row['status']);
        self::assertStringNotContainsString('confidentiel', json_encode($this->mail->sent ?? [], JSON_THROW_ON_ERROR) . json_encode($this->sms->sent, JSON_THROW_ON_ERROR));
        $sender = $this->connections()->pdo()->query("SELECT COUNT(*) FROM message_deliveries WHERE recipient_id = 'par1'")->fetchColumn();
        self::assertSame(0, (int) $sender, 'l\'auteur n\'est pas notifié de son propre message');
    }

    public function testUneNotificationEnEchecNeBloquePasLeMessage(): void
    {
        $this->people->revokeConsent('t1', 'tea1');

        $conversation = $this->openConversation();

        self::assertCount(1, $this->messagesOf($conversation, 'paula'));
    }

    public function testSignalementPuisMasquagePuisRetablissement(): void
    {
        $conversation = $this->openConversation();
        $sent = $this->api('POST', '/conversations/' . $conversation . '/messages', 'tom', 't1', 'e1', ['body' => 'Message déplacé']);
        $messageId = $sent[1]['data']['id'];
        $path = '/conversations/' . $conversation . '/messages/' . $messageId;

        self::assertSame(422, $this->code('POST', $path . '/report', 'tom', 't1', 'e1', ['reason' => 'Mon propre message']), 'pas d\'auto-signalement');
        self::assertSame(422, $this->code('POST', $path . '/report', 'paula', 't1', 'e1', ['reason' => 'ab']), 'motif trop court');
        [$report, $reportBody] = $this->api('POST', $path . '/report', 'paula', 't1', 'e1', ['reason' => 'Propos inappropriés']);
        self::assertSame(201, $report->getStatusCode());
        self::assertSame('open', $reportBody['data']['status']);
        self::assertSame(409, $this->code('POST', $path . '/report', 'paula', 't1', 'e1', ['reason' => 'Encore']));

        self::assertSame(403, $this->code('GET', '/conversation-reports', 'paula', 't1', 'e1'));
        self::assertSame(403, $this->code('POST', $path . '/hide', 'paula', 't1', 'e1', ['reason' => 'Je masque']), 'seul un modérateur masque');
        [$queue, $queueBody] = $this->api('GET', '/conversation-reports?filter[status]=open', 'mona', 't1', 'e1');
        self::assertSame(200, $queue->getStatusCode());
        self::assertSame([$reportBody['data']['id']], array_column($queueBody['data'], 'id'));

        [$hidden, $hiddenBody] = $this->api('POST', $path . '/hide', 'mona', 't1', 'e1', ['reason' => 'Contraire à la charte']);
        self::assertSame(200, $hidden->getStatusCode());
        self::assertSame('hidden', $hiddenBody['data']['moderation_status']);
        self::assertSame('mona', $hiddenBody['data']['moderated_by']);
        self::assertSame(409, $this->code('POST', $path . '/hide', 'mona', 't1', 'e1', ['reason' => 'Deux fois']));

        $seenByParent = $this->messagesOf($conversation, 'paula')[0];
        self::assertSame('hidden', $seenByParent['moderation_status']);
        self::assertNull($seenByParent['body']);
        self::assertArrayNotHasKey('moderation_reason', $seenByParent);
        $seenByModerator = $this->messagesOf($conversation, 'mona')[0];
        self::assertSame('Message déplacé', $seenByModerator['body']);
        self::assertSame('Contraire à la charte', $seenByModerator['moderation_reason']);
        self::assertSame('actioned', $this->api('GET', '/conversation-reports', 'mona', 't1', 'e1')[1]['data'][0]['status']);

        [$restored, $restoredBody] = $this->api('POST', $path . '/restore', 'mona', 't1', 'e1');
        self::assertSame(200, $restored->getStatusCode());
        self::assertSame('visible', $restoredBody['data']['moderation_status']);
        self::assertSame('Message déplacé', $this->messagesOf($conversation, 'paula')[0]['body']);
        self::assertSame(409, $this->code('POST', $path . '/restore', 'mona', 't1', 'e1'));
    }

    public function testUnSignalementPeutEtreClasseSansSuite(): void
    {
        $conversation = $this->openConversation();
        $messageId = $this->api('POST', '/conversations/' . $conversation . '/messages', 'tom', 't1', 'e1', ['body' => 'Rien de grave'])[1]['data']['id'];
        $reportId = $this->api('POST', '/conversations/' . $conversation . '/messages/' . $messageId . '/report', 'paula', 't1', 'e1', ['reason' => 'Doute'])[1]['data']['id'];

        self::assertSame(403, $this->code('POST', '/conversation-reports/' . $reportId . '/dismiss', 'paula', 't1', 'e1'));
        [$dismissed, $body] = $this->api('POST', '/conversation-reports/' . $reportId . '/dismiss', 'mona', 't1', 'e1');
        self::assertSame(200, $dismissed->getStatusCode());
        self::assertSame('dismissed', $body['data']['status']);
        self::assertSame(409, $this->code('POST', '/conversation-reports/' . $reportId . '/dismiss', 'mona', 't1', 'e1'));
        self::assertSame(404, $this->code('POST', '/conversation-reports/inconnu/dismiss', 'mona', 't1', 'e1'));
    }

    public function testLeModerateurLitToutEtChaqueLectureEstAuditee(): void
    {
        $conversation = $this->openConversation();

        [$all, $list] = $this->api('GET', '/conversations?filter[scope]=all', 'mona', 't1', 'e1');
        self::assertSame(200, $all->getStatusCode());
        self::assertSame([$conversation], array_column($list['data'], 'id'));
        self::assertSame(403, $this->code('GET', '/conversations?filter[scope]=all', 'paula', 't1', 'e1'));
        self::assertSame(422, $this->code('GET', '/conversations?filter[scope]=autre', 'mona', 't1', 'e1'));

        self::assertSame(200, $this->code('GET', '/conversations/' . $conversation, 'mona', 't1', 'e1'));
        self::assertCount(1, $this->messagesOf($conversation, 'mona'));
        $audited = $this->connections()->pdo()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'conversation.moderator_read' AND actor_id = 'mona'")->fetchColumn();
        self::assertSame(1, (int) $audited);
        self::assertSame(403, $this->code('POST', '/conversations/' . $conversation . '/messages', 'mona', 't1', 'e1', ['body' => 'Je m\'invite']), 'le modérateur ne parle pas sans permission de messagerie');
    }

    public function testArchivageParLeModerateur(): void
    {
        $conversation = $this->openConversation();

        self::assertSame(403, $this->code('POST', '/conversations/' . $conversation . '/archive', 'paula', 't1', 'e1'));
        [$archived, $body] = $this->api('POST', '/conversations/' . $conversation . '/archive', 'mona', 't1', 'e1');
        self::assertSame(200, $archived->getStatusCode());
        self::assertSame('archived', $body['data']['status']);
        self::assertSame(409, $this->code('POST', '/conversations/' . $conversation . '/archive', 'mona', 't1', 'e1'));
        self::assertSame(409, $this->code('POST', '/conversations/' . $conversation . '/messages', 'paula', 't1', 'e1', ['body' => 'Encore ?']));
        self::assertSame(200, $this->code('GET', '/conversations/' . $conversation, 'paula', 't1', 'e1'));
    }

    public function testInterlocuteursProposesSelonLeRole(): void
    {
        [, $parent] = $this->api('GET', '/conversation-contacts?learner_id=kid1', 'paula', 't1', 'e1');
        self::assertEqualsCanonicalizing(
            [['person_id' => 'tea1', 'name' => 'Tom Ndi', 'chat_role' => 'teacher', 'kind' => 'teacher_parent'], ['person_id' => 'dir1', 'name' => 'Dora Bello', 'chat_role' => 'direction', 'kind' => 'direction_parent']],
            $parent['data'],
        );
        self::assertSame([], $this->api('GET', '/conversation-contacts?learner_id=kid2', 'paula', 't1', 'e1')[1]['data'], 'pas son enfant');
        [, $teacher] = $this->api('GET', '/conversation-contacts?learner_id=kid1', 'tom', 't1', 'e1');
        self::assertSame(['par1'], array_column($teacher['data'], 'person_id'));
        self::assertSame([], $this->api('GET', '/conversation-contacts?learner_id=kid1', 'tina', 't1', 'e1')[1]['data']);
        self::assertSame(422, $this->code('GET', '/conversation-contacts', 'paula', 't1', 'e1'));
        $this->people->revokeConsent('t1', 'par1');
        self::assertSame([], $this->api('GET', '/conversation-contacts?learner_id=kid1', 'tom', 't1', 'e1')[1]['data']);
    }

    public function testValidationEtProtectionContreLAffectationEnMasse(): void
    {
        foreach ([
            ['tenant_id' => 't2'], ['establishment_id' => 'e2'], ['status' => 'archived'], ['created_by' => 'par2'],
            ['kind' => 'groupe'], ['subject' => ''], ['body' => ''], ['participant_ids' => 'tea1'], ['participant_ids' => ['a', 'b', 'c', 'd']],
        ] as $override) {
            self::assertSame(422, $this->code('POST', '/conversations', 'paula', 't1', 'e1', $this->conversationBody($override)), json_encode($override));
        }
        $conversation = $this->openConversation();
        self::assertSame(422, $this->code('POST', '/conversations/' . $conversation . '/messages', 'paula', 't1', 'e1', ['body' => 'x', 'sender_id' => 'tea1']));
        self::assertSame(422, $this->code('POST', '/conversations/' . $conversation . '/messages', 'paula', 't1', 'e1', ['body' => str_repeat('a', 5001)]));
    }

    public function testIsolationInterClientsEtInterEtablissements(): void
    {
        $conversation = $this->openConversation();
        $messageId = $this->messagesOf($conversation, 'paula')[0]['id'];

        // Autre client : mêmes identifiants, rien n'est visible, même pour un modérateur.
        foreach ([['GET', ''], ['GET', '/messages'], ['POST', '/archive'], ['POST', '/messages/' . $messageId . '/hide']] as [$method, $suffix]) {
            self::assertSame(404, $this->code($method, '/conversations/' . $conversation . $suffix, 'bob', 't2', 'e3', $method === 'POST' ? ['reason' => 'Intrusion'] : null), 'client t2 : ' . $method . $suffix);
        }
        self::assertSame([], $this->api('GET', '/conversations?filter[scope]=all', 'bob', 't2', 'e3')[1]['data']);

        // Autre établissement du même client.
        $this->access->grant('mona', 'e2');
        $this->access->grant('paula', 'e2');
        foreach (['mona', 'paula'] as $user) {
            self::assertSame(404, $this->code('GET', '/conversations/' . $conversation, $user, 't1', 'e2'), 'e2 : ' . $user);
            self::assertSame(404, $this->code('GET', '/conversations/' . $conversation . '/messages', $user, 't1', 'e2'), 'e2 : ' . $user);
        }
        self::assertSame([], $this->api('GET', '/conversations?filter[scope]=all', 'mona', 't1', 'e2')[1]['data']);
        self::assertSame(404, $this->code('POST', '/conversations/' . $conversation . '/messages/' . $messageId . '/hide', 'mona', 't1', 'e2', ['reason' => 'Autre école']));
        self::assertSame(404, $this->code('POST', '/conversations/' . $conversation . '/messages', 'paula', 't1', 'e2', ['body' => 'Autre école']));
    }

    public function testLeMessageSignaleDUneAutreConversationEstIntrouvable(): void
    {
        $first = $this->openConversation();
        $second = $this->openConversation('paula', ['subject' => 'Autre sujet']);
        $messageId = $this->messagesOf($first, 'paula')[0]['id'];

        self::assertSame(404, $this->code('POST', '/conversations/' . $second . '/messages/' . $messageId . '/hide', 'mona', 't1', 'e1', ['reason' => 'Mauvaise conversation']));
        self::assertSame(404, $this->code('POST', '/conversations/' . $second . '/messages/' . $messageId . '/report', 'tom', 't1', 'e1', ['reason' => 'Mauvaise conversation']));
    }
}

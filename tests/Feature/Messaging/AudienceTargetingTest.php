<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use Tests\Support\Messaging\MessagingTestCase;

/** Ciblage : personne, classe, niveau, établissement, impayés, absents du jour. Aperçu, envoi, livraisons. */
final class AudienceTargetingTest extends MessagingTestCase
{
    protected function setUp(): void
    {
        $this->bootMessaging();
        // Garants (avec téléphone) et apprenants (sans téléphone propre).
        $this->parent('g1', 'Marie', '+237670000001');
        $this->parent('g2', 'Jean', '+237670000002');
        $this->parent('g3', 'Olga', '+237670000003', 'en');
        $this->people->add('t1', 'l1', 'Yann', 'Foka', '+237671000001');
        $this->people->add('t1', 'l2', 'Nadia', 'Foka', '+237671000002');
        $this->people->add('t1', 'l3', 'Karl', 'Eto', '+237671000003');
        $this->people->guarantor('t1', 'l1', 'g1');
        $this->people->guarantor('t1', 'l2', 'g1');
        $this->people->guarantor('t1', 'l3', 'g2');
        $this->enrollments->enrol('t1', 'class-6a', 'e1', 'l1');
        $this->enrollments->enrol('t1', 'class-6a', 'e1', 'l3');
        $this->enrollments->enrol('t1', 'class-5b', 'e1', 'l2');
        $this->enrollments->enrol('t1', 'level-6', 'e1', 'l1');
        $this->enrollments->enrol('t1', 'level-6', 'e1', 'l3');
        // Un autre client a la même structure : il ne doit rien voir.
        $this->people->add('t2', 'x1', 'Xavier', 'Autre', '+237672000001');
        $this->enrollments->enrol('t2', 'class-6a', 'e3', 'x1');
    }

    /** @return array<string, mixed> */
    private function preview(array $audience, string $user = 'alice', string $tenant = 't1', string $establishment = 'e1'): array
    {
        return $this->api('POST', '/messages/audience-preview', $user, $tenant, $establishment, ['audience' => $audience])[1];
    }

    /** @return array<string, mixed> message envoyé */
    private function sendTo(array $audience, string $template = 'family_payment_reference', array $variables = ['payment_reference' => 'FAM-0587']): array
    {
        $created = $this->api('POST', '/messages', 'alice', 't1', 'e1', ['template_key' => $template, 'variables' => $variables, 'audience' => $audience]);
        self::assertSame(201, $created[0]->getStatusCode(), (string) $created[0]->getBody());
        $sent = $this->api('POST', '/messages/' . $created[1]['data']['id'] . '/send', 'alice', 't1', 'e1');
        self::assertSame(200, $sent[0]->getStatusCode(), (string) $sent[0]->getBody());

        return $sent[1]['data'];
    }

    /** @return list<string> */
    private function recipients(string $messageId): array
    {
        $rows = $this->api('GET', '/messages/' . $messageId . '/deliveries', 'alice', 't1', 'e1')[1]['data'];
        $ids = array_map(static fn (array $d): string => $d['recipient_id'], $rows);
        sort($ids);

        return $ids;
    }

    public function testApercuParClasseDonneLeNombreEtUnExempleSansContact(): void
    {
        $body = $this->preview(['type' => 'structure', 'id' => 'class-6a']);

        self::assertSame(2, $body['data']['count']);
        self::assertCount(2, $body['data']['sample']);
        self::assertSame('Yann F.', $body['data']['sample'][0]['display_name']);
        self::assertStringNotContainsString('+237', json_encode($body, JSON_THROW_ON_ERROR));
        self::assertSame([], $this->sms->sent, 'Un aperçu n\'envoie rien.');
        self::assertSame('0', (string) $this->connections()->pdo()->query('SELECT COUNT(*) FROM messages')->fetchColumn());
    }

    public function testCiblageClasseNiveauEtEtablissement(): void
    {
        self::assertSame(['l1', 'l3'], $this->recipients($this->sendTo(['type' => 'structure', 'id' => 'class-6a'])['id']));
        self::assertSame(['l1', 'l3'], $this->recipients($this->sendTo(['type' => 'level', 'id' => 'level-6'])['id']));
        $all = $this->sendTo(['type' => 'establishment']);
        self::assertSame(['l1', 'l2', 'l3'], $this->recipients($all['id']));
        self::assertSame(3, $all['recipients_count']);
    }

    public function testCiblagePersonne(): void
    {
        $message = $this->sendTo(['type' => 'person', 'id' => 'g3']);

        self::assertSame(['g3'], $this->recipients($message['id']));
        self::assertSame('Your family payment reference : FAM-0587. Collège Alpha', $this->sms->sent[0]['message']);
    }

    public function testCiblageImpayesVersLesGarantsAvecLesVariablesDeLEnfant(): void
    {
        $this->debtors->owes('t1', 'e1', 'l1', 35000);
        $this->debtors->owes('t1', 'e1', 'l2', 10000);
        $this->debtors->owes('t1', 'e1', 'l3', 0);
        $this->debtors->owes('t1', 'e2', 'l3', 99999);

        self::assertSame(1, $this->preview(['type' => 'arrears'])['data']['count']);
        $message = $this->sendTo(['type' => 'arrears'], 'payment_reminder', ['due_date' => '03/11/2026']);

        self::assertSame(['g1'], $this->recipients($message['id']));
        self::assertCount(1, $this->sms->sent);
        self::assertSame('+237670000001', $this->sms->sent[0]['to']);
        self::assertSame('Bonjour Marie, il reste 45000 FCFA à régler pour Yann, Nadia avant le 03/11/2026. Collège Alpha', $this->sms->sent[0]['message']);
    }

    public function testCiblageAbsentsDuJour(): void
    {
        $this->debtors->absent('t1', 'e1', 'l3');

        $message = $this->sendTo(['type' => 'absent_today'], 'absence_notice');

        self::assertSame(['g2'], $this->recipients($message['id']));
        self::assertSame('Karl est absent(e) le 2026-10-08. Collège Alpha', $this->sms->sent[0]['message']);
    }

    public function testUneAudienceVideEstRefusee(): void
    {
        $created = $this->api('POST', '/messages', 'alice', 't1', 'e1', ['template_key' => 'absence_notice', 'audience' => ['type' => 'absent_today']]);
        $sent = $this->api('POST', '/messages/' . $created[1]['data']['id'] . '/send', 'alice', 't1', 'e1');

        self::assertSame(422, $sent[0]->getStatusCode());
    }

    public function testLesDestinatairesSansConsentementSontBloquesIndividuellement(): void
    {
        $this->people->revokeConsent('t1', 'l1');

        $message = $this->sendTo(['type' => 'structure', 'id' => 'class-6a']);

        self::assertEquals(['blocked' => 1, 'sent' => 1], $message['delivery_counts']);
        $blocked = $this->api('GET', '/messages/' . $message['id'] . '/deliveries?filter[status]=blocked', 'alice', 't1', 'e1')[1]['data'];
        self::assertCount(1, $blocked);
        self::assertSame(['l1', 'consent_missing'], [$blocked[0]['recipient_id'], $blocked[0]['reason']]);
        self::assertCount(1, $this->sms->sent);
    }

    public function testEnvoiEnMasseHorsPlageMetToutEnFile(): void
    {
        $this->clock->set('2026-10-08T22:00:00+01:00');

        $message = $this->sendTo(['type' => 'establishment']);

        self::assertSame(['queued' => 3], $message['delivery_counts']);
        self::assertSame('scheduled', $message['status']);
        self::assertSame([], $this->sms->sent);
        $this->clock->set('2026-10-09T07:05:00+01:00');
        self::assertSame(3, $this->kernel->container()->get(\Scholaris\Modules\Socle\Messaging\Application\MessageService::class)->processPending());
        self::assertCount(3, $this->sms->sent);
        $detail = $this->api('GET', '/messages/' . $message['id'], 'alice', 't1', 'e1')[1]['data'];
        self::assertSame('completed', $detail['status']);
        self::assertSame(['sent' => 3], $detail['delivery_counts']);
    }

    public function testEnvoiProgrammeResteEnFileJusquALHeure(): void
    {
        $created = $this->api('POST', '/messages', 'alice', 't1', 'e1', [
            'template_key' => 'family_payment_reference', 'variables' => ['payment_reference' => 'F1'],
            'audience' => ['type' => 'person', 'id' => 'g1'], 'scheduled_at' => '2026-10-10T09:00:00+01:00',
        ]);
        $sent = $this->api('POST', '/messages/' . $created[1]['data']['id'] . '/send', 'alice', 't1', 'e1')[1]['data'];

        self::assertSame('scheduled', $sent['status']);
        self::assertSame([], $this->sms->sent);
        $this->clock->set('2026-10-10T09:00:00+01:00');
        $this->kernel->container()->get(\Scholaris\Modules\Socle\Messaging\Application\MessageService::class)->processPending();
        self::assertCount(1, $this->sms->sent);
    }

    public function testUnMessageNePartQuUneFois(): void
    {
        $message = $this->sendTo(['type' => 'person', 'id' => 'g1']);

        self::assertSame(409, $this->code('POST', '/messages/' . $message['id'] . '/send', 'alice', 't1', 'e1'));
        self::assertCount(1, $this->sms->sent);
    }

    public function testMessageLibreEtCanauxExplicites(): void
    {
        $created = $this->api('POST', '/messages', 'alice', 't1', 'e1', ['body' => 'Réunion le :date', 'variables' => ['date' => '12/10'], 'channels' => ['sms'], 'audience' => ['type' => 'person', 'id' => 'g2'], 'urgent' => true]);
        $this->api('POST', '/messages/' . $created[1]['data']['id'] . '/send', 'alice', 't1', 'e1');

        self::assertSame('Réunion le 12/10', $this->sms->sent[0]['message']);
        self::assertTrue($created[1]['data']['urgent']);
    }

    public function testValidationDuMessage(): void
    {
        $base = ['audience' => ['type' => 'establishment']];
        self::assertSame(422, $this->code('POST', '/messages', 'alice', 't1', 'e1', $base), 'ni modèle ni texte');
        self::assertSame(422, $this->code('POST', '/messages', 'alice', 't1', 'e1', $base + ['template_key' => 'absent', 'body' => 'x', 'channels' => ['sms']]), 'les deux');
        self::assertSame(422, $this->code('POST', '/messages', 'alice', 't1', 'e1', $base + ['template_key' => 'inconnu_total']));
        self::assertSame(422, $this->code('POST', '/messages', 'alice', 't1', 'e1', $base + ['body' => 'Code :password', 'channels' => ['sms']]));
        self::assertSame(422, $this->code('POST', '/messages', 'alice', 't1', 'e1', $base + ['body' => 'x', 'channels' => ['sms'], 'variables' => ['password' => 'a']]));
        self::assertSame(422, $this->code('POST', '/messages', 'alice', 't1', 'e1', $base + ['body' => 'x']), 'texte libre sans canal');
        self::assertSame(422, $this->code('POST', '/messages', 'alice', 't1', 'e1', ['body' => 'x', 'channels' => ['sms'], 'audience' => ['type' => 'person']]));
        self::assertSame(422, $this->code('POST', '/messages', 'alice', 't1', 'e1', $base + ['body' => 'x', 'channels' => ['fax']]));
        self::assertSame(422, $this->code('POST', '/messages', 'alice', 't1', 'e1', $base + ['body' => 'x', 'channels' => ['sms'], 'tenant_id' => 't2']), 'affectation en masse');
        self::assertSame(422, $this->code('POST', '/messages', 'alice', 't1', 'e1', $base + ['body' => 'x', 'channels' => ['sms'], 'scheduled_at' => 'demain']));
    }
}

<?php

declare(strict_types=1);

namespace Tests\E2E;

use Scholaris\Modules\Socle\Messaging\Contracts\OptOutRegistry;
use Tests\E2E\Support\BuildsIpes;
use Tests\E2E\Support\RealStackTestCase;

/**
 * RAPPORT-MAIL (16 novembre 2026) : un rapport planifié « direction » d'un IPES fraîchement créé par l'acceptation d'une demande
 * d'accès, envoyé à l'administrateur du client (super_admin, e-mail, aucun consentement enregistré) et à un enseignant (e-mail).
 * Pile réelle (Messaging, People, Identity, Staff). Noms, adresses et numéros FICTIFS.
 */
final class ScheduledReportIpesTest extends RealStackTestCase
{
    use BuildsIpes;

    private const INSTITUTE = [
        'name' => 'Institut Fictif du Rapport de Garoua', 'city' => 'Garoua', 'region' => 'Nord',
        'address' => 'Quartier fictif, Garoua', 'first' => 'Aminou', 'last' => 'Fictif-Promoteur',
        'email' => 'direction@rapport-recette.test', 'phone' => '+237 699 30 00 03', 'normalized' => '+237699300003',
    ];

    protected function setUp(): void
    {
        $this->bootRealStack(['app' => ['url' => 'https://app.scholaris.test']]);
    }

    /**
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function ipesWithTeacher(): array
    {
        $ctx = $this->onboard(self::INSTITUTE);
        $teacher = (string) $this->ok('POST', '/users', $ctx['admin'], [
            'first_name' => 'Moussa', 'last_name' => 'Fictif-Enseignant', 'phone' => $this->nextPhone(), 'email' => 'enseignant@rapport-recette.test',
        ], $ctx['establishment'], [], 201)['data']['person_id'];
        $this->assignRole($ctx, $teacher, 'enseignant', 'establishment');

        return [$ctx, $teacher];
    }

    /**
     * @param array<string, mixed> $ctx
     * @param list<string> $recipients
     * @return array<string, mixed>
     */
    private function schedule(array $ctx, array $recipients): array
    {
        return $this->as($ctx, 'admin', 'POST', '/report-schedules', [
            'name' => 'Direction quotidienne', 'report_type' => 'direction', 'frequency' => 'daily', 'recipient_ids' => $recipients,
        ], 201);
    }

    public function testLeRapportDeDirectionPartAuPersonnelSansConsentementAuxCommunications(): void
    {
        [$ctx, $teacher] = $this->ipesWithTeacher();
        $created = $this->schedule($ctx, [$ctx['admin_person'], $teacher]);
        self::assertSame([], $created['meta']['recipient_warnings'] ?? [], 'Les deux destinataires ont une adresse e-mail.');
        $before = count($this->mail->sent);

        $run = $this->as($ctx, 'admin', 'POST', '/report-schedules/' . $created['data']['id'] . '/run')['data'];

        // Avant la correction : « failed », 0 envoyé, 2 échecs, motif consent_missing pour les deux (Messenger exigeait le consentement).
        self::assertSame('done', $run['status'], (string) json_encode($run));
        self::assertSame(2, $run['sent_count']);
        self::assertSame(0, $run['failed_count']);
        $mails = array_slice($this->mail->sent, $before);
        self::assertCount(2, $mails);
        $body = (string) ($mails[0]['body'] ?? '');
        // Établissement du supérieur : aucun indicateur du primaire ou du secondaire, jamais « indisponible » pour un indicateur sans objet.
        self::assertStringNotContainsString('Absents ce matin', $body);
        self::assertStringNotContainsString('Élèves en retard de paiement', $body);
        self::assertStringNotContainsString('indisponible ; Absents', $body);
        self::assertStringNotContainsString('Élèves inscrits', $body);

        $runs = $this->as($ctx, 'admin', 'GET', '/report-schedules/' . $created['data']['id'] . '/runs')['data'];
        self::assertSame([], $runs[0]['failures']);
    }

    /** Motif conservé par destinataire ; personnel désinscrit respecté ; personne hors du personnel : consentement toujours exigé. */
    public function testLeMotifDeChaqueEchecEstConserveEtLaDesinscriptionRespectee(): void
    {
        [$ctx, $teacher] = $this->ipesWithTeacher();
        $noMail = (string) $this->ok('POST', '/users', $ctx['admin'], [
            'first_name' => 'Hawa', 'last_name' => 'Fictive-Secretaire', 'phone' => $this->nextPhone(),
        ], $ctx['establishment'], [], 201)['data']['person_id'];
        $this->assignRole($ctx, $noMail, 'comptable', 'establishment');
        $this->kernel->container()->get(OptOutRegistry::class)->optOut($ctx['tenant'], $teacher, 'email');
        // Compte sans aucun rôle dans l'établissement : pas du personnel.
        $outsider = (string) $this->ok('POST', '/users', $ctx['admin'], [
            'first_name' => 'Paul', 'last_name' => 'Fictif-Externe', 'phone' => $this->nextPhone(), 'email' => 'externe@rapport-recette.test',
        ], $ctx['establishment'], [], 201)['data']['person_id'];

        $created = $this->schedule($ctx, [$ctx['admin_person'], $teacher, $noMail, $outsider]);
        $warnings = array_column($created['meta']['recipient_warnings'], 'reason', 'person_id');
        ksort($warnings);
        $expected = [$noMail => 'no_email', $outsider => 'consent_required'];
        ksort($expected);
        self::assertSame($expected, $warnings);

        $run = $this->as($ctx, 'admin', 'POST', '/report-schedules/' . $created['data']['id'] . '/run')['data'];
        self::assertSame('partial', $run['status']);
        self::assertSame(1, $run['sent_count']);
        self::assertSame(3, $run['failed_count']);
        $reasons = array_column($run['failures'], 'reason', 'person_id');
        self::assertSame(['opted_out', 'no_contact', 'consent_missing'], [$reasons[$teacher], $reasons[$noMail], $reasons[$outsider]]);
        self::assertSame('Paul Fictif-Externe', array_column($run['failures'], 'name', 'person_id')[$outsider]);

        $runs = $this->as($ctx, 'admin', 'GET', '/report-schedules/' . $created['data']['id'] . '/runs')['data'];
        self::assertSame($run['failures'], $runs[0]['failures']);
    }
}

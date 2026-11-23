<?php

declare(strict_types=1);

namespace Tests\Support\Messaging;

use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Scholaris\Core\RateLimit\Clock;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Modules\Socle\Billing\Contracts\DebtorDirectory;
use Scholaris\Modules\Socle\Integrations\Contracts\MailGateway;
use Scholaris\Modules\Socle\Integrations\Contracts\SmsGateway;
use Scholaris\Modules\Socle\Messaging\Contracts\AbsenteeDirectory;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\People\Contracts\ConsentChecker;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Tests\Support\AppTestCase;

/**
 * Base des tests du module Messaging : Kernel de test (SQLite en mémoire migrée), horloge figée, passerelles
 * enregistreuses et doublures des contrats People, Billing, Organisation et Attendance.
 *
 * Jeu de données : client t1 (établissements e1 « Collège Alpha » et e2), client t2 (e3). Utilisateurs : alice (t1, e1 et e2,
 * tous les droits), bob (t2, e3, tous les droits), carol (t1, e1, sans aucun droit).
 */
abstract class MessagingTestCase extends AppTestCase
{
    protected FixedClock $clock;
    protected StubPermissions $permissions;
    protected InMemoryPeople $people;
    protected InMemoryEnrollments $enrollments;
    protected InMemoryDebtors $debtors;
    protected RecordingSmsGateway $sms;
    protected RecordingMailGateway $mail;
    protected ArrayLogger $logger;
    protected StubSettings $settings;

    /** @param array<string, mixed> $extra */
    protected function bootMessaging(array $extra = []): void
    {
        $this->clock = new FixedClock();
        $this->permissions = new StubPermissions();
        $this->people = new InMemoryPeople();
        $this->enrollments = new InMemoryEnrollments();
        $this->debtors = new InMemoryDebtors();
        $this->sms = new RecordingSmsGateway();
        $this->mail = new RecordingMailGateway();
        $this->logger = new ArrayLogger();
        $this->settings = new StubSettings();
        $establishments = new InMemoryEstablishments();
        $establishments->add('t1', 'e1', 'Collège Alpha');
        $establishments->add('t1', 'e2', 'Lycée Beta');
        $establishments->add('t2', 'e3', 'Institut Gamma');

        $this->boot($extra + [
            Clock::class => $this->clock,
            PermissionChecker::class => $this->permissions,
            PersonDirectory::class => $this->people,
            ConsentChecker::class => $this->people,
            EnrollmentDirectory::class => $this->enrollments,
            DebtorDirectory::class => $this->debtors,
            AbsenteeDirectory::class => $this->debtors,
            EstablishmentDirectory::class => $establishments,
            SmsGateway::class => $this->sms,
            MailGateway::class => $this->mail,
            LoggerInterface::class => $this->logger,
            SettingsReader::class => $this->settings,
        ]);

        $this->access->grant('alice', 'e1');
        $this->access->grant('alice', 'e2');
        $this->access->grant('bob', 'e3');
        $this->access->grant('carol', 'e1');
        $this->permissions->grant('alice', 'messaging.read', 'messaging.send', 'messaging.templates.manage');
        $this->permissions->grant('bob', 'messaging.read', 'messaging.send', 'messaging.templates.manage');
    }

    protected function messenger(): Messenger
    {
        return $this->kernel->container()->get(Messenger::class);
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{0: ResponseInterface, 1: array<string, mixed>}
     */
    protected function api(string $method, string $path, string $user, string $tenant, ?string $establishment = null, ?array $body = null): array
    {
        $headers = ['Authorization' => 'Bearer ' . $user . ':' . $tenant];
        if ($establishment !== null) {
            $headers['X-Establishment-Id'] = $establishment;
        }
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        $response = $this->call($method, '/api/v1' . $path, $headers, $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR));
        $raw = (string) $response->getBody();

        return [$response, $raw === '' ? [] : (array) json_decode($raw, true, flags: JSON_THROW_ON_ERROR)];
    }

    /** @param array<string, mixed>|null $body */
    protected function code(string $method, string $path, string $user, string $tenant, ?string $establishment = null, ?array $body = null): int
    {
        return $this->api($method, $path, $user, $tenant, $establishment, $body)[0]->getStatusCode();
    }

    /** Parent avec téléphone, consentement donné. */
    protected function parent(string $id, string $first, string $phone, string $locale = 'fr', string $tenant = 't1'): void
    {
        $this->people->add($tenant, $id, $first, 'Foka', $phone, null, $locale);
    }

    /** @return array{status: string, reason: string|null} */
    protected function lastDelivery(string $personId): array
    {
        $row = $this->connections()->pdo()->query(
            "SELECT status, reason FROM message_deliveries WHERE recipient_id = '" . $personId . "' ORDER BY id DESC LIMIT 1",
        )->fetch();

        return ['status' => (string) $row['status'], 'reason' => $row['reason'] === null ? null : (string) $row['reason']];
    }
}

<?php

declare(strict_types=1);

namespace Tests\Support\Documents;

use Psr\Http\Message\ResponseInterface;
use Scholaris\Core\RateLimit\Clock;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Modules\Socle\Billing\Contracts\DebtorDirectory;
use Scholaris\Modules\Socle\Documents\Contracts\DocumentIssuer;
use Scholaris\Modules\Socle\Documents\Domain\QrCodeGenerator;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Tests\Support\AppTestCase;

/**
 * Base des tests du module Documents : Kernel de test (SQLite en mémoire migrée), horloge figée et doublures
 * de PermissionChecker, annuaire des personnes, des établissements et des débiteurs.
 *
 * Client t1 : établissement e1 « Collège La Palmeraie » (code CLP), établissement e2 ; client t2 : établissement e3.
 */
abstract class DocumentsTestCase extends AppTestCase
{
    protected DocPermissions $permissions;
    protected InMemoryPeople $people;
    protected InMemoryDebtors $debtors;
    protected InMemoryEstablishments $establishments;
    protected DocClock $clock;
    protected FlakyQr $qr;

    /** @param array<string, mixed> $overrides liaisons supplémentaires (paramètres, gardes d'émission enregistrées) */
    protected function bootDocuments(array $overrides = []): void
    {
        $this->permissions = new DocPermissions();
        $this->people = new InMemoryPeople();
        $this->debtors = new InMemoryDebtors();
        $this->establishments = new InMemoryEstablishments();
        $this->clock = new DocClock();
        $this->qr = new FlakyQr();

        $this->boot([
            PermissionChecker::class => $this->permissions,
            PersonDirectory::class => $this->people,
            DebtorDirectory::class => $this->debtors,
            EstablishmentDirectory::class => $this->establishments,
            Clock::class => $this->clock,
            QrCodeGenerator::class => $this->qr,
        ] + $overrides, [
            'app' => ['url' => 'https://api.scholaris.test'],
            'api' => ['idempotent_paths' => ['#^/api/v1/documents$#']],
        ]);

        $this->establishments->add('t1', 'e1', 'Collège La Palmeraie');
        $this->establishments->add('t1', 'e2', 'Lycée Bilingue');
        $this->establishments->add('t2', 'e3', 'Institut Étranger');
        $this->access->grant('alice', 'e1');
        $this->access->grant('alice', 'e2');
        $this->access->grant('bob', 'e3');
        $this->permissions->grant('alice', 'documents.read', 'documents.issue', 'documents.revoke', 'documents.templates.manage');
        $this->permissions->grant('bob', 'documents.read', 'documents.issue', 'documents.revoke', 'documents.templates.manage');

        $this->people->add('t1', 'p1', 'Nadia', 'Fotso');
        $this->people->add('t1', 'p2', 'Paul', 'Mbarga');
        $this->people->add('t1', 'p3', 'Awa', 'Njoya');
        $this->people->add('t2', 'p9', 'Eric', 'Etranger');
    }

    protected function issuer(): DocumentIssuer
    {
        return $this->kernel->container()->get(DocumentIssuer::class);
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     * @return array{0: ResponseInterface, 1: array<string, mixed>}
     */
    protected function api(string $method, string $path, string $user, string $tenant, ?string $establishment = 'e1', ?array $body = null, array $headers = []): array
    {
        $headers += ['Authorization' => 'Bearer ' . $user . ':' . $tenant];
        if ($establishment !== null) {
            $headers['X-Establishment-Id'] = $establishment;
        }
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        $response = $this->call($method, '/api/v1' . $path, $headers, $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR));
        $raw = (string) $response->getBody();
        $json = str_contains($response->getHeaderLine('Content-Type'), 'json') && $raw !== '' ? (array) json_decode($raw, true, flags: JSON_THROW_ON_ERROR) : [];

        return [$response, $json];
    }

    /**
     * @param array<string, scalar|null> $data
     * @return array<string, mixed> document créé par POST /documents
     */
    protected function issueViaApi(string $subject = 'p1', string $type = 'attestation_simple', string $key = 'cle-emission-0001', array $data = ['academic_year' => '2026-2027', 'class_name' => '3eA']): array
    {
        [$response, $json] = $this->api('POST', '/documents', 'alice', 't1', 'e1', ['type' => $type, 'subject_person_id' => $subject, 'data' => $data], ['Idempotency-Key' => $key]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return $json['data'];
    }

    protected function publicVerify(string $token): ResponseInterface
    {
        return $this->call('GET', '/api/v1/public/verify/' . $token);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Support\People;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use Psr\Http\Message\ResponseInterface;
use Scholaris\Core\RateLimit\Clock;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Modules\Socle\Organisation\Contracts\EnrolmentGate;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\People\Application\PersonService;
use Scholaris\Modules\Socle\People\Contracts\TeachingScope;
use Tests\Support\AppTestCase;

/**
 * Base des tests du module People : Kernel de test (SQLite en mémoire migrée), horloge figée, clé secrète et dossier
 * de stockage temporaires, doublures de PermissionChecker, de barrière d'inscription et de périmètre enseignant.
 * Client « t1 », établissement « e1 » : « admin » gère les dossiers et lit la santé.
 */
abstract class PeopleTestCase extends AppTestCase
{
    protected Permissions $permissions;
    protected FixedClock $clock;
    protected OpenStructures $structures;
    protected FixedTeaching $teaching;
    protected FixedSettings $settings;
    protected string $storage = '';

    /** @param array<string, mixed> $extra définitions supplémentaires (extensions des applications, doublures) */
    protected function bootPeople(array $extra = []): void
    {
        $this->settings = new FixedSettings();
        $this->permissions = new Permissions();
        $this->clock = new FixedClock();
        $this->structures = new OpenStructures();
        $this->teaching = new FixedTeaching();
        $this->storage = sys_get_temp_dir() . '/scholaris-people-' . bin2hex(random_bytes(6));
        $this->boot($extra + [
            PermissionChecker::class => $this->permissions,
            Clock::class => $this->clock,
            EnrolmentGate::class => $this->structures,
            SettingsReader::class => $this->settings,
            EstablishmentDirectory::class => new OneEstablishment(),
            TeachingScope::class => $this->teaching,
        ], ['people' => ['secret_key' => 'cle-de-test-people', 'storage_path' => $this->storage]]);

        $this->access->grant('admin', 'e1');
        $this->permissions->grant('admin', 'people.read', 'people.manage', 'people.health.read');
    }

    protected function tearDown(): void
    {
        if ($this->storage !== '' && is_dir($this->storage)) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->storage, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->storage);
        }
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{0: ResponseInterface, 1: array<string, mixed>}
     */
    protected function api(string $method, string $path, string $user = 'admin', string $tenant = 't1', ?string $establishment = 'e1', ?array $body = null): array
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
    protected function code(string $method, string $path, string $user = 'admin', string $tenant = 't1', ?string $establishment = 'e1', ?array $body = null): int
    {
        return $this->api($method, $path, $user, $tenant, $establishment, $body)[0]->getStatusCode();
    }

    /**
     * Envoi multipart : champs de formulaire et un fichier « file ».
     *
     * @param array<string, string> $fields
     * @return array{0: ResponseInterface, 1: array<string, mixed>}
     */
    protected function upload(string $path, string $content, array $fields = [], string $user = 'admin', string $tenant = 't1'): array
    {
        $request = (new ServerRequest('POST', '/api/v1' . $path, [
            'Authorization' => 'Bearer ' . $user . ':' . $tenant,
            'X-Establishment-Id' => 'e1',
        ]))
            ->withParsedBody($fields)
            ->withUploadedFiles(['file' => new UploadedFile(Stream::create($content), strlen($content), UPLOAD_ERR_OK, 'nom-client.exe', 'application/x-msdownload')]);
        $response = $this->kernel->handle($request);
        $raw = (string) $response->getBody();

        return [$response, $raw === '' || !str_contains($response->getHeaderLine('Content-Type'), 'json') ? [] : (array) json_decode($raw, true, flags: JSON_THROW_ON_ERROR)];
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    protected function person(string $first, string $last, array $values = [], string $tenant = 't1'): array
    {
        return $this->kernel->container()->get(PersonService::class)->create($tenant, ['first_name' => $first, 'last_name' => $last] + $values);
    }

    /** Compte utilisateur lié à une personne (table users d'Identity). */
    protected function account(string $userId, string $personId, string $tenant = 't1'): void
    {
        $this->access->grant($userId, 'e1');
        $this->connections()->pdo()->prepare(
            'INSERT INTO users (id, tenant_id, person_id, phone, locale, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([$userId, $tenant, $personId, null, 'fr', 'active', '2026-10-08T09:00:00+01:00', '2026-10-08T09:00:00+01:00']);
    }

    protected function pdfContent(string $extra = ''): string
    {
        return "%PDF-1.4\n1 0 obj<<>>endobj\n" . $extra . "\n%%EOF";
    }
}

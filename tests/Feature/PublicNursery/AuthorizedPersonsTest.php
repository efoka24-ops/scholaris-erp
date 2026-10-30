<?php

declare(strict_types=1);

namespace Tests\Feature\PublicNursery;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Scholaris\Modules\Socle\People\Application\PersonService;
use Scholaris\Modules\Socle\People\Contracts\MediaRef;
use Scholaris\Modules\Socle\People\Contracts\MediaStore;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Tests\Support\Organisation\OrganisationTestCase;

final class AuthorizedPersonsTest extends OrganisationTestCase
{
    private string $storage = '';

    protected function setUp(): void
    {
        $_ENV['APP_KEY'] = $_SERVER['APP_KEY'] = 'pn-pickup-test-secret';
        $this->storage = sys_get_temp_dir() . '/scholaris-pn-pickup-' . bin2hex(random_bytes(4));
        @mkdir($this->storage . '/uploads', 0o777, true);
        $this->bootOrganisation([
            ApplicationAccess::class => new class () implements ApplicationAccess {
                public function state(string $tenantId, string $appKey): ?string
                {
                    return self::ACTIVE;
                }
                public function allowsWrites(string $tenantId, string $appKey): bool
                {
                    return true;
                }
                public function allowsReads(string $tenantId, string $appKey): bool
                {
                    return true;
                }
            },
        ]);
        $this->seedEstablishment('tenant-dev', 'est-dev', 'Maternelle publique pilote', 'nursery', 'teacher', 'parent-user', 'other-parent');
        $this->grantAll('teacher', 'attendance.discipline.manage', 'public_nursery.pickup.handover');
    }

    protected function tearDown(): void
    {
        if ($this->storage !== '' && is_dir($this->storage)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->storage, \FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $file) {
                $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
            }
            @rmdir($this->storage);
        }
        parent::tearDown();
    }

    public function testLeParentNeGereQueSesEnfantsEtLaPhotoPasseParMediaStore(): void
    {
        $parent = $this->person('Marie', 'Meka');
        $child = $this->person('Lina', 'Meka');
        $otherParent = $this->person('Paul', 'Nko');
        $otherChild = $this->person('Eva', 'Nko');
        $this->linkUser('parent-user', (string) $parent['id']);
        $this->linkUser('other-parent', (string) $otherParent['id']);
        $this->linkParent((string) $child['id'], (string) $parent['id']);
        $this->linkParent((string) $otherChild['id'], (string) $otherParent['id']);
        $this->enrol((string) $child['id']);
        $this->enrol((string) $otherChild['id']);

        [$created, $body] = $this->api('POST', '/public-nursery/portal/pickups', 'parent-user', 'tenant-dev', 'est-dev', [
            'learner_id' => $child['id'],
            'name' => 'Tante Rose',
            'relationship' => 'tante',
            'phone' => '+237670000222',
            'grant_type' => 'permanent',
            'photo_content_base64' => base64_encode($this->jpeg('pickup-photo')),
        ]);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        self::assertNotNull($body['data']['photo_document_ref']);
        self::assertStringStartsWith('public-nursery.', $body['data']['photo_document_ref']);

        [, $list] = $this->api('GET', '/public-nursery/portal/pickups?learner_id=' . $child['id'], 'parent-user', 'tenant-dev', 'est-dev');
        self::assertCount(1, $list['data']);
        self::assertSame('Tante Rose', $list['data'][0]['pickup_name']);

        self::assertSame(404, $this->code('GET', '/public-nursery/portal/pickups?learner_id=' . $otherChild['id'], 'parent-user', 'tenant-dev', 'est-dev'));
        self::assertSame(404, $this->code('POST', '/public-nursery/portal/pickups', 'parent-user', 'tenant-dev', 'est-dev', [
            'learner_id' => $otherChild['id'], 'name' => 'Intrus', 'relationship' => 'ami', 'grant_type' => 'permanent',
        ]));

        $ref = new MediaRef((string) $body['data']['photo_document_ref']);
        $url = $this->kernel->container()->get(MediaStore::class)->signedUrl('tenant-dev', $ref, 300, '/api/v1/public-nursery/pickup/photo');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $media = $this->kernel->container()->get(MediaStore::class)->readSigned('tenant-dev', $ref, (int) $query['expires'], (string) $query['signature']);
        self::assertSame('image/jpeg', $media->mime);
        self::assertStringContainsString('pickup-photo', $media->content);
    }

    public function testLePersonnelPeutListerEtRevoquerParLaCoucheMaternelle(): void
    {
        $child = $this->person('Lina', 'Meka');
        $this->enrol((string) $child['id']);
        $created = $this->api('POST', '/public-nursery/pickup/authorized-persons', 'teacher', 'tenant-dev', 'est-dev', [
            'learner_id' => $child['id'], 'name' => 'Oncle Marc', 'relationship' => 'oncle', 'grant_type' => 'day', 'valid_on' => '2026-10-08',
        ])[1]['data'];

        [, $list] = $this->api('GET', '/public-nursery/pickup/authorized-persons?learner_id=' . $child['id'], 'teacher', 'tenant-dev', 'est-dev');
        self::assertSame([$created['id']], array_column($list['data'], 'id'));

        [, $revoked] = $this->api('POST', '/public-nursery/pickup/authorized-persons/' . $created['id'] . '/revoke', 'teacher', 'tenant-dev', 'est-dev');
        self::assertSame('revoked', $revoked['data']['status']);
    }

    /** @return array<string, mixed> */
    private function person(string $first, string $last): array
    {
        return $this->kernel->container()->get(PersonService::class)->create('tenant-dev', ['first_name' => $first, 'last_name' => $last, 'birth_date' => '2021-01-01']);
    }

    private function linkUser(string $userId, string $personId): void
    {
        $now = '2026-10-08T09:00:00+01:00';
        $this->connections()->pdo()->prepare('INSERT INTO users (id, tenant_id, person_id, locale, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$userId, 'tenant-dev', $personId, 'fr', 'active', $now, $now]);
    }

    private function linkParent(string $learnerId, string $parentId): void
    {
        $this->connections()->pdo()->prepare('INSERT INTO person_relations (id, tenant_id, person_id, related_person_id, kind, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([(string) new \Scholaris\Core\Support\Ulid(), 'tenant-dev', $learnerId, $parentId, 'financial_guarantor', 'admin', '2026-10-08T09:00:00+01:00']);
    }

    private function enrol(string $personId): void
    {
        $now = '2026-10-08T09:00:00+01:00';
        $this->connections()->pdo()->prepare('INSERT INTO enrollments (id, tenant_id, person_id, establishment_id, structure_id, academic_year_id, status, completeness, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 100, ?, ?)')
            ->execute([(string) new \Scholaris\Core\Support\Ulid(), 'tenant-dev', $personId, 'est-dev', 'section-a', 'year-2026', 'active', $now, $now]);
    }

    private function jpeg(string $extra = '', int $pad = 64): string
    {
        return "\xFF\xD8\xFF\xE0" . str_repeat('J', $pad) . $extra;
    }
}

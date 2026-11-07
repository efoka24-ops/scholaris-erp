<?php

declare(strict_types=1);

namespace Tests\Feature\PublicNursery;

use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\PublicNursery\Development\Application\LifeBookService;
use Scholaris\Modules\Apps\PublicNursery\Development\Application\ProgressBookService;
use Scholaris\Modules\Socle\People\Application\PersonService;
use Scholaris\Modules\Socle\People\Contracts\ConsentChecker;
use Scholaris\Modules\Socle\People\Contracts\ConsentRecorder;
use Tests\Support\Organisation\OrganisationTestCase;

final class ParentDevelopmentPortalTest extends OrganisationTestCase
{
    public function testLePortailFamilleExposeLeCarnetPublieEtLeCahierDeVieFiltre(): void
    {
        $_ENV['APP_KEY'] = $_SERVER['APP_KEY'] = 'pn-portal-test-secret';
        $this->bootOrganisation();
        $this->seedEstablishment('tenant-dev', 'est-dev', 'École pilote', 'nursery');

        $parent = $this->person('Marie', 'Meka', '1986-02-03');
        $child = $this->person('Lina', 'Meka', '2021-05-16');
        $otherChild = $this->person('Noa', 'Meka', '2020-08-10');
        $this->linkUser('parent-user', (string) $parent['id']);
        $this->linkParent((string) $child['id'], (string) $parent['id']);
        $this->linkParent((string) $otherChild['id'], (string) $parent['id']);
        $this->enrol((string) $child['id'], 'est-dev', 'section-a');
        $this->enrol((string) $otherChild['id'], 'est-dev', 'section-b');
        $this->kernel->container()->get(ConsentRecorder::class)->grant('tenant-dev', (string) $child['id'], ConsentChecker::PHOTO, 'admin', 'scan-2026-10-16');

        $book = $this->kernel->container()->get(ProgressBookService::class)->saveDraft('tenant-dev', 'est-dev', (string) $child['id'], 'section-a', 'period-1', '2026-2027', [
            ['domain' => 'Langage', 'label' => 'S’exprimer', 'level' => 'acquired', 'comment' => 'Participe volontiers'],
        ], 'Expression positive et participation régulière.');
        $this->kernel->container()->get(ProgressBookService::class)->validate('tenant-dev', 'est-dev', (string) $book['id'], 'director');
        $this->kernel->container()->get(ProgressBookService::class)->publish('tenant-dev', 'est-dev', (string) $book['id'], 'director');

        $lifeBook = $this->kernel->container()->get(LifeBookService::class);
        $entry = $lifeBook->saveDraft('tenant-dev', 'est-dev', 'section-a', 'teacher', [
            'title' => 'Atelier peinture',
            'body' => 'Découverte des couleurs.',
            'activity_on' => '2026-10-10',
        ]);
        $withheld = $lifeBook->attachMedia('tenant-dev', 'est-dev', (string) $entry['id'], [
            'content' => $this->jpeg('withheld'),
            'mime_type' => 'image/jpeg',
            'tagging_done' => true,
            'no_child_visible' => false,
            'child_ids' => [(string) $child['id'], (string) $otherChild['id']],
        ]);
        $masked = $lifeBook->attachMedia('tenant-dev', 'est-dev', (string) $entry['id'], [
            'content' => $this->jpeg('masked'),
            'mime_type' => 'image/jpeg',
            'tagging_done' => true,
            'no_child_visible' => true,
            'masked_from_id' => $withheld['id'],
            'child_ids' => [],
        ]);
        $lifeBook->publish('tenant-dev', 'est-dev', (string) $entry['id']);

        [$response, $json] = $this->api('GET', '/portal/family', 'parent-user', 'tenant-dev');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $children = [];
        foreach ($json['data']['children'] as $row) {
            $children[$row['person']['id']] = $row;
        }

        self::assertArrayHasKey((string) $child['id'], $children);
        self::assertSame('Expression positive et participation régulière.', $children[(string) $child['id']]['sections']['progress_book']['book']['synthesis']);
        self::assertSame('period-1', $children[(string) $child['id']]['sections']['progress_book']['book']['period_id']);
        self::assertCount(1, $children[(string) $child['id']]['sections']['life_book']['items']);
        self::assertSame('Atelier peinture', $children[(string) $child['id']]['sections']['life_book']['items'][0]['title']);
        self::assertSame([$masked['id']], array_column($children[(string) $child['id']]['sections']['life_book']['items'][0]['media'], 'id'));
        self::assertStringStartsWith('/api/v1/public-nursery/development/lifebook/', $children[(string) $child['id']]['sections']['life_book']['items'][0]['media'][0]['media_url']);

        self::assertArrayHasKey((string) $otherChild['id'], $children);
        self::assertArrayNotHasKey('sections', $children[(string) $otherChild['id']]);
    }

    /** @return array<string, mixed> */
    private function person(string $first, string $last, string $birthDate): array
    {
        return $this->kernel->container()->get(PersonService::class)->create('tenant-dev', [
            'first_name' => $first,
            'last_name' => $last,
            'birth_date' => $birthDate,
        ]);
    }

    private function linkUser(string $userId, string $personId): void
    {
        $now = '2026-10-08T09:00:00+01:00';
        $this->connections()->pdo()->prepare(
            'INSERT INTO users (id, tenant_id, person_id, locale, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
        )->execute([$userId, 'tenant-dev', $personId, 'fr', 'active', $now, $now]);
    }

    private function linkParent(string $learnerId, string $parentId): void
    {
        $this->connections()->pdo()->prepare(
            'INSERT INTO person_relations (id, tenant_id, person_id, related_person_id, kind, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
        )->execute([(string) new Ulid(), 'tenant-dev', $learnerId, $parentId, 'financial_guarantor', 'admin', '2026-10-08T09:00:00+01:00']);
    }

    private function enrol(string $personId, string $establishmentId, string $structureId): void
    {
        $now = '2026-10-08T09:00:00+01:00';
        $this->connections()->pdo()->prepare(
            'INSERT INTO enrollments (id, tenant_id, person_id, establishment_id, structure_id, academic_year_id, status, completeness, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 100, ?, ?)',
        )->execute([(string) new Ulid(), 'tenant-dev', $personId, $establishmentId, $structureId, 'year-2026', 'active', $now, $now]);
    }

    private function jpeg(string $extra = '', int $pad = 64): string
    {
        return "\xFF\xD8\xFF\xDB\x00C\x00" . str_pad($extra, $pad, '0') . "\xFF\xD9";
    }
}

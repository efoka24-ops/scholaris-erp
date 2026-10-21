<?php

declare(strict_types=1);

namespace Tests\Feature\PublicNursery;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\PublicNursery\Development\Application\LifeBookService;
use Scholaris\Modules\Socle\People\Application\ConsentService;
use Scholaris\Modules\Socle\People\Application\PersonService;
use Scholaris\Modules\Socle\People\Contracts\ConsentChecker;
use Scholaris\Modules\Socle\People\Contracts\ConsentRecorder;
use Tests\Support\Organisation\OrganisationTestCase;

final class LifeBookTest extends OrganisationTestCase
{
    public function testLeCahierDeVieResteInvisibleAvantPublicationEtFiltreLeConsentementALaLecture(): void
    {
        $_ENV['APP_KEY'] = $_SERVER['APP_KEY'] = 'life-book-test-secret';
        $this->bootOrganisation();
        $this->seedEstablishment('tenant-dev', 'est-dev', 'École pilote', 'nursery', 'teacher');
        $this->grantAll('teacher', 'public_nursery.lifebook.write', 'public_nursery.lifebook.view');

        $childWithConsent = $this->createPerson('Lina', 'Meka', 'tenant-dev');
        $childWithoutConsent = $this->createPerson('Noa', 'Meka', 'tenant-dev');
        $this->seedConsent($childWithConsent['id']);

        $service = $this->kernel->container()->get(LifeBookService::class);
        $entry = $service->saveDraft('tenant-dev', 'est-dev', 'section-a', 'teacher', [
            'title' => 'Atelier du matin',
            'body' => 'Peinture et motricité.',
            'activity_on' => '2026-10-08',
        ]);

        self::assertSame('draft', $entry['status']);
        self::assertNull($service->find('tenant-dev', 'est-dev', (string) $entry['id']));

        $original = $service->attachMedia('tenant-dev', 'est-dev', (string) $entry['id'], [
            'content' => $this->jpeg('original'),
            'mime_type' => 'image/jpeg',
            'tagging_done' => true,
            'no_child_visible' => false,
            'child_ids' => [(string) $childWithConsent['id'], (string) $childWithoutConsent['id']],
        ]);
        $masked = $service->attachMedia('tenant-dev', 'est-dev', (string) $entry['id'], [
            'content' => $this->jpeg('mask'),
            'mime_type' => 'image/jpeg',
            'tagging_done' => true,
            'no_child_visible' => true,
            'masked_from_id' => $original['id'],
            'child_ids' => [],
        ]);
        $visible = $service->attachMedia('tenant-dev', 'est-dev', (string) $entry['id'], [
            'content' => $this->jpeg('visible'),
            'mime_type' => 'image/jpeg',
            'tagging_done' => true,
            'no_child_visible' => false,
            'child_ids' => [(string) $childWithConsent['id']],
        ]);

        $published = $service->publish('tenant-dev', 'est-dev', (string) $entry['id']);
        self::assertSame('published', $published['status']);

        $all = $service->all('tenant-dev', 'est-dev', 'section-a');
        self::assertCount(1, $all);
        self::assertCount(2, $all[0]['media']);
        self::assertSame([$masked['id'], $visible['id']], array_column($all[0]['media'], 'id'));

        $signed = $service->mediaUrl('tenant-dev', 'est-dev', (string) $entry['id'], (string) $visible['id']);
        self::assertStringStartsWith('/api/v1/public-nursery/development/lifebook/', $signed);

        $link = $this->link($signed);
        $content = $service->readMedia('tenant-dev', 'est-dev', (string) $entry['id'], (string) $visible['id'], $link['expires'], $link['signature']);
        self::assertSame('image/jpeg', $content->mime);

        $consent = $this->kernel->container()->get(ConsentService::class)->forPerson('tenant-dev', (string) $childWithConsent['id'], ConsentChecker::PHOTO)[0]['id'];
        $this->kernel->container()->get(ConsentRecorder::class)->revoke('tenant-dev', $consent, 'admin');

        $afterRevoke = $service->find('tenant-dev', 'est-dev', (string) $entry['id']);
        self::assertCount(1, $afterRevoke['media']);
        self::assertSame($masked['id'], $afterRevoke['media'][0]['id']);
        self::assertSame(404, $this->statusFor(fn () => $service->readMedia('tenant-dev', 'est-dev', (string) $entry['id'], (string) $visible['id'], $link['expires'], $link['signature'])));
    }

    public function testUnePhotoNonEtiqueteeBloqueLaPublication(): void
    {
        $_ENV['APP_KEY'] = $_SERVER['APP_KEY'] = 'life-book-test-secret';
        $this->bootOrganisation();
        $this->seedEstablishment('tenant-dev', 'est-dev', 'École pilote', 'nursery', 'teacher');
        $this->grantAll('teacher', 'public_nursery.lifebook.write');

        $service = $this->kernel->container()->get(LifeBookService::class);
        $entry = $service->saveDraft('tenant-dev', 'est-dev', 'section-a', 'teacher', [
            'title' => 'Atelier du soir',
            'body' => 'Essai de publication.',
            'activity_on' => '2026-10-08',
        ]);
        $service->attachMedia('tenant-dev', 'est-dev', (string) $entry['id'], [
            'content' => $this->jpeg('draft'),
            'mime_type' => 'image/jpeg',
            'tagging_done' => false,
            'no_child_visible' => false,
            'child_ids' => [],
        ]);

        try {
            $service->publish('tenant-dev', 'est-dev', (string) $entry['id']);
            self::fail('La publication devait être refusée.');
        } catch (HttpException $e) {
            self::assertSame(422, $e->status);
        }
    }

    private function seedConsent(string $personId, string $tenant = 'tenant-dev'): void
    {
        $this->kernel->container()->get(ConsentRecorder::class)->grant($tenant, $personId, ConsentChecker::PHOTO, 'admin', 'scan-2026-10-16');
    }

    /** @return array<string, mixed> */
    private function createPerson(string $first, string $last, string $tenant = 'tenant-dev'): array
    {
        return $this->kernel->container()->get(PersonService::class)->create($tenant, ['first_name' => $first, 'last_name' => $last]);
    }

    private function jpeg(string $extra = '', int $pad = 64): string
    {
        return "\xFF\xD8\xFF\xDB\x00C\x00" . str_pad($extra, $pad, '0') . "\xFF\xD9";
    }

    /** @return array{tenant: string, ref: string, expires: int, signature: string} */
    private function link(string $url): array
    {
        $parts = parse_url($url);
        parse_str((string) ($parts['query'] ?? ''), $query);

        return [
            'tenant' => (string) ($query['tenant'] ?? ''),
            'ref' => (string) ($query['ref'] ?? ''),
            'expires' => (int) ($query['expires'] ?? 0),
            'signature' => (string) ($query['signature'] ?? ''),
        ];
    }

    /** @return array{status: int, body: string} */
    private function statusFor(callable $action): int
    {
        try {
            $action();

            return 200;
        } catch (HttpException $e) {
            return $e->status;
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\PublicNursery;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\PublicNursery\Development\Application\LifeBookService;
use Scholaris\Modules\Apps\PublicNursery\Development\Console\PurgeMediaCommand;
use Scholaris\Modules\Socle\People\Contracts\MediaRef;
use Scholaris\Modules\Socle\People\Contracts\MediaStore;
use Tests\Support\Organisation\OrganisationTestCase;

final class PurgeMediaTest extends OrganisationTestCase
{
    public function testLaPurgeSupprimeSeulementLesMédiasÀEchéanceEtResteIdempotente(): void
    {
        $_ENV['APP_KEY'] = $_SERVER['APP_KEY'] = 'purge-media-test-secret';
        $this->bootOrganisation();
        $this->seedEstablishment('tenant-dev', 'est-dev', 'École pilote', 'nursery', 'teacher');
        $this->grantAll('teacher', 'public_nursery.lifebook.write', 'public_nursery.lifebook.view');

        $service = $this->kernel->container()->get(LifeBookService::class);
        $store = $this->kernel->container()->get(MediaStore::class);

        $old = $this->createPublishedEntry($service, 'teacher', '2025-10-16', 'ancienne scène');
        $fresh = $this->createPublishedEntry($service, 'teacher', '2026-10-16', 'scène fraîche');

        $oldMedia = $this->mediaRow((string) $old['id']);
        $freshMedia = $this->mediaRow((string) $fresh['id']);
        $oldLink = $service->mediaUrl('tenant-dev', 'est-dev', (string) $old['id'], (string) $oldMedia['id']);
        $freshLink = $service->mediaUrl('tenant-dev', 'est-dev', (string) $fresh['id'], (string) $freshMedia['id']);
        $oldParts = $this->link($oldLink);
        $freshParts = $this->link($freshLink);

        ob_start();
        $command = $this->kernel->container()->get('console.command.' . PurgeMediaCommand::NAME);
        $first = $command->run(['2026-10-15']);
        $firstOutput = (string) ob_get_clean();
        self::assertSame(0, $first);
        self::assertStringContainsString('0 ligne(s) supprimée(s)', $firstOutput);
        self::assertCount(1, $service->find('tenant-dev', 'est-dev', (string) $old['id'])['media']);
        self::assertCount(1, $service->find('tenant-dev', 'est-dev', (string) $fresh['id'])['media']);

        ob_start();
        $second = $command->run(['2026-10-16']);
        $secondOutput = (string) ob_get_clean();
        self::assertSame(0, $second);
        self::assertStringContainsString('1 ligne(s) supprimée(s)', $secondOutput);

        $oldAfter = $service->find('tenant-dev', 'est-dev', (string) $old['id']);
        $freshAfter = $service->find('tenant-dev', 'est-dev', (string) $fresh['id']);
        self::assertCount(0, $oldAfter['media']);
        self::assertCount(1, $freshAfter['media']);

        self::assertSame(404, $this->statusFor(fn () => $store->readSigned('tenant-dev', new MediaRef((string) $oldMedia['media_ref']), $oldParts['expires'], $oldParts['signature'])));
        self::assertSame('image/jpeg', $store->readSigned('tenant-dev', new MediaRef((string) $freshMedia['media_ref']), $freshParts['expires'], $freshParts['signature'])->mime);

        ob_start();
        self::assertSame(0, $command->run(['2026-10-16']));
        self::assertStringContainsString('0 ligne(s) supprimée(s)', (string) ob_get_clean());
    }

    private function createPublishedEntry(LifeBookService $service, string $authorId, string $activityOn, string $suffix): array
    {
        $entry = $service->saveDraft('tenant-dev', 'est-dev', 'section-a', $authorId, [
            'title' => 'Sortie ' . $suffix,
            'body' => 'Contenu ' . $suffix,
            'activity_on' => $activityOn,
        ]);
        $media = $service->attachMedia('tenant-dev', 'est-dev', (string) $entry['id'], [
            'content' => $this->jpeg($suffix),
            'mime_type' => 'image/jpeg',
            'tagging_done' => true,
            'no_child_visible' => true,
            'child_ids' => [],
        ]);
        $service->publish('tenant-dev', 'est-dev', (string) $entry['id']);

        return $entry + ['media' => [$media]];
    }

    /** @return array<string, mixed> */
    private function mediaRow(string $entryId): array
    {
        $row = $this->connections()->pdo()->query("SELECT * FROM pn_lifebook_media WHERE entry_id = '" . $entryId . "' ORDER BY created_at LIMIT 1")->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : throw new \RuntimeException('Média introuvable pour le test.');
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

    private function jpeg(string $extra = '', int $pad = 64): string
    {
        return "\xFF\xD8\xFF\xDB\x00C\x00" . str_pad($extra, $pad, '0') . "\xFF\xD9";
    }

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

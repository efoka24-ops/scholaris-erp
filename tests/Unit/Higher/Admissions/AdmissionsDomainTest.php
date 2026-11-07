<?php

declare(strict_types=1);

namespace Tests\Unit\Higher\Admissions;

use PHPUnit\Framework\TestCase;
use Scholaris\Modules\Apps\Higher\Admissions\Domain\Prerequisites;
use Scholaris\Modules\Apps\Higher\Admissions\Domain\Ranking;

final class AdmissionsDomainTest extends TestCase
{
    public function testPrerequisParDefautSelonLeNiveau(): void
    {
        self::assertSame(['bac', 'gce_al'], Prerequisites::defaultFor(1)->diplomas);
        self::assertSame(['bts', 'hnd', 'licence'], Prerequisites::defaultFor(3)->diplomas);
        self::assertSame([], Prerequisites::defaultFor(1)->unmetBy('gce_al', null, null, null));
        self::assertNotSame([], Prerequisites::defaultFor(3)->unmetBy('bac', null, null, null));
    }

    public function testSerieAnneeEtMention(): void
    {
        $rules = new Prerequisites(['bac'], ['C', 'D'], 2024, 'assez_bien');

        self::assertSame([], $rules->unmetBy('bac', 'c', 2025, 'bien'));
        self::assertCount(3, $rules->unmetBy('bac', 'A4', 2020, 'passable'));
        self::assertCount(1, $rules->unmetBy('bac', 'D', 2025, null));
    }

    public function testPiecesExigeesDoiventJustifierLesPrerequis(): void
    {
        [, $errors] = Prerequisites::normalizeDocuments([['code' => 'photo', 'label' => 'Photo']]);
        self::assertNotSame([], $errors);
        [$documents, $errors] = Prerequisites::normalizeDocuments(Prerequisites::defaultDocuments());
        self::assertSame([], $errors);
        self::assertCount(4, $documents);
    }

    public function testClassementAvecExAequo(): void
    {
        self::assertSame(
            ['a' => ['rank' => 1, 'list' => 'main'], 'b' => ['rank' => 2, 'list' => 'main'], 'c' => ['rank' => 2, 'list' => 'main'], 'd' => ['rank' => 4, 'list' => 'waiting']],
            Ranking::rank(['d' => 900, 'b' => 1200, 'a' => 1500, 'c' => 1200], 2),
        );
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Organisation;

use PHPUnit\Framework\TestCase;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Organisation\Domain\EstablishmentType;
use Scholaris\Modules\Socle\Organisation\Domain\PeriodKind;
use Scholaris\Modules\Socle\Organisation\Domain\StructureKind;
use Scholaris\Modules\Socle\Organisation\Http\Requests\AcademicYearRequest;
use Scholaris\Modules\Socle\Organisation\Http\Requests\CreateEstablishmentRequest;
use Scholaris\Modules\Socle\Organisation\Http\Requests\UpdateBrandingRequest;
use Scholaris\Modules\Socle\Organisation\Http\Requests\UpdateEstablishmentRequest;
use Scholaris\Modules\Socle\Organisation\Http\Requests\UpdateModulesRequest;

final class RequestValidationTest extends TestCase
{
    /** @param callable(): mixed $call @return array<string, list<string>> */
    private function errors(callable $call): array
    {
        try {
            $call();
        } catch (HttpException $e) {
            self::assertSame(422, $e->status);

            return $e->errors;
        }
        self::fail('Une erreur 422 était attendue.');
    }

    public function testChampInconnuRefuse(): void
    {
        $errors = $this->errors(fn () => (new CreateEstablishmentRequest())->validate(['name' => 'Lycée', 'type' => 'secondary', 'tenant_id' => 'autre']));

        self::assertSame(['tenant_id' => ['Champ non autorisé.']], $errors);
    }

    public function testChampsObligatoiresEtTypeInconnu(): void
    {
        $errors = $this->errors(fn () => (new CreateEstablishmentRequest())->validate(['type' => 'universite']));

        self::assertArrayHasKey('name', $errors);
        self::assertStringContainsString('nursery', $errors['type'][0]);
    }

    public function testLeTypeNEstJamaisModifiable(): void
    {
        $errors = $this->errors(fn () => (new UpdateEstablishmentRequest())->validate(['name' => 'Lycée', 'type' => 'higher']));

        self::assertStringContainsString('définitif', $errors['type'][0]);
    }

    public function testDatesInexistantesEtOrdreDesDates(): void
    {
        $errors = $this->errors(fn () => (new AcademicYearRequest())->validate(['label' => '2026-2027', 'starts_on' => '2026-02-30', 'ends_on' => '2027-06-30']));
        self::assertArrayHasKey('starts_on', $errors);

        $errors = $this->errors(fn () => (new AcademicYearRequest())->validate(['label' => '2026-2027', 'starts_on' => '2027-06-30', 'ends_on' => '2026-09-01']));
        self::assertArrayHasKey('ends_on', $errors);

        self::assertSame(
            ['label' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30'],
            (new AcademicYearRequest())->validate(['label' => ' 2026-2027 ', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30']),
        );
    }

    public function testModulesInconnusEtDoublonsEcartes(): void
    {
        $errors = $this->errors(fn () => (new UpdateModulesRequest())->validate(['modules' => ['billing', 'telepathie']]));
        self::assertArrayHasKey('modules', $errors);

        self::assertSame(['modules' => ['billing', 'documents']], (new UpdateModulesRequest())->validate(['modules' => ['billing', 'documents', 'billing']]));
    }

    public function testLogoEstControleEtPropreAuClientEtALEtablissement(): void
    {
        $request = (new UpdateBrandingRequest())->forEstablishment('t1', 'e1');
        $prefix = 'tenants/t1/establishments/e1/logo/';
        $valid = ['path' => $prefix . 'logo.png', 'mime' => 'image/png', 'size' => 1024];

        self::assertSame(['logo' => $valid], $request->validate(['logo' => $valid]));
        self::assertSame(['logo' => null], $request->validate(['logo' => null]));

        $bad = [
            'chemin d\'un autre client' => ['path' => 'tenants/t2/establishments/e1/logo/logo.png'] + $valid,
            'remontée de dossier' => ['path' => $prefix . '../../secret.png'] + $valid,
            'extension incohérente' => ['path' => $prefix . 'logo.php'] + $valid,
            'type non autorisé' => ['mime' => 'application/pdf'] + $valid,
            'trop volumineux' => ['size' => UpdateBrandingRequest::LOGO_MAX_BYTES + 1] + $valid,
            'taille nulle' => ['size' => 0] + $valid,
        ];
        foreach ($bad as $label => $logo) {
            self::assertArrayHasKey('logo', $this->errors(fn () => $request->validate(['logo' => $logo])), $label);
        }

        // Sans contexte (aucun établissement indiqué), aucun chemin n'est accepté.
        self::assertArrayHasKey('logo', $this->errors(fn () => (new UpdateBrandingRequest())->validate(['logo' => $valid])));
    }

    public function testLesRegleDeNatureSelonLeTypeDEtablissement(): void
    {
        self::assertTrue(PeriodKind::Semester->allowedFor(EstablishmentType::Higher));
        self::assertFalse(PeriodKind::Trimester->allowedFor(EstablishmentType::Higher));
        self::assertFalse(PeriodKind::Semester->allowedFor(EstablishmentType::Secondary));
        self::assertTrue(PeriodKind::Sequence->allowedFor(EstablishmentType::Primary));
        self::assertFalse(PeriodKind::Year->allowedFor(EstablishmentType::Higher));
        self::assertTrue(PeriodKind::Year->allowedFor(EstablishmentType::Secondary));
        self::assertSame(PeriodKind::Year, PeriodKind::Trimester->parentKind());
        self::assertSame(PeriodKind::Trimester, PeriodKind::Sequence->parentKind());
        self::assertNull(PeriodKind::Year->parentKind());
        self::assertNull(PeriodKind::Semester->parentKind());
        self::assertTrue(PeriodKind::Sequence->requiresParent());
        self::assertFalse(PeriodKind::Trimester->requiresParent());
        self::assertTrue(StructureKind::Track->allowedFor(EstablishmentType::Higher));
        self::assertFalse(StructureKind::Track->allowedFor(EstablishmentType::Nursery));
        self::assertTrue(StructureKind::SchoolClass->allowedFor(EstablishmentType::Nursery));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Assessment;

use PHPUnit\Framework\TestCase;
use Scholaris\Modules\Socle\Assessment\Application\AssessmentSettings;
use Tests\Support\Assessment\ArraySettings;

final class QualitativeScaleTest extends TestCase
{
    public function testDefaultScalePreservesHistoricalCodes(): void
    {
        $settings = new AssessmentSettings(new ArraySettings());
        self::assertSame(['acquired', 'in_progress', 'not_acquired'], array_column($settings->qualitativeScale('t', 'e'), 'code'));
    }

    public function testConfiguredScaleIsAcceptedAndSorted(): void
    {
        $settings = new AssessmentSettings(new ArraySettings(['assessment.qualitative_scale' => [
            ['code' => 'excellent', 'label_fr' => 'Excellent', 'label_en' => 'Excellent', 'sort_order' => 2],
            ['code' => 'developing', 'label_fr' => 'En développement', 'label_en' => 'Developing', 'sort_order' => 1],
        ]]));
        self::assertSame(['developing', 'excellent'], array_column($settings->qualitativeScale('t', 'e'), 'code'));
    }

    public function testInvalidScaleFallsBackToHistoricalDefault(): void
    {
        $settings = new AssessmentSettings(new ArraySettings(['assessment.qualitative_scale' => [
            ['code' => 'Bad code', 'label_fr' => 'Invalide', 'label_en' => 'Invalid'],
        ]]));
        self::assertSame(['acquired', 'in_progress', 'not_acquired'], array_column($settings->qualitativeScale('t', 'e'), 'code'));
    }
}

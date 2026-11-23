<?php

declare(strict_types=1);

namespace Tests\Unit\Higher\Deliberation;

use PHPUnit\Framework\TestCase;
use Scholaris\Modules\Apps\Higher\Deliberation\Application\TranscriptService;
use Scholaris\Modules\Apps\Higher\Deliberation\Documents\DeliberationDocumentTypes;
use Scholaris\Modules\Apps\Higher\Deliberation\Domain\AnnualTranscript;
use Scholaris\Modules\Apps\Higher\Documents\Application\HigherDocumentCatalog;
use Scholaris\Modules\Socle\Documents\Application\DocumentService;
use Scholaris\Modules\Socle\Documents\Domain\TemplateGuard;
use Scholaris\Modules\Socle\Documents\Domain\TemplateRenderer;
use Scholaris\Modules\Socle\Documents\Infrastructure\DompdfRenderer;

/** DOC-10 (C-103) : relevé de notes et de crédits annuel, données FICTIVES. */
final class AnnualTranscriptTest extends TestCase
{
    /** @return array<string, mixed> */
    public static function composed(string $locale = 'fr', int $unitsPerSemester = 6): array
    {
        $labels = TranscriptService::RESULT_LABELS[$locale];
        $semester = static function (string $id, string $label, int $offset) use ($labels, $unitsPerSemester, $locale): array {
            $units = [];
            for ($i = 1; $i <= $unitsPerSemester; ++$i) {
                $status = $i === 4 && $offset === 0 ? 'not_acquired' : 'acquired';
                $average = 8.0 + (($i * 13 + $offset) % 11);
                $units[] = [
                    'code' => 'UE' . $id . $i, 'name' => 'Unité fictive ' . $id . '-' . $i . ' <b>x</b>', 'credits' => 5, 'average' => $average,
                    'letter' => $average >= 10 ? 'C' : 'F', 'points' => $average >= 10 ? 2.0 : 0.0, 'status' => $status, 'result_label' => $labels[$status],
                    'session' => 'normal', 'session_label' => $locale === 'en' ? 'Regular' : 'Normale', 'elements' => [],
                    'mention' => $status === 'acquired' && $average >= 12 ? 'Assez bien' : null,
                ];
            }

            return [
                'jury_id' => 'JURY-' . $id, 'structure_id' => 'GRP1', 'school_id' => 'EC', 'program_id' => 'CF', 'level_id' => 'CF-L1', 'period_id' => $id,
                'period_label' => $label, 'session_label' => $locale === 'en' ? 'Regular' : 'Normale', 'school' => ['name' => 'École', 'code' => 'EC'],
                'program' => ['name' => 'Comptabilité', 'code' => 'CF'], 'level' => ['name' => 'Licence 1', 'code' => 'CF-L1'], 'authorization_ref' => 'AUT-FICTIVE',
                'tutelage' => 'Université d\'exemple', 'student' => ['last_name' => 'NGO', 'first_names' => 'Marie (fictive)', 'birth_date' => '2006-04-12', 'matricule' => 'IPES-FICTIF-1'],
                'units' => $units, 'synthesis' => ['average' => 11.5 + $offset, 'credits_earned' => 25 + $offset, 'credits_total' => 30],
            ];
        };
        $decision = [
            'academic_year_id' => '2026-2027', 'average' => 12.04, 'credits_earned' => 55, 'credits_total' => 60, 'cumulative_credits' => 55,
            'decision_label' => 'Admis avec dettes', 'mention' => 'Assez bien',
        ];

        return AnnualTranscript::compose([$semester('S1', 'Semestre 1', 0), $semester('S2', 'Semestre 2', 1)], $decision, $locale);
    }

    public function testLaMgpEstPondereeParLesCreditsDeToutesLesUe(): void
    {
        $data = self::composed();

        self::assertSame(1.67, $data['synthesis']['gpa'], '10 UE à 2,0 sur 12 de 5 crédits et 2 à 0 : 20/12 = 1,67');
        self::assertSame(55, $data['synthesis']['credits_earned']);
        self::assertSame(55, $data['synthesis']['cumulative_credits']);
        self::assertSame('JURY-S2', $data['jury_id'], 'jury_ref : dernier semestre');
        self::assertCount(2, $data['semesters']);
    }

    public function testLesVariablesSontCellesDuTypeDoc10(): void
    {
        $type = (new DeliberationDocumentTypes())->types()[1];
        $vars = AnnualTranscript::variables(self::composed());

        self::assertSame('DOC-10', $type->code);
        $declared = HigherDocumentCatalog::variablesOf($type);
        self::assertSame([], array_values(array_diff(array_keys($vars), [...$declared, ...\Scholaris\Modules\Apps\Higher\Documents\Application\HigherDocumentService::OVERRIDABLE])));
        self::assertSame('1,67', $vars['gpa']);
        self::assertSame('12,04', $vars['semester_average']);
        self::assertSame('Semestre 1', $vars['s1_label']);
        self::assertSame('55', $vars['cumulative_credits']);
        self::assertSame('UES11', $vars['a01_code']);
    }

    public function testLeTableauPorteLaMentionParUeEtTouteLesUe(): void
    {
        $table = json_decode((string) AnnualTranscript::variables(self::composed('fr', 12))['annual_table'], true);

        self::assertCount(8, $table['columns']);
        // 2 sections + 24 UE + 1 total
        self::assertCount(27, $table['rows']);
        self::assertSame('section', $table['rows'][0]['kind']);
        self::assertSame('total', $table['rows'][26]['kind']);
        $mentions = array_column(array_filter($table['rows'], static fn (array $r): bool => !isset($r['kind'])), 6);
        self::assertContains('Assez bien', $mentions);
        self::assertContains('—', $mentions, 'UE non acquise ou sous la plus basse mention : pas de mention inventée.');
    }

    public function testUneDonneeAbsenteNEstPasInventee(): void
    {
        $data = self::composed();
        $data['synthesis']['gpa'] = null;
        $data['synthesis']['mention'] = null;
        $vars = AnnualTranscript::variables($data);

        self::assertSame('—', $vars['gpa']);
        self::assertSame('—', $vars['mention']);
        self::assertSame('—', $vars['rank_info']);
    }

    /** @dataProvider locales */
    public function testLeModeleDoc10EchappeLesDonneesEtTientSurUnePage(string $locale): void
    {
        $type = (new DeliberationDocumentTypes())->types()[1];
        $html = HigherDocumentCatalog::html($type, $locale);
        $declared = HigherDocumentCatalog::variablesOf($type);
        TemplateGuard::assertValid($html, $declared);
        $values = AnnualTranscript::variables(self::composed($locale, 7)) + [
            'school_name' => 'École d\'exemple', 'school_code' => 'EC', 'school_header' => 'En-tête', 'school_logo_url' => '', 'school_color' => '#0B5394',
            'document_title' => $type->label($locale), 'program_name' => 'Comptabilité', 'level_name' => 'Licence 1', 'issue_place' => 'Exempleville',
        ];
        $content = TemplateRenderer::render($html, $values + [
            'number' => 'REL-EC-2026-000001', 'verification_url' => 'https://verif.example.invalid/v/X', 'holder_name' => 'Marie NGO (fictive)',
            'establishment_name' => 'Institut d\'exemple', 'issued_on' => '31/10/2026', 'year' => '2026',
        ], ['qr' => '', 'signature' => '', 'stamp' => '', 'logo' => '']);

        self::assertStringContainsString('<table class="doc-table', $content);
        self::assertStringNotContainsString('<b>x</b>', $content, 'Valeurs échappées.');
        self::assertStringContainsString('Assez bien', $content);
        $pdf = (new DompdfRenderer(sys_get_temp_dir() . '/scholaris-dompdf-test'))->render(DocumentService::withPrintStyle($content));
        self::assertSame(1, preg_match_all('#/Type\s*/Page[^s]#', $pdf), 'Une seule page A4 pour 14 UE.');
    }

    /** @return iterable<string, array{0: string}> */
    public static function locales(): iterable
    {
        yield 'fr' => ['fr'];
        yield 'en' => ['en'];
    }
}

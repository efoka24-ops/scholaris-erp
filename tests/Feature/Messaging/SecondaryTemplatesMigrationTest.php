<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use PHPUnit\Framework\TestCase;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Database\Migrator;
use Scholaris\Core\Kernel;
use Scholaris\Modules\Socle\Messaging\Domain\TemplateRenderer;

/** T809 (SS11) : modèles NOT-01 à NOT-14 du secondaire, FR/EN, canal du CDC, et rendu de chacun. */
final class SecondaryTemplatesMigrationTest extends TestCase
{
    /** Clé => canaux attendus (CDC « Notifications automatiques » : SMS ou courrier = sms, notification ou portail = push). */
    private const EXPECTED = [
        'sec.not01.enrolment_validated' => ['sms'],
        'sec.not02.first_hour_absence' => ['sms'],
        'sec.not03.absence_threshold' => ['push', 'sms'],
        'sec.not04.grade_published' => ['push'],
        'sec.not05.report_card_ready' => ['push', 'sms'],
        'sec.not06.discipline_council' => ['sms'],
        'sec.not07.fees_due' => ['sms'],
        'sec.not08.fees_overdue' => ['sms'],
        'sec.not09.payment_received' => ['sms'],
        'sec.not10.exam_fees_unpaid' => ['sms'],
        'sec.not11.grades_late' => ['push'],
        'sec.not12.lesson_log_missing' => ['push'],
        'sec.not13.exam_result' => ['sms'],
        'sec.not14.timetable_changed' => ['push'],
    ];

    /** @var list<array<string, mixed>> */
    private static array $rows = [];

    protected function setUp(): void
    {
        if (self::$rows !== []) {
            return;
        }
        $kernel = Kernel::boot(dirname(__DIR__, 3), Config::fromArray([
            'app' => ['timezone' => 'Africa/Douala', 'log_path' => 'storage/logs/test.log', 'api_prefix' => '/api/v1'],
            'database' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]));
        $kernel->container()->get(Migrator::class)->migrate();
        $all = (array) $kernel->container()->get(ConnectionFactory::class)->pdo()->query('SELECT * FROM message_templates')->fetchAll();
        self::$rows = array_values(array_filter($all, static fn (array $row): bool => str_starts_with((string) $row['template_key'], 'sec.')));
    }

    public function testQuatorzeNotificationsEnFrancaisEtEnAnglaisSurLesCanauxDuCdc(): void
    {
        $byKey = [];
        foreach (self::$rows as $row) {
            self::assertSame('platform', $row['tenant_id']);
            self::assertSame('platform', $row['establishment_id']);
            $byKey[(string) $row['template_key']][(string) $row['channel']][] = (string) $row['locale'];
        }
        ksort($byKey);
        self::assertSame(array_keys(self::EXPECTED), array_keys($byKey));
        foreach (self::EXPECTED as $key => $channels) {
            $found = array_keys($byKey[$key]);
            sort($found);
            self::assertSame($channels, $found, $key);
            foreach ($byKey[$key] as $locales) {
                sort($locales);
                self::assertSame(['en', 'fr'], $locales, $key);
            }
        }
    }

    public function testVariablesDeclareesEgalesAuTexteEtDansLaListeBlanche(): void
    {
        foreach (self::$rows as $row) {
            $declared = json_decode((string) $row['variables'], true, flags: JSON_THROW_ON_ERROR);
            $used = TemplateRenderer::placeholders((string) $row['body']);
            sort($declared);
            sort($used);
            self::assertSame($used, $declared, (string) $row['template_key']);
            self::assertSame([], array_values(array_diff($declared, TemplateRenderer::ALLOWED)), (string) $row['template_key']);
        }
    }

    public function testChaqueModeleSeRendSansVariableRestante(): void
    {
        foreach (self::$rows as $row) {
            $values = [];
            foreach (json_decode((string) $row['variables'], true, flags: JSON_THROW_ON_ERROR) as $name) {
                $values[(string) $name] = 'x-' . $name;
            }
            $text = TemplateRenderer::render((string) $row['body'], $values);
            self::assertSame([], TemplateRenderer::placeholders($text), (string) $row['template_key']);
            self::assertStringContainsString('x-', $text);
        }
    }

    public function testLeBulletinPorteMoyenneRangEtDecisionEtLeSeuilDAbsenceSesHeures(): void
    {
        $text = '';
        foreach (self::$rows as $row) {
            if ($row['template_key'] === 'sec.not05.report_card_ready' && $row['locale'] === 'fr' && $row['channel'] === 'sms') {
                $text = TemplateRenderer::render((string) $row['body'], [
                    'child_name' => 'Aïcha', 'period_label' => '1er trimestre', 'average' => '11,30', 'rank' => '5e', 'decision' => 'aucune', 'link' => 'https://exemple.test/b',
                ]);
            }
        }
        self::assertStringContainsString('moyenne 11,30, rang 5e, décision : aucune', $text);
        foreach (['hours', 'average', 'rank', 'decision'] as $variable) {
            self::assertContains($variable, TemplateRenderer::ALLOWED);
        }
    }

    public function testAucunMatriculeNiValeurReglementaireEnDur(): void
    {
        foreach (self::$rows as $row) {
            $body = (string) $row['body'];
            self::assertDoesNotMatchRegularExpression('/\d/', $body, (string) $row['template_key'] . ' : les délais et seuils arrivent en variables');
            self::assertStringNotContainsString(':national_id', $body);
        }
    }
}

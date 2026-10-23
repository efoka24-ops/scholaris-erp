<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use PHPUnit\Framework\TestCase;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Database\Migrator;
use Scholaris\Core\Kernel;
use Scholaris\Modules\Socle\Messaging\Domain\TemplateRenderer;

/** T509 : modèles de messages du primaire privé (données) et variables ajoutées à la liste blanche. */
final class PrimaryTemplatesMigrationTest extends TestCase
{
    /** Liste blanche avant T509 : elle ne doit pas perdre une variable. */
    private const PREVIOUS = [
        'receipt_number', 'amount', 'balance', 'due_date', 'payment_reference', 'first_name', 'last_name',
        'full_name', 'child_name', 'class_name', 'establishment_name', 'date', 'absence_date', 'link',
        'reference', 'school_year', 'due_on', 'label', 'offset', 'subject', 'time', 'change_label', 'details',
    ];

    /** @var list<array<string, mixed>> */
    private static array $all = [];

    protected function setUp(): void
    {
        if (self::$all !== []) {
            return;
        }
        $kernel = Kernel::boot(dirname(__DIR__, 3), Config::fromArray([
            'app' => ['timezone' => 'Africa/Douala', 'log_path' => 'storage/logs/test.log', 'api_prefix' => '/api/v1'],
            'database' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]));
        $kernel->container()->get(Migrator::class)->migrate();
        self::$all = array_values((array) $kernel->container()->get(ConnectionFactory::class)->pdo()->query('SELECT * FROM message_templates')->fetchAll());
    }

    /** @return list<array<string, mixed>> */
    private function primary(): array
    {
        return array_values(array_filter(self::$all, static fn (array $row): bool => str_starts_with((string) $row['template_key'], 'pri.')));
    }

    public function testLaListeBlancheGardeToutesSesVariablesAnterieures(): void
    {
        self::assertSame([], array_values(array_diff(self::PREVIOUS, TemplateRenderer::ALLOWED)));
        $added = array_values(array_diff(TemplateRenderer::ALLOWED, self::PREVIOUS));
        sort($added);
        self::assertSame(['exam_label', 'period_label', 'place'], $added);
    }

    public function testChaqueModeleExisteEnFrancaisEtEnAnglaisEnModelePlateforme(): void
    {
        $byKey = [];
        foreach ($this->primary() as $row) {
            self::assertSame('platform', $row['tenant_id']);
            self::assertSame('platform', $row['establishment_id']);
            self::assertSame('sms', $row['channel']);
            $byKey[(string) $row['template_key']][] = (string) $row['locale'];
        }
        self::assertCount(9, $byKey);
        foreach ($byKey as $locales) {
            sort($locales);
            self::assertSame(['en', 'fr'], $locales);
        }
    }

    public function testLesVariablesDeclareesSontCellesDuTexteEtDeLaListeBlanche(): void
    {
        foreach ($this->primary() as $row) {
            $declared = json_decode((string) $row['variables'], true, flags: JSON_THROW_ON_ERROR);
            $used = TemplateRenderer::placeholders((string) $row['body']);
            sort($declared);
            sort($used);
            self::assertSame($used, $declared, (string) $row['template_key']);
            self::assertSame([], array_values(array_diff($declared, TemplateRenderer::ALLOWED)), (string) $row['template_key']);
        }
    }

    public function testChaqueModeleSeRendAvecSesVariables(): void
    {
        foreach ($this->primary() as $row) {
            $values = [];
            foreach (json_decode((string) $row['variables'], true, flags: JSON_THROW_ON_ERROR) as $name) {
                $values[(string) $name] = 'x-' . $name;
            }
            $text = TemplateRenderer::render((string) $row['body'], $values);
            self::assertStringNotContainsString(':child_name', $text);
            self::assertStringContainsString('x-', $text);
        }
    }

    public function testTousLesModelesExistantsRestentValides(): void
    {
        self::assertGreaterThan(count($this->primary()), count(self::$all));
        foreach (self::$all as $row) {
            TemplateRenderer::assertBodyAllowed((string) $row['body']);
            self::assertTrue(true);
        }
        $keys = array_column(self::$all, 'template_key');
        foreach (['billing.receipt', 'billing.reminder', 'pn.pickup_code'] as $kept) {
            self::assertContains($kept, $keys);
        }
    }
}

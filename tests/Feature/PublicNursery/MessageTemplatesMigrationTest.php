<?php

declare(strict_types=1);

namespace Tests\Feature\PublicNursery;

use PHPUnit\Framework\TestCase;
use Scholaris\Modules\Socle\Messaging\Domain\TemplateRenderer;

final class MessageTemplatesMigrationTest extends TestCase
{
    public function testLesVariablesDesModelesMaternelleRestentDansLaListeBlanche(): void
    {
        $sql = file_get_contents(dirname(__DIR__, 3) . '/database/migrations/202610161255_message_templates_public_nursery.sql');
        self::assertIsString($sql);
        preg_match_all("/'(\[[^']+\])'/", $sql, $matches);
        self::assertNotEmpty($matches[1]);

        foreach ($matches[1] as $json) {
            $variables = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($variables);
            self::assertSame([], array_values(array_diff($variables, TemplateRenderer::ALLOWED)));
        }
    }
}

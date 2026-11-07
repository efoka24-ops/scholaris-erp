<?php

declare(strict_types=1);

namespace Tests\Feature\Nursery\Core;

use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Modules\Socle\Messaging\Domain\TemplateRenderer;
use Tests\Support\Nursery\NurseryTestCase;

/** EX-MAT-25 : modèles NOT-MAT bilingues, variables de la liste blanche seulement, chaque clé utilisée par le code existe en FR et EN. */
final class MessageTemplatesMigrationTest extends NurseryTestCase
{
    /** @return array<string, array<string, array{body: string, variables: list<string>}>> clé => langue => modèle */
    private function templates(): array
    {
        $this->bootNursery();
        $rows = $this->service(ConnectionFactory::class)->pdo()->query("SELECT template_key, locale, body, variables FROM message_templates WHERE template_key LIKE 'nursery.%'");
        self::assertNotFalse($rows);
        $out = [];
        foreach ($rows->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[(string) $row['template_key']][(string) $row['locale']] = ['body' => (string) $row['body'], 'variables' => (array) json_decode((string) $row['variables'], true, flags: JSON_THROW_ON_ERROR)];
        }

        return $out;
    }

    public function testChaqueModeleExisteEnFrancaisEtEnAnglaisAvecDesVariablesAutorisees(): void
    {
        $templates = $this->templates();
        self::assertNotSame([], $templates);
        foreach ($templates as $key => $locales) {
            self::assertEqualsCanonicalizing(['fr', 'en'], array_keys($locales), $key);
            foreach ($locales as $locale => $template) {
                self::assertSame([], array_values(array_diff($template['variables'], TemplateRenderer::ALLOWED)), $key . ' ' . $locale);
                self::assertSame([], array_values(array_diff(TemplateRenderer::placeholders($template['body']), $template['variables'])), $key . ' ' . $locale . ' : variable du texte non déclarée');
            }
        }
    }

    public function testToutesLesClesUtiliseesParLeCodeSontDefinies(): void
    {
        $defined = array_keys($this->templates());
        $used = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 4) . '/src/Modules/Apps/Nursery', \RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                preg_match_all("/'(nursery\.(?:not\d\d|msg)\.[a-z_]+)'/", (string) file_get_contents($file->getPathname()), $matches);
                $used = array_merge($used, $matches[1]);
            }
        }
        self::assertNotSame([], $used);
        self::assertSame([], array_values(array_diff(array_unique($used), $defined)), 'Modèle utilisé mais absent de la migration.');
    }
}

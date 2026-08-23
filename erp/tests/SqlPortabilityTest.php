<?php

declare(strict_types=1);

namespace Scholaris\Tests;

/**
 * Compatibilite MySQL du SQL ecrit a la main.
 *
 * Les tests tournent sur SQLite, plus permissif que MySQL : certaines erreurs
 * ne se manifesteraient qu'en production. Ces controles lisent donc le code
 * source pour attraper les pieges connus.
 *
 * Le cas fondateur : reutiliser deux fois le meme placeholder nomme dans une
 * requete. SQLite l'accepte, MySQL en preparation native repond
 * "SQLSTATE[HY093] Invalid parameter number". Le defaut n'a ete decouvert qu'au
 * premier seed sur le serveur.
 */
final class SqlPortabilityTest extends TestCase
{
    public function testAucunPlaceholderNommeNEstReutiliseDansUneRequete(): void
    {
        $problems = [];

        foreach ($this->sqlLiterals() as [$file, $line, $sql]) {
            preg_match_all('/:([a-z_][a-z0-9_]*)/i', $sql, $matches);

            $counts = array_count_values($matches[1]);

            foreach ($counts as $name => $count) {
                if ($count > 1) {
                    $problems[] = sprintf('%s ligne %d : ":%s" apparait %d fois', $file, $line, $name, $count);
                }
            }
        }

        $this->assertSame(
            [],
            $problems,
            'Un placeholder nomme repete echoue en MySQL : '.implode(' | ', $problems)
        );
    }

    public function testLeSchemaNUtilisePasDeMotReserveMysql(): void
    {
        // Mots reserves de MySQL 8 qui obligeraient a echapper les identifiants.
        $reserved = ['order', 'rank', 'groups', 'key', 'index', 'system', 'lead', 'lag', 'row'];
        $problems = [];

        foreach (glob($this->basePath().'/database/migrations/*.sql') ?: [] as $file) {
            $sql = (string) file_get_contents($file);

            foreach (explode("\n", $sql) as $number => $line) {
                $line = trim($line);

                if ($line === '' || str_starts_with($line, '--')) {
                    continue;
                }

                // Nom de colonne : premier mot d'une ligne de definition.
                if (preg_match('/^([a-z_][a-z0-9_]*)\s+(CHAR|VARCHAR|INT|TEXT|DATE|DATETIME|DECIMAL|TINYINT)/i', $line, $m) === 1
                    && in_array(strtolower($m[1]), $reserved, true)) {
                    $problems[] = basename($file).' ligne '.($number + 1).' : colonne "'.$m[1].'"';
                }

                if (preg_match('/^CREATE TABLE ([a-z_]+)/i', $line, $m) === 1
                    && in_array(strtolower($m[1]), $reserved, true)) {
                    $problems[] = basename($file).' ligne '.($number + 1).' : table "'.$m[1].'"';
                }
            }
        }

        $this->assertSame([], $problems, 'Mot reserve MySQL utilise : '.implode(' | ', $problems));
    }

    public function testLeSchemaNUtilisePasDeTypeSpecifiqueAUnMoteur(): void
    {
        // Types non portables entre MySQL et SQLite, ou dependants du moteur.
        $forbidden = ['ENUM(', 'AUTO_INCREMENT', 'SERIAL', 'JSONB', 'UUID ', 'BOOLEAN'];
        $problems = [];

        foreach (glob($this->basePath().'/database/migrations/*.sql') ?: [] as $file) {
            $sql = strtoupper((string) file_get_contents($file));

            foreach ($forbidden as $token) {
                if (str_contains($sql, $token)) {
                    $problems[] = basename($file).' contient "'.trim($token).'"';
                }
            }
        }

        $this->assertSame([], $problems, 'Type non portable dans le schema : '.implode(' | ', $problems));
    }

    /**
     * Extrait les chaines SQL du code source.
     *
     * @return list<array{0: string, 1: int, 2: string}>
     */
    private function sqlLiterals(): array
    {
        $literals = [];

        foreach ($this->sourceFiles() as $file) {
            $content = (string) file_get_contents($file);
            $relative = str_replace($this->basePath().DIRECTORY_SEPARATOR, '', $file);

            // Chaines simples contenant un mot-cle SQL, sur une ou plusieurs lignes.
            preg_match_all(
                "/'((?:SELECT|INSERT|UPDATE|DELETE)[^']*)'/is",
                $content,
                $matches,
                PREG_OFFSET_CAPTURE
            );

            foreach ($matches[1] as [$sql, $offset]) {
                $line = substr_count(substr($content, 0, $offset), "\n") + 1;
                $literals[] = [$relative, $line, $sql];
            }
        }

        return $literals;
    }

    /**
     * @return list<string>
     */
    private function sourceFiles(): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->basePath().'/src', \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        $files[] = $this->basePath().'/artisan';

        return $files;
    }
}

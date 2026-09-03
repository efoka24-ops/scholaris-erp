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
    /**
     * Un meme nom d'index ne doit pas etre cree par deux migrations.
     *
     * Le defaut fondateur ici : une migration reconstruisait une table pour
     * SQLite, puis recreait son index sans marqueur de dialecte. Sur SQLite
     * l'index avait disparu avec la table et devait bien etre recree ; sur
     * MySQL, ou la table n'est pas reconstruite, il existait toujours et la
     * migration echouait sur « Duplicate key name ». Les tests, qui tournent
     * sur SQLite, ne passaient jamais par la branche fautive.
     */
    public function testUnNomDIndexNEstPasCreeDeuxFoisPourLeMemeMoteur(): void
    {
        $files = glob($this->basePath().'/database/migrations/*.sql');
        sort($files);

        $seen = [];
        $cleared = [];
        $problems = [];

        foreach ($files === false ? [] : $files as $file) {
            $name = basename($file);
            $dialect = null;

            foreach (explode("\n", (string) file_get_contents($file)) as $line) {
                $trimmed = trim($line);

                if ($trimmed === '-- @mysql') {
                    $dialect = 'mysql';

                    continue;
                }

                if ($trimmed === '-- @sqlite') {
                    $dialect = 'sqlite';

                    continue;
                }

                // Un marqueur ne vaut que pour l'instruction qui le suit ;
                // une ligne vide le referme.
                if ($trimmed === '') {
                    $dialect = null;

                    continue;
                }

                // Une suppression prealable rend la recreation legitime :
                // c'est le cas d'une table reconstruite pour SQLite, dont
                // l'index disparait avec elle.
                if (preg_match('/DROP\s+INDEX\s+(?:IF\s+EXISTS\s+)?([a-z0-9_]+)/i', $trimmed, $dropped) === 1) {
                    $droppedIndex = strtolower($dropped[1]);
                    $dropScope = $dialect ?? 'tous';

                    $seen[$droppedIndex] = array_values(array_filter(
                        $seen[$droppedIndex] ?? [],
                        static fn (array $entry): bool => $dropScope !== 'tous' && $entry[1] !== $dropScope
                            && $entry[1] !== 'tous'
                    ));

                    // Le moteur vise n'a plus cet index : une creation le
                    // concernant redevient admissible.
                    $cleared[$droppedIndex][] = $dropScope;

                    continue;
                }

                if (preg_match('/CREATE\s+(?:UNIQUE\s+)?INDEX\s+([a-z0-9_]+)/i', $trimmed, $match) !== 1) {
                    continue;
                }

                $index = strtolower($match[1]);
                $scope = $dialect ?? 'tous';

                // La creation ne pose probleme que pour les moteurs ou l'index
                // existe encore.
                $clearedScopes = $cleared[$index] ?? [];
                $coveredByDrop = in_array('tous', $clearedScopes, true)
                    || ($scope !== 'tous' && in_array($scope, $clearedScopes, true));

                if (! $coveredByDrop) {
                    foreach ($seen[$index] ?? [] as [$previousFile, $previousScope]) {
                        // Deux creations ne cohabitent que si elles visent des
                        // moteurs differents.
                        if ($scope === 'tous' || $previousScope === 'tous' || $scope === $previousScope) {
                            $problems[] = $index.' cree par '.$previousFile.' ('.$previousScope
                                .') puis par '.$name.' ('.$scope.')';
                        }
                    }
                }

                $seen[$index][] = [$name, $scope];
            }
        }

        $this->assertSame(
            [],
            $problems,
            'Index recree, ce qui echoue en MySQL : '.implode(' | ', $problems)
        );
    }

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

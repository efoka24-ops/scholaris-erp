<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PhpMyAdmin\SqlParser\Parser;
use PHPUnit\Framework\TestCase;

/**
 * Garde-fou MySQL 8 : les tests tournent sur SQLite, qui accepte des choses que MySQL refuse.
 * Ce test analyse toutes les migrations SQL et refuse : mots réservés MySQL 8 utilisés sans guillemets,
 * erreurs de syntaxe MySQL, index sur TEXT/BLOB, clés plus longues que la limite InnoDB (3072 octets en utf8mb4).
 *
 * Origine : `last_value` (fonction de fenêtrage, mot réservé depuis MySQL 8.0.2) a fait échouer `artisan migrate` en recette.
 */
final class MysqlCompatibilityTest extends TestCase
{
    /** Mots réservés de MySQL 8.0 (https://dev.mysql.com/doc/refman/8.0/en/keywords.html), en minuscules. */
    private const RESERVED = 'accessible add all alter analyze and as asc asensitive before between bigint binary blob both by call cascade case change char character check collate column condition constraint continue convert create cross cube cume_dist current_date current_time current_timestamp current_user cursor database databases day_hour day_microsecond day_minute day_second dec decimal declare default delayed delete dense_rank desc describe deterministic distinct distinctrow div double drop dual each else elseif empty enclosed escaped except exists exit explain false fetch first_value float float4 float8 for force foreign from fulltext function generated get grant group grouping groups having high_priority hour_microsecond hour_minute hour_second if ignore in index infile inner inout insensitive insert int int1 int2 int3 int4 int8 integer intersect interval into io_after_gtids io_before_gtids is iterate join json_table key keys kill lag last_value lateral lead leading leave left like limit linear lines load localtime localtimestamp lock long longblob longtext loop low_priority master_bind master_ssl_verify_server_cert match maxvalue mediumblob mediumint mediumtext middleint minute_microsecond minute_second mod modifies natural not no_write_to_binlog nth_value ntile null numeric of on optimize optimizer_costs option optionally or order out outer outfile over partition percent_rank precision primary procedure purge range rank read reads read_write real recursive references regexp release rename repeat replace require resignal restrict return revoke right rlike row rows row_number schema schemas second_microsecond select sensitive separator set show signal smallint spatial specific sql sqlexception sqlstate sqlwarning sql_big_result sql_calc_found_rows sql_small_result ssl starting stored straight_join system table terminated then tinyblob tinyint tinytext to trailing trigger true undo union unique unlock unsigned update usage use using utc_date utc_time utc_timestamp values varbinary varchar varcharacter varying virtual when where while window with write xor year_month zerofill';

    private const MAX_KEY_BYTES = 3072;

    /** @return array<string, string> chemin => contenu SQL sans commentaires */
    private function migrations(): array
    {
        $root = dirname(__DIR__, 2);
        $paths = array_merge(
            glob($root . '/database/migrations/*.sql') ?: [],
            glob($root . '/src/Modules/*/*/Database/Migrations/*.sql') ?: [],
            glob($root . '/src/Modules/Apps/*/*/Database/Migrations/*.sql') ?: [],
        );

        $files = [];
        foreach ($paths as $path) {
            $files[substr($path, strlen($root) + 1)] = (string) preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($path));
        }

        return $files;
    }

    /** @return list<string> */
    private function statements(string $sql): array
    {
        return array_values(array_filter(array_map('trim', explode(';', $sql)), static fn (string $s): bool => $s !== ''));
    }

    public function testAucunMotReserveMysqlNEstUtiliseCommeIdentifiant(): void
    {
        $reserved = array_flip(explode(' ', self::RESERVED));
        $types = 'CHAR|VARCHAR|TEXT|LONGTEXT|MEDIUMTEXT|INTEGER|INT|BIGINT|SMALLINT|TINYINT|DECIMAL|BLOB|DATETIME|DATE|TIMESTAMP|FLOAT|DOUBLE|JSON|BOOLEAN';
        $hits = [];

        foreach ($this->migrations() as $file => $sql) {
            preg_match_all('/CREATE TABLE(?: IF NOT EXISTS)?\s+`?(\w+)`?/i', $sql, $tables);
            preg_match_all('/^\s+`?(\w+)`?\s+(?:' . $types . ')\b/im', $sql, $columns);
            preg_match_all('/(?:PRIMARY KEY|UNIQUE(?: INDEX| KEY)?(?: \w+)?|(?:INDEX|KEY) \w+|CREATE (?:UNIQUE )?INDEX \w+ ON \w+)\s*\(([^)]*)\)/i', $sql, $indexes);

            $names = array_merge($tables[1], $columns[1]);
            foreach ($indexes[1] as $list) {
                foreach (explode(',', $list) as $column) {
                    $names[] = trim(str_replace('`', '', $column));
                }
            }
            foreach ($names as $name) {
                if (isset($reserved[strtolower($name)])) {
                    $hits[] = $file . ' : « ' . $name . ' »';
                }
            }
        }

        self::assertSame([], array_values(array_unique($hits)), 'Mots réservés MySQL 8 utilisés comme identifiants (renommer ou mettre entre accents graves).');
    }

    public function testChaqueInstructionEstSyntaxiquementValidePourMysql(): void
    {
        $errors = [];
        foreach ($this->migrations() as $file => $sql) {
            foreach ($this->statements($sql) as $statement) {
                $parser = new Parser($statement);
                foreach ($parser->errors as $error) {
                    $errors[] = $file . ' : ' . $error->getMessage() . ' [' . substr($statement, 0, 60) . '…]';
                }
            }
        }

        self::assertSame([], $errors);
    }

    public function testAucunIndexNPorteSurUnTexteOuUnBlob(): void
    {
        $problems = [];
        foreach ($this->migrations() as $file => $sql) {
            foreach ($this->statements($sql) as $statement) {
                if (preg_match('/^CREATE TABLE(?: IF NOT EXISTS)?\s+`?(\w+)`?\s*\((.*)\)\s*$/is', $statement, $m) !== 1) {
                    continue;
                }
                $columns = $this->columnTypes($m[2]);
                foreach ($this->indexColumns($m[2]) as $index) {
                    foreach ($index as $column) {
                        if (in_array($columns[$column]['type'] ?? '', ['TEXT', 'LONGTEXT', 'MEDIUMTEXT', 'BLOB', 'JSON'], true)) {
                            $problems[] = $file . ' : index sur ' . $m[1] . '.' . $column . ' de type ' . $columns[$column]['type'];
                        }
                    }
                }
            }
        }

        self::assertSame([], $problems);
    }

    public function testLesClesRestentSousLaLimiteInnodb(): void
    {
        $problems = [];
        foreach ($this->migrations() as $file => $sql) {
            foreach ($this->statements($sql) as $statement) {
                if (preg_match('/^CREATE TABLE(?: IF NOT EXISTS)?\s+`?(\w+)`?\s*\((.*)\)\s*$/is', $statement, $m) !== 1) {
                    continue;
                }
                $columns = $this->columnTypes($m[2]);
                foreach ($this->indexColumns($m[2]) as $index) {
                    $bytes = 0;
                    foreach ($index as $column) {
                        $bytes += ($columns[$column]['length'] ?? 8) * (in_array($columns[$column]['type'] ?? '', ['CHAR', 'VARCHAR'], true) ? 4 : 1);
                    }
                    if ($bytes > self::MAX_KEY_BYTES) {
                        $problems[] = $file . ' : clé de ' . $m[1] . ' trop longue (' . $bytes . ' octets)';
                    }
                }
            }
        }

        self::assertSame([], $problems);
    }

    public function testPasDeSyntaxeSpecifiqueAUnMoteur(): void
    {
        $problems = [];
        foreach ($this->migrations() as $file => $sql) {
            foreach (['AUTO_INCREMENT', 'AUTOINCREMENT', 'ENGINE=', 'ENGINE ', 'PRAGMA', 'WITHOUT ROWID'] as $word) {
                if (stripos($sql, $word) !== false) {
                    $problems[] = $file . ' : ' . $word;
                }
            }
        }

        self::assertSame([], $problems, 'Les migrations doivent rester portables (MySQL 8 et SQLite).');
    }

    /** @return array<string, array{type: string, length: int|null}> */
    private function columnTypes(string $body): array
    {
        $columns = [];
        preg_match_all('/^\s*`?(\w+)`?\s+(CHAR|VARCHAR|TEXT|LONGTEXT|MEDIUMTEXT|INTEGER|INT|BIGINT|SMALLINT|TINYINT|DECIMAL|BLOB|DATETIME|DATE|TIMESTAMP|FLOAT|DOUBLE|JSON|BOOLEAN)\b(?:\((\d+)\))?/im', $body, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $columns[$m[1]] = ['type' => strtoupper($m[2]), 'length' => isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null];
        }

        return $columns;
    }

    /** @return list<list<string>> colonnes de chaque clé primaire ou unique déclarée dans le CREATE TABLE */
    private function indexColumns(string $body): array
    {
        preg_match_all('/(?:PRIMARY KEY|UNIQUE(?: INDEX| KEY)?(?: \w+)?|(?:INDEX|KEY) \w+)\s*\(([^)]*)\)/i', $body, $matches);
        $indexes = array_map(
            static fn (string $list): array => array_map(static fn (string $c): string => trim(str_replace('`', '', $c)), explode(',', $list)),
            $matches[1],
        );

        // PRIMARY KEY en ligne (« id CHAR(26) NOT NULL PRIMARY KEY »)
        preg_match_all('/^\s*`?(\w+)`?\s+[A-Z]+(?:\(\d+\))?[^,\n]*PRIMARY KEY/im', $body, $inline);
        foreach ($inline[1] as $column) {
            $indexes[] = [$column];
        }

        return $indexes;
    }
}

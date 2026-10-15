<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use PDO;
use PDOStatement;
use Scholaris\Core\Support\Ulid;
use Tests\E2E\Support\BuildsSchool;
use Tests\E2E\Support\RealStackTestCase;

/**
 * Non-régression P1 (recette) : le lanceur GET /me/contexts (tuiles et indicateurs de Reporting) et le tableau de bord de
 * direction font un nombre de requêtes SQL BORNÉ, indépendant du nombre d'apprenants (avant : 3 requêtes par apprenant).
 * Pile réelle (Identity, Reporting, Billing, Attendance, Assessment) ; les inscriptions supplémentaires sont dupliquées en
 * SQL pour aller vite, les autres données passent par l'API.
 */
final class ContextsQueryCountTest extends RealStackTestCase
{
    use BuildsSchool;

    private const MAX_QUERIES = 50;

    protected function setUp(): void
    {
        $this->bootRealStack();
        $this->buildSchool();
    }

    public function testLeLanceurEtLeTableauDeBordFontUnNombreDeRequetesIndependantDuNombreDApprenants(): void
    {
        $first = $this->enrollLearner('Nadia', 'Foka', '2011-05-14', null, false);
        $item = (string) $this->ok('POST', '/fee-items', $this->admin, ['kind' => 'tuition', 'name' => 'Pension', 'amount_xaf' => 100000], $this->school, [], 201)['data']['id'];
        $this->ok('POST', '/fee-schedules', $this->admin, ['fee_item_id' => $item, 'academic_year_id' => $this->year, 'amount_xaf' => 100000, 'terms' => [['due_on' => '2026-10-15', 'amount' => 100000]]], $this->school, [], 201);
        $this->ok('POST', '/invoices', $this->admin, ['enrollment_id' => $first['enrollment']], $this->school, [], 201);

        [$small, $tiles] = $this->countQueries('/me/contexts');
        $outstanding = $this->indicator($tiles, 'billing.outstanding_xaf');
        self::assertSame(100000, $outstanding, 'Le solde du lanceur doit rester exact avec la lecture groupée.');

        $this->duplicateEnrollments($first['enrollment'], 120);
        [$large, $tilesAfter] = $this->countQueries('/me/contexts');
        [$dashboard] = $this->countQueries('/dashboards/direction');

        self::assertSame(121, $this->indicator($tilesAfter, 'people.enrolled'));
        self::assertLessThan(self::MAX_QUERIES, $large, 'GET /me/contexts : ' . $large . ' requêtes pour 121 apprenants.');
        self::assertLessThanOrEqual($small + 2, $large, 'Le nombre de requêtes ne doit pas croître avec les apprenants (' . $small . ' → ' . $large . ').');
        self::assertLessThan(self::MAX_QUERIES, $dashboard, 'GET /dashboards/direction : ' . $dashboard . ' requêtes.');
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function countQueries(string $path): array
    {
        $pdo = $this->pdo();
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [CountingStatement::class, []]);
        CountingStatement::$count = 0;
        try {
            $json = $this->ok('GET', $path, $this->admin, null, $this->school);
        } finally {
            $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PDOStatement::class]);
        }

        return [CountingStatement::$count, $json];
    }

    /** @param array<string, mixed> $json */
    private function indicator(array $json, string $key): int|float|null
    {
        foreach ((array) ($json['data'] ?? []) as $tile) {
            foreach ((array) ($tile['indicators'] ?? []) as $indicator) {
                if (($indicator['key'] ?? null) === $key) {
                    return $indicator['value'];
                }
            }
        }

        return null;
    }

    private function duplicateEnrollments(string $enrollmentId, int $copies): void
    {
        $pdo = $this->pdo();
        $stmt = $pdo->prepare('SELECT * FROM enrollments WHERE id = ?');
        $stmt->execute([$enrollmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        $columns = array_keys($row);
        $insert = $pdo->prepare(sprintf('INSERT INTO enrollments (%s) VALUES (%s)', implode(', ', $columns), implode(', ', array_fill(0, count($columns), '?'))));
        for ($i = 0; $i < $copies; ++$i) {
            $copy = $row;
            $copy['id'] = (string) new Ulid();
            $copy['person_id'] = (string) new Ulid();
            foreach (['internal_number', 'number'] as $unique) {
                if (array_key_exists($unique, $copy) && $copy[$unique] !== null) {
                    $copy[$unique] = $copy[$unique] . '-' . $i;
                }
            }
            $insert->execute(array_values($copy));
        }
    }
}

/** Compte les instructions exécutées sur la connexion de test. */
class CountingStatement extends PDOStatement
{
    public static int $count = 0;

    protected function __construct()
    {
    }

    public function execute(?array $params = null): bool
    {
        ++self::$count;

        return parent::execute($params);
    }
}

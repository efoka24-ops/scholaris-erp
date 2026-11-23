<?php

declare(strict_types=1);

namespace Tests\Support\Higher\Dashboards;

use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Modules\Apps\Higher\Dashboards\Application\DashboardClock;
use Tests\Fixtures\Higher\HigherOrgFixture;
use Tests\Support\Higher\HigherTestCase;

/**
 * Base des tests des tableaux de bord : Kernel Higher avec base SQLite migrée, arbre de HigherOrgFixture (écoles EC et IT, filières
 * CF, DR et INFO) et insertion de lignes de tables hi_* / socle par complétion automatique des colonnes obligatoires.
 */
abstract class DashboardsTestCase extends HigherTestCase
{
    public const T = HigherOrgFixture::TENANT_A;
    public const E = HigherOrgFixture::ESTABLISHMENT_A;
    public const TB = HigherOrgFixture::TENANT_B;
    public const EB = HigherOrgFixture::ESTABLISHMENT_B;
    public const YEAR = 'AY-2026';
    public const TODAY = '2026-10-16';

    private int $sequence = 0;

    protected function bootDashboards(): void
    {
        $this->bootHigher([DashboardClock::class => new DashboardClock(self::TODAY)], true);
    }

    /** Direction générale : tout l'établissement, avec les permissions de donnée données. */
    protected function director(string $user, array $permissions, string $tenant = self::T, string $establishment = self::E): void
    {
        foreach (['higher.dashboard.read', ...$permissions] as $permission) {
            $this->assign($tenant, $user, $establishment, $permission, 'establishment', null, 'direction_groupe');
        }
    }

    /** Chef de département : périmètre limité à l'unité interne donnée. */
    protected function chief(string $user, string $orgUnit, array $permissions): void
    {
        foreach (['higher.dashboard.read', ...$permissions] as $permission) {
            $this->assign(self::T, $user, self::E, $permission, 'org_unit', $orgUnit, 'higher_chef_dept');
        }
    }

    /**
     * @param array<string, mixed> $values
     */
    protected function insert(string $table, array $values, string $tenant = self::T, string $establishment = self::E): string
    {
        $pdo = $this->service(ConnectionFactory::class)->pdo();
        $row = $values + ['tenant_id' => $tenant, 'establishment_id' => $establishment];
        if (!isset($row['id'])) {
            $row['id'] = sprintf('%026d', ++$this->sequence);
        }
        $columns = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(\PDO::FETCH_ASSOC);
        $row = array_intersect_key($row, array_flip(array_column($columns, 'name')));
        foreach ($columns as $column) {
            $name = (string) $column['name'];
            if (array_key_exists($name, $row) || (int) $column['notnull'] !== 1 || $column['dflt_value'] !== null) {
                continue;
            }
            $type = strtoupper((string) $column['type']);
            $row[$name] = str_contains($type, 'INT') || str_contains($type, 'DECIMAL') ? 0 : '2026-10-01T08:00:00+01:00';
            if (str_contains($type, 'CHAR') || str_contains($type, 'TEXT')) {
                $row[$name] = str_ends_with($name, '_at') ? '2026-10-01T08:00:00+01:00' : 'x';
            }
        }
        $names = array_keys($row);
        $pdo->prepare('INSERT INTO ' . $table . ' (' . implode(', ', $names) . ') VALUES (' . implode(', ', array_fill(0, count($names), '?')) . ')')->execute(array_values($row));

        return (string) $row['id'];
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    protected function get(string $path, string $user, string $tenant = self::T, string $establishment = self::E): array
    {
        [$response, $decoded] = $this->api('GET', $path, $user, $tenant, $establishment);

        return [$response->getStatusCode(), $decoded];
    }

    /** @return array<string, mixed> data d'une réponse 200 */
    protected function board(string $path, string $user, string $tenant = self::T, string $establishment = self::E): array
    {
        [$status, $decoded] = $this->get($path, $user, $tenant, $establishment);
        self::assertSame(200, $status, $path . ' : ' . json_encode($decoded, JSON_UNESCAPED_UNICODE));
        $data = $decoded['data'] ?? [];

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $board
     * @return list<array<string, mixed>>
     */
    protected static function rows(array $board, string $table): array
    {
        foreach ($board['tables'] as $candidate) {
            if ($candidate['key'] === $table) {
                return $candidate['rows'];
            }
        }
        self::fail('Tableau absent : ' . $table);
    }

    /**
     * @param array<string, mixed> $board
     */
    protected static function card(array $board, string $key): mixed
    {
        foreach ($board['cards'] as $card) {
            if ($card['key'] === $key) {
                return $card['value'];
            }
        }
        self::fail('Carte absente : ' . $key);
    }
}

<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Billing\Application;

use DateTimeImmutable;
use DateTimeZone;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\RateLimit\Clock;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Higher\Support\Perimeter;
use Scholaris\Modules\Apps\Higher\Support\ScopedBySchool;

/**
 * Contrôle d'accès du module FIN (refus par défaut) : permission + périmètre école/filière/niveau (ScopedBySchool), horloge du
 * module (Africa/Douala). Lecture hors périmètre : 404 ; écriture hors périmètre : 403 journalisé.
 */
final class BillingAccess
{
    public const READ = 'higher.billing.read';
    public const MANAGE = 'billing.manage';
    public const CASH = 'billing.cash';
    public const COLUMNS = ['school' => 'school_id', 'program' => 'program_id', 'level' => 'level_id'];

    public function __construct(private readonly ScopedBySchool $scoped, private readonly Clock $clock)
    {
    }

    /** Premier périmètre non vide parmi les permissions (ordre de préférence) ; 403 si aucune. */
    public function perimeter(Principal $principal, string $establishmentId, string ...$permissions): Perimeter
    {
        foreach ($permissions as $permission) {
            $perimeter = $this->scoped->perimeter($principal, $permission, $establishmentId);
            if (!$perimeter->isEmpty()) {
                return $perimeter;
            }
        }

        throw HttpException::forbidden('Permission requise : ' . implode(' ou ', $permissions) . '.');
    }

    /** @return array{0: string, 1: list<string>} */
    public function filter(Perimeter $perimeter): array
    {
        return $this->scoped->sqlFilter($perimeter, self::COLUMNS);
    }

    /** @param array<string, mixed> $row ligne portant school_id, program_id, level_id */
    public function assertReadable(Perimeter $perimeter, array $row): void
    {
        $this->scoped->assertReadable($perimeter, self::scopeOf($row));
    }

    /** @param array<string, mixed> $row */
    public function assertWritable(Principal $principal, Perimeter $perimeter, array $row, string $objectType, string $objectId): void
    {
        $this->scoped->assertWritable($principal, $perimeter, self::scopeOf($row), $objectType, $objectId);
    }

    public function now(): string
    {
        return $this->moment()->format(DATE_ATOM);
    }

    public function today(): string
    {
        return $this->moment()->format('Y-m-d');
    }

    private function moment(): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $this->clock->now()))->setTimezone(new DateTimeZone('Africa/Douala'));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, ?string>
     */
    private static function scopeOf(array $row): array
    {
        $scope = [];
        foreach (self::COLUMNS as $key => $column) {
            $value = $row[$column] ?? null;
            $scope[$key] = is_string($value) && $value !== '' ? $value : null;
        }

        return $scope;
    }
}
